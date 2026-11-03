<?php
declare(strict_types=1);

namespace Tests;

use App\Nfe\ConfigService;
use App\Nfe\NfeBuilder;
use App\Nfe\SefazClient;
use App\Nfe\XsdValidator;
use App\Support\Env;
use Tests\Support\FakeTools;
use Tests\Support\TestCert;

final class RtcInformadaTest extends TestCase
{
    private function payload(): array
    {
        $p = json_decode(file_get_contents(APP_ROOT . '/examples/nfce-exemplo.json'), true);
        $p['itens'][0]['valor_item'] = '99.80';
        $p['itens'][0]['impostos']['ibscbs'] = [
            'CST' => '000', 'cClassTrib' => '000001',
            'gIBSCBS' => [
                'vBC' => '87.65',
                'gIBSUF' => ['pIBSUF' => '0.1234', 'vIBSUF' => '0.11'],
                'gIBSMun' => ['pIBSMun' => '0.0000', 'vIBSMun' => '0.00'],
                'vIBS' => '0.11', 'gCBS' => ['pCBS' => '0.9876', 'vCBS' => '0.87'],
            ],
        ];
        $p['reforma_tributaria'] = ['modo' => 'informado', 'totais' => [
            'IBSCBSTot' => [
                'vBCIBSCBS' => '87.65',
                'gIBS' => [
                    'gIBSUF' => ['vDif' => '0.00', 'vDevTrib' => '0.00', 'vIBSUF' => '0.11'],
                    'gIBSMun' => ['vDif' => '0.00', 'vDevTrib' => '0.00', 'vIBSMun' => '0.00'],
                    'vIBS' => '0.11', 'vCredPres' => '0.00', 'vCredPresCondSus' => '0.00',
                ],
                'gCBS' => ['vDif' => '0.00', 'vDevTrib' => '0.00', 'vCBS' => '0.87', 'vCredPres' => '0.00', 'vCredPresCondSus' => '0.00'],
            ], 'vNFTot' => '99.80',
        ]];
        return $p;
    }

    private function montar(array $p): array
    {
        return NfeBuilder::build(NfeBuilder::validatePayload($p), ConfigService::require(), 1, 1);
    }

