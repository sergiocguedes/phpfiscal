<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Caminhos de armazenamento. Tudo fica em storage/ (permissão 700).
 * Pode ser trocado via STORAGE_DIR (usado nos testes).
 */
final class Paths
{
    public static function storage(string $sub = ''): string
    {
        $base = Env::get('STORAGE_DIR', APP_ROOT . '/storage');
        return rtrim($base, '/') . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }

    public static function ensure(): void
    {
        $old = umask(0077);
        foreach (['', 'logs', 'ratelimit', 'tmp', 'locks', 'tenants', 'users', 'sessions', 'apikeys', 'resets', 'mfa-pendente'] as $d) {
            $p = self::storage($d);
            if (!is_dir($p)) {
                @mkdir($p, 0700, true);
            }
        }
        umask($old);
    }
}
