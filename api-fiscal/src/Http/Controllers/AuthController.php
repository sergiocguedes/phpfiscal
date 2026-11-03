<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\AuthService;

use App\Auth\MfaService;
use App\Auth\Principal;
use App\Auth\SessionManager;
use App\Auth\UserStore;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Logger;
use App\Support\RequestContext;
use App\Support\Validate;
use App\Tenancy\TenantStore;

/** Rotas /api/v1/auth/* */
final class AuthController
{
    
    public static function sessionBody(array $user, array $session): array
    {
        $tid = $session['tenant_id'] ?? null;
        $tenant = is_string($tid) ? TenantStore::get($tid) : null;
        if ($tenant === null) {
            throw new ApiException(401, 'nao_autorizado', 'Credencial ausente ou inválida');
        }
        $body = [
            'via' => Principal::VIA_SESSION,
            'usuario' => UserStore::publicView($user),
            'tenant' => $tenant !== null ? TenantStore::publicView($tenant) : null,
            'csrf_token' => $session['csrf'],
            // MFA_OBRIGATORIO_DONO=true e owner sem MFA: o painel leva direto ao cadastro (o servidor bloqueia o resto)
            'mfa_cadastro_obrigatorio' => MfaService::cadastroObrigatorio($user),
            'sessao' => [
                'inatividade_segundos' => SessionManager::idle(),
                'expira_em' => date('c', (int) $session['criado_em'] + SessionManager::absolute()),
            ],
        ];
        
        return $body;
    }

    public static function login(Request $req): Response
    {
        [$token, $s, $user] = AuthService::login($req->json(), $req->ip);
        if ($token === null) {
            // senha correta + MFA ativo: nenhuma sessão ainda; o navegador envia o código em /auth/mfa/verificar
            return Response::json([
                'mfa_necessario' => true,
                'mfa_token' => $s['mfa_token'],
                'expira_em' => date('c', (int) $s['expira_em']),
                'metodos' => ['totp', 'recuperacao'],
            ]);
        }
        return Response::json(self::sessionBody($user, $s), 200, ['Set-Cookie' => SessionManager::cookie($token)]);
    }

    /** Segunda etapa do login (pública, com rate limit e verificação de origem). */
    public static function mfaVerify(Request $req): Response
    {
        [$token, $s, $user] = MfaService::verificarLogin($req->json(), $req->ip);
        return Response::json(self::sessionBody($user, $s), 200, ['Set-Cookie' => SessionManager::cookie($token)]);
    }

    public static function mfaStatus(): Response
    {
        return Response::json(MfaService::status(RequestContext::$principal));
    }

    public static function mfaStart(): Response
    {
        return Response::json(MfaService::iniciar(RequestContext::$principal), 201);
    }

    public static function mfaConfirm(Request $req): Response
    {
        [$token, $s, $codigos] = MfaService::confirmar(RequestContext::$principal, $req->json(), $req->ip);
        return Response::json([
            'ativo' => true,
            'codigos_recuperacao' => $codigos,
            'aviso' => 'Guarde os códigos de recuperação em local seguro: cada um vale uma única vez e eles não serão exibidos novamente. Outras sessões foram encerradas.',
            'csrf_token' => $s['csrf'],
        ], 200, ['Set-Cookie' => SessionManager::cookie($token)]);
    }

    public static function mfaDisable(Request $req): Response
    {
        [$token, $s] = MfaService::desativar(RequestContext::$principal, $req->json(), $req->ip);
        return Response::json(['ativo' => false, 'mensagem' => 'Verificação em duas etapas desativada. Outras sessões foram encerradas.', 'csrf_token' => $s['csrf']], 200, ['Set-Cookie' => SessionManager::cookie($token)]);
    }

    public static function mfaRecoveryCodes(Request $req): Response
    {
        $codigos = MfaService::regenerarCodigos(RequestContext::$principal, $req->json());
        return Response::json([
            'codigos_recuperacao' => $codigos,
            'aviso' => 'Os códigos anteriores deixaram de valer. Guarde estes em local seguro: não serão exibidos novamente.',
        ]);
    }

    public static function logout(Request $req): Response
    {
        $p = RequestContext::$principal;
        if ($p?->sessionHash !== null) {
            SessionManager::destroy($p->sessionHash);
        }
        Logger::audit('auth.logout', 'sucesso');
        $r = Response::noContent();
        $r->headers['Set-Cookie'] = SessionManager::clearCookie();
        return $r;
    }

    public static function forgot(Request $req): Response
    {
        AuthService::forgot($req->json(), $req->ip);
        return Response::json(['mensagem' => 'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha em instantes.'], 202);
    }

    public static function reset(Request $req): Response
    {
        AuthService::reset($req->json(), $req->ip);
        return Response::json(['mensagem' => 'Senha redefinida. Entre com a nova senha.']);
    }

    public static function me(Request $req): Response
    {
        $p = RequestContext::$principal;
        if ($p->via === Principal::VIA_API_KEY) {
            $tenant = TenantStore::get((string) $p->tenantId);
            if ($tenant === null) {
                throw new ApiException(401, 'nao_autorizado', 'Credencial ausente ou inválida');
            }
            return Response::json(['via' => Principal::VIA_API_KEY, 'chave_id' => $p->apiKeyId, 'tenant' => TenantStore::publicView($tenant)]);
        }
        $user = UserStore::get((string) $p->userId);
        if ($user === null) {
            throw new ApiException(401, 'nao_autorizado', 'Credencial ausente ou inválida');
        }
        // $p->session já reflete a empresa conferida nesta requisição (vínculo removido => null)
        return Response::json(self::sessionBody($user, ['tenant_id' => $p->tenantId] + $p->session));
    }

    public static function profile(Request $req): Response
    {
        $nome = Validate::text($req->json()['nome'] ?? null, 2, 80);
        if ($nome === null) {
            throw ApiException::validation([['campo' => 'nome', 'mensagem' => '2 a 80 caracteres']]);
        }
        $u = UserStore::update((string) RequestContext::$principal->userId, fn($u) => ['nome' => $nome] + $u);
        Logger::audit('usuario.perfil', 'sucesso');
        return Response::json(UserStore::publicView($u));
    }

    public static function changePassword(Request $req): Response
    {
        [$token, $s] = AuthService::changePassword(RequestContext::$principal, $req->json(), $req->ip);
        return Response::json(['mensagem' => 'Senha alterada. Outras sessões foram encerradas.', 'csrf_token' => $s['csrf']], 200, ['Set-Cookie' => SessionManager::cookie($token)]);
    }
}
