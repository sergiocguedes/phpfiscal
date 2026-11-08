<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Nfe\ConfigService;
use App\Support\Logger;
use App\Tributos\CalculadoraTributos;

/**
 * /api/v1/tributos/calcular — cálculo de ICMS, IPI, PIS, COFINS, ISSQN e tributos aproximados de uma venda.
 * Só calcula: não grava, não numera e não transmite. CRT e UF vêm da configuração da empresa quando não
 * informados em "emitente". ?detalhar=1 acrescenta a memória de cálculo por item.
 */
final class TributosController
{
    public static function calcular(Request $req): Response
    {
        $r = CalculadoraTributos::calcular($req->json(), ConfigService::get(), $req->queryFlag('detalhar'));
        $manuais = array_sum(array_map(fn(array $i) => count($i['ajustes_manuais']), $r['itens']));
        Logger::audit('tributos.calculo', 'sucesso', ['calculo_id' => $r['calculo_id'], 'itens' => count($r['itens']), 'ajustes_manuais' => $manuais]);
        return Response::json($r);
    }
}
