<?php

declare(strict_types=1);

namespace App\Nfse;

use App\Http\ApiException;
use App\Storage\JsonStore;
use App\Tenancy\Tenant;

/**
 * NFS-e da empresa, sempre dentro do diretório do tenant autenticado (storage/tenants/<id>/nfse/):
 *   <idDps>.json              metadados e histórico (a DPS existe antes da chave da NFS-e)
 *   <idDps>-dps.xml           DPS assinada enviada
 *   <idDps>-nfse.xml          NFS-e autorizada devolvida pela Sefin
 *   <idDps>-evento-101101.xml evento de cancelamento
 *   <idDps>-danfse.pdf        DANFSe baixado do ADN (cache)
 *   chaves.json               índice chave de acesso (50 dígitos) -> idDps
 * Uma referência de outro tenant simplesmente não existe aqui (404) — sem IDOR.
 */
final class NfseRepository
{
    private const ARQUIVOS = ['dps' => 'dps.xml', 'nfse' => 'nfse.xml', 'evento-101101' => 'evento-101101.xml', 'danfse' => 'danfse.pdf'];

    public static function validId(string $id): bool
    {
        return preg_match('/^DPS\d{42}$/', $id) === 1;
    }

    /** Referência da API: id da DPS ("DPS" + 42 dígitos) ou chave de acesso da NFS-e (50 dígitos). */
    public static function resolve(string $ref): string
    {
        if (self::validId($ref)) {
            return $ref;
        }
        if (preg_match('/^\d{50}$/', $ref)) {
            $id = (JsonStore::read(Tenant::path('nfse/chaves.json')) ?? [])[$ref] ?? null;
            if (is_string($id) && self::validId($id)) {
                return $id;
            }
            throw new ApiException(404, 'nao_encontrada', 'NFS-e não encontrada');
        }
        throw new ApiException(400, 'referencia_invalida', 'Use o id da DPS ("DPS" + 42 dígitos) ou a chave de acesso da NFS-e (50 dígitos)');
    }

    /** @return array<string,mixed>|null */
    public static function find(string $id): ?array
    {
        return self::validId($id) ? JsonStore::read(Tenant::path("nfse/$id.json")) : null;
    }

    /** @return array<string,mixed> */
    public static function get(string $ref): array
    {
        return self::find(self::resolve($ref)) ?? throw new ApiException(404, 'nao_encontrada', 'NFS-e não encontrada');
    }

    /** @param array<string,mixed> $rec */
    public static function save(array $rec): void
    {
        if (!self::validId((string) $rec['id'])) {
            throw new \InvalidArgumentException('id inválido');
        }
        $rec['atualizado_em'] = date('c');
        JsonStore::write(Tenant::path('nfse/' . $rec['id'] . '.json'), $rec);
        if (!empty($rec['chave']) && preg_match('/^\d{50}$/', (string) $rec['chave'])) {
            JsonStore::update(Tenant::path('nfse/chaves.json'), function (?array $d) use ($rec) {
                $d ??= [];
                if (($d[$rec['chave']] ?? null) === $rec['id']) {
                    return [null, null];
                }
                $d[$rec['chave']] = $rec['id'];
                return [$d, null];
            });
        }
    }

    public static function saveFile(string $id, string $tipo, string $content): void
    {
        if (!self::validId($id) || !isset(self::ARQUIVOS[$tipo])) {
            throw new \InvalidArgumentException('arquivo inválido');
        }
        JsonStore::writeRaw(Tenant::path("nfse/$id-" . self::ARQUIVOS[$tipo]), $content);
    }

    public static function file(string $id, string $tipo): ?string
    {
        if (!self::validId($id) || !isset(self::ARQUIVOS[$tipo])) {
            throw new \InvalidArgumentException('arquivo inválido');
        }
        return JsonStore::readRaw(Tenant::path("nfse/$id-" . self::ARQUIVOS[$tipo]));
    }

    /** @return list<array<string,mixed>> */
    public static function list(int $limit = 50, ?string $status = null): array
    {
        $files = array_filter(JsonStore::files(Tenant::path('nfse/DPS*.json')), fn($f) => preg_match('/^DPS\d{42}\.json$/', basename($f)) === 1);
        $out = [];
        foreach ($files as $f) {
            $r = JsonStore::read($f);
            if (!is_array($r) || ($status !== null && ($r['status'] ?? '') !== $status)) {
                continue;
            }
            unset($r['historico']);
            $out[] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}
