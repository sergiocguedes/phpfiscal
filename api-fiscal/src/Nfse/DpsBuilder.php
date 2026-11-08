<?php

declare(strict_types=1);

namespace App\Nfse;

use App\App;

use App\Http\ApiException;
use App\Support\Errors;
use App\Support\Validate;

/**
 * Monta a DPS (Declaração de Prestação de Serviço) do Sistema Nacional NFS-e, leiaute v1.01, na estrutura do
 * ambiente (NfseXml::leiaute): produção = NFSe-ESQUEMAS_XSD-v1.01-20260209; produção restrita =
 * NFSe-ESQUEMAS_XSD-PRODREST-v1.01-20261020 (com finNFSe e xDescServ no fim de cServ; Anexo I PRODREST 20261020).
 *
 * Escopo implementado: emitente = prestador (tpEmit 1) identificado por CNPJ, tomador nacional (CPF/CNPJ) ou não
 * identificado, serviço prestado no Brasil, ISSQN tributável/imune/não incidente, retenção, descontos, tributos
 * aproximados (Lei 12.741) e informações complementares. NÃO implementado (validação recusa ou o campo não existe na
 * API): exportação (comExt), obra, evento, deduções documentais, benefício municipal,
 * intermediário e substituição. IBS/CBS (classificação e diferimento), tributos federais, suspensão e deduções simples são informados pelo integrador.
 *
 * Regras de negócio da Sefin reproduzidas aqui para recusar ANTES do envio (código da rejeição entre parênteses):
 *  - prestador emitente não informa xNome nem end (E0121/E0128); regApTribSN só para ME/EPP (E0162/E0166);
 *  - MEI e regime especial não retêm ISS (E0583/E0588); imune/não incidente não retém (E0580);
 *  - retenção pelo tomador exige tomador com CPF/CNPJ (E0204); tomador ≠ prestador (E0202);
 *  - alíquota (pAliq): até 5% (E0595), proibida/obrigatória conforme regime e convênio (E0600..E0640);
 *  - tributos aproximados: MEI não usa pTotTribSN (E0710), ME/EPP não usa indTotTrib (E0712),
 *    não optante não usa indTotTrib nem pTotTribSN (E0713);
 *  - competência <= emissão (E0015).
 */
