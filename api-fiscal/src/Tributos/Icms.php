<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Support\Errors;

/**
 * ICMS por CST (regime normal) e CSOSN (Simples Nacional).
 * Devolve o grupo com as tags do leiaute, pronto para "impostos.icms" do POST /nfe e /pdv/nfce.
 *
 * Diferenças intencionais em relação ao TCalcula_Imposto do ClickPDV (ver especificação no Project):
 * alíquota ST obrigatória, MVA sempre aplicada, frete/seguro/outras na base, ST nunca negativo,
 * redução da base ST sobre a base, desoneração só com motivo, alíquota interestadual coerente com o cálculo.
 */
final class Icms
{
    public const CST_SUPORTADOS = ['00', '02', '10', '20', '30', '40', '41', '50', '51', '60', '61', '70', '90'];
    public const CSOSN_SUPORTADOS = ['101', '102', '103', '201', '202', '203', '300', '400', '500', '900'];
    private const RETIDO = ['vBCSTRet', 'pST', 'vICMSSubstituto', 'vICMSSTRet', 'vBCFCPSTRet', 'pFCPSTRet', 'vFCPSTRet'];

    /** @param array<string,mixed> $op operação normalizada */
    public function __construct(private readonly array $op, private readonly Errors $e)
    {
    }

    /** @return array<string,mixed>|null */
    public function calcular(ItemContexto $c): ?array
    {
        $simples = in_array($this->op['crt'], [1, 4], true);
        $cst = $c->p('icms', 'CST');
        $csosn = $c->p('icms', 'CSOSN');
        $f = "$c->campo.regra.icms";
        if ($simples && $csosn === null) {
            $this->e->add("$f.CSOSN", 'Emitente do Simples Nacional (CRT ' . $this->op['crt'] . ') usa CSOSN');
            return null;
        }
        if (!$simples && $cst === null) {
            $this->e->add("$f.CST", 'Emitente do regime normal (CRT ' . $this->op['crt'] . ') usa CST');
            return null;
        }
        $g = ['orig' => (int) $c->p('icms', 'orig')];
        if ($simples) {
            if (!in_array($csosn, self::CSOSN_SUPORTADOS, true)) {
                $this->e->add("$f.CSOSN", 'CSOSN não suportado: ' . implode(', ', self::CSOSN_SUPORTADOS));
                return null;
            }
            $g['CSOSN'] = $csosn;
            return $this->csosn($c, $csosn, $g);
        }
        if (!in_array($cst, self::CST_SUPORTADOS, true)) {
            $this->e->add("$f.CST", 'CST não suportado nesta versão: ' . implode(', ', self::CST_SUPORTADOS));
            return null;
        }
        $g['CST'] = $cst;
        return $this->cst($c, $cst, $g);
    }

    /** @param array<string,mixed> $g @return array<string,mixed>|null */
    private function cst(ItemContexto $c, string $cst, array $g): ?array
    {
        $f = "$c->campo.regra.icms";
        switch ($cst) {
            case '00':
                if ($c->tem('icms', 'pRedBC')) {
                    $this->e->add("$f.pRedBC", 'CST 00 não tem redução de base: use CST 20');
                    return null;
                }
                return $this->propria($c, $g, false);
            case '20':
                $g = $this->propria($c, $g, true);
                return $g === null ? null : $this->desoneracaoReducao($c, $g);
            case '10':
                $g = $this->propria($c, $g, false);
                return $g === null ? null : $this->st($c, $g, $c->vICMS);
            case '70':
                $g = $this->propria($c, $g, true);
                $g = $g === null ? null : $this->st($c, $g, $c->vICMS);
                return $g === null ? null : $this->desoneracaoReducao($c, $g);
            case '30':
                $p = $this->aliquota($c, false);
                $deduz = $p === null ? '0' : Dec::abnt(Dec::pct($c->baseOperacao(), $p));
                $g = $this->st($c, $g, $deduz);
                return $g === null ? null : $this->desoneracao($c, $g, $p);
            case '40':
            case '41':
            case '50':
                $this->proibirValores($c, ['vBC', 'vICMS', 'vBCST', 'vICMSST'], "CST $cst");
                return $this->desoneracao($c, $g, $this->aliquota($c, false));
            case '51':
                return $this->diferimento($c, $g);
            case '60':
                $this->proibirValores($c, ['vBC', 'vICMS', 'vBCST', 'vICMSST'], 'CST 60');
                return $this->retido($c, $g);
            case '02':
                return $this->monofasico($c, $g, false);
            case '61':
                return $this->monofasico($c, $g, true);
            case '90':
                if ($this->aliquota($c, false) !== null || $c->tem('icms', 'vBC') || $c->tem('icms', 'vICMS')) {
                    $g = $this->propria($c, $g, $c->tem('icms', 'pRedBC'));
                    if ($g === null) {
                        return null;
                    }
                }
                if ($c->tem('icms', 'pICMSST') || $c->tem('icms', 'vBCST')) {
                    $g = $this->st($c, $g, $c->vICMS);
                }
                return $g === null ? null : $this->desoneracao($c, $g, $this->aliquota($c, false));
        }
        return null;
    }

