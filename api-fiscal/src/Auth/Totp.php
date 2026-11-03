<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * TOTP (RFC 6238) sobre HOTP (RFC 4226), sem dependências externas.
 *  - HMAC-SHA1, passo de 30 s, 6 dígitos, tolerância de ±1 passo (relógio do celular);
 *  - segredo de 160 bits (random_bytes(20)) em Base32 (RFC 4648, sem padding), como os
 *    aplicativos autenticadores (Google Authenticator, Microsoft Authenticator, Aegis, 1Password) esperam;
 *  - comparação em tempo constante (hash_equals).
 */
final class Totp
{
    public const PERIODO = 30;
    public const DIGITOS = 6;
    public const ISSUER = 'Emissor NF-e';
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function gerarSegredo(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=-]/', '', $b32) ?? '');
        if ($b32 === '' || strspn($b32, self::B32) !== strlen($b32)) {
            throw new \InvalidArgumentException('Base32 inválido');
        }
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    
    public static function hotp(string $segredoB32, int $contador): string
    {
        $key = self::base32Decode($segredoB32);
        $h = hash_hmac('sha1', pack('J', $contador), $key, true);
        $off = ord($h[19]) & 0x0f;
        $bin = ((ord($h[$off]) & 0x7f) << 24) | (ord($h[$off + 1]) << 16) | (ord($h[$off + 2]) << 8) | ord($h[$off + 3]);
        return str_pad((string) ($bin % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    public static function passo(?int $agora = null): int
    {
        return intdiv($agora ?? time(), self::PERIODO);
    }

    public static function codigo(string $segredoB32, ?int $agora = null): string
    {
        return self::hotp($segredoB32, self::passo($agora));
    }

    
    public static function verificar(string $segredoB32, string $codigo, ?int $ultimoPasso = null, int $janela = 1, ?int $agora = null): ?int
    {
        if (preg_match('/^\d{' . self::DIGITOS . '}$/', $codigo) !== 1) {
            return null;
        }
        $atual = self::passo($agora);
        $aceito = null;
        for ($i = -$janela; $i <= $janela; $i++) {
            $c = $atual + $i;
            if (hash_equals(self::hotp($segredoB32, $c), $codigo) && ($ultimoPasso === null || $c > $ultimoPasso) && $aceito === null) {
                $aceito = $c;
            }
        }
        return $aceito;
    }

    /** URI otpauth:// (formato Key Uri do Google Authenticator) para QR-Code ou digitação manual. */
    public static function uri(string $segredoB32, string $conta): string
    {
        $label = rawurlencode(self::ISSUER) . ':' . rawurlencode($conta);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $segredoB32,
            'issuer' => self::ISSUER,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITOS,
            'period' => self::PERIODO,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Segredo em grupos de 4 caracteres, para digitação manual. */
    public static function agrupar(string $segredoB32): string
    {
        return implode(' ', str_split($segredoB32, 4));
    }
}
