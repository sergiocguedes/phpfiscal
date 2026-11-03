<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use App\Storage\JsonStore;
use App\Support\Validate;
use App\Tenancy\Tenant;

/**
 * Chaves de API por tenant (acesso programático, Authorization: Bearer).
 *  - O valor completo só é mostrado uma vez, na criação. No disco fica o SHA-256.
 *  - storage/apikeys/<sha256>.json      índice global hash -> {id, tenant_id}
 *  - storage/tenants/<tid>/apikeys.json registros do tenant (sem o valor)
 *  - Revogar remove o índice: a chave deixa de autenticar na hora.
 */
final class ApiKeyStore
{
    public const ID_REGEX = '/^k_[a-f0-9]{16}$/';
    public const MAX_ACTIVE = 20;

    private static function list(string $tenantId): string
    {
        return Tenant::pathFor($tenantId, 'apikeys.json');
    }

    private static function index(string $hash): string
    {
        return "apikeys/$hash.json";
    }

    /**
     * @return array{0:string, 1:array<string,mixed>} [valor da chave, registro público]
     */
    public static function create(string $tenantId, string $nome, ?string $createdBy, ?string $presetToken = null): array
    {
        $token = $presetToken ?? 'nfk_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $token);
        $rec = [
            'id' => 'k_' . bin2hex(random_bytes(8)),
            'nome' => $nome,
            'prefixo' => $presetToken !== null ? '(.env API_KEY)' : substr($token, 0, 12),
            'hash' => $hash,
            'criado_em' => date('c'),
            'criado_por' => $createdBy,
            'ultimo_uso_em' => null,
            'revogada_em' => null,
        ];
        JsonStore::update(self::list($tenantId), function (?array $d) use ($rec) {
            $d ??= [];
            $active = array_filter($d, fn($k) => $k['revogada_em'] === null);
            if (count($active) >= self::MAX_ACTIVE) {
                throw new ApiException(409, 'limite_chaves', 'Limite de ' . self::MAX_ACTIVE . ' chaves ativas. Revogue uma antes de criar outra.');
            }
            $d[$rec['id']] = $rec;
            return [$d, null];
        });
        JsonStore::write(self::index($hash), ['id' => $rec['id'], 'tenant_id' => $tenantId]);
        return [$token, self::publicView($rec)];
    }

    /** Autentica o Bearer. Devolve Principal ou null. */
    public static function authenticate(string $token): ?Principal
    {
        if (strlen($token) < 32 || strlen($token) > 256) {
            return null;
        }
        $hash = hash('sha256', $token);
        $idx = JsonStore::read(self::index($hash));
        if (!is_array($idx) || !is_string($idx['tenant_id'] ?? null) || !Tenant::valid($idx['tenant_id'])) {
            return null;
        }
        $tid = $idx['tenant_id'];
        $all = JsonStore::read(self::list($tid)) ?? [];
        $rec = $all[$idx['id']] ?? null;
        if (!is_array($rec) || $rec['revogada_em'] !== null || !hash_equals((string) $rec['hash'], $hash)) {
            return null;
        }
        $last = $rec['ultimo_uso_em'] !== null ? strtotime((string) $rec['ultimo_uso_em']) : 0;
        if (time() - $last > 60) {
            JsonStore::update(self::list($tid), function (?array $d) use ($rec) {
                if (!isset($d[$rec['id']])) {
                    return [null, null];
                }
                $d[$rec['id']]['ultimo_uso_em'] = date('c');
                return [$d, null];
            });
        }
        // chaves de API têm acesso de proprietário ao tenant (integrações)
        return new Principal($tid, Principal::VIA_API_KEY, UserStore::ROLE_OWNER, null, $rec['id']);
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $tenantId): array
    {
        $out = array_map([self::class, 'publicView'], array_values(JsonStore::read(self::list($tenantId)) ?? []));
        usort($out, fn($a, $b) => strcmp((string) $b['criado_em'], (string) $a['criado_em']));
        return $out;
    }

    public static function revoke(string $tenantId, string $id): void
    {
        if (preg_match(self::ID_REGEX, $id) !== 1) {
            throw Tenant::notFound('Chave');
        }
        // a busca é feita SOMENTE na lista do tenant autenticado
        $hash = JsonStore::update(self::list($tenantId), function (?array $d) use ($id) {
            $d ??= [];
            if (!isset($d[$id]) || $d[$id]['revogada_em'] !== null) {
                return [null, null];
            }
            $d[$id]['revogada_em'] = date('c');
            return [$d, $d[$id]['hash']];
        });
        if ($hash === null) {
            throw Tenant::notFound('Chave');
        }
        JsonStore::delete(self::index($hash));
    }

    public static function validName(mixed $nome): ?string
    {
        return Validate::text($nome, 2, 60);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function publicView(array $r): array
    {
        unset($r['hash']);
        $r['ativa'] = $r['revogada_em'] === null;
        return $r;
    }
}
