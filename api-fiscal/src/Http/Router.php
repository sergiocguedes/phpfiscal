<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<array{0:string,1:string,2:callable,3:array<string,bool>}> */
    private array $routes = [];

    /**
     * Registra rota. Parâmetros no formato {nome}.
     * $opts: true (= pública) ou array com as chaves:
     *   public  – sem autenticação;
     *   session – exige sessão de usuário (não aceita chave de API);
     *   owner   – exige papel owner (chaves de API contam como owner);
     *   fiscal  – exige papel owner ou fiscal (cancelamento, CC-e, inutilização).
     * @param bool|array<string,bool> $opts
     */
    public function add(string $method, string $pattern, callable $handler, bool|array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, is_bool($opts) ? ['public' => $opts] : $opts];
    }

    /**
     * @return array{0: callable, 1: array<string,bool>}|null  [handler, opções]
     */
    public function match(Request $req): ?array
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler, $opts]) {
            if (preg_match($regex, $req->path, $m)) {
                if ($method !== $req->method) {
                    $allowed[] = $method;
                    continue;
                }
                foreach ($m as $k => $v) {
                    if (is_string($k)) {
                        $req->params[$k] = $v;
                    }
                }
                return [$handler, $opts];
            }
        }
        if ($allowed !== []) {
            throw new ApiException(405, 'metodo_nao_permitido', 'Método não permitido', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        return null;
    }
}
