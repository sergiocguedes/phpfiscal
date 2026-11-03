<?php

declare(strict_types=1);

/**
 * Desativa o MFA (TOTP) de um usuário que perdeu o celular E os códigos de recuperação.
 * Uso administrativo, na máquina do emissor, com o usuário do sistema dono de storage/.
 * Confirme a identidade da pessoa por outro canal ANTES de rodar. Derruba as sessões dela e
 * grava auth.mfa / desativado_cli no audit.log (ator cli:<usuário do sistema>).
 *
 * Uso: php bin/mfa-desativar.php <email>
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Auth\UserStore;
use App\Support\Logger;
use App\Support\RequestContext;
use App\Tenancy\Tenant;

if ($argc !== 2) {
    fwrite(STDERR, "Uso: php bin/mfa-desativar.php <email>\n");
    exit(2);
}
RequestContext::$actor = 'cli:' . (get_current_user() ?: 'desconhecido');
RequestContext::$requestId = 'cli';

$email = UserStore::normalizeEmail($argv[1]);
$u = $email !== null ? UserStore::findByEmail($email) : null;
if ($u === null) {
    fwrite(STDERR, "Usuário não encontrado.\n");
    exit(1);
}
if (empty($u['mfa']['ativo'])) {
    echo "O MFA deste usuário já está desativado.\n";
    exit(0);
}
Tenant::set($u['tenant_id']);
UserStore::update($u['id'], function ($x) {
    unset($x['mfa'], $x['mfa_cadastro']);
    $x['sessao_versao'] = (int) $x['sessao_versao'] + 1; // derruba as sessões abertas
    return $x;
});
Logger::audit('auth.mfa', 'desativado_cli', ['user_id' => $u['id']]);
echo "MFA desativado para {$u['id']} (tenant {$u['tenant_id']}). Sessões encerradas; peça para a pessoa entrar e cadastrar de novo.\n";
