<?php

declare(strict_types=1);

namespace App\Nfe;

use App\Http\ApiException;
use NFePHP\NFe\Common\Webservices;
use NFePHP\NFe\Factories\QRCode;

/**
 * Regras específicas da NFC-e (modelo 65) que não dependem de certificado:
 *  - endereço e versão do QR-Code por UF/ambiente (mesmos arquivos da sped-nfe:
 *    storage/wsnfe_4.00_mod65.xml e storage/uri_consulta_nfce.json);
 *  - se o CSC é obrigatório (QR-Code versão 2.00 exige CSC + id; a versão 3.00,
 *    NT 2025.001, usada hoje em homologação, não usa CSC);
 *  - prévia do grupo infNFeSupl (qrCode + urlChave) no dry-run.
 *
 * Na emissão real quem gera o QR-Code é a própria sped-nfe (Tools::signNFe com model(65)).
 */
final class Nfce
{
    public const MODELO = 65;

    private static function storageDir(): string
    {
        return APP_ROOT . '/vendor/nfephp-org/sped-nfe/storage/';
    }

    /**
     * @return array{versao:string, url_qrcode:string, url_chave:string}
     */
    public static function qrInfo(string $uf, int $tpAmb): array
    {
        $xml = @file_get_contents(self::storageDir() . 'wsnfe_4.00_mod65.xml');
        $uris = json_decode((string) @file_get_contents(self::storageDir() . 'uri_consulta_nfce.json'), true);
        if ($xml === false || !is_array($uris)) {
            throw new \RuntimeException('Arquivos de web services da NFC-e não encontrados na sped-nfe');
        }
        try {
            $std = (new Webservices($xml))->get($uf, $tpAmb, self::MODELO);
        } catch (\Throwable) {
            $std = null;
        }
        $qr = $std->NfeConsultaQR ?? null;
        $urlChave = $uris[(string) $tpAmb][$uf] ?? '';
        if ($qr === null || empty($qr->url) || $urlChave === '') {
            throw new ApiException(422, 'nfce_uf_indisponivel', "NFC-e não disponível para a UF $uf neste ambiente (sem URL de QR-Code/consulta na sped-nfe)");
        }
        return ['versao' => (string) $qr->version, 'url_qrcode' => (string) $qr->url, 'url_chave' => $urlChave];
    }

    public static function exigeCsc(string $uf, int $tpAmb): bool
    {
        return (int) self::qrInfo($uf, $tpAmb)['versao'] < 300;
    }

    /**
     * Garante que a empresa tem CSC quando a versão do QR-Code da UF/ambiente exige.
     * @param array<string,mixed> $cfg
     */
    public static function assertCsc(array $cfg): void
    {
        if (!self::exigeCsc((string) $cfg['uf'], (int) $cfg['ambiente'])) {
            return;
        }
        $csc = ConfigService::csc();
        if ($csc === null) {
            throw new ApiException(422, 'csc_ausente', 'NFC-e exige o CSC (Código de Segurança do Contribuinte) e o identificador do CSC. Gere no portal da SEFAZ do seu estado e cadastre em Empresa › NFC-e (PUT /api/v1/config: csc e csc_id).', [
                ['campo' => 'csc', 'mensagem' => 'CSC e csc_id obrigatórios para NFC-e neste ambiente'],
            ]);
        }
    }

    /**
     * Acrescenta ao XML NÃO assinado (dry-run) o grupo infNFeSupl com qrCode e urlChave,
     * calculado como a sped-nfe faria para emissão on-line (tpEmis=1). O qrCode de emissão
     * on-line não depende da assinatura, então a prévia é idêntica ao que será enviado.
     * @param array<string,mixed> $cfg
     */
    public static function withQrPreview(string $xml, array $cfg): string
    {
        $info = self::qrInfo((string) $cfg['uf'], (int) $cfg['ambiente']);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml, LIBXML_NONET);
        $nfe = $dom->documentElement;
        if ((int) $info['versao'] >= 300) {
            // QR-Code 3.00 on-line (NT 2025.001): <url>?p=<chave>|3|<tpAmb> — mesma regra de
            // QRCode::get300 da sped-nfe, que exige certificado só para o modo off-line
            $inf = $dom->getElementsByTagName('infNFe')->item(0);
            $chave = substr((string) $inf?->getAttribute('Id'), 3, 44);
            $tpAmb = (string) $dom->getElementsByTagName('tpAmb')->item(0)?->nodeValue;
            $url = $info['url_qrcode'];
            $qr = (str_contains($url, '?p=') ? $url : $url . '?p=') . "$chave|3|$tpAmb";
            $supl = $dom->createElement('infNFeSupl');
            $supl->appendChild($dom->createElement('qrCode', htmlspecialchars($qr, ENT_XML1)));
            $supl->appendChild($dom->createElement('urlChave', htmlspecialchars($info['url_chave'], ENT_XML1)));
            $nfe->appendChild($supl);
        } else {
            // QR-Code 2.00 (hash SHA-1 com o CSC): gerado pela própria sped-nfe. putQRTag lê o
            // DigestValue e insere antes de <Signature>, então usamos uma assinatura provisória.
            $csc = ConfigService::csc();
            $ds = 'http://www.w3.org/2000/09/xmldsig#';
            $sig = $dom->createElementNS($ds, 'Signature');
            $si = $dom->createElementNS($ds, 'SignedInfo');
            $si->appendChild($dom->createElementNS($ds, 'DigestValue', 'AAAA'));
            $sig->appendChild($si);
            $nfe->appendChild($sig);
            try {
                QRCode::putQRTag($dom, $csc['csc'] ?? '', $csc['csc_id'] ?? '', $info['versao'], $info['url_qrcode'], $info['url_chave']);
            } catch (\Throwable $e) {
                \App\Support\Logger::exception($e, 'nfce.qrcode');
                throw new ApiException(422, 'qrcode_falhou', 'Não foi possível montar o QR-Code da NFC-e. Confira o CSC e o identificador do CSC.');
            }
            $nfe->removeChild($sig);
        }
        $dom->formatOutput = false;
        return '<?xml version="1.0" encoding="UTF-8"?>' . $dom->saveXML($nfe);
    }

    /** Modelo (55/65) a partir da chave de acesso (posições 21-22). */
    public static function modeloDaChave(string $chave): int
    {
        return (int) substr($chave, 20, 2);
    }
}
