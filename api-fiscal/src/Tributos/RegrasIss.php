<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Http\ApiException;
use App\Nfse\NfseConfig;
use App\Storage\JsonStore;
use App\Support\Errors;
use App\Tenancy\Tenant;

/**
 * Regras de ISS da NFS-e por empresa (equivalente ao IMPOSTO_ISS do ClickPDV): cada regra tem um código escolhido
 * pelo ERP e guarda os códigos do serviço e o tratamento do ISS. Na emissão (`POST /nfse`), `regra_iss` preenche
 * `servico` e `valores` com esses dados; o que o JSON informar explicitamente prevalece.
 * A alíquota é validada contra o regime do prestador e o convênio do município só na emissão.
 * Como no IMPOSTO_ISS do ClickPDV (frmCadImpostoIss), a regra também guarda:
 * - federais: CST e alíquotas de PIS/COFINS do serviço, alíquotas de IRRF e CSLL e o tipo de retenção
 *   (tpRetPisCofins 0–9). Sem `tributos_federais` no JSON, a API calcula o grupo tribFed sobre
 *   valor do serviço − desconto incondicionado (vRetCSLL soma PIS, COFINS e CSLL retidos conforme o tipo, NT 007).
 * - ibs_cbs: indicador da operação, cClassTrib, diferimento, operação onerosa e serviço no exterior. A NFS-e não
 *   calcula IBS/CBS: quando o JSON traz `ibs_cbs`, a regra completa os campos que faltarem.
 * - tipo_pessoa (F/J): identificação da regra, para o ERP escolher a regra do tomador.
 * Tipo de imunidade, suspensão da exigibilidade e os padrões de tributação/retenção do ISSQN ficam no cadastro da
 * empresa (PUT /nfse/config); a regra guarda só a alíquota do ISS.
 * Gravação: tenants/{id}/tributacao/iss/{codigo}.json (igual em homologação e produção).
 */
final class RegrasIss
{
    private const CODIGO = '/^[A-Za-z0-9_-]{1,30}$/D';
    public const TRIBUTACAO = ['1' => 'Operação tributável', '2' => 'Imunidade', '4' => 'Não incidência'];
    public const RETENCAO = ['1' => 'Não retido', '2' => 'Retido pelo tomador'];
    public const IMUNIDADE = ['0' => 'Imunidade (tipo não informado)', '1' => 'Patrimônio, renda ou serviços entre entes públicos',
        '2' => 'Templos de qualquer culto', '3' => 'Partidos, sindicatos, entidades de educação e assistência social sem fins lucrativos',
        '4' => 'Livros, jornais, periódicos e papel de impressão', '5' => 'Fonogramas e videofonogramas musicais produzidos no Brasil'];
    private const SERVICO = ['codigo_tributacao_nacional', 'codigo_tributacao_municipal', 'nbs', 'codigo_interno'];
    private const ISS = ['aliquota_iss'];
    /** Vêm do cadastro da empresa (PUT /nfse/config), não da regra. */
    private const DA_EMPRESA = ['tributacao_iss', 'retencao_iss', 'tipo_imunidade'];
    private const FEDERAIS = ['cst_pis_cofins', 'aliquota_pis', 'aliquota_cofins', 'aliquota_irrf', 'aliquota_csll', 'tipo_retencao'];
    private const IBS_CBS = ['indicador_operacao', 'classificacao_tributaria', 'percentual_diferimento', 'operacao_onerosa', 'servico_exterior'];
    public const TIPOS_PESSOA = ['F' => 'Pessoa física', 'J' => 'Pessoa jurídica'];
    /** CST de PIS/COFINS aceitos na NFS-e (TSTipoCST). */
    public const CST_PIS_COFINS = ['00','01','02','03','04','05','06','07','08','09','49','50','51','52','53','54','55','56','60','61','62',
        '63','64','65','66','67','70','71','72','73','74','75','98','99'];
    /**
     * Descrições oficiais da NFS-e (TSTipoCST) para os CST usados em serviço. Atenção à tabela CST_ISS do ClickPDV:
     * lá o 00 e o 07 aparecem como "sem incidência" e o 09 como "outras operações de saída", mas na NFS-e o 00 é
     * "nenhum", o 07 é isenta e o 09 é suspensão (outras saídas = 49). O Delphi envia o código como está.
     */
    public const CST_PIS_COFINS_DESCRICAO = ['00' => 'Nenhum', '01' => 'Tributável com alíquota básica', '02' => 'Tributável com alíquota diferenciada',
        '03' => 'Tributável com alíquota por unidade de medida', '04' => 'Tributável monofásica, revenda a alíquota zero',
        '05' => 'Tributável por substituição tributária', '06' => 'Tributável a alíquota zero', '07' => 'Isenta da contribuição',
        '08' => 'Sem incidência da contribuição', '09' => 'Suspensão da contribuição', '49' => 'Outras operações de saída',
        '99' => 'Outras operações'];
    /** tpRetPisCofins da NFS-e Nacional (TSTipoRetPISCofins). */
    public const RETENCAO_PIS_COFINS = ['0' => 'PIS/COFINS/CSLL não retidos', '1' => 'PIS/COFINS retidos', '2' => 'PIS/COFINS não retidos',
        '3' => 'PIS/COFINS/CSLL retidos', '4' => 'PIS/COFINS retidos, CSLL não retido', '5' => 'PIS retido, COFINS/CSLL não retidos',
        '6' => 'COFINS retido, PIS/CSLL não retidos', '7' => 'PIS não retido, COFINS/CSLL retidos', '8' => 'PIS/COFINS não retidos, CSLL retido',
        '9' => 'COFINS não retido, PIS/CSLL retidos'];
    /** Tributos retidos em cada tpRetPisCofins. */
    private const RETIDOS = [0 => [], 1 => ['pis', 'cofins'], 2 => [], 3 => ['pis', 'cofins', 'csll'], 4 => ['pis', 'cofins'], 5 => ['pis'],
        6 => ['cofins'], 7 => ['cofins', 'csll'], 8 => ['csll'], 9 => ['pis', 'csll']];

