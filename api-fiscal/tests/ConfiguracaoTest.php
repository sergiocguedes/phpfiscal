<?php
declare(strict_types=1);
namespace Tests;

use App\Auth\CentralAccess;
use App\Storage\JsonStore;
use App\Support\{Env,Settings};

/** Configuração do sistema cifrada no banco: leitura por Env, segredos, validação e acesso. */
final class ConfiguracaoTest extends TestCase
{
    private function admin(string $acesso = 'admin', string $email = 'admin@example.com'): array
    {
        Env::set('CENTRAL_AUTH','true');
        CentralAccess::createUser(['nome'=>'Pessoa','email'=>$email,'senha'=>self::STRONG,'acesso_central'=>$acesso]);
        $r=$this->call('POST','/api/v1/auth/login',json_encode(['email'=>$email,'senha'=>self::STRONG]));
        self::assertSame(200,$r->status,$r->body);return $this->sessionFrom($r,$email);
    }
    private function item(array $view, string $k): array { foreach($view['itens'] as $i){if($i['chave']===$k){return $i;}} self::fail("sem $k"); }

    public function testValorDoBancoValeParaEnvEFicaCifrado(): void
    {
        $s=$this->admin();
        self::assertSame(1800,Env::int('SESSION_IDLE_SECONDS',1800));
        $r=$this->sess($s,'PUT','/api/v1/admin/configuracao/email',json_encode(['SMTP_HOST'=>'smtp.exemplo.com.br','SMTP_PORT'=>'465','SMTP_SECURE'=>'ssl','SMTP_USER'=>'envio','SMTP_PASSWORD'=>'Senha-Super-Secreta-123','SMTP_FROM'=>'nfe@exemplo.com.br']));
        self::assertSame(200,$r->status,$r->body);
        self::assertSame('smtp.exemplo.com.br',Env::get('SMTP_HOST'));
        self::assertSame(465,Env::int('SMTP_PORT',587));
        self::assertSame('Senha-Super-Secreta-123',Env::get('SMTP_PASSWORD'));
        $raw=json_encode(JsonStore::read(Settings::PATH));
        self::assertStringNotContainsString('smtp.exemplo.com.br',$raw,'Documento inteiro cifrado');
        self::assertStringNotContainsString('Senha-Super',$raw);
        $pw=$this->item($this->json($r),'SMTP_PASSWORD');
        self::assertSame('',$pw['valor']);self::assertTrue($pw['definido']);
        self::assertStringNotContainsString('Senha-Super',$this->sess($s,'GET','/api/v1/admin/configuracao')->body,'Segredo nunca volta à tela');
        // salvar o grupo sem senha mantém a senha; limpar exige pedido explícito
        $this->sess($s,'PUT','/api/v1/admin/configuracao/email',json_encode(['SMTP_HOST'=>'smtp2.exemplo.com.br','SMTP_PASSWORD'=>'']));
        Settings::reset();
        self::assertSame('Senha-Super-Secreta-123',Env::get('SMTP_PASSWORD'));
        self::assertSame(587,Env::int('SMTP_PORT',587),'Campo omitido volta ao padrão');
        $this->sess($s,'PUT','/api/v1/admin/configuracao/email',json_encode(['limpar_segredos'=>['SMTP_PASSWORD']]));
        Settings::reset();
        self::assertNull(Env::get('SMTP_PASSWORD'));
    }

    public function testValidacaoERejeicaoSemGravarNada(): void
    {
        $s=$this->admin();
        $r=$this->sess($s,'PUT','/api/v1/admin/configuracao/sessao',json_encode(['SESSION_IDLE_SECONDS'=>'10','LOGIN_MAX_FAILURES'=>'abc']));
        self::assertSame(422,$r->status,$r->body);
        $campos=array_column($this->json($r)['erro']['detalhes'],'campo');
        self::assertContains('SESSION_IDLE_SECONDS',$campos);self::assertContains('LOGIN_MAX_FAILURES',$campos);
        self::assertNull(JsonStore::read(Settings::PATH));
        self::assertSame(422,$this->sess($s,'PUT','/api/v1/admin/configuracao/acesso',json_encode(['APP_URL'=>'javascript:alert(1)']))->status);
        self::assertSame(422,$this->sess($s,'PUT','/api/v1/admin/configuracao/acesso',json_encode(['TRUSTED_PROXIES'=>'10.0.0.1,abc']))->status);
        self::assertSame(422,$this->sess($s,'PUT','/api/v1/admin/configuracao/fiscal',json_encode(['NFCE_CONTINGENCIA_UFS'=>'AM,XYZ']))->status);
        self::assertSame(404,$this->sess($s,'PUT','/api/v1/admin/configuracao/inexistente','{}')->status);
        $ok=$this->sess($s,'PUT','/api/v1/admin/configuracao/fiscal',json_encode(['NFCE_CONTINGENCIA_UFS'=>' am, pa ','NFSE_TIMEOUT'=>'90']));
        self::assertSame(200,$ok->status,$ok->body);
        self::assertSame('AM,PA',Env::get('NFCE_CONTINGENCIA_UFS'));self::assertSame(90,Env::int('NFSE_TIMEOUT',60));
    }

