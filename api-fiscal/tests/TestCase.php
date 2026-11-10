<?php

declare(strict_types=1);

namespace Tests;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Support\Env;
use App\Support\Paths;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected App $app;

    protected function setUp(): void
    {
        // storage limpo a cada teste
        exec('rm -rf ' . escapeshellarg(Paths::storage()));
        Paths::ensure();
        if(\App\Storage\PostgresStore::enabled()){
            $ns=\App\Storage\PostgresStore::ns();
            if(!str_starts_with($ns,'tests_')){throw new \LogicException('Banco de testes sem namespace isolado');}
            foreach(['fiscal_documents','fiscal_blobs','fiscal_api_settings'] as $table){$q=\App\Storage\PostgresStore::db()->prepare("DELETE FROM $table WHERE namespace=?");$q->execute([$ns]);}
        }
        \App\Support\Env::set('CENTRAL_AUTH','false');
        \App\Support\Env::set('XML_STORAGE','local');
        \App\Storage\S3Client::$transport=null;
        \App\Nfe\SefazClient::$factory = null;
        \App\Support\Env::set('ALLOW_PRODUCAO', 'false');
        \App\Support\Env::set('NFSE_LEIAUTE_PRODUCAO', '');
        \App\Support\Env::set('NFSE_LEIAUTE_HOMOLOGACAO', '');
        \App\Nfse\SefinClient::$transport = static function (string $m, string $url): array {
            throw new \LogicException('Chamada de rede à Sefin/ADN proibida nos testes: ' . $m . ' ' . $url);
        };
        \App\Support\Mailer::$transport = null;
        $this->app = new App();
    }

    // ------------------------------------------------------------ helpers de sessão (interface web)

    protected const STRONG = 'Correta#Cavalo9Bateria';

    /**
     * Cadastra empresa + proprietário e devolve a "sessão do navegador".
     * @return array{cookie:string, csrf:string, tenant:string, user:string, email:string, body:array<mixed>}
     */
    protected function signup(string $email, string $empresa = 'Empresa A LTDA', string $cnpj = '11222333000181', string $ip = '127.0.0.1'): array
    {
        $tenant=\App\Tenancy\TenantStore::create($empresa,$cnpj);
        $user=\App\Auth\UserStore::create($tenant['id'],'Pessoa Teste',$email,\App\Auth\Password::hash(self::STRONG),\App\Auth\UserStore::ROLE_OWNER);
        [$token,$s]=\App\Auth\SessionManager::create($user,$ip);
        $r=Response::json(\App\Http\Controllers\AuthController::sessionBody($user,$s),201,['Set-Cookie'=>\App\Auth\SessionManager::cookie($token)]);
        $this->assertSame(201, $r->status, $r->body);
        return $this->sessionFrom($r, $email);
    }

    /** @return array{cookie:string, csrf:string, tenant:string, user:string, email:string, body:array<mixed>} */
    protected function sessionFrom(Response $r, string $email): array
    {
        $this->assertArrayHasKey('Set-Cookie', $r->headers);
        $cookie = explode(';', $r->headers['Set-Cookie'])[0];
        $b = $this->json($r);
        return ['cookie' => $cookie, 'csrf' => $b['csrf_token'], 'tenant' => $b['tenant']['id'], 'user' => $b['usuario']['id'], 'email' => $email, 'body' => $b];
    }

    /**
     * Requisição como o navegador logado: cookie + X-CSRF-Token nas mutações.
     * @param array{cookie:string, csrf:string} $s
     * @param array<string,string> $headers
     */
    protected function sess(array $s, string $method, string $uri, string $body = '', array $headers = []): Response
    {
        $h = ['Cookie' => $s['cookie']] + $headers;
        if ($method !== 'GET' && !isset($headers['X-CSRF-Token'])) {
            $h['X-CSRF-Token'] = $s['csrf'];
        }
        return $this->call($method, $uri, $body, $h);
    }

    /** Configuração do emitente via sessão. @param array{cookie:string, csrf:string} $s */
    protected function configureSession(array $s, string $cnpj = '11222333000181', string $razao = 'EMPRESA A LTDA', int $crt = 1): void
    {
        $r = $this->sess($s, 'PUT', '/api/v1/config', (string) json_encode([
            'cnpj' => $cnpj, 'ie' => '111111111111', 'razao_social' => $razao, 'uf' => 'SP', 'ambiente' => 2, 'crt' => $crt,
            'endereco' => ['logradouro' => 'Rua das Flores', 'numero' => '100', 'bairro' => 'Centro', 'codigo_municipio' => '3550308', 'municipio' => 'Sao Paulo', 'cep' => '01001000'],
            'serie' => 1, 'proximo_numero' => 1,
        ]));
        $this->assertSame(200, $r->status, $r->body);
    }

    protected function apiKey(): string
    {
        return (string) Env::get('API_KEY');
    }

    /** @param array<string,string> $headers */
    protected function call(string $method, string $uri, string $body = '', array $headers = [], string $ip = '127.0.0.1'): Response
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        if ($body !== '' && !isset($h['content-type'])) {
            $h['content-type'] = 'application/json';
        }
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        return $this->app->handle(new Request($method, '/' . trim($path, '/'), $query, $h, $body, $ip));
    }

    /** @param array<string,string> $headers */
    protected function authed(string $method, string $uri, string $body = '', array $headers = []): Response
    {
        return $this->call($method, $uri, $body, ['Authorization' => 'Bearer ' . $this->apiKey()] + $headers);
    }

    /** @return array<mixed> */
    protected function json(Response $r): array
    {
        return json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function configure(int $crt = 1): void
    {
        $r = $this->authed('PUT', '/api/v1/config', (string) json_encode([
            'cnpj' => '11222333000181',
            'ie' => '111111111111',
            'razao_social' => 'EMPRESA TESTE LTDA',
            'uf' => 'SP',
            'ambiente' => 2,
            'crt' => $crt,
            'endereco' => [
                'logradouro' => 'Rua das Flores', 'numero' => '100', 'bairro' => 'Centro',
                'codigo_municipio' => '3550308', 'municipio' => 'Sao Paulo', 'cep' => '01001000',
            ],
            'serie' => 1,
            'proximo_numero' => 1,
        ]));
        $this->assertSame(200, $r->status, $r->body);
    }

    /**
     * Grava uma nota no tenant a partir do XML de um DRY-RUN (nada é assinado nem enviado à SEFAZ).
     * $req: fn(string $metodo, string $uri, string $corpo): Response (chave de API ou sessão do tenant).
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $extra campos adicionais do registro (protocolo, cancelamento...)
     */
    protected function seedNota(string $tenantId, callable $req, array $payload, int $numero, string $status = 'autorizada', ?string $criadoEm = null, array $extra = []): string
    {
        $payload['numero'] = $numero;
        $r = $req('POST', '/api/v1/nfe?dry_run=1', (string) json_encode($payload));
        $this->assertSame(200, $r->status, $r->body);
        $d = $this->json($r);
        \App\Tenancy\Tenant::run($tenantId, function () use ($d, $payload, $status, $criadoEm, $extra) {
            \App\Nfe\NfeRepository::save($extra + [
                'chave' => $d['chave'], 'numero' => $d['numero'], 'serie' => $d['serie'], 'modelo' => $d['modelo'],
                'ambiente' => 2, 'valor_total' => $d['valor_total'],
                'destinatario' => $payload['destinatario']['cnpj'] ?? $payload['destinatario']['cpf'] ?? null,
                'origem' => 'api', 'status' => $status, 'protocolo' => $status === 'autorizada' || $status === 'cancelada' ? '135260000000099' : null,
                'criado_em' => $criadoEm ?? date('c'), 'historico' => [],
            ]);
            \App\Nfe\NfeRepository::saveXml($d['chave'], $d['xml']);
        });
        return $d['chave'];
    }

    /**
     * Convida um membro (papel member) para a empresa do owner e devolve a sessão dele.
     * @param array{cookie:string, csrf:string, tenant:string} $owner
     * @return array{cookie:string, csrf:string, tenant:string, user:string, email:string, body:array<mixed>}
     */
    protected function memberOf(array $owner, string $email): array
    {
        \App\Auth\UserStore::create($owner['tenant'], 'Membro Teste', $email, \App\Auth\Password::hash('Outra#Chave2026x'), \App\Auth\UserStore::ROLE_MEMBER);
        $login = $this->call('POST', '/api/v1/auth/login', (string) json_encode(['email' => $email, 'senha' => 'Outra#Chave2026x']));
        $this->assertSame(200, $login->status, $login->body);
        \App\Support\Mailer::$transport = null;
        return $this->sessionFrom($login, $email);
    }

    protected function auditTrail(): string
    {
        return (string) @file_get_contents(Paths::storage('logs/audit.log'));
    }

    protected function samplePayload(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/examples/nfe-exemplo.json');
    }
}
