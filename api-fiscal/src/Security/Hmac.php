<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Assinatura HMAC-SHA256 sobre o corpo bruto.
 * Formato do cabeçalho: X-Signature: sha256=<hex minúsculo>
 */
final class Hmac
{
    public static function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public static function verify(string $body, ?string $header, string $secret): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }
        $header = trim($header);
        if (!preg_match('/^sha256=([a-fA-F0-9]{64})$/', $header, $m)) {
            return false;
        }
        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, strtolower($m[1]));
    }
}
