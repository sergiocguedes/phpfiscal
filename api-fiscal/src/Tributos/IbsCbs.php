<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Support\Errors;

/**
 * IBS (UF e município) e CBS por item da NF-e/NFC-e — NT 2025.002-RTC.
 *
 * Base (regra UB16-10): vProd + vFrete + vSeg + vOutro + vII − vDesc − vPIS − vCOFINS − vICMS − vICMSUFDest − vFCP
 *   − vFCPUFDest − vICMSMono − vISSQN (DIFAL e ISSQN não existem nesta versão; II não se aplica na saída).
 * Alíquotas (IBS UF, IBS município, CBS): informadas pelo ERP na operação (operacao.aliquotas_ibscbs).
 * cClassTrib, CST e redução do cClassTrib (pRedIBS, pRedCBS, CST 200): informados pelo ERP na regra do item.
 * A API não mantém tabela de cClassTrib nem de alíquotas: confere só o formato e a coerência CST × cClassTrib.
 * Alíquota efetiva com redução: p × (1 − pRed/100), com 4 casas. Valor: base × alíquota, com 2 casas.
 * Diferimento (CST 510): vDif = base × alíquota × pDif; o valor do tributo é o calculado menos o diferido.
 * vItem (regra VB01-10): vProd − vDesc − vICMSDeson (se deduz) + vICMSST + vFCPST + vFrete + vSeg + vOutro + vIPI;
 *   em 2025 e 2026 sem IBS/CBS; a partir de 2027 soma vIBS e vCBS.
 *
 * CST suportados no cálculo: 000 (integral), 200 (alíquota reduzida), 400 (isenção), 410 (imunidade/não incidência)
 * e 510 (diferimento). Os demais (alíquota fixa, suspensão, monofasia, transferência de crédito, ajustes) seguem
 * no modo informado (reforma_tributaria.modo = informado).
 */
final class IbsCbs
{
    public const CST_SUPORTADOS = ['000', '200', '400', '410', '510'];
    /** CST com o grupo de valores gIBSCBS. */
    private const COM_VALORES = ['000', '200', '510'];

