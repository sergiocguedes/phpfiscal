<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
header_remove('X-Powered-By');
(new App\App())->handle(App\Http\Request::fromGlobals())->send();
