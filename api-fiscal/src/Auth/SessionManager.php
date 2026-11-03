<?php

declare(strict_types=1);

namespace App\Auth;


use App\Http\Request;
use App\Storage\JsonStore;
use App\Support\Env;
use App\Support\Paths;

/**
 * Sessões de usuário em storage/sessions/<sha256(token)>.json.
 *  - O token (32 bytes aleatórios) só existe no cookie; no disco fica o hash.
 *  - Cookie HttpOnly, SameSite=Strict, Path=/, Secure (prefixo __Host-) quando
 *    SESSION_COOKIE_SECURE=true.
 *  - Expira por inatividade (SESSION_IDLE_SECONDS) e por tempo absoluto
 *    (SESSION_ABSOLUTE_SECONDS). Logout apaga o registro no servidor.
 *  - Troca/redefinição de senha incrementa sessao_versao do usuário, o que
 *    invalida todas as sessões anteriores dele.
 *  - Cada sessão tem um token CSRF próprio (enviado no cabeçalho X-CSRF-Token).
 */
final class SessionManager
{
    public static function secure(): bool
    {
        return Env::bool('SESSION_COOKIE_SECURE', true);
    }

    public static function cookieName(): string
    {
        return self::secure() ? '__Host-nfe_sessao' : 'nfe_sessao';
    }

    public static function idle(): int
    {
        return max(60, Env::int('SESSION_IDLE_SECONDS', 1800));
    }

    public static function absolute(): int
    {
        return max(300, Env::int('SESSION_ABSOLUTE_SECONDS', 43200));
    }

    private static function file(string $hash): string
    {
        return "sessions/$hash.json";
    }

    /**
     * Cria a sessão e devolve [token, registro].
     * @param array<string,mixed> $user
     * @return array{0:string, 1:array<string,mixed>}
     */
    public static function create(array $user, string $ip): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = time();
        $s = [
            'user_id' => $user['id'],
            
            'tenant_id' => $user['tenant_id'],
            'versao' => (int) $user['sessao_versao'],
            'csrf' => bin2hex(random_bytes(32)),
            'criado_em' => $now,
            'ultimo_acesso' => $now,
            'ip' => $ip,
        ];
        JsonStore::write(self::file(hash('sha256', $token)), $s);
        if (random_int(1, 50) === 1) {
            self::gc();
        }
        return [$token, $s];
    }

    /**
     * Valida a sessão do cookie. Devolve [Principal|null, motivo].
     * @return array{0: ?Principal, 1: string}
     */
    public static function load(Request $req): array
    {
        $token = $req->cookie(self::cookieName());
        if ($token === null) {
            return [null, 'sem_sessao'];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return [null, 'sessao_invalida'];
        }
        $hash = hash('sha256', $token);
        $s = JsonStore::read(self::file($hash));
        if ($s === null) {
            return [null, 'sessao_invalida'];
        }
        $now = time();
        if ($now - (int) $s['ultimo_acesso'] > self::idle() || $now - (int) $s['criado_em'] > self::absolute()) {
            JsonStore::delete(self::file($hash));
            return [null, 'sessao_expirada'];
        }
        $user = UserStore::get((string) $s['user_id']);
        if ($user === null || ($user['ativo'] ?? true) === false || (\App\Support\Env::bool('CENTRAL_AUTH',true) && !in_array($user['acesso_central']??null,['admin','operador'],true)) || (int) $user['sessao_versao'] !== (int) $s['versao']
            || ($user['tenant_id'] === null || $user['tenant_id'] !== $s['tenant_id'])) {
            JsonStore::delete(self::file($hash));
            return [null, 'sessao_invalida'];
        }
        $dirty = false;
        
        if ($dirty || $now - (int) $s['ultimo_acesso'] >= 30) {
            $s['ultimo_acesso'] = $now;
            JsonStore::write(self::file($hash), $s);
        }
        return [new Principal($s['tenant_id'], Principal::VIA_SESSION, $user['papel'], $user['id'], null, $hash, $s, !empty($user['mfa']['ativo']), $user['acesso_central']??null), 'ok'];
    }

    

    public static function destroy(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash)) {
            JsonStore::delete(self::file($hash));
        }
    }

    public static function cookie(string $token): string
    {
        return self::cookieName() . '=' . $token . '; Path=/; HttpOnly; SameSite=Strict' . (self::secure() ? '; Secure' : '');
    }

    public static function clearCookie(): string
    {
        return self::cookieName() . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Strict' . (self::secure() ? '; Secure' : '');
    }

    /** Remove sessões expiradas. */
    public static function gc(): void
    {
        $now = time();
        foreach (JsonStore::files('sessions/*.json') as $f) {
            $s = JsonStore::read($f);
            if (!is_array($s) || $now - (int) ($s['ultimo_acesso'] ?? 0) > self::idle() || $now - (int) ($s['criado_em'] ?? 0) > self::absolute()) {
                JsonStore::delete($f);
            }
        }
    }
}
