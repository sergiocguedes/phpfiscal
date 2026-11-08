<?php

declare(strict_types=1);

namespace Tests;

/**
 * Fase 3: matriz de regras tributárias da empresa (/api/v1/tributacao) e uso dos perfis no cálculo e na emissão.
 */
final class TributacaoMatrizTest extends TestCase
{
    /** @param array<mixed>|null $body @return array<mixed> */
    private function req(string $m, string $uri, ?array $body = null, int $status = 200): array
    {
        $r = $this->authed($m, '/api/v1' . $uri, $body === null ? '' : (string) json_encode($body));
        $this->assertSame($status, $r->status, $r->body);
        return $r->status === 204 ? [] : $this->json($r);
    }

    /** @param array<mixed> $d */
    private function campos(array $d): array
    {
        return array_column($d['erro']['detalhes'] ?? [], 'campo');
    }

    private function configurar(string $uf = 'AM', int $crt = 3): void
    {
        $this->req('PUT', '/config', [
            'cnpj' => '11222333000181', 'ie' => '111111111111', 'razao_social' => 'EMPRESA TESTE LTDA', 'uf' => $uf, 'ambiente' => 2, 'crt' => $crt,
            'endereco' => ['logradouro' => 'Rua das Flores', 'numero' => '100', 'bairro' => 'Centro',
                'codigo_municipio' => $uf === 'AM' ? '1302603' : '3550308', 'municipio' => $uf === 'AM' ? 'Manaus' : 'Sao Paulo', 'cep' => '69005000'],
            'serie' => 1, 'proximo_numero' => 1,
        ]);
    }

    /** Cadastro igual ao da tela do ClickPDV: 1 Produto tributado, 2 Produto substituição, operação venda. */
    private function cadastroBase(): void
    {
        $this->req('PUT', '/tributacao/perfis/1', ['nome' => 'PRODUTO TRIBUTADO', 'tipo' => 'icms']);
        $this->req('PUT', '/tributacao/perfis/2', ['nome' => 'PRODUTO SUBSTITUIÇÃO', 'tipo' => 'icms']);
        $this->req('PUT', '/tributacao/perfis/PC1', ['nome' => 'TRIBUTADO PIS/COFINS', 'tipo' => 'piscofins']);
        $this->req('PUT', '/tributacao/operacoes/VENDA', ['nome' => 'VENDA DE PRODUTOS']);
        $this->req('PUT', '/tributacao/regras-icms/1/VENDA/AM/F', ['descricao' => 'Tributado interno', 'cfop' => '5102',
            'icms' => ['CST' => '00', 'modBC' => 3, 'pICMS' => 20]]);
        $this->req('PUT', '/tributacao/regras-icms/2/VENDA/AM/F', ['descricao' => 'ST interno', 'cfop' => '5405',
            'icms' => ['CST' => '10', 'pICMS' => 20, 'modBCST' => 4, 'pMVAST' => 40, 'pICMSST' => 20]]);
        $this->req('PUT', '/tributacao/regras-piscofins/PC1/VENDA', ['descricao' => 'Lucro real',
            'pis' => ['CST' => '01', 'pPIS' => 1.65], 'cofins' => ['CST' => '01', 'pCOFINS' => 7.6]]);
    }

