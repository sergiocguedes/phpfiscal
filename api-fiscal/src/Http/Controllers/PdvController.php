<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Nfe\PdvService;

final class PdvController
{
    public static function emitir(Request $req): Response
    {
        $r = PdvService::emitir($req->json());
        $http = match ($r['status']) {
            'autorizada' => 201,
            'cancelada' => 200,
            'rejeitada', 'denegada' => 422,
            default => 202,
        };
        return Response::json($r, $http);
    }

    public static function consultar(Request $req): Response
    {
        return Response::json(PdvService::consultar($req->params['id']));
    }

    public static function sincronizar(Request $req): Response
    {
        return Response::json(PdvService::sincronizar($req->params['id']));
    }
}
