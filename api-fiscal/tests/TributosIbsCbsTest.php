<?php

declare(strict_types=1);

namespace Tests;

use App\Nfe\SefazClient;
use App\Tributos\TabelaCClassTrib;
use Tests\Support\FakeTools;
use Tests\Support\TestCert;

/**
 * IBS/CBS calculado pela API (NT 2025.002-RTC) na NF-e e na NFC-e. Valores conferidos à mão.
 * Alíquotas informadas pelo sistema (2026: IBS UF 0,10%, IBS municipal 0%, CBS 0,90%); redução pela tabela local de cClassTrib.
 * Base = vProd − desconto − PIS − COFINS − ICMS − FCP.
 */
final class TributosIbsCbsTest extends TestCase
{
    private const ALIQ_2026 = ['pIBSUF' => 0.1, 'pIBSMun' => 0, 'pCBS' => 0.9];

    protected function setUp(): void
    {
        parent::setUp();
        TabelaCClassTrib::importarLinhas([
            ['CST-IBS/CBS' => '000', 'cClassTrib' => '000001', 'Nome cClassTrib' => 'Tributação integral', 'pRedIBS' => '0,00', 'pRedCBS' => '0,00', 'dIniVig' => '01/01/2026'],
            ['CST-IBS/CBS' => '200', 'cClassTrib' => '200034', 'Nome cClassTrib' => 'Alimentos (Anexo I)', 'pRedIBS' => '60,00', 'pRedCBS' => '60,00'],
            ['CST-IBS/CBS' => '200', 'cClassTrib' => '200013', 'Nome cClassTrib' => 'Redução de 100%', 'pRedIBS' => '100', 'pRedCBS' => '100'],
            ['CST-IBS/CBS' => '400', 'cClassTrib' => '400001', 'Nome cClassTrib' => 'Isenção'],
            ['CST-IBS/CBS' => '410', 'cClassTrib' => '410001', 'Nome cClassTrib' => 'Imunidade'],
            ['CST-IBS/CBS' => '510', 'cClassTrib' => '510001', 'Nome cClassTrib' => 'Diferimento'],
            ['CST-IBS/CBS' => '000', 'cClassTrib' => '000002', 'Nome cClassTrib' => 'Encerrado', 'dFimVig' => '2026-06-30'],
            ['CST-IBS/CBS' => '410', 'cClassTrib' => '410014', 'Nome cClassTrib' => 'Produtor rural não contribuinte', 'ind_gCredPresOper' => '1'],
            ['CST-IBS/CBS' => '000', 'cClassTrib' => '000003', 'Nome cClassTrib' => 'Sem crédito presumido', 'ind_gCredPresOper' => '0'],
        ], 'teste', 'IT-teste');
    }
    /** @param array<mixed> $body @return array<mixed> */
    private function calc(array $body, int $status = 200): array
    {
        $r = $this->authed('POST', '/api/v1/tributos/calcular', (string) json_encode($body));
        $this->assertSame($status, $r->status, $r->body);
        return $this->json($r);
    }

