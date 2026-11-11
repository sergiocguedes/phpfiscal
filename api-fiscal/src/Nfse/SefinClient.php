<?php

declare(strict_types=1);

namespace App\Nfse;

use App\Http\ApiException;
use App\Nfe\CertificateStore;
use App\Support\Env;
use App\Support\Logger;
use App\Tenancy\Tenant;

/**
 * Cliente HTTP da Sefin Nacional (emissão/consulta/eventos/parâmetros) e do ADN (DANFSe), Sistema Nacional NFS-e.
 * REST + JSON, autenticação mTLS com o certificado A1 ICP-Brasil da empresa.
 *
 * Endpoints (Manual de Contribuintes — Emissor Público API v1.2 e Manual das APIs do ADN, gov.br/nfse):
 *   POST {sefin}/nfse                         {"dpsXmlGZipB64": ...}  -> {chaveAcesso, nfseXmlGZipB64, alertas} | {erros}
 *   GET  {sefin}/nfse/{chave}                 -> {chaveAcesso, nfseXmlGZipB64}
 *   GET  {sefin}/dps/{idDps}                  -> {chaveAcesso}  (recupera a chave quando a resposta do POST se perdeu)
 *   POST {sefin}/nfse/{chave}/eventos         {"pedidoRegistroEventoXmlGZipB64": ...} -> {eventoXmlGZipB64} | {erros}
 *   GET  {adn}/parametrizacao/{cMun}/convenio -> 200 {parametrosConvenio:{aderenteAmbienteNacional, aderenteEmissorNacional,
 *                                                 situacaoEmissaoPadraoContribuintesRFB, ...}, mensagem}
 *                                             | 404 {parametrosConvenio:null, mensagem:"O convênio ... ainda não está ativo"}
 *                                             (Swagger oficial: adn[.producaorestrita].nfse.gov.br/parametrizacao/docs)
 *   GET  {adn}/danfse/{chave}                 -> application/pdf
 *
 * Travas (todas aqui, o único ponto que fala com o governo):
 *  - ambiente fiscal definido por empresa para NFS-e;
 *  - URLs só https://*.nfse.gov.br (mesmo se sobrescritas no .env), sem redirecionamento, com timeout;
 *  - chave privada em arquivo temporário 0600, cifrado com senha aleatória, apagado no finally.
 * Nos testes, $transport substitui o cURL (nenhuma chamada de rede).
 */
final class SefinClient
{
    /** @var (\Closure(string, string, ?string, array<string,string>): array{status:int, body:string, content_type:string})|null */
    public static ?\Closure $transport = null;

    private const URLS = [
        'sefin' => [1 => 'https://sefin.nfse.gov.br/SefinNacional', 2 => 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional'],
        'adn' => [1 => 'https://adn.nfse.gov.br', 2 => 'https://adn.producaorestrita.nfse.gov.br'],
    ];
    private const MAX_RESPONSE = 10 * 1024 * 1024;

    public static function baseUrl(string $servico, int $ambiente): string
    {
        $env = 'NFSE_' . strtoupper($servico) . '_URL_' . ($ambiente === 1 ? 'PRODUCAO' : 'HOMOLOGACAO');
        $url = rtrim((string) (Env::get($env) ?: self::URLS[$servico][$ambiente]), '/');
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !preg_match('/(^|\.)nfse\.gov\.br$/', $host)) {
            // defesa contra SSRF/configuração errada: o certificado da empresa só é apresentado ao governo
            throw new \LogicException("$env deve ser https://*.nfse.gov.br");
        }
        return $url;
    }

    public static function validarAmbiente(int $ambiente): void
    {
        if(!in_array($ambiente,[1,2],true)){throw new \InvalidArgumentException('Ambiente NFS-e deve ser 1 ou 2');}
    }

    /** @return array{status:int, json:array<mixed>|null, body:string, content_type:string} */
    public static function emitir(int $amb, string $dpsAssinada): array
    {
        return self::call($amb, 'POST', self::baseUrl('sefin', $amb) . '/nfse', ['dpsXmlGZipB64' => NfseXml::gzB64($dpsAssinada)]);
    }

    /** @return array{status:int, json:array<mixed>|null, body:string, content_type:string} */
    public static function consultar(int $amb, string $chave): array
    {
        self::assertChave($chave);
        return self::call($amb, 'GET', self::baseUrl('sefin', $amb) . '/nfse/' . $chave);
    }

    /** @return array{status:int, json:array<mixed>|null, body:string, content_type:string} */
    public static function consultarDps(int $amb, string $idDps): array
    {
        if (!preg_match('/^DPS\d{42}$/', $idDps)) {
            throw new \InvalidArgumentException('id da DPS inválido');
        }
        return self::call($amb, 'GET', self::baseUrl('sefin', $amb) . '/dps/' . $idDps);
    }

