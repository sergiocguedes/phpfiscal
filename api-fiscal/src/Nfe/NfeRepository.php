<?php

declare(strict_types=1);

namespace App\Nfe;

use App\Http\ApiException;
use App\Storage\JsonStore;
use App\Support\Validate;
use App\Tenancy\Tenant;

/**
 * Tudo dentro do diretório do tenant autenticado (storage/tenants/<id>/):
 *   nfe/<chave>.json  – metadados e histórico
 *   nfe/<chave>.xml   – XML assinado ou nfeProc (autorizado)
 *   nfe/<chave>-<evento>.xml – retornos de eventos
 * Uma chave de outro tenant simplesmente não existe aqui (404).
 */
final class NfeRepository
{
    /** Unicidade fiscal independente de cNF/chave; compartilhada com o emissor legado. */
    public static function claimNumero(array $rec): void
    {
        $key = implode(':', [$rec['ambiente'], $rec['modelo'], $rec['serie'], $rec['numero']]);
        $file = Tenant::path('numeros/' . hash('sha256', $key) . '.json');
        JsonStore::withLock('numero-' . sha1($file), function () use ($file, $rec) {
            $claim = JsonStore::read($file);
            if ($claim !== null && $claim['chave'] !== $rec['chave']) {
                throw new ApiException(409, 'numero_fiscal_utilizado', 'Número e série já atribuídos a outro documento neste ambiente');
            }
            if ($claim === null) {
                foreach (JsonStore::files(Tenant::path('nfe/*.json')) as $path) {
                    $r = JsonStore::read($path);
                    if (is_array($r) && $r['chave'] !== $rec['chave']
                        && (int) ($r['ambiente'] ?? 2) === $rec['ambiente']
                        && (int) ($r['modelo'] ?? 55) === $rec['modelo']
                        && (int) $r['serie'] === $rec['serie'] && (int) $r['numero'] === $rec['numero']) {
                        throw new ApiException(409, 'numero_fiscal_utilizado', 'Número fiscal já consta no armazenamento');
                    }
                }
                JsonStore::write($file, ['chave' => $rec['chave']]);
            }
        });
    }

    public static function assertChave(string $chave): void
    {
        if (!Validate::chave($chave)) {
            throw new ApiException(400, 'chave_invalida', 'Chave de acesso inválida (44 dígitos com DV)');
        }
    }

    /** @return array<string,mixed>|null */
    public static function find(string $chave): ?array
    {
        self::assertChave($chave);
        return JsonStore::read(Tenant::path("nfe/$chave.json"));
    }

    /** @return array<string,mixed> */
    public static function get(string $chave): array
    {
        $r = self::find($chave);
        if ($r === null) {
            throw new ApiException(404, 'nao_encontrada', 'NF-e não encontrada');
        }
        return $r;
    }

    /** @param array<string,mixed> $rec */
    public static function save(array $rec): void
    {
        self::assertChave($rec['chave']);
        $rec['atualizado_em'] = date('c');
        JsonStore::write(Tenant::path('nfe/' . $rec['chave'] . '.json'), $rec);
    }

    public static function saveXml(string $chave, string $xml, string $suffix = ''): void
    {
        self::assertChave($chave);
        if ($suffix !== '' && !preg_match('/^[a-z0-9-]{1,40}$/', $suffix)) {
            throw new \InvalidArgumentException('sufixo inválido');
        }
        JsonStore::writeRaw(Tenant::path('nfe/' . $chave . ($suffix !== '' ? "-$suffix" : '') . '.xml'), $xml);
    }

    public static function xml(string $chave): ?string
    {
        self::assertChave($chave);
        return JsonStore::readRaw(Tenant::path("nfe/$chave.xml"));
    }

    /** XML de retorno de evento gravado (ex.: "ret-cancelamento"), ou null. */
    public static function xmlEvento(string $chave, string $suffix): ?string
    {
        self::assertChave($chave);
        if (!preg_match('/^[a-z0-9-]{1,40}$/', $suffix)) {
            throw new \InvalidArgumentException('sufixo inválido');
        }
        return JsonStore::readRaw(Tenant::path("nfe/$chave-$suffix.xml"));
    }

    /**
     * Acrescenta uma entrada ao histórico da nota de forma atômica (lock), sem sobrescrever
     * alterações concorrentes (ex.: envio de e-mail pelo worker enquanto a nota é cancelada).
     * @param array{status:string, mensagem:?string} $entry
     */
    public static function appendHistorico(string $chave, array $entry): void
    {
        self::assertChave($chave);
        JsonStore::update(Tenant::path("nfe/$chave.json"), function (?array $r) use ($entry) {
            if ($r === null) {
                return [null, null];
            }
            $r['historico'][] = ['em' => date('c'), 'status' => $entry['status'], 'cStat' => null, 'mensagem' => $entry['mensagem']];
            $r['atualizado_em'] = date('c');
            return [$r, null];
        });
    }

    /** @return list<array<string,mixed>> */
    public static function list(int $limit = 50, ?string $status = null, ?int $modelo = null): array
    {
        $files = array_filter(JsonStore::files(Tenant::path('nfe/*.json')), fn($f) => preg_match('/^\d{44}\.json$/', basename($f)) === 1);
        $out = [];
        foreach ($files as $f) {
            $r = JsonStore::read($f);
            if (!is_array($r) || ($status !== null && ($r['status'] ?? '') !== $status)) {
                continue;
            }
            $r['modelo'] = (int) ($r['modelo'] ?? 55);
            if ($modelo !== null && $r['modelo'] !== $modelo) {
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
