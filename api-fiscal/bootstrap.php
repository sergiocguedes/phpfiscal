<?php

declare(strict_types=1);

/**
 * Inicialização comum a HTTP, worker, scripts e testes.
 */

define('APP_ROOT', __DIR__);

require APP_ROOT . '/vendor/autoload.php';
require APP_ROOT . '/src/Support/soap_polyfill.php';

date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
error_reporting(E_ALL);

App\Support\Env::load(getenv('APP_ENV_FILE') ?: APP_ROOT . '/.env');
App\Support\Paths::ensure();
