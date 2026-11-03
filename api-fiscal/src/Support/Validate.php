<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validações de domínio fiscal (sem efeitos colaterais).
 */
final class Validate
{
    public const UFS = [
        'RO' => 11, 'AC' => 12, 'AM' => 13, 'RR' => 14, 'PA' => 15, 'AP' => 16, 'TO' => 17,
        'MA' => 21, 'PI' => 22, 'CE' => 23, 'RN' => 24, 'PB' => 25, 'PE' => 26, 'AL' => 27,
        'SE' => 28, 'BA' => 29, 'MG' => 31, 'ES' => 32, 'RJ' => 33, 'SP' => 35, 'PR' => 41,
        'SC' => 42, 'RS' => 43, 'MS' => 50, 'MT' => 51, 'GO' => 52, 'DF' => 53,
    ];

    /** Brasília: o Distrito Federal não tem municípios, então 5300108 é o ÚNICO código IBGE válido com UF 53. */
    public const IBGE_BRASILIA = '5300108';

    /**
     * v2.9.3: checagem extra do código IBGE (7 dígitos já validados). Hoje: no DF só existe 5300108.
     * Devolve a mensagem de erro ou null se o código é aceitável.
     */
    public static function municipioIbgeErro(string $cMun): ?string
    {
        if (str_starts_with($cMun, '53') && $cMun !== self::IBGE_BRASILIA) {
            return 'No Distrito Federal o único código IBGE é ' . self::IBGE_BRASILIA . ' (Brasília). Use esse código para qualquer região administrativa do DF.';
        }
        return null;
    }

    public static function cnpj(string $v): bool
    {
        if (!preg_match('/^\d{14}$/', $v) || preg_match('/^(\d)\1{13}$/', $v)) {
            return false;
        }
        foreach ([12, 13] as $len) {
            $sum = 0;
            $w = $len - 7;
            for ($i = 0; $i < $len; $i++) {
                $sum += (int) $v[$i] * $w;
                $w = $w === 2 ? 9 : $w - 1;
            }
            $dv = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $v[$len] !== $dv) {
                return false;
            }
        }
        return true;
    }

    public static function cpf(string $v): bool
    {
        if (!preg_match('/^\d{11}$/', $v) || preg_match('/^(\d)\1{10}$/', $v)) {
            return false;
        }
        foreach ([9, 10] as $len) {
            $sum = 0;
            for ($i = 0; $i < $len; $i++) {
                $sum += (int) $v[$i] * ($len + 1 - $i);
            }
            $dv = ($sum * 10) % 11 % 10;
            if ((int) $v[$len] !== $dv) {
                return false;
            }
        }
        return true;
    }

    public static function chave(string $v): bool
    {
        if (!preg_match('/^\d{44}$/', $v)) {
            return false;
        }
        $sum = 0;
        $w = 2;
        for ($i = 42; $i >= 0; $i--) {
            $sum += (int) $v[$i] * $w;
            $w = $w === 9 ? 2 : $w + 1;
        }
        $dv = 11 - $sum % 11;
        $dv = $dv >= 10 ? 0 : $dv;
        return (int) $v[43] === $dv;
    }

    public static function digits(mixed $v): string
    {
        return is_scalar($v) ? preg_replace('/\D/', '', (string) $v) : '';
    }

    /** Texto livre: sem caracteres de controle, tamanho limitado. */
    public static function text(mixed $v, int $min, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        if ($v === '' && $min > 0) {
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f<>]/u', $v) || !mb_check_encoding($v, 'UTF-8')) {
            return null;
        }
        $len = mb_strlen($v);
        return ($len >= $min && $len <= $max) ? $v : null;
    }

    /**
     * E-mail estrito para cabeçalhos de mensagem (From, To, Bcc, Reply-To) — OWASP A03 (injeção de
     * cabeçalho): só um endereço "local@dominio", sem nome, vírgula, ponto e vírgula, aspas, espaços,
     * < > nem CR/LF ou outros caracteres de controle; local até 64 e total até 254 caracteres;
     * domínio com pelo menos um ponto. Devolve o endereço (sem espaços nas pontas) ou null.
     */
    public static function email(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/[\x00-\x1f\x7f]/', $v)) {
            return null;
        }
        $v = trim($v, ' ');
        if ($v === '' || strlen($v) > 254) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]{1,64}@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/', $v)) {
            return null;
        }
        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : null;
    }

    /** Uma linha de texto para cabeçalho (nome, assunto): remove CR/LF/controle e limita o tamanho. */
    public static function headerLine(string $v, int $max): string
    {
        $v = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v) ?? '';
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        return mb_substr($v, 0, $max);
    }

    /** Número decimal >= 0 com no máximo $dec casas. */
    public static function decimal(mixed $v, int $dec = 2, float $max = 9999999999999.99): ?float
    {
        if (is_int($v) || is_float($v)) {
            $s = (string) $v;
        } elseif (is_string($v) && preg_match('/^\d+(\.\d+)?$/', $v)) {
            $s = $v;
        } else {
            return null;
        }
        if (!is_numeric($s)) {
            return null;
        }
        $f = (float) $s;
        if ($f < 0 || $f > $max || !is_finite($f)) {
            return null;
        }
        return round($f, $dec);
    }
}
