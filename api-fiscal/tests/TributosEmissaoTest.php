<?php

declare(strict_types=1);

namespace Tests;

use App\Nfe\SefazClient;
use Tests\Support\FakeTools;
use Tests\Support\TestCert;

/**
 * Fase 2: itens com "regra" em POST /nfe e /pdv/nfce — a API calcula os tributos antes de montar o XML.
 */
final class TributosEmissaoTest extends TestCase
{
    private function configurar(int $crt): void
    {
        $r = $this->authed('PUT', '/api/v1/config', (string) json_encode([
            'cnpj' => '11222333000181', 'ie' => '111111111111', 'razao_social' => 'EMPRESA TESTE LTDA', 'uf' => 'SP', 'ambiente' => 2, 'crt' => $crt,
            'endereco' => ['logradouro' => 'Rua das Flores', 'numero' => '100', 'bairro' => 'Centro', 'codigo_municipio' => '3550308',
                'municipio' => 'Sao Paulo', 'cep' => '01001000'],
            'serie' => 1, 'proximo_numero' => 1,
        ]));
        $this->assertSame(200, $r->status, $r->body);
    }

    private function certificado(): void
    {
        FakeTools::reset();
        $r = $this->authed('POST', '/api/v1/certificado', (string) json_encode([
            'certificado_base64' => base64_encode(TestCert::pfx('teste-calc')), 'senha' => 'teste-calc',
        ]));
        $this->assertSame(201, $r->status, $r->body);
        SefazClient::$factory = FakeTools::factory();
    }

    /** @return array<string,mixed> NF-e de exemplo (consumidor final em SP) com itens calculados pela API */
    private function nfe(array $regras): array
    {
        $p = json_decode($this->samplePayload(), true);
        $base = $p['itens'][0];
        unset($base['impostos'], $base['valor_aproximado_tributos'], $base['cfop']);
        $p['itens'] = [];
        foreach ($regras as $k => $regra) {
            $p['itens'][] = ['codigo' => "SKU-$k", 'regra' => $regra] + $base; // 2 × 49,90
        }
        $p['pagamentos'] = [['forma' => '01']]; // valor = total calculado
        return $p;
    }

    private function regraSt(array $extra = []): array
    {
        return ['cfop' => '5405', 'icms' => $extra + ['orig' => 0, 'CST' => '10', 'pICMS' => 18, 'pMVAST' => 40, 'pICMSST' => 18],
            'pis' => ['CST' => '01', 'pPIS' => 1.65], 'cofins' => ['CST' => '01', 'pCOFINS' => 7.6], 'ibpt' => ['federal' => 10, 'estadual' => 18]];
    }

