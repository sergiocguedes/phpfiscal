<?php
declare(strict_types=1);

namespace App\Nfe;

use NFePHP\NFe\Tools;

/** Usa o mesmo XSD local para a nota assinada e o envelope, sem enfraquecer a validação. */
class SefazTools extends Tools
{
    protected function isValid(string $version, string $body, string $method): bool
    {
        if ($version === '4.00' && in_array($method, ['nfe', 'enviNFe'], true)) {
            $errors = XsdValidator::validate($body, $method . '_v4.00.xsd');
            if ($errors !== []) {
                throw new \RuntimeException(implode("\n", $errors));
            }
            return true;
        }
        return parent::isValid($version, $body, $method);
    }
}
