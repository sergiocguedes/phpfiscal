<?php
declare(strict_types=1);
namespace App\Storage;
use App\Support\Paths;

/** Leitura dos XML gravados em arquivo por instalações anteriores (storage/xml, storage/xml-pendentes). Nada novo é gravado aqui. */
final class XmlFiles
{
    public static function path(string $relative): string
    {
        if (!preg_match('~^(?:xml|xml-pendentes)/tenants/t_[a-f0-9]{16}/ambientes/[12]/(?:nfe|nfse|pdv)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.xml$~D', $relative) || str_contains($relative, '..')) {
            throw new \InvalidArgumentException('Referência de arquivo XML inválida');
        }
        return Paths::storage($relative);
    }
    public static function get(string $relative): ?string
    {
        $file = self::path($relative);
        if (!is_file($file)) { return null; }
        $data = file_get_contents($file);
        if ($data === false) { throw new \RuntimeException('Falha ao ler XML privado'); }
        return $data;
    }
}
