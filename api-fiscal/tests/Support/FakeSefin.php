<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Nfse\DpsBuilder;
use App\Nfse\NfseXml;

/**
 * Dublê HTTP da Sefin Nacional / ADN (nenhuma rede). Instalado em App\Nfse\SefinClient::$transport.
 * Confere o que o emissor envia (JSON com XML gzip+base64, DPS assinada e válida no XSD) e responde como a API:
 *  - POST /nfse                 -> 201 {chaveAcesso, nfseXmlGZipB64} | 400 {erros}
 *  - GET  /nfse/{chave}         -> 200 {chaveAcesso, nfseXmlGZipB64}
 *  - GET  /dps/{id}             -> 200 {chaveAcesso} | 404
 *  - POST /nfse/{chave}/eventos -> 201 {eventoXmlGZipB64} | 400 {erros}
 *  - GET  {adn}/parametrizacao/{cMun}/convenio -> 200/404 (JSON do ADN)
 *  - GET  {adn}/danfse/{chave}  -> application/pdf
 */
final class FakeSefin
{
    /** @var list<array{metodo:string, url:string, body:?string}> */
    public array $calls = [];
    /** "autorizar" | "rejeitar" | "timeout" | "html" | "e0014" */
    public string $modo = 'autorizar';
    public bool $cancelarOk = true;
    /** v2.9.3: modo 'rejeitar_com' devolve estes erros (formato da Sefin). @var list<array<string,mixed>> */
    public array $errosRejeicao = [];
    public int $convenioStatus = 200;
    /** @var array<string,mixed> */
    public array $convenioJson = ['parametrosConvenio' => ['aderenteEmissorNacional' => 1, 'aderenteAmbienteNacional' => 1]];
    /** @var array<string,string> idDps => xml NFS-e (o que "existe" no governo) */
    public array $geradas = [];
    /** @var list<string> DPS recebidas (XML descompactado) */
    public array $dpsRecebidas = [];
    /** @var list<string> */
    public array $eventosRecebidos = [];
    /** v2.9.4: resposta do ADN para o DANFSe: "pdf" (200), "503", "timeout" (exceção de rede) ou "html" (200 sem PDF). */
    public string $danfseModo = 'pdf';

    public function install(): void
    {
        \App\Nfse\SefinClient::$transport = \Closure::fromCallable([$this, 'handle']);
    }