    /** @return list<array<string,mixed>> */
    public static function listar(): array
    {
        $out = [];
        foreach (JsonStore::files(Tenant::path('tributacao/iss/*.json')) as $f) {
            $r = JsonStore::read($f);
            if ($r !== null) {
                $out[] = $r;
            }
        }
        usort($out, fn($a, $b) => strnatcmp((string) $a['codigo'], (string) $b['codigo']));
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function obter(string $codigo): ?array
    {
        return preg_match(self::CODIGO, $codigo) ? JsonStore::read(self::arq($codigo)) : null;
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    /** Inclusão com código automático (maior código numérico + 1). @param array<mixed> $in @return array<string,mixed> */
    public static function criar(array $in): array
    {
        return self::salvar(Matriz::proximoCodigo(array_column(self::listar(), 'codigo')), $in);
    }

    public static function salvar(string $codigo, array $in): array
    {
        $r = self::validar($codigo, $in);
        JsonStore::write(self::arq($codigo), $r);
        return $r;
    }

    /**
     * Copia uma regra para um novo código (com outra descrição, opcional).
     * @param array<mixed> $in {origem, destino, descricao?, sobrescrever?}
     * @return array<string,mixed>
     */
    public static function copiar(array $in): array
    {
        $o = is_int($in['origem'] ?? null) ? (string) $in['origem'] : ($in['origem'] ?? null);
        $r = is_string($o) ? self::obter($o) : null;
        $r ?? throw Tenant::notFound('Regra de ISS de origem');
        // sem destino: próximo código automático
        $d = is_int($in['destino'] ?? null) ? (string) $in['destino'] : ($in['destino'] ?? Matriz::proximoCodigo(array_column(self::listar(), 'codigo')));
        if (!is_string($d) || !preg_match(self::CODIGO, $d)) {
            throw ApiException::validation([['campo' => 'destino', 'mensagem' => 'Código com 1 a 30 caracteres [A-Za-z0-9_-]']]);
        }
        if ($d === $o) {
            throw ApiException::validation([['campo' => 'destino', 'mensagem' => 'Informe um código diferente do de origem']]);
        }
        if (self::obter($d) !== null && ($in['sobrescrever'] ?? false) !== true) {
            throw new ApiException(409, 'regra_existente', "Já existe a regra de ISS $d (envie sobrescrever: true para substituir)");
        }
        $copia = array_intersect_key($r, array_flip(['tipo_pessoa', 'servico', 'iss', 'federais', 'ibs_cbs']));
        $copia['iss'] = array_diff_key($copia['iss'] ?? [], array_flip(self::DA_EMPRESA));
        $copia['descricao'] = $in['descricao'] ?? mb_substr('Cópia de ' . $r['descricao'], 0, 120);
        return self::salvar($d, $copia);
    }

    public static function excluir(string $codigo): void
    {
        self::obter($codigo) ?? throw Tenant::notFound('Regra de ISS');
        JsonStore::delete(self::arq($codigo));
    }

    /**
     * Aplica `regra_iss` ao payload de emissão da NFS-e. Campos informados no JSON prevalecem sobre a regra.
     * @param array<mixed> $in
     * @return array<mixed>
     */
    public static function aplicar(array $in, Errors $e): array
    {
        unset($in['_regra_iss']);
        if (!array_key_exists('regra_iss', $in)) {
            return $in;
        }
        $cod = is_int($in['regra_iss']) ? (string) $in['regra_iss'] : $in['regra_iss'];
        unset($in['regra_iss']);
        $r = is_string($cod) ? self::obter($cod) : null;
        if ($r === null) {
            $e->add('regra_iss', 'Regra de ISS não cadastrada' . (is_string($cod) ? " ($cod)" : ''));
            return $in;
        }
        $sv = is_array($in['servico'] ?? null) ? $in['servico'] : [];
        $in['servico'] = array_merge($r['servico'], array_filter($sv, fn($v) => $v !== null && $v !== ''));
        $v = is_array($in['valores'] ?? null) ? $in['valores'] : ($in['valores'] ?? []);
        if (is_array($v)) {
            foreach ($r['iss'] as $k => $x) {
                // tributação, retenção e imunidade são da empresa: regras antigas que as tenham gravadas não as aplicam
                if (!in_array($k, self::DA_EMPRESA, true) && !array_key_exists($k, $v)) {
                    $v[$k] = $x;
                }
            }
            $in['valores'] = $v;
        }
        // tributos federais calculados pela regra, salvo se o JSON já informou o grupo
        if (isset($r['federais']) && !array_key_exists('tributos_federais', $in) && is_array($in['valores'] ?? null)) {
            $f = self::federais($r['federais'], $in['valores']);
            if ($f !== null) {
                $in['tributos_federais'] = $f;
            }
        }
        // IBS/CBS informado (a NFS-e não calcula): a regra completa os campos ausentes
        if (isset($r['ibs_cbs']) && is_array($in['ibs_cbs'] ?? null) && !array_is_list($in['ibs_cbs'])) {
            $g = $r['ibs_cbs'];
            $x = $in['ibs_cbs'];
            if (isset($g['indicador_operacao'])) {
                $x['indicador_operacao'] ??= $g['indicador_operacao'];
            }
            if (isset($g['classificacao_tributaria'])) {
                $x['classificacao_tributaria'] ??= $g['classificacao_tributaria'];
                $x['cst'] ??= substr($g['classificacao_tributaria'], 0, 3);
            }
            if (isset($g['percentual_diferimento']) && !array_key_exists('diferimento', $x)) {
                $pd = $g['percentual_diferimento'];
                $x['diferimento'] = ['percentual_uf' => $pd, 'percentual_municipal' => $pd, 'percentual_cbs' => $pd];
            }
            $in['ibs_cbs'] = $x;
        }
        $in['_regra_iss'] = ['codigo' => $r['codigo'], 'descricao' => $r['descricao']];
        return $in;
    }

    /**
     * Grupo tribFed calculado: base = serviço − desconto incondicionado; valores com 2 casas (ABNT).
     * @param array<string,mixed> $f
     * @param array<mixed> $v valores da NFS-e
     * @return array<string,mixed>|null
     */
    public static function federais(array $f, array $v): ?array
    {
        $vs = Dec::de($v['servico'] ?? null);
        $di = Dec::de($v['desconto_incondicionado'] ?? 0) ?? '0';
        if ($vs === null) {
            return null; // valores inválidos: a validação da NFS-e aponta o erro
        }
        foreach (['aliquota_pis', 'aliquota_cofins', 'aliquota_irrf', 'aliquota_csll'] as $k) {
            $f[$k] = isset($f[$k]) ? Dec::de($f[$k]) : null;
        }
        $base = Dec::abnt(Dec::max0(Dec::sub($vs, $di)));
        $calc = fn(?string $p): string => $p === null ? '0' : Dec::abnt(Dec::pct($base, $p));
        $tipo = (int) ($f['tipo_retencao'] ?? 0);
        $retidos = self::RETIDOS[$tipo] ?? [];
        $vPis = $calc($f['aliquota_pis'] ?? null);
        $vCofins = $calc($f['aliquota_cofins'] ?? null);
        $vCsll = $calc($f['aliquota_csll'] ?? null);
        $pc = ['cst' => $f['cst_pis_cofins']];
        if (Dec::cmp($vPis, '0') > 0 || Dec::cmp($vCofins, '0') > 0) {
            $pc += ['base_calculo' => Dec::fixo($base, 2), 'aliquota_pis' => Dec::fixo($f['aliquota_pis'] ?? '0', 2),
                'aliquota_cofins' => Dec::fixo($f['aliquota_cofins'] ?? '0', 2), 'valor_pis' => Dec::fixo($vPis, 2), 'valor_cofins' => Dec::fixo($vCofins, 2)];
        }
        $pc['tipo_retencao'] = $tipo;
        $out = ['pis_cofins' => $pc];
        $irrf = $calc($f['aliquota_irrf'] ?? null);
        if (Dec::cmp($irrf, '0') > 0) {
            $out['retencao_irrf'] = Dec::fixo($irrf, 2);
        }
        // NT 007: vRetCSLL leva a soma de PIS, COFINS e CSLL retidos pelo tomador, conforme o tpRetPisCofins
        $ret = Dec::soma(in_array('pis', $retidos, true) ? $vPis : '0', in_array('cofins', $retidos, true) ? $vCofins : '0',
            in_array('csll', $retidos, true) ? $vCsll : '0');
        if (Dec::cmp($ret, '0') > 0) {
            $out['retencao_csll'] = Dec::fixo($ret, 2);
        }
        return $out;
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    private static function validar(string $codigo, array $in): array
    {
        if (!preg_match(self::CODIGO, $codigo)) {
            throw ApiException::validation([['campo' => 'codigo', 'mensagem' => 'Código com 1 a 30 caracteres [A-Za-z0-9_-]']]);
        }
        $e = new Errors();
        foreach (array_keys($in) as $k) {
            in_array($k, ['codigo', 'descricao', 'tipo_pessoa', 'servico', 'iss', 'federais', 'ibs_cbs'], true) ?: $e->add((string) $k, 'Campo não permitido');
        }
        $descricao = \App\Support\Validate::text($in['descricao'] ?? null, 1, 120);
        $descricao !== null ?: $e->add('descricao', '1 a 120 caracteres');

        $sv = is_array($in['servico'] ?? null) && !array_is_list($in['servico']) ? $in['servico'] : null;
        $servico = [];
        if ($sv === null) {
            $e->add('servico', 'Objeto com codigo_tributacao_nacional (e opcionais codigo_tributacao_municipal, nbs, codigo_interno)');
        } else {
            foreach (array_keys($sv) as $k) {
                in_array($k, self::SERVICO, true) ?: $e->add("servico.$k", 'Campo não permitido');
            }
            $servico = NfseConfig::codigos($sv, 'servico', $e);
        }

        $is = $in['iss'] ?? [];
        $iss = [];
        if (!is_array($is) || ($is !== [] && array_is_list($is))) {
            $e->add('iss', 'Objeto com aliquota_iss');
            $is = [];
        }
        foreach (array_keys($is) as $k) {
            in_array($k, self::DA_EMPRESA, true)
                ? $e->add("iss.$k", 'Tributação, retenção e imunidade do ISSQN ficam no cadastro da empresa (PUT /nfse/config), não na regra de ISS')
                : (in_array($k, self::ISS, true) ?: $e->add("iss.$k", 'Campo não permitido'));
        }
        if (isset($is['aliquota_iss'])) {
            $a = NfseConfig::aliquota($is['aliquota_iss']);
            if ($a === null) {
                $e->add('iss.aliquota_iss', 'Alíquota do ISS entre 0 e 5 (%), até 2 casas');
            } else {
                $iss['aliquota_iss'] = $a;
            }
        }
        $tp = $in['tipo_pessoa'] ?? null;
        if ($tp !== null && !isset(self::TIPOS_PESSOA[(string) $tp])) {
            $e->add('tipo_pessoa', 'F (física) ou J (jurídica)');
        }
        $federais = array_key_exists('federais', $in) && $in['federais'] !== null ? self::validarFederais($in['federais'], $e) : null;
        $ibs = array_key_exists('ibs_cbs', $in) && $in['ibs_cbs'] !== null ? self::validarIbsCbs($in['ibs_cbs'], $servico, $e) : null;
        $e->throwIfAny();
        return ['codigo' => $codigo, 'descricao' => $descricao] + ($tp !== null ? ['tipo_pessoa' => (string) $tp] : [])
            + ['servico' => $servico, 'iss' => $iss] + ($federais !== null ? ['federais' => $federais] : [])
            + ($ibs !== null ? ['ibs_cbs' => $ibs] : []) + ['atualizado_em' => date('c')];
    }

    /** @return array<string,mixed> */
    private static function validarFederais(mixed $f, Errors $e): array
    {
        if (!is_array($f) || array_is_list($f)) {
            $e->add('federais', 'Objeto com cst_pis_cofins, aliquota_pis, aliquota_cofins, aliquota_irrf, aliquota_csll e tipo_retencao');
            return [];
        }
        foreach (array_keys($f) as $k) {
            in_array($k, self::FEDERAIS, true) ?: $e->add("federais.$k", 'Campo não permitido');
        }
        $out = [];
        $cst = $f['cst_pis_cofins'] ?? null;
        is_string($cst) && in_array($cst, self::CST_PIS_COFINS, true) ? $out['cst_pis_cofins'] = $cst
            : $e->add('federais.cst_pis_cofins', 'CST de PIS/COFINS com 2 dígitos, como texto (obrigatório)');
        foreach (['aliquota_pis', 'aliquota_cofins', 'aliquota_irrf', 'aliquota_csll'] as $k) {
            if (isset($f[$k])) {
                $d = Dec::de($f[$k]);
                $d !== null && Dec::cmp($d, '0') >= 0 && Dec::cmp($d, '100') <= 0 && preg_match('/^\d+(\.\d{1,2})?$/D', $d)
                    ? $out[$k] = Dec::num($d) : $e->add("federais.$k", 'Percentual de 0 a 100 com até 2 casas');
            }
        }
        $t = $f['tipo_retencao'] ?? 0;
        is_int($t) && isset(self::RETENCAO_PIS_COFINS[(string) $t]) ? $out['tipo_retencao'] = $t
            : $e->add('federais.tipo_retencao', 'Inteiro de 0 a 9 (tpRetPisCofins da NFS-e)');
        return $out;
    }

    /** @param array<string,mixed> $servico @return array<string,mixed> */
    private static function validarIbsCbs(mixed $g, array $servico, Errors $e): array
    {
        if (!is_array($g) || array_is_list($g)) {
            $e->add('ibs_cbs', 'Objeto com indicador_operacao, classificacao_tributaria, percentual_diferimento, operacao_onerosa e servico_exterior');
            return [];
        }
        foreach (array_keys($g) as $k) {
            in_array($k, self::IBS_CBS, true) ?: $e->add("ibs_cbs.$k", 'Campo não permitido');
        }
        $out = [];
        foreach (['indicador_operacao' => 6, 'classificacao_tributaria' => 6] as $k => $n) {
            if (isset($g[$k])) {
                is_string($g[$k]) && preg_match('/^\d{' . $n . '}$/D', $g[$k]) ? $out[$k] = $g[$k]
                    : $e->add("ibs_cbs.$k", "Texto com $n dígitos (preserve os zeros à esquerda)");
            }
        }
        if (isset($g['percentual_diferimento'])) {
            $d = Dec::de($g['percentual_diferimento']);
            $d !== null && Dec::cmp($d, '0') >= 0 && Dec::cmp($d, '100') <= 0 && preg_match('/^\d+(\.\d{1,2})?$/D', $d)
                ? $out['percentual_diferimento'] = Dec::num($d) : $e->add('ibs_cbs.percentual_diferimento', 'Percentual de 0 a 100 com até 2 casas');
        }
        foreach (['operacao_onerosa' => true, 'servico_exterior' => false] as $k => $padrao) {
            $v = $g[$k] ?? $padrao;
            is_bool($v) ? $out[$k] = $v : $e->add("ibs_cbs.$k", 'true ou false');
        }
        if (($out['indicador_operacao'] ?? null) !== null && !isset($servico['nbs'])) {
            $e->add('servico.nbs', 'Informe a NBS do serviço junto com o indicador da operação (IBS/CBS)');
        }
        return $out;
    }

    private static function arq(string $codigo): string
    {
        return Tenant::path("tributacao/iss/$codigo.json");
    }
}
