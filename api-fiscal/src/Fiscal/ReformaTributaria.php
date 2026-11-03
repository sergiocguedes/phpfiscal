<?php
declare(strict_types=1);
namespace App\Fiscal;

/** Apenas a identificação do schema XML; nenhuma tabela, padrão fiscal ou cálculo. */
final class ReformaTributaria
{
    public const MAKE_SCHEMA='PL_010_V1.30';
    public static function semGrupos(array $p): array
    {
        return ['itens'=>array_fill(0,count($p['itens']),null),'is'=>array_fill(0,count($p['itens']),null),
            'usar_pl010'=>false,'avisos'=>[],'resumo'=>['modo'=>'informado','grupo_informado'=>false]];
    }
}
