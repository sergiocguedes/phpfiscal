<?php
declare(strict_types=1);
namespace Tests;

final class EditionTest extends TestCase
{
    public function testPainelSoTemPaginasAdministrativasEModulosRetiradosRetornam404(): void
    {
        foreach (['/','/login','/empresas','/usuarios','/administracao','/empresa','/certificado','/chaves','/nfse','/perfil','/mfa','/painel.js','/painel.css','/docs','/openapi.yaml'] as $path) {
            $r=$this->call('GET',$path);
            self::assertSame(200,$r->status,$path.' '.$r->body);
        }
        foreach (['/nfe/nova','/nfse/nova','/marketplace','/recorrentes','/mcp','/api/v1/marketplace','/api/v1/produtos','/api/v1/clientes','/api/v1/webhooks','/api/v1/smtp','/api/v1/tenant/contadores','/api/v1/lotes'] as $path) {
            self::assertSame(404,$this->authed('GET',$path)->status,$path);
        }
        foreach (['/mcp','/api/v1/pedidos/split','/api/v1/recorrentes','/api/v1/webhooks/entrada'] as $path) {
            self::assertSame(404,$this->authed('POST',$path,'{}')->status,$path);
        }
    }

    public function testLoginPorEmpresaChavesECsrfMantidos(): void
    {
        $a=$this->signup('empresa-a@example.com');
        $this->configureSession($a);
        $key=$this->sess($a,'POST','/api/v1/chaves',json_encode(['nome'=>'PDV Caixa 01']));
        self::assertSame(201,$key->status,$key->body);
        $token=$this->json($key)['chave'];
        $r=$this->call('GET','/api/v1/config','',['Authorization'=>'Bearer '.$token]);
        self::assertSame(200,$r->status,$r->body);
        self::assertSame('11222333000181',$this->json($r)['cnpj']);
        self::assertSame(403,$this->call('POST','/api/v1/chaves',json_encode(['nome'=>'Sem CSRF']),['Cookie'=>$a['cookie']])->status);
        $login=$this->call('POST','/api/v1/auth/login',json_encode(['email'=>'empresa-a@example.com','senha'=>self::STRONG]));
        self::assertSame(200,$login->status,$login->body);
        $b=$this->signup('empresa-b@example.com','Empresa B','11444777000161','127.0.0.2');
        self::assertFalse($this->json($this->sess($b,'GET','/api/v1/config'))['configurado']);
        self::assertNotSame($a['tenant'],$b['tenant']);
        $id=$this->json($key)['id'];
        self::assertSame(404,$this->sess($b,'DELETE','/api/v1/chaves/'.$id)->status);
    }

    public function testOpenApiDocumentaApenasRotasPresentesETodasReferenciasExistem(): void
    {
        $doc=json_decode(file_get_contents(APP_ROOT.'/openapi.yaml'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('3.2.0-api',$doc['info']['version']);
        self::assertArrayHasKey('/api/v1/pdv/nfce',$doc['paths']);
        self::assertArrayHasKey('/api/v1/nfse',$doc['paths']);
        self::assertArrayNotHasKey('/api/v1/produtos',$doc['paths']);
        $walk=function($node)use(&$walk,$doc){foreach($node as $k=>$v){if($k==='$ref'&&str_starts_with($v,'#/components/')){$ref=explode('/',substr($v,2));$target=$doc;foreach($ref as $part){self::assertArrayHasKey($part,$target,$v);$target=$target[$part];}}elseif(is_array($v)){$walk($v);}}};
        $walk($doc);
    }

    public function testSwaggerPublicoNaoMostraRotasInternas(): void
    {
        $r=$this->call('GET','/openapi.yaml');
        self::assertSame(200,$r->status);
        $pub=json_decode($r->body,true,512,JSON_THROW_ON_ERROR);
        $metodos=['get','put','post','delete','patch'];
        foreach($pub['paths'] as $path=>$item){
            self::assertDoesNotMatchRegularExpression('#^/api/v1/(admin/|empresas|tenant|chaves|tributos/|tributacao|portal/(sessao|contexto|sair))#',$path,$path);
            foreach(array_intersect_key($item,array_flip($metodos)) as $op){self::assertArrayNotHasKey('x-interno',$op,$path);}
        }
        self::assertArrayHasKey('/api/v1/portal/acessos',$pub['paths']);
        self::assertArrayHasKey('/api/v1/nfe',$pub['paths']);
        self::assertStringNotContainsString('/api/v1/admin/',(string) json_encode($pub['info']));
        // As rotas internas continuam existindo (documentadas no arquivo completo) e protegidas.
        $doc=json_decode(file_get_contents(APP_ROOT.'/openapi.yaml'),true,512,JSON_THROW_ON_ERROR);
        self::assertTrue($doc['paths']['/api/v1/admin/usuarios']['get']['x-interno']);
        self::assertTrue($doc['paths']['/api/v1/portal/contexto']['get']['x-interno']);
        // tributação e tabelas IBS/CBS retiradas: os códigos fiscais vêm no JSON de cada nota
        self::assertSame([],array_values(array_filter(array_keys($doc['paths']),fn($x)=>str_starts_with($x,'/api/v1/tributacao')||str_starts_with($x,'/api/v1/admin/rtc'))));
        self::assertContains($this->call('GET','/api/v1/admin/usuarios')->status,[401,403]);
    }
}
