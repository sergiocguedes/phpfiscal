<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Support\Errors;

/**
 * Validação e normalização do JSON de POST /api/v1/tributos/calcular.
 * Valores numéricos viram string decimal (Dec); campos desconhecidos são recusados.
 */
final class Entrada
{
    public const MAX_ITENS = 990;

    private const UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI',
        'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];

    /** Campos por grupo da regra: tipo => [casas decimais | 'texto' | regex]. */
    public const CAMPOS = [
        'icms' => [
            // parâmetros
            'orig' => '/^[0-8]$/', 'CST' => '/^\d{2}$/', 'CSOSN' => '/^\d{3}$/', 'modBC' => '/^[0-3]$/', 'modBCST' => '/^[0-6]$/',
            'pICMS' => 4, 'pRedBC' => 4, 'pMVAST' => 4, 'pICMSST' => 4, 'pRedBCST' => 4, 'pDif' => 4,
            'pCredSN' => 4, 'pFCP' => 4, 'pFCPST' => 4, 'adRemICMS' => 4, 'adRemICMSRet' => 4,
            'motDesICMS' => '/^(1|3|4|5|6|7|8|9|10|11|12|16|90)$/', 'indDeduzDeson' => '/^[01]$/',
            // CST 30/40/41/50/90: como calcular o desonerado, conforme a base legal do benefício
            'metodo_desoneracao' => '/^(por_fora|por_dentro)$/D',
            // informados no CST 60 / CSOSN 500 (ST retido anteriormente): repassados sem cálculo
            'vBCSTRet' => 2, 'pST' => 4, 'vICMSSubstituto' => 2, 'vICMSSTRet' => 2, 'vBCFCPSTRet' => 2, 'pFCPSTRet' => 4, 'vFCPSTRet' => 2,
            // valores finais (ajuste manual)
            'vBC' => 2, 'vICMS' => 2, 'vBCST' => 2, 'vICMSST' => 2, 'vICMSDeson' => 2, 'vCredICMSSN' => 2, 'vFCP' => 2, 'vFCPST' => 2,
            'vICMSOp' => 2, 'vICMSDif' => 2, 'qBCMono' => 4, 'vICMSMono' => 2, 'qBCMonoRet' => 4, 'vICMSMonoRet' => 2,
        ],
        'pis' => ['CST' => '/^\d{2}$/', 'pPIS' => 4, 'vAliqProd' => 4, 'vBC' => 2, 'vPIS' => 2, 'qBCProd' => 4],
        'cofins' => ['CST' => '/^\d{2}$/', 'pCOFINS' => 4, 'vAliqProd' => 4, 'vBC' => 2, 'vCOFINS' => 2, 'qBCProd' => 4],
        'ipi' => ['CST' => '/^\d{2}$/', 'cEnq' => '/^\d{3}$/', 'pIPI' => 4, 'vUnid' => 4, 'vBC' => 2, 'vIPI' => 2, 'qUnid' => 4],
        'iss' => ['aliquota' => 4, 'cMunFG' => '/^\d{7}$/', 'cListServ' => '/^\d{2}\.\d{2}$/', 'indISS' => '/^[1-7]$/',
            'indIncentivo' => '/^[12]$/', 'vBC' => 2, 'vISSQN' => 2],
        'ibpt' => ['federal' => 4, 'estadual' => 4, 'municipal' => 4],
        // Reforma Tributária: cClassTrib do produto (CST, redução e vigência vêm da tabela local) e diferimento (CST 510)
        'ibscbs' => ['CST' => '/^\d{3}$/D', 'cClassTrib' => '/^\d{6}$/D', 'pDif' => 4],
    ];

    /** Percentuais acima de 100 aceitos (MVA). */
    public const SEM_TETO = ['pMVAST'];

