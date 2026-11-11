<?php

declare(strict_types=1);

// Testes rodam com .env e storage isolados (diretório temporário), sem tocar no ambiente real.
$tmp = sys_get_temp_dir() . '/sistema-nfephp-tests-' . getmypid();
@mkdir($tmp, 0700, true);
$env = "$tmp/.env";
file_put_contents($env, implode("\n", [
    'API_KEY=' . str_repeat('a1', 32),
    'WEBHOOK_INBOUND_SECRET=segredo-de-teste-entrada-0123456789',
    'WEBHOOK_INBOUND_REQUIRE_BEARER=true',
    'WEBHOOK_OUTBOUND_SECRET=segredo-de-teste-saida-0123456789',
    'WEBHOOK_ALLOW_PRIVATE_URLS=false',
    'CERT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'AUTH_MAX_FAILURES=5',
    'STORAGE_DIR=' . "$tmp/storage",
    'STORAGE_DRIVER=files',
]));
putenv("APP_ENV_FILE=$env");
if(getenv('FISCAL_TEST_PG')==='1'){
    $source=file_get_contents(dirname(__DIR__).'/.env');
    $extra=[];
    foreach(explode("\n",$source) as $line){if(preg_match('/^DATABASE_(DSN|USER|PASSWORD)=/',$line)){$extra[]=trim($line);}}
    $extra[]='STORAGE_DRIVER=pgsql';$extra[]='STORAGE_DIR='."$tmp/storage";$extra[]='STORAGE_NAMESPACE=tests_'.getmypid();
    file_put_contents($env,"\n".implode("\n",$extra),FILE_APPEND);
}

require dirname(__DIR__) . '/bootstrap.php';
$testLoader = require dirname(__DIR__) . '/vendor/autoload.php';
$testLoader->addPsr4('Tests\\', __DIR__);
if(\App\Storage\PostgresStore::enabled()){\App\Storage\PostgresStore::migrate();}

register_shutdown_function(static function () use ($tmp) {
    if(\App\Storage\PostgresStore::enabled()){
        $ns=\App\Storage\PostgresStore::ns();
        if(str_starts_with($ns,'tests_')){foreach(['fiscal_documents','fiscal_blobs','fiscal_api_settings'] as $t){$q=\App\Storage\PostgresStore::db()->prepare("DELETE FROM $t WHERE namespace=?");$q->execute([$ns]);}}
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});
