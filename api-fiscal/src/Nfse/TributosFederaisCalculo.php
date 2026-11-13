<?php

declare(strict_types=1);

namespace App\Nfse;

use App\Support\Errors;
use App\Tributos\Dec;

/**
 * Tributos federais da NFS-e (grupo tribFed) calculados pela API a partir dos percentuais enviados pelo ERP no JSON.
 *
 * Entrada (`federais` no POST /nfse): {cst_pis_cofins, aliquota_pis?, aliquota_cofins?, aliquota_irrf?, aliquota_csll?,
 * tipo_retencao?}. A API calcula no envio, sobre valor do serviço − desconto incondicionado, e gera `tributos_federais`
 * (o mesmo grupo que o ERP pode enviar já calculado). Valores com 2 casas (ABNT).
 * vRetCSLL leva a soma de PIS, COFINS e CSLL retidos pelo tomador, conforme o tpRetPisCofins (NT 007).
 * Enviar `federais` e `tributos_federais` juntos é recusado: ou a API calcula, ou o ERP manda os valores.
 */
final class TributosFederaisCalculo
{
    private const CAMPOS = ['cst_pis_cofins', 'aliquota_pis', 'aliquota_cofins', 'aliquota_irrf', 'aliquota_csll', 'tipo_retencao'];
    /** CST de PIS/COFINS aceitos na NFS-e (TSTipoCST). */
    public const CST_PIS_COFINS = ['00','01','02','03','04','05','06','07','08','09','49','50','51','52','53','54','55','56','60','61','62',
        '63','64','65','66','67','70','71','72','73','74','75','98','99'];
    /** tpRetPisCofins da NFS-e Nacional (TSTipoRetPISCofins). */
    public const RETENCAO_PIS_COFINS = ['0' => 'PIS/COFINS/CSLL não retidos', '1' => 'PIS/COFINS retidos', '2' => 'PIS/COFINS não retidos',
        '3' => 'PIS/COFINS/CSLL retidos', '4' => 'PIS/COFINS retidos, CSLL não retido', '5' => 'PIS retido, COFINS/CSLL não retidos',
        '6' => 'COFINS retido, PIS/CSLL não retidos', '7' => 'PIS não retido, COFINS/CSLL retidos', '8' => 'PIS/COFINS não retidos, CSLL retido',
        '9' => 'COFINS não retido, PIS/CSLL retidos'];
    /** Tributos retidos em cada tpRetPisCofins. */
    private const RETIDOS = [0 => [], 1 => ['pis', 'cofins'], 2 => [], 3 => ['pis', 'cofins', 'csll'], 4 => ['pis', 'cofins'], 5 => ['pis'],
        6 => ['cofins'], 7 => ['cofins', 'csll'], 8 => ['csll'], 9 => ['pis', 'csll']];

    /**
     * Troca `federais` (percentuais) por `tributos_federais` (valores calculados) no payload da NFS-e.
     * @param array<mixed> $in
     * @return array<mixed>
     */
    public static function aplicar(array $in, Errors $e): array
    {
        if (!array_key_exists('federais', $in)) {
            return $in;
        }
        $f = self::validar($in['federais'], $e);
        unset($in['federais']);
        if (array_key_exists('tributos_federais', $in)) {
            $e->add('federais', 'Envie federais (percentuais, a API calcula) ou tributos_federais (valores já calculados pelo ERP), não os dois');
            return $in;
        }
        $v = is_array($in['valores'] ?? null) ? $in['valores'] : [];
        $calc = $f === null ? null : self::calcular($f, $v);
        if ($calc !== null) {
            $in['tributos_federais'] = $calc;
        }
        return $in;
    }

    /** @return array<string,mixed>|null */
    public static function validar(mixed $f, Errors $e): ?array
    {
        if (!is_array($f) || array_is_list($f)) {
            $e->add('federais', 'Objeto com cst_pis_cofins, aliquota_pis, aliquota_cofins, aliquota_irrf, aliquota_csll e tipo_retencao');
            return null;
        }
        $antes = count($e->all());
        foreach (array_keys($f) as $k) {
            in_array($k, self::CAMPOS, true) ?: $e->add("federais.$k", 'Campo não permitido');
        }
        $out = [];
        $cst = $f['cst_pis_cofins'] ?? null;
        is_string($cst) && in_array($cst, self::CST_PIS_COFINS, true) ? $out['cst_pis_cofins'] = $cst
            : $e->add('federais.cst_pis_cofins', 'CST de PIS/COFINS com 2 dígitos, como texto (obrigatório)');
        foreach (['aliquota_pis', 'aliquota_cofins', 'aliquota_irrf', 'aliquota_csll'] as $k) {
            if (isset($f[$k])) {
                $d = Dec::de($f[$k]);
                $d !== null && Dec::cmp($d, '0') >= 0 && Dec::cmp($d, '100') <= 0 && preg_match('/^\d+(\.\d{1,2})?$/D', $d)
                    ? $out[$k] = $d : $e->add("federais.$k", 'Percentual de 0 a 100 com até 2 casas');
            }
        }
        $t = $f['tipo_retencao'] ?? 0;
        is_int($t) && isset(self::RETENCAO_PIS_COFINS[(string) $t]) ? $out['tipo_retencao'] = $t
            : $e->add('federais.tipo_retencao', 'Inteiro de 0 a 9 (tpRetPisCofins da NFS-e)');
        return count($e->all()) > $antes ? null : $out;
    }

    /**
     * Grupo tribFed calculado: base = serviço − desconto incondicionado; valores com 2 casas (ABNT).
     * @param array<string,mixed> $f percentuais validados
     * @param array<mixed> $v valores da NFS-e
     * @return array<string,mixed>|null
     */
    public static function calcular(array $f, array $v): ?array
    {
        $vs = Dec::de($v['servico'] ?? null);
        $di = Dec::de($v['desconto_incondicionado'] ?? 0) ?? '0';
        if ($vs === null) {
            return null; // valores inválidos: a validação da NFS-e aponta o erro
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
}
