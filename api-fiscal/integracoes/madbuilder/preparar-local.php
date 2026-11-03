<?php
/** Executar no container da API local. Não imprime a chave criada. */
require dirname(__DIR__, 2) . '/bootstrap.php';
$cnpj = preg_replace('/\D/', '', $argv[1] ?? '');
if (strlen($cnpj) !== 14) { throw new RuntimeException('Informe o CNPJ.'); }
$path = APP_ROOT . '/storage/integracoes/madbuilder-api.local.php';
if (file_exists($path)) { echo "Configuração já preparada; nenhuma chave criada.\n"; exit(0); }
$ids = array_filter(App\Tenancy\TenantStore::ids(), fn($id) => (App\Tenancy\TenantStore::get($id)['cnpj'] ?? '') === $cnpj);
if (count($ids) !== 1) { throw new RuntimeException('CNPJ não localizado de forma única na API local.'); }
$id = array_values($ids)[0];
[$key, $record] = App\Auth\ApiKeyStore::create($id, 'MadBuilder emissor serviços local', null);
$cfg = ['url' => 'http://host.docker.internal:8081/api/v1', 'empresas' => [$cnpj => ['chave' => $key, 'empresa_id' => '']]];
if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
file_put_contents($path, '<?php return ' . var_export($cfg, true) . ";\n", LOCK_EX); chmod($path, 0600);
echo "Configuração local preparada para $cnpj; chave restrita criada e salva sem exibição.\n";
