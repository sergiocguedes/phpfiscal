<?php

declare(strict_types=1);

namespace App\Http;

use App\Storage\JsonStore;
use App\Support\Paths;
use App\Tenancy\Tenant;

/**
 * Chave de idempotência (cabeçalho Idempotency-Key).
 *  - mesma chave + mesmo corpo  => devolve a resposta gravada (sem reemitir);
 *  - mesma chave + corpo diferente => 422;
 *  - chave ainda em processamento => 409.
 * Registros expiram após 7 dias. Escopo por tenant (storage/tenants/<id>/idempotency).
 */
final class Idempotency
{
    private const TTL = 7 * 86400;

    public static function validKey(?string $key): bool
    {
        return $key !== null && preg_match('/^[A-Za-z0-9_.:\-]{8,128}$/', $key) === 1;
    }

    private static function file(string $scope, string $key): string
    {
        return Tenant::path('idempotency/' . hash('sha256', $scope . '|' . $key) . '.json');
    }

    /**
     * Executa $fn uma única vez por chave.
     * @param callable(): Response $fn
     */
    public static function run(string $scope, string $key, string $body, callable $fn): Response
    {
        $file = self::file($scope, $key);
        $bodyHash = hash('sha256', $body);

        $claim = JsonStore::update($file, function (?array $d) use ($bodyHash) {
            if ($d !== null && ($d['criado_em'] ?? 0) > time() - self::TTL) {
                return [null, $d];
            }
            return [['estado' => 'processando', 'body_hash' => $bodyHash, 'criado_em' => time()], null];
        });

        if ($claim !== null) {
            if (!hash_equals($claim['body_hash'], $bodyHash)) {
                throw new ApiException(422, 'idempotencia_conflito', 'Idempotency-Key já usada com outro conteúdo');
            }
            if ($claim['estado'] === 'processando') {
                throw new ApiException(409, 'idempotencia_em_andamento', 'Requisição com esta Idempotency-Key ainda em processamento');
            }
            $r = new Response((int) $claim['status'], (string) $claim['body'], (array) $claim['headers']);
            $r->headers['Idempotent-Replayed'] = 'true';
            return $r;
        }

        try {
            $resp = $fn();
        } catch (ApiException $e) {
            // erro de validação/negócio também é resposta definitiva para esta chave
            $resp = Response::error($e->status(), $e->errorCode, $e->getMessage(), $e->details, $e->headers);
        } catch (\Throwable $e) {
            JsonStore::delete($file); // falha inesperada: libera a chave para nova tentativa
            throw $e;
        }
        JsonStore::write($file, [
            'estado' => 'concluido',
            'body_hash' => $bodyHash,
            'criado_em' => time(),
            'status' => $resp->status,
            'body' => $resp->body,
            'headers' => array_intersect_key($resp->headers, ['Content-Type' => 1]),
        ]);
        if (random_int(1, 50) === 1) {
            self::gc();
        }
        return $resp;
    }

    private static function gc(): void
    {
        foreach (JsonStore::files(Tenant::path('idempotency/*.json')) as $f) {
            if (JsonStore::mtime($f) < time() - self::TTL) {
                JsonStore::delete($f);
            }
        }
    }
}
