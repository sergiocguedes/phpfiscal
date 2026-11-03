<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use App\Security\Crypto;
use App\Security\RateLimiter;
use App\Storage\JsonStore;
use App\Support\Env;
use App\Support\Logger;
use App\Support\RequestContext;
use App\Tenancy\Tenant;


final class MfaService
{
    public const PENDENTE_TTL = 300;      // 5 minutos entre a senha e o segundo fator
    public const CADASTRO_TTL = 600;      // 10 minutos para confirmar o cadastro
    public const MAX_TENTATIVAS_ETAPA = 5;
    public const QTD_RECUPERACAO = 8;

    public static function obrigatorioParaDono(): bool
    {
        return Env::bool('MFA_OBRIGATORIO_DONO', false);
    }

    /** O usuário precisa cadastrar o MFA antes de usar o painel (MFA_OBRIGATORIO_DONO=true e owner sem MFA). */
    public static function cadastroObrigatorio(array $user): bool
    {
        return self::obrigatorioParaDono() && ($user['papel'] ?? '') === UserStore::ROLE_OWNER && empty($user['mfa']['ativo']);
    }

    // ------------------------------------------------------------------ situação / cadastro

    /** @return array<string,mixed> */
    public static function status(Principal $p): array
    {
        $u = self::user($p);
        $ativo = !empty($u['mfa']['ativo']);
        return [
            'ativo' => $ativo,
            'ativado_em' => $ativo ? ($u['mfa']['ativado_em'] ?? null) : null,
            'codigos_recuperacao_restantes' => $ativo ? count($u['mfa']['recuperacao'] ?? []) : 0,
            'obrigatorio' => self::obrigatorioParaDono() && $u['papel'] === UserStore::ROLE_OWNER,
            'cadastro_pendente' => !$ativo && (int) ($u['mfa_cadastro']['expira_em'] ?? 0) > time(),
        ];
    }

    /**
     * Inicia o cadastro: gera segredo novo (substitui cadastro pendente anterior) e devolve a URI otpauth://
     * e o segredo para digitação manual. Recusado se o MFA já estiver ativo (o segredo ativo nunca é reexibido).
     * @return array<string,mixed>
     */
    public static function iniciar(Principal $p): array
    {
        $u = self::user($p);
        if (!empty($u['mfa']['ativo'])) {
            throw new ApiException(409, 'mfa_ja_ativo', 'A verificação em duas etapas já está ativa. Para trocar de aparelho, desative e cadastre de novo.');
        }
        $wait = RateLimiter::hit('mfa-iniciar', $u['id'], 10, 3600);
        if ($wait > 0) {
            throw self::tooMany($wait);
        }
        $segredo = Totp::gerarSegredo();
        $expira = time() + self::CADASTRO_TTL;
        UserStore::update($u['id'], function ($x) use ($segredo, $expira) {
            $x['mfa_cadastro'] = ['segredo_enc' => Crypto::encrypt($segredo), 'expira_em' => $expira];
            return $x;
        });
        Logger::audit('auth.mfa_cadastro', 'iniciado', ['user_id' => $u['id']]);
        return [
            'otpauth_uri' => Totp::uri($segredo, $u['email']),
            'segredo' => $segredo,
            'segredo_agrupado' => Totp::agrupar($segredo),
            'emissor' => Totp::ISSUER,
            'conta' => $u['email'],
            'algoritmo' => 'SHA1',
            'digitos' => Totp::DIGITOS,
            'periodo' => Totp::PERIODO,
            'expira_em' => date('c', $expira),
            'aviso' => 'Cadastre no aplicativo autenticador e confirme com um código em até 10 minutos. Depois de confirmado, o segredo não é exibido novamente.',
        ];
    }