    /**
     * Valida o grupo ibscbs da regra do item. Devolve o grupo normalizado.
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    public static function validar(array $g, string $f, Errors $e): array
    {
        $cc = (string) ($g['cClassTrib'] ?? '');
        // o CST são os 3 primeiros dígitos do cClassTrib; informar é opcional
        if (!isset($g['CST']) && preg_match('/^\d{6}$/D', $cc)) {
            $g['CST'] = substr($cc, 0, 3);
        }
        $cst = (string) ($g['CST'] ?? '');
        if (!preg_match('/^\d{6}$/D', $cc)) {
            $e->add("$f.cClassTrib", 'Informe o cClassTrib do produto (6 dígitos)');
            return $g;
        }
        if (!preg_match('/^\d{3}$/D', $cst)) {
            $e->add("$f.CST", 'CST do IBS/CBS com 3 dígitos');
            return $g;
        }
        if (!in_array($cst, self::CST_SUPORTADOS, true)) {
            $e->add("$f.CST", "CST $cst não é calculado pela API (suportados: " . implode(', ', self::CST_SUPORTADOS)
                . '). Para este caso envie a nota com reforma_tributaria.modo = informado');
        }
        if (!preg_match('/^\d{6}$/D', $cc)) {
            $e->add("$f.cClassTrib", 'cClassTrib com 6 dígitos (tabela do Informe Técnico 2025.002)');
        } elseif (substr($cc, 0, 3) !== $cst) {
            $e->add("$f.cClassTrib", "Os 3 primeiros dígitos do cClassTrib ($cc) devem ser o CST ($cst)");
        }
        // redução da alíquota (CST 200): percentuais do cClassTrib, enviados pelo ERP
        if ($cst === '200') {
            if (!isset($g['pRedIBS']) && !isset($g['pRedCBS'])) {
                $e->add("$f.pRedIBS", 'CST 200: informe pRedIBS e pRedCBS (percentual de redução do cClassTrib)');
            }
        } else {
            foreach (['pRedIBS', 'pRedCBS'] as $k) {
                if (isset($g[$k])) {
                    $e->add("$f.$k", 'Redução da alíquota só no CST 200');
                }
            }
        }
        if ($cst === '510') {
            if (!isset($g['pDif']) || (float) $g['pDif'] <= 0) {
                $e->add("$f.pDif", 'CST 510: percentual de diferimento obrigatório');
            }
        } elseif (isset($g['pDif'])) {
            $e->add("$f.pDif", 'Diferimento só no CST 510');
        }
        return $g;
    }

    /**
     * @param array<string,mixed> $imp impostos já calculados do item (icms, ipi, pis, cofins)
     * @param array{pIBSUF:string, pIBSMun:string, pCBS:string} $aliq alíquotas informadas pelo sistema
     * @param array<string,mixed> $classe cClassTrib e redução (pRedIBS, pRedCBS) informados na regra do item
     * @return array{grupo: array<string,mixed>, vBC: string, vIBSUF: string, vIBSMun: string, vCBS: string,
     *   vDifUF: string, vDifMun: string, vDifCBS: string, comValores: bool}
     */
    public static function calcular(ItemContexto $c, array $imp, array $aliq, array $classe): array
    {
        $cst = (string) $c->p('ibscbs', 'CST');
        $grupo = ['CST' => $cst, 'cClassTrib' => (string) $c->p('ibscbs', 'cClassTrib')];
        $zero = ['vBC' => '0', 'vIBSUF' => '0', 'vIBSMun' => '0', 'vCBS' => '0', 'vDifUF' => '0', 'vDifMun' => '0', 'vDifCBS' => '0'];
        if (!in_array($cst, self::COM_VALORES, true)) {
            $c->mem("IBS/CBS: CST $cst sem grupo de valores");
            return ['grupo' => $grupo, 'comValores' => false] + $zero;
        }

        $vBC = self::base($c, $imp);

        $pDif = $c->p('ibscbs', 'pDif');
        $out = ['vBC' => Dec::fixo($vBC, 2)];
        $vals = [];
        foreach (['gIBSUF' => ['IBSUF', 'pIBSUF', 'vIBSUF', 'pRedIBS'], 'gIBSMun' => ['IBSMun', 'pIBSMun', 'vIBSMun', 'pRedIBS'],
            'gCBS' => ['CBS', 'pCBS', 'vCBS', 'pRedCBS']] as $tag => [$k, $pTag, $vTag, $redK]) {
            $p = $aliq[$pTag];
            // redução só no CST 200, pelo percentual do cClassTrib informado pelo ERP
            $pRed = $cst === '200' ? Dec::de($classe[$redK] ?? 0) : null;
            $g = [$pTag => Dec::fixo($p, 4)];
            $pEf = $p;
            $temRed = $pRed !== null && Dec::cmp($pRed, '0') > 0;
            if ($temRed) {
                $pEf = Dec::meio(Dec::mul($p, Dec::sub('1', Dec::div($pRed, '100'))), 4);
            }
            $bruto = Dec::abnt(Dec::pct($vBC, $pEf));
            $vDif = '0';
            if ($pDif !== null) {
                $vDif = Dec::abnt(Dec::pct(Dec::pct($vBC, $pEf), $pDif));
                $g['gDif'] = ['pDif' => Dec::fixo($pDif, 4), 'vDif' => Dec::fixo($vDif, 2)];
            }
            if ($temRed) {
                $g['gRed'] = ['pRedAliq' => Dec::fixo($pRed, 4), 'pAliqEfet' => Dec::fixo($pEf, 4)];
            }
            $valor = Dec::sub($bruto, $vDif);
            $g[$vTag] = Dec::fixo($valor, 2);
            $vals[$k] = [$valor, $vDif];
            $out[$tag] = $g;
            $c->mem("IBS/CBS: $k alíquota $p%" . ($temRed ? " reduzida em $pRed% = $pEf%" : '') . " → $bruto"
                . ($pDif !== null ? " − diferido $vDif ($pDif%)" : '') . " = $valor" . ($temRed ? " (cClassTrib {$classe['cClassTrib']})" : ''));
            if ($tag === 'gIBSMun') {
                $out['vIBS'] = Dec::fixo(Dec::soma($vals['IBSUF'][0], $valor), 2);
            }
        }
        // ordem do XSD: vBC, gIBSUF, gIBSMun, vIBS, gCBS
        $grupo['gIBSCBS'] = ['vBC' => $out['vBC'], 'gIBSUF' => $out['gIBSUF'], 'gIBSMun' => $out['gIBSMun'], 'vIBS' => $out['vIBS'], 'gCBS' => $out['gCBS']];
        return ['grupo' => $grupo, 'comValores' => true, 'vBC' => $vBC,
            'vIBSUF' => $vals['IBSUF'][0], 'vIBSMun' => $vals['IBSMun'][0], 'vCBS' => $vals['CBS'][0],
            'vDifUF' => $vals['IBSUF'][1], 'vDifMun' => $vals['IBSMun'][1], 'vDifCBS' => $vals['CBS'][1]];
    }

