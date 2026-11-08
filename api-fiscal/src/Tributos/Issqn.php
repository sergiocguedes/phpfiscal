<?php

declare(strict_types=1);

namespace App\Tributos;

/**
 * ISSQN de item de serviço (NF-e conjugada / venda com serviço no PDV).
 * Base: valor do serviço sem desconto quando o desconto é condicionado; senão, valor com desconto
 * (mesma regra do ClickPDV, USA_DESCONTO_INCONDICIONAL).
 */
final class Issqn
{
    /** @param array<string,mixed> $op */
    public function __construct(private readonly array $op)
    {
    }

    /** @return array<string,mixed> */
    public function calcular(ItemContexto $c): array
    {
        $aliq = (string) $c->p('iss', 'aliquota');
        $base = $this->op['desconto_iss_condicionado'] ? $c->vProd : $c->vLiq;
        $vBC = $c->valor('iss', 'vBC', Dec::abnt($base));
        $v = $c->valor('iss', 'vISSQN', Dec::abnt(Dec::pct($vBC, $aliq)));
        $c->mem("vISSQN = $vBC × $aliq% = $v");
        $g = ['vBC' => Dec::num($vBC), 'vAliq' => Dec::num($aliq), 'vISSQN' => Dec::num($v)];
        foreach (['cMunFG', 'cListServ', 'indISS', 'indIncentivo'] as $k) {
            $x = $c->p('iss', $k);
            if ($x !== null) {
                $g[$k] = in_array($k, ['indISS', 'indIncentivo'], true) ? (int) $x : $x;
            }
        }
        return $g;
    }
}