    /** @return array<string,mixed> */
    private function xmlTot(string $xml): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $o = [];
        foreach (['vBC', 'vICMS', 'vBCST', 'vST', 'vPIS', 'vCOFINS', 'vNF', 'vTotTrib'] as $t) {
            $o[$t] = (float) $xp->evaluate("string(//n:ICMSTot/n:$t)");
        }
        return $o;
    }

    public function testDryRunCalculaPreencheOXmlEDevolveOCalculo(): void
    {
        $this->configurar(3);
        $p = $this->nfe([$this->regraSt(), ['cfop' => '5102', 'icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18],
            'pis' => ['CST' => '01', 'pPIS' => 1.65], 'cofins' => ['CST' => '01', 'pCOFINS' => 7.6]]]);
        $r = $this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p));
        $this->assertSame(200, $r->status, $r->body);
        $d = $this->json($r);
        $c = $d['calculo_tributos'];
        $this->assertMatchesRegularExpression('/^calc_[0-9a-f]{32}$/', $c['calculo_id']);
        // 99,80 × 18% = 17,96; ST: 99,80 × 1,4 = 139,72 × 18% = 25,15 − 17,96 = 7,19
        $this->assertSame(['orig' => 0, 'CST' => '10', 'modBC' => 3, 'vBC' => 99.8, 'pICMS' => 18, 'vICMS' => 17.96, 'modBCST' => 4, 'pMVAST' => 40,
            'vBCST' => 139.72, 'pICMSST' => 18, 'vICMSST' => 7.19], $c['itens'][0]['impostos']['icms']);
        $tot = $this->xmlTot($d['xml']);
        $this->assertSame(['vBC' => 199.6, 'vICMS' => 35.92, 'vBCST' => 139.72, 'vST' => 7.19, 'vPIS' => 3.3, 'vCOFINS' => 15.16, 'vNF' => 206.79,
            'vTotTrib' => 27.94], $tot);
        $this->assertSame($c['totais']['ICMSTot']['vNF'], $d['valor_total']);
        $this->assertStringContainsString('Valor aproximado dos tributos: R$ 27,94', $d['xml']);

        // pré-visualização em /tributos/calcular com os mesmos dados: mesmo calculo_id
        $prev = $this->json($this->authed('POST', '/api/v1/tributos/calcular', (string) json_encode([
            'operacao' => ['consumidor_final' => true],
            'itens' => array_map(fn($it) => ['quantidade' => $it['quantidade'], 'valor_unitario' => $it['valor_unitario'], 'regra' => $it['regra']], $p['itens']),
        ])));
        $this->assertSame($prev['calculo_id'], $c['calculo_id']);
    }

    public function testEmissaoRealGravaResumoEConfereCalculoId(): void
    {
        $this->configurar(3);
        $this->certificado();
        $p = $this->nfe([$this->regraSt()]);
        $prev = $this->json($this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p)))['calculo_tributos'];

        // id de uma pré-visualização com outra alíquota: recusa antes de numerar e sem queimar a Idempotency-Key
        $outro = $p;
        $outro['calculo_tributos'] = ['calculo_id' => 'calc_' . str_repeat('0', 32)];
        $r = $this->authed('POST', '/api/v1/nfe', (string) json_encode($outro), ['Idempotency-Key' => 'pedido-calc-0001']);
        $this->assertSame(409, $r->status, $r->body);
        $this->assertSame('calculo_divergente', $this->json($r)['erro']['codigo']);
        $this->assertStringContainsString($prev['calculo_id'], $r->body);
        $this->assertSame(1, \App\Nfe\ConfigService::require()['proximo_numero'], 'numeração não consumida');

        $p['calculo_tributos'] = ['calculo_id' => $prev['calculo_id']];
        $r = $this->authed('POST', '/api/v1/nfe', (string) json_encode($p), ['Idempotency-Key' => 'pedido-calc-0001']);
        $this->assertContains($r->status, [200, 201], $r->body);
        $d = $this->json($r);
        $this->assertSame('autorizada', $d['status'], $r->body);
        $this->assertSame($prev['calculo_id'], $d['calculo_tributos']['calculo_id']);
        $this->assertSame(106.99, (float) $d['valor_total'], 'vNF = 99,80 + ST 7,19');
        $get = $this->json($this->authed('GET', '/api/v1/nfe/' . $d['chave']));
        $this->assertSame($prev['calculo_id'], $get['calculo_tributos']['calculo_id']);
        $this->assertSame([], $get['calculo_tributos']['ajustes_manuais']);
        $this->assertSame(7.19, $get['calculo_tributos']['totais']['ICMSTot']['vST']);
    }

    public function testValidacoesDaNotaComRegra(): void
    {
        $this->configurar(3);
        $p = $this->nfe([$this->regraSt(), $this->regraSt()]);
        $p['itens'][1]['impostos'] = ['icms' => ['orig' => 0, 'CST' => '00'], 'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07']];
        unset($p['itens'][1]['regra']);
        $d = $this->json($this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p)));
        $this->assertContains('itens[1].regra', array_column($d['erro']['detalhes'], 'campo'));

        $p = $this->nfe([$this->regraSt()]);
        $p['itens'][0]['impostos'] = ['icms' => ['orig' => 0, 'CST' => '00']];
        $p['itens'][0]['cfop'] = '5102';
        $d = $this->json($this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p)));
        $campos = array_column($d['erro']['detalhes'], 'campo');
        $this->assertContains('itens[0].impostos', $campos);
        $this->assertContains('itens[0].cfop', $campos);

        $p = $this->nfe([['cfop' => '5405', 'icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 18, 'pMVAST' => 40], 'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07']]]);
        $d = $this->json($this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p)));
        $this->assertContains('itens[0].regra.icms.pICMSST', array_column($d['erro']['detalhes'], 'campo'));

        $p = json_decode($this->samplePayload(), true);
        $p['calculo_tributos'] = ['metodo_desoneracao' => 'por_fora'];
        $d = $this->json($this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p)));
        $this->assertContains('calculo_tributos', array_column($d['erro']['detalhes'], 'campo'));

        $p = $this->nfe([['cfop' => '5102', 'icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18], 'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07']]]);
        $p['calculo_tributos'] = ['metodo_desoneracao' => 'por_fora'];
        $d = $this->json($this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p)));
        $this->assertContains('calculo_tributos.metodo_desoneracao', array_column($d['erro']['detalhes'], 'campo'));
    }

    public function testInterestadualUsaUfDoDestinatario(): void
    {
        $this->configurar(3);
        $p = $this->nfe([['cfop' => '6102', 'icms' => ['orig' => 1, 'CST' => '00'], 'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07']]]);
        $p['destinatario']['endereco'] = ['logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'codigo_municipio' => '1302603',
            'municipio' => 'Manaus', 'uf' => 'AM', 'cep' => '69005000'];
        $p['consumidor_final'] = 0;
        $p['destinatario']['indicador_ie'] = 9;
        $d = $this->json($this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p)));
        $this->assertSame(4, $d['calculo_tributos']['itens'][0]['impostos']['icms']['pICMS'], json_encode($d));
        $this->assertSame('AM', $d['calculo_tributos']['operacao']['uf_destino']);
    }

    public function testAjusteManualExigePapelFiscalEFicaNoAudit(): void
    {
        $owner = $this->signup('dono@empresa.com');
        $this->configureSession($owner); // CRT 1
        $membro = $this->memberOf($owner, 'caixa@empresa.com');
        $regra = fn(array $icms) => ['cfop' => '5102', 'icms' => $icms + ['orig' => 0, 'CSOSN' => '101', 'pCredSN' => 2.5],
            'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07']];

        // parâmetro alterado (alíquota): liberado para qualquer usuário
        $r = $this->sess($membro, 'POST', '/api/v1/nfe?dry_run=1', (string) json_encode($this->nfe([$regra(['pCredSN' => 1.25])])));
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(1.25, $this->json($r)['calculo_tributos']['itens'][0]['impostos']['icms']['vCredICMSSN']);

        // valor final alterado: membro não pode; proprietário pode e fica registrado
        $p = $this->nfe([$regra(['vCredICMSSN' => 3])]);
        $r = $this->sess($membro, 'POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p));
        $this->assertSame(403, $r->status, $r->body);
        $this->assertSame('ajuste_manual_negado', $this->json($r)['erro']['codigo']);
        $this->assertStringContainsString('icms.vCredICMSSN', $r->body);

        $r = $this->sess($owner, 'POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p));
        $this->assertSame(200, $r->status, $r->body);
        $c = $this->json($r)['calculo_tributos'];
        $this->assertSame(['icms.vCredICMSSN'], $c['itens'][0]['ajustes_manuais']);
        $this->assertSame(3, $c['itens'][0]['impostos']['icms']['vCredICMSSN']);
        $audit = $this->auditTrail();
        $this->assertStringContainsString('tributos.ajuste_manual', $audit);
        $this->assertStringContainsString('negado', $audit);
    }

    public function testPdvNfceComRegraCalculaEmiteERegistra(): void
    {
        $this->configure(); // CRT 1, SP
        $this->certificado();
        $nota = json_decode((string) file_get_contents(APP_ROOT . '/examples/nfce-exemplo.json'), true);
        foreach ($nota['itens'] as &$it) {
            unset($it['impostos']);
            $it['regra'] = ['icms' => ['orig' => 0, 'CSOSN' => '102'], 'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07'],
                'ibpt' => ['federal' => 4.2, 'estadual' => 18, 'municipal' => 0]];
        }
        unset($it);
        $r = $this->authed('POST', '/api/v1/pdv/nfce', (string) json_encode(['operacao_id' => 'caixa01-venda-calc-1', 'nota' => $nota]));
        $this->assertSame(201, $r->status, $r->body);
        $d = $this->json($r);
        $this->assertSame('autorizada', $d['status']);
        $this->assertSame('simples_nacional', $d['calculo_tributos']['regime']);
        $this->assertStringContainsString('<CSOSN>102</CSOSN>', $d['xml']);
        $this->assertGreaterThan(0, $this->xmlTot($d['xml'])['vTotTrib']);
        // replay idêntico: mesmo resultado, sem nova transmissão
        $replay = $this->json($this->authed('POST', '/api/v1/pdv/nfce', (string) json_encode(['operacao_id' => 'caixa01-venda-calc-1', 'nota' => $nota])));
        $this->assertEquals($d['calculo_tributos'], $replay['calculo_tributos'], 'Replay devolve o mesmo cálculo (jsonb não preserva a ordem das chaves)');
        $this->assertCount(1, FakeTools::$sentXml);

        // erro de regra aponta o caminho do PDV
        $nota['itens'][0]['regra']['icms'] = ['orig' => 0, 'CSOSN' => '201', 'pICMS' => 18, 'pMVAST' => 40, 'pICMSST' => 18];
        $r = $this->authed('POST', '/api/v1/pdv/nfce', (string) json_encode(['operacao_id' => 'caixa01-venda-calc-2', 'nota' => $nota]));
        $this->assertSame(422, $r->status, $r->body);
        $this->assertContains('nota.itens[0].regra.icms.pCredSN', array_column($this->json($r)['erro']['detalhes'], 'campo'));
    }
}
