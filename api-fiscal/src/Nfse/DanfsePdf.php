<?php
declare(strict_types=1);
namespace App\Nfse;

/** Mantém fontes licenciadas da instalação fora do vendor e dos arquivos públicos. */
final class DanfsePdf extends \NFePHP\DA\Legacy\Pdf
{
    protected function getFontPath()
    {
        return APP_ROOT . '/secrets/danfse-fonts/';
    }
}