    /**
     * @param array<mixed> $in
     * @param array<string,mixed>|null $cfg configuração do emitente (crt, uf) para os padrões
     * @return array{modelo:int, crt:int, uf:string, uf_destino:string, consumidor_final:bool, contribuinte_icms:bool,
     *   desconto_iss_condicionado:bool, excluir_icms_base_piscofins:bool, itens:list<ItemContexto>}
     */
    public static function normalizar(array $in, ?array $cfg): array
    {
        $e = new Errors();
        foreach (array_keys($in) as $k) {
            if (!in_array($k, ['modelo', 'emitente', 'operacao', 'rateio', 'itens'], true)) {
                $e->add((string) $k, 'Campo não permitido');
            }
        }
        $modelo = $in['modelo'] ?? 55;
        if (!in_array($modelo, [55, 65, '55', '65'], true)) {
            $e->add('modelo', 'Use 55 (NF-e) ou 65 (NFC-e)');
        }
        $modelo = (int) $modelo;

        $em = self::obj($in, 'emitente', $e);
        $crt = $em['crt'] ?? $cfg['crt'] ?? null;
        if (!in_array($crt, [1, 2, 3, 4, '1', '2', '3', '4'], true)) {
            $e->add('emitente.crt', 'Informe o CRT (1 Simples, 2 Simples excesso, 3 Normal, 4 MEI) ou configure o emitente');
        }
        $uf = strtoupper((string) ($em['uf'] ?? $cfg['uf'] ?? ''));
        if (!in_array($uf, self::UFS, true)) {
            $e->add('emitente.uf', 'Informe a UF do emitente ou configure o emitente');
        }
        $munEmit = (string) ($em['codigo_municipio'] ?? $cfg['endereco']['codigo_municipio'] ?? '');
        if (isset($em['codigo_municipio']) && Municipios::get($munEmit) === null) {
            $e->add('emitente.codigo_municipio', 'Código IBGE do município (7 dígitos)');
        }
        foreach (array_keys($em) as $k) {
            if (!in_array($k, ['crt', 'uf', 'codigo_municipio'], true)) {
                $e->add("emitente.$k", 'Campo não permitido');
            }
        }

        $op = self::obj($in, 'operacao', $e);
        $permitidos = ['codigo', 'tipo_pessoa', 'uf_destino', 'consumidor_final', 'contribuinte_icms', 'desconto_iss_condicionado',
            'excluir_icms_base_piscofins', 'data', 'aliquotas_ibscbs', 'municipio_destino', 'credito_presumido'];
        foreach (array_keys($op) as $k) {
            if ($k === 'metodo_desoneracao') {
                $e->add('operacao.metodo_desoneracao', 'Agora fica na regra de ICMS: regra.icms.metodo_desoneracao (CST 30, 40, 41, 50 e 90)');
            } elseif (!in_array($k, $permitidos, true)) {
                $e->add("operacao.$k", 'Campo não permitido');
            }
        }
        $ufDest = strtoupper((string) ($op['uf_destino'] ?? $uf));
        if (!in_array($ufDest, self::UFS, true) && $ufDest !== 'EX') {
            $e->add('operacao.uf_destino', 'UF inválida');
        }
        $bools = [];
        foreach (['consumidor_final' => $modelo === 65, 'contribuinte_icms' => false, 'desconto_iss_condicionado' => false, 'excluir_icms_base_piscofins' => false] as $k => $padrao) {
            $v = $op[$k] ?? $padrao;
            if (!is_bool($v)) {
                $e->add("operacao.$k", 'true ou false');
                $v = $padrao;
            }
            $bools[$k] = $v;
        }
        $opCodigo = $op['codigo'] ?? null;
        if (is_int($opCodigo)) {
            $opCodigo = (string) $opCodigo;
        }
        if ($opCodigo !== null && (!is_string($opCodigo) || !preg_match('/^[A-Za-z0-9_-]{1,30}$/D', $opCodigo))) {
            $e->add('operacao.codigo', 'Código da operação cadastrada na matriz de regras');
            $opCodigo = null;
        }
        // data da operação (emissão): define as alíquotas do IBS/CBS do ano e se vItem soma IBS/CBS (a partir de 2027)
        $data = $op['data'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        if (!is_string($data) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $data, $md) || !checkdate((int) $md[2], (int) $md[3], (int) $md[1])) {
            $e->add('operacao.data', 'Data AAAA-MM-DD');
            $md = [1 => date('Y')];
        }
        $ano = (int) $md[1];
        $data = is_string($data) && isset($md[2]) ? substr($data, 0, 10) : null;
        // município da operação para as alíquotas do IBS/CBS: o do destinatário; sem ele, o do emitente
        $munDest = $op['municipio_destino'] ?? null;
        if ($munDest !== null && (!is_string($munDest) || Municipios::get($munDest) === null)) {
            $e->add('operacao.municipio_destino', 'Código IBGE do município (7 dígitos)');
            $munDest = null;
        }
        $munOp = $munDest ?? ($munEmit !== '' ? $munEmit : null);
        // alíquotas do IBS/CBS informadas no JSON têm prioridade sobre a tabela da API
        $aliqIbs = null;
        if (array_key_exists('aliquotas_ibscbs', $op)) {
            $a = $op['aliquotas_ibscbs'];
            if (!is_array($a) || array_is_list($a)) {
                $e->add('operacao.aliquotas_ibscbs', 'Objeto {pIBSUF, pIBSMun, pCBS} em %');
            } else {
                $aliqIbs = [];
                foreach (array_keys($a) as $k) {
                    in_array($k, ['pIBSUF', 'pIBSMun', 'pCBS'], true) ?: $e->add("operacao.aliquotas_ibscbs.$k", 'Campo não permitido');
                }
                foreach (['pIBSUF', 'pIBSMun', 'pCBS'] as $k) {
                    $d = self::valor($a[$k] ?? null, 4);
                    if ($d === null || Dec::cmp($d, '100') > 0) {
                        $e->add("operacao.aliquotas_ibscbs.$k", 'Percentual de 0 a 100 com até 4 casas (obrigatório)');
                    } else {
                        $aliqIbs[$k] = $d;
                    }
                }
            }
        }
        // crédito presumido do IBS/CBS (cCredPres): vem da natureza da operação no ERP; o item pode trocar
        $credPresOp = array_key_exists('credito_presumido', $op) ? self::credPres($op['credito_presumido'], 'operacao.credito_presumido', $e) : null;
        $tipoPessoa = strtoupper((string) ($op['tipo_pessoa'] ?? 'F'));
        if (!in_array($tipoPessoa, Matriz::TIPOS_PESSOA, true)) {
            $e->add('operacao.tipo_pessoa', 'F (física), J (jurídica) ou E (estrangeiro)');
        }

        $rateio = self::obj($in, 'rateio', $e);
        $rat = [];
        foreach ($rateio as $k => $v) {
            if (!in_array($k, ['desconto', 'acrescimo', 'frete', 'seguro'], true)) {
                $e->add("rateio.$k", 'Campo não permitido');
                continue;
            }
            $d = self::valor($v, 2);
            $d === null ? $e->add("rateio.$k", 'Número >= 0 com até 2 casas') : $rat[$k] = $d;
        }

        $itensIn = $in['itens'] ?? null;
        $itens = [];
        if (!is_array($itensIn) || !array_is_list($itensIn) || $itensIn === []) {
            $e->add('itens', 'Lista com ao menos um item');
            $itensIn = [];
        } elseif (count($itensIn) > self::MAX_ITENS) {
            $e->add('itens', 'Máximo de ' . self::MAX_ITENS . ' itens');
            $itensIn = [];
        }
        $ctx = ['operacao' => $opCodigo, 'tipo_pessoa' => $tipoPessoa, 'uf' => (int) $modelo === 65 ? $uf : $ufDest];
        foreach ($itensIn as $i => $it) {
            $item = self::item($it, $i, $e, $ctx);
            if ($item !== null) {
                if (is_array($it) && array_key_exists('credito_presumido', $it)) {
                    $cp = self::credPres($it['credito_presumido'], "itens[$i].credito_presumido", $e);
                    $item->credPres = $cp === null ? null : $cp + ['origem' => 'item'];
                } elseif ($credPresOp !== null) {
                    $item->credPres = $credPresOp + ['origem' => 'operacao'];
                }
                $itens[] = $item;
            }
        }
        $e->throwIfAny();

        self::ratear($itens, $rat);
        foreach ($itens as $it) {
            if (Dec::cmp($it->vLiq, '0') < 0) {
                $e->add("$it->campo.desconto", 'Desconto maior que o valor do item');
            }
        }
        $e->throwIfAny();

        return [
            'modelo' => $modelo, 'crt' => (int) $crt, 'uf' => $uf, 'uf_destino' => $ufDest,
            'consumidor_final' => $bools['consumidor_final'], 'contribuinte_icms' => $bools['contribuinte_icms'],
            'desconto_iss_condicionado' => $bools['desconto_iss_condicionado'],
            'excluir_icms_base_piscofins' => $bools['excluir_icms_base_piscofins'],
            'operacao_codigo' => $opCodigo, 'tipo_pessoa' => $tipoPessoa, 'ano' => $ano, 'data' => $data,
            'aliquotas_ibscbs' => $aliqIbs, 'municipio_operacao' => $munOp, 'itens' => $itens,
        ];
    }