final class DpsBuilder
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';
    public const VERSAO = '1.01';
    public const TP_EMIT_PRESTADOR = 1;
    /** finNFSe: 0 = NFS-e regular (único valor aceito pelo XSD PRODREST 20261020; 1/2 crédito/débito ainda não). */
    public const FIN_NFSE_REGULAR = '0';

    /**
     * Valida e normaliza o payload da API (mesmo formato no REST, MCP e painel).
     * @param array<mixed> $in
     * @param array<string,mixed> $cfg configuração NFS-e do prestador
     * @param array<string,mixed> $emit configuração do emitente (NF-e): CNPJ
     * @return array<string,mixed>
     */
    public static function validatePayload(array $in, array $cfg, array $emit): array
    {
        $e = new Errors();
        $p = ['avisos' => []];
        // regra_iss: código da regra de ISS da empresa; preenche servico e valores (o JSON informado prevalece)
        $in = \App\Tributos\RegrasIss::aplicar($in, $e);
        if (isset($in['_regra_iss'])) {
            $p['regra_iss'] = $in['_regra_iss'];
            unset($in['_regra_iss']);
        }
        $op = (int) $cfg['opcao_simples_nacional'];
        $regAp = isset($cfg['regime_apuracao_sn']) ? (int) $cfg['regime_apuracao_sn'] : null;
        $regEsp = (int) ($cfg['regime_especial'] ?? 0);

        foreach (['numero' => [1, NfseConfig::NUMERO_MAX], 'serie' => [1, NfseConfig::SERIE_MAX]] as $k => [$min, $max]) {
            if (array_key_exists($k, $in) && $in[$k] !== null) {
                is_int($in[$k]) && $in[$k] >= $min && $in[$k] <= $max ? $p[$k] = $in[$k] : $e->add($k, "Inteiro entre $min e $max");
            }
        }

        // competência (data de início da prestação): padrão hoje
        $comp = $in['competencia'] ?? date('Y-m-d');
        $d = is_string($comp) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $comp) : false;
        if ($d === false || $d->format('Y-m-d') !== $comp || $comp < '2020-01-01') {
            $e->add('competencia', 'Data de competência AAAA-MM-DD');
        } elseif ($comp > date('Y-m-d')) {
            $e->add('competencia', 'A competência não pode ser posterior à data de emissão (rejeição E0015)');
        }
        $p['competencia'] = $comp;

        // Serviço é sempre informado pelo consumidor, sem catálogo local.
        $sv=[];
        if(array_key_exists('servico_id',$in)){$e->add('servico_id','Envie o objeto servico completo no JSON; não há cadastro de serviços');}
        $sIn = $in['servico'] ?? [];
        if (!is_array($sIn)) {
            $e->add('servico', 'Objeto esperado');
            $sIn = [];
        }
        $sv = array_filter($sIn, fn($v) => $v !== null && $v !== '') + $sv;
        $s = NfseConfig::codigos($sv, 'servico', $e);
        $s['descricao'] = self::texto($sv['descricao'] ?? null, 1, 2000, 'servico.descricao', 'Descrição do serviço prestado, 1 a 2000 caracteres (sem < >)', $e);
        $locPrest = Validate::digits($sv['municipio_prestacao'] ?? $cfg['codigo_municipio']);
        if (!preg_match('/^\d{7}$/', $locPrest) || !in_array((int) substr($locPrest, 0, 2), Validate::UFS, true)) {
            $e->add('servico.municipio_prestacao', 'Código IBGE (7 dígitos) do município onde o serviço foi prestado');
        } elseif (($dfErr = Validate::municipioIbgeErro($locPrest)) !== null) {
            $e->add('servico.municipio_prestacao', $dfErr);
        }
        $s['municipio_prestacao'] = $locPrest;
        $p['servico'] = $s;

        // tomador (opcional): explícito ou do catálogo de clientes (campos explícitos prevalecem)
        $t = $in['tomador'] ?? null;
        if (isset($in['cliente_id'])) { $e->add('cliente_id', 'Informe o tomador diretamente; esta edição não mantém catálogo'); }
        
        $p['tomador'] = null;
        if ($t !== null) {
            $p['tomador'] = is_array($t) ? self::tomador($t, $e, (string) $emit['cnpj']) : ($e->add('tomador', 'Objeto esperado') ?? null);
        }

        // valores e ISSQN
        $v = $in['valores'] ?? null;
        if (!is_array($v)) {
            $e->add('valores', 'Obrigatório: {"servico": 100.00, ...}');
            $v = [];
        }
        $vs = self::money($v['servico'] ?? null);
        if ($vs === null || $vs <= 0) {
            $e->add('valores.servico', 'Valor do serviço > 0, até 2 casas decimais');
        }
        $di = self::money($v['desconto_incondicionado'] ?? 0);
        $dc = self::money($v['desconto_condicionado'] ?? 0);
        $di === null ? $e->add('valores.desconto_incondicionado', 'Valor >= 0, até 2 casas') : null;
        $dc === null ? $e->add('valores.desconto_condicionado', 'Valor >= 0, até 2 casas') : null;
        if ($vs !== null && $di !== null && $dc !== null && $di + $dc >= $vs) {
            $e->add('valores', 'A soma dos descontos deve ser menor que o valor do serviço');
        }
        $trib = $v['tributacao_iss'] ?? 1;
        if (!in_array($trib, [1, 2, 4], true)) {
            $e->add('valores.tributacao_iss', $trib === 3
                ? 'Exportação de serviço (3) ainda não é suportada por este emissor'
                : 'Use 1 (operação tributável), 2 (imunidade) ou 4 (não incidência)');
        }
        $imun = null;
        if ($trib === 2) {
            $imun = $v['tipo_imunidade'] ?? 0;
            if (!in_array($imun, [0, 1, 2, 3, 4, 5], true)) {
                $e->add('valores.tipo_imunidade', 'Tipo de imunidade de 0 a 5 (CF/88 art. 150, VI)');
            }
        }
        $ret = $v['retencao_iss'] ?? 1;
        if (!in_array($ret, [1, 2, 3], true)) {
            $e->add('valores.retencao_iss', 'Use 1 (não retido), 2 (retido pelo tomador) ou 3 (retido pelo intermediário)');
        } elseif ($ret !== 1) {
            if ($op === 2) {
                $e->add('valores.retencao_iss', 'MEI não pode ter ISS retido (rejeição E0583)');
            } elseif ($regEsp !== 0) {
                $e->add('valores.retencao_iss', 'Prestador com regime especial de tributação não pode ter ISS retido (rejeição E0588)');
            } elseif ($trib !== 1) {
                $e->add('valores.retencao_iss', 'Serviço imune ou sem incidência não tem retenção (rejeição E0580)');
            } elseif ($ret === 3) {
                $e->add('valores.retencao_iss', 'Retenção pelo intermediário exige o grupo do intermediário, ainda não suportado');
            } elseif ($p['tomador'] === null) {
                $e->add('tomador', 'Retenção pelo tomador exige o tomador identificado por CPF ou CNPJ (rejeição E0204)');
            }
        }
        $aliqIn = array_key_exists('aliquota_iss', $v) && $v['aliquota_iss'] !== null ? NfseConfig::aliquota($v['aliquota_iss']) : null;
        if (array_key_exists('aliquota_iss', $v) && $v['aliquota_iss'] !== null && $aliqIn === null) {
            $e->add('valores.aliquota_iss', 'Alíquota do ISS entre 0 e 5 (%), até 2 casas (rejeição E0595 acima de 5%)');
        }
        $aliq = $aliqIn ?? ($p['_aliquota_preset'] ?? null);
        unset($p['_aliquota_preset']);
        $ativo = $cfg['municipio_convenio_ativo'] ?? null;
        $regra = self::regraAliquota($op, $regAp, $regEsp, (int) $trib, (int) $ret, $ativo !== false);
        if ($regra === 'proibida' && $aliq !== null) {
            if ($aliqIn !== null) {
                $p['avisos'][] = 'Alíquota do ISS não é informada na DPS neste cenário (regime/retenção/convênio): a Sefin Nacional aplica a alíquota parametrizada pelo município.';
            }
            $aliq = null;
        } elseif ($regra === 'obrigatoria' && $aliq === null) {
            $e->add('valores.aliquota_iss', 'Alíquota do ISS obrigatória neste cenário (regime ' . $op . ($regAp !== null ? '/' . $regAp : '') . ', retenção ' . $ret . ', convênio ' . ($ativo === false ? 'não ativo' : 'ativo') . ')');
        } elseif ($regra === 'obrigatoria' && $op === 3 && $regAp === 1 && $aliq < 1.8) {
            $e->add('valores.aliquota_iss', 'Para ME/EPP do Simples com retenção, a alíquota mínima é 1,8%');
        }
        $pm = $cfg['parametros_municipio'] ?? null;
        $pmAtual = is_array($pm) && ($pm['ambiente'] ?? null) === (int) ($cfg['ambiente'] ?? 0)
            && ($pm['codigo_municipio'] ?? null) === (string) ($cfg['codigo_municipio'] ?? '');
        $naoHabilitado = $pmAtual && (($pm['conveniado'] ?? null) === false || ($pm['emissor_nacional'] ?? null) === false);
        if ($naoHabilitado || ($ativo === false && $pmAtual)) {
            // v2.9.3: resposta do ADN Parâmetros Municipais para o município emissor neste ambiente (aviso, não bloqueia)
            $p['avisos'][] = 'Atenção: ' . Rejeicoes::mensagemNaoHabilitado()
                . ' A Sefin Nacional deve recusar esta nota (E0037/E0039)'
                . ((int) $cfg['ambiente'] === 2 ? '; a consulta foi feita na produção restrita e a produção pode ser diferente.' : '.');
        }
        if (isset($s['codigo_tributacao_municipal']) && $op !== 2 && !($pmAtual && ($pm['conveniado'] ?? null) === true && ($pm['emissor_nacional'] ?? null) !== false)) {
            // v2.9.3: cTribMun só existe se o município o cadastrou no Sistema Nacional; senão a Sefin recusa com E0314
            $p['avisos'][] = 'Cód. municipal "' . $s['codigo_tributacao_municipal'] . '" informado no serviço: ele precisa existir na tabela do município no Sistema Nacional, '
                . 'senão a nota é recusada (E0314). Se a prefeitura não exigir esse código, deixe o campo em branco.';
        }
        if ($ativo === null) {
            $p['avisos'][] ='Situação do convênio do município não confirmada: use "Verificar" em NFS-e (prestador) no painel ou GET /api/v1/nfse/parametros antes da primeira emissão.';
        }
        $p['valores'] = [
            'servico' => $vs, 'desconto_incondicionado' => $di ?? 0.0, 'desconto_condicionado' => $dc ?? 0.0,
            'tributacao_iss' => $trib, 'tipo_imunidade' => $imun, 'retencao_iss' => $ret, 'aliquota_iss' => $aliq,
        ];

        $p['tributos_aproximados'] = self::tributos($in['tributos_aproximados'] ?? null, $op, $vs, $e);
        $ibs = IbsCbsInformados::validar($in, $e);
        if ($ibs !== null) { $p['ibs_cbs'] = $ibs; }

        if (isset($in['informacoes_complementares']) && $in['informacoes_complementares'] !== '') {
            $p['informacoes_complementares'] = self::texto($in['informacoes_complementares'], 1, 2000, 'informacoes_complementares', '1 a 2000 caracteres (sem < >)', $e);
        }
        if (isset($in['pedido_id']) && $in['pedido_id'] !== '') {
            is_string($in['pedido_id']) && preg_match('/^[A-Za-z0-9_.:\/-]{1,60}$/', $in['pedido_id'])
                ? $p['pedido_id'] = $in['pedido_id']
                : $e->add('pedido_id', 'Referência do pedido: 1 a 60 caracteres [A-Za-z0-9_.:-/]');
        }
        $informados = TributosInformados::validar($in, $e);
        foreach (['deducoes', 'suspensao'] as $k) {
            if (isset($informados[$k])) { $p['valores'][$k] = $informados[$k]; }
        }
        if (isset($informados['tributos_federais'])) { $p['tributos_federais'] = $informados['tributos_federais']; }
        $e->throwIfAny();
        // Não estimar novos cenários usando a fórmula antiga (sem retenções/deduções/suspensão).
        $p['estimativa'] = $informados === [] ? self::estimativa($p['valores'])
            : ['base_calculo' => null, 'iss' => null, 'iss_retido' => null, 'valor_liquido' => null];
        return $p;
    }

    /**
     * Alíquota (pAliq) conforme as regras E0600–E0640 do Anexo I v1.01.
     * $municipioAtivo: convênio do município de incidência "Ativo" no Sistema Nacional.
     * @return 'proibida'|'obrigatoria'
     */
    public static function regraAliquota(int $op, ?int $regAp, int $regEsp, int $trib, int $ret, bool $municipioAtivo): string
    {
        if ($op === 2 || $trib !== 1 || $regEsp !== 0) {
            return 'proibida'; // E0600 (MEI), E0602 (imune/não incidência), E0604 (regime especial)
        }
        if ($op === 1) {
            return $municipioAtivo ? 'proibida' : 'obrigatoria'; // E0617 / E0619
        }
        if ($regAp === 1) {
            return $ret !== 1 ? 'obrigatoria' : 'proibida'; // E0621/E0628 e E0625/E0631
        }
        return $municipioAtivo ? 'proibida' : 'obrigatoria'; // E0635 / E0640
    }

    /** @param array<mixed> $t @return array<string,mixed> */
    private static function tomador(array $t, Errors $e, string $cnpjPrestador): array
    {
        $o = [];
        $cpf = Validate::digits($t['cpf'] ?? '');
        $cnpj = Validate::digits($t['cnpj'] ?? '');
        if (($cpf === '') === ($cnpj === '')) {
            $e->add('tomador', 'Informe o CPF ou o CNPJ do tomador (só um). Para consumidor não identificado, omita "tomador".');
        } elseif ($cnpj !== '') {
            if (!Validate::cnpj($cnpj)) {
                $e->add('tomador.cnpj', 'CNPJ inválido (rejeição E0188)');
            } elseif ($cnpj === $cnpjPrestador) {
                $e->add('tomador.cnpj', 'O tomador não pode ser o próprio prestador (rejeição E0202)');
            }
            $o['cnpj'] = $cnpj;
        } else {
            Validate::cpf($cpf) ? null : $e->add('tomador.cpf', 'CPF inválido (rejeição E0206)');
            $o['cpf'] = $cpf;
        }
        $o['nome'] = self::texto($t['nome'] ?? null, 2, 300, 'tomador.nome', 'Nome ou razão social do tomador, 2 a 300 caracteres', $e);
        if (!empty($t['email'])) {
            $em = Validate::email($t['email']);
            $em !== null && strlen($em) <= 80 ? $o['email'] = $em : $e->add('tomador.email', 'E-mail inválido (até 80 caracteres)');
        }
        if (!empty($t['telefone'])) {
            $f = Validate::digits($t['telefone']);
            preg_match('/^\d{6,20}$/', $f) ? $o['telefone'] = $f : $e->add('tomador.telefone', '6 a 20 dígitos');
        }
        if (!empty($t['inscricao_municipal'])) {
            is_string($t['inscricao_municipal']) && preg_match('/^[0-9A-Za-z.\-\/]{1,15}$/', $t['inscricao_municipal'])
                ? $o['inscricao_municipal'] = $t['inscricao_municipal'] : $e->add('tomador.inscricao_municipal', '1 a 15 caracteres');
        }
        $end = $t['endereco'] ?? null;
        if ($end !== null) {
            if (!is_array($end)) {
                $e->add('tomador.endereco', 'Objeto esperado');
            } else {
                $a = [];
                $a['logradouro'] = self::texto($end['logradouro'] ?? null, 1, 255, 'tomador.endereco.logradouro', '1 a 255 caracteres', $e);
                $a['numero'] = self::texto($end['numero'] ?? null, 1, 60, 'tomador.endereco.numero', '1 a 60 caracteres', $e);
                if (!empty($end['complemento'])) {
                    $a['complemento'] = self::texto($end['complemento'], 1, 156, 'tomador.endereco.complemento', '1 a 156 caracteres', $e);
                }
                $a['bairro'] = self::texto($end['bairro'] ?? null, 1, 60, 'tomador.endereco.bairro', '1 a 60 caracteres', $e);
                $cm = Validate::digits($end['codigo_municipio'] ?? '');
                if (!preg_match('/^\d{7}$/', $cm) || !in_array((int) substr($cm, 0, 2), Validate::UFS, true)) {
                    $e->add('tomador.endereco.codigo_municipio', 'Código IBGE com 7 dígitos');
                } elseif (($dfErr = Validate::municipioIbgeErro($cm)) !== null) {
                    $e->add('tomador.endereco.codigo_municipio', $dfErr);
                } else {
                    $a['codigo_municipio'] = $cm;
                }
                $cep = Validate::digits($end['cep'] ?? '');
                preg_match('/^\d{8}$/', $cep) ? $a['cep'] = $cep : $e->add('tomador.endereco.cep', 'CEP com 8 dígitos');
                $o['endereco'] = $a;
            }
        }
        return $o;
    }

    /**
     * Tributos aproximados (totTrib, Lei 12.741/2012).
     * @return array{tipo:string, federal?:float, estadual?:float, municipal?:float, percentual_sn?:float}
     */
    private static function tributos(mixed $in, int $op, ?float $vServ, Errors $e): array
    {
        if ($in === null) {
            // padrão: MEI "não informar" (indTotTrib=0); demais, percentuais zerados
            return $op === 2 ? ['tipo' => 'nao_informar'] : ['tipo' => 'percentual', 'federal' => 0.0, 'estadual' => 0.0, 'municipal' => 0.0];
        }
        if (!is_array($in)) {
            $e->add('tributos_aproximados', 'Objeto esperado');
            return ['tipo' => 'nao_informar'];
        }
        $tipo = $in['tipo'] ?? 'percentual';
        $o = ['tipo' => $tipo];
        if ($tipo === 'nao_informar') {
            if ($op !== 2) {
                $e->add('tributos_aproximados.tipo', $op === 3 ? 'ME/EPP não pode usar "nao_informar" (rejeição E0712)' : 'Não optante não pode usar "nao_informar" (rejeição E0713)');
            }
        } elseif ($tipo === 'percentual_sn') {
            if ($op !== 3) {
                $e->add('tributos_aproximados.tipo', '"percentual_sn" só para ME/EPP do Simples (rejeições E0710/E0713)');
            }
            $x = Validate::decimal($in['percentual_sn'] ?? null, 2, 99.99);
            $x === null ? $e->add('tributos_aproximados.percentual_sn', 'Percentual entre 0 e 99,99') : $o['percentual_sn'] = $x;
        } elseif ($tipo === 'percentual' || $tipo === 'valor') {
            foreach (['federal', 'estadual', 'municipal'] as $k) {
                $x = $tipo === 'percentual' ? Validate::decimal($in[$k] ?? 0, 2, 100.0) : self::money($in[$k] ?? 0);
                if ($x === null || ($tipo === 'valor' && $vServ !== null && $x > $vServ)) {
                    $e->add("tributos_aproximados.$k", $tipo === 'percentual' ? 'Percentual entre 0 e 100' : 'Valor >= 0 e <= valor do serviço');
                }
                $o[$k] = $x ?? 0.0;
            }
        } else {
            $e->add('tributos_aproximados.tipo', 'Use "percentual", "valor", "percentual_sn" (ME/EPP) ou "nao_informar" (MEI)');
        }
        return $o;
    }

    /**
     * Texto livre para a DPS. Os tipos TSString do leiaute nacional só aceitam caracteres de U+0020 a U+00FF
     * (padrão "[!-ÿ]..."): pontuação tipográfica comum é convertida (— – → -, aspas curvas → retas, … → ...);
     * o que sobrar fora dessa faixa (emoji, outros alfabetos) é recusado com mensagem clara.
     */
    public static function texto(mixed $v, int $min, int $max, string $campo, string $msg, Errors $e): ?string
    {
        if (is_string($v)) {
            $v = strtr($v, ["\u{2014}" => '-', "\u{2013}" => '-', "\u{2012}" => '-', "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
                "\u{2026}" => '...', "\u{2022}" => '-', "\u{00A0}" => ' ', "\u{20AC}" => 'EUR']);
        }
        $t = Validate::text($v, $min, $max);
        if ($t === null) {
            $e->add($campo, $msg);
            return null;
        }
        if (preg_match('/[^\x{20}-\x{FF}]/u', $t, $m)) {
            $e->add($campo, 'Caractere não aceito pelo leiaute nacional da NFS-e: "' . $m[0] . '" (use letras, números e pontuação comuns)');
            return null;
        }
        return $t;
    }

    /** Valor monetário >= 0 com no máximo 2 casas. */
    public static function money(mixed $v): ?float
    {
        $f = Validate::decimal($v, 6);
        if ($f === null || abs($f * 100 - round($f * 100)) > 1e-6) {
            return null;
        }
        return round($f, 2);
    }

    /** @param array<string,mixed> $v @return array<string,float|null> */
    public static function estimativa(array $v): array
    {
        $base = round($v['servico'] - $v['desconto_incondicionado'], 2);
        $iss = $v['aliquota_iss'] !== null ? round($base * $v['aliquota_iss'] / 100, 2) : null;
        $retido = $v['retencao_iss'] !== 1 && $iss !== null ? $iss : 0.0;
        return [
            'base_calculo' => $base,
            'iss' => $iss,
            'iss_retido' => $retido,
            'valor_liquido' => round($v['servico'] - $v['desconto_incondicionado'] - $v['desconto_condicionado'] - $retido, 2),
        ];
    }

    /** Id da DPS: "DPS" + cMun(7) + tipo de inscrição (2 = CNPJ) + CNPJ(14) + série(5) + número(15). */
    public static function id(string $cMun, string $cnpj, int $serie, int $numero): string
    {
        return 'DPS' . $cMun . '2' . $cnpj . str_pad((string) $serie, 5, '0', STR_PAD_LEFT) . str_pad((string) $numero, 15, '0', STR_PAD_LEFT);
    }

    /**
     * XML da DPS (não assinado).
     * @param array<string,mixed> $p payload validado
     * @param array<string,mixed> $cfg configuração NFS-e
     * @param array<string,mixed> $emit configuração do emitente
     * @return array{xml:string, id:string, dh_emi:string, leiaute:string}
     */
    public static function build(array $p, array $cfg, array $emit, int $numero, int $serie, ?int $agora = null): array
    {
        $cnpj = (string) $emit['cnpj'];
        $cMun = (string) $cfg['codigo_municipio'];
        $id = self::id($cMun, $cnpj, $serie, $numero);
        $leiaute = NfseXml::leiaute((int) $cfg['ambiente']);
        $novo = NfseXml::temFinNFSe($leiaute);
        // alguns segundos no passado: a Sefin rejeita dhEmi posterior ao processamento (E0008)
        $recuo = max(30, min(300, \App\Support\Env::int('NFSE_DHEMI_RECUO_SEGUNDOS', 30)));
        $dhEmi = date('Y-m-d\TH:i:sP', ($agora ?? time()) - $recuo);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS(self::NS, 'DPS');
        $root->setAttribute('versao', self::VERSAO);
        $dom->appendChild($root);
        $inf = self::el($dom, $root, 'infDPS');
        $inf->setAttribute('Id', $id);
        self::el($dom, $inf, 'tpAmb', (string) (int) $cfg['ambiente']);
        self::el($dom, $inf, 'dhEmi', $dhEmi);
        self::el($dom, $inf, 'verAplic', mb_substr('nfephp-' . App::VERSION, 0, 20));
        self::el($dom, $inf, 'serie', (string) $serie);
        self::el($dom, $inf, 'nDPS', (string) $numero);
        self::el($dom, $inf, 'dCompet', (string) $p['competencia']);
        self::el($dom, $inf, 'tpEmit', (string) self::TP_EMIT_PRESTADOR);
        // cMotivoEmisTI (motivo da emissão pelo Tomador/Intermediário) só existe para tpEmit 2 ou 3; este emissor
        // emite sempre como prestador (tpEmit 1), então o campo nunca é informado.
        if ($novo) {
            // leiaute 20261020+ (NT 009): finalidade obrigatória; o XSD aceita só 0 = NFS-e regular
            self::el($dom, $inf, 'finNFSe', self::FIN_NFSE_REGULAR);
        }
        self::el($dom, $inf, 'cLocEmi', $cMun);

        // prestador emitente: sem xNome e sem end (E0121/E0128); dados vêm do CNC/CNPJ
        $prest = self::el($dom, $inf, 'prest');
        self::el($dom, $prest, 'CNPJ', $cnpj);
        if (!empty($cfg['inscricao_municipal'])) {
            self::el($dom, $prest, 'IM', (string) $cfg['inscricao_municipal']);
        }
        $rt = self::el($dom, $prest, 'regTrib');
        self::el($dom, $rt, 'opSimpNac', (string) (int) $cfg['opcao_simples_nacional']);
        if ((int) $cfg['opcao_simples_nacional'] === 3) {
            self::el($dom, $rt, 'regApTribSN', (string) (int) $cfg['regime_apuracao_sn']);
        }
        self::el($dom, $rt, 'regEspTrib', (string) (int) ($cfg['regime_especial'] ?? 0));

        $t = $p['tomador'];
        if ($t !== null) {
            $toma = self::el($dom, $inf, 'toma');
            isset($t['cnpj']) ? self::el($dom, $toma, 'CNPJ', $t['cnpj']) : self::el($dom, $toma, 'CPF', $t['cpf']);
            if (isset($t['inscricao_municipal'])) {
                self::el($dom, $toma, 'IM', $t['inscricao_municipal']);
            }
            self::el($dom, $toma, 'xNome', $t['nome']);
            if (isset($t['endereco'])) {
                $a = $t['endereco'];
                $end = self::el($dom, $toma, 'end');
                $nac = self::el($dom, $end, 'endNac');
                self::el($dom, $nac, 'cMun', $a['codigo_municipio']);
                self::el($dom, $nac, 'CEP', $a['cep']);
                self::el($dom, $end, 'xLgr', $a['logradouro']);
                self::el($dom, $end, 'nro', $a['numero']);
                if (isset($a['complemento'])) {
                    self::el($dom, $end, 'xCpl', $a['complemento']);
                }
                self::el($dom, $end, 'xBairro', $a['bairro']);
            }
            if (isset($t['telefone'])) {
                self::el($dom, $toma, 'fone', $t['telefone']);
            }
            if (isset($t['email'])) {
                self::el($dom, $toma, 'email', $t['email']);
            }
        }

        $s = $p['servico'];
        $serv = self::el($dom, $inf, 'serv');
        $loc = self::el($dom, $serv, 'locPrest');
        self::el($dom, $loc, 'cLocPrestacao', $s['municipio_prestacao']);
        $cs = self::el($dom, $serv, 'cServ');
        self::el($dom, $cs, 'cTribNac', $s['codigo_tributacao_nacional']);
        if (isset($s['codigo_tributacao_municipal'])) {
            self::el($dom, $cs, 'cTribMun', $s['codigo_tributacao_municipal']);
        }
        if (!$novo) {
            self::el($dom, $cs, 'xDescServ', $s['descricao']); // leiaute 20260209: logo após cTribMun
        }
        if (isset($s['nbs'])) {
            self::el($dom, $cs, 'cNBS', $s['nbs']);
        }
        if (isset($s['codigo_interno'])) {
            self::el($dom, $cs, 'cIntContrib', $s['codigo_interno']);
        }
        if ($novo) {
            self::el($dom, $cs, 'xDescServ', $s['descricao']); // leiaute 20261020+: último elemento de cServ
        }
        if (isset($p['informacoes_complementares']) || isset($p['pedido_id'])) {
            $ic = self::el($dom, $serv, 'infoCompl');
            if (isset($p['pedido_id'])) {
                self::el($dom, $ic, 'xPed', $p['pedido_id']);
            }
            if (isset($p['informacoes_complementares'])) {
                self::el($dom, $ic, 'xInfComp', $p['informacoes_complementares']);
            }
        }

        $v = $p['valores'];
        $val = self::el($dom, $inf, 'valores');
        $vsp = self::el($dom, $val, 'vServPrest');
        self::el($dom, $vsp, 'vServ', self::dec($v['servico']));
        if ($v['desconto_incondicionado'] > 0 || $v['desconto_condicionado'] > 0) {
            $dsc = self::el($dom, $val, 'vDescCondIncond');
            if ($v['desconto_incondicionado'] > 0) {
                self::el($dom, $dsc, 'vDescIncond', self::dec($v['desconto_incondicionado']));
            }
            if ($v['desconto_condicionado'] > 0) {
                self::el($dom, $dsc, 'vDescCond', self::dec($v['desconto_condicionado']));
            }
        }
        if (isset($v['deducoes'])) { TributosInformados::deducoes($dom, $val, $v['deducoes'], $novo); }
        $trib = self::el($dom, $val, 'trib');
        $tm = self::el($dom, $trib, 'tribMun');
        self::el($dom, $tm, 'tribISSQN', (string) $v['tributacao_iss']);
        if ($v['tributacao_iss'] === 2) {
            self::el($dom, $tm, 'tpImunidade', (string) $v['tipo_imunidade']);
        }
        if (isset($v['suspensao'])) { TributosInformados::suspensao($dom, $tm, $v['suspensao']); }
        self::el($dom, $tm, 'tpRetISSQN', (string) $v['retencao_iss']);
        if ($v['aliquota_iss'] !== null) {
            self::el($dom, $tm, 'pAliq', self::dec($v['aliquota_iss']));
        }
        if (isset($p['tributos_federais'])) { TributosInformados::federais($dom, $trib, $p['tributos_federais']); }
        $tt = self::el($dom, $trib, 'totTrib');
        $ta = $p['tributos_aproximados'];
        switch ($ta['tipo']) {
            case 'valor':
                $g = self::el($dom, $tt, 'vTotTrib');
                self::el($dom, $g, 'vTotTribFed', self::dec($ta['federal']));
                self::el($dom, $g, 'vTotTribEst', self::dec($ta['estadual']));
                self::el($dom, $g, 'vTotTribMun', self::dec($ta['municipal']));
                break;
            case 'percentual':
                $g = self::el($dom, $tt, 'pTotTrib');
                self::el($dom, $g, 'pTotTribFed', self::dec($ta['federal']));
                self::el($dom, $g, 'pTotTribEst', self::dec($ta['estadual']));
                self::el($dom, $g, 'pTotTribMun', self::dec($ta['municipal']));
                break;
            case 'percentual_sn':
                self::el($dom, $tt, 'pTotTribSN', self::dec($ta['percentual_sn']));
                break;
            default:
                self::el($dom, $tt, 'indTotTrib', '0');
        }

        if (isset($p['ibs_cbs'])) { IbsCbsInformados::montar($dom, $inf, $p['ibs_cbs'], $novo); }
        $xml = (string) $dom->saveXML($dom->documentElement);
        return ['xml' => '<?xml version="1.0" encoding="UTF-8"?>' . $xml, 'id' => $id, 'dh_emi' => $dhEmi, 'leiaute' => $leiaute];
    }

    /**
     * Pedido de registro do evento de cancelamento (e101101), não assinado.
     * Id = "PRE" + chave (50) + código do evento (101101).
     */
    public static function pedidoCancelamento(string $chave, string $cnpj, int $ambiente, int $codigo, string $motivo, ?int $agora = null): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS(self::NS, 'pedRegEvento');
        $root->setAttribute('versao', self::VERSAO);
        $dom->appendChild($root);
        $inf = self::el($dom, $root, 'infPedReg');
        $inf->setAttribute('Id', 'PRE' . $chave . '101101');
        self::el($dom, $inf, 'tpAmb', (string) $ambiente);
        self::el($dom, $inf, 'verAplic', mb_substr('nfephp-' . App::VERSION, 0, 20));
        $recuo=max(30,min(300,\App\Support\Env::int('NFSE_DHEMI_RECUO_SEGUNDOS',30)));
        self::el($dom, $inf, 'dhEvento', date('Y-m-d\TH:i:sP', ($agora ?? time()) - $recuo));
        self::el($dom, $inf, 'CNPJAutor', $cnpj);
        self::el($dom, $inf, 'chNFSe', $chave);
        $ev = self::el($dom, $inf, 'e101101');
        self::el($dom, $ev, 'xDesc', 'Cancelamento de NFS-e');
        self::el($dom, $ev, 'cMotivo', (string) $codigo);
        self::el($dom, $ev, 'xMotivo', $motivo);
        return '<?xml version="1.0" encoding="UTF-8"?>' . $dom->saveXML($dom->documentElement);
    }

    private static function dec(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private static function el(\DOMDocument $dom, \DOMElement $parent, string $name, ?string $text = null): \DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name);
        if ($text !== null) {
            $el->appendChild($dom->createTextNode($text));
        }
        $parent->appendChild($el);
        return $el;
    }
}
