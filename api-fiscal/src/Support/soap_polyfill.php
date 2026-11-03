<?php

/**
 * Polyfill mínimo para a extensão ext-soap, que não está instalada neste host.
 *
 * A sped-nfe declara ext-soap como requisito, mas o transporte usado por padrão
 * (NFePHP\Common\Soap\SoapCurl) monta o envelope SOAP manualmente e envia via
 * cURL. Da extensão, o código só usa:
 *   - as constantes SOAP_1_1 / SOAP_1_2 (parâmetros padrão);
 *   - a classe \SoapHeader, apenas em Tools::sefazCadastro com layout 2.00,
 *     lida por SoapBase::mountSoapHeaders através das propriedades
 *     namespace, name e data.
 *
 * Se a extensão real estiver presente, nada aqui é definido.
 */

if (!defined('SOAP_1_1')) {
    define('SOAP_1_1', 1);
}
if (!defined('SOAP_1_2')) {
    define('SOAP_1_2', 2);
}

if (!class_exists('SoapHeader', false)) {
    // phpcs:ignore PSR1.Classes.ClassDeclaration
    class SoapHeader
    {
        public string $namespace;
        public string $name;
        /** @var mixed */
        public $data;
        public bool $mustUnderstand;
        /** @var mixed */
        public $actor;

        public function __construct(string $namespace, string $name, $data = null, bool $mustUnderstand = false, $actor = null)
        {
            $this->namespace = $namespace;
            $this->name = $name;
            $this->data = $data;
            $this->mustUnderstand = $mustUnderstand;
            $this->actor = $actor;
        }
    }
}
