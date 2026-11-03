<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\ApiKeyStore;
use App\Auth\Principal;
use App\Auth\SessionManager;
use App\Http\ApiException;
use App\Http\Request;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\RequestContext;

/**
 * Autenticação das rotas protegidas:
 *  1. Authorization: Bearer <chave de API do tenant>  (integrações; sem CSRF);
 *  2. senão, cookie de sessão HttpOnly/SameSite=Strict (interface web). Em
 *     métodos que alteram estado exige X-CSRF-Token = token da sessão e
 *     origem same-origin (Origin/Sec-Fetch-Site).
 * O tenant da requisição vem exclusivamente do principal autenticado.
 */
final class Auth
{
    public static function ensureNotBlocked(Request $req): void
    {
        $wait = RateLimiter::blockedFor($req->ip);
        if ($wait > 0) {
            Logger::audit('auth.bloqueado', 'negado', ['path' => $req->path]);
            throw new ApiException(429, 'muitas_tentativas', 'Muitas falhas de autenticação. Tente mais tarde.', [], ['Retry-After' => (string) $wait]);
        }
    }

    public static function authenticate(Request $req): Principal
    {
        if ($req->header('authorization') !== null) {
            return self::requireBearer($req);
        }
        [$p, $reason] = SessionManager::load($req);
        if ($p === null) {
            if ($reason !== 'sem_sessao') {
                Logger::audit('auth.sessao', $reason, ['path' => $req->path]);
                throw new ApiException(401, $reason === 'sessao_expirada' ? 'sessao_expirada' : 'nao_autorizado',
                    $reason === 'sessao_expirada' ? 'Sua sessão expirou. Entre novamente.' : 'Credencial ausente ou inválida',
                    [], ['WWW-Authenticate' => 'Bearer realm="sistema-nfephp"', 'Set-Cookie' => SessionManager::clearCookie()]);
            }
            throw new ApiException(401, 'nao_autorizado', 'Credencial ausente ou inválida', [], ['WWW-Authenticate' => 'Bearer realm="sistema-nfephp"']);
        }
        if ($req->isMutating()) {
            self::checkSameOrigin($req);
            $sent = (string) ($req->header('x-csrf-token') ?? '');
            if ($sent === '' || !hash_equals((string) $p->session['csrf'], $sent)) {
                RequestContext::authenticate($p);
                Logger::audit('auth.csrf', 'negado', ['path' => $req->path]);
                throw new ApiException(403, 'csrf_invalido', 'Token CSRF ausente ou inválido. Recarregue a página.');
            }
        }
        RequestContext::authenticate($p);
        return $p;
    }

    public static function requireBearer(Request $req): Principal
    {
        self::ensureNotBlocked($req);
        $header = $req->header('authorization') ?? '';
        $token = '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            $token = $m[1];
        }
        $p = $token !== '' ? (str_starts_with($token,'adm_') ? \App\Auth\CentralAccess::authenticate($token) : ApiKeyStore::authenticate($token)) : null;
        if ($p === null) {
            $blocked = RateLimiter::fail($req->ip);
            Logger::audit('auth.falha', 'negado', ['path' => $req->path, 'motivo' => $token === '' ? 'sem_token' : 'token_invalido']);
            if ($blocked > 0) {
                throw new ApiException(429, 'muitas_tentativas', 'Muitas falhas de autenticação. Tente mais tarde.', [], ['Retry-After' => (string) $blocked]);
            }
            throw new ApiException(401, 'nao_autorizado', 'Credencial ausente ou inválida', [], ['WWW-Authenticate' => 'Bearer realm="sistema-nfephp"']);
        }
        RequestContext::authenticate($p);
        return $p;
    }

    /**
     * Defesa em profundidade contra CSRF/login-CSRF: se o navegador informar
     * a origem, ela precisa ser a própria aplicação.
     */
    public static function checkSameOrigin(Request $req): void
    {
        $site = $req->header('sec-fetch-site');
        if ($site !== null && !in_array(strtolower($site), ['same-origin', 'none'], true)) {
            throw new ApiException(403, 'origem_invalida', 'Requisição de origem não permitida');
        }
        $origin = $req->header('origin');
        if ($origin === null) {
            return;
        }
        $allowed = [strtolower(Mailer::appUrl())];
        $host = $req->header('host');
        if ($host !== null) {
            $allowed[] = 'http://' . strtolower($host);
            $allowed[] = 'https://' . strtolower($host);
        }
        if (!in_array(strtolower(rtrim($origin, '/')), $allowed, true)) {
            throw new ApiException(403, 'origem_invalida', 'Requisição de origem não permitida');
        }
    }
}
