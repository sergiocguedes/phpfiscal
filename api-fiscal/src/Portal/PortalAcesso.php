<?php

declare(strict_types=1);

namespace App\Portal;

use App\Auth\Principal;
use App\Auth\UserStore;
use App\Http\ApiException;
use App\Http\Request;
use App\Security\RateLimiter;
use App\Storage\JsonStore;
use App\Support\Env;
use App\Support\Errors;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\RequestContext;
use App\Support\Validate;
use App\Tenancy\TenantStore;

/**
 * Acesso ao Portal do Contador pelo atalho dos sistemas da LSI, sem senha.
 *
 * 1. O sistema (com a chave de API da empresa) pede um link: POST /api/v1/portal/acessos.
 *    O link é de USO ÚNICO e vale PORTAL_LINK_SECONDS (padrão 300 s). O token (256 bits) só existe na resposta;
 *    fica gravado como hash. Vai no fragmento da URL (#t=), que não chega a logs de servidor.
 * 2. O portal troca o token por uma sessão: POST /api/v1/portal/sessao → cookie HttpOnly/SameSite=Strict.
 *    Sessão com expiração por inatividade (PORTAL_IDLE_SECONDS, 3600) e absoluta (PORTAL_ABSOLUTE_SECONDS, 28800).
 * 3. A sessão do portal só é aceita nas rotas marcadas com 'portal' => true (tributação, cálculo, contexto).
 *    Emissão, cancelamento, CC-e, inutilização, certificado e configuração: 403.
 *
 * Perfis: fiscal (cliente ou contador: consulta e mantém as regras tributárias) e consulta (só leitura).
 */
final class PortalAcesso
{
    public const PERFIS = ['fiscal', 'consulta'];
    public const TIPOS_USUARIO = ['cliente', 'contador'];
    public const ABAS = ['inicio', 'perfis', 'operacoes', 'regras-icms', 'regras-piscofins', 'regras-iss', 'simulador'];

    public static function cookieName(): string
    {
        return Env::bool('SESSION_COOKIE_SECURE', true) ? '__Host-portal_sessao' : 'portal_sessao';
    }

    public static function url(): string
    {
        return rtrim((string) (Env::get('PORTAL_URL') ?? (rtrim(Mailer::appUrl(), '/') . '/portal')), '/');
    }

