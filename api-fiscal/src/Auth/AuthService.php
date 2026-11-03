<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use App\Security\RateLimiter;
use App\Storage\JsonStore;
use App\Support\Env;
use App\Support\Errors;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\RequestContext;
use App\Support\Validate;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStore;

/**
 * Cadastro, login, logout, recuperação/redefinição e troca de senha.
 * Rate limit e bloqueio de conta:
 *  - login: LOGIN_IP_MAX falhas por IP em 15 min (429) e bloqueio da conta (por e-mail,
 *    exista ou não — não revela cadastro) após LOGIN_MAX_FAILURES falhas em LOGIN_LOCK_SECONDS;
 *  - cadastro: SIGNUP_IP_MAX por IP por hora;
 *  - esqueci-senha: 5 por IP em 15 min (429) e 3 por e-mail por hora (silencioso).
 */
final class AuthService
{
    public const RESET_TTL = 1800;     // 30 minutos
    public const INVITE_TTL = 172800;  // 48 horas (convite de membro)

    public static function lockMax(): int
    {
        return max(1, Env::int('LOGIN_MAX_FAILURES', 5));
    }

    public static function lockWindow(): int
    {
        return max(60, Env::int('LOGIN_LOCK_SECONDS', 900));
    }

    /**
     * Login por senha. Sem MFA: [token, sessão, usuário, tenant]. Com MFA ativo: [null, {mfa_token, expira_em},
     * usuário, tenant] — a sessão só é criada em MfaService::verificarLogin.
     * @param array<string,mixed> $in
     * @return array{0:?string, 1:array<string,mixed>, 2:array<string,mixed>, 3:?array<string,mixed>}
     */
    public static function login(array $in, string $ip): array
    {
        $email = UserStore::normalizeEmail($in['email'] ?? null);
        $senha = $in['senha'] ?? null;
        if ($email === null || !is_string($senha) || $senha === '' || strlen($senha) > 1024) {
            throw ApiException::validation([['campo' => 'email', 'mensagem' => 'Informe e-mail e senha']]);
        }
        $ipMax = max(1, Env::int('LOGIN_IP_MAX', 20));
        if (RateLimiter::count('login-ip', $ip, 900) >= $ipMax) {
            Logger::audit('auth.login', 'limite_ip');
            throw self::tooMany(RateLimiter::retryAfter('login-ip', $ip, 900));
        }
        if (RateLimiter::count('login-email', $email, self::lockWindow()) >= self::lockMax()) {
            Logger::audit('auth.login', 'conta_bloqueada', ['email_hash' => substr(hash('sha256', $email), 0, 12)]);
            $wait = RateLimiter::retryAfter('login-email', $email, self::lockWindow());
            throw new ApiException(429, 'conta_bloqueada', 'Muitas tentativas de login para esta conta. Tente novamente em ' . max(1, (int) ceil($wait / 60)) . ' min ou redefina a senha.', [], ['Retry-After' => (string) $wait]);
        }

        $user = UserStore::findByEmail($email);
        // mesmo custo de verificação exista ou não o usuário (sem enumeração por tempo)
        $ok = Password::verify($senha, $user['senha_hash'] ?? Password::dummyHash());
        if ($user === null || !$ok || !($user['senha_definida'] ?? true) || ($user['ativo']??true)===false
            || (Env::bool('CENTRAL_AUTH',true) && !in_array($user['acesso_central']??null,['admin','operador'],true))) {
            RateLimiter::hit('login-ip', $ip, PHP_INT_MAX, 900);
            RateLimiter::hit('login-email', $email, PHP_INT_MAX, self::lockWindow());
            if ($user !== null) {
                Tenant::set($user['tenant_id']);
            }
            Logger::audit('auth.login', 'falha', ['user_id' => $user['id'] ?? null, 'email_hash' => substr(hash('sha256', $email), 0, 12)]);
            throw new ApiException(401, 'credenciais_invalidas', 'E-mail ou senha inválidos');
        }
        Tenant::set($user['tenant_id']);
        RequestContext::$actor = 'user:' . $user['id'];
        $mfa = !empty($user['mfa']['ativo']);
        if (!$mfa) {
            
            RateLimiter::clear('login-email', $email);
        }
        $user = UserStore::update($user['id'], function ($u) use ($senha, $mfa) {
            if (Password::needsRehash($u['senha_hash'])) {
                $u['senha_hash'] = Password::hash($senha);
            }
            if (!$mfa) {
                $u['ultimo_login_em'] = date('c');
            }
            return $u;
        });
        
        $tenant = TenantStore::get((string) $user['tenant_id']);
        if ($tenant === null) {
            throw new ApiException(401, 'credenciais_invalidas', 'E-mail ou senha inválidos');
        }
        if ($mfa) {
            // senha correta, mas a sessão só nasce depois do código TOTP/recuperação (POST /auth/mfa/verificar)
            [$pt, $exp] = MfaService::criarPendente($user, $ip);
            Logger::audit('auth.login', 'mfa_pendente', ['user_id' => $user['id']]);
            return [null, ['mfa_token' => $pt, 'expira_em' => $exp], $user, $tenant];
        }
        [$token, $s] = SessionManager::create($user, $ip);
        Logger::audit('auth.login', 'sucesso', ['user_id' => $user['id']]);
        return [$token, $s, $user, $tenant];
    }