    private function tag(string $xml, string $path): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        return $xp->evaluate('string(' . $path . ')');
    }

    public function testValoresDoDelphiNaoSaoRecalculadosENemPadraoDaEmpresaAplicado(): void
    {
        $this->configure();
        \App\Storage\JsonStore::write(\App\Tenancy\Tenant::path('reforma-tributaria.json'), ['habilitado' => true, 'cst' => '200', 'cClassTrib' => '200034']);
        $b = $this->montar($this->payload());
        self::assertSame([], XsdValidator::validateUnsignedNFe($b['xml']));
        self::assertSame('87.65', $this->tag($b['xml'], '//n:det/n:imposto/n:IBSCBS/n:gIBSCBS/n:vBC'));
        self::assertSame('0.1234', $this->tag($b['xml'], '//n:det//n:gIBSUF/n:pIBSUF'));
        self::assertSame('0.9876', $this->tag($b['xml'], '//n:det//n:gCBS/n:pCBS'));
        self::assertSame('99.80', $this->tag($b['xml'], '//n:det/n:vItem'));
        self::assertSame('99.80', $this->tag($b['xml'], '//n:total/n:vNFTot'));
        self::assertSame('informado', $b['reforma_tributaria']['modo']);
    }

    public function testGruposDetalhadosPreservados(): void
    {
        $this->configure();
        $p = $this->payload();
        $ib = &$p['itens'][0]['impostos']['ibscbs'];
        foreach (['gIBSUF', 'gIBSMun', 'gCBS'] as $group) {
            $ib['gIBSCBS'][$group]['gDif'] = ['pDif' => '12.3456', 'vDif' => '1.23'];
            $ib['gIBSCBS'][$group]['gDevTrib'] = ['vDevTrib' => '0.12'];
            $ib['gIBSCBS'][$group]['gRed'] = ['pRedAliq' => '20.0000', 'pAliqEfet' => '0.1234'];
        }
        $ib['gIBSCBS']['gTribRegular'] = [
            'CSTReg' => '000', 'cClassTribReg' => '000001',
            'pAliqEfetRegIBSUF' => '0.1234', 'vTribRegIBSUF' => '1.23',
            'pAliqEfetRegIBSMun' => '0.1234', 'vTribRegIBSMun' => '1.24',
            'pAliqEfetRegCBS' => '0.1234', 'vTribRegCBS' => '1.25',
        ];
        $ib['gIBSCBS']['gTribCompraGov'] = [
            'pAliqIBSUF' => '0.1234', 'vTribIBSUF' => '1.23', 'pAliqIBSMun' => '0.1234', 'vTribIBSMun' => '1.24',
            'pAliqCBS' => '0.1234', 'vTribCBS' => '1.25',
        ];
        $ib['gEstornoCred'] = ['vIBSEstCred' => '1.23', 'vCBSEstCred' => '2.34'];
        $ib['gCredPresOper'] = [
            'vBCCredPres' => '87.65', 'cCredPres' => '01',
            'gIBSCredPres' => ['pCredPres' => '5.4321', 'vCredPres' => '1.23'],
            'gCBSCredPres' => ['pCredPres' => '6.5432', 'vCredPresCondSus' => '2.34'],
        ];
        $b = $this->montar($p);
        self::assertSame('12.3456', $this->tag($b['xml'], '//n:det//n:gIBSUF/n:gDif/n:pDif'));
        self::assertSame('0.12', $this->tag($b['xml'], '//n:det//n:gCBS/n:gDevTrib/n:vDevTrib'));
        self::assertSame('1.25', $this->tag($b['xml'], '//n:gTribRegular/n:vTribRegCBS'));
        self::assertSame('1.24', $this->tag($b['xml'], '//n:gTribCompraGov/n:vTribIBSMun'));
        self::assertSame('2.34', $this->tag($b['xml'], '//n:det//n:gEstornoCred/n:vCBSEstCred'));
        self::assertSame('6.5432', $this->tag($b['xml'], '//n:gCBSCredPres/n:pCredPres'));
    }

    public function testTransferenciaAjusteEZfmNoModelo55(): void
    {
        $this->configure();
        foreach ([
            ['800', '800001', 'gTransfCred', ['vIBS' => '12.34', 'vCBS' => '23.45']],
            ['811', '811001', 'gAjusteCompet', ['competApur' => '2026-11', 'vIBS' => '12.34', 'vCBS' => '23.45']],
        ] as [$cst, $cls, $group, $values]) {
            $p = $this->payload();
            $p['modelo'] = 55;
            $p['destinatario'] = ['cnpj' => '11222333000181', 'nome' => 'Cliente', 'endereco' => ConfigService::require()['endereco'] + ['uf' => 'SP']];
            $p['itens'][0]['impostos']['ibscbs'] = ['CST' => $cst, 'cClassTrib' => $cls, $group => $values];
            $b = $this->montar($p);
            self::assertSame('12.34', $this->tag($b['xml'], '//n:' . $group . '/n:vIBS'));
        }
        $p = $this->payload();
        $p['itens'][0]['impostos']['ibscbs']['gCredPresIBSZFM'] = ['competApur' => '2026-11', 'tpCredPresIBSZFM' => '1', 'vCredPresIBSZFM' => '12.34'];
        self::assertSame('2026-11', $this->tag($this->montar($p)['xml'], '//n:gCredPresIBSZFM/n:competApur'));
    }

    public function testMonofasiaNovaAdRemEAdValorem(): void
    {
        $this->configure();
        foreach ([
            ['gIBSMonoAdRem', ['qBCMono' => '1.2345', 'adRemIBS' => '0.1234', 'vIBSMono' => '1.23'], 'gCBSMonoAdRem', ['qBCMono' => '1.2345', 'adRemCBS' => '0.1234', 'vCBSMono' => '2.34']],
            ['gIBSMonoAdValorem', ['vBCMono' => '87.65', 'pAliqMonoUF' => '0.1234', 'vIBSMonoUF' => '1.23', 'pAliqMonoMun' => '0.0000', 'vIBSMonoMun' => '0.00', 'vIBSMono' => '1.23'], 'gCBSMonoAdValorem', ['vBCMono' => '87.65', 'pAliqMonoCBS' => '0.1234', 'vCBSMono' => '2.34']],
        ] as [$ibs, $vi, $cbs, $vc]) {
            $p = $this->payload();
            $retIbs = str_contains($ibs, 'AdRem')
                ? ['qBCMonoReten' => '1.2345', 'adRemIBSReten' => '0.1234', 'vIBSMonoReten' => '0.12']
                : ['vBCMonoReten' => '87.65', 'pAliqMonoReten' => '0.1234', 'vIBSMonoReten' => '0.12'];
            $retCbs = str_contains($cbs, 'AdRem')
                ? ['qBCMonoReten' => '1.2345', 'adRemCBSReten' => '0.1234', 'vCBSMonoReten' => '0.23']
                : ['vBCMonoReten' => '87.65', 'pAliqMonoReten' => '0.1234', 'vCBSMonoReten' => '0.23'];
            $p['itens'][0]['impostos']['ibscbs'] = ['CST' => '620', 'cClassTrib' => '620006', 'gIBSCBSMono' => [
                $ibs => ['gMonoPadrao' => $vi, 'gMonoReten' => $retIbs, 'gMonoRet' => ['vIBSMonoRet' => '0.12'], 'gpBioDiferenca' => ['qBCBioComb' => '1.2345', 'vIBSDiferenca' => '0.12']],
                $cbs => ['gMonoPadrao' => $vc, 'gMonoReten' => $retCbs, 'gMonoRet' => ['vCBSMonoRet' => '0.23'], 'gpBioDiferenca' => ['qBCBioComb' => '1.2345', 'vCBSDiferenca' => '0.23']],
                'vTotIBSMonoItem' => '1.23', 'vTotCBSMonoItem' => '2.34',
            ]];
            $b = $this->montar($p);
            self::assertSame('1.23', $this->tag($b['xml'], '//n:' . $ibs . '/n:gMonoPadrao/n:vIBSMono'));
            self::assertSame('2.34', $this->tag($b['xml'], '//n:' . $cbs . '/n:gMonoPadrao/n:vCBSMono'));
            self::assertSame('0.12', $this->tag($b['xml'], '//n:' . $ibs . '/n:gMonoReten/n:vIBSMonoReten'));
            self::assertSame('0.23', $this->tag($b['xml'], '//n:' . $cbs . '/n:gMonoReten/n:vCBSMonoReten'));
        }
    }

    public function testImpostoSeletivoEspecificoETotais(): void
    {
        $this->configure();
        $p = $this->payload();
        $p['itens'][0]['impostos']['is'] = [
            'CSTIS' => '000', 'cClassTribIS' => '000001', 'vBCIS' => '87.65', 'pIS' => '1.2345',
            'adRemIS' => '2.3456', 'uTrib' => 'UN', 'qTrib' => '1.2345', 'vIS' => '2.34',
        ];
        $p['reforma_tributaria']['totais']['ISTot'] = ['vIS' => '2.34'];
        $b = $this->montar($p);
        self::assertSame('2.3456', $this->tag($b['xml'], '//n:det/n:imposto/n:IS/n:adRemIS'));
        self::assertSame('2.34', $this->tag($b['xml'], '//n:total/n:ISTot/n:vIS'));
    }

    public function testPdvAssinaValidaEnvelopeEAutorizaMonofasiaSemSchemaAntigo(): void
    {
        $this->configure();
        FakeTools::reset();
        $r = $this->authed('POST', '/api/v1/certificado', json_encode(['certificado_base64' => base64_encode(TestCert::pfx('rtc-teste')), 'senha' => 'rtc-teste']));
        self::assertSame(201, $r->status, $r->body);
        SefazClient::$factory = FakeTools::factory();
        $p = $this->payload();
        $p['itens'][0]['impostos']['ibscbs'] = ['CST' => '620', 'cClassTrib' => '620006', 'gIBSCBSMono' => [
            'gIBSMonoAdRem' => ['gMonoPadrao' => ['qBCMono' => '1.2345', 'adRemIBS' => '0.1234', 'vIBSMono' => '1.23']],
            'gCBSMonoAdRem' => ['gMonoPadrao' => ['qBCMono' => '1.2345', 'adRemCBS' => '0.1234', 'vCBSMono' => '2.34']],
            'vTotIBSMonoItem' => '1.23', 'vTotCBSMonoItem' => '2.34',
        ]];
        $r = $this->authed('POST', '/api/v1/pdv/nfce', json_encode(['operacao_id' => 'rtc-monofasia-0001', 'nota' => $p]));
        self::assertSame(201, $r->status, $r->body);
        $d = $this->json($r);
        self::assertStringContainsString('<nfeProc', $d['xml']);
        self::assertSame('0.1234', $this->tag($d['xml'], '//n:gIBSMonoAdRem/n:gMonoPadrao/n:adRemIBS'));
    }

    public function testCamposDesconhecidosPrecisaoEEscolhasInvalidasSaoRecusados(): void
    {
        $this->configure();
        $cases = [];
        $p = $this->payload();
        $p['itens'][0]['impostos']['ibscbs']['campoDesconhecido'] = '1.00';
        $cases[] = $p;
        $p = $this->payload();
        $p['itens'][0]['impostos']['ibscbs']['gIBSCBS']['vBC'] = 87.65;
        $cases[] = $p;
        $p = $this->payload();
        $p['itens'][0]['impostos']['ibscbs']['gIBSCBS']['vBC'] = '87.655';
        $cases[] = $p;
        $p = $this->payload();
        $p['itens'][0]['impostos']['ibscbs']['gTransfCred'] = ['vIBS' => '1.00', 'vCBS' => '1.00'];
        $cases[] = $p;
        $p = $this->payload();
        unset($p['reforma_tributaria']['totais']['IBSCBSTot']);
        $cases[] = $p;
        $p = $this->payload();
        $p['reforma_tributaria']['totais']['vNFTot'] = '99.81';
        $cases[] = $p;
        foreach ($cases as $input) {
            try {
                $this->montar($input);
                self::fail('Deve recusar sem arredondar/descartar campos');
            } catch (\App\Http\ApiException $e) {
                self::assertSame(422, $e->status());
            }
        }
    }

    public function testClassificacaoInformadaNaoDependeDeTabelaLocal(): void
    {
        $this->configure();$p=$this->payload();
        $p['itens'][0]['impostos']['ibscbs']['cClassTrib']='999999';
        $b=$this->montar($p);
        self::assertStringContainsString('<cClassTrib>999999</cClassTrib>',$b['xml']);
    }

    public function testExemploCompletoEContratoPdvExigemModoInformado(): void
    {
        $this->configure();
        $entrada = json_decode(file_get_contents(APP_ROOT . '/examples/pdv-nfce-rtc-informada.json'), true);
        $b = $this->montar($entrada['nota']);
        self::assertSame('10.00', $this->tag($b['xml'], '//n:total/n:vNFTot'));
        unset($entrada['nota']['reforma_tributaria']);
        $r = $this->authed('POST', '/api/v1/pdv/nfce', json_encode($entrada));
        self::assertSame(422, $r->status, $r->body);
        self::assertSame('nota.reforma_tributaria.modo', $this->json($r)['erro']['detalhes'][0]['campo']);
    }

    public function testTotaisDetalhadosRetencaoDoacaoEOrdemJson(): void
    {
        $this->configure();
        $p = $this->payload();
        $p['itens'][0]['impostos']['ibscbs']['indDoacao'] = '1';
        $p['itens'][0]['impostos']['ibscbs'] = array_reverse($p['itens'][0]['impostos']['ibscbs'], true);
        $tot = &$p['reforma_tributaria']['totais']['IBSCBSTot'];
        $tot['gMono'] = ['vIBSMono' => '1.23', 'vCBSMono' => '2.34', 'vIBSMonoReten' => '3.45', 'vCBSMonoReten' => '4.56', 'vIBSMonoRet' => '5.67', 'vCBSMonoRet' => '6.78'];
        $tot['gEstornoCred'] = ['vIBSEstCred' => '1.23', 'vCBSEstCred' => '2.34'];
        $b = $this->montar($p);
        self::assertSame('1', $this->tag($b['xml'], '//n:IBSCBS/n:indDoacao'));
        self::assertSame('3.45', $this->tag($b['xml'], '//n:IBSCBSTot/n:gMono/n:vIBSMonoReten'));
        self::assertSame('2.34', $this->tag($b['xml'], '//n:IBSCBSTot/n:gEstornoCred/n:vCBSEstCred'));
    }

    public function testRtcInformadaSobreviveContingenciaERecuperacao(): void
    {
        $this->configure();
        FakeTools::reset();
        $r = $this->authed('POST', '/api/v1/certificado', json_encode(['certificado_base64' => base64_encode(TestCert::pfx('rtc-fila')), 'senha' => 'rtc-fila']));
        self::assertSame(201, $r->status, $r->body);
        SefazClient::$factory = FakeTools::factory();
        $entrada = ['operacao_id' => 'rtc-contingencia-0001', 'contingencia' => 'offline', 'nota' => $this->payload()];
        $r = $this->authed('POST', '/api/v1/pdv/nfce', json_encode($entrada));
        self::assertSame(202, $r->status, $r->body);
        $off = $this->json($r);
        $file = \App\Tenancy\Tenant::path('pdv/' . hash('sha256', $entrada['operacao_id']) . '.json');
        \App\Storage\JsonStore::update($file, function ($op) {
            $op['proxima_tentativa'] = 0;
            return [$op, null];
        });
        $d = \App\Nfe\PdvService::sincronizar($entrada['operacao_id']);
        self::assertSame('autorizada', $d['status']);
        self::assertSame($off['xml'], FakeTools::$sentXml[0]);
        self::assertSame('0.9876', $this->tag($d['xml'], '//n:det//n:gCBS/n:pCBS'));
    }

    public function testPdvSemRtcNaoHerdaCalculoAutomaticoDoPadraoDaEmpresa(): void
    {
        $this->configure();
        FakeTools::reset();
        $r = $this->authed('POST', '/api/v1/certificado', json_encode(['certificado_base64' => base64_encode(TestCert::pfx('rtc-default')), 'senha' => 'rtc-default']));
        self::assertSame(201, $r->status, $r->body);
        SefazClient::$factory = FakeTools::factory();
        \App\Storage\JsonStore::write(\App\Tenancy\Tenant::path('reforma-tributaria.json'), ['habilitado' => true, 'cst' => '000', 'cClassTrib' => '000001']);
        $entrada = json_decode(file_get_contents(APP_ROOT . '/examples/pdv-nfce-exemplo.json'), true);
        $r = $this->authed('POST', '/api/v1/pdv/nfce', json_encode($entrada));
        self::assertSame(201, $r->status, $r->body);
        self::assertStringNotContainsString('<IBSCBS>', $this->json($r)['xml']);
    }
}
