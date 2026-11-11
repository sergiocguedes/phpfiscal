<?php

declare(strict_types=1);

namespace App\Storage;

use App\Support\Paths;

/**
 * Persistência em arquivos JSON com escrita atômica (tmp + rename) e
 * exclusão mútua via flock. Arquivos criados com permissão 600.
 */
final class JsonStore
{
    public static function path(string $relative): string
    {
        if (str_contains($relative, '..') || str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('Caminho inválido');
        }
        return Paths::storage($relative);
    }

    /** @return array<mixed>|null */
    public static function read(string $relative): ?array
    {
        if (PostgresStore::enabled()) {
            self::path($relative);
            $raw = PostgresStore::read($relative);
            return $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        $file = self::path($relative);
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<mixed> $data */
    public static function write(string $relative, array $data): void
    {
        self::writeRaw($relative, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function writeRaw(string $relative, string $content): void
    {
        if (XmlArchive::recognizes($relative)) { XmlArchive::put($relative, $content); return; } // banco ou B2
        self::writeBackend($relative, $content);
    }

    /** Gravação direta no backend (PostgreSQL; arquivos só nos testes). */
    public static function writeBackend(string $relative, string $content): void
    {
        if (PostgresStore::enabled()) { self::path($relative); PostgresStore::write($relative,$content); return; }
        $file = self::path($relative);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, $content, LOCK_EX) === false) {
                throw new \RuntimeException('Falha ao gravar arquivo');
            }
            chmod($tmp, 0600);
            if (!rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('Falha ao gravar arquivo');
            }
        } finally {
            umask($old);
        }
    }

    public static function readRaw(string $relative): ?string
    {
        $raw = self::readBackend($relative);
        if ($raw === null && XmlArchive::recognizes($relative) && XmlArchive::exists($relative)) { return XmlArchive::get($relative); }
        return $raw;
    }

    public static function readBackend(string $relative): ?string
    {
        if (PostgresStore::enabled()) { self::path($relative); return PostgresStore::read($relative); }
        $file = self::path($relative);
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public static function delete(string $relative): void
    {
        self::deleteBackend($relative);
    }

    /** Remoção da cópia no backend. */
    public static function deleteBackend(string $relative): void
    {
        if (PostgresStore::enabled()) { self::path($relative); PostgresStore::delete($relative); return; }
        $file = self::path($relative);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Executa $fn com lock exclusivo nomeado. $fn recebe o conteúdo atual
     * do JSON e devolve [novoConteudo|null, retorno].
     *
     * @template T
     * @param callable(array<mixed>|null): array{0: array<mixed>|null, 1: T} $fn
     * @return T
     */
    public static function update(string $relative, callable $fn)
    {
        return self::withLock('store-' . sha1($relative), function () use ($relative, $fn) {
            [$new, $ret] = $fn(self::read($relative));
            if ($new !== null) {
                self::write($relative, $new);
            }
            return $ret;
        });
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function withLock(string $name, callable $fn)
    {
        if (PostgresStore::enabled()) { return PostgresStore::lock($name,$fn); }
        $lockFile = Paths::storage('locks/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name) . '.lock');
        $h = fopen($lockFile, 'c');
        if ($h === false) {
            throw new \RuntimeException('Falha ao obter lock');
        }
        @chmod($lockFile, 0600);
        try {
            flock($h, LOCK_EX);
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** Lista chaves relativas, em ordem de atualização, sem depender do filesystem. */
    public static function files(string $pattern): array
    {
        self::path($pattern);
        if (PostgresStore::enabled()) { $files = PostgresStore::files($pattern); }
        else {
        $files=glob(self::path($pattern)) ?: [];
        usort($files, fn($a,$b)=>filemtime($b)<=>filemtime($a) ?: strcmp($a,$b));
        $prefix=rtrim(Paths::storage(),'/').'/';
        $files = array_map(fn($p)=>substr($p,strlen($prefix)),$files);
        }
        if (str_starts_with($pattern, 'tenants/')) { $files = array_values(array_unique(array_merge($files, XmlArchive::paths($pattern)))); }
        return $files;
    }

    public static function mtime(string $relative): int
    {
        $t = PostgresStore::enabled() ? PostgresStore::mtime($relative) : (int) @filemtime(self::path($relative));
        if ($t === 0 && XmlArchive::recognizes($relative) && XmlArchive::exists($relative)) { return self::mtime('archive/index/'.hash('sha256',$relative).'.json'); }
        return $t;
    }
}
