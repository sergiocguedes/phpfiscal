<?php
declare(strict_types=1);
namespace Tests;

use App\Auth\CentralAccess;
use App\Fiscal\ApiConfigService;
use App\Nfe\ConfigService;
use App\Storage\JsonStore;
use App\Tenancy\{Tenant,TenantStore,FiscalScope};

final class ModelConfigurationTest extends TestCase
{
    public function testPrestadorSemIeGeraDpsMasNaoNfe(): void
    {
        $this->configure();
        $cfg=$this->json($this->authed('GET','/api/v1/config'));unset($cfg['ie']);
        $saved=$this->authed('PUT','/api/v1/config',json_encode($cfg));
        self::assertSame(200,$saved->status,$saved->body);self::assertNull($this->json($saved)['ie']);
        $provider=$this->authed('PUT','/api/v1/nfse/config',json_encode(['opcao_simples_nacional'=>3,'regime_apuracao_sn'=>1,'regime_especial'=>0,'inscricao_municipal'=>'12345','serie'=>1]));
        self::assertSame(200,$provider->status,$provider->body);
        $dry=$this->authed('POST','/api/v1/nfse?dry_run=1',json_encode(['servico'=>['codigo_tributacao_nacional'=>'080201','descricao'=>'Curso teste sem IE'],'valores'=>['servico'=>100]]));
        self::assertSame(200,$dry->status,$dry->body);self::assertTrue($this->json($dry)['valida']);
        self::assertStringNotContainsString('<IE>',$this->json($dry)['xml']);
        $nfe=$this->authed('POST','/api/v1/nfe?dry_run=1',$this->samplePayload());
        self::assertSame(422,$nfe->status,$nfe->body);self::assertStringContainsString('config.ie',$nfe->body);
        $cfg['ie']='texto-invalido';self::assertSame(422,$this->authed('PUT','/api/v1/config',json_encode($cfg))->status);
    }

    public function testChaveDaEmpresaConfiguraSeuAmbienteSemAdministrarOutraEmpresa(): void
    {
        $before=$this->json($this->authed('GET','/api/v1/tenant'));
        $r=$this->authed('PUT','/api/v1/tenant','{"ambiente_nfse":1}');
        self::assertSame(200,$r->status,$r->body);$after=$this->json($r);
        self::assertSame(1,$after['ambiente_nfse']);self::assertSame($before['ambiente_nfce'],$after['ambiente_nfce']);
        self::assertSame(422,$this->authed('PUT','/api/v1/tenant','{"ambiente_nfse":3}')->status);
        self::assertSame(422,$this->authed('PUT','/api/v1/tenant','{"ativa":true}')->status);
        self::assertSame($before['cnpj'],$this->json($this->authed('GET','/api/v1/tenant'))['cnpj']);
    }

    public function testAmbientesIndependentesPelaApiSemTravaGlobal(): void
    {
        $this->configure();$key=CentralAccess::createKey('Admin de teste','teste')['chave'];
        $r=$this->call('PUT','/api/v1/empresas/'.Tenant::DEFAULT_ID,json_encode(['ambiente_nfe'=>1,'ambiente_nfce'=>2,'ambiente_nfse'=>1]),['Authorization'=>'Bearer '.$key]);
        self::assertSame(200,$r->status,$r->body);$t=$this->json($r);self::assertArrayNotHasKey('ambiente',$t);
        self::assertSame(1,$t['ambiente_nfe']);self::assertSame(2,$t['ambiente_nfce']);self::assertSame(1,$t['ambiente_nfse']);
        $nfe=$this->authed('POST','/api/v1/nfe?dry_run=1',$this->samplePayload());
        self::assertSame(200,$nfe->status,$nfe->body);self::assertStringContainsString('<tpAmb>1</tpAmb>',$this->json($nfe)['xml']);
        $nfce=$this->authed('POST','/api/v1/nfe?dry_run=1',file_get_contents(APP_ROOT.'/examples/nfce-exemplo.json'));
        self::assertSame(200,$nfce->status,$nfce->body);self::assertStringContainsString('<tpAmb>2</tpAmb>',$this->json($nfce)['xml']);
        $nfse=$this->authed('GET','/api/v1/nfse/config');self::assertSame(1,$this->json($nfse)['ambiente']);
        $bad=$this->call('PUT','/api/v1/empresas/'.Tenant::DEFAULT_ID,'{"ambiente_nfce":3}',['Authorization'=>'Bearer '.$key]);self::assertSame(422,$bad->status);
    }