    public function testCadastroEValidacaoPorCst(): void
    {
        $this->cadastroBase();
        $c = $this->req('GET', '/tributacao');
        $this->assertSame(['1', '2', 'PC1'], array_column($c['perfis'], 'codigo'));
        $this->assertSame(['VENDA'], array_column($c['operacoes'], 'codigo'));
        $this->assertContains('pRedBC', $c['campos_por_cst']['20']);
        $this->assertSame([], $c['campos_por_cst']['60']);

        // campo que não se aplica ao CST, obrigatório ausente, perfil de outro tipo, operação inexistente
        $d = $this->req('PUT', '/tributacao/regras-icms/1/VENDA/AM/J', ['descricao' => 'X', 'cfop' => '5102',
            'icms' => ['CST' => '00', 'pICMS' => 18, 'pMVAST' => 40]], 422);
        $this->assertContains('icms.pMVAST', $this->campos($d));
        $d = $this->req('PUT', '/tributacao/regras-icms/1/VENDA/AM/J', ['descricao' => 'X', 'cfop' => '5102',
            'icms' => ['CST' => '20', 'pICMS' => 18]], 422);
        $this->assertContains('icms.pRedBC', $this->campos($d));
        $d = $this->req('PUT', '/tributacao/regras-icms/PC1/OUTRA/AM/J', ['descricao' => 'X', 'cfop' => '5102',
            'icms' => ['CST' => '00', 'pICMS' => 18]], 422);
        $this->assertContains('perfil', $this->campos($d));
        $this->assertContains('operacao', $this->campos($d));
        $d = $this->req('PUT', '/tributacao/regras-piscofins/PC1/VENDA', ['descricao' => 'X',
            'pis' => ['CST' => '01'], 'cofins' => ['CST' => '07', 'pCOFINS' => 3]], 422);
        $this->assertContains('pis.pPIS', $this->campos($d));
        $this->assertContains('cofins', $this->campos($d));

        // redução de base (CST 20) e diferimento (CST 51) aceitos com os campos do CST
        $r = $this->req('PUT', '/tributacao/regras-icms/1/VENDA/AM/J', ['descricao' => 'Redução', 'cfop' => '5102',
            'icms' => ['CST' => '20', 'pICMS' => 18, 'pRedBC' => 33.33, 'motDesICMS' => 9], 'ipi' => ['CST' => '52', 'cEnq' => '999']]);
        $this->assertSame(['CST' => '20', 'pICMS' => 18, 'pRedBC' => 33.33, 'motDesICMS' => 9], $r['icms']);
        $this->assertCount(3, $this->req('GET', '/tributacao/regras-icms')['regras']);
        $this->assertCount(2, $this->req('GET', '/tributacao/regras-icms?uf=am&operacao=VENDA&perfil=1')['regras']);

        // exclusões: perfil/operação em uso não saem
        $this->assertSame('perfil_em_uso', $this->req('DELETE', '/tributacao/perfis/1', null, 409)['erro']['codigo']);
        $this->assertSame('operacao_em_uso', $this->req('DELETE', '/tributacao/operacoes/VENDA', null, 409)['erro']['codigo']);
        $this->req('DELETE', '/tributacao/regras-icms/1/VENDA/AM/J', null, 204);
        $this->req('DELETE', '/tributacao/regras-icms/1/VENDA/AM/F', null, 204);
        $this->req('DELETE', '/tributacao/perfis/1', null, 204);
        $this->req('DELETE', '/tributacao/regras-icms/1/VENDA/AM/F', null, 404);
    }

    public function testCopiarRegraParaOutrasUfsETiposDePessoa(): void
    {
        $this->cadastroBase();
        $r = $this->req('POST', '/tributacao/regras-icms/copiar', ['origem' => ['perfil' => '2', 'operacao' => 'VENDA', 'uf' => 'AM', 'tipo_pessoa' => 'F'],
            'destino' => ['ufs' => ['*'], 'tipos_pessoa' => ['F', 'J']]]);
        $this->assertSame(28 * 2 - 1, $r['criadas']);
        $this->assertSame([], $r['ignoradas']);
        $rj = $this->req('GET', '/tributacao/regras-icms?perfil=2&uf=RJ')['regras'];
        $this->assertSame(['F', 'J'], array_column($rj, 'tipo_pessoa'));
        $this->assertSame(40, $rj[1]['icms']['pMVAST']);
        $this->assertSame('ST interno', $rj[1]['descricao']);

        // sem sobrescrever, as existentes ficam; com sobrescrever, são substituídas
        $r = $this->req('POST', '/tributacao/regras-icms/copiar', ['origem' => ['perfil' => '1', 'operacao' => 'VENDA', 'uf' => 'AM'],
            'destino' => ['perfis' => ['2'], 'ufs' => ['AM', 'PA']]]);
        $this->assertSame(['criadas' => 0, 'sobrescritas' => 0, 'ignoradas' => ['2/VENDA/AM/F', '2/VENDA/PA/F']], $r);
        $r = $this->req('POST', '/tributacao/regras-icms/copiar', ['origem' => ['perfil' => '1', 'operacao' => 'VENDA', 'uf' => 'AM'],
            'destino' => ['perfis' => ['2'], 'ufs' => ['PA']], 'sobrescrever' => true]);
        $this->assertSame(1, $r['sobrescritas']);
        $this->assertSame('00', $this->req('GET', '/tributacao/regras-icms?perfil=2&uf=PA')['regras'][0]['icms']['CST']);

        $d = $this->req('POST', '/tributacao/regras-icms/copiar', ['origem' => ['perfil' => '2', 'operacao' => 'VENDA', 'uf' => 'AM'],
            'destino' => ['ufs' => ['XX']]], 422);
        $this->assertContains('destino.ufs', $this->campos($d));
        $this->req('POST', '/tributacao/regras-icms/copiar', ['origem' => ['perfil' => '1', 'operacao' => 'VENDA', 'uf' => 'SC']], 404);
    }

