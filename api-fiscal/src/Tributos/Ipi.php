<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Support\Errors;

/**
 * IPI (só NF-e). Tributado (00, 49, 50, 99): por alíquota sobre o valor da operação ou por unidade (vUnid).
 * Não tributado (01–05, 51–55): só o CST. A NFC-e não tem IPI.
 */
final class Ipi
{
    private const TRIBUTADO = ['00', '49', '50', '99'];
    private const NAO_TRIBUTADO = ['01', '02', '03', '04', '05', '51', '52', '53', '54', '55'];

    /** @param array<string,mixed> $op */
    public function __construct(private readonly array $op, private readonly Errors $e)
    {
    }

    /** @return array<string,mixed>|null */
    public function calcular(ItemContexto $c): ?array
    {
        if (!isset($c->regra['ipi'])) {
            return null;
        }
        $f = "$c->campo.regra.ipi";
        if ($this->op['modelo'] === 65) {
            $c->alerta('NFC-e não tem IPI: regra.ipi ignorada');
            return null;
        }
        $cst = $c->p('ipi', 'CST');
        if ($cst === null) {
            $this->e->add("$f.CST", 'Obrigatório');
            return null;
        }
        $g = ['CST' => $cst, 'cEnq' => $c->p('ipi', 'cEnq') ?? '999'];
        if (in_array($cst, self::NAO_TRIBUTADO, true)) {
            return $g;
        }
        if (!in_array($cst, self::TRIBUTADO, true)) {
            $this->e->add("$f.CST", 'CST de IPI inválido');
            return null;
        }
        $vUnid = $c->p('ipi', 'vUnid');
        if ($vUnid !== null) {
            $q = $c->valor('ipi', 'qUnid', $c->quantidade, 4);
            $v = $c->valor('ipi', 'vIPI', Dec::abnt(Dec::mul($q, $vUnid)));
            $c->vIPI = $v;
            $c->mem("vIPI = $q × R$ $vUnid = $v");
            return $g + ['qUnid' => Dec::num($q), 'vUnid' => Dec::num($vUnid), 'vIPI' => Dec::num($v)];
        }
        $p = $c->p('ipi', 'pIPI');
        if ($p === null) {
            $this->e->add("$f.pIPI", 'Informe pIPI (alíquota) ou vUnid (valor por unidade)');
            return null;
        }
        $vBC = $c->valor('ipi', 'vBC', Dec::abnt($c->baseOperacao()));
        $v = $c->valor('ipi', 'vIPI', Dec::abnt(Dec::pct($vBC, $p)));
        $c->vIPI = $v;
        $c->mem("vIPI = $vBC × $p% = $v");
        return $g + ['vBC' => Dec::num($vBC), 'pIPI' => Dec::num($p), 'vIPI' => Dec::num($v)];
    }
}
