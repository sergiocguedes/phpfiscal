<?php
declare(strict_types=1);
namespace App\Storage;
use App\Support\{Env,Logger};

/**
 * Armazenamento dos XML fiscais.
 *
 * XML_STORAGE=banco (padrão): o XML fica no PostgreSQL (fiscal_blobs), sem índice.
 * XML_STORAGE=s3 (Backblaze B2): a emissão grava o XML no banco como cópia durável PENDENTE e um índice em
 *   archive/index/<sha256(path)>.json. O worker (ciclo) envia ao B2, baixa de volta, confere o SHA-256 e só então
 *   marca "arquivado" e apaga a cópia do banco. A emissão nunca espera a rede; falha do B2 preserva a cópia.
 *   Objetos são imutáveis: cada versão do XML tem chave própria (<prefixo>/<path>/<sha256>.xml).
 *
 * Instalações anteriores podem ter índices v2 com cópia em arquivo (storage/xml, storage/xml-pendentes):
 * migrarLegado() (bin/banco.php, a cada início) traz essas cópias para o banco com conferência de SHA-256.
 */
final class XmlArchive
{
    public static function modo(): string { return in_array(strtolower((string) Env::get('XML_STORAGE', 'banco')), ['s3', 'b2'], true) ? 's3' : 'banco'; }

    public static function recognizes(string $path): bool
    {
        return !str_contains($path, '..') && preg_match('~^tenants/t_[a-f0-9]{16}/ambientes/[12]/(?:nfe|nfse|pdv)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.xml$~D', $path) === 1;
    }
    private static function index(string $path): string { return 'archive/index/' . hash('sha256', $path) . '.json'; }
    private static function lockName(string $path): string { return 'xml-' . hash('sha256', $path); }
    public static function exists(string $path): bool { return JsonStore::read(self::index($path)) !== null; }
    public static function reference(string $path): ?array { return JsonStore::read(self::index($path)); }

    private static function objectKey(string $path, string $sha): string
    {
        $prefix = trim((string) Env::get('S3_PREFIX', 'fiscal'), '/');
        if (!preg_match('~^[a-zA-Z0-9/_-]*$~D', $prefix) || str_contains($prefix, '..')) { throw new \RuntimeException('Prefixo do B2 inválido'); }
        return ($prefix !== '' ? $prefix . '/' : '') . $path . '/' . $sha . '.xml';
    }

    /** Gravação de um XML fiscal (chamada por JsonStore::writeRaw). */
    public static function put(string $path, string $xml): void
    {
        if (!self::recognizes($path)) { throw new \InvalidArgumentException('Caminho fiscal XML inválido'); }
        JsonStore::withLock(self::lockName($path), function () use ($path, $xml) {
            $idx = self::reference($path); $sha = hash('sha256', $xml);
            if (self::modo() === 'banco') {
                JsonStore::writeBackend($path, $xml);
                if ($idx !== null) { JsonStore::deleteBackend(self::index($path)); if (isset($idx['blob'])) { JsonStore::deleteBackend($idx['blob']); } }
                return;
            }
            if ($idx !== null && ($idx['versao'] ?? 0) === 3 && hash_equals((string) $idx['sha256'], $sha)) {
                if ($idx['estado'] === 'arquivado') { return; }                                  // mesma versão já no B2
                if (JsonStore::readBackend($path) !== null) { return; }                          // mesma versão já pendente
            }
            JsonStore::writeBackend($path, $xml);                                                  // cópia durável ANTES do índice
            JsonStore::write(self::index($path), ['versao' => 3, 'path' => $path, 'sha256' => $sha, 'bytes' => strlen($xml),
                'provedor' => 's3', 'object_key' => self::objectKey($path, $sha), 'estado' => 'pendente', 'tentativas' => 0,
                'proxima_tentativa' => 0, 'criado_em' => date('c')]);
            if ($idx !== null && isset($idx['blob'])) { JsonStore::deleteBackend($idx['blob']); }
        });
    }

    /** Leitura quando não há cópia no banco: arquivo legado ou objeto no B2. */
    public static function get(string $path): ?string
    {
        $r = self::reference($path); if ($r === null) { return null; }
        $xml = isset($r['local_path']) ? XmlFiles::get($r['local_path']) : null;
        if ($xml === null && isset($r['blob'])) { $xml = JsonStore::readBackend($r['blob']); }
        if ($xml === null && isset($r['object_key']) && $r['estado'] === 'arquivado') {
            try { $xml = S3Client::request('GET', $r['object_key']); }
            catch (\Throwable $e) {
                Logger::exception($e, 'archive.download');
                throw new \App\Http\ApiException(503, 'xml_armazenamento_indisponivel', 'XML arquivado no Backblaze temporariamente indisponível. Tente novamente sem emitir outra nota.');
            }
        }
        if ($xml === null) {
            throw new \App\Http\ApiException(503, 'xml_armazenamento_indisponivel', 'Cópia do XML não encontrada. Restaure o armazenamento (banco ou storage/xml) sem emitir outra nota.');
        }
        if (!hash_equals($r['sha256'], hash('sha256', $xml))) { throw new \RuntimeException('Integridade do XML arquivado inválida'); }
        return $xml;
    }