    /**
     * Confirma o cadastro com um código do aplicativo. Ativa o MFA, gera os códigos de recuperação (exibidos
     * uma única vez), derruba as outras sessões do usuário e devolve uma sessão nova.
     * @param array<string,mixed> $in
     * @return array{0:string, 1:array<string,mixed>, 2:list<string>} [token, sessão, códigos de recuperação]
     */
    public static function confirmar(Principal $p, array $in, string $ip): array
    {
        $u = self::user($p);
        if (!empty($u['mfa']['ativo'])) {
            throw new ApiException(409, 'mfa_ja_ativo', 'A verificação em duas etapas já está ativa.');
        }
        $cad = $u['mfa_cadastro'] ?? null;
        if (!is_array($cad) || (int) ($cad['expira_em'] ?? 0) < time()) {
            throw new ApiException(409, 'mfa_cadastro_expirado', 'Cadastro não iniciado ou expirado. Gere um novo QR-Code/segredo e tente de novo.');
        }
        $wait = RateLimiter::hit('mfa-confirmar', $u['id'], 10, 900);
        if ($wait > 0) {
            Logger::audit('auth.mfa_cadastro', 'limite', ['user_id' => $u['id']]);
            throw self::tooMany($wait);
        }
        $codigo = self::normalizarCodigo($in['codigo'] ?? null);
        $passo = $codigo === null ? null : Totp::verificar(Crypto::decrypt((string) $cad['segredo_enc']), $codigo);
        if ($passo === null) {
            Logger::audit('auth.mfa_cadastro', 'codigo_invalido', ['user_id' => $u['id']]);
            throw ApiException::validation([['campo' => 'codigo', 'mensagem' => 'Código inválido ou expirado. Confira a hora do celular e digite o código atual do aplicativo.']]);
        }
        $codigos = self::novosCodigos();
        $user = UserStore::update($u['id'], function ($x) use ($cad, $passo, $codigos) {
            $x['mfa'] = [
                'ativo' => true,
                'segredo_enc' => $cad['segredo_enc'],
                'ativado_em' => date('c'),
                'ultimo_passo' => $passo,
                'recuperacao' => array_map([self::class, 'hashRecuperacao'], $codigos),
            ];
            unset($x['mfa_cadastro']);
            $x['sessao_versao'] = (int) $x['sessao_versao'] + 1; // outras sessões (sem MFA) caem
            return $x;
        });
        RateLimiter::clear('mfa-confirmar', $u['id']);
        if ($p->sessionHash !== null) {
            SessionManager::destroy($p->sessionHash);
        }
        Logger::audit('auth.mfa', 'ativado', ['user_id' => $u['id']]);
        [$token, $s] = SessionManager::create($user, $ip);
        return [$token, $s, $codigos];
    }

    /**
     * Desativa o MFA: exige senha atual + código (TOTP ou de recuperação). Derruba as outras sessões.
     * @param array<string,mixed> $in
     * @return array{0:string, 1:array<string,mixed>}
     */
    public static function desativar(Principal $p, array $in, string $ip): array
    {
        $u = self::user($p);
        if (empty($u['mfa']['ativo'])) {
            throw new ApiException(409, 'mfa_inativo', 'A verificação em duas etapas não está ativa.');
        }
        if (self::obrigatorioParaDono() && $u['papel'] === UserStore::ROLE_OWNER) {
            Logger::audit('auth.mfa', 'desativar_negado', ['user_id' => $u['id'], 'motivo' => 'obrigatorio']);
            throw new ApiException(409, 'mfa_obrigatorio', 'A verificação em duas etapas é obrigatória para proprietários nesta instalação.');
        }
        $metodo = self::senhaEFator($u, $in, 'desativar');
        $user = UserStore::update($u['id'], function ($x) {
            unset($x['mfa'], $x['mfa_cadastro']);
            $x['sessao_versao'] = (int) $x['sessao_versao'] + 1;
            return $x;
        });
        if ($p->sessionHash !== null) {
            SessionManager::destroy($p->sessionHash);
        }
        Logger::audit('auth.mfa', 'desativado', ['user_id' => $u['id'], 'metodo' => $metodo]);
        return SessionManager::create($user, $ip);
    }

