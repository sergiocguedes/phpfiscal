<?php

declare(strict_types=1);

namespace Tests;

use App\Storage\JsonStore;

/**
 * Portal do Contador: atalho dos sistemas (link de uso único), sessão do portal e limites de permissão.
 */
final class PortalAcessoTest extends TestCase
{
    /** @return array{url:string, token:string} */
    private function link(array $extra = []): array
    {
        $r = $this->authed('POST', '/api/v1/portal/acessos', (string) json_encode($extra + [
            'perfil' => 'fiscal', 'tipo_usuario' => 'contador', 'sistema' => 'ClickPDV 5.20',
            'usuario' => ['id' => '42', 'nome' => 'Maria Contadora', 'email' => 'maria@escritorio.com.br'],
            'abrir' => ['aba' => 'regras-icms'],
        ]));
        $this->assertSame(201, $r->status, $r->body);
        $d = $this->json($r);
        $this->assertMatchesRegularExpression('#/portal/acesso\#t=[A-Za-z0-9_-]{43}$#', $d['url']);
        $this->assertMatchesRegularExpression('/^acs_[0-9a-f]{16}$/', $d['id']);
        return ['url' => $d['url'], 'token' => substr($d['url'], strpos($d['url'], '#t=') + 3)];
    }

    /** @return array{cookie:string, csrf:string, ctx:array<mixed>} */
    private function entrar(string $token): array
    {
        $r = $this->call('POST', '/api/v1/portal/sessao', (string) json_encode(['token' => $token]));
        $this->assertSame(200, $r->status, $r->body);
        $this->assertStringContainsString('HttpOnly; SameSite=Strict', $r->headers['Set-Cookie']);
        $ctx = $this->json($r);
        return ['cookie' => explode(';', $r->headers['Set-Cookie'])[0], 'csrf' => $ctx['csrf_token'], 'ctx' => $ctx];
    }

    private function portal(array $s, string $method, string $uri, string $body = '', bool $csrf = true): \App\Http\Response
    {
        $h = ['Cookie' => $s['cookie'], 'X-Portal' => '1'];
        if ($csrf && $method !== 'GET') {
            $h['X-CSRF-Token'] = $s['csrf'];
        }
        return $this->call($method, $uri, $body, $h);
    }

    public function testLinkDeUsoUnicoAbreSessaoComContexto(): void
    {
        $this->configure();
        $l = $this->link();
        $s = $this->entrar($l['token']);
        $this->assertSame('fiscal', $s['ctx']['perfil']);
        $this->assertSame('contador', $s['ctx']['tipo_usuario']);
        $this->assertSame('regras-icms', $s['ctx']['aba']);
        $this->assertSame('Maria Contadora', $s['ctx']['usuario']['nome']);
        $this->assertTrue($s['ctx']['pode_alterar']);

        // o mesmo link não abre de novo
        $r = $this->call('POST', '/api/v1/portal/sessao', (string) json_encode(['token' => $l['token']]));
        $this->assertSame(401, $r->status);
        $this->assertSame('link_usado', $this->json($r)['erro']['codigo']);

        $ctx = $this->json($this->portal($s, 'GET', '/api/v1/portal/contexto'));
        $this->assertSame($s['ctx']['empresa']['id'], $ctx['empresa']['id']);
        $audit = $this->auditTrail();
        $this->assertStringContainsString('portal.acesso', $audit);
        $this->assertStringContainsString('portal.sessao', $audit);
    }

    public function testLinkExpiradoOuInvalido(): void
    {
        $l = $this->link();
        $arq = 'portal/acessos/' . hash('sha256', $l['token']) . '.json';
        JsonStore::update($arq, fn($a) => [['expira_em' => time() - 1] + $a, null]);
        $r = $this->call('POST', '/api/v1/portal/sessao', (string) json_encode(['token' => $l['token']]));
        $this->assertSame('link_expirado', $this->json($r)['erro']['codigo']);
        $r = $this->call('POST', '/api/v1/portal/sessao', (string) json_encode(['token' => str_repeat('a', 43)]));
        $this->assertSame('link_invalido', $this->json($r)['erro']['codigo']);
        $this->assertSame(401, $this->call('GET', '/api/v1/portal/contexto', '', ['X-Portal' => '1'])->status);

        $r = $this->authed('POST', '/api/v1/portal/acessos', (string) json_encode(['perfil' => 'admin', 'usuario' => [], 'abrir' => ['aba' => 'emitir']]));
        $this->assertSame(422, $r->status);
        $campos = array_column($this->json($r)['erro']['detalhes'], 'campo');
        foreach (['perfil', 'usuario.id', 'usuario.nome', 'abrir.aba'] as $c) {
            $this->assertContains($c, $campos);
        }
    }

