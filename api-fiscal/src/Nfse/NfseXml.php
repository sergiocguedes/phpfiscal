<?php

declare(strict_types=1);

namespace App\Nfse;

use App\Nfe\CertificateStore;
use NFePHP\Common\Signer;

/**
 * XML da NFS-e: leitura segura, validação no XSD oficial (resources/nfse/schemas/1.01-<data>), assinatura e
 * compactação (gzip + base64) para a API da Sefin Nacional.
 *
 * Leiaute por ambiente: o governo publica os esquemas de PRODUÇÃO ("Documentação Atual") e de PRODUÇÃO RESTRITA
 * ("Documentação Técnica (Homologação/testes)") separadamente, e a produção restrita adota as mudanças antes.
 * Ambos usam versao="1.01" no XML, mas a estrutura difere (ex.: em 20/10/2026 a produção restrita passou a exigir
 * infDPS/finNFSe e moveu cServ/xDescServ para depois de cNBS/cIntContrib — NT 009). Cada ambiente valida e gera no
 * seu leiaute; NFSE_LEIAUTE_PRODUCAO / NFSE_LEIAUTE_HOMOLOGACAO no .env trocam o leiaute sem nova versão quando o
 * governo promover o novo leiaute para produção.
 *
 * Leitura (OWASP A05/XXE): sem rede (LIBXML_NONET), sem DTD — documento com <!DOCTYPE> é recusado antes do
 * parse, então não há entidades externas nem "billion laughs" — e tamanho máximo.
 */
final class NfseXml
{
    public const SCHEMA_VERSION = '1.01';
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Leiautes embutidos (data do pacote oficial de XSD => origem). */
    public const LEIAUTES = [
        '20260209' => 'NFSe-ESQUEMAS_XSD-v1.01-20260209 (Documentação Atual — Produção)',
        '20261020' => 'NFSe-ESQUEMAS_XSD-PRODREST-v1.01-20261020 (Produção Restrita — NT 009)',
    ];
    /** Leiaute padrão por ambiente (1 = produção, 2 = produção restrita/homologação). */
    public const LEIAUTE_PADRAO = [1 => '20260209', 2 => '20261020'];
    /** A partir deste leiaute a DPS leva finNFSe e xDescServ vai para o fim de cServ. */
    public const LEIAUTE_FIN_NFSE = '20261020';

    /** Leiaute em uso no ambiente ($ambiente 1 ou 2), com troca opcional pelo .env. */
    public static function leiaute(int $ambiente): string
    {
        $amb = $ambiente === 1 ? 1 : 2;
        $env = trim((string) \App\Support\Env::get($amb === 1 ? 'NFSE_LEIAUTE_PRODUCAO' : 'NFSE_LEIAUTE_HOMOLOGACAO', ''));
        if ($env !== '') {
            if (isset(self::LEIAUTES[$env])) {
                return $env;
            }
            \App\Support\Logger::audit('nfse.leiaute_invalido', 'ignorado', ['valor' => mb_substr($env, 0, 20)]);
        }
        return self::LEIAUTE_PADRAO[$amb];
    }

    /** O leiaute exige infDPS/finNFSe (e a nova ordem de cServ)? */
    public static function temFinNFSe(string $leiaute): bool
    {
        return strcmp($leiaute, self::LEIAUTE_FIN_NFSE) >= 0;
    }

    private static function assertLeiaute(string $leiaute): void
    {
        if (!isset(self::LEIAUTES[$leiaute])) {
            throw new \InvalidArgumentException('leiaute NFS-e desconhecido');
        }
    }

    /** XSD oficiais, sem alteração (conferidos por SHA256SUMS). */
    public static function officialSchemaDir(string $leiaute): string
    {
        self::assertLeiaute($leiaute);
        return APP_ROOT . '/resources/nfse/schemas/' . self::SCHEMA_VERSION . '-' . $leiaute . '/';
    }

