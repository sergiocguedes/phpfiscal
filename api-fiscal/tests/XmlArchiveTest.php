<?php
declare(strict_types=1);
namespace Tests;

use App\Storage\{JsonStore,XmlArchive,S3Client};
use App\Support\{Env,Paths};
use App\Tenancy\Tenant;

/**
 * XML fiscal: no banco (padrão) ou no Backblaze B2 com cópia pendente no banco até o worker enviar e conferir.
 * Legado em arquivo (storage/xml + índice v2) é lido e migrado com conferência de SHA-256.
 */
final class XmlArchiveTest extends TestCase
{
    private array $objects = [];
    private array $calls = [];
    private function path(): string { Tenant::set(Tenant::DEFAULT_ID);return Tenant::path('nfse/DPS-arquivo.xml'); }
    private function b2(): void
    {
        Env::set('XML_STORAGE','s3');
        foreach(['S3_ENDPOINT'=>'https://s3.us-east-005.backblazeb2.com','S3_REGION'=>'us-east-005','S3_BUCKET'=>'emissor','S3_ACCESS_KEY_ID'=>'0050000000000000000001','S3_SECRET_ACCESS_KEY'=>'segredo-de-teste'] as $k=>$v){Env::set($k,$v);}
        S3Client::$transport=function($m,$k,$b){$this->calls[]=$m;if($m==='PUT'){$this->objects[$k]=$b;return '';}if($m==='DELETE'){unset($this->objects[$k]);return '';}return $this->objects[$k]??throw new \RuntimeException('404');};
    }
    private function index(): string { return JsonStore::files('archive/index/*.json')[0]; }
    private function legado(string $path, string $xml): string
    {
        $sha=hash('sha256',$xml);$local='xml/'.substr($path,0,-4).'-'.$sha.'.xml';
        $file=Paths::storage($local);@mkdir(dirname($file),0700,true);file_put_contents($file,$xml);
        JsonStore::write('archive/index/'.hash('sha256',$path).'.json',['versao'=>2,'path'=>$path,'sha256'=>$sha,'bytes'=>strlen($xml),'provedor'=>'local','local_path'=>$local,'estado'=>'local']);
        return $file;
    }

    public function testModoBancoGravaSemIndiceNemRede(): void
    {
        $path=$this->path();
        JsonStore::writeRaw($path,'<xml>original</xml>');
        self::assertSame('<xml>original</xml>',JsonStore::readBackend($path));
        self::assertSame([],JsonStore::files('archive/index/*.json'));
        self::assertFalse(is_dir(Paths::storage('xml')),'Nada de XML em arquivo');
        XmlArchive::ciclo();self::assertSame([],$this->calls);
        self::assertContains($path,JsonStore::files(dirname($path).'/*.xml'));
        self::assertGreaterThan(0,JsonStore::mtime($path));
    }

    public function testB2EnviaConfereELiberaCopiaSoDepoisDaConfirmacao(): void
    {
        $this->b2();$path=$this->path();
        JsonStore::writeRaw($path,'<xml>original</xml>');
        self::assertSame([],$this->calls,'Emissão não depende de rede');
        self::assertSame('<xml>original</xml>',JsonStore::readBackend($path),'Cópia durável no banco enquanto pendente');
        self::assertSame(1,XmlArchive::status()['pendentes']);
        XmlArchive::ciclo();
        self::assertSame(['PUT','GET'],$this->calls);
        self::assertNull(JsonStore::readBackend($path),'Cópia do banco liberada após conferência');
        self::assertSame('<xml>original</xml>',JsonStore::readRaw($path),'Leitura vem do B2');
        self::assertSame(1,XmlArchive::status()['arquivados']);
        self::assertContains($path,JsonStore::files(dirname($path).'/*.xml'));
        $key=array_key_first($this->objects);
        self::assertStringStartsWith('fiscal/'.$path.'/',$key);
        JsonStore::writeRaw($path,'<xml>original</xml>');XmlArchive::ciclo();
        self::assertCount(1,$this->objects,'Regravação idêntica não cria outro objeto nem cópia');
        self::assertNull(JsonStore::readBackend($path));
        JsonStore::writeRaw($path,'<xml>autorizado</xml>');XmlArchive::ciclo();
        self::assertCount(2,$this->objects,'Nova versão tem chave imutável própria');
        self::assertSame('<xml>autorizado</xml>',JsonStore::readRaw($path));
    }

