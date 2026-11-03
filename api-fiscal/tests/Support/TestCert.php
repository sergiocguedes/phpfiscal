<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Gera um certificado A1 AUTOASSINADO apenas para testes (não é ICP-Brasil,
 * não serve para a SEFAZ). Inclui o OID 2.16.76.1.3.3 (CNPJ) no SAN, como
 * nos certificados e-CNPJ, para que a sped-common identifique o CNPJ.
 */
final class TestCert
{
    public static function pfx(string $password, string $cnpj = '11222333000181', int $days = 30): string
    {
        $cfgFile = tempnam(sys_get_temp_dir(), 'tc');
        file_put_contents($cfgFile, <<<CFG
[ req ]
distinguished_name = dn
prompt = no
[ dn ]
CN = EMPRESA TESTE LTDA:$cnpj
O = ICP-Brasil
C = BR
[ v3 ]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, nonRepudiation, keyEncipherment
extendedKeyUsage = clientAuth, emailProtection
subjectAltName = otherName:2.16.76.1.3.3;OCTETSTRING:$cnpj
CFG);
        $opts = ['config' => $cfgFile, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($opts);
        $csr = openssl_csr_new(['CN' => "EMPRESA TESTE LTDA:$cnpj", 'O' => 'ICP-Brasil', 'C' => 'BR'], $key, $opts);
        $x509 = openssl_csr_sign($csr, null, $key, $days, $opts, random_int(1, PHP_INT_MAX));
        openssl_pkcs12_export($x509, $out, $key, $password);
        unlink($cfgFile);
        return $out;
    }
}