    public function testImportacaoEmLoteTudoOuNada(): void
    {
        $lote = [
            'perfis' => [['codigo' => '1', 'nome' => 'PRODUTO TRIBUTADO', 'tipo' => 'icms'], ['codigo' => 'PC1', 'nome' => 'PIS COFINS', 'tipo' => 'piscofins']],
            'operacoes' => [['codigo' => 'VENDA', 'nome' => 'VENDA DE PRODUTOS'], ['codigo' => 'DEVOL', 'nome' => 'DEVOLUÇÃO DE SAÍDA']],
            'regras_icms' => [
                ['perfil' => '1', 'operacao' => 'VENDA', 'uf' => 'AM', 'tipo_pessoa' => 'F', 'descricao' => 'Interno', 'cfop' => '5102', 'icms' => ['CST' => '00', 'pICMS' => 20]],
                ['perfil' => '1', 'operacao' => 'VENDA', 'uf' => 'SP', 'tipo_pessoa' => 'J', 'descricao' => 'Inter', 'cfop' => '6102', 'icms' => ['CST' => '00', 'pICMS' => 12]],
            ],
            'regras_piscofins' => [['perfil' => 'PC1', 'operacao' => 'VENDA', 'descricao' => 'Isento', 'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07']]],
        ];
        $ruim = $lote;
        $ruim['regras_icms'][] = ['perfil' => '1', 'operacao' => 'VENDA', 'uf' => 'RJ', 'tipo_pessoa' => 'F', 'descricao' => 'Ruim', 'cfop' => '6102',
            'icms' => ['CST' => '20', 'pICMS' => 12]];
        $d = $this->req('POST', '/tributacao/importar', $ruim, 422);
        $this->assertContains('regras_icms[2].icms.pRedBC', $this->campos($d));
        $this->assertSame([], $this->req('GET', '/tributacao')['perfis'], 'nada gravado');

