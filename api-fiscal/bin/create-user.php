<?php

declare(strict_types=1);

/**
 * Cria um usuário (convite) em um tenant existente e envia o link para ele definir a senha
 * (em modo dev o link vai para storage/logs/mail.log). Útil para dar um proprietário ao
 * tenant padrão criado pela migração single-tenant -> multi-tenant.
 *
 * Uso: php bin/create-user.php <email> "<nome>" [tenant_id] [owner|fiscal|member]
 *      tenant_id padrão: t_0000000000000001 (dados migrados)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Auth\AuthService;
use App\Auth\UserStore;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\RequestContext;
use App\Tenancy\Migration;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStore;

if ($argc < 3) {
    fwrite(STDERR, "Uso: php bin/create-user.php <email> \"<nome>\" [tenant_id] [owner|fiscal|member]\n");
    exit(2);
}
Migration::run();
RequestContext::$actor = 'cli:' . (get_current_user() ?: 'desconhecido');
RequestContext::$requestId = 'cli';

$email = UserStore::normalizeEmail($argv[1]);
$nome = \App\Support\Validate::text($argv[2], 2, 80);
$tid = $argv[3] ?? Tenant::DEFAULT_ID;
$role = $argv[4] ?? UserStore::ROLE_OWNER;
if ($email === null || $nome === null || !Tenant::valid($tid) || TenantStore::get($tid) === null || !in_array($role, UserStore::ROLES, true)) {
    fwrite(STDERR, "Parâmetros inválidos (e-mail, nome, tenant inexistente ou papel).\n");
    exit(2);
}
Tenant::set($tid);
try {
    $u = UserStore::create($tid, $nome, $email, null, $role);
} catch (\App\Http\ApiException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$token = AuthService::issueResetToken($u, AuthService::INVITE_TTL);
Mailer::send($email, 'Acesso ao Emissor NF-e', "Olá, $nome.\n\nDefina sua senha (link válido por 48 horas, uso único):\n" . Mailer::appUrl() . '/redefinir-senha#token=' . $token . "\n");
Logger::audit('usuario.criado_cli', 'sucesso', ['user_id' => $u['id'], 'papel' => $role]);
echo "Usuário {$u['id']} criado no tenant $tid. Link para definir a senha enviado"
    . (Mailer::devMode() ? " (modo dev: veja storage/logs/mail.log).\n" : " por e-mail.\n");