    /**
     * @param array<mixed> $in
     * @return array{id:string, url:string, expira_em:string}
     */
    public static function criar(Principal $p, array $in): array
    {
        $e = new Errors();
        $perfil = $in['perfil'] ?? 'fiscal';
        in_array($perfil, self::PERFIS, true) ?: $e->add('perfil', 'fiscal ou consulta');
        $tipo = $in['tipo_usuario'] ?? 'cliente';
        in_array($tipo, self::TIPOS_USUARIO, true) ?: $e->add('tipo_usuario', 'cliente ou contador');
        $u = is_array($in['usuario'] ?? null) ? $in['usuario'] : [];
        $usuario = [
            'id' => Validate::text($u['id'] ?? null, 1, 60) ?? ($e->add('usuario.id', 'Identificador do usuário no sistema (1 a 60 caracteres)') ?? ''),
            'nome' => Validate::text($u['nome'] ?? null, 1, 80) ?? ($e->add('usuario.nome', '1 a 80 caracteres') ?? ''),
            'email' => null,
        ];
        if (isset($u['email'])) {
            is_string($u['email']) && filter_var($u['email'], FILTER_VALIDATE_EMAIL) && strlen($u['email']) <= 120
                ? $usuario['email'] = strtolower($u['email']) : $e->add('usuario.email', 'E-mail inválido');
        }
        $abrir = is_array($in['abrir'] ?? null) ? $in['abrir'] : [];
        $aba = $abrir['aba'] ?? 'inicio';
        in_array($aba, self::ABAS, true) ?: $e->add('abrir.aba', 'Uma de: ' . implode(', ', self::ABAS));
        $sistema = isset($in['sistema']) ? Validate::text($in['sistema'], 1, 60) : null;
        if (isset($in['sistema']) && $sistema === null) {
            $e->add('sistema', '1 a 60 caracteres');
        }
        $e->throwIfAny();
        $tenant = $p->tenantId ?? throw new ApiException(422, 'empresa_obrigatoria', 'Informe a empresa');
        if ((TenantStore::get($tenant)['ativa'] ?? true) === false) {
            throw new ApiException(403, 'empresa_inativa', 'Empresa inativa');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $validade = max(30, min(900, Env::int('PORTAL_LINK_SECONDS', 300)));
        $acesso = [
            'id' => 'acs_' . substr($hash, 0, 16),
            'tenant_id' => $tenant, 'perfil' => $perfil, 'tipo_usuario' => $tipo, 'usuario' => $usuario,
            'aba' => $aba, 'sistema' => $sistema, 'criado_por' => $p->actor(), 'criado_em' => time(),
            'expira_em' => time() + $validade, 'usado_em' => null,
        ];
        JsonStore::write("portal/acessos/$hash.json", $acesso);
        Logger::audit('portal.acesso', 'criado', ['id' => $acesso['id'], 'perfil' => $perfil, 'usuario' => $usuario['id'], 'sistema' => $sistema]);
        if (random_int(1, 50) === 1) {
            self::gc();
        }
        return ['id' => $acesso['id'], 'url' => self::url() . '/acesso#t=' . $token, 'expira_em' => date('c', $acesso['expira_em'])];
    }

    /**
     * Troca o token do link pela sessão do portal (uso único).
     * @return array{0: string, 1: array<string,mixed>} [cookie, contexto]
     */
    public static function trocar(Request $req, mixed $token): array
    {
        $espera = RateLimiter::hit('portal-sessao', $req->ip, max(5, Env::int('PORTAL_SESSAO_IP_MAX', 30)), 900);
        if ($espera > 0) {
            throw new ApiException(429, 'muitas_tentativas', 'Muitas tentativas de acesso. Tente mais tarde.', [], ['Retry-After' => (string) $espera]);
        }
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            throw new ApiException(401, 'link_invalido', 'Link de acesso inválido. Abra novamente pelo atalho do seu sistema.');
        }
        $hash = hash('sha256', $token);
        $acesso = JsonStore::update("portal/acessos/$hash.json", function (?array $a) {
            if ($a === null) {
                return [null, ['erro' => 'link_invalido']];
            }
            if ($a['usado_em'] !== null) {
                return [null, ['erro' => 'link_usado', 'a' => $a]];
            }
            if (time() > (int) $a['expira_em']) {
                return [null, ['erro' => 'link_expirado', 'a' => $a]];
            }
            $a['usado_em'] = time();
            return [$a, ['a' => $a]];
        });
        if (isset($acesso['erro'])) {
            Logger::audit('portal.sessao', $acesso['erro'], ['id' => $acesso['a']['id'] ?? null]);
            throw new ApiException(401, $acesso['erro'], match ($acesso['erro']) {
                'link_usado' => 'Este link já foi usado. Abra o painel novamente pelo atalho do seu sistema.',
                'link_expirado' => 'Este link expirou. Abra o painel novamente pelo atalho do seu sistema.',
                default => 'Link de acesso inválido. Abra novamente pelo atalho do seu sistema.',
            });
        }
        $a = $acesso['a'];
        $sid = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $s = [
            'acesso_id' => $a['id'], 'tenant_id' => $a['tenant_id'], 'perfil' => $a['perfil'], 'tipo_usuario' => $a['tipo_usuario'],
            'usuario' => $a['usuario'], 'sistema' => $a['sistema'], 'aba' => $a['aba'], 'csrf' => bin2hex(random_bytes(32)),
            'criado_em' => time(), 'ultimo_acesso' => time(), 'ip' => $req->ip,
        ];
        JsonStore::write('portal/sessoes/' . hash('sha256', $sid) . '.json', $s);
        RequestContext::$actor = 'portal:' . $a['usuario']['id'];
        Logger::audit('portal.sessao', 'iniciada', ['acesso' => $a['id'], 'perfil' => $a['perfil'], 'tipo' => $a['tipo_usuario']]);
        $cookie = self::cookieName() . '=' . $sid . '; Path=/; HttpOnly; SameSite=Strict' . (Env::bool('SESSION_COOKIE_SECURE', true) ? '; Secure' : '');
        return [$cookie, self::contexto($s)];
    }

