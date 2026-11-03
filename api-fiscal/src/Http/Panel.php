<?php
declare(strict_types=1);
namespace App\Http;

/** Painel administrativo de configuração. Não possui páginas de emissão de documentos. */
final class Panel
{
    public static function serve(string $path): Response
    {
        $assets = ['painel.js' => 'application/javascript; charset=utf-8', 'painel.css' => 'text/css; charset=utf-8'];
        $name = ltrim($path, '/');
        if (isset($assets[$name])) {
            return new Response(200, file_get_contents(APP_ROOT . '/resources/painel/' . $name), ['Content-Type' => $assets[$name]]);
        }
        if (!in_array($path, ['/', '/login', '/empresas', '/usuarios', '/administracao', '/empresa', '/certificado', '/chaves', '/nfse', '/perfil', '/mfa', '/esqueci-senha', '/redefinir-senha'], true)) {
            throw new ApiException(404, 'nao_encontrado', 'Página não encontrada nesta edição da API');
        }
        return new Response(200, file_get_contents(APP_ROOT . '/resources/painel/index.html'), ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