    /** @param array<string,string> $headers @return array{status:int, body:string, content_type:string} */
    public function handle(string $method, string $url, ?string $body, array $headers, ?int $timeout = null): array
    {
        $this->calls[] = ['metodo' => $method, 'url' => $url, 'body' => $body, 'timeout' => $timeout];
        \PHPUnit\Framework\Assert::assertMatchesRegularExpression('#^https://(sefin|adn)\.producaorestrita\.nfse\.gov\.br/#', $url, 'só produção restrita nos testes');
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($method === 'POST' && preg_match('#/SefinNacional/nfse$#', $path)) {
            $j = json_decode((string) $body, true);
            \PHPUnit\Framework\Assert::assertSame('application/json', $headers['Content-Type'] ?? null);
            \PHPUnit\Framework\Assert::assertSame(['dpsXmlGZipB64'], array_keys($j));
            $xml = NfseXml::unGzB64($j['dpsXmlGZipB64']);
            $this->dpsRecebidas[] = $xml;
            \PHPUnit\Framework\Assert::assertSame([], NfseXml::validate($xml, 'DPS_v1.01.xsd'), 'DPS assinada deve ser válida no XSD');
            \PHPUnit\Framework\Assert::assertStringContainsString('<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">', $xml, 'assinatura sem prefixo (E1228)');
            $id = self::idDps($xml);
            if ($this->modo === 'timeout') {
                $this->geradas[$id] = $this->nfse($xml);
                throw new \RuntimeException('Operation timed out after 60000 milliseconds');
            }
            if ($this->modo === 'html') {
                return ['status' => 502, 'body' => '<html>Bad gateway</html>', 'content_type' => 'text/html'];
            }
            if ($this->modo === 'rejeitar') {
                return self::json(400, ['tipoAmbiente' => 2, 'idDps' => $id, 'erros' => [
                    ['Codigo' => 'E0310', 'Descricao' => 'O código de tributação nacional informado não existe', 'Complemento' => null],
                ]]);
            }
            if ($this->modo === 'rejeitar_com') {
                return self::json(400, ['tipoAmbiente' => 2, 'idDps' => $id, 'erros' => $this->errosRejeicao]);
            }
            if ($this->modo === 'e0014') {
                $this->geradas[$id] ??= $this->nfse($xml);
                return self::json(400, ['erros' => [['Codigo' => 'E0014', 'Descricao' => 'Conjunto de Série, Número ... já existe']]]);
            }
            $nfse = $this->geradas[$id] = $this->nfse($xml);
            return self::json(201, ['tipoAmbiente' => 2, 'versaoAplicativo' => 'SefinNacional_1.0', 'dataHoraProcessamento' => date('c'),
                'idDps' => $id, 'chaveAcesso' => self::chaveDe($nfse), 'nfseXmlGZipB64' => NfseXml::gzB64($nfse), 'alertas' => []]);
        }
        if ($method === 'GET' && preg_match('#/SefinNacional/dps/(DPS\d{42})$#', $path, $m)) {
            return isset($this->geradas[$m[1]]) ? self::json(200, ['chaveAcesso' => self::chaveDe($this->geradas[$m[1]])]) : self::json(404, ['erro' => ['Codigo' => 'E404', 'Descricao' => 'DPS não encontrada']]);
        }
        if ($method === 'GET' && preg_match('#/SefinNacional/nfse/(\d{50})$#', $path, $m)) {
            foreach ($this->geradas as $x) {
                if (self::chaveDe($x) === $m[1]) {
                    return self::json(200, ['chaveAcesso' => $m[1], 'nfseXmlGZipB64' => NfseXml::gzB64($x)]);
                }
            }
            return self::json(404, ['erro' => ['Codigo' => 'E404', 'Descricao' => 'NFS-e não encontrada']]);
        }
        if ($method === 'POST' && preg_match('#/SefinNacional/nfse/(\d{50})/eventos$#', $path, $m)) {
            $j = json_decode((string) $body, true);
            \PHPUnit\Framework\Assert::assertSame(['pedidoRegistroEventoXmlGZipB64'], array_keys($j));
            $xml = NfseXml::unGzB64($j['pedidoRegistroEventoXmlGZipB64']);
            $this->eventosRecebidos[] = $xml;
            \PHPUnit\Framework\Assert::assertSame([], NfseXml::validate($xml, 'pedRegEvento_v1.01.xsd'));
            \PHPUnit\Framework\Assert::assertStringContainsString('Id="PRE' . $m[1] . '101101"', $xml);
            if (!$this->cancelarOk) {
                return self::json(400, ['erros' => [['Codigo' => 'E0822', 'Descricao' => 'Cancelamento fora do prazo']]]);
            }
            $ev = '<?xml version="1.0" encoding="UTF-8"?><evento xmlns="' . DpsBuilder::NS . '" versao="1.01"><infEvento Id="EVT' . $m[1] . '101101001"><verAplic>x</verAplic></infEvento></evento>';
            return self::json(201, ['tipoAmbiente' => 2, 'eventoXmlGZipB64' => NfseXml::gzB64($ev)]);
        }
        if ($method === 'GET' && preg_match('#^/parametrizacao/(\d{7})/convenio$#', $path)) {
            \PHPUnit\Framework\Assert::assertStringStartsWith('https://adn.', $url, 'convênio é da API ADN Parâmetros Municipais');
            if ($this->convenioStatus === 200) {
                return self::json(200, $this->convenioJson);
            }
            // resposta real do ADN (produção restrita, Contagem/MG, 22/10/2026)
            return self::json($this->convenioStatus, ['parametrosConvenio' => null, 'mensagem' => 'O convênio do o município <Contagem/MG> ainda não está ativo no Sistema Nacional da NFS-e']);
        }
        if ($method === 'GET' && preg_match('#/parametros_municipais/#', $path)) {
            // rota que não existe na Sefin: IIS devolve 404 em HTML (era o endpoint usado até a 2.9.1)
            return ['status' => 404, 'body' => '<!DOCTYPE html><html><head><title>The resource cannot be found.</title></head></html>', 'content_type' => 'text/html; charset=utf-8'];
        }
        if ($method === 'GET' && preg_match('#^/danfse/(\d{50})$#', $path)) {
            if ($this->danfseModo === '503') {
                return ['status' => 503, 'body' => '<html>Service Unavailable</html>', 'content_type' => 'text/html'];
            }
            if ($this->danfseModo === 'timeout') {
                throw new \RuntimeException('falha de comunicação: Operation timed out after 8000 milliseconds');
            }
            if ($this->danfseModo === 'html') {
                return ['status' => 200, 'body' => '<html>erro</html>', 'content_type' => 'text/html'];
            }
            return ['status' => 200, 'body' => "%PDF-1.4\n% DANFSe de teste\n%%EOF", 'content_type' => 'application/pdf'];
        }
        return self::json(404, ['erro' => ['Codigo' => 'X', 'Descricao' => 'rota desconhecida no dublê']]);
    }