    public function testSessaoDoPortalSoAlcancaTributacaoECalculo(): void
    {
        $this->configure();
        $s = $this->entrar($this->link()['token']);

        // tributação e cálculo: permitidos (com CSRF nas alterações)
        $this->assertSame(200, $this->portal($s, 'GET', '/api/v1/tributacao')->status);
        $r = $this->portal($s, 'PUT', '/api/v1/tributacao/perfis/1', (string) json_encode(['nome' => 'PRODUTO TRIBUTADO', 'tipo' => 'icms']));
        $this->assertSame(200, $r->status, $r->body);
        $r = $this->portal($s, 'PUT', '/api/v1/tributacao/perfis/2', (string) json_encode(['nome' => 'X', 'tipo' => 'icms']), false);
        $this->assertSame('csrf_invalido', $this->json($r)['erro']['codigo']);
        $r = $this->portal($s, 'POST', '/api/v1/tributos/calcular', (string) json_encode(['itens' => [['quantidade' => 1, 'valor_unitario' => 10,
            'regra' => ['cfop' => '5102', 'icms' => ['orig' => 0, 'CSOSN' => '102'], 'pis' => ['CST' => '07'], 'cofins' => ['CST' => '07']]]]]));
        $this->assertSame(200, $r->status, $r->body);

        // emitir, cancelar, inutilizar, configuração, certificado e novos links: bloqueados
        foreach ([['POST', '/api/v1/nfe?dry_run=1'], ['POST', '/api/v1/pdv/nfce'], ['POST', '/api/v1/inutilizar'],
            ['POST', '/api/v1/nfe/35261111222333000181550010000000011000000010/cancelar'], ['GET', '/api/v1/config'], ['PUT', '/api/v1/config'],
            ['GET', '/api/v1/certificado'], ['GET', '/api/v1/nfe'], ['GET', '/api/v1/chaves'], ['POST', '/api/v1/portal/acessos'], ['POST', '/api/v1/nfse']] as [$m, $u]) {
            $r = $this->portal($s, $m, $u, $m === 'GET' ? '' : '{}');
            $this->assertSame(403, $r->status, "$m $u: $r->body");
            $this->assertSame('portal_sem_permissao', $this->json($r)['erro']['codigo'], "$m $u");
        }
        $this->assertStringContainsString('portal.rota', $this->auditTrail());

        // sair encerra a sessão
        $this->assertSame(200, $this->portal($s, 'POST', '/api/v1/portal/sair', '{}')->status);
        $r = $this->portal($s, 'GET', '/api/v1/portal/contexto');
        $this->assertSame(401, $r->status);
        $this->assertSame('portal_sessao_expirada', $this->json($r)['erro']['codigo']);
    }

    public function testPerfilConsultaNaoAlteraRegras(): void
    {
        $this->configure();
        $s = $this->entrar($this->link(['perfil' => 'consulta', 'tipo_usuario' => 'cliente'])['token']);
        $this->assertFalse($s['ctx']['pode_alterar']);
        $this->assertSame(200, $this->portal($s, 'GET', '/api/v1/tributacao/regras-icms')->status);
        $r = $this->portal($s, 'PUT', '/api/v1/tributacao/perfis/1', (string) json_encode(['nome' => 'X', 'tipo' => 'icms']));
        $this->assertSame(403, $r->status, $r->body);
    }

    public function testPaginasDoPortal(): void
    {
        foreach (['/portal', '/portal/acesso'] as $u) {
            $r = $this->call('GET', $u);
            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('/portal/portal.js', $r->body);
            $this->assertStringContainsString("script-src 'self'", $r->headers['Content-Security-Policy']);
        }
        $this->assertSame(200, $this->call('GET', '/portal/portal.js')->status);
        $this->assertSame(200, $this->call('GET', '/portal/portal.css')->status);
        $this->assertSame(404, $this->call('GET', '/portal/outra')->status);
        // chave de 44 dígitos com modelo inválido: erro de validação, não 500
        $this->assertNotSame(500, $this->authed('GET', '/api/v1/nfe/' . str_repeat('1', 44))->status);
    }
}
