<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Env;

/**
 * Cifragem simétrica autenticada (libsodium secretbox: XSalsa20-Poly1305)
 * com chave de 32 bytes em CERT_ENCRYPTION_KEY (base64) no .env.
 */
final class Crypto
{
    private static function key(): string
    {
        $b64 = Env::require('CERT_ENCRYPTION_KEY');
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('CERT_ENCRYPTION_KEY inválida');
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $c = sodium_crypto_secretbox($plain, $nonce, $key);
        sodium_memzero($key);
        return 'v1:' . base64_encode($nonce . $c);
    }

    public static function decrypt(string $blob): string
    {
        if (!str_starts_with($blob, 'v1:')) {
            throw new \RuntimeException('Formato cifrado desconhecido');
        }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Dado cifrado corrompido');
        }
        $key = self::key();
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );
        sodium_memzero($key);
        if ($plain === false) {
            throw new \RuntimeException('Falha ao decifrar (chave incorreta ou dado adulterado)');
        }
        return $plain;
    }
}
