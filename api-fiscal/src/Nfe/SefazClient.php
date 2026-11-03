<?php

declare(strict_types=1);

namespace App\Nfe;

use App\Http\ApiException;
use App\Support\Env;
use App\Support\Logger;
use App\Support\Paths;
use NFePHP\Common\Soap\SoapCurl;
use NFePHP\NFe\Tools;

/**
 * Fábrica do NFePHP\NFe\Tools a partir da configuração + certificado cifrado.
 * Transporte: SoapCurl (cURL) — não depende da extensão soap.
 * $modelo 55 (NF-e) ou 65 (NFC-e) seleciona os web services; para 65 o CSC é decifrado
 * só aqui, em memória, para a sped-nfe gerar o QR-Code.
 */
final class SefazClient
{
    /** Permite injetar um Tools falso nos testes (recebe o modelo). */
    public static ?\Closure $factory = null;

    public static function tools(int $modelo = 55): Tools
    {
        return \App\Tenancy\FiscalScope::forModelo($modelo,fn()=>self::createTools($modelo));
    }
    private static function createTools(int $modelo): Tools
    {
        if (!in_array($modelo, [55, 65], true)) {
            throw new \InvalidArgumentException('modelo inválido');
        }
        if (self::$factory !== null) {
            $tools = (self::$factory)($modelo);
            $tools->model($modelo);
            return $tools;
        }
        $cfg = ConfigService::require();
        $cert = CertificateStore::load();
        $csc = $modelo === 65 ? ConfigService::csc() : null;
        $config = [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => (int) $cfg['ambiente'],
            'razaosocial' => $cfg['razao_social'],
            'cnpj' => $cfg['cnpj'],
            'siglaUF' => $cfg['uf'],
            'schemes' => \App\Fiscal\ReformaTributaria::MAKE_SCHEMA,
            'versao' => '4.00',
            'tokenIBPT' => '',
            'CSC' => $csc['csc'] ?? '',
            'CSCid' => $csc['csc_id'] ?? '',
            'aProxyConf' => ['proxyIp' => '', 'proxyPort' => '', 'proxyUser' => '', 'proxyPass' => ''],
        ];
        $tools = new SefazTools(json_encode($config, JSON_THROW_ON_ERROR), $cert);
        $tools->model($modelo);

        $soap = new SoapCurl($cert);
        $tmp = Paths::storage('tmp/sped/' . \App\Tenancy\Tenant::id() . '/');
        if (!is_dir($tmp)) {
            mkdir($tmp, 0700, true);
        }
        $soap->setTemporaryFolder($tmp);
        $soap->setEncriptPrivateKey(true); // chave privada temporária gravada cifrada
        $soap->timeout(30);
        $tools->loadSoapClass($soap);
        return $tools;
    }

}
