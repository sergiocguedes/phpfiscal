<?php
declare(strict_types=1);
namespace App\Http;

/** Painel administrativo de configuração. Não possui páginas de emissão de documentos. */
final class Panel
{
    public static function serve(string $path): Response
    {
        // Portal do Contador (SPA própria em resources/portal; entrada pelo link /portal/acesso#t=...)
        if ($path === '/portal' || str_starts_with($path, '/portal/')) {
            $portal = ['portal.js' => 'application/javascript; charset=utf-8', 'portal.css' => 'text/css; charset=utf-8'];
            $name = substr($path, strlen('/portal/'));
            if (isset($portal[$name])) {
                return new Response(200, file_get_contents(APP_ROOT . '/resources/portal/' . $name), ['Content-Type' => $portal[$name]]);
            }
            if (!in_array($path, ['/portal', '/portal/', '/portal/acesso'], true)) {
                throw new ApiException(404, 'nao_encontrado', 'Página não encontrada');
            }
            return new Response(200, file_get_contents(APP_ROOT . '/resources/portal/index.html'), ['Content-Type' => 'text/html; charset=utf-8']);
        }
        // Configuração do sistema (administração central): área no padrão do Portal do Contador
        if ($path === '/configuracao' || str_starts_with($path, '/configuracao/')) {
            $files = ['configuracao.js' => [APP_ROOT . '/resources/configuracao/configuracao.js', 'application/javascript; charset=utf-8'],
                'portal.css' => [APP_ROOT . '/resources/portal/portal.css', 'text/css; charset=utf-8']];
            $name = substr($path, strlen('/configuracao/'));
            if (isset($files[$name])) {
                return new Response(200, file_get_contents($files[$name][0]), ['Content-Type' => $files[$name][1]]);
            }
            if (!in_array($path, ['/configuracao', '/configuracao/'], true)) {
                throw new ApiException(404, 'nao_encontrado', 'Página não encontrada');
            }
            return new Response(200, file_get_contents(APP_ROOT . '/resources/configuracao/index.html'), ['Content-Type' => 'text/html; charset=utf-8']);
        }
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