    /**
     * Gera novos códigos de recuperação (os anteriores deixam de valer). Exige senha + código.
     * @param array<string,mixed> $in
     * @return list<string>
     */
    public static function regenerarCodigos(Principal $p, array $in): array
    {
        $u = self::user($p);
        if (empty($u['mfa']['ativo'])) {
            throw new ApiException(409, 'mfa_inativo', 'A verificação em duas etapas não está ativa.');
        }
        self::senhaEFator($u, $in, 'regenerar_codigos');
        $codigos = self::novosCodigos();
        UserStore::update($u['id'], function ($x) use ($codigos) {
            $x['mfa']['recuperacao'] = array_map([self::class, 'hashRecuperacao'], $codigos);
            return $x;
        });
        Logger::audit('auth.mfa', 'codigos_regenerados', ['user_id' => $u['id']]);
        return $codigos;
    }

    // ------------------------------------------------------------------ login (segunda etapa)

    /**
     * Cria a etapa pendente do login (senha já conferida). Devolve [token, expira_em].
     * @param array<string,mixed> $user
     * @return array{0:string, 1:int}
     */
    public static function criarPendente(array $user, string $ip): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expira = time() + self::PENDENTE_TTL;
        JsonStore::write(self::pendenteFile(hash('sha256', $token)), [
            'user_id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'versao' => (int) $user['sessao_versao'],
            'criado_em' => time(),
            'expira_em' => $expira,
            'tentativas' => 0,
            'ip' => $ip,
        ]);
        if (random_int(1, 50) === 1) {
            self::gcPendentes();
        }
        return [$token, $expira];
    }

    /**
     * Segunda etapa do login: token da etapa pendente + código TOTP ou de recuperação.
     * @param array<string,mixed> $in
     * @return array{0:string, 1:array<string,mixed>, 2:array<string,mixed>} [token da sessão, sessão, usuário]
     */
    public static function verificarLogin(array $in, string $ip): array
    {
        $ipMax = max(1, Env::int('LOGIN_IP_MAX', 20));
        if (RateLimiter::count('login-ip', $ip, 900) >= $ipMax) {
            Logger::audit('auth.mfa', 'limite_ip');
            throw self::tooMany(RateLimiter::retryAfter('login-ip', $ip, 900));
        }
        $invalid = fn() => new ApiException(401, 'mfa_etapa_invalida', 'A etapa de verificação expirou ou é inválida. Entre novamente com e-mail e senha.');
        $tok = $in['mfa_token'] ?? null;
        if (!is_string($tok) || preg_match('/^[A-Za-z0-9_-]{43}$/', $tok) !== 1) {
            RateLimiter::hit('login-ip', $ip, PHP_INT_MAX, 900);
            Logger::audit('auth.mfa', 'etapa_invalida');
            throw $invalid();
        }
        $hash = hash('sha256', $tok);
        $file = self::pendenteFile($hash);
        return JsonStore::withLock("mfa-pendente-$hash", function () use ($file, $in, $ip, $invalid) {
            $pend = JsonStore::read($file);
            if ($pend === null) {
                RateLimiter::hit('login-ip', $ip, PHP_INT_MAX, 900);
                Logger::audit('auth.mfa', 'etapa_invalida');
                throw $invalid();
            }
            if ((int) $pend['expira_em'] < time()) {
                JsonStore::delete($file);
                Logger::audit('auth.mfa', 'etapa_expirada', ['user_id' => $pend['user_id']]);
                throw $invalid();
            }
            $user = UserStore::get((string) $pend['user_id']);
            if ($user === null || $user['tenant_id'] !== $pend['tenant_id'] || (int) $user['sessao_versao'] !== (int) $pend['versao'] || empty($user['mfa']['ativo'])) {
                JsonStore::delete($file);
                throw $invalid();
            }
            Tenant::set($user['tenant_id']);
            RequestContext::$actor = 'user:' . $user['id'];
            if (RateLimiter::count('login-email', $user['email'], AuthService::lockWindow()) >= AuthService::lockMax()) {
                JsonStore::delete($file);
                Logger::audit('auth.mfa', 'conta_bloqueada', ['user_id' => $user['id']]);
                $wait = RateLimiter::retryAfter('login-email', $user['email'], AuthService::lockWindow());
                throw new ApiException(429, 'conta_bloqueada', 'Muitas tentativas para esta conta. Tente novamente em ' . max(1, (int) ceil($wait / 60)) . ' min.', [], ['Retry-After' => (string) $wait]);
            }
            $metodo = self::consumirFator($user['id'], $in['codigo'] ?? null);
            if ($metodo === null) {
                RateLimiter::hit('login-ip', $ip, PHP_INT_MAX, 900);
                RateLimiter::hit('login-email', $user['email'], PHP_INT_MAX, AuthService::lockWindow());
                $pend['tentativas'] = (int) $pend['tentativas'] + 1;
                $esgotou = $pend['tentativas'] >= self::MAX_TENTATIVAS_ETAPA;
                $esgotou ? JsonStore::delete($file) : JsonStore::write($file, $pend);
                Logger::audit('auth.mfa', 'falha', ['user_id' => $user['id'], 'tentativa' => $pend['tentativas'], 'etapa_encerrada' => $esgotou]);
                if ($esgotou) {
                    throw new ApiException(401, 'mfa_etapa_invalida', 'Muitos códigos incorretos. Entre novamente com e-mail e senha.');
                }
                throw new ApiException(401, 'codigo_invalido', 'Código inválido. Digite o código atual do aplicativo autenticador ou um código de recuperação.');
            }
            JsonStore::delete($file); // etapa de uso único
            RateLimiter::clear('login-email', $user['email']);
            $user = UserStore::update($user['id'], fn($u) => ['ultimo_login_em' => date('c')] + $u);
            [$token, $s] = SessionManager::create($user, $ip);
            $ctx = ['user_id' => $user['id'], 'metodo' => $metodo];
            if ($metodo === 'recuperacao') {
                $ctx['codigos_recuperacao_restantes'] = count($user['mfa']['recuperacao'] ?? []);
            }
            Logger::audit('auth.mfa', 'sucesso', $ctx);
            Logger::audit('auth.login', 'sucesso', ['user_id' => $user['id'], 'mfa' => true]);
            return [$token, $s, $user];
        });
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string,mixed> */
    private static function user(Principal $p): array
    {
        $u = $p->userId !== null ? UserStore::get($p->userId) : null;
        
        if ($u === null || ($u['tenant_id'] !== $p->tenantId)) {
            throw new ApiException(401, 'nao_autorizado', 'Sessão inválida');
        }
        return $u;
    }

    /**
     * Confere senha atual e depois o segundo fator (desativar / regenerar códigos). Falhas alimentam o
     * bloqueio da conta. Devolve o método do fator usado.
     * @param array<string,mixed> $u
     * @param array<string,mixed> $in
     */
    private static function senhaEFator(array $u, array $in, string $acao): string
    {
        if (RateLimiter::count('login-email', $u['email'], AuthService::lockWindow()) >= AuthService::lockMax()) {
            Logger::audit('auth.mfa', $acao . '_bloqueado', ['user_id' => $u['id']]);
            throw self::tooMany(RateLimiter::retryAfter('login-email', $u['email'], AuthService::lockWindow()));
        }
        $senha = $in['senha'] ?? null;
        if (!is_string($senha) || strlen($senha) > 1024 || !Password::verify($senha, $u['senha_hash'])) {
            RateLimiter::hit('login-email', $u['email'], PHP_INT_MAX, AuthService::lockWindow());
            Logger::audit('auth.mfa', $acao . '_senha_incorreta', ['user_id' => $u['id']]);
            throw ApiException::validation([['campo' => 'senha', 'mensagem' => 'Senha atual incorreta']]);
        }
        $metodo = self::consumirFator($u['id'], $in['codigo'] ?? null);
        if ($metodo === null) {
            RateLimiter::hit('login-email', $u['email'], PHP_INT_MAX, AuthService::lockWindow());
            Logger::audit('auth.mfa', $acao . '_codigo_invalido', ['user_id' => $u['id']]);
            throw ApiException::validation([['campo' => 'codigo', 'mensagem' => 'Código inválido']]);
        }
        return $metodo;
    }

    /**
     * Confere e CONSOME o fator sob lock do registro do usuário: TOTP (6 dígitos; grava o passo aceito para
     * impedir replay) ou código de recuperação (removido da lista: uso único). Devolve 'totp', 'recuperacao'
     * ou null.
     */
    private static function consumirFator(string $uid, mixed $codigoBruto): ?string
    {
        $metodo = null;
        $codigo = self::normalizarCodigo($codigoBruto);
        if ($codigo === null) {
            return null;
        }
        UserStore::update($uid, function ($u) use ($codigo, &$metodo) {
            if (empty($u['mfa']['ativo'])) {
                return $u;
            }
            if (preg_match('/^\d{' . Totp::DIGITOS . '}$/', $codigo) === 1) {
                $ultimo = isset($u['mfa']['ultimo_passo']) ? (int) $u['mfa']['ultimo_passo'] : null;
                $passo = Totp::verificar(Crypto::decrypt((string) $u['mfa']['segredo_enc']), $codigo, $ultimo);
                if ($passo !== null) {
                    $u['mfa']['ultimo_passo'] = $passo;
                    $metodo = 'totp';
                }
                return $u;
            }
            $h = self::hashRecuperacao($codigo);
            $lista = array_values(array_filter((array) ($u['mfa']['recuperacao'] ?? []), 'is_string'));
            $achou = null;
            foreach ($lista as $i => $x) {
                if (hash_equals($x, $h)) {
                    $achou = $i;
                }
            }
            if ($achou !== null) {
                array_splice($lista, $achou, 1); // uso único
                $u['mfa']['recuperacao'] = $lista;
                $metodo = 'recuperacao';
            }
            return $u;
        });
        return $metodo;
    }

    /** Remove espaços/hífens; aceita 6 dígitos (TOTP) ou 10 caracteres Base32 (recuperação). */
    private static function normalizarCodigo(mixed $c): ?string
    {
        if (!is_string($c) || strlen($c) > 32) {
            return null;
        }
        $c = strtoupper(preg_replace('/[\s-]/', '', $c) ?? '');
        if (preg_match('/^\d{' . Totp::DIGITOS . '}$/', $c) === 1 || preg_match('/^[A-Z2-7]{10}$/', $c) === 1) {
            return $c;
        }
        return null;
    }

    /** @return list<string> códigos no formato XXXXX-XXXXX (50 bits cada) */
    private static function novosCodigos(): array
    {
        $out = [];
        while (count($out) < self::QTD_RECUPERACAO) {
            $c = substr(Totp::base32Encode(random_bytes(7)), 0, 10);
            $out[$c] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return array_values($out);
    }

    /** HMAC-SHA256 do código normalizado com chave derivada de CERT_ENCRYPTION_KEY (rótulo próprio). */
    public static function hashRecuperacao(string $codigo): string
    {
        $c = strtoupper(preg_replace('/[\s-]/', '', $codigo) ?? '');
        $key = hash_hmac('sha256', 'mfa-recuperacao-v1', (string) base64_decode(Env::require('CERT_ENCRYPTION_KEY'), true), true);
        return hash_hmac('sha256', $c, $key);
    }

    private static function pendenteFile(string $hash): string
    {
        return "mfa-pendente/$hash.json";
    }

    private static function gcPendentes(): void
    {
        foreach (JsonStore::files('mfa-pendente/*.json') as $f) {
            $d = JsonStore::read($f);
            if (!is_array($d) || (int) ($d['expira_em'] ?? 0) < time()) {
                JsonStore::delete($f);
            }
        }
    }

    private static function tooMany(int $wait): ApiException
    {
        return new ApiException(429, 'muitas_tentativas', 'Muitas tentativas. Tente novamente em ' . max(1, (int) ceil($wait / 60)) . ' min.', [], ['Retry-After' => (string) $wait]);
    }
}
