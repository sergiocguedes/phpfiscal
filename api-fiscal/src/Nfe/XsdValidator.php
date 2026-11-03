<?php

declare(strict_types=1);

namespace App\Nfe;

use NFePHP\Common\Validator;

/**
 * Validação contra o XSD oficial vigente da NF-e/NFC-e.
 *
 * v2.7: pacote de liberação PL_010f v1.04 (31/08/2026, NT 2025.002 v1.50 + NT 2026.007), baixado do Portal Nacional
 * da NF-e e guardado SEM alteração em resources/nfe/schemas/PL_010f_v1.04 (SHA-256 em SHA256SUMS, conferido por
 * teste). Ele aceita as notas sem IBS/CBS (grupos opcionais) e as notas com os grupos da Reforma Tributária.
 * Arquivos que o pacote não traz (procNFe, eventos) vêm da pasta equivalente da sped-nfe (PL_010_V1.30).
 */
final class XsdValidator
{
    public const PACOTE = 'PL_010f_v1.04';

    public static function schemaDir(): string
    {
        return APP_ROOT . '/resources/nfe/schemas/' . self::PACOTE . '/';
    }

    public static function libSchemaDir(): string
    {
        return APP_ROOT . '/vendor/nfephp-org/sped-nfe/schemes/' . \App\Fiscal\ReformaTributaria::MAKE_SCHEMA . '/';
    }

    /**
     * O XSD exige <ds:Signature>. Para validar um XML ainda NÃO assinado
     * (dry-run, sem certificado), validamos uma CÓPIA com uma assinatura
     * placeholder estruturalmente válida. O XML devolvido continua sem assinatura.
     * A assinatura em si (criptografia) só é verificada pela SEFAZ.
     *
     * @return list<string> lista de erros (vazia = válido)
     */
    public static function validateUnsignedNFe(string $xml): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return ['XML malformado'];
        }
        $inf = $dom->getElementsByTagName('infNFe')->item(0);
        if (!$inf instanceof \DOMElement) {
            return ['infNFe ausente'];
        }
        $id = $inf->getAttribute('Id');
        $ds = 'http://www.w3.org/2000/09/xmldsig#';
        $sig = '<Signature xmlns="' . $ds . '"><SignedInfo>'
            . '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
            . '<Reference URI="#' . htmlspecialchars($id, ENT_XML1) . '"><Transforms>'
            . '<Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>'
            . '<Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/></Transforms>'
            . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
            . '<DigestValue>AAAAAAAAAAAAAAAAAAAAAAAAAAA=</DigestValue></Reference></SignedInfo>'
            . '<SignatureValue>AAAA</SignatureValue><KeyInfo><X509Data><X509Certificate>AAAA</X509Certificate>'
            . '</X509Data></KeyInfo></Signature>';
        $frag = $dom->createDocumentFragment();
        $frag->appendXML($sig);
        $dom->documentElement->appendChild($frag);
        return self::validate($dom->saveXML(), 'nfe_v4.00.xsd');
    }

    /** @return list<string> */
    public static function validate(string $xml, string $xsdFile): array
    {
        try {
            $dir = is_file(self::schemaDir() . $xsdFile) ? self::schemaDir() : self::libSchemaDir();
            $file = $xsdFile === 'enviNFe_v4.00.xsd' ? APP_ROOT . '/resources/nfe/enviNFe_v4.00.xsd' : $dir . $xsdFile;
            Validator::isValid($xml, $file);
            return [];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $msg = preg_replace('/^Este XML não é válido\.\s*/u', '', $msg) ?? $msg;
            return array_values(array_filter(array_map('trim', preg_split('/\n+/', $msg) ?: [])));
        }
    }
}
