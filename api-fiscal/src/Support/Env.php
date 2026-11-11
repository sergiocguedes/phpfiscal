<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Leitura de configuração.
 *
 * - Inicialização (BOOTSTRAP): conexão com o PostgreSQL e chave-mestra. Vêm das variáveis de ambiente do
 *   processo (Docker: env_file /etc/phpfiscal/bootstrap.env, fora do projeto) ou, em desenvolvimento, do .env.
 * - Todo o resto: tabela de configurações cifrada no banco (Settings), editada na janela /configuracao.
 *   Um valor ainda presente num .env antigo vale só até ser gravado no banco (bin/configuracao.php importar).
 *
 * Não usa putenv para não vazar segredos a processos filhos.
 */
final class Env
{
    /** Únicas chaves que podem (e precisam) existir fora do banco. */
    public const BOOTSTRAP = ['DATABASE_DSN', 'DATABASE_USER', 'DATABASE_PASSWORD', 'DATABASE_SCHEMA', 'STORAGE_NAMESPACE', 'CERT_ENCRYPTION_KEY'];
    /** Usadas apenas em desenvolvimento/testes ou por compatibilidade com instalações antigas. */
    private const INTERNAL = ['STORAGE_DRIVER', 'STORAGE_DIR', 'API_KEY', 'APP_ENV'];

    /** @var array<string,string> valores do arquivo .env (legado / desenvolvimento) */
    private static array $vars = [];
    /** @var array<string,string> sobrescritos em tempo de execução (testes) */
    private static array $overrides = [];

    public static function load(string $file): void
    {
        self::$vars = [];
        self::$overrides = [];
        Settings::reset();
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
        self::$overrides[$key] = $value;
    }

    /** Remove uma sobrescrita feita por set() (testes). */
    public static function clear(string $key): void
    {
        unset(self::$overrides[$key]);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = self::$overrides[$key] ?? null;
        if ($v === null && in_array($key, self::BOOTSTRAP, true) || $v === null && in_array($key, self::INTERNAL, true)) {
            $p = getenv($key);
            $v = ($p === false || $p === '') ? null : $p;
        }
        if ($v === null && !in_array($key, self::BOOTSTRAP, true) && !in_array($key, self::INTERNAL, true)) {
            $v = Settings::get($key);
        }
        $v ??= self::$vars[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    /** Valor presente só no arquivo .env (para importação e para mostrar a origem na janela). */
    public static function fileValue(string $key): ?string
    {
        $v = self::$vars[$key] ?? null;
        return ($v === null || $v === '') ? null : $v;
    }

    /** Situação dos dados de inicialização, sem revelar valores. */
    public static function bootstrapView(): array
    {
        $out = [];
        foreach (self::BOOTSTRAP as $k) {
            $p = getenv($k);
            $origem = ($p !== false && $p !== '') ? 'ambiente' : (isset(self::$vars[$k]) && self::$vars[$k] !== '' ? 'arquivo' : null);
            $out[] = ['chave' => $k, 'definido' => $origem !== null, 'origem' => $origem];
        }
        $legado = array_values(array_diff(array_keys(array_filter(self::$vars, fn($v) => $v !== '')), self::BOOTSTRAP, self::INTERNAL));
        return ['itens' => $out, 'arquivo_legado_com' => $legado];
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