    /**
     * Crédito presumido do IBS/CBS: {cCredPres, pCredPresIBS?, pCredPresCBS?}. null no item = sem crédito presumido
     * (anula o da operação).
     * @return array{cCredPres:string, pCredPresIBS:?string, pCredPresCBS:?string}|null
     */
    private static function credPres(mixed $v, string $f, Errors $e): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v) || array_is_list($v)) {
            $e->add($f, 'Objeto {cCredPres, pCredPresIBS, pCredPresCBS} ou null');
            return null;
        }
        foreach (array_keys($v) as $k) {
            in_array($k, ['cCredPres', 'pCredPresIBS', 'pCredPresCBS'], true) ?: $e->add("$f.$k", 'Campo não permitido');
        }
        $c = $v['cCredPres'] ?? null;
        $c = is_int($c) ? (string) $c : $c;
        if (!is_string($c) || !preg_match('/^\d{1,2}$/D', $c) || (int) $c === 0) {
            $e->add("$f.cCredPres", 'Código da tabela cCredPres (1 a 99)');
            return null;
        }
        $out = ['cCredPres' => str_pad($c, 2, '0', STR_PAD_LEFT), 'pCredPresIBS' => null, 'pCredPresCBS' => null];
        foreach (['pCredPresIBS', 'pCredPresCBS'] as $k) {
            if (isset($v[$k])) {
                $d = self::valor($v[$k], 4);
                $d === null || Dec::cmp($d, '100') > 0 ? $e->add("$f.$k", 'Percentual de 0 a 100 com até 4 casas') : $out[$k] = $d;
            }
        }
        if ($out['pCredPresIBS'] === null && $out['pCredPresCBS'] === null) {
            $e->add($f, 'Informe pCredPresIBS e/ou pCredPresCBS (percentual do crédito presumido)');
            return null;
        }
        return $out;
    }

    /** @param array{operacao: ?string, tipo_pessoa: string, uf: string} $ctx */
    private static function item(mixed $it, int $i, Errors $e, array $ctx): ?ItemContexto
    {
        $f = "itens[$i]";
        if (!is_array($it) || array_is_list($it)) {
            $e->add($f, 'Objeto do item');
            return null;
        }
        $antes = count($e->all());
        $permitidos = ['item', 'codigo', 'ncm', 'cest', 'servico', 'quantidade', 'valor_unitario', 'valor_total', 'desconto', 'frete',
            'seguro', 'outras_despesas', 'qtd_tributavel_unidade', 'valor_pauta_st', 'origem', 'perfil', 'perfil_icms', 'perfil_piscofins',
            'cclasstrib', 'credito_presumido', 'regra'];
        foreach (array_keys($it) as $k) {
            if (!in_array($k, $permitidos, true)) {
                $e->add("$f.$k", 'Campo não permitido');
            }
        }
        $n = $it['item'] ?? ($i + 1);
        if (!is_int($n) || $n < 1 || $n > 990) {
            $e->add("$f.item", 'Inteiro de 1 a 990');
            $n = $i + 1;
        }
        $servico = $it['servico'] ?? false;
        if (!is_bool($servico)) {
            $e->add("$f.servico", 'true ou false');
            $servico = false;
        }
        $q = self::valor($it['quantidade'] ?? null, 4);
        if ($q === null || Dec::zero($q)) {
            $e->add("$f.quantidade", 'Número > 0 com até 4 casas');
        }
        $vProd = null;
        if (isset($it['valor_total'])) {
            $vProd = self::valor($it['valor_total'], 2);
            $vProd === null ? $e->add("$f.valor_total", 'Número >= 0 com até 2 casas') : null;
        } else {
            $vu = self::valor($it['valor_unitario'] ?? null, 10);
            if ($vu === null || Dec::zero($vu)) {
                $e->add("$f.valor_unitario", 'Número > 0 com até 10 casas (ou informe valor_total)');
            } elseif ($q !== null) {
                $vProd = Dec::meio(Dec::mul($q, $vu), 2);
            }
        }
        $acess = [];
        foreach (['desconto', 'frete', 'seguro', 'outras_despesas'] as $k) {
            $v = isset($it[$k]) ? self::valor($it[$k], 2) : '0';
            $v === null ? $e->add("$f.$k", 'Número >= 0 com até 2 casas') : $acess[$k] = $v;
        }
        $qTrib = null;
        if (isset($it['qtd_tributavel_unidade'])) {
            $qTrib = self::valor($it['qtd_tributavel_unidade'], 4);
            $qTrib === null ? $e->add("$f.qtd_tributavel_unidade", 'Número >= 0 com até 4 casas') : null;
        }
        $pauta = null;
        if (isset($it['valor_pauta_st'])) {
            $pauta = self::valor($it['valor_pauta_st'], 4);
            $pauta === null ? $e->add("$f.valor_pauta_st", 'Número >= 0 com até 4 casas') : null;
        }
        $antesRegra = count($e->all());
        [$bruta, $aplicada] = self::resolverRegra($it, $f, $servico, $ctx, $e);
        if (count($e->all()) > $antesRegra) {
            return null; // regra da matriz não encontrada: não repete erros de campos da regra vazia
        }
        // IBS/CBS: cClassTrib do produto (o CST e a redução vêm da tabela local de cClassTrib)
        if (isset($it['cclasstrib']) && !$servico) {
            $cc = is_int($it['cclasstrib']) ? str_pad((string) $it['cclasstrib'], 6, '0', STR_PAD_LEFT) : $it['cclasstrib'];
            if (!is_string($cc) || !preg_match('/^\d{6}$/D', $cc)) {
                $e->add("$f.cclasstrib", 'cClassTrib com 6 dígitos');
            } elseif (is_array($bruta) && !array_is_list($bruta)) {
                $g = is_array($bruta['ibscbs'] ?? null) ? $bruta['ibscbs'] : [];
                if (isset($g['cClassTrib']) && (string) $g['cClassTrib'] !== $cc) {
                    $e->add("$f.cclasstrib", 'Diferente de regra.ibscbs.cClassTrib');
                }
                $bruta['ibscbs'] = ['cClassTrib' => $cc] + $g;
            }
        }
        $regra = self::regra($bruta, "$f.regra", $servico, $e);
        if (count($e->all()) > $antes || $q === null || $vProd === null) {
            return null;
        }
        $c = new ItemContexto($n, $f, $servico, $q, $vProd, $acess['desconto'], $acess['frete'], $acess['seguro'],
            $acess['outras_despesas'], $qTrib, $regra);
        $c->pautaST = $pauta;
        $c->regraAplicada = $aplicada;
        return $c;
    }

    /**
     * Regra do item: a da matriz (perfil_icms / perfil_piscofins + operação + UF + tipo de pessoa), com os campos
     * de "regra" informados por cima. Trocar o CST/CSOSN na regra informada substitui o grupo inteiro da matriz.
     * @param array<mixed> $it
     * @param array{operacao: ?string, tipo_pessoa: string, uf: string} $ctx
     * @return array{0: mixed, 1: ?array<string,mixed>}
     */
    private static function resolverRegra(array $it, string $f, bool $servico, array $ctx, Errors $e): array
    {
        $informada = $it['regra'] ?? null;
        // um perfil vale para ICMS e PIS/COFINS; perfil_icms/perfil_piscofins (legado) trocam um deles
        $pIcms = $it['perfil_icms'] ?? $it['perfil'] ?? null;
        $pPc = $it['perfil_piscofins'] ?? $it['perfil'] ?? null;
        $origem = $it['origem'] ?? null;
        if ($pIcms === null && $pPc === null) {
            if ($origem !== null && is_array($informada) && !array_is_list($informada) && !isset($informada['icms']['orig'])) {
                $informada['icms']['orig'] = $origem;
            }
            return [$informada, null];
        }
        if ($informada !== null && (!is_array($informada) || array_is_list($informada))) {
            $e->add("$f.regra", 'Objeto com os campos que sobrepõem a regra da matriz');
            return [[], null];
        }
        if ($ctx['operacao'] === null) {
            $e->add('operacao.codigo', 'Informe a operação (código cadastrado na matriz) para usar perfis tributários');
            return [[], null];
        }
        $base = [];
        $aplicada = ['icms' => null, 'piscofins' => null, 'sobreposicoes' => []];
        if ($pIcms !== null && !$servico) {
            $pIcms = is_int($pIcms) ? (string) $pIcms : $pIcms;
            $r = is_string($pIcms) ? Matriz::regraIcms($pIcms, $ctx['operacao'], $ctx['uf'], $ctx['tipo_pessoa']) : null;
            if ($r === null) {
                $e->add("$f.perfil_icms", "Sem regra de ICMS para perfil " . (is_string($pIcms) ? $pIcms : '?') . ", operação {$ctx['operacao']}, UF {$ctx['uf']}, pessoa {$ctx['tipo_pessoa']}");
            } else {
                $base['cfop'] = $r['cfop'];
                $base['icms'] = $r['icms'];
                if (isset($r['ipi'])) {
                    $base['ipi'] = $r['ipi'];
                }
                $aplicada['icms'] = ['perfil' => $r['perfil'], 'operacao' => $r['operacao'], 'uf' => $r['uf'], 'tipo_pessoa' => $r['tipo_pessoa'], 'descricao' => $r['descricao']];
            }
        }
        if ($pPc !== null) {
            $pPc = is_int($pPc) ? (string) $pPc : $pPc;
            $r = is_string($pPc) ? Matriz::regraPisCofins($pPc, $ctx['operacao']) : null;
            if ($r === null) {
                $e->add("$f.perfil_piscofins", "Sem regra de PIS/COFINS para perfil " . (is_string($pPc) ? $pPc : '?') . ", operação {$ctx['operacao']}");
            } else {
                $base['pis'] = $r['pis'];
                $base['cofins'] = $r['cofins'];
                $aplicada['piscofins'] = ['perfil' => $r['perfil'], 'operacao' => $r['operacao'], 'descricao' => $r['descricao']];
            }
        }
        foreach ($informada ?? [] as $grupo => $campos) {
            if ($grupo === 'cfop' || !is_array($campos) || !is_array($base[$grupo] ?? null)) {
                $base[$grupo] = $campos;
                $aplicada['sobreposicoes'][] = (string) $grupo;
                continue;
            }
            $trocaCst = (isset($campos['CST']) && (string) $campos['CST'] !== (string) ($base[$grupo]['CST'] ?? ''))
                || (isset($campos['CSOSN']) && (string) $campos['CSOSN'] !== (string) ($base[$grupo]['CSOSN'] ?? ''));
            $base[$grupo] = $trocaCst ? $campos : array_merge($base[$grupo], $campos);
            foreach (array_keys($trocaCst ? ['*' => 1] : $campos) as $k) {
                $aplicada['sobreposicoes'][] = $trocaCst ? "$grupo (CST trocado)" : "$grupo.$k";
            }
        }
        if ($origem !== null && isset($base['icms']) && !isset($base['icms']['orig'])) {
            $base['icms']['orig'] = $origem;
        }
        return [$base, $aplicada];
    }

    /** @return array<string,mixed> */
    private static function regra(mixed $r, string $f, bool $servico, Errors $e): array
    {
        if (!is_array($r) || array_is_list($r)) {
            $e->add($f, 'Objeto com cfop, icms, pis, cofins (e opcionais ipi, iss, ibpt)');
            return [];
        }
        $out = [];
        foreach ($r as $grupo => $campos) {
            if ($grupo === 'cfop') {
                $cfop = is_string($campos) || is_int($campos) ? (string) $campos : '';
                preg_match('/^[1-35-7]\d{3}$/D', $cfop) ? $out['cfop'] = $cfop : $e->add("$f.cfop", 'CFOP com 4 dígitos');
                continue;
            }
            if (!isset(self::CAMPOS[$grupo])) {
                $e->add("$f.$grupo", 'Grupo não permitido');
                continue;
            }
            if (!is_array($campos) || array_is_list($campos)) {
                $e->add("$f.$grupo", 'Objeto com os campos do leiaute');
                continue;
            }
            foreach ($campos as $k => $v) {
                $tipo = self::CAMPOS[$grupo][$k] ?? null;
                if ($tipo === null) {
                    $e->add("$f.$grupo.$k", 'Campo não permitido');
                    continue;
                }
                if ($v === null) {
                    continue;
                }
                if (is_int($tipo)) {
                    $d = self::valor($v, $tipo);
                    if ($d === null) {
                        $e->add("$f.$grupo.$k", "Número >= 0 com até $tipo casas");
                    } elseif (str_starts_with($k, 'p') && !in_array($k, self::SEM_TETO, true) && Dec::cmp($d, '100') > 0) {
                        $e->add("$f.$grupo.$k", 'Percentual entre 0 e 100');
                    } else {
                        $out[$grupo][$k] = $d;
                    }
                } else {
                    $s = is_int($v) ? (string) $v : $v;
                    is_string($s) && preg_match($tipo, $s) ? $out[$grupo][$k] = $s : $e->add("$f.$grupo.$k", 'Valor inválido');
                }
            }
        }
        if (!$servico) {
            if (!isset($out['icms']['CST']) && !isset($out['icms']['CSOSN'])) {
                $e->add("$f.icms", 'Informe CST (regime normal) ou CSOSN (Simples Nacional)');
            }
            if (!isset($out['icms']['orig'])) {
                $e->add("$f.icms.orig", 'Origem da mercadoria obrigatória (0 a 8): informe origem no item ou regra.icms.orig');
            }
            if (isset($out['icms']['metodo_desoneracao']) && !in_array($out['icms']['CST'] ?? null, ['30', '40', '41', '50', '90'], true)) {
                $e->add("$f.icms.metodo_desoneracao", 'Só se aplica aos CST 30, 40, 41, 50 e 90');
            }
            if (isset($out['ibscbs'])) {
                $out['ibscbs'] = IbsCbs::validar($out['ibscbs'], "$f.ibscbs", $e);
            }
        } elseif (!isset($out['iss']['aliquota'])) {
            $e->add("$f.iss.aliquota", 'Alíquota do ISS obrigatória para serviço');
        }
        foreach (['pis', 'cofins'] as $g) {
            if (!isset($out[$g]['CST'])) {
                $e->add("$f.$g.CST", 'Obrigatório');
            }
        }
        return $out;
    }

    /**
     * Rateio proporcional ao vProd dos valores informados no cabeçalho. A diferença de centavos
     * vai para o item de maior valor.
     * @param list<ItemContexto> $itens
     * @param array<string,string> $rat
     */
    private static function ratear(array $itens, array $rat): void
    {
        $total = Dec::soma(...array_map(fn(ItemContexto $i) => $i->vProd, $itens));
        if ($itens === [] || Dec::zero($total)) {
            return;
        }
        $maior = 0;
        foreach ($itens as $k => $it) {
            if (Dec::cmp($it->vProd, $itens[$maior]->vProd) > 0) {
                $maior = $k;
            }
        }
        $prop = ['desconto' => 'vDesc', 'acrescimo' => 'vOutro', 'frete' => 'vFrete', 'seguro' => 'vSeg'];
        foreach ($rat as $tipo => $valor) {
            if (Dec::zero($valor)) {
                continue;
            }
            $distribuido = '0';
            foreach ($itens as $it) {
                $parte = Dec::meio(Dec::div(Dec::mul($valor, $it->vProd), $total), 2);
                $it->{$prop[$tipo]} = Dec::soma($it->{$prop[$tipo]}, $parte);
                $distribuido = Dec::soma($distribuido, $parte);
            }
            $sobra = Dec::sub($valor, $distribuido);
            if (!Dec::zero($sobra)) {
                $itens[$maior]->{$prop[$tipo]} = Dec::soma($itens[$maior]->{$prop[$tipo]}, $sobra);
            }
        }
        foreach ($itens as $it) {
            $it->vLiq = Dec::sub($it->vProd, $it->vDesc);
        }
    }

    /** @return array<string,mixed> */
    private static function obj(array $in, string $k, Errors $e): array
    {
        $v = $in[$k] ?? [];
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            $e->add($k, 'Objeto');
            return [];
        }
        return $v;
    }

    /** Número >= 0 com até $casas decimais (como string). */
    public static function valor(mixed $v, int $casas): ?string
    {
        if (is_string($v) && !preg_match('/^\d{1,15}(\.\d+)?$/D', $v)) {
            return null;
        }
        $d = Dec::de($v);
        if ($d === null || Dec::cmp($d, '0') < 0) {
            return null;
        }
        $frac = explode('.', $d)[1] ?? '';
        return strlen($frac) > $casas ? null : $d;
    }
}
