<?php
declare(strict_types=1);
namespace Tests;

use App\Nfe\SefazClient;
use App\Support\Env;
use Tests\Support\FakeTools;
use Tests\Support\TestCert;

final class PdfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $this->configure(); FakeTools::reset();
        $r=$this->authed('POST','/api/v1/certificado',json_encode(['certificado_base64'=>base64_encode(TestCert::pfx('pdf-teste')),'senha'=>'pdf-teste']));
        self::assertSame(201,$r->status,$r->body);
        SefazClient::$factory=FakeTools::factory();
    }

    public function testPdfNfceAutorizadaCanceladaEIsolamento(): void
    {
        $entrada=json_decode(file_get_contents(APP_ROOT.'/examples/pdv-nfce-exemplo.json'),true);
        $nota=$this->json($this->authed('POST','/api/v1/pdv/nfce',json_encode($entrada)));
        $url=$nota['links']['danfe'];
        $r=$this->authed('GET',$url);
        self::assertSame(200,$r->status,$r->body);
        self::assertSame('application/pdf',$r->headers['Content-Type']);
        self::assertStringStartsWith('%PDF',$r->body);
        self::assertMatchesRegularExpression('#/MediaBox \[0 0 226\.77#',$r->body);
        self::assertSame(401,$this->call('GET',$url)->status);
        $outra=$this->signup('outra@example.com','Outra empresa','11444777000161');
        self::assertSame(404,$this->sess($outra,'GET',$url)->status);
        $r=$this->authed('POST','/api/v1/nfe/'.$nota['chave'].'/cancelar',json_encode(['justificativa'=>'Cancelamento solicitado para teste de PDF']));
        self::assertSame(200,$r->status,$r->body);
        self::assertStringStartsWith('%PDF',$this->authed('GET',$url)->body);
        self::assertCount(1,FakeTools::$sentXml);
    }

    public function testPdfOfflineSemTransmissaoETimeoutNormalBloqueado(): void
    {
        $entrada=json_decode(file_get_contents(APP_ROOT.'/examples/pdv-nfce-exemplo.json'),true);
        $entrada['contingencia']='offline';
        $nota=$this->json($this->authed('POST','/api/v1/pdv/nfce',json_encode($entrada)));
        $pdf=$this->authed('GET',$nota['links']['danfe']);
        self::assertSame(200,$pdf->status,$pdf->body);
        self::assertStringStartsWith('%PDF',$pdf->body);
        self::assertCount(0,FakeTools::$sentXml);
        $entrada['operacao_id'].='-timeout'; $entrada['contingencia']='desativada';
        FakeTools::$timeoutAfterSend=true;
        $nota=$this->json($this->authed('POST','/api/v1/pdv/nfce',json_encode($entrada)));
        self::assertSame('situacao_incerta',$nota['status']);
        self::assertSame(409,$this->authed('GET',$nota['links']['danfe'])->status);
    }

    public function testDanfeModelo55A4(): void
    {
        $entrada=json_decode(file_get_contents(APP_ROOT.'/examples/pdv-nfce-exemplo.json'),true);
        $p=$entrada['nota']; $p['modelo']=55;
        $p['destinatario']=['cpf'=>'52998224725','nome'=>'Cliente Teste','endereco'=>\App\Nfe\ConfigService::require()['endereco']+['uf'=>'SP']];
        $r=$this->authed('POST','/api/v1/nfe',json_encode($p),['Idempotency-Key'=>'pdf-modelo55-001']);
        self::assertSame(201,$r->status,$r->body);
        $nota=$this->json($r);
        $pdf=$this->authed('GET',$nota['links']['danfe']);
        self::assertSame(200,$pdf->status,$pdf->body);
        self::assertStringStartsWith('%PDF',$pdf->body);
        self::assertMatchesRegularExpression('#/MediaBox \[0 0 595\.28#',$pdf->body);
    }
}
