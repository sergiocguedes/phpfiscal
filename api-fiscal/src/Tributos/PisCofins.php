<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Support\Errors;

/**
 * PIS e COFINS (mesma regra, grupos distintos). Base = vProd − vDesc (como o ClickPDV),
 * opcionalmente sem o ICMS destacado (operacao.excluir_icms_base_piscofins, Tema 69 do STF).
 */
final class PisCofins
{
    private const ALIQUOTA = ['01', '02'];
    private const QUANTIDADE = ['03'];
    private const SEM_VALOR = ['04', '05', '06', '07', '08', '09'];
    private const OUTRAS = ['49', '50', '51', '52', '53', '54', '55', '56', '60', '61', '62', '63', '64', '65', '66', '67',
        '70', '71', '72', '73', '74', '75', '98', '99'];

    /** @param array<string,mixed> $op */
    public function __construct(private readonly array $op, private readonly Errors $e)
    {
    }

    /**
     * @param 'pis'|'cofins' $grupo
     * @return array<string,mixed>|null
     */
    public function calcular(ItemContexto $c, string $grupo): ?array
    {
        $U = strtoupper($grupo);
        $f = "$c->campo.regra.$grupo";
        $cst = (string) $c->p($grupo, 'CST');
        $p = $c->p($grupo, "p$U");
        $vAliq = $c->p($grupo, 'vAliqProd');
        $g = ['CST' => $cst];

        if (in_array($cst, self::SEM_VALOR, true)) {
            foreach (['vBC', "v$U", 'qBCProd'] as $k) {
                if ($c->tem($grupo, $k)) {
                    $this->e->add("$f.$k", "CST $cst não tem $k");
                }
            }
            return $g;
        }
        if (in_array($cst, self::QUANTIDADE, true) || (in_array($cst, self::OUTRAS, true) && $vAliq !== null)) {
            if ($vAliq === null) {
                $this->e->add("$f.vAliqProd", 'Alíquota em reais por unidade obrigatória no CST 03');
                return null;
            }
            $q = $c->valor($grupo, 'qBCProd', $c->quantidade, 4);
            $v = $c->valor($grupo, "v$U", Dec::abnt(Dec::mul($q, $vAliq)));
            $c->mem("v$U = $q × R$ $vAliq = $v");
            return $g + ['qBCProd' => Dec::num($q), 'vAliqProd' => Dec::num($vAliq), "v$U" => Dec::num($v)];
        }
        if (in_array($cst, self::ALIQUOTA, true) || in_array($cst, self::OUTRAS, true)) {
            if ($p === null) {
                if (in_array($cst, self::ALIQUOTA, true)) {
                    $this->e->add("$f.p$U", "Alíquota obrigatória no CST $cst");
                    return null;
                }
                // outras operações sem alíquota: grupo zerado (exigido pelo leiaute PISOutr/COFINSOutr)
                return $g + ['vBC' => 0, "p$U" => 0, "v$U" => 0];
            }
            $vBC = $c->valor($grupo, 'vBC', $this->base($c));
            $v = $c->valor($grupo, "v$U", Dec::abnt(Dec::pct($vBC, $p)));
            $c->mem("v$U = $vBC × $p% = $v");
            return $g + ['vBC' => Dec::num($vBC), "p$U" => Dec::num($p), "v$U" => Dec::num($v)];
        }
        $this->e->add("$f.CST", 'CST de PIS/COFINS inválido');
        return null;
    }

    private function base(ItemContexto $c): string
    {
        $base = $c->vLiq;
        if ($this->op['excluir_icms_base_piscofins'] && !Dec::zero($c->vICMS)) {
            $base = Dec::max0(Dec::sub($base, $c->vICMS));
            $c->mem("base PIS/COFINS sem o ICMS destacado ($c->vICMS) = $base");
        }
        return Dec::abnt($base);
    }
}
