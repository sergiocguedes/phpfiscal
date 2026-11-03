<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (!is_file($root . '/.env')) {
    $text = file_get_contents($root . '/.env.example');
    $text = preg_replace('/^API_KEY=.*$/m', 'API_KEY=' . bin2hex(random_bytes(32)), $text);
    $text = preg_replace('/^CERT_ENCRYPTION_KEY=.*$/m', 'CERT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)), $text);
    $text = preg_replace('/^DATABASE_PASSWORD=.*$/m', 'DATABASE_PASSWORD=' . bin2hex(random_bytes(32)), $text);
    $mask = umask(0077);
    file_put_contents($root . '/.env', $text);
    umask($mask);
    echo ".env criado com segredos aleatórios.\n";
} else { echo ".env existente preservado.\n"; }
@chmod($root . '/.env', 0600);
foreach (['storage','storage/logs','storage/ratelimit','storage/tmp','storage/locks','storage/tenants','storage/users','storage/sessions','storage/apikeys','storage/resets','storage/mfa-pendente','run'] as $dir) {
    if (!is_dir($root . '/' . $dir)) { mkdir($root . '/' . $dir, 0700, true); }
}
