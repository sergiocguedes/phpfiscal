<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use App\Storage\JsonStore;
use App\Tenancy\Tenant;

/**
 * Usuários.
 *  storage/users/<uid>.json            registro (com hash da senha)
 *  storage/users/email-<sha256>.json   índice e-mail -> uid (garante unicidade)
 *  storage/tenants/<tid>/users.json    lista de uids do tenant
 */
final class UserStore
{
    public const ROLE_OWNER = 'owner';
    /** operações fiscais irreversíveis: cancelamento, CC-e e inutilização (além do que o member faz) */
    public const ROLE_FISCAL = 'fiscal';
    public const ROLE_MEMBER = 'member';
    

    /** papéis válidos; registros antigos só têm owner/member e continuam valendo sem migração */
    public const ROLES = [self::ROLE_OWNER, self::ROLE_FISCAL, self::ROLE_MEMBER];
    public const ID_REGEX = '/^u_[a-f0-9]{16}$/';

    public static function normalizeEmail(mixed $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }
        $e = mb_strtolower(trim($email));
        if (strlen($e) > 254 || !filter_var($e, FILTER_VALIDATE_EMAIL) || preg_match('/[\x00-\x1f\x7f<>]/', $e)) {
            return null;
        }
        return $e;
    }

    private static function emailIndex(string $email): string
    {
        return 'users/email-' . hash('sha256', $email) . '.json';
    }

    private static function file(string $uid): string
    {
        if (preg_match(self::ID_REGEX, $uid) !== 1) {
            throw new \InvalidArgumentException('user_id inválido');
        }
        return "users/$uid.json";
    }

    /**
     * Cria o usuário. Lança 409 se o e-mail já existir.
     * @return array<string,mixed>
     */
    public static function create(string $tenantId, string $nome, string $email, ?string $passwordHash, string $role): array
    {
        $uid = 'u_' . bin2hex(random_bytes(8));
        $user = [
            'id' => $uid,
            'tenant_id' => $tenantId,
            'nome' => $nome,
            'email' => $email,
            // sem senha definida (convite): hash de valor aleatório, inutilizável
            'senha_hash' => $passwordHash ?? Password::hash(bin2hex(random_bytes(32))),
            'senha_definida' => $passwordHash !== null,
            'papel' => $role,
            'sessao_versao' => 1,
            'criado_em' => date('c'),
        ];
        $ok = JsonStore::update(self::emailIndex($email), function (?array $idx) use ($uid) {
            if ($idx !== null && isset($idx['user_id']) && JsonStore::read(self::file($idx['user_id'])) !== null) {
                return [null, false];
            }
            return [['user_id' => $uid], true];
        });
        if (!$ok) {
            throw new ApiException(409, 'email_em_uso', 'Não foi possível concluir o cadastro com este e-mail. Se ele já tem conta, faça login ou recupere a senha.');
        }
        JsonStore::write(self::file($uid), $user);
        JsonStore::update(Tenant::pathFor($tenantId, 'users.json'), function (?array $l) use ($uid) {
            $l ??= [];
            $l[] = $uid;
            return [array_values(array_unique($l)), null];
        });
        return $user;
    }

    

    /** @return array<string,mixed>|null */
    public static function get(string $uid): ?array
    {
        if (preg_match(self::ID_REGEX, $uid) !== 1) {
            return null;
        }
        return JsonStore::read(self::file($uid));
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        $idx = JsonStore::read(self::emailIndex($email));
        if (!is_array($idx) || !is_string($idx['user_id'] ?? null)) {
            return null;
        }
        $u = self::get($idx['user_id']);
        return ($u !== null && $u['email'] === $email) ? $u : null;
    }

    /**
     * Atualiza o registro sob lock.
     * @param callable(array<string,mixed>): array<string,mixed> $fn
     * @return array<string,mixed>
     */
    public static function update(string $uid, callable $fn): array
    {
        return JsonStore::update(self::file($uid), function (?array $u) use ($fn) {
            if ($u === null) {
                throw new ApiException(404, 'nao_encontrado', 'Usuário não encontrado');
            }
            $u = $fn($u);
            $u['atualizado_em'] = date('c');
            return [$u, $u];
        });
    }

    /** Usuário pertencente ao tenant (escopo validado no servidor), ou null. */
    public static function getInTenant(string $tenantId, string $uid): ?array
    {
        $u = self::get($uid);
        
        return ($u !== null && is_string($u['tenant_id'] ?? null) && hash_equals($u['tenant_id'], $tenantId)) ? $u : null;
    }

    /** @return list<array<string,mixed>> */
    public static function listTenant(string $tenantId): array
    {
        $out = [];
        foreach (JsonStore::read(Tenant::pathFor($tenantId, 'users.json')) ?? [] as $uid) {
            $u = is_string($uid) ? self::getInTenant($tenantId, $uid) : null;
            if ($u !== null) {
                $out[] = $u;
            }
        }
        return $out;
    }

    public static function delete(string $tenantId, string $uid): void
    {
        $u = self::getInTenant($tenantId, $uid);
        if ($u === null) {
            throw new ApiException(404, 'nao_encontrado', 'Usuário não encontrado');
        }
        JsonStore::update(Tenant::pathFor($tenantId, 'users.json'), function (?array $l) use ($uid) {
            return [array_values(array_filter($l ?? [], fn($x) => $x !== $uid)), null];
        });
        JsonStore::delete(self::emailIndex($u['email']));
        JsonStore::delete(self::file($uid));
    }

    /** @param array<string,mixed> $u @return array<string,mixed> */
    public static function publicView(array $u): array
    {
        return [
            'id' => $u['id'],
            'nome' => $u['nome'],
            'email' => $u['email'],
            'papel' => $u['papel'],
            'acesso_central' => $u['acesso_central'] ?? null,
            'ativo' => (bool) ($u['ativo'] ?? true),
            'senha_definida' => (bool) ($u['senha_definida'] ?? true),
            'mfa_ativo' => !empty($u['mfa']['ativo']),
            'criado_em' => $u['criado_em'],
            'ultimo_login_em' => $u['ultimo_login_em'] ?? null,
        ];
    }
}