    /** Worker: envia ao B2 os XML pendentes, confere e libera a cópia do banco. */
    public static function ciclo(int $limit = 10): void
    {
        if (self::modo() !== 's3' || !S3Client::configurado()) { return; }
        foreach (JsonStore::files('archive/index/*.json') as $index) {
            $r = JsonStore::read($index);
            if ($r === null || ($r['versao'] ?? 0) !== 3 || $r['estado'] !== 'pendente' || ($r['proxima_tentativa'] ?? 0) > time()) { continue; }
            JsonStore::withLock(self::lockName($r['path']), function () use ($index) {
                $r = JsonStore::read($index);
                if ($r === null || $r['estado'] !== 'pendente') { return; }
                try {
                    $xml = JsonStore::readBackend($r['path']);
                    if ($xml === null || !hash_equals($r['sha256'], hash('sha256', $xml))) { throw new \RuntimeException('Cópia pendente ausente ou diferente do índice'); }
                    S3Client::request('PUT', $r['object_key'], $xml);
                    $verify = S3Client::request('GET', $r['object_key']);
                    if (!hash_equals($r['sha256'], hash('sha256', $verify))) { throw new \RuntimeException('Verificação do objeto no B2 falhou'); }
                    unset($r['erro']);
                    $r['estado'] = 'arquivado'; $r['arquivado_em'] = date('c');
                    JsonStore::write($index, $r);                 // referência confirmada ANTES de liberar a cópia
                    JsonStore::deleteBackend($r['path']);
                } catch (\Throwable $e) {
                    $r = JsonStore::read($index);
                    if ($r !== null && $r['estado'] === 'pendente') {
                        $r['tentativas'] = ($r['tentativas'] ?? 0) + 1; $r['proxima_tentativa'] = time() + min(3600, 30 * (2 ** min(7, $r['tentativas'])));
                        $r['erro'] = 'Falha ao enviar/verificar no B2; cópia no banco preservada'; JsonStore::write($index, $r);
                    }
                    Logger::exception($e, 'archive.b2');
                }
            });
            if (--$limit <= 0) { break; }
        }
    }

    /** Caminhos fiscais conhecidos só pelo índice (arquivados no B2 ou legado em arquivo). */
    public static function paths(string $pattern): array
    {
        $paths = [];
        foreach (JsonStore::files('archive/index/*.json') as $index) {
            $r = JsonStore::read($index);
            if ($r !== null && fnmatch($pattern, $r['path'], FNM_PATHNAME)) { $paths[] = $r['path']; }
        }
        return $paths;
    }

    /**
     * Traz para o banco as cópias em arquivo de versões anteriores (índice v2) e, com B2 ligado, as deixa pendentes
     * para o worker. Itens já arquivados no B2 são mantidos como estão.
     * @return array{migrados:int, falhas:int, ausentes:int}
     */
    public static function migrarLegado(): array
    {
        $out = ['migrados' => 0, 'falhas' => 0, 'ausentes' => 0];
        foreach (JsonStore::files('archive/index/*.json') as $index) {
            $r = JsonStore::read($index);
            if ($r === null || !isset($r['path']) || !self::recognizes($r['path']) || ($r['versao'] ?? 0) === 3 || ($r['estado'] ?? '') === 'arquivado') { continue; }
            JsonStore::withLock(self::lockName($r['path']), function () use ($index, $r, &$out) {
                try {
                    $xml = isset($r['local_path']) ? XmlFiles::get($r['local_path']) : null;
                    if ($xml === null && isset($r['blob'])) { $xml = JsonStore::readBackend($r['blob']); }
                    if ($xml === null) { $out['ausentes']++; return; }
                    if (!hash_equals($r['sha256'], hash('sha256', $xml))) { throw new \RuntimeException('SHA-256 divergente no XML legado'); }
                    JsonStore::writeBackend($r['path'], $xml);
                    if (!hash_equals($r['sha256'], hash('sha256', (string) JsonStore::readBackend($r['path'])))) { throw new \RuntimeException('Conferência do XML gravado no banco falhou'); }
                    if (self::modo() === 's3') {
                        JsonStore::write($index, ['versao' => 3, 'path' => $r['path'], 'sha256' => $r['sha256'], 'bytes' => strlen($xml), 'provedor' => 's3',
                            'object_key' => $r['object_key'] ?? self::objectKey($r['path'], $r['sha256']), 'estado' => 'pendente', 'tentativas' => 0,
                            'proxima_tentativa' => 0, 'criado_em' => $r['criado_em'] ?? date('c')]);
                    } else {
                        JsonStore::deleteBackend($index);
                    }
                    if (isset($r['blob'])) { JsonStore::deleteBackend($r['blob']); }
                    $out['migrados']++;
                } catch (\Throwable $e) {
                    $out['falhas']++;
                    Logger::exception($e, 'xml.migracao_banco');
                }
            });
        }
        if ($out['migrados'] + $out['falhas'] + $out['ausentes'] > 0) { Logger::audit('xml.migracao_banco', $out['falhas'] + $out['ausentes'] === 0 ? 'sucesso' : 'parcial', $out); }
        return $out;
    }

    public static function status(?string $tenant = null): array
    {
        $out = ['provedor' => self::modo() === 's3' ? 'b2' : 'banco', 'b2_configurado' => S3Client::configurado(), 'pendentes' => 0, 'arquivados' => 0, 'com_falha' => 0, 'legado_pendente' => 0];
        foreach (JsonStore::files('archive/index/*.json') as $i) {
            $r = JsonStore::read($i);
            if ($r === null || ($tenant !== null && !str_starts_with($r['path'], 'tenants/' . $tenant . '/'))) { continue; }
            if (($r['estado'] ?? '') === 'arquivado') { $out['arquivados']++; }
            elseif (($r['versao'] ?? 0) === 3) { $out['pendentes']++; }
            else { $out['legado_pendente']++; }
            if (isset($r['erro'])) { $out['com_falha']++; }
        }
        return $out;
    }
}