    public function testSomenteAdministradorCentralPelaSessao(): void
    {
        self::assertSame(401,$this->call('GET','/api/v1/admin/configuracao')->status);
        self::assertSame(403,$this->authed('GET','/api/v1/admin/configuracao')->status,'Chave de empresa recusada');
        $adm=$this->admin();
        $k=$this->json($this->sess($adm,'POST','/api/v1/admin/chaves',json_encode(['nome'=>'ERP administrativo'])));
        self::assertSame(403,$this->call('GET','/api/v1/admin/configuracao','',['Authorization'=>'Bearer '.$k['chave']])->status,'Chave central também não: só sessão');
        $op=$this->admin('operador','operador@example.com');
        self::assertSame(403,$this->sess($op,'GET','/api/v1/admin/configuracao')->status);
        self::assertSame(403,$this->call('PUT','/api/v1/admin/configuracao/email','{}',['Cookie'=>$adm['cookie']])->status,'Mutação exige CSRF');
        $v=$this->json($this->sess($adm,'GET','/api/v1/admin/configuracao'));
        self::assertSame(array_keys(Settings::GRUPOS),array_column($v['grupos'],'codigo'));
        self::assertContains('CERT_ENCRYPTION_KEY',array_column($v['inicializacao']['itens'],'chave'));
        self::assertStringNotContainsString(base64_encode('x'),json_encode($v['inicializacao']));
    }

    public function testImportaArquivoLegadoSemSobrescreverBanco(): void
    {
        $s=$this->admin();
        // o .env de teste traz AUTH_MAX_FAILURES=5
        self::assertSame('arquivo',$this->item(Settings::publicView(),'AUTH_MAX_FAILURES')['origem']);
        $this->sess($s,'PUT','/api/v1/admin/configuracao/sessao',json_encode(['LOGIN_MAX_FAILURES'=>'7']));
        $importadas=Settings::importarArquivo();
        self::assertContains('AUTH_MAX_FAILURES',$importadas);
        self::assertSame('banco',$this->item(Settings::publicView(),'AUTH_MAX_FAILURES')['origem']);
        self::assertSame(7,Env::int('LOGIN_MAX_FAILURES',5));
        self::assertSame([],Settings::importarArquivo(),'Idempotente');
    }

    public function testChaveMestraDiferenteImpedeLeituraComMensagemClara(): void
    {
        $s=$this->admin();
        $this->sess($s,'PUT','/api/v1/admin/configuracao/email',json_encode(['SMTP_HOST'=>'smtp.exemplo.com.br']));
        $orig=Env::get('CERT_ENCRYPTION_KEY');
        Env::set('CERT_ENCRYPTION_KEY',base64_encode(random_bytes(32)));Settings::reset();
        try{Env::get('SMTP_HOST');self::fail('Deveria recusar');}
        catch(\RuntimeException $e){self::assertStringContainsString('chave-mestra',$e->getMessage());}
        finally{Env::set('CERT_ENCRYPTION_KEY',(string)$orig);Settings::reset();}
        self::assertSame('smtp.exemplo.com.br',Env::get('SMTP_HOST'));
    }

