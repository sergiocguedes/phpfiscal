<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Auth\ApiKeyStore;
use App\Storage\JsonStore;
use App\Support\Env;
use App\Support\Logger;
use App\Support\Paths;



final class Migration
{
    private const MARK = 'migrations.json';
    private const NAME = 'multitenant_v1';

    public static function run(): void
    {
        $m = JsonStore::read(self::MARK);
        if (isset($m[self::NAME])) {
            return;
        }
        JsonStore::withLock('migration', function () {
            $m = JsonStore::read(self::MARK) ?? [];
            if (isset($m[self::NAME])) {
                return;
            }
            $tid = Tenant::DEFAULT_ID;
            $legacyCfg = JsonStore::read('config.json');
            if (TenantStore::get($tid) === null) {
                TenantStore::create(
                    (string) ($legacyCfg['razao_social'] ?? 'Empresa padrão (migrada)'),
                    (string) ($legacyCfg['cnpj'] ?? ''),
                    $tid
                );
            }
            TenantStore::ensureDirs($tid);
            $moved = [];
            foreach (['config.json', 'cert.enc', 'cert.meta.json', 'inutilizacoes.json'] as $f) {
                $src = Paths::storage($f);
                if (is_file($src)) {
                    rename($src, Paths::storage(Tenant::pathFor($tid, $f)));
                    $moved[] = $f;
                }
            }
            foreach (['nfe', 'idempotency'] as $d) {
                $src = Paths::storage($d);
                if (!is_dir($src)) {
                    continue;
                }
                foreach (scandir($src) ?: [] as $f) {
                    if ($f === '.' || $f === '..' || !is_file("$src/$f")) {
                        continue;
                    }
                    rename("$src/$f", Paths::storage(Tenant::pathFor($tid, "$d/$f")));
                    $moved[] = "$d/$f";
                }
                @rmdir($src);
            }
            Tenant::run($tid, function () {
                $key = Env::get('API_KEY');
                if ($key !== null && strlen($key) >= 32) {
                    ApiKeyStore::create(Tenant::DEFAULT_ID, 'Chave legada (.env API_KEY)', 'migracao', $key);
                }
                
            });
            $m[self::NAME] = ['em' => date('c'), 'tenant' => $tid, 'arquivos' => count($moved)];
            JsonStore::write(self::MARK, $m);
            Logger::audit('migracao.multitenant', 'sucesso', ['tenant_destino' => $tid, 'arquivos' => count($moved)]);
        });
    }
}
