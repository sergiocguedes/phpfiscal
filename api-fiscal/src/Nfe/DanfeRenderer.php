<?php
declare(strict_types=1);
namespace App\Nfe;

use App\Http\ApiException;
use App\Support\Logger;

/** PDF auxiliar do XML armazenado na empresa autenticada. Não transmite nem reemite. */
final class DanfeRenderer
{
    public static function pdf(string $chave): string
    {
        $rec = NfeRepository::get($chave);
        $xml = NfeRepository::xml($chave) ?? throw new ApiException(404, 'xml_nao_encontrado', 'XML não encontrado');
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            throw new ApiException(422, 'xml_invalido', 'XML armazenado inválido');
        }
        $nfce = (int) ($rec['modelo'] ?? Nfce::modeloDaChave($chave)) === 65;
        $offline = $nfce && $dom->getElementsByTagName('tpEmis')->item(0)?->nodeValue === '9'
            && $dom->getElementsByTagName('Signature')->length > 0
            && in_array($rec['status_pdv'] ?? $rec['status'], ['contingencia_pendente', 'situacao_incerta'], true);
        if (!in_array($rec['status'], ['autorizada', 'cancelada'], true) && !$offline) {
            throw new ApiException(409, 'pdf_nao_disponivel', 'PDF disponível para nota autorizada, cancelada ou NFC-e assinada em contingência');
        }
        try {
            if ($nfce) {
                $renderer = new \NFePHP\DA\NFe\Danfce($xml);
                $renderer->setPaperWidth(80);
                $renderer->setMargins(2);
                if ($rec['status'] === 'cancelada') { $renderer->setAsCanceled(); }
            } else {
                $renderer = new \NFePHP\DA\NFe\Danfe($xml);
            }
            $renderer->creditsIntegratorFooter('API Fiscal');
            return $renderer->render();
        } catch (\Throwable $e) {
            Logger::exception($e, 'pdf.fiscal');
            throw new ApiException(422, 'danfe_falhou', 'Não foi possível gerar o PDF do documento');
        }
    }
}