    public function testNumeracaoECscNaoSeMisturamEntreModelosEAmbientes(): void
    {
        $this->configure();$cfg=$this->json($this->authed('GET','/api/v1/config'));
        $cfg['csc']='12345678901234567890123456789012';$cfg['csc_id']='1';
        self::assertSame(200,$this->authed('PUT','/api/v1/config',json_encode($cfg))->status);
        Tenant::set(Tenant::DEFAULT_ID);TenantStore::settings(Tenant::DEFAULT_ID,['ambiente_nfe'=>1,'ambiente_nfce'=>2]);
        self::assertSame(1,FiscalScope::forModelo(55,fn()=>ConfigService::reserveNumber(55)));
        self::assertSame(2,FiscalScope::forModelo(55,fn()=>ConfigService::reserveNumber(55)));
        self::assertSame(1,FiscalScope::forModelo(65,fn()=>ConfigService::reserveNumber(65)));
        self::assertNotNull(ConfigService::csc());
        $combined=$this->json($this->authed('GET','/api/v1/config'));
        self::assertSame(200,$this->authed('PUT','/api/v1/config',json_encode($combined))->status);
        TenantStore::settings(Tenant::DEFAULT_ID,['ambiente_nfce'=>1]);self::assertNull(ConfigService::csc());
        self::assertSame(1,FiscalScope::forModelo(65,fn()=>ConfigService::reserveNumber(65)));
        self::assertSame(3,FiscalScope::forModelo(55,fn()=>ConfigService::reserveNumber(55)));
        TenantStore::settings(Tenant::DEFAULT_ID,['ambiente_nfce'=>2]);self::assertNotNull(ConfigService::csc());
        self::assertSame(2,FiscalScope::forModelo(65,fn()=>ConfigService::reserveNumber(65)));
    }

    public function testResponsavelTecnicoGeralSomenteAdministrador(): void
    {
        $this->configure();$rt=['cnpj'=>'11444777000161','contato'=>'Equipe Fiscal','email'=>'fiscal@example.com','fone'=>'11999999999'];
        self::assertSame(403,$this->authed('PUT','/api/v1/admin/config',json_encode(['responsavel_tecnico'=>$rt]))->status);
        $key=CentralAccess::createKey('Administrador','teste')['chave'];
        $r=$this->call('PUT','/api/v1/admin/config',json_encode(['responsavel_tecnico'=>$rt]),['Authorization'=>'Bearer '.$key]);self::assertSame(200,$r->status,$r->body);
        $cfg=$this->json($this->authed('GET','/api/v1/config'));self::assertArrayNotHasKey('responsavel_tecnico',$cfg);
        $r=$this->authed('POST','/api/v1/nfe?dry_run=1',$this->samplePayload());self::assertSame(200,$r->status,$r->body);
        self::assertStringContainsString('<infRespTec><CNPJ>11444777000161</CNPJ>',$this->json($r)['xml']);
        $cfg['responsavel_tecnico']=$rt;self::assertSame(422,$this->authed('PUT','/api/v1/config',json_encode($cfg))->status);
    }

    public function testCadastroDeServicosRecusadoEJsonExplicitoObrigatorio(): void
    {
        $this->configure(4);$r=$this->authed('PUT','/api/v1/nfse/config',json_encode(['opcao_simples_nacional'=>2,'servicos'=>[]]));self::assertSame(422,$r->status);
        $r=$this->authed('PUT','/api/v1/nfse/config',json_encode(['opcao_simples_nacional'=>2]));self::assertSame(200,$r->status,$r->body);self::assertArrayNotHasKey('servicos',$this->json($r));
        $r=$this->authed('POST','/api/v1/nfse?dry_run=1',json_encode(['servico_id'=>'svc_antigo','valores'=>['servico'=>100]]));self::assertSame(422,$r->status);self::assertStringContainsString('servico_id',$r->body);
    }
}
