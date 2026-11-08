<?php

declare(strict_types=1);

namespace Tests;

/**
 * POST /api/v1/tributos/calcular — valores conferidos à mão (ver docs/TRIBUTOS-CALCULO.md).
 */
final class TributosCalculoTest extends TestCase
{
    /** @param array<mixed> $body @return array<mixed> */
    private function calc(array $body, int $status = 200, string $query = ''): array
    {
        $r = $this->authed('POST', '/api/v1/tributos/calcular' . $query, (string) json_encode($body));
        $this->assertSame($status, $r->status, $r->body);
        return $this->json($r);
    }

    /** @param array<string,mixed> $regra @param array<string,mixed> $extra @return array<string,mixed> */
    private function item(array $regra, float $qtd = 1, float $vu = 100, array $extra = []): array
    {
        return $extra + ['quantidade' => $qtd, 'valor_unitario' => $vu, 'regra' => $regra + [
            'cfop' => '5102', 'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07'],
        ]];
    }

    /** @param list<array<string,mixed>> $itens @param array<string,mixed> $extra @return array<string,mixed> */
    private function venda(array $itens, int $crt = 3, string $uf = 'SP', array $extra = []): array
    {
        return $extra + ['modelo' => 55, 'emitente' => ['crt' => $crt, 'uf' => $uf], 'itens' => $itens];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function icms(array $r, int $i = 0): array
    {
        return $r['itens'][$i]['impostos']['icms'];
    }

    /** @param array<mixed> $d */
    private function assertCampoComErro(array $d, string $campo): void
    {
        $campos = array_column($d['erro']['detalhes'] ?? [], 'campo');
        $this->assertContains($campo, $campos, json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    public function testExemploDaEspecificacaoCst10ComPisCofinsEIbpt(): void
    {
        $r = $this->calc($this->venda([[
            'quantidade' => 2, 'valor_unitario' => 5.49, 'desconto' => 0.50,
            'regra' => [
                'cfop' => '5405',
                'icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 20, 'pMVAST' => 40, 'pICMSST' => 20],
                'pis' => ['CST' => '01', 'pPIS' => 1.65], 'cofins' => ['CST' => '01', 'pCOFINS' => 7.6],
                'ibpt' => ['federal' => 13.45, 'estadual' => 18, 'municipal' => 0],
            ],
        ]], 3, 'AM'));
        $i = $r['itens'][0];
        $this->assertSame(['vProd' => 10.98, 'vDesc' => 0.5, 'vFrete' => 0, 'vSeg' => 0, 'vOutro' => 0, 'valor_liquido' => 10.48], $i['valores']);
        $this->assertSame(['orig' => 0, 'CST' => '10', 'modBC' => 3, 'vBC' => 10.48, 'pICMS' => 20, 'vICMS' => 2.1,
            'modBCST' => 4, 'pMVAST' => 40, 'vBCST' => 14.67, 'pICMSST' => 20, 'vICMSST' => 0.83], $i['impostos']['icms']);
        $this->assertSame(['CST' => '01', 'vBC' => 10.48, 'pPIS' => 1.65, 'vPIS' => 0.17], $i['impostos']['pis']);
        $this->assertSame(['CST' => '01', 'vBC' => 10.48, 'pCOFINS' => 7.6, 'vCOFINS' => 0.8], $i['impostos']['cofins']);
        $this->assertSame(3.3, $i['valor_aproximado_tributos']);
        $this->assertSame(['federal' => 1.41, 'estadual' => 1.89, 'municipal' => 0, 'total' => 3.3], $i['tributos_aproximados']);
        $t = $r['totais']['ICMSTot'];
        $this->assertSame([10.48, 2.1, 14.67, 0.83, 0.17, 0.8, 11.31, 3.3],
            [$t['vBC'], $t['vICMS'], $t['vBCST'], $t['vST'], $t['vPIS'], $t['vCOFINS'], $t['vNF'], $t['vTotTrib']]);
        $this->assertSame('normal', $r['regime']);
        $this->assertSame([], $i['ajustes_manuais']);
        $this->assertMatchesRegularExpression('/^calc_[0-9a-f]{32}$/', $r['calculo_id']);
    }

    public function testRateioDoCabecalhoEFreteOutrasNaBaseDoIcms(): void
    {
        $cst00 = ['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18], 'pis' => ['CST' => '01', 'pPIS' => 1.65]];
        $r = $this->calc($this->venda([
            $this->item($cst00, 1, 10, ['frete' => 1.00]),
            $this->item($cst00, 1, 10),
            $this->item($cst00, 1, 10),
        ], 3, 'SP', ['rateio' => ['desconto' => 1.00, 'acrescimo' => 0.50]]));
        // desconto 1,00 / 3 = 0,33 cada; sobra 0,01 vai para o maior item (o primeiro, empate)
        $this->assertSame([0.34, 0.33, 0.33], array_map(fn($i) => $i['valores']['vDesc'], $r['itens']));
        $this->assertSame([0.16, 0.17, 0.17], array_map(fn($i) => $i['valores']['vOutro'], $r['itens']));
        // base = líquido + frete + outras: 9,66 + 1,00 + 0,16 = 10,82
        $this->assertSame([10.82, 9.84, 9.84], array_map(fn($i) => $i['impostos']['icms']['vBC'], $r['itens']));
        $this->assertSame([1.95, 1.77, 1.77], array_map(fn($i) => $i['impostos']['icms']['vICMS'], $r['itens']));
        // PIS sobre o valor líquido (sem frete/outras)
        $this->assertSame([9.66, 9.67, 9.67], array_map(fn($i) => $i['impostos']['pis']['vBC'], $r['itens']));
        $t = $r['totais']['ICMSTot'];
        $this->assertSame([30.5, 5.49, 30, 1, 1, 0.5, 30.5], [$t['vBC'], $t['vICMS'], $t['vProd'], $t['vDesc'], $t['vFrete'], $t['vOutro'], $t['vNF']]);
    }

    public function testCst20ComReducaoEDesoneracaoDeduzidaDoTotal(): void
    {
        $r = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '20', 'pICMS' => 18, 'pRedBC' => 33.33,
            'motDesICMS' => 9, 'indDeduzDeson' => 1]])]));
        $this->assertSame(['orig' => 0, 'CST' => '20', 'modBC' => 3, 'pRedBC' => 33.33, 'vBC' => 66.67, 'pICMS' => 18, 'vICMS' => 12,
            'vICMSDeson' => 6, 'motDesICMS' => 9, 'indDeduzDeson' => 1], $this->icms($r));
        $this->assertSame(94, $r['totais']['ICMSTot']['vNF']);
        $this->assertSame(6, $r['totais']['ICMSTot']['vICMSDeson']);
    }

    public function testCst40DesoneracaoPorForaPorDentroESemMotivo(): void
    {
        $regra = ['icms' => ['orig' => 0, 'CST' => '40', 'pICMS' => 18, 'motDesICMS' => 7]];
        $this->assertSame(18, $this->icms($this->calc($this->venda([$this->item($regra)])))['vICMSDeson']);
        $regraDentro = $regra;
        $regraDentro['icms']['metodo_desoneracao'] = 'por_dentro';
        $dentro = $this->calc($this->venda([$this->item($regraDentro)]));
        $this->assertArrayNotHasKey('metodo_desoneracao', $this->icms($dentro), 'parâmetro da regra não vai para o XML');
        $erro = $this->calc($this->venda([$this->item($regra)], 3, 'SP', ['operacao' => ['metodo_desoneracao' => 'por_dentro']]), 422);
        $this->assertCampoComErro($erro, 'operacao.metodo_desoneracao');
        $erro = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '20', 'pICMS' => 18, 'pRedBC' => 10,
            'metodo_desoneracao' => 'por_dentro']])]), 422);
        $this->assertCampoComErro($erro, 'itens[0].regra.icms.metodo_desoneracao');
        $this->assertSame(21.95, $this->icms($dentro)['vICMSDeson']);
        $this->assertSame(100, $dentro['totais']['ICMSTot']['vNF'], 'indDeduzDeson padrão 0 não reduz o total');
        $sem = $this->icms($this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '40']])])));
        $this->assertSame(['orig' => 0, 'CST' => '40'], $sem);
        $erro = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '40', 'vICMSDeson' => 5]])]), 422);
        $this->assertCampoComErro($erro, 'itens[0].regra.icms.motDesICMS');
        $erro = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '41', 'vICMS' => 5]])]), 422);
        $this->assertCampoComErro($erro, 'itens[0].regra.icms.vICMS');
    }

    public function testCst51Diferimento(): void
    {
        $g = $this->icms($this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '51', 'pICMS' => 12, 'pDif' => 33.33]], 2, 100)])));
        $this->assertSame(['orig' => 0, 'CST' => '51', 'modBC' => 3, 'vBC' => 200, 'pICMS' => 12, 'vICMSOp' => 24, 'pDif' => 33.33,
            'vICMSDif' => 8, 'vICMS' => 16], $g);
    }

    public function testCst70ReducaoDaBaseStSobreABaseEStNegativoZerado(): void
    {
        $g = $this->icms($this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '70', 'pICMS' => 18, 'pRedBC' => 10,
            'pMVAST' => 50, 'pRedBCST' => 10, 'pICMSST' => 18]])])));
        $this->assertSame([90, 16.2, 135, 8.1], [$g['vBC'], $g['vICMS'], $g['vBCST'], $g['vICMSST']]);

        $r = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 18, 'pMVAST' => 0, 'pICMSST' => 12]])]));
        $this->assertSame(0, $this->icms($r)['vICMSST']);
        $this->assertStringContainsString('negativo', implode(' ', $r['itens'][0]['alertas']));
    }

    public function testCst60RetidoECst61Monofasico(): void
    {
        $r = $this->calc($this->venda([
            $this->item(['icms' => ['orig' => 0, 'CST' => '60', 'vBCSTRet' => 50, 'pST' => 18, 'vICMSSTRet' => 9]], 1, 60),
            $this->item(['icms' => ['orig' => 0, 'CST' => '61', 'adRemICMSRet' => 1.47]], 40, 6.19, ['qtd_tributavel_unidade' => 1]),
        ]));
        $this->assertSame(['orig' => 0, 'CST' => '60', 'vBCSTRet' => 50, 'pST' => 18, 'vICMSSTRet' => 9], $this->icms($r));
        $this->assertSame(['orig' => 0, 'CST' => '61', 'qBCMonoRet' => 40, 'adRemICMSRet' => 1.47, 'vICMSMonoRet' => 58.8], $this->icms($r, 1));
        $this->assertSame(58.8, $r['totais']['ICMSTot']['vICMSMonoRet']);
    }

    public function testSimplesNacionalCreditoESubstituicao(): void
    {
        $r = $this->calc($this->venda([
            $this->item(['icms' => ['orig' => 0, 'CSOSN' => '101', 'pCredSN' => 2.56]], 1, 100, ['frete' => 10]),
            $this->item(['icms' => ['orig' => 0, 'CSOSN' => '201', 'pICMS' => 18, 'pMVAST' => 40, 'pICMSST' => 18, 'pCredSN' => 1.25]]),
            $this->item(['icms' => ['orig' => 0, 'CSOSN' => '102']]),
        ], 1));
        $this->assertSame('simples_nacional', $r['regime']);
        $this->assertSame(['orig' => 0, 'CSOSN' => '101', 'pCredSN' => 2.56, 'vCredICMSSN' => 2.82], $this->icms($r));
        $this->assertSame(['orig' => 0, 'CSOSN' => '201', 'modBCST' => 4, 'pMVAST' => 40, 'vBCST' => 140, 'pICMSST' => 18, 'vICMSST' => 7.2,
            'pCredSN' => 1.25, 'vCredICMSSN' => 1.25], $this->icms($r, 1));
        $this->assertSame(['orig' => 0, 'CSOSN' => '102'], $this->icms($r, 2));
        $this->assertSame(0, $r['totais']['ICMSTot']['vICMS']);
        $this->assertSame(4.07, $r['totais']['vCredICMSSN']);

        $erro = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18]])], 1), 422);
        $this->assertCampoComErro($erro, 'itens[0].regra.icms.CSOSN');
    }

    public function testInterestadualAliquotaAutomaticaEAlertas(): void
    {
        $sem = fn(int $orig) => $this->item(['cfop' => '6102', 'icms' => ['orig' => $orig, 'CST' => '00']]);
        $r = $this->calc($this->venda([$sem(0), $sem(1)], 3, 'SP', ['operacao' => ['uf_destino' => 'AM']]));
        $this->assertSame([7, 4], [$this->icms($r)['pICMS'], $this->icms($r, 1)['pICMS']]);
        $this->assertTrue($r['operacao']['interestadual']);
        $r = $this->calc($this->venda([$sem(0)], 3, 'AM', ['operacao' => ['uf_destino' => 'SP', 'consumidor_final' => true]]));
        $this->assertSame(12, $this->icms($r)['pICMS']);
        $this->assertStringContainsString('DIFAL', implode(' ', $r['alertas']));

        $r = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18]])], 3, 'SP', ['operacao' => ['uf_destino' => 'RJ']]));
        $alertas = implode(' | ', $r['itens'][0]['alertas']);
        $this->assertStringContainsString('difere da alíquota interestadual esperada (12%)', $alertas);
        $this->assertStringContainsString('CFOP interno 5102', $alertas);
    }

    public function testIpiNaBaseDoIcmsDoConsumidorFinalENaBaseSt(): void
    {
        $ipi = ['CST' => '50', 'pIPI' => 10];
        $r = $this->calc($this->venda([$this->item(['ipi' => $ipi, 'icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18]])], 3, 'SP',
            ['operacao' => ['consumidor_final' => true]]));
        $this->assertSame(['CST' => '50', 'cEnq' => '999', 'vBC' => 100, 'pIPI' => 10, 'vIPI' => 10], $r['itens'][0]['impostos']['ipi']);
        $this->assertSame([110, 19.8], [$this->icms($r)['vBC'], $this->icms($r)['vICMS']]);

        $r = $this->calc($this->venda([$this->item(['ipi' => $ipi, 'icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 18, 'pMVAST' => 40, 'pICMSST' => 18]])]));
        $g = $this->icms($r);
        $this->assertSame([100, 18, 154, 9.72], [$g['vBC'], $g['vICMS'], $g['vBCST'], $g['vICMSST']]);
        $this->assertSame(119.72, $r['totais']['ICMSTot']['vNF']);

        $r = $this->calc($this->venda([$this->item(['ipi' => ['CST' => '50', 'vUnid' => 0.5], 'icms' => ['orig' => 0, 'CST' => '41']], 10, 3)]));
        $this->assertSame(['CST' => '50', 'cEnq' => '999', 'qUnid' => 10, 'vUnid' => 0.5, 'vIPI' => 5], $r['itens'][0]['impostos']['ipi']);

        $r = $this->calc(['modelo' => 65] + $this->venda([$this->item(['ipi' => $ipi, 'icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18]])]));
        $this->assertArrayNotHasKey('ipi', $r['itens'][0]['impostos']);
        $this->assertStringContainsString('NFC-e não tem IPI', implode(' ', $r['itens'][0]['alertas']));
    }

    public function testPisCofinsPorQuantidadeIsentoOutrasEExclusaoDoIcms(): void
    {
        $icms = ['orig' => 0, 'CST' => '00', 'pICMS' => 18];
        $r = $this->calc($this->venda([
            $this->item(['icms' => $icms, 'pis' => ['CST' => '03', 'vAliqProd' => 0.1234], 'cofins' => ['CST' => '04']], 3, 10),
            $this->item(['icms' => $icms, 'pis' => ['CST' => '49'], 'cofins' => ['CST' => '99', 'pCOFINS' => 3]]),
        ]));
        $this->assertSame(['CST' => '03', 'qBCProd' => 3, 'vAliqProd' => 0.1234, 'vPIS' => 0.37], $r['itens'][0]['impostos']['pis']);
        $this->assertSame(['CST' => '04'], $r['itens'][0]['impostos']['cofins']);
        $this->assertSame(['CST' => '49', 'vBC' => 0, 'pPIS' => 0, 'vPIS' => 0], $r['itens'][1]['impostos']['pis']);
        $this->assertSame(['CST' => '99', 'vBC' => 100, 'pCOFINS' => 3, 'vCOFINS' => 3], $r['itens'][1]['impostos']['cofins']);

        $r = $this->calc($this->venda([$this->item(['icms' => $icms, 'pis' => ['CST' => '01', 'pPIS' => 1.65], 'cofins' => ['CST' => '01', 'pCOFINS' => 7.6]])],
            3, 'SP', ['operacao' => ['excluir_icms_base_piscofins' => true]]));
        $this->assertSame([82, 1.35], [$r['itens'][0]['impostos']['pis']['vBC'], $r['itens'][0]['impostos']['pis']['vPIS']]);
        $this->assertSame(6.23, $r['itens'][0]['impostos']['cofins']['vCOFINS']);
    }

    public function testServicoComIssDescontoIncondicionalECondicionado(): void
    {
        $serv = ['quantidade' => 1, 'valor_unitario' => 200, 'desconto' => 20, 'servico' => true, 'regra' => [
            'iss' => ['aliquota' => 5, 'cListServ' => '14.01', 'cMunFG' => '3550308'], 'pis' => ['CST' => '01', 'pPIS' => 0.65], 'cofins' => ['CST' => '01', 'pCOFINS' => 3],
        ]];
        $r = $this->calc($this->venda([$serv]));
        $this->assertSame(['vBC' => 180, 'vAliq' => 5, 'vISSQN' => 9, 'cMunFG' => '3550308', 'cListServ' => '14.01'], $r['itens'][0]['impostos']['issqn']);
        $this->assertArrayNotHasKey('icms', $r['itens'][0]['impostos']);
        $this->assertSame(['vServ' => 200, 'vBC' => 180, 'vISS' => 9, 'vPIS' => 1.17, 'vCOFINS' => 5.4, 'vDescIncond' => 20, 'vDescCond' => 0],
            $r['totais']['ISSQNtot']);
        $this->assertSame(0, $r['totais']['ICMSTot']['vPIS']);

        $r = $this->calc($this->venda([$serv], 3, 'SP', ['operacao' => ['desconto_iss_condicionado' => true]]));
        $this->assertSame(10, $r['itens'][0]['impostos']['issqn']['vISSQN']);
        $this->assertSame(20, $r['totais']['ISSQNtot']['vDescCond']);
    }

    public function testAjusteManualRecalculaDependentesEFicaRegistrado(): void
    {
        $r = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18, 'vBC' => 90]])]));
        $this->assertSame([90, 16.2], [$this->icms($r)['vBC'], $this->icms($r)['vICMS']]);
        $this->assertSame(['icms.vBC'], $r['itens'][0]['ajustes_manuais']);
        $this->assertStringContainsString('icms.vBC informado manualmente (90.00) difere do calculado (100.00)', implode(' ', $r['itens'][0]['alertas']));

        // ICMS próprio manual: o ST deduz o valor informado
        $r = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 18, 'vICMS' => 20, 'pMVAST' => 40, 'pICMSST' => 18]])]));
        $this->assertSame([20, 140, 5.2], [$this->icms($r)['vICMS'], $this->icms($r)['vBCST'], $this->icms($r)['vICMSST']]);

        // valor manual igual ao calculado: registrado, sem alerta
        $r = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18, 'vICMS' => 18]])]));
        $this->assertSame(['icms.vICMS'], $r['itens'][0]['ajustes_manuais']);
        $this->assertSame([], array_filter($r['itens'][0]['alertas'], fn($a) => str_contains($a, 'manualmente')));
    }

    public function testValidacoes(): void
    {
        $d = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 18, 'pMVAST' => 40]])]), 422);
        $this->assertCampoComErro($d, 'itens[0].regra.icms.pICMSST');
        $d = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 180, 'xyz' => 1]], 1, 100, ['perfil_tributario' => 'pt_1'])]), 422);
        $this->assertCampoComErro($d, 'itens[0].regra.icms.pICMS');
        $this->assertCampoComErro($d, 'itens[0].regra.icms.xyz');
        $this->assertCampoComErro($d, 'itens[0].perfil_tributario');
        $d = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '15']])]), 422);
        $this->assertCampoComErro($d, 'itens[0].regra.icms.CST');
        $d = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18]], 1, 10, ['desconto' => 11])]), 422);
        $this->assertCampoComErro($d, 'itens[0].desconto');
        $d = $this->calc(['modelo' => 55, 'itens' => [$this->item(['icms' => ['orig' => 0, 'CSOSN' => '102']])]], 422);
        $this->assertCampoComErro($d, 'emitente.crt');
        $d = $this->calc($this->venda([$this->item(['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18]], 1, 100, ['item' => 1]),
            $this->item(['icms' => ['orig' => 0, 'CST' => '00', 'pICMS' => 18]], 1, 100, ['item' => 1])]), 422);
        $this->assertCampoComErro($d, 'itens[1].item');
        $this->assertSame(401, $this->call('POST', '/api/v1/tributos/calcular', '{}')->status);
    }

    public function testUsaCrtEUfDaEmpresaConfiguradaEDetalhaMemoria(): void
    {
        $this->configure(); // CRT 1, SP
        $r = $this->calc(['itens' => [$this->item(['icms' => ['orig' => 0, 'CSOSN' => '102']])]], 200, '?detalhar=1');
        $this->assertSame('simples_nacional', $r['regime']);
        $this->assertSame('SP', $r['operacao']['uf']);
        $this->assertSame(55, $r['modelo']);
        $this->assertArrayHasKey('memoria_calculo', $r['itens'][0]);

        $r2 = $this->calc(['itens' => [$this->item(['icms' => ['orig' => 0, 'CSOSN' => '102']])]]);
        $this->assertSame($r['calculo_id'], $r2['calculo_id'], 'mesmo cálculo, mesmo id');
        $r3 = $this->calc(['itens' => [$this->item(['icms' => ['orig' => 0, 'CSOSN' => '102']], 2)]]);
        $this->assertNotSame($r['calculo_id'], $r3['calculo_id']);
    }

    public function testImpostosCalculadosSaoAceitosPelaEmissaoDryRun(): void
    {
        $r = $this->authed('PUT', '/api/v1/config', (string) json_encode([
            'cnpj' => '11222333000181', 'ie' => '111111111111', 'razao_social' => 'EMPRESA NORMAL LTDA', 'uf' => 'SP', 'ambiente' => 2, 'crt' => 3,
            'endereco' => ['logradouro' => 'Rua das Flores', 'numero' => '100', 'bairro' => 'Centro', 'codigo_municipio' => '3550308',
                'municipio' => 'Sao Paulo', 'cep' => '01001000'],
            'serie' => 1, 'proximo_numero' => 1,
        ]));
        $this->assertSame(200, $r->status, $r->body);
        $regras = [
            ['cfop' => '5405', 'ipi' => ['CST' => '50', 'pIPI' => 5], 'icms' => ['orig' => 0, 'CST' => '10', 'pICMS' => 18, 'pMVAST' => 40, 'pICMSST' => 18, 'pFCPST' => 2],
                'pis' => ['CST' => '01', 'pPIS' => 1.65], 'cofins' => ['CST' => '01', 'pCOFINS' => 7.6], 'ibpt' => ['federal' => 10, 'estadual' => 18]],
            ['cfop' => '5102', 'icms' => ['orig' => 0, 'CST' => '20', 'pICMS' => 18, 'pRedBC' => 10, 'motDesICMS' => 9],
                'pis' => ['CST' => '03', 'vAliqProd' => 0.05], 'cofins' => ['CST' => '49']],
            ['cfop' => '5102', 'icms' => ['orig' => 0, 'CST' => '51', 'pICMS' => 12, 'pDif' => 50], 'pis' => ['CST' => '04'], 'cofins' => ['CST' => '04']],
        ];
        $calc = $this->calc(['emitente' => ['crt' => 3], 'operacao' => ['consumidor_final' => true],
            'itens' => array_map(fn($g) => ['quantidade' => 2, 'valor_unitario' => 49.9, 'regra' => $g], $regras)]);

        $p = json_decode($this->samplePayload(), true);
        $base = $p['itens'][0];
        $p['itens'] = [];
        foreach ($calc['itens'] as $k => $it) {
            $p['itens'][] = ['codigo' => 'SKU-' . $k, 'cfop' => $it['cfop'], 'impostos' => $it['impostos']]
                + (isset($it['valor_aproximado_tributos']) ? ['valor_aproximado_tributos' => $it['valor_aproximado_tributos']] : [])
                + array_diff_key($base, ['impostos' => 1, 'valor_aproximado_tributos' => 1, 'codigo' => 1, 'cfop' => 1]);
        }
        $p['pagamentos'] = [['forma' => '01', 'valor' => $calc['totais']['ICMSTot']['vNF']]];
        $r = $this->authed('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($p));
        $this->assertSame(200, $r->status, $r->body);
        $d = $this->json($r);
        $this->assertSame($calc['totais']['ICMSTot']['vNF'], $d['valor_total']);

        $dom = new \DOMDocument();
        $dom->loadXML($d['xml']);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $tot = fn(string $tag) => (float) $xp->evaluate("string(//n:ICMSTot/n:$tag)");
        foreach (['vBC', 'vICMS', 'vICMSDeson', 'vBCST', 'vST', 'vFCPST', 'vIPI', 'vPIS', 'vCOFINS', 'vNF'] as $tag) {
            $this->assertSame((float) $calc['totais']['ICMSTot'][$tag], $tot($tag), "ICMSTot.$tag do XML = calculado");
        }
    }
}