    /**
     * Cópia de trabalho dos XSD para o libxml. Única diferença: no leiaute 20260209 o tipo TSSerieDPS usa
     * pattern "^0{0,4}\d{1,5}$"; em expressões XML Schema "^" e "$" NÃO são âncoras e sim caracteres literais,
     * então o libxml recusaria qualquer série. A cópia usa "0{0,4}\d{1,5}" (mesmo significado pretendido).
     * O leiaute 20261020 já corrigiu o pattern no oficial (a troca não encontra nada e a cópia fica idêntica).
     */
    public static function schemaDir(string $leiaute): string
    {
        $src = self::officialSchemaDir($leiaute);
        $dir = \App\Support\Paths::storage('tmp/nfse-xsd/' . self::SCHEMA_VERSION . '-' . $leiaute . '-' . substr(hash_file('sha256', $src . 'SHA256SUMS') ?: 'x', 0, 12) . '/');
        if (is_file($dir . '.pronto')) {
            return $dir;
        }
        $tmp = rtrim($dir, '/') . '.' . bin2hex(random_bytes(4));
        mkdir($tmp, 0700, true);
        foreach (glob($src . '*.xsd') ?: [] as $f) {
            $c = (string) file_get_contents($f);
            if (basename($f) === 'tiposSimples_v' . self::SCHEMA_VERSION . '.xsd') {
                $c = str_replace('<xs:pattern value="^0{0,4}\d{1,5}$"/>', '<xs:pattern value="0{0,4}\d{1,5}"/>', $c);
            }
            file_put_contents($tmp . '/' . basename($f), $c);
        }
        touch($tmp . '/.pronto');
        if (!@rename($tmp, rtrim($dir, '/'))) {
            exec('rm -rf ' . escapeshellarg($tmp)); // outro processo já criou
        }
        return $dir;
    }

