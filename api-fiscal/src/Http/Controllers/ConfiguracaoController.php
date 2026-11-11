<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Http\{Request,Response};
use App\Http\ApiException;
use App\Storage\S3Client;
use App\Support\{Logger,RequestContext,Settings};

/** Configuração do sistema (cifrada no banco). Somente administradores centrais, pela sessão do painel. */
final class ConfiguracaoController
{
    public static function obter(): Response
    {
        return Response::json(Settings::publicView());
    }

    public static function salvar(Request $r): Response
    {
        $p = RequestContext::$principal;
        $autor = $p !== null ? $p->actor() : 'desconhecido';
        return Response::json(Settings::saveGroup($r->params['grupo'], $r->json(), (string) $autor));
    }

    /** Testa o Backblaze B2 com os valores da janela (ainda não salvos): grava, lê, confere e apaga um objeto sem dados fiscais. */
    public static function testarArmazenamento(Request $r): Response
    {
        $cfg = Settings::previewArmazenamento($r->json());
        if (!S3Client::configurado($cfg)) {
            throw new ApiException(422, 'b2_incompleto', 'Preencha endpoint, região, bucket, keyID e applicationKey para testar.');
        }
        try {
            $msg = S3Client::testar($cfg);
        } catch (\Throwable $e) {
            Logger::audit('configuracao.teste_b2', 'falha');
            throw new ApiException(502, 'b2_falhou', 'Teste do Backblaze falhou: ' . mb_substr($e->getMessage(), 0, 200));
        }
        Logger::audit('configuracao.teste_b2', 'sucesso');
        return Response::json(['ok' => true, 'mensagem' => $msg]);
    }
}
