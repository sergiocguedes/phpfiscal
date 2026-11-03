<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(\App\Storage\PostgresStore::enabled()) { \App\Storage\PostgresStore::migrate(); }
