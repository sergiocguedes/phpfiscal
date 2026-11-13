<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\ApiKeyStore;
use App\Auth\AuthService;
use App\Auth\UserStore;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Logger;
use App\Support\RequestContext;
use App\Support\Validate;
use App\Tenancy\TenantStore;

/** Rotas /api/v1/tenant (usada pela integração MadBuilder) e /api/v1/chaves (painel). Fora do Swagger público (x-interno). */
final class TenantController
{
    private static function tid(): string
    {
        return \App\Tenancy\Tenant::id();
    }

    public static function get(): Response
    {
        return Response::json(TenantStore::publicView(TenantStore::get(self::tid())));
    }

    public static function put(Request $req): Response
    {
        $in = $req->json();
        $allowed = ['nome', 'ambiente_nfe', 'ambiente_nfce', 'ambiente_nfse'];
        if ($in === [] || array_diff(array_keys($in), $allowed) !== []) {
            throw ApiException::validation([['campo' => 'empresa', 'mensagem' => 'Informe nome e/ou ambiente_nfe, ambiente_nfce, ambiente_nfse. Ativação e CNPJ são administrados pela gestão central.']]);
        }
        $t = TenantStore::settings(self::tid(), $in);
        Logger::audit('tenant.atualizado', 'sucesso');
        return Response::json(TenantStore::publicView($t));
    }



    /**
     * Altera o papel de um usuário da empresa (só proprietário, só por sessão — ver rota).
     * Regras: papel em owner|fiscal|member; ninguém altera o próprio papel (evita auto-promoção e
     * deixar a empresa sem proprietário); usuário de outro tenant => 404. A mudança vale na próxima
     * requisição do usuário (o papel é lido do registro a cada requisição). Auditoria com de/para.
     */


    public static function keys(): Response
    {
        return Response::json(['chaves' => ApiKeyStore::all(self::tid())]);
    }

    public static function createKey(Request $req): Response
    {
        $nome = ApiKeyStore::validName($req->json()['nome'] ?? null);
        if ($nome === null) {
            throw ApiException::validation([['campo' => 'nome', 'mensagem' => '2 a 60 caracteres']]);
        }
        [$token, $rec] = ApiKeyStore::create(self::tid(), $nome, RequestContext::$actor);
        Logger::audit('chave_api.criada', 'sucesso', ['chave_id' => $rec['id']]);
        return Response::json($rec + ['chave' => $token, 'aviso' => 'Copie a chave agora: ela não será exibida novamente.'], 201);
    }

    public static function revokeKey(Request $req): Response
    {
        ApiKeyStore::revoke(self::tid(), $req->params['id']);
        Logger::audit('chave_api.revogada', 'sucesso', ['chave_id' => $req->params['id']]);
        return Response::noContent();
    }
}