    public function testBackblazeConfiguradoPelaJanelaComTesteAntesDeSalvar(): void
    {
        $s=$this->admin();
        $base=['XML_STORAGE'=>'s3','S3_ENDPOINT'=>'https://s3.us-east-005.backblazeb2.com','S3_REGION'=>'us-east-005','S3_BUCKET'=>'emissor','S3_ACCESS_KEY_ID'=>'0050000000000000000001','S3_PREFIX'=>'fiscal'];
        // B2 sem applicationKey: recusado, nada gravado
        $r=$this->sess($s,'PUT','/api/v1/admin/configuracao/armazenamento',json_encode($base));
        self::assertSame(422,$r->status,$r->body);self::assertContains('S3_SECRET_ACCESS_KEY',array_column($this->json($r)['erro']['detalhes'],'campo'));
        self::assertSame(422,$this->sess($s,'PUT','/api/v1/admin/configuracao/armazenamento',json_encode(['S3_ENDPOINT'=>'http://s3.exemplo.com']+$base+['S3_SECRET_ACCESS_KEY'=>'x']))->status,'Só HTTPS');
        // teste de conexão com os valores do formulário, sem salvar
        $calls=[];\App\Storage\S3Client::$transport=function($m,$k,$b)use(&$calls){static $o=[];$calls[]=$m;if($m==='PUT'){$o[$k]=$b;return '';}return $o[$k]??'';};
        $t=$this->sess($s,'POST','/api/v1/admin/configuracao/armazenamento/testar',json_encode($base+['S3_SECRET_ACCESS_KEY'=>'K005segredo']));
        self::assertSame(200,$t->status,$t->body);self::assertSame(['PUT','GET','DELETE'],$calls);
        self::assertNull(JsonStore::read(Settings::PATH),'Teste não grava a configuração');
        \App\Storage\S3Client::$transport=fn()=>throw new \RuntimeException('HTTP 403, código InvalidAccessKeyId');
        $t=$this->sess($s,'POST','/api/v1/admin/configuracao/armazenamento/testar',json_encode($base+['S3_SECRET_ACCESS_KEY'=>'K005segredo']));
        self::assertSame(502,$t->status);self::assertStringContainsString('InvalidAccessKeyId',$t->body);
        self::assertStringNotContainsString('K005segredo',$t->body);
        // salva; segredo fica cifrado, nunca volta, e vale para o Env/S3Client
        foreach(['S3_ENDPOINT','S3_REGION','S3_BUCKET','S3_ACCESS_KEY_ID','S3_SECRET_ACCESS_KEY','S3_PREFIX'] as $k){\App\Support\Env::clear($k);}
        \App\Support\Env::clear('XML_STORAGE');
        $r=$this->sess($s,'PUT','/api/v1/admin/configuracao/armazenamento',json_encode($base+['S3_SECRET_ACCESS_KEY'=>'K005segredo']));
        self::assertSame(200,$r->status,$r->body);
        self::assertSame('s3',\App\Storage\XmlArchive::modo());self::assertTrue(\App\Storage\S3Client::configurado());
        self::assertStringNotContainsString('K005segredo',json_encode(JsonStore::read(Settings::PATH)));
        self::assertStringNotContainsString('K005segredo',$this->sess($s,'GET','/api/v1/admin/configuracao')->body);
        // teste com applicationKey em branco usa a salva
        $calls=[];\App\Storage\S3Client::$transport=function($m,$k,$b)use(&$calls){static $o=[];$calls[]=$m;if($m==='PUT'){$o[$k]=$b;return '';}return $o[$k]??'';};
        self::assertSame(200,$this->sess($s,'POST','/api/v1/admin/configuracao/armazenamento/testar',json_encode($base+['S3_SECRET_ACCESS_KEY'=>'']))->status);
        $st=$this->json($this->sess($s,'GET','/api/v1/admin/armazenamento'));
        self::assertSame('b2',$st['provedor']);self::assertTrue($st['b2_configurado']);
        self::assertSame(403,$this->authed('POST','/api/v1/admin/configuracao/armazenamento/testar','{}')->status,'Chave de API não testa');
    }

    public function testTelaServidaComCspDoPainel(): void
    {
        foreach(['/configuracao','/configuracao/configuracao.js','/configuracao/portal.css'] as $p){
            $r=$this->call('GET',$p);self::assertSame(200,$r->status,$p);
        }
        self::assertSame(404,$this->call('GET','/configuracao/../.env')->status);
        self::assertStringContainsString("script-src 'self'",$this->call('GET','/configuracao')->headers['Content-Security-Policy']);
    }
}
