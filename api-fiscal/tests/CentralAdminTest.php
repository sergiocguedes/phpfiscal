<?php
declare(strict_types=1);
namespace Tests;

use App\Auth\CentralAccess;
use App\Storage\JsonStore;
use App\Support\Env;
use App\Tenancy\{Tenant,TenantStore,FiscalScope};

final class CentralAdminTest extends TestCase
{
    private function admin(): array
    {
        Env::set('CENTRAL_AUTH','true');
        CentralAccess::createUser(['nome'=>'Administrador','email'=>'admin@example.com','senha'=>self::STRONG,'acesso_central'=>'admin']);
        $r=$this->call('POST','/api/v1/auth/login',json_encode(['email'=>'admin@example.com','senha'=>self::STRONG]));
        self::assertSame(200,$r->status,$r->body);return $this->sessionFrom($r,'admin@example.com');
    }
    public function testNoPublicSignupAndCentralCompanyManagementBySessionAndApi(): void
    {
        self::assertSame(404,$this->call('POST','/api/v1/auth/cadastro','{}')->status);
        self::assertSame(404,$this->call('GET','/cadastro')->status);
        self::assertSame(403,$this->authed('GET','/api/v1/empresas')->status);
        $s=$this->admin();
        $before=count(TenantStore::ids());
        self::assertSame(422,$this->sess($s,'POST','/api/v1/empresas',json_encode(['nome'=>'Inválida','cnpj'=>'11444777000161','ativa'=>null]))->status);
        self::assertSame($before,count(TenantStore::ids()),'Dados inválidos não deixam empresa órfã');
        self::assertSame(403,$this->call('POST','/api/v1/empresas','{}',['Cookie'=>$s['cookie']])->status);
        $r=$this->sess($s,'POST','/api/v1/empresas',json_encode(['nome'=>'Empresa Teste','cnpj'=>'11222333000181']));
        self::assertSame(201,$r->status,$r->body);$tid=$this->json($r)['id'];
        self::assertSame(422,$this->sess($s,'GET','/api/v1/config')->status);
        self::assertSame(200,$this->sess($s,'GET','/api/v1/config','',['X-Empresa-Id'=>$tid])->status);
        self::assertSame(403,$this->authed('GET','/api/v1/config','',['X-Empresa-Id'=>$tid])->status);
        self::assertSame(409,$this->sess($s,'POST','/api/v1/empresas',json_encode(['nome'=>'Outra','cnpj'=>'11222333000181']))->status);
        $k=$this->json($this->sess($s,'POST','/api/v1/admin/chaves',json_encode(['nome'=>'ERP administrativo'])));
        $headers=['Authorization'=>'Bearer '.$k['chave']];
        self::assertSame(200,$this->call('GET','/api/v1/empresas','',$headers)->status);
        self::assertSame(200,$this->call('GET','/api/v1/config','',$headers+['X-Empresa-Id'=>$tid])->status);
        $r=$this->call('POST','/api/v1/chaves',json_encode(['nome'=>'PDV da empresa']),$headers+['X-Empresa-Id'=>$tid]);
        self::assertSame(201,$r->status,$r->body);$companyKey=$this->json($r)['chave'];
        self::assertSame(200,$this->call('GET','/api/v1/config','',['Authorization'=>'Bearer '.$companyKey])->status);
        self::assertSame(403,$this->call('GET','/api/v1/empresas','',['Authorization'=>'Bearer '.$companyKey])->status);
        self::assertSame(204,$this->sess($s,'DELETE','/api/v1/admin/chaves/'.$k['id'])->status);
        self::assertSame(401,$this->call('GET','/api/v1/empresas','',$headers)->status);
    }
    public function testBlockedCompanyKeepsQueriesAndSettingsButStopsEmission(): void
    {
        $this->configure();
        TenantStore::settings(Tenant::DEFAULT_ID,['ativa'=>false]);
        self::assertSame(200,$this->authed('GET','/api/v1/config')->status);
        foreach(['/api/v1/nfe','/api/v1/nfse','/api/v1/pdv/nfce'] as $path){
            $r=$this->authed('POST',$path,'{}');self::assertSame(403,$r->status,$r->body);self::assertSame('empresa_inativa',$this->json($r)['erro']['codigo']);
        }
    }
    public function testEnvironmentSeparatesCountersDocumentsAndIdempotency(): void
    {
        $this->configure();$this->authed('GET','/api/v1/config');
        $path2=Tenant::path('nfe/mesma-nota.json');JsonStore::write($path2,['ambiente_nfe'=>2]);
        $config2=JsonStore::read(Tenant::path('config.json'));
        Env::set('ALLOW_PRODUCAO','true');TenantStore::settings(Tenant::DEFAULT_ID,['ambiente_nfe'=>1]);
        self::assertNull(JsonStore::read(Tenant::path('config.json')));
        self::assertNull(JsonStore::read(Tenant::path('nfe/mesma-nota.json')));
        self::assertNotSame($path2,Tenant::path('nfe/mesma-nota.json'));
        JsonStore::write(Tenant::path('nfe/mesma-nota.json'),['ambiente_nfe'=>1]);
        TenantStore::settings(Tenant::DEFAULT_ID,['ambiente_nfe'=>2]);
        self::assertSame($config2,JsonStore::read(Tenant::path('config.json')));
        self::assertSame(['ambiente_nfe'=>2],JsonStore::read($path2));
    }
    public function testOperatorsCannotManageUsersAndLastAdminCannotBeBlocked(): void
    {
        $s=$this->admin();$admin=$s['user'];
        $r=$this->sess($s,'PUT','/api/v1/admin/usuarios/'.$admin,json_encode(['ativo'=>false]));self::assertSame(409,$r->status,$r->body);
        $u=CentralAccess::createUser(['nome'=>'Operador','email'=>'op@example.com','senha'=>self::STRONG,'acesso_central'=>'operador']);
        $login=$this->call('POST','/api/v1/auth/login',json_encode(['email'=>'op@example.com','senha'=>self::STRONG]));
        $op=$this->sessionFrom($login,'op@example.com');
        self::assertSame(200,$this->sess($op,'GET','/api/v1/empresas')->status);
        self::assertSame(403,$this->sess($op,'GET','/api/v1/admin/usuarios')->status);
        self::assertSame(403,$this->sess($op,'POST','/api/v1/admin/chaves',json_encode(['nome'=>'Negado']))->status);
        self::assertSame(200,$this->sess($s,'PUT','/api/v1/admin/usuarios/'.$u['id'],json_encode(['ativo'=>false]))->status);
        self::assertSame(401,$this->sess($op,'GET','/api/v1/empresas')->status);
        self::assertSame(401,$this->call('POST','/api/v1/auth/login',json_encode(['email'=>'op@example.com','senha'=>self::STRONG]))->status);
    }
    public function testLegacyOwnersDoNotBecomeGlobalAdministrators(): void
    {
        $s=$this->signup('legacy@example.com');Env::set('CENTRAL_AUTH','true');
        self::assertSame(401,$this->sess($s,'GET','/api/v1/empresas')->status);
        self::assertSame(401,$this->call('POST','/api/v1/auth/login',json_encode(['email'=>'legacy@example.com','senha'=>self::STRONG]))->status);
    }
}
