<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Http\ApiException;

/**
 * Tenant da requisição atual. Definido SOMENTE pela autenticação (sessão ou
 * chave de API) — nunca por parâmetro enviado pelo cliente.
 *
 * Todo acesso a dado de tenant passa por Tenant::path(), que:
 *  - falha se não houver tenant definido (nenhum acesso "sem escopo");
 *  - monta tenants/<id>/<arquivo>, com o id validado por regex (sem path traversal).
 */
final class Tenant
{
    public const ID_REGEX = '/^t_[a-f0-9]{16}$/';
    /** Tenant que recebe os dados da instalação single-tenant anterior. */
    public const DEFAULT_ID = 't_0000000000000001';

    private static ?string $current = null;

    public static function valid(string $id): bool
    {
        return preg_match(self::ID_REGEX, $id) === 1;
    }

    public static function set(?string $id): void
    {
        if ($id !== null && !self::valid($id)) {
            throw new \InvalidArgumentException('tenant_id inválido');
        }
        self::$current = $id;
    }

    public static function id(): string
    {
        if (self::$current === null) {
            // erro de programação: acesso a dado de tenant sem escopo definido
            throw new \LogicException('Acesso a dado de tenant sem tenant definido');
        }
        return self::$current;
    }

    public static function current(): ?string
    {
        return self::$current;
    }

    /** Caminho relativo ao storage dentro do diretório do tenant atual. */
    public static function path(string $relative = ''): string
    {
        return self::pathFor(self::id(), $relative);
    }

    public static function pathFor(string $tenantId, string $relative = ''): string
    {
        if (!self::valid($tenantId)) {
            throw new \InvalidArgumentException('tenant_id inválido');
        }
        if (str_contains($relative, '..') || str_contains($relative, "\0") || str_starts_with($relative, '/')) {
            throw new \InvalidArgumentException('Caminho inválido');
        }
        if (preg_match('~^(?:config\.json|nfse-config\.json|inutilizacoes\.json|nfe(?:/|$)|nfse(?:/|$)|pdv(?:/|$)|numeros(?:/|$)|idempotency(?:/|$))~', $relative)) {
            $relative = 'ambientes/' . FiscalScope::ambiente($tenantId) . '/' . $relative;
        }
        return 'tenants/' . $tenantId . ($relative !== '' ? '/' . $relative : '');
    }

    /**
     * Executa $fn no escopo de outro tenant (worker/migração) e restaura o anterior.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(string $tenantId, callable $fn)
    {
        $prev = self::$current;
        self::set($tenantId);
        try {
            return $fn();
        } finally {
            self::$current = $prev;
        }
    }

    public static function notFound(string $what = 'Recurso'): ApiException
    {
        return new ApiException(404, 'nao_encontrado', "$what não encontrado");
    }
}