    /**
     * Sessão do portal no cookie, ou null. Expirada/inválida: null (o portal pede novo acesso).
     */
    public static function principal(Request $req): ?Principal
    {
        $sid = $req->cookie(self::cookieName());
        if ($sid === null || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $sid)) {
            return null;
        }
        $hash = hash('sha256', $sid);
        $s = JsonStore::read("portal/sessoes/$hash.json");
        if ($s === null) {
            return null;
        }
        $now = time();
        if ($now - (int) $s['ultimo_acesso'] > max(300, Env::int('PORTAL_IDLE_SECONDS', 3600))
            || $now - (int) $s['criado_em'] > max(600, Env::int('PORTAL_ABSOLUTE_SECONDS', 28800))
            || (TenantStore::get((string) $s['tenant_id'])['ativa'] ?? false) === false) {
            JsonStore::delete("portal/sessoes/$hash.json");
            return null;
        }
        if ($now - (int) $s['ultimo_acesso'] >= 30) {
            $s['ultimo_acesso'] = $now;
            JsonStore::write("portal/sessoes/$hash.json", $s);
        }
        $papel = $s['perfil'] === 'fiscal' ? UserStore::ROLE_OWNER : 'leitura';
        return new Principal((string) $s['tenant_id'], Principal::VIA_PORTAL, $papel, (string) $s['usuario']['id'], null, $hash, $s);
    }

    public static function sair(Principal $p): void
    {
        if ($p->via === Principal::VIA_PORTAL && $p->sessionHash !== null) {
            JsonStore::delete('portal/sessoes/' . $p->sessionHash . '.json');
        }
    }

    public static function clearCookie(): string
    {
        return self::cookieName() . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Strict' . (Env::bool('SESSION_COOKIE_SECURE', true) ? '; Secure' : '');
    }

    /** @param array<string,mixed> $s @return array<string,mixed> */
    public static function contexto(array $s): array
    {
        $t = TenantStore::get((string) $s['tenant_id']) ?? [];
        return [
            'empresa' => ['id' => $s['tenant_id'], 'nome' => $t['nome'] ?? '', 'cnpj' => $t['cnpj'] ?? null],
            'usuario' => $s['usuario'], 'perfil' => $s['perfil'], 'tipo_usuario' => $s['tipo_usuario'],
            'pode_alterar' => $s['perfil'] === 'fiscal', 'aba' => $s['aba'], 'sistema' => $s['sistema'], 'csrf_token' => $s['csrf'],
        ];
    }

    /** Remove links vencidos (mais de 1 dia) e sessões expiradas. */
    public static function gc(): void
    {
        $now = time();
        foreach (JsonStore::files('portal/acessos/*.json') as $f) {
            $a = JsonStore::read($f);
            if (!is_array($a) || $now - (int) ($a['expira_em'] ?? 0) > 86400) {
                JsonStore::delete($f);
            }
        }
        foreach (JsonStore::files('portal/sessoes/*.json') as $f) {
            $s = JsonStore::read($f);
            if (!is_array($s) || $now - (int) ($s['criado_em'] ?? 0) > max(600, Env::int('PORTAL_ABSOLUTE_SECONDS', 28800))) {
                JsonStore::delete($f);
            }
        }
    }
}