    /** Sempre responde igual (202) exista ou não o e-mail. */
    public static function forgot(array $in, string $ip): void
    {
        $wait = RateLimiter::hit('forgot-ip', $ip, max(1, Env::int('FORGOT_IP_MAX', 5)), 900);
        if ($wait > 0) {
            Logger::audit('auth.esqueci_senha', 'limite');
            throw self::tooMany($wait);
        }
        $email = UserStore::normalizeEmail($in['email'] ?? null);
        if ($email === null) {
            throw ApiException::validation([['campo' => 'email', 'mensagem' => 'E-mail inválido']]);
        }
        if (RateLimiter::hit('forgot-email', $email, 3, 3600) > 0) {
            Logger::audit('auth.esqueci_senha', 'limite_email', ['email_hash' => substr(hash('sha256', $email), 0, 12)]);
            return;
        }
        $user = UserStore::findByEmail($email);
        if ($user === null) {
            Logger::audit('auth.esqueci_senha', 'solicitado', ['email_hash' => substr(hash('sha256', $email), 0, 12)]);
            return;
        }
        Tenant::set($user['tenant_id']);
        $token = self::issueResetToken($user, self::RESET_TTL);
        $link = Mailer::appUrl() . '/redefinir-senha#token=' . $token;
        Mailer::send($user['email'], 'Redefinição de senha — Sistema NF-e', implode("\n", [
            'Olá, ' . $user['nome'] . '.',
            '',
            'Recebemos um pedido para redefinir a sua senha. Para criar uma nova senha, acesse:',
            $link,
            '',
            'O link vale por 30 minutos e só pode ser usado uma vez.',
            'Se você não fez este pedido, ignore este e-mail: sua senha atual continua valendo.',
        ]));
        Logger::audit('auth.esqueci_senha', 'solicitado', ['user_id' => $user['id']]);
    }

    
    public static function issueResetToken(array $user, int $ttl, array $extra = []): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        UserStore::update($user['id'], function ($u) use ($hash) {
            if (!empty($u['reset_hash'])) {
                JsonStore::delete('resets/' . $u['reset_hash'] . '.json');
            }
            $u['reset_hash'] = $hash;
            return $u;
        });
        JsonStore::write("resets/$hash.json", ['user_id' => $user['id'], 'criado_em' => time(), 'expira_em' => time() + $ttl] + $extra);
        return $token;
    }

    /** @param array<string,mixed> $in */
    public static function reset(array $in, string $ip): void
    {
        $wait = RateLimiter::hit('reset-ip', $ip, 10, 900);
        if ($wait > 0) {
            throw self::tooMany($wait);
        }
        $token = $in['token'] ?? null;
        $invalid = fn() => new ApiException(400, 'token_invalido', 'Link de redefinição inválido ou expirado. Solicite um novo.');
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            Logger::audit('auth.redefinir_senha', 'token_invalido');
            throw $invalid();
        }
        $hash = hash('sha256', $token);
        $senha = $in['senha'] ?? null;
        JsonStore::withLock("reset-$hash", function () use ($hash, $senha, $invalid) {
            $r = JsonStore::read("resets/$hash.json");
            if ($r === null) {
                Logger::audit('auth.redefinir_senha', 'token_invalido');
                throw $invalid();
            }
            if ((int) $r['expira_em'] < time()) {
                JsonStore::delete("resets/$hash.json");
                Logger::audit('auth.redefinir_senha', 'token_expirado', ['user_id' => $r['user_id']]);
                throw $invalid();
            }
            $user = UserStore::get((string) $r['user_id']);
            if ($user === null || !hash_equals((string) ($user['reset_hash'] ?? ''), $hash)) {
                JsonStore::delete("resets/$hash.json");
                Logger::audit('auth.redefinir_senha', 'token_invalido');
                throw $invalid();
            }
            Tenant::set($user['tenant_id']);
            RequestContext::$actor = 'user:' . $user['id'];
            $p = Password::problem($senha, [$user['email'], $user['nome']]);
            if ($p !== null) {
                // token NÃO é consumido: o usuário pode tentar outra senha
                throw ApiException::validation([['campo' => 'senha', 'mensagem' => $p]]);
            }
            JsonStore::delete("resets/$hash.json"); // uso único
            UserStore::update($user['id'], function ($u) use ($senha) {
                $u['senha_hash'] = Password::hash($senha);
                $u['senha_definida'] = true;
                $u['reset_hash'] = null;
                $u['sessao_versao'] = (int) $u['sessao_versao'] + 1; // derruba sessões abertas
                return $u;
            });
            RateLimiter::clear('login-email', $user['email']);
            Logger::audit('auth.redefinir_senha', 'sucesso', ['user_id' => $user['id']]);
            
        });
    }

    /**
     * Troca de senha autenticada. Derruba as outras sessões e devolve uma nova sessão.
     * @param array<string,mixed> $in
     * @return array{0:string, 1:array<string,mixed>}
     */
    public static function changePassword(Principal $p, array $in, string $ip): array
    {
        $user = UserStore::get((string) $p->userId);
        if ($user === null) {
            throw new ApiException(401, 'nao_autorizado', 'Sessão inválida');
        }
        if (RateLimiter::count('login-email', $user['email'], self::lockWindow()) >= self::lockMax()) {
            throw self::tooMany(RateLimiter::retryAfter('login-email', $user['email'], self::lockWindow()));
        }
        $atual = $in['senha_atual'] ?? null;
        if (!is_string($atual) || !Password::verify($atual, $user['senha_hash'])) {
            // conta como falha de login (alimenta o bloqueio da conta)
            RateLimiter::hit('login-email', $user['email'], PHP_INT_MAX, self::lockWindow());
            Logger::audit('auth.alterar_senha', 'senha_atual_incorreta');
            throw ApiException::validation([['campo' => 'senha_atual', 'mensagem' => 'Senha atual incorreta']]);
        }
        $prob = Password::problem($in['nova_senha'] ?? null, [$user['email'], $user['nome']]);
        if ($prob !== null) {
            throw ApiException::validation([['campo' => 'nova_senha', 'mensagem' => $prob]]);
        }
        $user = UserStore::update($user['id'], function ($u) use ($in) {
            $u['senha_hash'] = Password::hash($in['nova_senha']);
            $u['sessao_versao'] = (int) $u['sessao_versao'] + 1;
            return $u;
        });
        if ($p->sessionHash !== null) {
            SessionManager::destroy($p->sessionHash);
        }
        Logger::audit('auth.alterar_senha', 'sucesso', ['user_id' => $user['id']]);
        return SessionManager::create($user, $ip);
    }

    /**
     * Convite de membro (só proprietário): cria o usuário sem senha e envia link para defini-la.
     * @return array<string,mixed>
     */
    public static function invite(Principal $p, array $in): array
    {
        $wait = RateLimiter::hit('invite-tenant', $p->tenantId, 20, 3600);
        if ($wait > 0) {
            throw self::tooMany($wait);
        }
        $e = new Errors();
        $nome = Validate::text($in['nome'] ?? null, 2, 80);
        $nome ?? $e->add('nome', '2 a 80 caracteres');
        $email = UserStore::normalizeEmail($in['email'] ?? null);
        $email ?? $e->add('email', 'E-mail inválido');
        $papel = $in['papel'] ?? UserStore::ROLE_MEMBER;
        in_array($papel, UserStore::ROLES, true) ?: $e->add('papel', 'Papel inválido: use owner, fiscal ou member');
        $e->throwIfAny();
        if (count(UserStore::listTenant($p->tenantId)) >= 50) {
            throw new ApiException(409, 'limite_usuarios', 'Limite de 50 usuários por empresa');
        }
        $user = UserStore::create($p->tenantId, $nome, $email, null, $papel);
        $token = self::issueResetToken($user, self::INVITE_TTL);
        $tenant = TenantStore::get($p->tenantId);
        Mailer::send($email, 'Convite — Sistema NF-e', implode("\n", [
            'Olá, ' . $nome . '.',
            '',
            'Você foi convidado(a) para acessar a empresa "' . ($tenant['nome'] ?? '') . '" no Sistema NF-e.',
            'Para definir sua senha, acesse (válido por 48 horas, uso único):',
            Mailer::appUrl() . '/redefinir-senha#token=' . $token,
        ]));
        Logger::audit('usuario.convite', 'sucesso', ['user_id' => $user['id'], 'papel' => $papel]);
        return $user;
    }

    private static function tooMany(int $wait): ApiException
    {
        return new ApiException(429, 'muitas_tentativas', 'Muitas tentativas. Tente novamente em ' . max(1, (int) ceil($wait / 60)) . ' min.', [], ['Retry-After' => (string) $wait]);
    }
}
