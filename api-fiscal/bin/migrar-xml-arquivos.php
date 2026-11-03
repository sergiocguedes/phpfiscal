<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
use App\Storage\{JsonStore,PostgresStore,XmlArchive};
use App\Support\{Env,Paths};
use App\Tenancy\TenantStore;

$apply = in_array('--aplicar', $argv, true);
$paths = [];
if (PostgresStore::enabled()) {
    $q = PostgresStore::db()->prepare("SELECT path FROM fiscal_blobs WHERE namespace=? AND path LIKE 'tenants/%' AND path LIKE '%.xml'");
    $q->execute([PostgresStore::ns()]); $paths = $q->fetchAll(PDO::FETCH_COLUMN);
} elseif (is_dir(Paths::storage('tenants'))) {
    $prefix = rtrim(Paths::storage(), '/') . '/';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Paths::storage('tenants'), FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getExtension() === 'xml') { $paths[] = substr($f->getPathname(), strlen($prefix)); }
    }
}
foreach (JsonStore::files('archive/index/*.json') as $index) { $r = JsonStore::read($index); if ($r) { $paths[] = $r['path']; } }
$counts = ['modo'=>$apply ? 'aplicar' : 'simular', 'destino'=>Env::get('XML_STORAGE','local'), 'xml_encontrados'=>0, 'convertidos'=>0, 'ja_atualizados'=>0, 'falhas'=>0, 'pdv_inline'=>0];
foreach (array_unique($paths) as $path) {
    if (!XmlArchive::recognizes($path)) { continue; } $counts['xml_encontrados']++;
    $ref = XmlArchive::reference($path);
    if (($ref['versao'] ?? null) === 2 && ($ref['provedor'] ?? '') === $counts['destino']) { $counts['ja_atualizados']++; continue; }
    if (!$apply) { continue; }
    try {
        $xml = JsonStore::readRaw($path); if ($xml === null) { throw new RuntimeException('XML de origem ausente'); }
        XmlArchive::put($path, $xml);
        if (!hash_equals(hash('sha256',$xml), hash('sha256', (string) JsonStore::readRaw($path)))) { throw new RuntimeException('Verificação da migração falhou'); }
        $counts['convertidos']++;
    } catch (Throwable $e) { $counts['falhas']++; fwrite(STDERR, 'Falha em ' . $path . ': ' . $e->getMessage() . PHP_EOL); }
}
foreach (TenantStore::ids() as $tid) { foreach ([1,2] as $amb) {
    foreach (JsonStore::files("tenants/$tid/ambientes/$amb/pdv/*.json") as $path) {
        $op = JsonStore::read($path);
        if (!is_array($op) || (!isset($op['xml']) && !isset($op['xml_sem_assinatura']))) { continue; }
        $counts['pdv_inline']++;
        if (!$apply) { continue; }
        JsonStore::withLock('pdv-'.sha1($path), function () use ($path) {
            $op = JsonStore::read($path);
            foreach (['xml', 'xml_sem_assinatura'] as $field) {
                if (!isset($op[$field])) { continue; }
                $file = substr($path, 0, -5) . '-' . $field . '.xml';
                XmlArchive::put($file, $op[$field]);
                if (!hash_equals(hash('sha256',$op[$field]), hash('sha256',(string)JsonStore::readRaw($file)))) { throw new RuntimeException('XML PDV divergente'); }
                $op[$field.'_arquivo'] = $file; unset($op[$field]);
            }
            JsonStore::write($path, $op);
        });
    }
} }
echo json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($counts['falhas'] ? 1 : 0);
