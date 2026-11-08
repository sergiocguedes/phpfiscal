<?php

declare(strict_types=1);

namespace App\Tributos;

/**
 * Alíquota interestadual do ICMS (Resolução do Senado 22/1989 e 13/2012).
 *  - 4% para mercadoria importada ou com conteúdo de importação > 40% (origem 1, 2, 3 e 8);
 *  - 7% das UFs do Sul e Sudeste (exceto ES) para Norte, Nordeste, Centro-Oeste e ES;
 *  - 12% nos demais casos.
 */
final class AliquotaInterestadual
{
    private const SUL_SUDESTE = ['SP', 'RJ', 'MG', 'PR', 'SC', 'RS'];

    public static function para(string $ufOrigem, string $ufDestino, string $origemMercadoria): string
    {
        if (in_array($origemMercadoria, ['1', '2', '3', '8'], true)) {
            return '4';
        }
        if (in_array($ufOrigem, self::SUL_SUDESTE, true) && !in_array($ufDestino, self::SUL_SUDESTE, true)) {
            return '7';
        }
        return '12';
    }
}