    /** @return array{status:int, json:array<mixed>|null, body:string, content_type:string} */
    public static function evento(int $amb, string $chave, string $pedidoAssinado): array
    {
        self::assertChave($chave);
        return self::call($amb, 'POST', self::baseUrl('sefin', $amb) . '/nfse/' . $chave . '/eventos', ['pedidoRegistroEventoXmlGZipB64' => NfseXml::gzB64($pedidoAssinado)]);
    }

    /** @return array{status:int, json:array<mixed>|null, body:string, content_type:string} */
    public static function convenio(int $amb, string $cMun): array
    {
        if (!preg_match('/^\d{7}$/', $cMun)) {
            throw new \InvalidArgumentException('município inválido');
        }
        // API "ADN Parâmetros Municipais" (adn.../parametrizacao/docs): a Sefin Nacional NÃO publica
        // /parametros_municipais (devolve 404 HTML genérico, que antes era lido como "sem convênio").
        return self::call($amb, 'GET', self::baseUrl('adn', $amb) . '/parametrizacao/' . $cMun . '/convenio');
    }

    /** @return array{status:int, json:array<mixed>|null, body:string, content_type:string} */
    public static function danfse(int $amb, string $chave): array
    {
        self::assertChave($chave);
        // v2.9.4: timeout curto (NFSE_DANFSE_TIMEOUT, padrão 8 s) — se o ADN não responder, o DANFSe é gerado localmente
        $t = min(30, max(2, Env::int('NFSE_DANFSE_TIMEOUT', 8)));
        return self::call($amb, 'GET', self::baseUrl('adn', $amb) . '/danfse/' . $chave, null, 'application/pdf', $t);
    }

    private static function assertChave(string $chave): void
    {
        if (!preg_match('/^\d{50}$/', $chave)) {
            throw new \InvalidArgumentException('chave inválida');
        }
    }

    /**
     * @param array<string,string>|null $json
     * @return array{status:int, json:array<mixed>|null, body:string, content_type:string}
     */
    private static function call(int $amb, string $method, string $url, ?array $json = null, string $accept = 'application/json', ?int $timeout = null): array
    {
        self::validarAmbiente($amb);
        // limite por empresa de chamadas ao governo (protege o certificado/CNPJ de bloqueio por excesso)
        $wait = \App\Security\RateLimiter::hit('nfse-sefin', Tenant::id(), max(1, Env::int('NFSE_RATE_MAX', 60)), 60);
        if ($wait > 0) {
            throw new ApiException(429, 'muitas_requisicoes', "Limite de chamadas à Sefin Nacional atingido. Tente novamente em $wait s.", [], ['Retry-After' => (string) $wait]);
        }
        $body =$json === null ? null : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = ['Accept' => $accept] + ($body !== null ? ['Content-Type' => 'application/json'] : []);
        $r = self::$transport !== null ? (self::$transport)($method, $url, $body, $headers, $timeout) : self::curl($method, $url, $body, $headers, $timeout);
        $decoded = null;
        if (str_contains(strtolower($r['content_type']), 'json') || (($r['body'][0] ?? '') === '{')) {
            $d = json_decode($r['body'], true, 64);
            $decoded = is_array($d) ? $d : null;
        }
        return ['status' => $r['status'], 'json' => $decoded, 'body' => $r['body'], 'content_type' => $r['content_type']];
    }

    /**
     * Transporte real: cURL com mTLS. Nunca usado nos testes.
     * @param array<string,string> $headers
     * @return array{status:int, body:string, content_type:string}
     */
    private static function curl(string $method, string $url, ?string $body, array $headers, ?int $timeout = null): array
    {
        $cert = CertificateStore::load();
        // mTLS sem arquivos: chave e certificado vão da memória para o cURL (nada de .pem em disco).
        $pass = bin2hex(random_bytes(16));
        $keyPem = '';
        try {
            $key = openssl_pkey_get_private((string) $cert->privateKey);
            if ($key === false || !openssl_pkey_export($key, $keyPem, $pass)) {
                throw new \RuntimeException('chave privada do certificado ilegível');
            }
            $buf = '';
            $ch = curl_init($url);
            $h = [];
            foreach ($headers as $k => $v) {
                $h[] = "$k: $v";
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $h,
                CURLOPT_SSLCERT_BLOB => (string) $cert->publicKey,
                CURLOPT_SSLCERTTYPE => 'PEM',
                CURLOPT_SSLKEY_BLOB => $keyPem,
                CURLOPT_SSLKEYTYPE => 'PEM',
                CURLOPT_KEYPASSWD => $pass,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => $timeout !== null ? min(10, $timeout) : 10,
                CURLOPT_TIMEOUT => $timeout ?? max(10, Env::int('NFSE_TIMEOUT', 60)),
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use (&$buf) {
                    if (strlen($buf) + strlen($chunk) > self::MAX_RESPONSE) {
                        return 0; // aborta respostas grandes demais
                    }
                    $buf .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($ok === false) {
                throw new \RuntimeException('falha de comunicação: ' . $err);
            }
            return ['status' => $status, 'body' => $buf, 'content_type' => $ct];
        } finally {
            $keyPem = str_repeat("\0", strlen($keyPem));
        }
    }
}