    /** Carrega XML não confiável (resposta da Sefin/ADN). Lança \UnexpectedValueException se inválido. */
    public static function load(string $xml): \DOMDocument
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES) {
            throw new \UnexpectedValueException('XML vazio ou grande demais');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \UnexpectedValueException('XML com DTD/entidades não é aceito');
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA)) {
                throw new \UnexpectedValueException('XML malformado');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        return $dom;
    }

    /**
     * Valida no XSD oficial. $xsd: DPS_v1.01.xsd, pedRegEvento_v1.01.xsd, NFSe_v1.01.xsd...
     * $leiaute null: usa o leiaute do ambiente indicado no próprio XML (tpAmb; sem tpAmb, homologação).
     * @return list<string> erros (vazio = válido)
     */
    public static function validate(string $xml, string $xsd, ?string $leiaute = null): array
    {
        if ($leiaute !== null) {
            self::assertLeiaute($leiaute);
        }
        if (!preg_match('/^[A-Za-z]+_v1\.0[01]\.xsd$/', $xsd) || !is_file(self::officialSchemaDir($leiaute ?? self::LEIAUTE_PADRAO[2]) . $xsd)) {
            throw new \InvalidArgumentException('schema inválido');
        }
        try {
            $dom = self::load($xml);
        } catch (\UnexpectedValueException $e) {
            return [$e->getMessage()];
        }
        if ($leiaute === null) {
            $amb = $dom->getElementsByTagNameNS(DpsBuilder::NS, 'tpAmb')->item(0);
            $leiaute = self::leiaute($amb !== null && trim($amb->textContent) === '1' ? 1 : 2);
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $ok = $dom->schemaValidate(self::schemaDir($leiaute) . $xsd);
            $errs = [];
            foreach (libxml_get_errors() as $err) {
                $msg = trim($err->message);
                // mensagens do libxml citam o namespace inteiro: encurta para leitura
                $msg = str_replace('{' . DpsBuilder::NS . '}', '', $msg);
                $errs[] = "Linha {$err->line}: $msg";
            }
            return $ok ? [] : array_values(array_unique($errs ?: ['XML inválido no schema']));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * Assina (XMLDSig enveloped, RSA-SHA256, C14N) o elemento $tag (infDPS ou infPedReg) com o certificado
     * A1 da empresa. A assinatura fica sem prefixo de namespace (a Sefin recusa prefixos: E1228).
     */
    public static function sign(string $xml, string $tag): string
    {
        if (!in_array($tag, ['infDPS', 'infPedReg'], true)) {
            throw new \InvalidArgumentException('tag inválida');
        }
        $cert = CertificateStore::load();
        $signed = Signer::sign($cert, $xml, $tag, 'Id', OPENSSL_ALGO_SHA256, [true, false, null, null]);
        return '<?xml version="1.0" encoding="UTF-8"?>' . $signed;
    }

    public static function gzB64(string $xml): string
    {
        return base64_encode((string) gzencode($xml, 9));
    }

    /** Decodifica o campo "...XmlGZipB64" da resposta, com limite de tamanho (zip bomb). */
    public static function unGzB64(string $b64): string
    {
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            throw new \UnexpectedValueException('base64 inválido');
        }
        // descompactação incremental: entrada em blocos de 1 KB (no máximo ~1 MB de saída por bloco)
        $ctx = inflate_init(ZLIB_ENCODING_GZIP);
        $xml = '';
        foreach (str_split($bin, 1024) as $chunk) {
            $out = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
            if ($out === false) {
                throw new \UnexpectedValueException('gzip inválido');
            }
            $xml .= $out;
            if (strlen($xml) > self::MAX_BYTES) {
                throw new \UnexpectedValueException('conteúdo descompactado grande demais');
            }
        }
        if (inflate_get_status($ctx) !== ZLIB_STREAM_END) {
            $out = @inflate_add($ctx, '', ZLIB_FINISH);
            if ($out === false || inflate_get_status($ctx) !== ZLIB_STREAM_END) {
                throw new \UnexpectedValueException('gzip inválido ou incompleto');
            }
            $xml .= $out;
        }
        if (strlen($xml) > self::MAX_BYTES) {
            throw new \UnexpectedValueException('conteúdo descompactado grande demais');
        }
        return $xml;
    }

    /**
     * Dados principais da NFS-e autorizada (leiaute NFSe_v1.01, qualquer data). Chave = Id de infNFSe sem o prefixo "NFS".
     * @return array<string,string|null>
     */
    public static function resumoNfse(string $xml): array
    {
        $dom = self::load($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('n', DpsBuilder::NS);
        $inf = $xp->query('/n:NFSe/n:infNFSe')->item(0);
        if (!$inf instanceof \DOMElement) {
            throw new \UnexpectedValueException('infNFSe ausente');
        }
        $q = fn(string $p) => ($n = $xp->query($p, $inf)->item(0)) !== null ? trim((string) $n->textContent) : null;
        $id = $inf->getAttribute('Id');
        return [
            'chave' => preg_match('/^NFS(\d{50})$/', $id, $m) ? $m[1] : null,
            'numero_nfse' => $q('n:nNFSe'),
            'cStat' => $q('n:cStat'),
            'dh_processamento' => $q('n:dhProc'),
            'id_dps' => ($d = $xp->query('n:DPS/n:infDPS', $inf)->item(0)) instanceof \DOMElement ? $d->getAttribute('Id') : null,
            'base_calculo' => $q('n:valores/n:vBC'),
            'aliquota_aplicada' => $q('n:valores/n:pAliqAplic'),
            'valor_iss' => $q('n:valores/n:vISSQN'),
            'valor_liquido' => $q('n:valores/n:vLiq'),
            'municipio_incidencia' => $q('n:xLocIncid'),
            'descricao_tributacao_nacional' => $q('n:xTribNac'),
        ];
    }
}
