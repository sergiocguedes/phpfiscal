<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Portal\PortalAcesso;
use App\Support\RequestContext;

/**
 * /api/v1/portal — acesso ao Portal do Contador pelo atalho dos sistemas (ver docs/PORTAL-CONTADOR.md).
 */
final class PortalController
{
    /** POST /portal/acessos — chamado pelo sistema da empresa (chave de API) para gerar o link de uso único. */
    public static function criarAcesso(Request $req): Response
    {
        return Response::json(PortalAcesso::criar(RequestContext::$principal, $req->json()), 201);
    }

    /** POST /portal/sessao {token} — o portal troca o token do link pela sessão (cookie). */
    public static function sessao(Request $req): Response
    {
        [$cookie, $ctx] = PortalAcesso::trocar($req, $req->json()['token'] ?? null);
        return Response::json($ctx, 200, ['Set-Cookie' => $cookie]);
    }

    public static function contexto(): Response
    {
        return Response::json(PortalAcesso::contexto(RequestContext::$principal->session ?? []));
    }

    public static function sair(): Response
    {
        PortalAcesso::sair(RequestContext::$principal);
        \App\Support\Logger::audit('portal.sessao', 'encerrada');
        return Response::json(['ok' => true], 200, ['Set-Cookie' => PortalAcesso::clearCookie()]);
    }
}
