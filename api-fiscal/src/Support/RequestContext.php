<?php

declare(strict_types=1);

namespace App\Support;

use App\Auth\Principal;
use App\Tenancy\Tenant;

final class RequestContext
{
    public static string $requestId = '-';
    public static string $actor = 'system';
    public static string $ip = '-';
    public static ?Principal $principal = null;

    public static function reset(string $ip): void
    {
        self::$requestId = bin2hex(random_bytes(8));
        self::$actor = 'anonimo';
        self::$ip = $ip;
        self::$principal = null;
        Tenant::set(null);
        \App\Tenancy\FiscalScope::reset();
    }

    public static function authenticate(Principal $p): void
    {
        self::$principal = $p;
        self::$actor = $p->actor();
        Tenant::set($p->tenantId);
    }
}
