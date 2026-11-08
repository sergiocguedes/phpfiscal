<?php

declare(strict_types=1);

namespace App\Tributos;

/**
 * Estado de um item durante o cálculo: valores do item (já com rateio), regra normalizada,
 * campos ajustados manualmente, alertas e memória de cálculo.
 *
 * Ajuste manual: qualquer valor final (vBC, vICMS, vBCST...) informado na regra é usado no lugar do
 * calculado. Os campos que dependem dele são recalculados a partir do valor informado
 * (ex.: vBC manual → vICMS = vBC × pICMS). A origem fica registrada e, se a diferença para o
 * calculado passar de R$ 0,01, sai um alerta.
 */
final class ItemContexto
{
    public string $vLiq;
    /** @var array<string,string> campo => 'manual' */
    public array $origem = [];
    /** @var list<string> */
    public array $alertas = [];
    /** @var list<string> */
    public array $memoria = [];
    public string $vIPI = '0';
    /** Valor de pauta / preço tabelado do ICMS ST por unidade (modBCST 0 ou 5), do produto. */
    public ?string $pautaST = null;
    /** @var array<string,mixed>|null regra da matriz usada (perfis), quando houver */
    public ?array $regraAplicada = null;
    public string $vICMS = '0';

    /**
     * @param array<string,mixed> $regra regra normalizada (valores como string decimal)
     */
    public function __construct(
        public readonly int $n,
        public readonly string $campo,
        public readonly bool $servico,
        public readonly string $quantidade,
        public readonly string $vProd,
        public string $vDesc,
        public string $vFrete,
        public string $vSeg,
        public string $vOutro,
        public readonly ?string $qtdTributavel,
        public readonly array $regra,
    ) {
        $this->vLiq = Dec::sub($vProd, $vDesc);
    }

    /** Parâmetro da regra (string decimal ou texto) ou null. */
    public function p(string $grupo, string $campo): ?string
    {
        $v = $this->regra[$grupo][$campo] ?? null;
        return $v === null ? null : (string) $v;
    }

    public function tem(string $grupo, string $campo): bool
    {
        return isset($this->regra[$grupo][$campo]);
    }

    /**
     * Valor final: o informado pelo usuário (ajuste manual) ou o calculado.
     */
    public function valor(string $grupo, string $campo, string $calculado, int $casas = 2): string
    {
        $informado = $this->regra[$grupo][$campo] ?? null;
        $calculado = Dec::fixo($calculado, $casas);
        if ($informado === null) {
            return $calculado;
        }
        $informado = Dec::fixo((string) $informado, $casas);
        $this->origem["$grupo.$campo"] = 'manual';
        if (Dec::cmp(Dec::abs(Dec::sub($informado, $calculado)), '0.01') > 0) {
            $this->alertas[] = "$grupo.$campo informado manualmente (" . $informado . ') difere do calculado (' . $calculado . ')';
        }
        $this->mem("$grupo.$campo = $informado (manual; calculado $calculado)");
        return $informado;
    }

    public function mem(string $linha): void
    {
        $this->memoria[] = $linha;
    }

    public function alerta(string $msg): void
    {
        $this->alertas[] = $msg;
    }

    /** Base das operações próprias: vProd − vDesc + vFrete + vSeg + vOutro. */
    public function baseOperacao(): string
    {
        return Dec::soma($this->vLiq, $this->vFrete, $this->vSeg, $this->vOutro);
    }
}
