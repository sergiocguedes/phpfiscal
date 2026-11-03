<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Leitor simples de .env (CHAVE=valor). Não usa putenv para não vazar
 * segredos a processos filhos.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        self::$vars = [];
        self::$loaded = true;
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) {
                self::$vars[$k] = $v;
            }
        }
    }

    /** Permite sobrescrever valores (usado nos testes). */
    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = self::$vars[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null) {
            throw new \RuntimeException("Variável de ambiente obrigatória ausente: $key");
        }
        return $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'sim'], true);
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return ($v !== null && preg_match('/^\d+$/', $v)) ? (int) $v : $default;
    }
}