    /** @param array<string,mixed> $g @return array<string,mixed>|null */
    private function csosn(ItemContexto $c, string $csosn, array $g): ?array
    {
        switch ($csosn) {
            case '101':
                return $this->creditoSn($c, $g, true);
            case '102':
            case '103':
            case '300':
            case '400':
                $this->proibirValores($c, ['vBC', 'vICMS', 'vBCST', 'vICMSST', 'vCredICMSSN'], "CSOSN $csosn");
                return $g;
            case '201':
            case '202':
            case '203':
                $p = $this->aliquota($c, true);
                if ($p === null) {
                    return null;
                }
                // ICMS que seria devido na operação própria, deduzido do ST (não é destacado no Simples)
                $deduz = Dec::abnt(Dec::pct($c->baseOperacao(), $p));
                $c->mem("ICMS próprio presumido para dedução do ST = base " . $c->baseOperacao() . " × $p% = $deduz");
                $g = $this->st($c, $g, $deduz);
                return $g !== null && $csosn === '201' ? $this->creditoSn($c, $g, true) : $g;
            case '500':
                return $this->retido($c, $g);
            case '900':
                if ($this->aliquota($c, false) !== null || $c->tem('icms', 'vBC')) {
                    $g = $this->propria($c, $g, $c->tem('icms', 'pRedBC'));
                    if ($g === null) {
                        return null;
                    }
                }
                if ($c->tem('icms', 'pICMSST') || $c->tem('icms', 'vBCST')) {
                    $g = $this->st($c, $g, $c->vICMS);
                    if ($g === null) {
                        return null;
                    }
                }
                return $c->tem('icms', 'pCredSN') ? $this->creditoSn($c, $g, false) : $g;
        }
        return null;
    }

    /**
     * Alíquota da operação própria: informada; ou, sem informação e operação interestadual (NF-e),
     * a alíquota interestadual pela origem da mercadoria.
     */
    private function aliquota(ItemContexto $c, bool $obrigatoria): ?string
    {
        $origem = (string) $c->p('icms', 'orig');
        $inter = $this->interestadual();
        $esperada = $inter ? AliquotaInterestadual::para($this->op['uf'], $this->op['uf_destino'], $origem) : null;
        $p = $c->p('icms', 'pICMS');
        if ($p === null && $esperada !== null) {
            $c->mem("pICMS interestadual {$this->op['uf']}→{$this->op['uf_destino']} origem $origem = $esperada%");
            return $esperada;
        }
        if ($p !== null && $esperada !== null && Dec::cmp($p, $esperada) !== 0) {
            $c->alerta("pICMS $p% difere da alíquota interestadual esperada ($esperada%) para origem $origem");
        }
        if ($p === null && $obrigatoria) {
            $this->e->add("$c->campo.regra.icms.pICMS", 'Alíquota do ICMS obrigatória para este CST/CSOSN');
        }
        return $p;
    }

    private function interestadual(): bool
    {
        return $this->op['modelo'] === 55 && $this->op['uf_destino'] !== $this->op['uf'];
    }