    /** @param array<mixed> $j @return array{status:int, body:string, content_type:string} */
    private static function json(int $status, array $j): array
    {
        return ['status' => $status, 'body' => (string) json_encode($j), 'content_type' => 'application/json; charset=utf-8'];
    }

    public static function idDps(string $xml): string
    {
        preg_match('/Id="(DPS\d{42})"/', $xml, $m);
        return $m[1];
    }

    public static function chaveDe(string $nfse): string
    {
        preg_match('/Id="NFS(\d{50})"/', $nfse, $m);
        return $m[1];
    }

    /** NFS-e de teste envolvendo a DPS recebida (estrutura NFSe_v1.01, campos principais). */
    private function nfse(string $dps): string
    {
        $id = self::idDps($dps);
        $n = count($this->geradas) + 1;
        $chave = substr($id, 3, 7) . '2' . '2' . substr($id, 11, 14) . str_pad((string) $n, 13, '0', STR_PAD_LEFT) . date('ym') . '000000001' . '7';
        $dpsSemDecl = preg_replace('/^<\?xml[^>]*\?>/', '', $dps);
        return '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="' . DpsBuilder::NS . '" versao="1.01"><infNFSe Id="NFS' . $chave . '">'
            . '<xLocEmi>Sao Paulo</xLocEmi><xLocPrestacao>Sao Paulo</xLocPrestacao><nNFSe>' . $n . '</nNFSe><cLocIncid>3550308</cLocIncid><xLocIncid>Sao Paulo</xLocIncid>'
            . '<xTribNac>Treinamento</xTribNac><verAplic>SefinNac</verAplic><ambGer>2</ambGer><tpEmis>1</tpEmis><procEmi>1</procEmi><cStat>100</cStat>'
            . '<dhProc>' . date('Y-m-d\TH:i:sP') . '</dhProc><nDFSe>' . $n . '</nDFSe><emit><CNPJ>' . substr($id, 11, 14) . '</CNPJ><xNome>EMPRESA</xNome></emit>'
            . '<valores><vBC>1500.00</vBC><pAliqAplic>2.00</pAliqAplic><vISSQN>30.00</vISSQN><vLiq>1500.00</vLiq></valores>'
            . $dpsSemDecl . '</infNFSe></NFSe>';
    }
}