    /** Base do IBS/CBS (regra UB16-10), também usada como base do crédito presumido da operação. */
    public static function base(ItemContexto $c, array $imp): string
    {
        $v = fn(string $g, string $k): string => isset($imp[$g][$k]) ? (Dec::de($imp[$g][$k]) ?? '0') : '0';
        $base = Dec::soma($c->vProd, $c->vFrete, $c->vSeg, $c->vOutro);
        $deduz = Dec::soma($c->vDesc, $v('pis', 'vPIS'), $v('cofins', 'vCOFINS'), $v('icms', 'vICMS'), $v('icms', 'vFCP'), $v('icms', 'vICMSMono'));
        $vBC = Dec::abnt(Dec::max0(Dec::sub($base, $deduz)));
        $c->mem("IBS/CBS: base = vProd+frete+seguro+outras ($base) − desconto, PIS, COFINS, ICMS, FCP e ICMS monofásico ($deduz) = $vBC");
        return $vBC;
    }

    /**
     * Crédito presumido da operação (grupo gCredPresOper, NT 2025.002-RTC): o cCredPres e os percentuais vêm do ERP
     * (natureza da operação); a base é a mesma do IBS/CBS. Valor = base × pCredPres, com 2 casas. Condição suspensiva
     * (vCredPresCondSus) só a partir de 2033 para o cCredPres 04: não calculada.
     * @param array{cCredPres:string, pCredPresIBS:?string, pCredPresCBS:?string} $cp
     * @return array{grupo: array<string,mixed>, vIBS: string, vCBS: string}
     */
    public static function creditoPresumido(ItemContexto $c, string $vBC, array $cp): array
    {
        $g = ['vBCCredPres' => Dec::fixo($vBC, 2), 'cCredPres' => $cp['cCredPres']];
        $out = ['vIBS' => '0', 'vCBS' => '0'];
        foreach (['IBS' => 'pCredPresIBS', 'CBS' => 'pCredPresCBS'] as $t => $k) {
            if ($cp[$k] !== null) {
                $val = Dec::abnt(Dec::pct($vBC, $cp[$k]));
                $g["g{$t}CredPres"] = ['pCredPres' => Dec::fixo($cp[$k], 4), 'vCredPres' => Dec::fixo($val, 2)];
                $out["v$t"] = $val;
                $c->mem("Crédito presumido $t (cCredPres {$cp['cCredPres']}): $vBC × {$cp[$k]}% = $val");
            }
        }
        return ['grupo' => $g] + $out;
    }

    /**
     * Grupo com números (resposta do cálculo) ↔ texto no formato do XSD (emissão: percentuais com 4 casas, valores com 2).
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    public static function numeros(array $g): array
    {
        foreach ($g as $k => $v) {
            $g[$k] = is_array($v) ? self::numeros($v) : (in_array($k, ['CST', 'cClassTrib', 'cCredPres'], true) ? $v : Dec::num((string) $v));
        }
        return $g;
    }

    /** @param array<string,mixed> $g @return array<string,mixed> */
    public static function paraXml(array $g): array
    {
        foreach ($g as $k => $v) {
            if (is_array($v)) {
                $g[$k] = self::paraXml($v);
            } elseif (!in_array($k, ['CST', 'cClassTrib', 'cCredPres'], true)) {
                $g[$k] = Dec::fixo(Dec::de($v) ?? '0', str_starts_with((string) $k, 'p') ? 4 : 2);
            }
        }
        return $g;
    }

    /**
     * Valor total do item (vItem, regra VB01-10).
     * @param array<string,mixed> $imp
     */
    public static function valorItem(ItemContexto $c, array $imp, int $ano, string $vIBS, string $vCBS): string
    {
        $v = fn(string $g, string $k): string => isset($imp[$g][$k]) ? (Dec::de($imp[$g][$k]) ?? '0') : '0';
        $soma = Dec::soma($c->vProd, $v('icms', 'vICMSST'), $v('icms', 'vFCPST'), $c->vFrete, $c->vSeg, $c->vOutro, $v('ipi', 'vIPI'));
        $menos = $c->vDesc;
        if ((int) ($imp['icms']['indDeduzDeson'] ?? 0) === 1) {
            $menos = Dec::soma($menos, $v('icms', 'vICMSDeson'));
        }
        $total = Dec::sub($soma, $menos);
        if ($ano >= 2027) {
            $total = Dec::soma($total, $vIBS, $vCBS);
        }
        return Dec::fixo($total, 2);
    }
}