    public function testFalhaOuObjetoCorrompidoPreservaCopiaETentaDeNovo(): void
    {
        $this->b2();$path=$this->path();
        JsonStore::writeRaw($path,'<xml>preservado</xml>');
        S3Client::$transport=fn()=>throw new \RuntimeException('indisponível');
        XmlArchive::ciclo();
        self::assertSame(1,XmlArchive::status()['com_falha']);
        self::assertSame('<xml>preservado</xml>',JsonStore::readBackend($path));
        self::assertSame('<xml>preservado</xml>',JsonStore::readRaw($path));
        $i=$this->index();$r=JsonStore::read($i);self::assertGreaterThan(time(),$r['proxima_tentativa'],'Espera antes de tentar de novo');
        XmlArchive::ciclo();self::assertSame(1,JsonStore::read($i)['tentativas'],'Não tenta antes da hora');
        $r['proxima_tentativa']=0;JsonStore::write($i,$r);
        S3Client::$transport=fn($m)=>$m==='GET'?'<xml>corrompido</xml>':'';
        XmlArchive::ciclo();
        self::assertSame('pendente',JsonStore::read($i)['estado'],'Objeto divergente não libera a cópia');
        self::assertSame('<xml>preservado</xml>',JsonStore::readBackend($path));
        $r=JsonStore::read($i);$r['proxima_tentativa']=0;JsonStore::write($i,$r);
        S3Client::$transport=fn($m)=>$m==='GET'?'<xml>preservado</xml>':'';
        XmlArchive::ciclo();
        self::assertSame(1,XmlArchive::status()['arquivados']);self::assertSame(0,XmlArchive::status()['com_falha']);
        self::assertNull(JsonStore::readBackend($path));
    }

    public function testB2SemCredenciaisNaoEnviaEMantemNoBanco(): void
    {
        Env::set('XML_STORAGE','s3');$path=$this->path();
        foreach(['S3_ENDPOINT','S3_REGION','S3_BUCKET','S3_ACCESS_KEY_ID','S3_SECRET_ACCESS_KEY'] as $k){Env::set($k,'');}
        S3Client::$transport=function(){$this->calls[]='x';return '';};
        JsonStore::writeRaw($path,'<xml>a</xml>');XmlArchive::ciclo();
        self::assertSame([],$this->calls);self::assertSame('<xml>a</xml>',JsonStore::readRaw($path));
        self::assertFalse(XmlArchive::status()['b2_configurado']);
    }

    public function testVoltarParaBancoContinuaLendoDoB2EFalhaRemotaEExplicita(): void
    {
        $this->b2();$path=$this->path();
        JsonStore::writeRaw($path,'<xml>antes</xml>');XmlArchive::ciclo();
        Env::set('XML_STORAGE','banco');
        self::assertSame('<xml>antes</xml>',JsonStore::readRaw($path));
        S3Client::$transport=fn()=>throw new \RuntimeException('offline');
        try{JsonStore::readRaw($path);self::fail('Deveria indicar indisponibilidade');}
        catch(\App\Http\ApiException $e){self::assertSame(503,$e->status());}
        JsonStore::writeRaw($path,'<xml>depois</xml>');
        self::assertSame('<xml>depois</xml>',JsonStore::readRaw($path));
        self::assertSame([],JsonStore::files('archive/index/*.json'),'Nova versão no banco substitui a referência');
    }

    public function testLegadoEmArquivoVaiParaOBancoOuFicaPendenteParaOB2(): void
    {
        $path=$this->path();$this->legado($path,'<xml>antigo</xml>');
        self::assertSame('<xml>antigo</xml>',JsonStore::readRaw($path));
        self::assertSame(1,XmlArchive::status()['legado_pendente']);
        self::assertSame(['migrados'=>1,'falhas'=>0,'ausentes'=>0],XmlArchive::migrarLegado());
        self::assertSame('<xml>antigo</xml>',JsonStore::readBackend($path));
        self::assertSame([],JsonStore::files('archive/index/*.json'));
        self::assertSame(['migrados'=>0,'falhas'=>0,'ausentes'=>0],XmlArchive::migrarLegado(),'Idempotente');

        $this->b2();$p2=str_replace('DPS-arquivo','DPS-outro',$path);$this->legado($p2,'<xml>outro</xml>');
        self::assertSame(1,XmlArchive::migrarLegado()['migrados']);
        self::assertSame(1,XmlArchive::status()['pendentes'],'Com B2 ligado, legado vira pendente de envio');
        XmlArchive::ciclo();
        self::assertSame(1,XmlArchive::status()['arquivados']);self::assertSame('<xml>outro</xml>',JsonStore::readRaw($p2));
    }

    public function testLegadoCorrompidoOuAusenteNaoEMigradoNemApagado(): void
    {
        $path=$this->path();$file=$this->legado($path,'<xml>certo</xml>');
        file_put_contents($file,'<xml>adulterado</xml>');
        self::assertSame(1,XmlArchive::migrarLegado()['falhas']);
        self::assertSame(1,XmlArchive::status()['legado_pendente'],'Índice preservado para análise');
        unlink($file);
        self::assertSame(1,XmlArchive::migrarLegado()['ausentes']);
        try{JsonStore::readRaw($path);self::fail('Deveria indicar indisponibilidade');}
        catch(\App\Http\ApiException $e){self::assertSame(503,$e->status());}
    }

    public function testSeparaEmpresasEAmbientesENaoArquivaCertificados(): void
    {
        $this->b2();$a=$this->path();
        $b=str_replace('/ambientes/2/','/ambientes/1/',$a);
        $c=str_replace(Tenant::DEFAULT_ID,'t_1111111111111111',$a);
        foreach([$a,$b,$c] as $p){JsonStore::writeRaw($p,$p);}
        XmlArchive::ciclo();
        foreach([$a,$b,$c] as $p){self::assertSame($p,JsonStore::readRaw($p));}
        self::assertCount(3,$this->objects);
        self::assertFalse(XmlArchive::recognizes(Tenant::path('cert.enc')));
    }
}
