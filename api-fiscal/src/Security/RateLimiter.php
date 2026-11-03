<?php

declare(strict_types=1);

namespace App\Security;

use App\Storage\JsonStore;
use App\Support\Env;

/**
 * Limitadores baseados em arquivo (storage/ratelimit, com flock):
 *
 *  - fail()/blockedFor(): falhas de autenticação por IP (Bearer inválido ou
 *    HMAC inválido). Após AUTH_MAX_FAILURES falhas em AUTH_WINDOW_SECONDS, o
 *    IP fica bloqueado por AUTH_BLOCK_SECONDS — inclusive para credenciais válidas.
 *
 *  - hit()/count()/clear(): janela deslizante genérica por "balde" + chave
 *    (login por IP, cadastro por IP, esqueci-senha por IP/e-mail, bloqueio de conta).
 */
final class RateLimiter
{
    private static function file(string $ip): string
    {
        return 'ratelimit/' . hash('sha256', $ip) . '.json';
    }

    private static function bucketFile(string $bucket, string $key): string
    {
        return 'ratelimit/' . preg_replace('/[^a-z0-9_-]/', '', $bucket) . '-' . hash('sha256', $bucket . '|' . $key) . '.json';
    }

    /** Segundos restantes de bloqueio (0 = liberado). */
    public static function blockedFor(string $ip): int
    {
        $d = JsonStore::read(self::file($ip));
        $until = (int) ($d['blocked_until'] ?? 0);
        return max(0, $until - time());
    }

    /** Registra falha; devolve segundos de bloqueio (0 se ainda não bloqueado). */
    public static function fail(string $ip): int
    {
        $max = Env::int('AUTH_MAX_FAILURES', 10);
        $window = Env::int('AUTH_WINDOW_SECONDS', 300);
        $block = Env::int('AUTH_BLOCK_SECONDS', 900);
        return JsonStore::update(self::file($ip), function (?array $d) use ($max, $window, $block) {
            $now = time();
            $fails = array_values(array_filter($d['fails'] ?? [], fn($t) => $t > $now - $window));
            $fails[] = $now;
            $until = (int) ($d['blocked_until'] ?? 0);
            if (count($fails) >= $max) {
                $until = $now + $block;
                $fails = [];
            }
            return [['fails' => $fails, 'blocked_until' => $until], max(0, $until - $now)];
        });
    }

    /**
     * Registra um evento no balde. Se, contando este, houver mais de $max
     * eventos na janela, devolve os segundos até liberar (>0) e NÃO registra.
     */
    public static function hit(string $bucket, string $key, int $max, int $window): int
    {
        return JsonStore::update(self::bucketFile($bucket, $key), function (?array $d) use ($max, $window) {
            $now = time();
            $ev = array_values(array_filter($d['ev'] ?? [], fn($t) => is_int($t) && $t > $now - $window));
            if (count($ev) >= $max) {
                return [['ev' => $ev], max(1, $ev[0] + $window - $now)];
            }
            $ev[] = $now;
            return [['ev' => $ev], 0];
        });
    }

    /** Quantos eventos há no balde dentro da janela. */
    public static function count(string $bucket, string $key, int $window): int
    {
        $d = JsonStore::read(self::bucketFile($bucket, $key));
        $now = time();
        return count(array_filter($d['ev'] ?? [], fn($t) => is_int($t) && $t > $now - $window));
    }

    /** Segundos até o evento mais antigo sair da janela (0 se vazio). */
    public static function retryAfter(string $bucket, string $key, int $window): int
    {
        $d = JsonStore::read(self::bucketFile($bucket, $key));
        $now = time();
        $ev = array_values(array_filter($d['ev'] ?? [], fn($t) => is_int($t) && $t > $now - $window));
        return $ev === [] ? 0 : max(1, $ev[0] + $window - $now);
    }

    public static function clear(string $bucket, string $key): void
    {
        JsonStore::delete(self::bucketFile($bucket, $key));
    }
}
