<?php
declare(strict_types=1);
namespace Tests;

use App\Nfse\DanfseRenderer;
use PHPUnit\Framework\TestCase as UnitTestCase;

final class DanfseRendererTest extends UnitTestCase
{
    protected function setUp(): void
    {
        if (!is_file(APP_ROOT . '/secrets/danfse-fonts/micross.php')) {
            $this->markTestSkipped('Instale as fontes licenciadas conforme docs/DANFSE-NACIONAL.md');
        }
    }

    private function xml(bool $homologacao, bool $leiauteNovo, bool $longo = false): string
    {
        $toma = '<CPF>52998224725</CPF><xNome>Cliente de teste</xNome><end><endNac><cMun>3550308</cMun><CEP>01001000</CEP></endNac><xLgr>Rua teste</xLgr><nro>1</nro><xBairro>Centro</xBairro></end>';
        $trib = '<CST>000</CST><cClassTrib>000001</cClassTrib>';
        $descricao = $longo ? str_repeat('Descrição extensa do serviço; ', 100) : 'Serviço prestado';
        $comp = $longo ? str_repeat('Informação extensa | ', 120) : 'Informação do contribuinte';
        return '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe Id="NFS' . str_repeat('1', 50) . '">'
            . '<xLocEmi>São Paulo</xLocEmi><xLocPrestacao>São Paulo</xLocPrestacao><nNFSe>123</nNFSe><cStat>100</cStat><ambGer>2</ambGer><dhProc>2026-11-03T12:00:00-03:00</dhProc>'
            . '<emit><CNPJ>11444777000161</CNPJ><xNome>Empresa de teste</xNome></emit><valores><vLiq>97.00</vLiq><vTotalRet>3.00</vTotalRet></valores>'
            . '<IBSCBS><cLocalidadeIncid>3550308</cLocalidadeIncid><xLocalidadeIncid>São Paulo</xLocalidadeIncid><valores><vBC>100.00</vBC></valores><totCIBS><vTotNF>97.00</vTotNF><gIBS><vIBSTot>0.10</vIBSTot></gIBS><gCBS><vCBS>0.90</vCBS></gCBS></totCIBS></IBSCBS>'
            . '<DPS><infDPS><tpAmb>' . ($homologacao ? '2' : '1') . '</tpAmb><nDPS>10</nDPS><serie>1</serie><dhEmi>2026-11-03T11:00:00-03:00</dhEmi><dCompet>2026-11-03</dCompet><cLocEmi>3550308</cLocEmi><tpEmit>1</tpEmit>'
            . ($leiauteNovo ? '<finNFSe>0</finNFSe>' : '')
            . '<prest><regTrib><opSimpNac>3</opSimpNac><regApTribSN>1</regApTribSN><regEspTrib>0</regEspTrib></regTrib></prest><toma>' . $toma . '</toma>'
            . ($longo ? '<interm>' . $toma . '</interm>' : '')
            . '<serv><locPrest><cLocPrestacao>3550308</cLocPrestacao></locPrest><cServ><cTribNac>010501</cTribNac><cNBS>111032200</cNBS><xDescServ>' . $descricao . '</xDescServ></cServ><infoCompl><xInfComp>' . $comp . '</xInfComp></infoCompl></serv>'
            . '<valores><vServPrest><vServ>100.00</vServ></vServPrest><trib><tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>1</tpRetISSQN></tribMun><tribFed><vRetCSLL>1.00</vRetCSLL><piscofins><vPis>2.00</vPis><vCofins>3.00</vCofins><tpRetPisCofins>1</tpRetPisCofins></piscofins></tribFed></trib></valores>'
            . '<IBSCBS>' . (!$leiauteNovo ? '<finNFSe>0</finNFSe>' : '') . '<indDest>0</indDest><cIndOp>100501</cIndOp><valores><trib>' . ($leiauteNovo ? $trib : '<gIBSCBS>' . $trib . '</gIBSCBS>') . '</trib></valores></IBSCBS>'
            . '</infDPS></DPS></infNFSe></NFSe>';
    }

    private function conteudo(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
        return implode("\n", array_map(fn ($v) => @gzuncompress($v) ?: $v, $m[1]));
    }

    public function testModeloUnicoNosDoisLeiautesComFontesENumeroCompleto(): void
    {
        foreach ([false, true] as $novo) {
            $pdf = DanfseRenderer::render($this->xml($novo, $novo));
            $txt = $this->conteudo($pdf);
            self::assertSame(1, preg_match_all('~/Type /Page\b~', $pdf));
            self::assertStringContainsString('/ArialMT', $pdf);
            self::assertStringContainsString('/Arial-BoldMT', $pdf);
            self::assertStringContainsString('/MicrosoftSansSerif', $pdf);
            self::assertStringContainsString('DANFSe v2.0', $txt);
            self::assertStringContainsString(str_repeat('1', 50), $txt);
            self::assertStringContainsString('NFS-e regular', $txt, 'Zero de finNFSe deve ser preservado');
            self::assertStringContainsString('000 / 000001', $txt);
            self::assertStringContainsString('R$ 6,00', $txt, 'Contribuições sociais seguem a soma da NT');
            self::assertStringContainsString('R$ 0,00', $txt, 'PIS/COFINS próprios zerados quando retidos');
            self::assertSame($novo, str_contains($txt, 'NFS-e SEM VALIDADE'));
        }
    }

    public function testCancelamentoETexToLongoSemDiminuirFonteOuCriarOutraPagina(): void
    {
        $pdf = DanfseRenderer::render($this->xml(true, true, true), ['cancelada' => true]);
        $txt = $this->conteudo($pdf);
        self::assertSame(1, preg_match_all('~/Type /Page\b~', $pdf));
        self::assertStringContainsString('CANCELADA', $txt);
        self::assertStringContainsString('Cancelada', $txt);
        self::assertStringContainsString('...', $txt);
        preg_match_all('~/F\d+ ([0-9.]+) Tf~', $txt, $sizes);
        self::assertNotEmpty($sizes[1]);
        self::assertGreaterThanOrEqual(6.0, min(array_map('floatval', $sizes[1])));
    }
}