    /** ICMS próprio, com ou sem redução de base. @param array<string,mixed> $g @return array<string,mixed>|null */
    private function propria(ItemContexto $c, array $g, bool $reducao): ?array
    {
        $p = $this->aliquota($c, !$c->tem('icms', 'vICMS'));
        if ($p === null && !$c->tem('icms', 'vICMS')) {
            return null;
        }
        $p ??= '0';
        $base = $this->baseIcms($c);
        $pRed = $reducao ? ($c->p('icms', 'pRedBC') ?? null) : null;
        if ($reducao && $pRed === null) {
            $this->e->add("$c->campo.regra.icms.pRedBC", 'Percentual de redução obrigatório para este CST');
            return null;
        }
        $bcCalc = $pRed === null ? Dec::abnt($base) : Dec::abnt(Dec::mul($base, Dec::sub('1', Dec::div($pRed, '100'))));
        $c->mem('vBC = ' . $base . ($pRed !== null ? " × (1 − $pRed%)" : '') . " = $bcCalc");
        $vBC = $c->valor('icms', 'vBC', $bcCalc);
        $vICMS = $c->valor('icms', 'vICMS', Dec::abnt(Dec::pct($vBC, $p)));
        $c->mem("vICMS = $vBC × $p% = $vICMS");
        $c->vICMS = $vICMS;
        $g += ['modBC' => (int) ($c->p('icms', 'modBC') ?? 3)];
        if ($pRed !== null) {
            $g['pRedBC'] = Dec::num($pRed);
        }
        $g += ['vBC' => Dec::num($vBC), 'pICMS' => Dec::num($p), 'vICMS' => Dec::num($vICMS)];
        return $this->fcp($c, $g, $vBC);
    }

    /** Base do ICMS próprio: valor da operação (+ IPI quando destinado a consumidor final). */
    private function baseIcms(ItemContexto $c): string
    {
        $base = $c->baseOperacao();
        if ($this->op['consumidor_final'] && !Dec::zero($c->vIPI)) {
            $c->mem("IPI ($c->vIPI) integra a base do ICMS (consumidor final)");
            $base = Dec::soma($base, $c->vIPI);
        }
        return $base;
    }

    /** @param array<string,mixed> $g @return array<string,mixed> */
    private function fcp(ItemContexto $c, array $g, string $vBC): array
    {
        $p = $c->p('icms', 'pFCP');
        if ($p === null || Dec::zero($p)) {
            return $g;
        }
        $v = $c->valor('icms', 'vFCP', Dec::abnt(Dec::pct($vBC, $p)));
        return $g + ['vBCFCP' => Dec::num($vBC), 'pFCP' => Dec::num($p), 'vFCP' => Dec::num($v)];
    }

    /**
     * Substituição tributária. $deduzir = ICMS da operação própria (destacado ou presumido).
     * @param array<string,mixed> $g @return array<string,mixed>|null
     */
    private function st(ItemContexto $c, array $g, string $deduzir): ?array
    {
        $f = "$c->campo.regra.icms";
        $pST = $c->p('icms', 'pICMSST');
        if ($pST === null) {
            $this->e->add("$f.pICMSST", 'Alíquota do ICMS ST obrigatória');
            return null;
        }
        $mva = $c->p('icms', 'pMVAST');
        $mod = (int) ($c->p('icms', 'modBCST') ?? ($mva !== null ? 4 : 6));
        $pRed = $c->p('icms', 'pRedBCST');
        // a base do ST parte do valor da operação sem a redução própria, com IPI
        $base0 = Dec::soma($c->baseOperacao(), $c->vIPI);
        if ($mod === 4) {
            if ($mva === null) {
                $this->e->add("$f.pMVAST", 'MVA obrigatória com modBCST 4');
                return null;
            }
            $bst = Dec::mul($base0, Dec::soma('1', Dec::div($mva, '100')));
            $c->mem("base ST = $base0 × (1 + $mva%) = " . Dec::abnt($bst));
        } elseif ($mod === 6) {
            $bst = $base0;
        } elseif (in_array($mod, [0, 5], true) && $c->pautaST !== null) {
            $bst = Dec::mul($c->quantidade, $c->pautaST);
            $c->mem("base ST = quantidade $c->quantidade × pauta R$ $c->pautaST = " . Dec::abnt($bst));
        } elseif (!$c->tem('icms', 'vBCST')) {
            $this->e->add("$c->campo." . (in_array($mod, [0, 5], true) ? 'valor_pauta_st' : 'regra.icms.vBCST'),
                in_array($mod, [0, 5], true) ? "modBCST $mod (preço tabelado/pauta): informe o valor de pauta por unidade do produto"
                    : "modBCST $mod (lista) não é calculado: informe vBCST");
            return null;
        } else {
            $bst = '0';
        }
        if ($pRed !== null && !Dec::zero($pRed)) {
            $bst = Dec::mul($bst, Dec::sub('1', Dec::div($pRed, '100')));
            $c->mem("base ST reduzida em $pRed% = " . Dec::abnt($bst));
        }
        $vBCST = $c->valor('icms', 'vBCST', Dec::abnt($bst));
        $bruto = Dec::abnt(Dec::pct($vBCST, $pST));
        $calc = Dec::sub($bruto, $deduzir);
        if (Dec::cmp($calc, '0') < 0) {
            $c->alerta("ICMS ST calculado negativo ($calc) zerado: ICMS próprio maior que o ST");
            $calc = '0';
        }
        $vST = $c->valor('icms', 'vICMSST', $calc);
        $c->mem("vICMSST = $vBCST × $pST% − $deduzir = $vST");
        $g += ['modBCST' => $mod];
        if ($mva !== null) {
            $g['pMVAST'] = Dec::num($mva);
        }
        if ($pRed !== null) {
            $g['pRedBCST'] = Dec::num($pRed);
        }
        $g += ['vBCST' => Dec::num($vBCST), 'pICMSST' => Dec::num($pST), 'vICMSST' => Dec::num($vST)];
        $pF = $c->p('icms', 'pFCPST');
        if ($pF !== null && !Dec::zero($pF)) {
            $vF = $c->valor('icms', 'vFCPST', Dec::abnt(Dec::pct($vBCST, $pF)));
            $g += ['vBCFCPST' => Dec::num($vBCST), 'pFCPST' => Dec::num($pF), 'vFCPST' => Dec::num($vF)];
        }
        return $g;
    }

