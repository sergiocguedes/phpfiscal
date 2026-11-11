<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use App\Nfe\PdvService;
use App\Support\Logger;
use App\Support\Paths;
use App\Support\RequestContext;

RequestContext::$actor = 'worker';
RequestContext::$requestId = 'worker';
$lock = fopen(Paths::storage('locks/worker.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Outro worker já está em execução.\n");
    exit(1);
}
$once = in_array('--once', $argv, true);
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
}
Logger::info('worker fiscal iniciado');
do {
    \App\Support\Settings::reset(); // relê a configuração do banco a cada ciclo (alterações da janela valem sem reiniciar)
    try { PdvService::ciclo(); } catch (Throwable $e) { Logger::exception($e, 'worker.pdv'); }
    try { \App\Storage\XmlArchive::ciclo(); } catch (Throwable $e) { Logger::exception($e, 'worker.b2'); } // envia XML pendentes ao Backblaze
    if (!$once) { sleep(2); }
} while (!$once && $running);