    /** @param array<string,mixed> $ibscbs @param array<string,mixed> $icms @return array<string,mixed> */
    private function item(array $ibscbs, array $icms = ['orig' => 0, 'CST' => '00', 'pICMS' => 18], float $vu = 100, array $extra = []): array
    {
        return $extra + ['quantidade' => 1, 'valor_unitario' => $vu, 'regra' => [
            'cfop' => '5102', 'icms' => $icms, 'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07'], 'ibscbs' => $ibscbs,
        ]];
    }

    /** @param list<array<string,mixed>> $itens @return array<string,mixed> */
    private function venda(array $itens, int $crt = 3, string $data = '2026-11-09', ?array $aliq = self::ALIQ_2026): array
    {
        return ['modelo' => 55, 'emitente' => ['crt' => $crt, 'uf' => 'SP'],
            'operacao' => ['data' => $data] + ($aliq === null ? [] : ['aliquotas_ibscbs' => $aliq]), 'itens' => $itens];
    }

    /** @param array<mixed> $d */
    private function assertCampoComErro(array $d, string $campo): void
    {
        $this->assertContains($campo, array_column($d['erro']['detalhes'] ?? [], 'campo'), json_encode($d, JSON_UNESCAPED_UNICODE));
    }

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

    /** @param array<mixed>|null $body @return array<mixed> */
    private function req(string $m, string $uri, ?array $body = null, int $status = 200): array
    {
        $r = $this->authed($m, '/api/v1' . $uri, $body === null ? '' : (string) json_encode($body));
        $this->assertSame($status, $r->status, $r->body);
        return $r->body === '' ? [] : $this->json($r);
    }

    public function testTributacaoIntegralComStPisCofinsEVItem(): void
    {
        // exemplo da especificação: 2 × 5,49 − 0,50; ICMS 2,10; ST 0,83; PIS 0,17; COFINS 0,80
        $r = $this->calc($this->venda([[
            'quantidade' => 2, 'valor_unitario' => 5.49, 'desconto' => 0.50,
            'regra' => [
                'cfop' => '5405', 'icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 20, 'pMVAST' => 40, 'pICMSST' => 20],
                'pis' => ['CST' => '01', 'pPIS' => 1.65], 'cofins' => ['CST' => '01', 'pCOFINS' => 7.6],
                'ibscbs' => ['CST' => '000', 'cClassTrib' => '000001'],
            ],
        ]]));
        $i = $r['itens'][0];
        // base: 10,98 − 0,50 − 0,17 − 0,80 − 2,10 = 7,41; IBS UF 0,0074 → 0,01; CBS 0,0667 → 0,07
        $this->assertSame(['CST' => '000', 'cClassTrib' => '000001', 'gIBSCBS' => [
            'vBC' => 7.41, 'gIBSUF' => ['pIBSUF' => 0.1, 'vIBSUF' => 0.01], 'gIBSMun' => ['pIBSMun' => 0, 'vIBSMun' => 0],
            'vIBS' => 0.01, 'gCBS' => ['pCBS' => 0.9, 'vCBS' => 0.07],
        ]], $i['impostos']['ibscbs']);
        // vItem = 10,98 − 0,50 + ST 0,83; em 2026 sem IBS/CBS
        $this->assertSame(11.31, $i['valor_item']);
        $this->assertSame(11.31, $r['totais']['vNFTot']);
        $this->assertSame($r['totais']['ICMSTot']['vNF'], $r['totais']['vNFTot']);
        $this->assertSame(['vBCIBSCBS' => 7.41,
            'gIBS' => ['gIBSUF' => ['vDif' => 0, 'vDevTrib' => 0, 'vIBSUF' => 0.01], 'gIBSMun' => ['vDif' => 0, 'vDevTrib' => 0, 'vIBSMun' => 0],
                'vIBS' => 0.01, 'vCredPres' => 0, 'vCredPresCondSus' => 0],
            'gCBS' => ['vDif' => 0, 'vDevTrib' => 0, 'vCBS' => 0.07, 'vCredPres' => 0, 'vCredPresCondSus' => 0]], $r['totais']['IBSCBSTot']);
        $this->assertSame([], $r['alertas']);
        $this->assertSame(2026, $r['operacao']['ano']);
    }

    public function testReducaoDiferimentoIsencaoEImunidade(): void
    {
        $r = $this->calc($this->venda([
            // CST 200, cClassTrib 200034 (tabela: redução de 60%): base 100 − ICMS 18 = 82; IBS UF 0,04% → 0,03; CBS 0,36% → 0,30
            $this->item(['CST' => '200', 'cClassTrib' => '200034']),
            // CST 510 diferimento 100% (ICMS isento): base 100; IBS UF 0,10 diferido; CBS 0,90 diferido
            $this->item(['CST' => '510', 'cClassTrib' => '510001', 'pDif' => 100], ['orig' => 0, 'CST' => '40']),
            // CST 410: só CST e cClassTrib, sem valores
            $this->item(['CST' => '410', 'cClassTrib' => '410001'], ['orig' => 0, 'CST' => '41'], 50),
        ]));
        $this->assertSame(['vBC' => 82, 'gIBSUF' => ['pIBSUF' => 0.1, 'gRed' => ['pRedAliq' => 60, 'pAliqEfet' => 0.04], 'vIBSUF' => 0.03],
            'gIBSMun' => ['pIBSMun' => 0, 'gRed' => ['pRedAliq' => 60, 'pAliqEfet' => 0], 'vIBSMun' => 0], 'vIBS' => 0.03,
            'gCBS' => ['pCBS' => 0.9, 'gRed' => ['pRedAliq' => 60, 'pAliqEfet' => 0.36], 'vCBS' => 0.3]], $r['itens'][0]['impostos']['ibscbs']['gIBSCBS']);
        $dif = $r['itens'][1]['impostos']['ibscbs']['gIBSCBS'];
        $this->assertSame(['pIBSUF' => 0.1, 'gDif' => ['pDif' => 100, 'vDif' => 0.1], 'vIBSUF' => 0], $dif['gIBSUF']);
        $this->assertSame(['pCBS' => 0.9, 'gDif' => ['pDif' => 100, 'vDif' => 0.9], 'vCBS' => 0], $dif['gCBS']);
        $this->assertSame(['CST' => '410', 'cClassTrib' => '410001'], $r['itens'][2]['impostos']['ibscbs']);
        $this->assertSame(50, $r['itens'][2]['valor_item']);
        $t = $r['totais']['IBSCBSTot'];
        $this->assertSame(182, $t['vBCIBSCBS']);
        $this->assertSame(['vDif' => 0.1, 'vDevTrib' => 0, 'vIBSUF' => 0.03], $t['gIBS']['gIBSUF']);
        $this->assertSame(['vDif' => 0.9, 'vDevTrib' => 0, 'vCBS' => 0.3, 'vCredPres' => 0, 'vCredPresCondSus' => 0], $t['gCBS']);
        $this->assertSame(250, $r['totais']['vNFTot']);
    }

    public function testSimplesNacionalNaoCalculaERegimeNormalSemRegraAlerta(): void
    {
        $r = $this->calc($this->venda([$this->item(['CST' => '000', 'cClassTrib' => '000001'], ['orig' => 0, 'CSOSN' => '102'])], 1));
        $this->assertArrayNotHasKey('ibscbs', $r['itens'][0]['impostos']);
        $this->assertArrayNotHasKey('IBSCBSTot', $r['totais']);
        $this->assertStringContainsString('Simples Nacional/MEI', $r['itens'][0]['alertas'][0]);

        $sem = $this->item([]);
        unset($sem['regra']['ibscbs']);
        $r = $this->calc($this->venda([$sem]));
        $this->assertArrayNotHasKey('IBSCBSTot', $r['totais']);
        $this->assertStringContainsString('sem o grupo IBS/CBS', $r['alertas'][0]);

        // todos ou nenhum: misturar itens com e sem regra de IBS/CBS é recusado
        $d = $this->calc($this->venda([$this->item(['CST' => '000', 'cClassTrib' => '000001']), $sem]), 422);
        $this->assertCampoComErro($d, 'itens[1].cclasstrib');
    }

    public function testAliquotasDaTabelaDaApiPorMunicipioUfENacional(): void
    {
        $item = $this->item(['CST' => '000', 'cClassTrib' => '000001']);
        $venda = fn(string $data, ?string $mun = null, ?array $aliq = null) => ['modelo' => 55, 'emitente' => ['crt' => 3, 'uf' => 'AM', 'codigo_municipio' => '1302603'],
            'operacao' => ['data' => $data] + ($mun ? ['municipio_destino' => $mun] : []) + ($aliq ? ['aliquotas_ibscbs' => $aliq] : []), 'itens' => [$item]];

        // sem JSON e sem linha gravada: alíquota de teste de 2026 da lei
        $r = $this->calc($venda('2026-11-09'));
        $this->assertSame(['tipo' => 'lei', 'id' => 'LC214-2026'], array_intersect_key($r['ibscbs']['origem_aliquotas'], ['tipo' => 1, 'id' => 1]));
        $this->assertSame('1302603', $r['ibscbs']['municipio'], 'sem destino: município do emitente');

        // tabela: nacional 2027, UF AM e município de Manaus
        \App\Tributos\AliquotasIbsCbs::salvar(null, ['abrangencia' => 'nacional', 'inicio' => '2027-01-01', 'pIBSUF' => 0.05, 'pIBSMun' => 0.05, 'pCBS' => 8.8]);
        \App\Tributos\AliquotasIbsCbs::salvar(null, ['abrangencia' => 'uf', 'uf' => 'AM', 'inicio' => '2027-01-01', 'pIBSUF' => 0.06, 'pIBSMun' => 0.05, 'pCBS' => 8.8]);
        $man = \App\Tributos\AliquotasIbsCbs::salvar(null, ['abrangencia' => 'municipio', 'codigo_municipio' => '1302603', 'inicio' => '2027-01-01',
            'pIBSUF' => 0.06, 'pIBSMun' => 0.07, 'pCBS' => 8.8]);
        $this->assertSame('Manaus', $man['municipio']);
        $g = fn(array $r) => [$r['ibscbs']['aliquotas']['pIBSUF'], $r['ibscbs']['aliquotas']['pIBSMun'], $r['ibscbs']['origem_aliquotas']['abrangencia']];
        $this->assertSame([0.06, 0.07, 'municipio'], $g($this->calc($venda('2027-01-04'))));                // emitente Manaus
        $this->assertSame([0.06, 0.05, 'uf'], $g($this->calc($venda('2027-01-04', '1303403'))));           // destino Parintins/AM
        $this->assertSame([0.05, 0.05, 'nacional'], $g($this->calc($venda('2027-01-04', '3550308'))));     // destino São Paulo
        // 2027 soma IBS/CBS no vItem: base 82; UF 0,05 → 0,04; mun 0,05 → 0,04; CBS 8,8 → 7,22
        $r = $this->calc($venda('2027-01-04', '3550308'));
        $this->assertSame(107.3, $r['itens'][0]['valor_item']);
        $this->assertSame(100, $r['totais']['ICMSTot']['vNF'], 'vNF nunca soma IBS/CBS');

        // alíquotas no JSON têm prioridade
        $r = $this->calc($venda('2027-01-04', '1302603', ['pIBSUF' => 0.1, 'pIBSMun' => 0, 'pCBS' => 0.9]));
        $this->assertSame(['tipo' => 'informada'], $r['ibscbs']['origem_aliquotas']);
        $d = $this->calc($venda('2027-01-04', null, ['pIBSUF' => 0.1, 'pCBS' => 101]), 422);
        $this->assertCampoComErro($d, 'operacao.aliquotas_ibscbs.pIBSMun');
        $this->assertCampoComErro($d, 'operacao.aliquotas_ibscbs.pCBS');

        // ano sem linha vigente: recusa
        $d = $this->calc($venda('2025-12-31'), 422);
        $this->assertCampoComErro($d, 'operacao.aliquotas_ibscbs');
        $d = $this->calc($venda('2027-01-04', '9999999'), 422);
        $this->assertCampoComErro($d, 'operacao.municipio_destino');

        // vigência sobreposta para o mesmo local é recusada
        try {
            \App\Tributos\AliquotasIbsCbs::salvar(null, ['abrangencia' => 'uf', 'uf' => 'AM', 'inicio' => '2027-06-01', 'pIBSUF' => 0.06, 'pIBSMun' => 0.05, 'pCBS' => 8.8]);
            $this->fail('deveria recusar');
        } catch (\App\Http\ApiException $e) {
            $this->assertSame('vigencia_sobreposta', $e->errorCode);
        }
    }

    public function testRotasDoAdministradorParaAliquotasMunicipiosECClassTrib(): void
    {
        \App\Support\Env::set('CENTRAL_AUTH', 'true');
        \App\Auth\CentralAccess::createUser(['nome' => 'Administrador', 'email' => 'admin@example.com', 'senha' => self::STRONG, 'acesso_central' => 'admin']);
        $r = $this->call('POST', '/api/v1/auth/login', (string) json_encode(['email' => 'admin@example.com', 'senha' => self::STRONG]));
        $s = $this->sessionFrom($r, 'admin@example.com');
        $this->assertSame(403, $this->authed('GET', '/api/v1/admin/rtc/aliquotas')->status, 'chave de empresa não administra tabelas globais');

        $r = $this->sess($s, 'POST', '/api/v1/admin/rtc/aliquotas', (string) json_encode(['abrangencia' => 'municipio', 'codigo_municipio' => '3550308',
            'inicio' => '2027-01-01', 'pIBSUF' => 0.05, 'pIBSMun' => 0.06, 'pCBS' => 8.8, 'descricao' => 'São Paulo 2027']));
        $this->assertSame(201, $r->status, $r->body);
        $id = $this->json($r)['id'];
        $l = $this->json($this->sess($s, 'GET', '/api/v1/admin/rtc/aliquotas?data=2027-03-01&municipio=3550308'));
        $this->assertSame($id, $l['vigente']['origem']['id']);
        $this->assertSame(200, $this->sess($s, 'PUT', "/api/v1/admin/rtc/aliquotas/$id", (string) json_encode(['abrangencia' => 'municipio',
            'codigo_municipio' => '3550308', 'inicio' => '2027-01-01', 'fim' => '2027-12-31', 'pIBSUF' => 0.05, 'pIBSMun' => 0.06, 'pCBS' => 8.8]))->status);
        $this->assertSame(204, $this->sess($s, 'DELETE', "/api/v1/admin/rtc/aliquotas/$id")->status);

        $m = $this->json($this->sess($s, 'GET', '/api/v1/admin/rtc/municipios?uf=AM&busca=manau'))['municipios'];
        $this->assertSame([['codigo' => '1302603', 'nome' => 'Manaus', 'uf' => 'AM']], $m);

        $csv = "CST-IBS/CBS;cClassTrib;Nome cClassTrib;pRedIBS;pRedCBS;indNFe;indNFCe\n000;000001;Integral;0;0;1;1\n200;200034;Alimentos;60,00;60,00;1;0\n";
        $r = $this->sess($s, 'POST', '/api/v1/admin/rtc/cclasstrib', (string) json_encode(['conteudo' => $csv, 'versao' => 'IT-1.70']));
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(['carregada' => true, 'versao' => 'IT-1.70', 'total' => 2], array_intersect_key($this->json($r)['tabela'], ['carregada' => 1, 'versao' => 1, 'total' => 1]));
        $r = $this->sess($s, 'POST', '/api/v1/admin/rtc/cclasstrib', (string) json_encode(['conteudo' => "cClassTrib;pRedIBS\n12;10\n"]));
        $this->assertSame(422, $r->status);
        $this->assertSame('IT-1.70', \App\Tributos\TabelaCClassTrib::info()['versao']);

        // indNFCe = 0: o cClassTrib não pode ir na NFC-e
        $d = $this->calc(['modelo' => 65, 'emitente' => ['crt' => 3, 'uf' => 'SP'], 'operacao' => ['data' => '2026-11-09'],
            'itens' => [$this->item(['CST' => '200', 'cClassTrib' => '200034'])]], 422);
        $this->assertStringContainsString('não é permitido na NFC-e', json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    public function testValidacoesDaRegra(): void
    {
        $d = $this->calc($this->venda([
            $this->item(['CST' => '200', 'cClassTrib' => '200999']),
            $this->item(['CST' => '000', 'cClassTrib' => '200034']),
            $this->item(['CST' => '620', 'cClassTrib' => '620001']),
            $this->item(['CST' => '000', 'cClassTrib' => '000001', 'pDif' => 10]),
            $this->item(['CST' => '200', 'cClassTrib' => '200034', 'pRedIBS' => 60]),
        ]), 422);
        foreach (['itens[0].regra.ibscbs.cClassTrib', 'itens[1].regra.ibscbs.cClassTrib', 'itens[2].regra.ibscbs.CST', 'itens[3].regra.ibscbs.pDif',
            'itens[4].regra.ibscbs.pRedIBS'] as $c) {
            $this->assertCampoComErro($d, $c);
        }
        // fora da vigência da tabela
        $d = $this->calc($this->venda([$this->item(['CST' => '000', 'cClassTrib' => '000002'])]), 422);
        $this->assertCampoComErro($d, 'itens[0].regra.ibscbs.cClassTrib');
        $this->assertStringContainsString('fora da vigência', json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    public function testTabelaCClassTribConsultaEAtualizacao(): void
    {
        $t = $this->req('GET', '/tributacao/cclasstrib?cst=200');
        $this->assertSame(['carregada' => true, 'versao' => 'IT-teste'], array_intersect_key($t['tabela'], ['carregada' => 1, 'versao' => 1]));
        $this->assertSame(['200013', '200034'], array_column($t['itens'], 'cClassTrib'));
        $this->assertSame(60, $t['itens'][1]['pRedIBS']);
        $this->assertSame(['000001'], array_column($this->req('GET', '/tributacao/cclasstrib?busca=integral')['itens'], 'cClassTrib'));

        // nova versão muda a redução: o cálculo segue a tabela, sem mexer na regra
        $r = TabelaCClassTrib::importarLinhas([['CST' => '200', 'cClassTrib' => '200034', 'Nome cClassTrib' => 'Alimentos', 'pRedIBS' => '40', 'pRedCBS' => '40'],
            ['CST' => '000', 'cClassTrib' => '000001']], 'teste', 'IT-2');
        $this->assertSame(['total' => 2, 'incluidos' => 0, 'removidos' => 7, 'alterados' => 2], $r);
        $c = $this->calc($this->venda([$this->item(['CST' => '200', 'cClassTrib' => '200034'])]));
        $this->assertSame(0.54, $c['itens'][0]['impostos']['ibscbs']['gIBSCBS']['gCBS']['gRed']['pAliqEfet']);

        // arquivo inválido é recusado inteiro e a tabela fica como estava
        try {
            TabelaCClassTrib::importarLinhas([['CST' => '000', 'cClassTrib' => '000001'], ['CST' => '200', 'cClassTrib' => '2000', 'pRedIBS' => '10']], 'x');
            $this->fail('deveria recusar');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cClassTrib inválido', $e->getMessage());
        }
        $this->assertSame('IT-2', TabelaCClassTrib::info()['versao']);
    }

    public function testCClassTribDoProdutoComPerfisDaMatriz(): void
    {
        $this->configurar(3);
        $this->req('PUT', '/tributacao/perfis/1', ['nome' => 'PRODUTO TRIBUTADO', 'tipo' => 'icms']);
        $this->req('PUT', '/tributacao/perfis/PC1', ['nome' => 'TRIBUTADO PIS/COFINS', 'tipo' => 'piscofins']);
        $this->req('PUT', '/tributacao/operacoes/VENDA', ['nome' => 'VENDA DE PRODUTOS']);
        $this->req('PUT', '/tributacao/regras-icms/1/VENDA/SP/F', ['descricao' => 'Tributado', 'cfop' => '5102', 'icms' => ['CST' => '00', 'pICMS' => 18]]);
        $this->req('PUT', '/tributacao/regras-piscofins/PC1/VENDA', ['descricao' => 'Lucro real', 'pis' => ['CST' => '01', 'pPIS' => 1.65],
            'cofins' => ['CST' => '01', 'pCOFINS' => 7.6]]);
        // não existe mais regra/perfil de IBS/CBS
        $this->req('PUT', '/tributacao/perfis/X', ['nome' => 'X', 'tipo' => 'ibscbs'], 422);

        $base = ['quantidade' => 1, 'valor_unitario' => 100, 'origem' => 0, 'perfil_icms' => '1', 'perfil_piscofins' => 'PC1'];
        $r = $this->req('POST', '/tributos/calcular', ['operacao' => ['codigo' => 'VENDA', 'data' => '2026-11-09', 'aliquotas_ibscbs' => self::ALIQ_2026],
            'itens' => [$base + ['cclasstrib' => '000001'], $base + ['cclasstrib' => '200034']]]);
        // CST vem da tabela; base: 100 − PIS 1,65 − COFINS 7,60 − ICMS 18 = 72,75
        $this->assertSame('000', $r['itens'][0]['impostos']['ibscbs']['CST']);
        $this->assertSame('200', $r['itens'][1]['impostos']['ibscbs']['CST']);
        $this->assertSame(72.75, $r['itens'][1]['impostos']['ibscbs']['gIBSCBS']['vBC']);
        // CBS 0,9 × (1 − 60%) = 0,36% → 0,26
        $this->assertSame(0.26, $r['itens'][1]['impostos']['ibscbs']['gIBSCBS']['gCBS']['vCBS']);

        $d = $this->req('POST', '/tributos/calcular', ['operacao' => ['codigo' => 'VENDA', 'aliquotas_ibscbs' => self::ALIQ_2026],
            'itens' => [$base + ['cclasstrib' => '999999'], $base + ['cclasstrib' => '12']]], 422);
        $campos = array_column($d['erro']['detalhes'], 'campo');
        $this->assertContains('itens[0].regra.ibscbs.CST', $campos); // 999 não é CST calculado
        $this->assertContains('itens[1].cclasstrib', $campos);
        // diferimento: cClassTrib do produto + percentual na regra do item
        $r = $this->req('POST', '/tributos/calcular', ['operacao' => ['codigo' => 'VENDA', 'aliquotas_ibscbs' => self::ALIQ_2026],
            'itens' => [$base + ['cclasstrib' => '510001', 'regra' => ['ibscbs' => ['pDif' => 100]]]]]);
        $this->assertSame(0, $r['itens'][0]['impostos']['ibscbs']['gIBSCBS']['gCBS']['vCBS']);
    }

    public function testEmissaoNfeDryRunMontaGruposNoXmlValidado(): void
    {
        $this->configurar(3);
        $p = json_decode($this->samplePayload(), true);
        $base = $p['itens'][0];
        unset($base['impostos'], $base['valor_aproximado_tributos'], $base['cfop']);
        $regra = ['cfop' => '5102', 'icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18], 'pis' => ['CST' => '01', 'pPIS' => 1.65],
            'cofins' => ['CST' => '01', 'pCOFINS' => 7.6], 'ibscbs' => ['CST' => '000', 'cClassTrib' => '000001']];
        $semIbs = $regra;
        unset($semIbs['ibscbs']);
        // item A com regra.ibscbs; item B com o cClassTrib do produto (forma usual)
        $p['itens'] = [['codigo' => 'A', 'regra' => $regra] + $base, ['codigo' => 'B', 'regra' => $semIbs, 'cclasstrib' => '000001'] + $base]; // 2 × 49,90 cada
        $p['pagamentos'] = [['forma' => '01']];
        $p['calculo_tributos'] = ['aliquotas_ibscbs' => self::ALIQ_2026];
        $r = $this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p));
        $this->assertSame(200, $r->status, $r->body);
        $d = $this->json($r);
        // por item: 99,80 − PIS 1,65 − COFINS 7,58 − ICMS 17,96 = 72,61; IBS UF 0,07; CBS 0,65
        foreach (['<CST>000</CST>', '<cClassTrib>000001</cClassTrib>', '<vBC>72.61</vBC>', '<pIBSUF>0.1000</pIBSUF>', '<vIBSUF>0.07</vIBSUF>',
            '<pIBSMun>0.0000</pIBSMun>', '<vIBSMun>0.00</vIBSMun>', '<pCBS>0.9000</pCBS>', '<vCBS>0.65</vCBS>', '<vItem>99.80</vItem>',
            '<vBCIBSCBS>145.22</vBCIBSCBS>', '<vIBS>0.14</vIBS>', '<vCBS>1.30</vCBS>', '<vNFTot>199.60</vNFTot>'] as $tag) {
            $this->assertStringContainsString($tag, $d['xml']);
        }
        $this->assertSame(199.6, $d['valor_total']);
        $this->assertSame(145.22, $d['calculo_tributos']['totais']['IBSCBSTot']['vBCIBSCBS']);

        // RTC informada pelo sistema prevalece sobre a calculada
        unset($p['itens'][1]['cclasstrib']);
        $p['itens'][1]['regra'] = $regra;
        $p['itens'][0]['impostos'] = ['ibscbs' => ['CST' => '410', 'cClassTrib' => '410001']];
        $p['itens'][1]['impostos'] = ['ibscbs' => ['CST' => '410', 'cClassTrib' => '410001']];
        $p['itens'][0]['valor_item'] = '99.80';
        $p['itens'][1]['valor_item'] = '99.80';
        $p['reforma_tributaria'] = ['modo' => 'informado', 'totais' => ['IBSCBSTot' => ['vBCIBSCBS' => '0.00'], 'vNFTot' => '199.60']];
        $d = $this->json($this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p)));
        $this->assertStringContainsString('<CST>410</CST>', $d['xml'] ?? json_encode($d));
        $this->assertStringNotContainsString('<vIBSUF>', $d['xml']);
    }

    public function testCreditoPresumidoDaNaturezaDaOperacao(): void
    {
        // a natureza da operação no ERP manda o cCredPres e os percentuais; o item pode trocar ou anular (null)
        $venda = $this->venda([
            $this->item(['cClassTrib' => '000001']),                                   // base 82: IBS 82×1% = 0,82; CBS 82×3% = 2,46
            $this->item(['cClassTrib' => '410014'], ['orig' => 0, 'CST' => '41'], 50), // sem gIBSCBS: base 50 → 0,50 e 1,50
            $this->item(['cClassTrib' => '000003']),                                   // cClassTrib não admite: alerta e sem grupo
            $this->item(['cClassTrib' => '000001'], extra: ['credito_presumido' => null]),
        ]);
        $venda['operacao']['credito_presumido'] = ['cCredPres' => 1, 'pCredPresIBS' => 1, 'pCredPresCBS' => 3];
        $r = $this->calc($venda);
        $this->assertSame(['vBCCredPres' => 82, 'cCredPres' => '01', 'gIBSCredPres' => ['pCredPres' => 1, 'vCredPres' => 0.82],
            'gCBSCredPres' => ['pCredPres' => 3, 'vCredPres' => 2.46]], $r['itens'][0]['impostos']['ibscbs']['gCredPresOper']);
        $this->assertSame(['vBCCredPres' => 50, 'cCredPres' => '01', 'gIBSCredPres' => ['pCredPres' => 1, 'vCredPres' => 0.5],
            'gCBSCredPres' => ['pCredPres' => 3, 'vCredPres' => 1.5]], $r['itens'][1]['impostos']['ibscbs']['gCredPresOper']);
        $this->assertArrayNotHasKey('gIBSCBS', $r['itens'][1]['impostos']['ibscbs']);
        $this->assertArrayNotHasKey('gCredPresOper', $r['itens'][2]['impostos']['ibscbs']);
        $this->assertStringContainsString('não admite crédito presumido', implode(' ', $r['itens'][2]['alertas'] ?? []) . json_encode($r['alertas'], JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('gCredPresOper', $r['itens'][3]['impostos']['ibscbs']);
        $this->assertSame(1.32, $r['totais']['IBSCBSTot']['gIBS']['vCredPres']);
        $this->assertSame(3.96, $r['totais']['IBSCBSTot']['gCBS']['vCredPres']);

        // só CBS no item; erros: código inválido, sem percentual, item com cClassTrib que não admite
        $venda['operacao']['credito_presumido'] = ['cCredPres' => '2', 'pCredPresCBS' => 2.5];
        $r = $this->calc($venda);
        $this->assertSame(['vBCCredPres' => 82, 'cCredPres' => '02', 'gCBSCredPres' => ['pCredPres' => 2.5, 'vCredPres' => 2.05]],
            $r['itens'][0]['impostos']['ibscbs']['gCredPresOper']);
        $venda['operacao']['credito_presumido'] = ['cCredPres' => 'AB'];
        $this->assertCampoComErro($this->calc($venda, 422), 'operacao.credito_presumido.cCredPres');
        $venda['operacao']['credito_presumido'] = ['cCredPres' => 1];
        $this->assertCampoComErro($this->calc($venda, 422), 'operacao.credito_presumido');
        unset($venda['operacao']['credito_presumido']);
        $venda['itens'][2]['credito_presumido'] = ['cCredPres' => 1, 'pCredPresIBS' => 1];
        $this->assertCampoComErro($this->calc($venda, 422), 'itens[2].credito_presumido');
    }

    public function testCreditoPresumidoNaEmissaoPassaNoXsd(): void
    {
        $this->configurar(3);
        $p = json_decode($this->samplePayload(), true);
        $base = $p['itens'][0];
        unset($base['impostos'], $base['valor_aproximado_tributos'], $base['cfop']);
        $p['itens'] = [['regra' => ['cfop' => '5102', 'icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18], 'pis' => ['CST' => '01', 'pPIS' => 1.65],
            'cofins' => ['CST' => '01', 'pCOFINS' => 7.6]], 'cclasstrib' => '000001'] + $base];
        $p['pagamentos'] = [['forma' => '01']];
        $p['calculo_tributos'] = ['aliquotas_ibscbs' => self::ALIQ_2026, 'credito_presumido' => ['cCredPres' => 1, 'pCredPresIBS' => 1, 'pCredPresCBS' => 3]];
        $r = $this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p));
        $this->assertSame(200, $r->status, $r->body);
        $xml = $this->json($r)['xml'];
        // base 72,61: IBS 0,73; CBS 2,18
        foreach (['<gCredPresOper><vBCCredPres>72.61</vBCCredPres><cCredPres>01</cCredPres><gIBSCredPres><pCredPres>1.0000</pCredPres><vCredPres>0.73</vCredPres></gIBSCredPres>',
            '<gCBSCredPres><pCredPres>3.0000</pCredPres><vCredPres>2.18</vCredPres></gCBSCredPres></gCredPresOper>'] as $tag) {
            $this->assertStringContainsString($tag, $xml);
        }
        $this->assertMatchesRegularExpression('#<vIBS>0\.07</vIBS><vCredPres>0\.73</vCredPres>#', $xml);
    }

    public function testPdvNfceRegimeNormalCalculaEAutoriza(): void
    {
        $this->configurar(3);
        FakeTools::reset();
        $r = $this->authed('POST', '/api/v1/certificado', (string) json_encode([
            'certificado_base64' => base64_encode(TestCert::pfx('teste-rtc')), 'senha' => 'teste-rtc',
        ]));
        $this->assertSame(201, $r->status, $r->body);
        SefazClient::$factory = FakeTools::factory();
        $nota = json_decode((string) file_get_contents(APP_ROOT . '/examples/nfce-exemplo.json'), true);
        foreach ($nota['itens'] as &$it) {
            unset($it['impostos']);
            $it['regra'] = ['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18], 'pis' => ['CST' => '01', 'pPIS' => 1.65],
                'cofins' => ['CST' => '01', 'pCOFINS' => 7.6], 'ibscbs' => ['CST' => '000', 'cClassTrib' => '000001']];
        }
        unset($it);
        $nota['calculo_tributos'] = ['aliquotas_ibscbs' => self::ALIQ_2026];
        $r = $this->authed('POST', '/api/v1/pdv/nfce', (string) json_encode(['operacao_id' => 'caixa01-rtc-1', 'nota' => $nota]));
        $this->assertSame(201, $r->status, $r->body);
        $d = $this->json($r);
        $this->assertSame('autorizada', $d['status']);
        $this->assertStringContainsString('<IBSCBS>', $d['xml']);
        $this->assertStringContainsString('<vNFTot>', $d['xml']);
        $this->assertArrayHasKey('IBSCBSTot', $d['calculo_tributos']['totais']);
    }
}