    /** CST 20/70: desonerado = ICMS sem redução − ICMS com redução. @param array<string,mixed> $g @return array<string,mixed> */
    private function desoneracaoReducao(ItemContexto $c, array $g): array
    {
        $mot = $c->p('icms', 'motDesICMS');
        if ($mot === null) {
            $this->semDesoneracaoManual($c);
            return $g;
        }
        $semReducao = Dec::abnt(Dec::pct(Dec::abnt($this->baseIcms($c)), (string) $g['pICMS']));
        $calc = Dec::max0(Dec::sub($semReducao, $c->vICMS));
        $v = $c->valor('icms', 'vICMSDeson', $calc);
        $c->mem("vICMSDeson = $semReducao (sem redução) − $c->vICMS = $v");
        return $g + ['vICMSDeson' => Dec::num($v), 'motDesICMS' => (int) $mot, 'indDeduzDeson' => (int) ($c->p('icms', 'indDeduzDeson') ?? 0)];
    }

    /** CST 30/40/41/50/90: desonerado = base × alíquota (por fora) ou por dentro. @param array<string,mixed> $g @return array<string,mixed>|null */
    private function desoneracao(ItemContexto $c, array $g, ?string $p): ?array
    {
        $mot = $c->p('icms', 'motDesICMS');
        if ($mot === null) {
            $this->semDesoneracaoManual($c);
            return $g;
        }
        if ($p === null && !$c->tem('icms', 'vICMSDeson')) {
            $this->e->add("$c->campo.regra.icms.pICMS", 'Informe a alíquota do ICMS para calcular o valor desonerado (ou informe vICMSDeson)');
            return null;
        }
        $base = Dec::abnt($this->baseIcms($c));
        $p ??= '0';
        // método definido na regra de ICMS conforme a base legal do benefício (padrão: por fora)
        if ($c->p('icms', 'metodo_desoneracao') === 'por_dentro' && Dec::cmp($p, '100') < 0) {
            $fator = Dec::sub('1', Dec::div($p, '100'));
            $calc = Dec::abnt(Dec::pct(Dec::div($base, $fator), $p));
            $c->mem("vICMSDeson (por dentro) = $base / (1 − $p%) × $p% = $calc");
        } else {
            $calc = Dec::abnt(Dec::pct($base, $p));
            $c->mem("vICMSDeson (por fora) = $base × $p% = $calc");
        }
        $v = $c->valor('icms', 'vICMSDeson', $calc);
        return $g + ['vICMSDeson' => Dec::num($v), 'motDesICMS' => (int) $mot, 'indDeduzDeson' => (int) ($c->p('icms', 'indDeduzDeson') ?? 0)];
    }

    private function semDesoneracaoManual(ItemContexto $c): void
    {
        if ($c->tem('icms', 'vICMSDeson')) {
            $this->e->add("$c->campo.regra.icms.motDesICMS", 'vICMSDeson exige o motivo da desoneração (motDesICMS)');
        }
    }

