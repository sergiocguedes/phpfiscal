<?php
declare(strict_types=1);
namespace Tests;

use App\Storage\{JsonStore,XmlArchive,S3Client};
use App\Support\Env;
use App\Tenancy\Tenant;

final class XmlArchiveTest extends TestCase
{
    private function path(): string { Tenant::set(Tenant::DEFAULT_ID);return Tenant::path('nfse/DPS-arquivo.xml'); }
    private function pendentes(): array { return glob(\App\Support\Paths::storage('xml-pendentes/tenants/*/ambientes/*/nfse/*.xml')) ?: []; }
    public function testUploadVerifiedAndPendingCopyReleasedOnlyAfterConfirmation(): void
    {
        Env::set('XML_STORAGE','s3');$path=$this->path();$objects=[];$calls=[];
        S3Client::$transport=function($method,$key,$body)use(&$objects,&$calls){$calls[]=$method;if($method==='PUT'){$objects[$key]=$body;return '';}return $objects[$key];};
        JsonStore::writeRaw($path,'<xml>original</xml>');
        self::assertSame('<xml>original</xml>',JsonStore::readRaw($path));
        self::assertSame([], $calls,'Fila não depende de rede no caminho da emissão');
        XmlArchive::ciclo();self::assertSame(['PUT','GET'],$calls);
        self::assertCount(0,JsonStore::files('archive/payloads/*'));
        self::assertSame('<xml>original</xml>',JsonStore::readRaw($path));
        self::assertSame(1,XmlArchive::status()['arquivados']);
        JsonStore::writeRaw($path,'<xml>original</xml>');XmlArchive::ciclo();
        self::assertCount(1,$objects,'Regravação idêntica não cria outro objeto');
        JsonStore::writeRaw($path,'<xml>autorizado</xml>');XmlArchive::ciclo();
        self::assertCount(2,$objects,'Nova versão tem chave imutável');
        self::assertSame('<xml>autorizado</xml>',JsonStore::readRaw($path));
    }
    public function testFailedOrCorruptRemoteRetainsDurablePayloadAndRetries(): void
    {
        Env::set('XML_STORAGE','s3');$path=$this->path();
        JsonStore::writeRaw($path,'<xml>preservado</xml>');
        S3Client::$transport=fn()=>throw new \RuntimeException('indisponível');
        XmlArchive::ciclo();self::assertSame(1,XmlArchive::status()['com_falha']);
        self::assertCount(1,$this->pendentes());
        self::assertSame('<xml>preservado</xml>',JsonStore::readRaw($path));
        $i=JsonStore::files('archive/index/*.json')[0];$r=JsonStore::read($i);$r['proxima_tentativa']=0;JsonStore::write($i,$r);
        S3Client::$transport=fn()=>'<xml>corrompido</xml>';
        XmlArchive::ciclo();self::assertCount(1,$this->pendentes());
        self::assertSame('<xml>preservado</xml>',JsonStore::readRaw($path));
        $r=JsonStore::read($i);$r['proxima_tentativa']=0;JsonStore::write($i,$r);
        S3Client::$transport=fn($m)=>$m==='GET'?'<xml>preservado</xml>':'';
        XmlArchive::ciclo();self::assertSame(1,XmlArchive::status()['arquivados']);self::assertCount(0,$this->pendentes());
    }
    public function testArchiveSeparatesCompaniesAndEnvironmentsAndDoesNotArchiveCertificates(): void
    {
        Env::set('XML_STORAGE','s3');$a=$this->path();
        $b=str_replace('/ambientes/2/','/ambientes/1/',$a);
        $c=str_replace(Tenant::DEFAULT_ID,'t_1111111111111111',$a);
        foreach([$a,$b,$c] as $p){JsonStore::writeRaw($p,$p);}
        self::assertSame($a,JsonStore::readRaw($a));self::assertSame($b,JsonStore::readRaw($b));
        self::assertCount(3,JsonStore::files('archive/index/*.json'));
        self::assertFalse(XmlArchive::handles(Tenant::path('cert.enc')));
    }

    public function testPausingArchiveKeepsAccessAndNewVersionsAndRemoteFailureIsExplicit(): void
    {
        Env::set('XML_STORAGE','s3');$path=$this->path();$objects=[];
        S3Client::$transport=function($m,$k,$b)use(&$objects){if($m==='PUT'){$objects[$k]=$b;return '';}return $objects[$k];};
        JsonStore::writeRaw($path,'<xml>antes</xml>');XmlArchive::ciclo();
        Env::set('XML_STORAGE','local');self::assertSame('<xml>antes</xml>',JsonStore::readRaw($path));
        S3Client::$transport=fn()=>throw new \RuntimeException('offline');
        try{JsonStore::readRaw($path);self::fail('Deveria indicar indisponibilidade');}
        catch(\App\Http\ApiException $e){self::assertSame(503,$e->status());}
        JsonStore::writeRaw($path,'<xml>depois</xml>');
        self::assertSame('<xml>depois</xml>',JsonStore::readRaw($path));
        self::assertSame(0,XmlArchive::status()['pendentes']);
        self::assertSame(1,XmlArchive::status()['locais']);
    }

    public function testLocalXmlIsAFileAndDatabaseHasOnlyReferenceIncludingAttempts(): void
    {
        $path = $this->path();
        foreach ([$path, str_replace('/nfse/', '/nfse/tentativas/', $path)] as $p) {
            JsonStore::writeRaw($p, '<xml>primeiro</xml>');
            $ref = XmlArchive::reference($p);
            self::assertSame('local', $ref['estado']);
            self::assertSame('<xml>primeiro</xml>', file_get_contents(\App\Storage\XmlFiles::path($ref['local_path'])));
            self::assertContains($p, JsonStore::files(dirname($p).'/*.xml'));
            self::assertFalse(isset($ref['blob']));
            if (\App\Storage\PostgresStore::enabled()) { self::assertNull(\App\Storage\PostgresStore::read($p)); }
            JsonStore::writeRaw($p, '<xml>segundo</xml>');
            self::assertSame('<xml>segundo</xml>', JsonStore::readRaw($p));
            self::assertSame('<xml>primeiro</xml>', file_get_contents(\App\Storage\XmlFiles::path($ref['local_path'])), 'Versão anterior preservada');
        }
        self::assertFalse(XmlArchive::recognizes(str_replace('/nfse/', '/nfse/../', $path)));
    }

    public function testS3QueueStoresNoXmlBytesInDatabase(): void
    {
        Env::set('XML_STORAGE', 's3'); $path = $this->path();
        JsonStore::writeRaw($path, '<xml>fila privada</xml>');
        $ref = XmlArchive::reference($path);
        self::assertSame('pendente', $ref['estado']);
        self::assertFalse(isset($ref['blob']));
        self::assertSame('<xml>fila privada</xml>', file_get_contents(\App\Storage\XmlFiles::path($ref['local_path'])));
        self::assertCount(0, JsonStore::files('archive/payloads/*'));
        if (\App\Storage\PostgresStore::enabled()) { self::assertNull(\App\Storage\PostgresStore::read($path)); }
    }
}
