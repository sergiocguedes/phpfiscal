<?php
declare(strict_types=1);
namespace App\Storage;

use App\Support\Env;

/** Documentos JSONB e binários com chaves estáveis; locks compartilhados entre web e workers. */
final class PostgresStore
{
    private static ?\PDO $db = null;
    private static string $connection = '';

    /** PostgreSQL é o único armazenamento da aplicação. STORAGE_DRIVER=files existe só para a suíte de testes. */
    public static function enabled(): bool { return Env::get('STORAGE_DRIVER', 'pgsql') !== 'files'; }
    public static function ns(): string { return (string) Env::get('STORAGE_NAMESPACE', 'fiscal'); }
    public static function db(): \PDO
    {
        $dsn = (string) Env::get('DATABASE_DSN', 'pgsql:host=db;port=5432;dbname=fiscal');
        $user = (string) Env::get('DATABASE_USER', 'fiscal');
        $password = (string) Env::get('DATABASE_PASSWORD', '');
        $schema=(string)Env::get('DATABASE_SCHEMA','public');
        if(!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D',$schema)){throw new \RuntimeException('DATABASE_SCHEMA inválido');}
        if(preg_match('/(?:^|;)port=6543(?:;|$)/',$dsn)){
            throw new \RuntimeException('Pooler de transação (porta 6543) não suporta os locks desta API; use conexão direta');
        }
        $key = hash('sha256', $dsn . $user . $password . $schema);
        if (self::$db === null || self::$connection !== $key) {
            self::$db = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES=>false]);
            self::$connection = $key;
            self::$db->exec("SET lock_timeout = '15s'");
            self::$db->exec('SET search_path TO "'.$schema.'"');
        }
        return self::$db;
    }
    public static function migrate(): void
    {
        $schema=(string)Env::get('DATABASE_SCHEMA','public');
        self::db()->exec('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
        self::db()->exec((string) file_get_contents(APP_ROOT . '/resources/sql/001-storage.sql'));
    }
    public static function read(string $path): ?string
    {
        if($path==='system/api-config.json'){$q=self::db()->prepare('SELECT config::text FROM fiscal_api_settings WHERE namespace=?');$q->execute([self::ns()]);$v=$q->fetchColumn();return $v===false?null:$v;}
        $q=self::db()->prepare('SELECT content::text FROM fiscal_documents WHERE namespace=? AND path=? UNION ALL SELECT encode(content,\'base64\') FROM fiscal_blobs WHERE namespace=? AND path=?');
        $q->execute([self::ns(),$path,self::ns(),$path]); $row=$q->fetchColumn();
        if ($row === false) { return null; }
        return str_ends_with($path,'.json') ? $row : (string) base64_decode($row, true);
    }
    public static function write(string $path,string $content): void
    {
        if($path==='system/api-config.json'){json_decode($content,true,512,JSON_THROW_ON_ERROR);$q=self::db()->prepare('INSERT INTO fiscal_api_settings(namespace,config) VALUES(?,?::jsonb) ON CONFLICT(namespace) DO UPDATE SET config=EXCLUDED.config,updated_at=clock_timestamp()');$q->execute([self::ns(),$content]);return;}
        if (str_ends_with($path,'.json')) {
            json_decode($content,true,512,JSON_THROW_ON_ERROR);
            $q=self::db()->prepare('INSERT INTO fiscal_documents(namespace,path,content) VALUES(?,?,?::jsonb) ON CONFLICT(namespace,path) DO UPDATE SET content=EXCLUDED.content,updated_at=clock_timestamp()');
            $q->execute([self::ns(),$path,$content]);
        } else {
            $q=self::db()->prepare("INSERT INTO fiscal_blobs(namespace,path,content) VALUES(?,?,decode(?,'base64')) ON CONFLICT(namespace,path) DO UPDATE SET content=EXCLUDED.content,updated_at=clock_timestamp()");
            $q->execute([self::ns(),$path,base64_encode($content)]);
        }
    }
    public static function delete(string $path): void
    {
        if($path==='system/api-config.json'){$q=self::db()->prepare('DELETE FROM fiscal_api_settings WHERE namespace=?');$q->execute([self::ns()]);return;}
        foreach(['fiscal_documents','fiscal_blobs'] as $table) { $q=self::db()->prepare("DELETE FROM $table WHERE namespace=? AND path=?");$q->execute([self::ns(),$path]); }
    }
    public static function files(string $pattern): array
    {
        $prefix=substr($pattern,0,strcspn($pattern,'*?['));
        $q=self::db()->prepare("SELECT path,updated_at FROM fiscal_documents WHERE namespace=? AND starts_with(path,?) UNION ALL SELECT path,updated_at FROM fiscal_blobs WHERE namespace=? AND starts_with(path,?) ORDER BY updated_at DESC,path");
        $q->execute([self::ns(),$prefix,self::ns(),$prefix]);$out=[];
        foreach($q as $row){if(fnmatch($pattern,$row['path'],FNM_PATHNAME)){$out[]=$row['path'];}}
        return $out;
    }
    public static function mtime(string $path): int
    {
        $q=self::db()->prepare('SELECT extract(epoch FROM updated_at)::bigint FROM fiscal_documents WHERE namespace=? AND path=? UNION ALL SELECT extract(epoch FROM updated_at)::bigint FROM fiscal_blobs WHERE namespace=? AND path=?');$q->execute([self::ns(),$path,self::ns(),$path]);return (int)$q->fetchColumn();
    }
    public static function lock(string $name,callable $fn): mixed
    {
        $key=hash('sha256',self::ns().':'.$name);
        $a=hexdec(substr($key,0,8));$b=hexdec(substr($key,8,8));
        if($a>2147483647){$a-=4294967296;}if($b>2147483647){$b-=4294967296;}
        $db=self::db();$q=$db->prepare('SELECT pg_advisory_lock(?::int,?::int)');$q->execute([$a,$b]);
        try{return $fn();}finally{$q=$db->prepare('SELECT pg_advisory_unlock(?::int,?::int)');$q->execute([$a,$b]);}
    }
}