    /** CST 51. @param array<string,mixed> $g @return array<string,mixed>|null */
    private function diferimento(ItemContexto $c, array $g): ?array
    {
        $p = $this->aliquota($c, true);
        if ($p === null) {
            return null;
        }
        $pRed = $c->p('icms', 'pRedBC');
        $base = $this->baseIcms($c);
        $bcCalc = $pRed === null ? Dec::abnt($base) : Dec::abnt(Dec::mul($base, Dec::sub('1', Dec::div($pRed, '100'))));
        $vBC = $c->valor('icms', 'vBC', $bcCalc);
        $vOp = $c->valor('icms', 'vICMSOp', Dec::abnt(Dec::pct($vBC, $p)));
        $pDif = $c->p('icms', 'pDif') ?? '0';
        $vDif = $c->valor('icms', 'vICMSDif', Dec::abnt(Dec::pct($vOp, $pDif)));
        $vICMS = $c->valor('icms', 'vICMS', Dec::max0(Dec::sub($vOp, $vDif)));
        $c->vICMS = $vICMS;
        $c->mem("vICMSOp = $vBC × $p% = $vOp; vICMSDif = $vOp × $pDif% = $vDif; vICMS = $vICMS");
        $g += ['modBC' => (int) ($c->p('icms', 'modBC') ?? 3)];
        if ($pRed !== null) {
            $g['pRedBC'] = Dec::num($pRed);
        }
        $g += ['vBC' => Dec::num($vBC), 'pICMS' => Dec::num($p), 'vICMSOp' => Dec::num($vOp), 'pDif' => Dec::num($pDif),
            'vICMSDif' => Dec::num($vDif), 'vICMS' => Dec::num($vICMS)];
        return $this->fcp($c, $g, $vBC);
    }

    /** CST 60 / CSOSN 500: repassa os valores retidos informados. @param array<string,mixed> $g @return array<string,mixed> */
    private function retido(ItemContexto $c, array $g): array
    {
        foreach (self::RETIDO as $k) {
            $v = $c->p('icms', $k);
            if ($v !== null) {
                $g[$k] = Dec::num($v);
            }
        }
        return $g;
    }

    /** CST 02 (monofásico próprio) e 61 (cobrado anteriormente). @param array<string,mixed> $g @return array<string,mixed>|null */
    private function monofasico(ItemContexto $c, array $g, bool $retido): ?array
    {
        $sufixo = $retido ? 'Ret' : '';
        $ad = $c->p('icms', "adRemICMS$sufixo") ?? ($retido ? $c->p('icms', 'adRemICMS') : null);
        if ($ad === null) {
            $this->e->add("$c->campo.regra.icms.adRemICMS$sufixo", 'Alíquota ad rem obrigatória para o ICMS monofásico');
            return null;
        }
        $qCalc = Dec::mul($c->qtdTributavel ?? '1', $c->quantidade);
        if ($c->qtdTributavel === null && !$c->tem('icms', "qBCMono$sufixo")) {
            $c->alerta('qtd_tributavel_unidade não informada: quantidade tributada = quantidade comercial');
        }
        $q = $c->valor('icms', "qBCMono$sufixo", $qCalc, 4);
        $v = $c->valor('icms', "vICMSMono$sufixo", Dec::abnt(Dec::mul($q, $ad)));
        $c->mem("vICMSMono$sufixo = $q × $ad = $v");
        return $g + ["qBCMono$sufixo" => Dec::num($q), "adRemICMS$sufixo" => Dec::num($ad), "vICMSMono$sufixo" => Dec::num($v)];
    }

    /** Crédito do Simples (CSOSN 101, 201, 900). @param array<string,mixed> $g @return array<string,mixed>|null */
    private function creditoSn(ItemContexto $c, array $g, bool $obrigatorio): ?array
    {
        $p = $c->p('icms', 'pCredSN');
        if ($p === null) {
            if ($obrigatorio) {
                $this->e->add("$c->campo.regra.icms.pCredSN", 'Alíquota de crédito do Simples obrigatória para este CSOSN');
                return null;
            }
            return $g;
        }
        $base = $c->baseOperacao();
        $v = $c->valor('icms', 'vCredICMSSN', Dec::abnt(Dec::pct($base, $p)));
        $c->mem("vCredICMSSN = $base × $p% = $v");
        return $g + ['pCredSN' => Dec::num($p), 'vCredICMSSN' => Dec::num($v)];
    }

    /** @param list<string> $campos */
    private function proibirValores(ItemContexto $c, array $campos, string $onde): void
    {
        foreach ($campos as $k) {
            if ($c->tem('icms', $k)) {
                $this->e->add("$c->campo.regra.icms.$k", "$onde não tem $k");
            }
        }
    }
}