        $r = $this->req('POST', '/tributacao/importar', $lote);
        $this->assertSame(['perfis' => 2, 'operacoes' => 2, 'regras_gravadas' => 3, 'regras_ignoradas' => 0], $r);
        $this->assertCount(2, $this->req('GET', '/tributacao/regras-icms')['regras']);
        $this->assertSame('07', $this->req('GET', '/tributacao/regras-piscofins')['regras'][0]['pis']['CST']);
    }

    public function testSoProprietarioAlteraAMatriz(): void
    {
        $owner = $this->signup('dono@empresa.com');
        $membro = $this->memberOf($owner, 'caixa@empresa.com');
        $r = $this->sess($owner, 'PUT', '/api/v1/tributacao/perfis/1', (string) json_encode(['nome' => 'PRODUTO TRIBUTADO', 'tipo' => 'icms']));
        $this->assertSame(200, $r->status, $r->body);
        $r = $this->sess($membro, 'PUT', '/api/v1/tributacao/perfis/2', (string) json_encode(['nome' => 'X', 'tipo' => 'icms']));
        $this->assertSame(403, $r->status, $r->body);
        $r = $this->sess($membro, 'GET', '/api/v1/tributacao');
        $this->assertSame(200, $r->status, $r->body);
        $this->assertCount(1, $this->json($r)['perfis']);
        $this->assertStringContainsString('tributacao.perfil', $this->auditTrail());
    }

    public function testCalculoComPerfisSobreposicaoEErros(): void
    {
        $this->configurar('AM');
        $this->cadastroBase();
        $item = fn(string $perfil, array $extra = []) => $extra + ['quantidade' => 2, 'valor_unitario' => 5.49, 'desconto' => 0.5,
            'origem' => 0, 'perfil_icms' => $perfil, 'perfil_piscofins' => 'PC1'];
        $r = $this->req('POST', '/tributos/calcular', ['operacao' => ['codigo' => 'VENDA', 'tipo_pessoa' => 'F'], 'itens' => [$item('2'), $item('1')]]);
        // mesmo resultado do exemplo com regra informada: CST 10, 20%, MVA 40%
        $this->assertSame(['orig' => 0, 'CST' => '10', 'modBC' => 3, 'vBC' => 10.48, 'pICMS' => 20, 'vICMS' => 2.1, 'modBCST' => 4, 'pMVAST' => 40,
            'vBCST' => 14.67, 'pICMSST' => 20, 'vICMSST' => 0.83], $r['itens'][0]['impostos']['icms']);
        $this->assertSame('5405', $r['itens'][0]['cfop']);
        $this->assertSame(0.17, $r['itens'][0]['impostos']['pis']['vPIS']);
        $this->assertSame(['perfil' => '2', 'operacao' => 'VENDA', 'uf' => 'AM', 'tipo_pessoa' => 'F', 'descricao' => 'ST interno'],
            $r['itens'][0]['regra_aplicada']['icms']);
        $this->assertSame('5102', $r['itens'][1]['cfop']);
        $this->assertSame('VENDA', $r['operacao']['codigo']);

        // sobreposição de um parâmetro e troca de CST
        $r = $this->req('POST', '/tributos/calcular', ['operacao' => ['codigo' => 'VENDA'], 'itens' => [
            $item('1', ['regra' => ['icms' => ['pICMS' => 18]]]),
            $item('1', ['regra' => ['icms' => ['CST' => '40']]]),
        ]]);
        $this->assertSame(1.89, $r['itens'][0]['impostos']['icms']['vICMS']);
        $this->assertSame(['icms.pICMS'], $r['itens'][0]['regra_aplicada']['sobreposicoes']);
        $this->assertSame(['orig' => 0, 'CST' => '40'], $r['itens'][1]['impostos']['icms']);

        // sem regra para a combinação; sem operação; sem origem
        $d = $this->req('POST', '/tributos/calcular', ['operacao' => ['codigo' => 'VENDA', 'tipo_pessoa' => 'J'], 'itens' => [$item('1')]], 422);
        $this->assertContains('itens[0].perfil_icms', $this->campos($d));
        $this->assertStringContainsString('pessoa J', json_encode($d, JSON_UNESCAPED_UNICODE));
        $d = $this->req('POST', '/tributos/calcular', ['itens' => [$item('1')]], 422);
        $this->assertContains('operacao.codigo', $this->campos($d));
        $semOrigem = $item('1');
        unset($semOrigem['origem']);
        $d = $this->req('POST', '/tributos/calcular', ['operacao' => ['codigo' => 'VENDA'], 'itens' => [$semOrigem]], 422);
        $this->assertContains('itens[0].regra.icms.orig', $this->campos($d));
    }

    public function testListagemEDetalheDasRegrasParaOErp(): void
    {
        $this->cadastroBase();
        $this->req('PUT', '/tributacao/regras-icms/1/VENDA/AM/J', ['descricao' => 'Isento SUFRAMA', 'cfop' => '5102',
            'icms' => ['CST' => '40', 'pICMS' => 18, 'motDesICMS' => 7, 'metodo_desoneracao' => 'por_dentro']]);

        $l = $this->req('GET', '/tributacao/regras-icms');
        $this->assertSame(3, $l['total']);
        $r = $l['regras'][0];
        $this->assertSame(['1', 'VENDA', 'AM', 'F'], [$r['perfil'], $r['operacao'], $r['uf'], $r['tipo_pessoa']]);
        $this->assertSame('PRODUTO TRIBUTADO', $r['perfil_nome']);
        $this->assertSame('VENDA DE PRODUTOS', $r['operacao_nome']);
        $this->assertSame('Física', $r['tipo_pessoa_descricao']);
        $this->assertSame('00', $r['cst']);
        $this->assertSame('Tributada integralmente', $r['cst_descricao']);
        $this->assertSame(1, $this->req('GET', '/tributacao/regras-icms?tipo_pessoa=j')['total']);
        $this->assertSame(2, $this->req('GET', '/tributacao/regras-icms?perfil=1')['total']);

        $d = $this->req('GET', '/tributacao/regras-icms/1/VENDA/am/j');
        $this->assertSame('Isento SUFRAMA', $d['descricao']);
        $this->assertSame('Isenta', $d['cst_descricao']);
        $campos = array_column($d['campos'], null, 'campo');
        $this->assertSame('5102', $campos['cfop']['valor']);
        $this->assertSame('Motivo da desoneração', $campos['motDesICMS']['rotulo']);
        $this->assertSame('SUFRAMA', $campos['motDesICMS']['valor_descricao']);
        $this->assertSame('Por dentro: base ÷ (1 − alíquota) × alíquota', $campos['metodo_desoneracao']['valor_descricao']);
        $this->assertSame('Alíquota do ICMS (%)', $campos['pICMS']['rotulo']);
        $this->assertNull($campos['pICMS']['valor_descricao']);
        $this->req('GET', '/tributacao/regras-icms/1/VENDA/RR/J', null, 404);

        $pc = $this->req('GET', '/tributacao/regras-piscofins');
        $this->assertSame(1, $pc['total']);
        $this->assertSame('Alíquota básica', $pc['regras'][0]['pis_cst_descricao']);
        $d = $this->req('GET', '/tributacao/regras-piscofins/PC1/VENDA');
        $this->assertSame('TRIBUTADO PIS/COFINS', $d['perfil_nome']);
        $campos = array_column($d['campos'], null, 'rotulo');
        $this->assertEquals(7.6, $campos['Alíquota da COFINS (%)']['valor']);
        $this->req('GET', '/tributacao/regras-piscofins/PC1/OUTRA', null, 404);
    }

    public function testRegrasDeIssDaNfse(): void
    {
        $this->assertSame(['total' => 0, 'regras' => []], $this->req('GET', '/tributacao/regras-iss'));
        $r = $this->req('PUT', '/tributacao/regras-iss/CURSO', ['descricao' => 'Curso online retido',
            'servico' => ['codigo_tributacao_nacional' => '08.02.01', 'nbs' => '122051900'],
            'iss' => ['tributacao_iss' => 1, 'retencao_iss' => 2, 'aliquota_iss' => 2]]);
        $this->assertSame('080201', $r['servico']['codigo_tributacao_nacional']);
        $this->assertSame('Retido pelo tomador', $r['retencao_iss_descricao']);
        $this->req('PUT', '/tributacao/regras-iss/IMUNE', ['descricao' => 'Templo',
            'servico' => ['codigo_tributacao_nacional' => '080201'], 'iss' => ['tributacao_iss' => 2, 'tipo_imunidade' => 2]]);

        $l = $this->req('GET', '/tributacao/regras-iss');
        $this->assertSame(2, $l['total']);
        $this->assertSame(['CURSO', 'IMUNE'], array_column($l['regras'], 'codigo'));
        $this->assertNotNull($l['regras'][0]['servico_descricao']);
        $this->assertSame('08.02', $l['regras'][0]['item_lc116']);
        $this->assertSame(['IMUNE'], array_column($this->req('GET', '/tributacao/regras-iss?busca=templo')['regras'], 'codigo'));

        $d = $this->req('GET', '/tributacao/regras-iss/IMUNE');
        $campos = array_column($d['campos'], null, 'campo');
        $this->assertSame('Imunidade', $campos['tributacao_iss']['valor_descricao']);
        $this->assertSame('Templos de qualquer culto', $campos['tipo_imunidade']['valor_descricao']);
        $this->assertSame('Não retido', $campos['retencao_iss']['valor_descricao']);
        $this->assertNotNull($campos['codigo_tributacao_nacional']['valor_descricao']);
        $this->req('GET', '/tributacao/regras-iss/NADA', null, 404);

        // validações: serviço inexistente, imune com retenção e alíquota, alíquota acima de 5%, campo estranho
        $d = $this->req('PUT', '/tributacao/regras-iss/X', ['descricao' => 'X', 'servico' => ['codigo_tributacao_nacional' => '999999'],
            'iss' => ['tributacao_iss' => 2, 'retencao_iss' => 2, 'aliquota_iss' => 2, 'outro' => 1]], 422);
        foreach (['servico.codigo_tributacao_nacional', 'iss.retencao_iss', 'iss.aliquota_iss', 'iss.outro'] as $c) {
            $this->assertContains($c, $this->campos($d));
        }
        $d = $this->req('PUT', '/tributacao/regras-iss/X', ['descricao' => 'X', 'servico' => ['codigo_tributacao_nacional' => '080201'],
            'iss' => ['aliquota_iss' => 6]], 422);
        $this->assertContains('iss.aliquota_iss', $this->campos($d));

        $this->req('DELETE', '/tributacao/regras-iss/IMUNE', null, 204);
        $this->assertSame(1, $this->req('GET', '/tributacao/regras-iss')['total']);
        $this->req('DELETE', '/tributacao/regras-iss/IMUNE', null, 404);
    }

    public function testMetodoDaDesoneracaoFicaNaRegraDeIcms(): void
    {
        $this->configurar('AM');
        $this->cadastroBase();
        // isenção com base legal que manda calcular "por dentro"
        $r = $this->req('PUT', '/tributacao/regras-icms/1/VENDA/AM/J', ['descricao' => 'Isento por dentro', 'cfop' => '5102',
            'icms' => ['CST' => '40', 'pICMS' => 18, 'motDesICMS' => 7, 'metodo_desoneracao' => 'por_dentro']]);
        $this->assertSame('por_dentro', $r['icms']['metodo_desoneracao']);
        $this->req('PUT', '/tributacao/regras-icms/1/VENDA/AM/F', ['descricao' => 'Isento por fora', 'cfop' => '5102',
            'icms' => ['CST' => '40', 'pICMS' => 18, 'motDesICMS' => 7]]);
        $d = $this->req('PUT', '/tributacao/regras-icms/1/VENDA/RR/F', ['descricao' => 'X', 'cfop' => '5102',
            'icms' => ['CST' => '00', 'pICMS' => 18, 'metodo_desoneracao' => 'por_dentro']], 422);
        $this->assertContains('icms.metodo_desoneracao', $this->campos($d));
        $d = $this->req('PUT', '/tributacao/regras-icms/1/VENDA/RR/F', ['descricao' => 'X', 'cfop' => '5102',
            'icms' => ['CST' => '40', 'metodo_desoneracao' => 'meio']], 422);
        $this->assertContains('icms.metodo_desoneracao', $this->campos($d));

        $item = ['quantidade' => 1, 'valor_unitario' => 100, 'origem' => 0, 'perfil_icms' => '1', 'perfil_piscofins' => 'PC1'];
        $j = $this->req('POST', '/tributos/calcular', ['operacao' => ['codigo' => 'VENDA', 'tipo_pessoa' => 'J'], 'itens' => [$item]]);
        $f = $this->req('POST', '/tributos/calcular', ['operacao' => ['codigo' => 'VENDA', 'tipo_pessoa' => 'F'], 'itens' => [$item]]);
        $this->assertSame(21.95, $j['itens'][0]['impostos']['icms']['vICMSDeson']);
        $this->assertSame(18, $f['itens'][0]['impostos']['icms']['vICMSDeson']);
        $this->assertArrayNotHasKey('metodo_desoneracao', $j['itens'][0]['impostos']['icms']);
    }

    public function testPautaDoIcmsSt(): void
    {
        $this->configurar('AM');
        $r = $this->req('POST', '/tributos/calcular', ['itens' => [['quantidade' => 2, 'valor_unitario' => 5, 'valor_pauta_st' => 7.5,
            'regra' => ['cfop' => '5405', 'icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 18, 'modBCST' => 5, 'pICMSST' => 18],
                'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07']]]]]);
        // base ST = 2 × 7,50 = 15,00; ST = 2,70 − ICMS próprio 1,80 = 0,90
        $g = $r['itens'][0]['impostos']['icms'];
        $this->assertSame([5, 15, 0.9], [$g['modBCST'], $g['vBCST'], $g['vICMSST']]);
        $d = $this->req('POST', '/tributos/calcular', ['itens' => [['quantidade' => 2, 'valor_unitario' => 5,
            'regra' => ['cfop' => '5405', 'icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 18, 'modBCST' => 0, 'pICMSST' => 18],
                'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07']]]]], 422);
        $this->assertContains('itens[0].valor_pauta_st', $this->campos($d));
    }

    public function testEmissaoComPerfisUsaOperacaoComoNaturezaETipoPessoaDoDestinatario(): void
    {
        $this->configurar('SP');
        $this->req('PUT', '/tributacao/perfis/1', ['nome' => 'PRODUTO TRIBUTADO', 'tipo' => 'icms']);
        $this->req('PUT', '/tributacao/perfis/PC1', ['nome' => 'PIS COFINS', 'tipo' => 'piscofins']);
        $this->req('PUT', '/tributacao/operacoes/VENDA', ['nome' => 'VENDA DE PRODUTOS']);
        $this->req('PUT', '/tributacao/regras-icms/1/VENDA/SP/F', ['descricao' => 'Interno', 'cfop' => '5102', 'icms' => ['CST' => '00', 'pICMS' => 18]]);
        $this->req('PUT', '/tributacao/regras-piscofins/PC1/VENDA', ['descricao' => 'Lucro real',
            'pis' => ['CST' => '01', 'pPIS' => 1.65], 'cofins' => ['CST' => '01', 'pCOFINS' => 7.6]]);

        $p = json_decode($this->samplePayload(), true); // destinatário com CPF em SP
        unset($p['natureza_operacao'], $p['itens'][0]['impostos'], $p['itens'][0]['cfop'], $p['itens'][0]['valor_aproximado_tributos']);
        $p['itens'][0] += ['origem' => 0, 'perfil_icms' => '1', 'perfil_piscofins' => 'PC1'];
        $p['pagamentos'] = [['forma' => '01']];
        $p['calculo_tributos'] = ['operacao' => 'VENDA'];
        $d = $this->req('POST', '/nfe?dry_run=1', $p);
        $this->assertTrue($d['valida'], json_encode($d['erros_schema']));
        $this->assertStringContainsString('<natOp>VENDA DE PRODUTOS</natOp>', $d['xml']);
        $this->assertStringContainsString('<CFOP>5102</CFOP>', $d['xml']);
        $this->assertSame('F', $d['calculo_tributos']['operacao']['tipo_pessoa']);
        $this->assertSame(17.96, $d['calculo_tributos']['itens'][0]['impostos']['icms']['vICMS']);

        // destinatário com CNPJ → pessoa J: sem regra cadastrada, o erro aponta a operação da nota
        $p['destinatario'] = ['cnpj' => '11444777000161', 'nome' => 'Cliente PJ', 'indicador_ie' => 9] + array_intersect_key($p['destinatario'], ['endereco' => 1]);
        $d = $this->req('POST', '/nfe?dry_run=1', $p, 422);
        $this->assertContains('itens[0].perfil_icms', $this->campos($d));
        unset($p['calculo_tributos']);
        $d = $this->req('POST', '/nfe?dry_run=1', $p, 422);
        $this->assertContains('calculo_tributos.operacao', $this->campos($d));
    }
}
