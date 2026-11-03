<?php
declare(strict_types=1);
namespace Tests;

use App\Storage\{PostgresStore,JsonStore};
use App\Support\Paths;

final class PostgresStoreTest extends TestCase
{
    private function cli(array $args): int
    {
        $pipes=[];$p=proc_open([PHP_BINARY,APP_ROOT.'/bin/backup-banco.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return proc_close($p);
    }
    public function testBackupImportAndConflictRollback(): void
    {
        if(!PostgresStore::enabled()){self::markTestSkipped('Executar com FISCAL_TEST_PG=1');}
        JsonStore::write('a.json',['valor'=>1]);JsonStore::write('b.json',['valor'=>2]);JsonStore::writeRaw('cert-teste.enc',"binario\0cifrado");
        JsonStore::write('system/api-config.json',['responsavel_tecnico'=>null,'backup_teste'=>true]);
        $file=Paths::storage('tmp/export.jsonl');
        self::assertSame(0,$this->cli(['exportar',$file]));
        JsonStore::delete('a.json');JsonStore::write('b.json',['valor'=>99]);
        self::assertSame(1,$this->cli(['importar',$file]));
        self::assertNull(JsonStore::read('a.json'),'Escrita anterior ao conflito deve ter rollback');
        self::assertSame(['valor'=>99],JsonStore::read('b.json'));
        JsonStore::delete('b.json');JsonStore::delete('cert-teste.enc');
        JsonStore::delete('system/api-config.json');
        self::assertSame(0,$this->cli(['importar',$file]));
        self::assertSame(['valor'=>1],JsonStore::read('a.json'));self::assertSame(['valor'=>2],JsonStore::read('b.json'));
        self::assertSame("binario\0cifrado",JsonStore::readRaw('cert-teste.enc'));
        self::assertTrue(JsonStore::read('system/api-config.json')['backup_teste']);
        self::assertSame(0,$this->cli(['importar',$file]),'Importação idêntica pode ser repetida');
    }
}
