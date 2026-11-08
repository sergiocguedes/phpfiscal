<?php

declare(strict_types=1);

namespace App\Tributos;

/**
 * Aritmética decimal exata (bcmath) para o cálculo de tributos.
 * Valores circulam como string numérica; nada de float nas contas.
 *
 * Arredondamento:
 *  - abnt(): ABNT NBR 5891, o mesmo RoundABNT do ACBr usado pelo ClickPDV. Dígito seguinte < 5 trunca,
 *    > 5 sobe; = 5 seguido de qualquer dígito diferente de zero sobe; = 5 seguido só de zeros arredonda
 *    para o par (sobe se o último dígito mantido for ímpar).
 *  - meio(): "half up" comum (round() do PHP), usado em vProd = qCom × vUnCom para coincidir com o NfeBuilder.
 */
final class Dec
{
    public const ESCALA = 10;

    /** Converte entrada JSON (int, float ou string numérica) em string decimal. Null se inválida. */
    public static function de(mixed $v): ?string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            if (!is_finite($v)) {
                return null;
            }
            return self::limpar(sprintf('%.' . self::ESCALA . 'F', $v));
        }
        if (is_string($v) && preg_match('/^-?\d{1,15}(\.\d{1,10})?$/D', $v)) {
            return self::limpar($v);
        }
        return null;
    }

    public static function soma(string ...$v): string
    {
        $r = '0';
        foreach ($v as $x) {
            $r = bcadd($r, $x, self::ESCALA);
        }
        return self::limpar($r);
    }

    public static function sub(string $a, string $b): string
    {
        return self::limpar(bcsub($a, $b, self::ESCALA));
    }

    public static function mul(string $a, string $b): string
    {
        return self::limpar(bcmul($a, $b, self::ESCALA));
    }

    public static function div(string $a, string $b): string
    {
        if (bccomp($b, '0', self::ESCALA) === 0) {
            return '0';
        }
        return self::limpar(bcdiv($a, $b, self::ESCALA));
    }

    /** a × p / 100 (percentual) */
    public static function pct(string $a, string $p): string
    {
        return self::div(self::mul($a, $p), '100');
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::ESCALA);
    }

    public static function zero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function max0(string $a): string
    {
        return self::cmp($a, '0') < 0 ? '0' : $a;
    }

    public static function abs(string $a): string
    {
        return ltrim($a, '-');
    }

    /** Arredondamento ABNT NBR 5891 (RoundABNT do ACBr). */
    public static function abnt(string $v, int $casas = 2): string
    {
        $neg = str_starts_with($v, '-');
        $v = ltrim($v, '-');
        [$int, $frac] = array_pad(explode('.', $v, 2), 2, '');
        $frac = str_pad($frac, $casas + 1, '0');
        $mantido = $int . '.' . substr($frac, 0, $casas);
        $seguinte = (int) $frac[$casas];
        $resto = substr($frac, $casas + 1);
        $sobe = false;
        if ($seguinte > 5) {
            $sobe = true;
        } elseif ($seguinte === 5) {
            if (trim($resto, '0') !== '') {
                $sobe = true;
            } else {
                $ultimo = $casas > 0 ? (int) substr($frac, $casas - 1, 1) : (int) substr($int, -1);
                $sobe = $ultimo % 2 === 1;
            }
        }
        $r = bcadd($mantido, $sobe ? '0.' . str_repeat('0', max(0, $casas - 1)) . '1' : '0', $casas);
        if ($casas === 0) {
            $r = bcadd($int, $sobe ? '1' : '0', 0);
        }
        $r = self::fixo($r, $casas);
        return $neg && self::cmp($r, '0') !== 0 ? '-' . $r : $r;
    }

    /** Arredondamento "half up" (round() do PHP), sem passar por float. */
    public static function meio(string $v, int $casas = 2): string
    {
        $neg = str_starts_with($v, '-');
        $ajuste = '0.' . str_repeat('0', $casas) . '5';
        $r = $neg ? bcsub($v, $ajuste, $casas) : bcadd($v, $ajuste, $casas);
        $r = self::fixo($r, $casas);
        return self::cmp($r, '0') === 0 ? self::fixo('0', $casas) : $r;
    }

    /** String com exatamente $casas decimais (sem arredondar: use abnt/meio antes). */
    public static function fixo(string $v, int $casas): string
    {
        return bcadd($v, '0', $casas);
    }

    /** Número para o JSON de saída. */
    public static function num(string $v): float|int
    {
        $f = (float) $v;
        return floor($f) === $f && abs($f) < 1e15 && !str_contains(self::limpar($v), '.') ? (int) $f : $f;
    }

    private static function limpar(string $v): string
    {
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }
        if ($v === '' || $v === '-' || $v === '-0') {
            return '0';
        }
        return $v;
    }
}
