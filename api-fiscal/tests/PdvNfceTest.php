<?php
declare(strict_types=1);

namespace Tests;

use App\Nfe\PdvService;
use App\Nfe\SefazClient;
use App\Storage\JsonStore;
use App\Support\Paths;
use App\Tenancy\Tenant;
use Tests\Support\FakeTools;
use Tests\Support\TestCert;

final class PdvNfceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeTools::reset();
        $this->configure();
        $r = $this->authed('POST', '/api/v1/certificado', json_encode([
            'certificado_base64' => base64_encode(TestCert::pfx('teste-pdv')), 'senha' => 'teste-pdv',
        ]));
        self::assertSame(201, $r->status, $r->body);
        SefazClient::$factory = FakeTools::factory();
    }

    private function entrada(string $id = 'terminal-01-venda-123'): array
    {
        return ['operacao_id' => $id, 'nota' => json_decode(file_get_contents(APP_ROOT . '/examples/nfce-exemplo.json'), true)];
    }

    private function emitir(array $p): \App\Http\Response
    {
        return $this->authed('POST', '/api/v1/pdv/nfce', json_encode($p));
    }

    private function vencerEspera(string $id): void
    {
        $file = Tenant::path('pdv/' . hash('sha256', $id) . '.json');
        $op = JsonStore::read($file);
        $op['proxima_tentativa'] = 0;
        JsonStore::write($file, $op);
    }

    public function testAutorizacaoRetornaXmlProtocoladoEReplayNaoTransmite(): void
    {
        $p = $this->entrada();
        $r = $this->emitir($p);
        self::assertSame(201, $r->status, $r->body);
        $d = $this->json($r);
        self::assertSame('autorizada', $d['status']);
        self::assertTrue($d['imprimivel']);
        self::assertStringContainsString('<nfeProc', $d['xml']);
        self::assertStringContainsString('<nProt>', $d['xml']);
        self::assertArrayNotHasKey('hash', $d);
        $p = array_reverse($p, true);
        $replay = $this->json($this->emitir($p));
        self::assertSame($d['chave'], $replay['chave']);
        self::assertSame($d['xml'], $replay['xml']);
        self::assertCount(1, FakeTools::$sentXml);
        $get = $this->authed('GET', '/api/v1/pdv/nfce/' . $p['operacao_id']);
        self::assertSame($d['xml'], $this->json($get)['xml']);
    }

    public function testXmlArquivadoPermiteConsultaEReplaySemNovaTransmissao(): void
    {
        \App\Support\Env::set('XML_STORAGE','s3');$objects=[];
        \App\Storage\S3Client::$transport=function($m,$k,$b)use(&$objects){if($m==='PUT'){$objects[$k]=$b;return '';}return $objects[$k];};
        $p=$this->entrada('pdv-arquivo-remoto-0001');$r=$this->emitir($p);
        self::assertSame(201,$r->status,$r->body);$xml=$this->json($r)['xml'];
        \App\Storage\XmlArchive::ciclo(100);
        self::assertCount(0,JsonStore::files('archive/payloads/*'));
        $stored=JsonStore::read(Tenant::path('pdv/'.hash('sha256',$p['operacao_id']).'.json'));
        self::assertArrayNotHasKey('xml',$stored);self::assertArrayHasKey('xml_arquivo',$stored);
        $get=$this->authed('GET','/api/v1/pdv/nfce/'.$p['operacao_id']);
        self::assertSame(200,$get->status,$get->body);self::assertSame($xml,$this->json($get)['xml']);
        self::assertSame($xml,$this->json($this->emitir($p))['xml']);self::assertCount(1,FakeTools::$sentXml);
    }

    public function testTimeoutAutorizadoRecuperaPorConsultaSemNovoEnvio(): void
    {
        FakeTools::$timeoutAfterSend = true;
        $p = $this->entrada();
        $r = $this->emitir($p);
        self::assertSame(202, $r->status, $r->body);
        $incerta = $this->json($r);
        self::assertSame('situacao_incerta', $incerta['status']);
        self::assertNull($incerta['xml']);
        self::assertFalse($incerta['imprimivel']);
        $this->vencerEspera($p['operacao_id']);
        $r = $this->authed('POST', '/api/v1/pdv/nfce/' . $p['operacao_id'] . '/sincronizar');
        self::assertSame(200, $r->status, $r->body);
        $d = $this->json($r);
        self::assertSame('autorizada', $d['status']);
        self::assertSame($incerta['chave'], $d['chave']);
        self::assertCount(1, FakeTools::$sentXml);
        self::assertStringContainsString('<nfeProc', $d['xml']);
        self::assertSame('autorizada', $this->json($this->emitir($p))['status']);
    }

    public function testOfflineAssinaSemEnviarEDepoisTransmiteMesmoXml(): void
    {
        FakeTools::$statusOffline = true;
        $p = $this->entrada();
        $r = $this->emitir($p);
        self::assertSame(202, $r->status, $r->body);
        $off = $this->json($r);
        self::assertSame('contingencia_pendente', $off['status']);
        self::assertTrue($off['imprimivel']);
        self::assertStringContainsString('<tpEmis>9</tpEmis>', $off['xml']);
        self::assertStringContainsString('<dhCont>', $off['xml']);
        self::assertStringContainsString('<Signature', $off['xml']);
        self::assertStringContainsString('<qrCode>', $off['xml']);
        self::assertCount(0, FakeTools::$sentXml);
        FakeTools::$statusOffline = false;
        $this->vencerEspera($p['operacao_id']);
        $d = PdvService::sincronizar($p['operacao_id']);
        self::assertSame('autorizada', $d['status']);
        self::assertSame($off['chave'], $d['chave']);
        self::assertSame($off['xml'], FakeTools::$sentXml[0]);
    }

    public function testConsultaComTimeoutNaoReenviaNemConverteNormalParaOffline(): void
    {
        FakeTools::$timeoutAfterSend = true;
        $p = $this->entrada();
        $this->emitir($p);
        FakeTools::$queryTimeout = true;
        $this->vencerEspera($p['operacao_id']);
        $d = PdvService::sincronizar($p['operacao_id']);
        self::assertSame('situacao_incerta', $d['status']);
        self::assertSame(1, $d['tipo_emissao']);
        self::assertCount(1, FakeTools::$sentXml);
    }

    public function testConflitoRejeicaoENumeroExplicito(): void
    {
        $p = $this->entrada();
        $this->emitir($p);
        $p['nota']['itens'][0]['descricao'] = 'Outro produto';
        self::assertSame(409, $this->emitir($p)->status);
        $p = $this->entrada('terminal-01-venda-124');
        $p['nota']['numero'] = 55;
        self::assertSame(422, $this->emitir($p)->status);
        unset($p['nota']['numero']);
        FakeTools::$cStat = '778';
        $r = $this->emitir($p);
        self::assertSame(422, $r->status, $r->body);
        self::assertSame('rejeitada', $this->json($r)['status']);
        self::assertNull($this->json($r)['xml']);
        $n = count(FakeTools::$sentXml);
        self::assertSame('rejeitada', PdvService::sincronizar($p['operacao_id'])['status']);
        self::assertCount($n, FakeTools::$sentXml);
    }

    public function testIsolamentoAutenticacaoEReparoDaProjecao(): void
    {
        $p = $this->entrada();
        $d = $this->json($this->emitir($p));
        self::assertSame(401, $this->call('GET', '/api/v1/pdv/nfce/' . $p['operacao_id'])->status);
        Tenant::set(Tenant::DEFAULT_ID);
        Tenant::run('t_0000000000000002', function () use ($p) {
            try {
                PdvService::consultar($p['operacao_id']);
                self::fail('Não deve acessar outra empresa');
            } catch (\App\Http\ApiException $e) {
                self::assertSame(404, $e->status());
            }
        });
        JsonStore::delete(Tenant::path('nfe/' . $d['chave'] . '.json'));
        JsonStore::delete(Tenant::path('nfe/' . $d['chave'] . '.xml'));
        self::assertSame($d['xml'], PdvService::consultar($p['operacao_id'])['xml']);
        self::assertNotNull(\App\Nfe\NfeRepository::find($d['chave']));
    }

    public function testOperacaoAntigaContinuaIdempotenteENumeracaoNaoPodeSerReutilizada(): void
    {
        $p = $this->entrada();
        $d = $this->json($this->emitir($p));
        $file = Tenant::path('pdv/' . hash('sha256', $p['operacao_id']) . '.json');
        $op = JsonStore::read($file);
        $op['criado_em'] = '2000-01-01T00:00:00-03:00';
        JsonStore::write($file, $op);
        self::assertSame($d['chave'], $this->json($this->emitir($p))['chave']);
        // Simula operador rebobinando o contador. cNF diferente não pode permitir o mesmo número.
        JsonStore::update(Tenant::path('config.json'), function ($cfg) {
            $cfg['proximo_numero_nfce'] = 1;
            return [$cfg, null];
        });
        $r = $this->emitir($this->entrada('terminal-01-venda-125'));
        self::assertSame(409, $r->status, $r->body);
        self::assertCount(1, FakeTools::$sentXml);
    }

    public function testWorkerRecuperaOfflineEBackoffImpedeNovaChamadaPrecoce(): void
    {
        $p = $this->entrada();
        $p['contingencia'] = 'offline';
        $d = $this->json($this->emitir($p));
        self::assertSame('contingencia_pendente', $d['status']);
        self::assertSame('contingencia_pendente', PdvService::sincronizar($p['operacao_id'])['status']);
        self::assertCount(0, FakeTools::$calls);
        $this->vencerEspera($p['operacao_id']);
        PdvService::ciclo();
        self::assertSame('autorizada', PdvService::consultar($p['operacao_id'])['status']);
        self::assertCount(1, FakeTools::$sentXml);
    }

    public function testInterrupcaoAntesDoEnvioRetomaMesmoXmlESerie(): void
    {
        $p = $this->entrada();
        $p['contingencia'] = 'offline';
        $d = $this->json($this->emitir($p));
        $file = Tenant::path('pdv/' . hash('sha256', $p['operacao_id']) . '.json');
        $op = JsonStore::read($file);
        $op['status'] = 'pronta'; // queda após assinatura e antes do primeiro envio
        $op['proxima_tentativa'] = 0;
        JsonStore::write($file, $op);
        self::assertSame($d['chave'], PdvService::sincronizar($p['operacao_id'])['chave']);
        self::assertCount(1, FakeTools::$sentXml);
        self::assertSame($d['xml'], FakeTools::$sentXml[0]);
    }

    public function testNaoReverteCancelamentoLocalERecusaConfiguracaoDivergente(): void
    {
        $p = $this->entrada();
        $d = $this->json($this->emitir($p));
        $cancel = $this->authed('POST', '/api/v1/nfe/' . $d['chave'] . '/cancelar', json_encode(['justificativa' => 'Cancelamento solicitado para teste']));
        self::assertSame(200, $cancel->status, $cancel->body);
        $replay = $this->json($this->emitir($p));
        self::assertSame('cancelada', $replay['status']);
        self::assertFalse($replay['imprimivel']);
        $p = $this->entrada('terminal-02-venda-0001');
        $p['contingencia'] = 'offline';
        $this->emitir($p);
        $this->vencerEspera($p['operacao_id']);
        JsonStore::update(Tenant::path('dados-emitente.json'), function ($cfg) {
            $cfg['cnpj'] = '11444777000161';
            return [$cfg, null];
        });
        $r = $this->authed('POST', '/api/v1/pdv/nfce/' . $p['operacao_id'] . '/sincronizar');
        self::assertSame(409, $r->status, $r->body);
        self::assertCount(1, FakeTools::$sentXml);
    }

    public function testSemImpostosNaoReservaNumeroEJsonInvalidoNaoCriaOperacao(): void
    {
        $p = $this->entrada();
        unset($p['nota']['itens'][0]['impostos']['icms']);
        self::assertSame(422, $this->emitir($p)->status);
        $p['nota']['itens'] = 'invalido';
        self::assertSame(422, $this->emitir($p)->status);
        $r = $this->authed('GET', '/api/v1/pdv/nfce/' . $p['operacao_id']);
        self::assertSame(404, $r->status);
        $d = $this->json($this->emitir($this->entrada()));
        self::assertSame(1, $d['numero']);
    }

    public function testProtocoloDeOutraChaveNaoAutorizaOperacao(): void
    {
        FakeTools::$timeoutAfterSend = true;
        $p = $this->entrada();
        $d = $this->json($this->emitir($p));
        FakeTools::$responses[$d['chave']] = str_replace($d['chave'], str_repeat('0', 44), FakeTools::$responses[$d['chave']]);
        $this->vencerEspera($p['operacao_id']);
        $r = PdvService::sincronizar($p['operacao_id']);
        self::assertSame('situacao_incerta', $r['status']);
        self::assertNull($r['xml']);
        self::assertCount(1, FakeTools::$sentXml);
    }

    public function testEmissorAnteriorCompartilhaProtecaoDaNumeracao(): void
    {
        $p = $this->entrada();
        $this->emitir($p);
        $nota = $p['nota'];
        $nota['numero'] = 1;
        $r = $this->authed('POST', '/api/v1/nfe', json_encode($nota), ['Idempotency-Key' => 'emissor-anterior-001']);
        self::assertSame(409, $r->status, $r->body);
        self::assertCount(1, FakeTools::$sentXml);
    }
}
