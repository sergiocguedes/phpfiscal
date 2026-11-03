<?php

declare(strict_types=1);

namespace Tests\Support;

use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools;

/**
 * Dublê da SEFAZ: Tools real da sped-nfe (assinatura, QR-Code e validação XSD locais),
 * com os métodos de rede substituídos por respostas fixas. Registra cada chamada com o
 * modelo (55/65) ativo, para os testes conferirem que o web service certo foi usado.
 */
final class FakeTools extends \App\Nfe\SefazTools
{
    /** @var list<array{metodo:string, modelo:int}> */
    public static array $calls = [];
    public static array $sentXml = [];
    public static array $responses = [];
    public static bool $timeoutAfterSend = false;
    public static bool $statusOffline = false;
    public static bool $queryTimeout = false;
    public static string $cStat = '100';
    /** CC-e só responde quando o teste permitir (nos testes de NFC-e ela nunca deve chegar à SEFAZ) */
    public static bool $permitirCce = false;

    /** @var array<string,string> cStat por CNPJ do emitente (pedido split: um vendedor rejeitado, outro autorizado) */
    public static array $cStatPorCnpj = [];
    /** @var array<string,bool> CNPJs cujo envio falha por "comunicação" (status erro) */
    public static array $falharPorCnpj = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$sentXml = [];
        self::$responses = [];
        self::$timeoutAfterSend = false;
        self::$statusOffline = false;
        self::$queryTimeout = false;
        self::$cStat = '100';
        self::$permitirCce = false;
        self::$cStatPorCnpj = [];
        self::$falharPorCnpj = [];
    }

    /** Fábrica para SefazClient::$factory (usa a config/CSC/certificado do tenant atual). */
    public static function factory(): \Closure
    {
        return function (int $modelo = 55): Tools {
            $cfg = \App\Nfe\ConfigService::require();
            $csc = $modelo === 65 ? \App\Nfe\ConfigService::csc() : null;
            $json = (string) json_encode([
                'atualizacao' => date('Y-m-d H:i:s'), 'tpAmb' => (int) $cfg['ambiente'], 'razaosocial' => $cfg['razao_social'],
                'cnpj' => $cfg['cnpj'], 'siglaUF' => $cfg['uf'], 'schemes' => \App\Fiscal\ReformaTributaria::MAKE_SCHEMA, 'versao' => '4.00',
                'CSC' => $csc['csc'] ?? '', 'CSCid' => $csc['csc_id'] ?? '',
            ]);
            return new self($json, \App\Nfe\CertificateStore::load());
        };
    }

    public function __construct(string $json, Certificate $cert)
    {
        parent::__construct($json, $cert);
    }

    private function log(string $m): void
    {
        self::$calls[] = ['metodo' => $m, 'modelo' => $this->modelo];
    }

    public function sefazEnviaLote(array $aXml, string $idLote = '', int $indSinc = 0, bool $compactar = false, array &$xmls = []): string
    {
        $this->log('sefazEnviaLote');
        self::$sentXml[] = $aXml[0];
        $this->isValid('4.00', '<enviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><idLote>' . ($idLote ?: '1') . '</idLote><indSinc>' . $indSinc . '</indSinc>' . preg_replace('/<\?xml.*?\?>/', '', $aXml[0]) . '</enviNFe>', 'enviNFe');
        $this->checkModelFromXml($aXml[0]); // lança se o modelo do XML diferir do Tools
        $d = new \DOMDocument();
        $d->loadXML($aXml[0]);
        $chave = substr($d->getElementsByTagName('infNFe')->item(0)->getAttribute('Id'), 3);
        $dig = $d->getElementsByTagName('DigestValue')->item(0)->nodeValue;
        $emit = (string) $d->getElementsByTagName('emit')->item(0)?->getElementsByTagName('CNPJ')->item(0)?->nodeValue;
        if (!empty(self::$falharPorCnpj[$emit])) {
            throw new \RuntimeException('timeout simulado');
        }
        $cStat = self::$cStatPorCnpj[$emit] ?? self::$cStat;
        $ok = $cStat === '100';
        $response = '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>' . $this->tpAmb . '</tpAmb><verAplic>SP_NFCE</verAplic>'
            . '<cStat>104</cStat><xMotivo>Lote processado</xMotivo><cUF>35</cUF><dhRecbto>' . date('c') . '</dhRecbto>'
            . '<protNFe versao="4.00"><infProt><tpAmb>' . $this->tpAmb . '</tpAmb><verAplic>SP_NFCE</verAplic><chNFe>' . $chave . '</chNFe>'
            . '<dhRecbto>' . date('c') . '</dhRecbto>' . ($ok ? '<nProt>135260000000077</nProt>' : '') . '<digVal>' . $dig . '</digVal>'
            . '<cStat>' . $cStat . '</cStat><xMotivo>' . ($ok ? 'Autorizado o uso da NF-e' : 'Rejeicao: teste') . '</xMotivo></infProt></protNFe></retEnviNFe>';
        self::$responses[$chave] = str_replace(['retEnviNFe', '<cStat>104</cStat>'], ['retConsSitNFe', '<cStat>100</cStat>'], $response);
        if (self::$timeoutAfterSend) {
            throw new \RuntimeException('Resposta perdida depois da autorização');
        }
        return $response;
    }

    public function sefazConsultaChave(string $chave, ?int $tpAmb = null): string
    {
        $this->log('sefazConsultaChave');
        if (self::$queryTimeout) {
            throw new \RuntimeException('timeout na consulta');
        }
        return self::$responses[$chave] ?? '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><cStat>217</cStat><xMotivo>NFe nao consta</xMotivo></retConsSitNFe>';
    }

    public function sefazCancela(string $chave, string $xJust, string $nProt, ?\DateTimeInterface $dhEvento = null, ?string $lote = null): string
    {
        $this->log('sefazCancela');
        return '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>1</idLote><tpAmb>2</tpAmb><verAplic>x</verAplic><cOrgao>35</cOrgao>'
            . '<cStat>128</cStat><xMotivo>Lote de Evento Processado</xMotivo><retEvento versao="1.00"><infEvento>'
            . '<tpAmb>2</tpAmb><verAplic>x</verAplic><cOrgao>35</cOrgao><cStat>135</cStat><xMotivo>Evento registrado e vinculado a NFC-e</xMotivo>'
            . '<chNFe>' . $chave . '</chNFe><tpEvento>110111</tpEvento><xEvento>Cancelamento registrado</xEvento><nSeqEvento>1</nSeqEvento>'
            . '<dhRegEvento>' . date('c') . '</dhRegEvento><nProt>135260000000078</nProt></infEvento></retEvento></retEnvEvento>';
    }

    public function sefazCCe(string $chave, string $xCorrecao, int $nSeqEvento = 1, ?\DateTimeInterface $dhEvento = null, ?string $lote = null): string
    {
        $this->log('sefazCCe');
        if (!self::$permitirCce) {
            throw new \LogicException('CC-e não deveria chegar à SEFAZ neste teste');
        }
        return '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>1</idLote><tpAmb>2</tpAmb><verAplic>x</verAplic><cOrgao>35</cOrgao>'
            . '<cStat>128</cStat><xMotivo>Lote de Evento Processado</xMotivo><retEvento versao="1.00"><infEvento>'
            . '<tpAmb>2</tpAmb><verAplic>x</verAplic><cOrgao>35</cOrgao><cStat>135</cStat><xMotivo>Evento registrado e vinculado a NF-e</xMotivo>'
            . '<chNFe>' . $chave . '</chNFe><tpEvento>110110</tpEvento><xEvento>Carta de Correcao registrada</xEvento><nSeqEvento>' . $nSeqEvento . '</nSeqEvento>'
            . '<dhRegEvento>' . date('c') . '</dhRegEvento><nProt>135260000000080</nProt></infEvento></retEvento></retEnvEvento>';
    }

    public function sefazInutiliza(int $nSerie, int $nIni, int $nFin, string $xJust, ?int $tpAmb = null, ?string $ano = null): string
    {
        $this->log('sefazInutiliza');
        return '<retInutNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><infInut><tpAmb>2</tpAmb><verAplic>x</verAplic>'
            . '<cStat>102</cStat><xMotivo>Inutilizacao de numero homologado</xMotivo><cUF>35</cUF><ano>26</ano><CNPJ>11222333000181</CNPJ>'
            . '<mod>' . $this->modelo . '</mod><serie>' . $nSerie . '</serie><nNFIni>' . $nIni . '</nNFIni><nNFFin>' . $nFin . '</nNFFin>'
            . '<dhRecbto>' . date('c') . '</dhRecbto><nProt>135260000000079</nProt></infInut></retInutNFe>';
    }

    public function sefazStatus(string $uf = '', ?int $tpAmb = null, bool $ignoreContingency = true): string
    {
        $this->log('sefazStatus');
        if (self::$statusOffline) {
            throw new \RuntimeException('SEFAZ indisponivel');
        }
        return '<retConsStatServ xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><verAplic>x</verAplic>'
            . '<cStat>107</cStat><xMotivo>Servico em Operacao</xMotivo><cUF>35</cUF><dhRecbto>' . date('c') . '</dhRecbto><tMed>1</tMed></retConsStatServ>';
    }
}
