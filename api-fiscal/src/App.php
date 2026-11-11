<?php

declare(strict_types=1);

namespace App;

use App\Http\ApiException;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NfseController;
use App\Http\Controllers\TenantController;
use App\Http\Idempotency;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

use App\Nfe\CertificateStore;
use App\Nfe\ConfigService;

use App\Nfe\EmissionService;
use App\Nfe\Nfce;
use App\Nfe\NfeBuilder;
use App\Nfe\NfeRepository;
use App\Security\Auth;
use App\Security\Hmac;
use App\Security\RateLimiter;
use App\Support\Env;
use App\Support\Errors;
use App\Support\Logger;
use App\Support\RequestContext;
use App\Support\Validate;
use App\Tenancy\Migration;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStore;

final class App {
    public const VERSION = '3.2.0-api';
    private const SPA_CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'";
    private Router $router;
    public function __construct() {
        Migration::run();
        \App\Tenancy\FiscalScope::migrate();
        \App\Fiscal\ApiConfigService::migrate();
        \App\Tenancy\ModelConfigMigration::run();
        $r = new Router(); $p='/api/v1'; $owner=['owner'=>true]; $session=['session'=>true]; $sessionOwner=$session+$owner; $fiscal=['fiscal'=>true];
        $r->add('GET', "$p/health", [$this, 'health'], true);
        $r->add('GET', '/docs', [$this, 'docs'], true);
        $r->add('GET', '/docs/{file}', [$this, 'docsAsset'], true);
        $r->add('GET', '/openapi.yaml', [$this, 'openapi'], true);
        $central=['central'=>true]; $admin=['admin'=>true];
        $a=\App\Http\Controllers\AdminController::class;
        $r->add('GET', "$p/empresas", [$a,'empresas'], $central);
        $r->add('POST', "$p/empresas", [$a,'createEmpresa'], $central);
        $r->add('PUT', "$p/empresas/{id}", [$a,'updateEmpresa'], $central);
        $r->add('GET', "$p/admin/usuarios", [$a,'users'], $admin);
        $r->add('POST', "$p/admin/usuarios", [$a,'createUser'], $admin);
        $r->add('PUT', "$p/admin/usuarios/{id}", [$a,'updateUser'], $admin);
        $tr=\App\Http\Controllers\TributacaoController::class;
        $r->add('GET', "$p/admin/rtc/aliquotas", [$tr,'aliquotasIbsCbs'], $admin);
        $r->add('POST', "$p/admin/rtc/aliquotas", [$tr,'salvarAliquotaIbsCbs'], $admin);
        $r->add('PUT', "$p/admin/rtc/aliquotas/{id}", [$tr,'salvarAliquotaIbsCbs'], $admin);
        $r->add('DELETE', "$p/admin/rtc/aliquotas/{id}", [$tr,'excluirAliquotaIbsCbs'], $admin);
        $r->add('GET', "$p/admin/rtc/municipios", [$tr,'municipios'], $admin);
        $r->add('GET', "$p/admin/rtc/cclasstrib", [$tr,'cclasstrib'], $admin);
        $r->add('POST', "$p/admin/rtc/cclasstrib", [$tr,'importarCClassTrib'], $admin);
        $r->add('GET', "$p/admin/chaves", [$a,'keys'], $admin);
        $r->add('GET', "$p/admin/armazenamento", [$a,'storage'], $admin);
        $r->add('GET', "$p/admin/config", [$a,'apiConfig'], $admin);
        $r->add('PUT', "$p/admin/config", [$a,'saveApiConfig'], $admin);
        $cfg=\App\Http\Controllers\ConfiguracaoController::class;
        $r->add('GET', "$p/admin/configuracao", [$cfg,'obter'], $admin+$session);
        $r->add('PUT', "$p/admin/configuracao/{grupo}", [$cfg,'salvar'], $admin+$session);
        $r->add('POST', "$p/admin/configuracao/armazenamento/testar", [$cfg,'testarArmazenamento'], $admin+$session);
        $r->add('POST', "$p/admin/chaves", [$a,'createKey'], $admin);
        $r->add('DELETE', "$p/admin/chaves/{id}", [$a,'revokeKey'], $admin);
        $r->add('POST', "$p/auth/login", [AuthController::class, 'login'], ['public' => true, 'auth_form' => true]);
        $r->add('POST', "$p/auth/esqueci-senha", [AuthController::class, 'forgot'], ['public' => true, 'auth_form' => true]);
        $r->add('POST', "$p/auth/redefinir-senha", [AuthController::class, 'reset'], ['public' => true, 'auth_form' => true]);
        $r->add('POST', "$p/auth/mfa/verificar", [AuthController::class, 'mfaVerify'], ['public' => true, 'auth_form' => true]);
        $r->add('GET', "$p/auth/sessao", [AuthController::class, 'me'], ['mfa_livre' => true] + []);
        $r->add('POST', "$p/auth/logout", [AuthController::class, 'logout'], $session + ['mfa_livre' => true] + []);
        $r->add('GET', "$p/auth/mfa", [AuthController::class, 'mfaStatus'], $session + ['mfa_livre' => true] + []);
        $r->add('POST', "$p/auth/mfa/iniciar", [AuthController::class, 'mfaStart'], $session + ['mfa_livre' => true] + []);
        $r->add('POST', "$p/auth/mfa/confirmar", [AuthController::class, 'mfaConfirm'], $session + ['mfa_livre' => true] + []);
        $r->add('POST', "$p/auth/mfa/desativar", [AuthController::class, 'mfaDisable'], $session + []);
        $r->add('POST', "$p/auth/mfa/codigos-recuperacao", [AuthController::class, 'mfaRecoveryCodes'], $session + []);
        $r->add('PUT', "$p/auth/perfil", [AuthController::class, 'profile'], $session + []);
        $r->add('POST', "$p/auth/alterar-senha", [AuthController::class, 'changePassword'], $session + []);
        $r->add('GET', "$p/tenant", [TenantController::class, 'get'], []);
        $r->add('PUT', "$p/tenant", [TenantController::class, 'put'], $owner);
        $r->add('GET', "$p/chaves", [TenantController::class, 'keys'], $owner);
        $r->add('POST', "$p/chaves", [TenantController::class, 'createKey'], $owner);
        $r->add('DELETE', "$p/chaves/{id}", [TenantController::class, 'revokeKey'], $owner);
        $r->add('GET', "$p/config", [$this, 'getConfig'], []);
        $r->add('PUT', "$p/config", [$this, 'putConfig'], $owner);
        $r->add('GET', "$p/certificado", [$this, 'getCert'], []); // só metadados (titular/validade)
        $r->add('POST', "$p/certificado", [$this, 'postCert'], $owner);
        $r->add('DELETE', "$p/certificado", [$this, 'deleteCert'], $owner);
        $r->add('GET', "$p/sefaz/status", fn(Request $q) => Response::json(EmissionService::status(self::modeloParam($q->query['modelo'] ?? null))));
        $r->add('GET', "$p/sefaz/consulta/{chave}", fn(Request $q) => Response::json(EmissionService::consultar($q->params['chave'])));
        $r->add('POST', "$p/pdv/nfce", [\App\Http\Controllers\PdvController::class, 'emitir']);
        $r->add('GET', "$p/pdv/nfce/{id}", [\App\Http\Controllers\PdvController::class, 'consultar']);
        $r->add('POST', "$p/pdv/nfce/{id}/sincronizar", [\App\Http\Controllers\PdvController::class, 'sincronizar']);
        $r->add('GET', "$p/nfe", [$this, 'listNfe'], []);
        $r->add('POST', "$p/nfe", [$this, 'postNfe']);
        $r->add('GET', "$p/nfe/{chave}", fn(Request $q) => Response::json(EmissionService::publicRec(NfeRepository::get($q->params['chave']))), []);
        $r->add('GET', "$p/nfe/{chave}/xml", [$this, 'getXml'], []);
        $r->add('GET', "$p/nfe/{chave}/danfe", [$this, 'getDanfe']);
        $r->add('POST', "$p/nfe/{chave}/cancelar", [$this, 'cancelar'], $fiscal);
        $r->add('POST', "$p/nfe/{chave}/cce", [$this, 'cce'], $fiscal);
        $r->add('POST', "$p/inutilizar", [$this, 'inutilizar'], $fiscal);
        $portal = ['portal' => true];
        $r->add('POST', "$p/tributos/calcular", [\App\Http\Controllers\TributosController::class, 'calcular'], $portal);
        $pc = \App\Http\Controllers\PortalController::class;
        $r->add('POST', "$p/portal/acessos", [$pc, 'criarAcesso'], $owner);
        $r->add('POST', "$p/portal/sessao", [$pc, 'sessao'], ['public' => true]);
        $r->add('GET', "$p/portal/contexto", [$pc, 'contexto'], $portal);
        $r->add('POST', "$p/portal/sair", [$pc, 'sair'], $portal);
        $t = \App\Http\Controllers\TributacaoController::class;
        $r->add('GET', "$p/tributacao", [$t, 'cadastro'], $portal);
        $r->add('POST', "$p/tributacao/perfis", [$t, 'criarPerfil'], $owner + $portal);
        $r->add('PUT', "$p/tributacao/perfis/{codigo}", [$t, 'salvarPerfil'], $owner + $portal);
        $r->add('DELETE', "$p/tributacao/perfis/{codigo}", [$t, 'excluirPerfil'], $owner + $portal);
        $r->add('POST', "$p/tributacao/operacoes", [$t, 'criarOperacao'], $owner + $portal);
        $r->add('PUT', "$p/tributacao/operacoes/{codigo}", [$t, 'salvarOperacao'], $owner + $portal);
        $r->add('DELETE', "$p/tributacao/operacoes/{codigo}", [$t, 'excluirOperacao'], $owner + $portal);
        $r->add('GET', "$p/tributacao/regras-icms", [$t, 'listarIcms'], $portal);
        $r->add('POST', "$p/tributacao/regras-icms/copiar", [$t, 'copiarIcms'], $owner + $portal);
        $r->add('GET', "$p/tributacao/regras-icms/{perfil}/{operacao}/{uf}/{tipo_pessoa}", [$t, 'detalheIcms'], $portal);
        $r->add('PUT', "$p/tributacao/regras-icms/{perfil}/{operacao}/{uf}/{tipo_pessoa}", [$t, 'salvarIcms'], $owner + $portal);
        $r->add('DELETE', "$p/tributacao/regras-icms/{perfil}/{operacao}/{uf}/{tipo_pessoa}", [$t, 'excluirIcms'], $owner + $portal);
        $r->add('GET', "$p/tributacao/regras-piscofins", [$t, 'listarPisCofins'], $portal);
        $r->add('POST', "$p/tributacao/regras-piscofins/copiar", [$t, 'copiarPisCofins'], $owner + $portal);
        $r->add('GET', "$p/tributacao/regras-piscofins/{perfil}/{operacao}", [$t, 'detalhePisCofins'], $portal);
        $r->add('PUT', "$p/tributacao/regras-piscofins/{perfil}/{operacao}", [$t, 'salvarPisCofins'], $owner + $portal);
        $r->add('DELETE', "$p/tributacao/regras-piscofins/{perfil}/{operacao}", [$t, 'excluirPisCofins'], $owner + $portal);
        $r->add('GET', "$p/tributacao/cclasstrib", [$t, 'cclasstrib'], $portal);
        $r->add('GET', "$p/tributacao/regras-iss", [$t, 'listarIss'], $portal);
        $r->add('POST', "$p/tributacao/regras-iss", [$t, 'criarIss'], $owner + $portal);
        $r->add('POST', "$p/tributacao/regras-iss/copiar", [$t, 'copiarIss'], $owner + $portal);
        $r->add('GET', "$p/tributacao/regras-iss/{codigo}", [$t, 'detalheIss'], $portal);
        $r->add('PUT', "$p/tributacao/regras-iss/{codigo}", [$t, 'salvarIss'], $owner + $portal);
        $r->add('DELETE', "$p/tributacao/regras-iss/{codigo}", [$t, 'excluirIss'], $owner + $portal);
        $r->add('POST', "$p/tributacao/importar", [$t, 'importar'], $owner + $portal);
        $r->add('GET', "$p/nfse/config", [NfseController::class, 'getConfig'], []);
        $r->add('PUT', "$p/nfse/config", [NfseController::class, 'putConfig'], $owner);
        $r->add('GET', "$p/nfse/parametros", [NfseController::class, 'parametros']);
        $r->add('GET', "$p/nfse/servicos-nacionais", [NfseController::class, 'servicosNacionais'], ['portal' => true]); // lista pública (Anexo B)
        $r->add('GET', "$p/nfse", [NfseController::class, 'listar'], []);
        $r->add('POST', "$p/nfse", [NfseController::class, 'emitir']);
        $r->add('GET', "$p/nfse/{ref}", [NfseController::class, 'obter'], []);
        $r->add('GET', "$p/nfse/{ref}/xml", [NfseController::class, 'xml'], []);
        $r->add('GET', "$p/nfse/{ref}/danfse", [NfseController::class, 'danfse'], []);
        $r->add('POST', "$p/nfse/{ref}/sincronizar", [NfseController::class, 'sincronizar']);
        $r->add('POST', "$p/nfse/{ref}/reenviar-horario", [NfseController::class, 'reenviarHorario'], $owner);
        $r->add('POST', "$p/nfse/{ref}/corrigir", [NfseController::class, 'corrigir'], $owner);
        $r->add('POST', "$p/nfse/{ref}/cancelar", [NfseController::class, 'cancelar'], $fiscal);
        $this->router=$r;
    }
    public function handle(Request $req): Response
    {
        RequestContext::reset($req->ip);
        $spa = false;
        try {
            // v2.9.1 (CVE-2026-109618/109619): todos os gatilhos conhecidos chegam por multipart/form-data, que o
            // PHP processa ANTES do código da aplicação. O start.sh desliga essa leitura (enable_post_data_reading=0);
            // aqui a recusa fica explícita (415) e o painel envia arquivos em JSON/base64. Reativar só com PHP
            // corrigido e PERMITIR_MULTIPART=true no .env.
            if (str_starts_with(strtolower($req->header('content-type') ?? ''), 'multipart/')
                && !\App\Support\Env::bool('PERMITIR_MULTIPART', false)) {
                throw new ApiException(415, 'multipart_desativado', 'Envio multipart/form-data desativado por segurança. Envie JSON com o arquivo em base64 (veja a documentação da API).');
            }
            $m = $this->router->match($req);
            if ($m === null) {
                if (in_array($req->method, ['GET', 'HEAD'], true) && !str_starts_with($req->path . '/', '/api/')) {
                    $spa = true;
                    return $this->secure(\App\Http\Panel::serve($req->path), $req, true);
                }
                throw new ApiException(404, 'nao_encontrado', 'Rota não encontrada');
            }
            [$handler, $opts] = $m;
            if(str_starts_with($req->path,'/api/v1/nfse')){\App\Tenancy\FiscalScope::setModelo('nfse');}
            elseif(str_starts_with($req->path,'/api/v1/pdv/nfce')){\App\Tenancy\FiscalScope::setModelo(65);}
            elseif(isset($req->params['chave'])&&preg_match('/^\d{44}$/D',$req->params['chave'])&&in_array((int)substr($req->params['chave'],20,2),[55,65],true)){\App\Tenancy\FiscalScope::setModelo((int)substr($req->params['chave'],20,2));}
            elseif(in_array($req->path,['/api/v1/nfe','/api/v1/inutilizar'],true)&&$req->method==='POST'){ $model=(int)($req->json()['modelo']??55);if(in_array($model,[55,65],true)){\App\Tenancy\FiscalScope::setModelo($model);} }
            elseif(in_array((int)($req->query['modelo']??55),[55,65],true)){\App\Tenancy\FiscalScope::setModelo((int)($req->query['modelo']??55));}
            if (!empty($opts['auth_form'])) {
                Auth::checkSameOrigin($req);
            }
            if (empty($opts['public'])) {
                $p = Auth::authenticate($req);
                if ($p->via === \App\Auth\Principal::VIA_PORTAL && empty($opts['portal'])) {
                    \App\Support\Logger::audit('portal.rota', 'negado', ['path' => $req->path, 'metodo' => $req->method]);
                    throw new ApiException(403, 'portal_sem_permissao', 'Esta operação não está disponível no Portal do Contador. Emissão, cancelamento e configuração são feitos no sistema da empresa.');
                }
                if ((!empty($opts['central']) && $p->acessoCentral===null) || (!empty($opts['admin']) && $p->acessoCentral!=='admin')) {
                    throw new ApiException(403,'acesso_central_negado','Acesso administrativo não permitido');
                }
                $selected=$req->header('x-empresa-id');
                if($selected!==null) {
                    if($p->acessoCentral===null && $selected!==$p->tenantId){throw new ApiException(403,'empresa_nao_permitida','Credencial restrita a outra empresa');}
                    if(!Tenant::valid($selected)||TenantStore::get($selected)===null){throw new ApiException(404,'empresa_nao_encontrada','Empresa não encontrada');}
                    $p=$p->forTenant($selected);\App\Support\RequestContext::authenticate($p);
                } elseif ($p->acessoCentral!==null && !str_starts_with($req->path,'/api/v1/auth/') && !str_starts_with($req->path,'/api/v1/admin/') && !str_starts_with($req->path,'/api/v1/empresas') && $req->path!=='/api/v1/health') {
                    throw new ApiException(422,'empresa_obrigatoria','Informe X-Empresa-Id para selecionar a empresa');
                }
                if (!empty($opts['session']) && $p->via !== \App\Auth\Principal::VIA_SESSION) {
                    throw new ApiException(403, 'requer_sessao', 'Esta operação só pode ser feita pela interface web (sessão de usuário)');
                }
                
                
                if (empty($opts['mfa_livre']) && $p->via === \App\Auth\Principal::VIA_SESSION && $p->isOwner()
                    && !$p->mfaAtivo && \App\Auth\MfaService::obrigatorioParaDono()) {
                    // MFA_OBRIGATORIO_DONO=true: owner sem MFA só acessa o cadastro do MFA, a sessão e o logout
                    Logger::audit('acesso.negado', 'mfa_cadastro_obrigatorio', ['path' => $req->path, 'metodo' => $req->method]);
                    throw new ApiException(403, 'mfa_cadastro_obrigatorio', 'Ative a verificação em duas etapas (Perfil) para continuar: ela é obrigatória para proprietários.');
                }
                if (!empty($opts['owner']) && !$p->isOwner()) {
                    Logger::audit('acesso.negado', 'papel', ['path' => $req->path, 'metodo' => $req->method]);
                    throw new ApiException(403, 'proibido', 'Apenas o proprietário da empresa pode fazer esta operação');
                }
                if (!empty($opts['fiscal']) && !$p->canFiscal()) {
                    Logger::audit('acesso.negado', 'papel', ['path' => $req->path, 'metodo' => $req->method, 'papel' => $p->role]);
                    $oQue = 'Cancelamento, carta de correção e inutilização exigem';
                    throw new ApiException(403, 'proibido', $oQue . ' o papel proprietário ou fiscal. Solicite acesso fiscal ao administrador da empresa.');
                }
            }
            if ($req->method === 'POST' && in_array($req->path, ['/api/v1/nfe','/api/v1/nfse','/api/v1/pdv/nfce'],true)) {
                TenantStore::assertActive();
            }
            $resp = $handler($req);
        } catch (ApiException $e) {
            $resp = Response::error($e->status(), $e->errorCode, $e->getMessage(), $e->details, $e->headers);
            if ($req->isMutating() && RequestContext::$principal !== null && $e->status() >= 400) {
                // trilha de auditoria também para operações recusadas (quem/quando/tenant/resultado)
                Logger::audit('requisicao.recusada', $e->errorCode, ['metodo' => $req->method, 'path' => $req->path, 'status' => $e->status()]);
            }
        } catch (\Throwable $e) {
            Logger::exception($e, 'erro_nao_tratado ' . $req->method . ' ' . $req->path);
            $resp = Response::error(500, 'erro_interno', 'Erro interno. Informe o request_id ao suporte.');
        }
        return $this->secure($resp, $req, $spa);
    }

    private function secure(Response $r, Request $req, bool $spa = false): Response
    {
        $isDocs = $req->path === '/docs' || str_starts_with($req->path, '/docs/');
        $r->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'X-Request-Id' => RequestContext::$requestId,
            'Content-Security-Policy' => $spa ? self::SPA_CSP : ($isDocs
                ? "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
                : "default-src 'none'; frame-ancestors 'none'; base-uri 'none'"),
        ];
        if (!isset($r->headers['Cache-Control'])) {
            $r->headers['Cache-Control'] = 'no-store';
        }
        return $r;
    }

    public function health(): Response
    {
        return Response::json([
            'status' => 'ok',
            'versao' => self::VERSION,
            'hora' => date('c'),
        ]);
    }

    public function docs(): Response
    {
        $html = (string) file_get_contents(APP_ROOT . '/resources/docs.html');
        return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }

    public function docsAsset(Request $req): Response
    {
        $map = [
            'swagger-ui.css' => ['swagger-ui/swagger-ui.css', 'text/css; charset=utf-8'],
            'swagger-ui-bundle.js' => ['swagger-ui/swagger-ui-bundle.js', 'application/javascript; charset=utf-8'],
            'swagger-ui-standalone-preset.js' => ['swagger-ui/swagger-ui-standalone-preset.js', 'application/javascript; charset=utf-8'],
            'init.js' => ['docs-init.js', 'application/javascript; charset=utf-8'],
        ];
        $f = $req->params['file'];
        if (!isset($map[$f])) {
            throw new ApiException(404, 'nao_encontrado', 'Arquivo não encontrado');
        }
        return new Response(200, (string) file_get_contents(APP_ROOT . '/resources/' . $map[$f][0]), [
            'Content-Type' => $map[$f][1],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function openapi(): Response
    {
        return new Response(200, (string) file_get_contents(APP_ROOT . '/openapi.yaml'), [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function getConfig(): Response
    {
        return Response::json(ConfigService::publicView(ConfigService::get()));
    }

    public function putConfig(Request $req): Response
    {
        $c = ConfigService::put($req->json());
        Logger::audit('config.atualizada', 'sucesso', ['cnpj' => $c['cnpj'], 'ambiente' => $c['ambiente']]);
        return Response::json(ConfigService::publicView($c));
    }

    public function getCert(): Response
    {
        $m = CertificateStore::meta();
        if ($m === null) {
            return Response::json(['cadastrado' => false]);
        }
        $cfg = ConfigService::get();
        $cnpjRef = $cfg['cnpj'] ?? (TenantStore::get(Tenant::id())['cnpj'] ?? '');
        $m['cnpj_confere'] = $m['cnpj'] === '' || $cnpjRef === '' ? null : $m['cnpj'] === $cnpjRef;
        return Response::json(['cadastrado' => true] + $m);
    }

    public function postCert(Request $req): Response
    {
        $ct = strtolower($req->header('content-type') ?? '');
        if (str_starts_with($ct, 'multipart/form-data')) {
            $f = $req->files['certificado'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                throw ApiException::validation([['campo' => 'certificado', 'mensagem' => 'Envie o arquivo .pfx no campo "certificado"']]);
            }
            if ($f['size'] > CertificateStore::MAX_BYTES) {
                throw ApiException::validation([['campo' => 'certificado', 'mensagem' => 'Arquivo maior que 64 KB']]);
            }
            $pfx = (string) file_get_contents($f['tmp_name']);
            @unlink($f['tmp_name']);
            $senha = (string) ($req->post['senha'] ?? '');
        } else {
            $j = $req->json();
            $b64 = $j['certificado_base64'] ?? null;
            $pfx = is_string($b64) ? (string) base64_decode($b64, true) : '';
            $senha = is_string($j['senha'] ?? null) ? $j['senha'] : '';
        }
        $replacing = CertificateStore::meta() !== null;
        $event = $replacing ? 'certificado.substituicao' : 'certificado.upload';
        try {
            $meta = CertificateStore::save($pfx, $senha);
        } catch (ApiException $e) {
            Logger::audit($event, 'falha', ['motivo' => $e->errorCode]);
            throw $e;
        } finally {
            // não manter o conteúdo do pfx/senha em memória além do necessário
            $pfx = $senha = '';
        }
        $cfg = ConfigService::get();
        $cnpjRef = $cfg['cnpj'] ?? (TenantStore::get(Tenant::id())['cnpj'] ?? '');
        if ($cnpjRef !== '' && $meta['cnpj'] !== '' && $meta['cnpj'] !== $cnpjRef) {
            $meta['aviso'] = 'CNPJ do certificado difere do CNPJ da empresa/emitente';
        }
        Logger::audit($event, 'sucesso', ['titular' => $meta['titular'], 'valido_ate' => $meta['valido_ate']]);
        return Response::json(['cadastrado' => true, 'substituido' => $replacing] + $meta, 201);
    }

    public function deleteCert(): Response
    {
        CertificateStore::delete();
        Logger::audit('certificado.remocao', 'sucesso');
        return Response::noContent();
    }

    public function listNfe(Request $req): Response
    {
        $status = $req->query['status'] ?? null;
        if ($status !== null && !in_array($status, ['processando', 'autorizada', 'rejeitada', 'denegada', 'cancelada', 'erro'], true)) {
            throw ApiException::validation([['campo' => 'status', 'mensagem' => 'Status inválido']]);
        }
        $limit = (int) ($req->query['limite'] ?? 50);
        $modelo = isset($req->query['modelo']) ? self::modeloParam($req->query['modelo']) : null;
        if($modelo===null){
            $records=[];foreach([55,65] as $mod){foreach(\App\Tenancy\FiscalScope::forModelo($mod,fn()=>NfeRepository::list(max(1,min(200,$limit)),$status,$mod)) as $r){$records[$r['chave']]=$r;}}
            $records=array_values($records);usort($records,fn($a,$b)=>strcmp($b['atualizado_em']??'',$a['atualizado_em']??''));
            return Response::json(['nfes'=>array_slice($records,0,max(1,min(200,$limit)))]);
        }
        return Response::json(['nfes' => NfeRepository::list(max(1, min(200, $limit)), $status, $modelo)]);
    }

    private static function modeloParam(mixed $v): int
    {
        if ($v === null || $v === '') {
            return 55;
        }
        if (in_array($v, [55, 65, '55', '65'], true)) {
            return (int) $v;
        }
        throw ApiException::validation([['campo' => 'modelo', 'mensagem' => 'Modelo inválido: use 55 (NF-e) ou 65 (NFC-e)']]);
    }

    public function postNfe(Request $req): Response
    {
        $dry = $req->queryFlag('dry_run');
        $exec = function () use ($req, $dry) {
            [$status, $body] = EmissionService::emitir($req->json(), $dry, 'api');
            return Response::json($body, $status);
        };
        $key = $req->header('idempotency-key');
        if ($key === null && !$dry) {
            Logger::audit('nfe.emissao', 'sem_idempotency_key');
            throw new ApiException(400, 'idempotency_key_obrigatoria',
                'Emissão real exige o cabeçalho Idempotency-Key: um identificador único da nota (8 a 128 caracteres '
                . '[A-Za-z0-9_.:-]), por exemplo "Idempotency-Key: pedido-1001". Reenvie com a MESMA chave em caso de '
                . 'timeout ou erro de rede: a nota não é emitida duas vezes. Para apenas validar, use ?dry_run=1.',
                [['campo' => 'Idempotency-Key', 'mensagem' => 'Cabeçalho obrigatório na emissão real']]);
        }
        if ($key !== null) {
            if (!Idempotency::validKey($key)) {
                throw new ApiException(400, 'idempotency_key', 'Idempotency-Key inválida (8 a 128 caracteres [A-Za-z0-9_.:-])');
            }
            if (!$dry) {
                // erros de payload/certificado/CSC não "queimam" a chave (mesma regra do MCP):
                // o cliente corrige e reenvia com a mesma Idempotency-Key.
                self::preValidarEmissao($req->json());
            }
            return Idempotency::run('nfe' . ($dry ? ':dry' : ''), $key, $req->body, $exec);
        }
        return $exec();
    }

    private static function preValidarEmissao(array $payload): void
    {
        $p = EmissionService::normalizar($payload, false);
        $cfg = ConfigService::require();
        if (!CertificateStore::exists()) {
            throw new ApiException(412, 'certificado_ausente', 'Certificado A1 não cadastrado (POST /api/v1/certificado)');
        }
        if ((int) $p['modelo'] === Nfce::MODELO) {
            Nfce::assertCsc($cfg);
        }
    }

    public function getDanfe(Request $req): Response
    {
        $chave = $req->params['chave'];
        $pdf = \App\Nfe\DanfeRenderer::pdf($chave);
        Logger::audit('nfe.pdf', 'sucesso', ['chave' => $chave]);
        return new Response(200, $pdf, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $chave . '.pdf"',
        ]);
    }

    public function getXml(Request $req): Response
    {
        $chave = $req->params['chave'];
        $xml = (NfeRepository::xml($chave) ?? throw Tenant::notFound('XML'));
        Logger::audit('nfe.download_xml', 'sucesso', ['chave' => $chave]);
        return new Response(200, $xml, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $chave . '.xml"',
        ]);
    }

    public function cancelar(Request $req): Response
    {
        $j = $req->json();
        $just = Validate::text($j['justificativa'] ?? null, 15, 255);
        if ($just === null) {
            throw ApiException::validation([['campo' => 'justificativa', 'mensagem' => 'Entre 15 e 255 caracteres']]);
        }
        $r = EmissionService::cancelar($req->params['chave'], $just);
        return Response::json($r, $r['sucesso'] ? 200 : 422);
    }

    public function cce(Request $req): Response
    {
        $j = $req->json();
        $c = Validate::text($j['correcao'] ?? null, 15, 1000);
        if ($c === null) {
            throw ApiException::validation([['campo' => 'correcao', 'mensagem' => 'Entre 15 e 1000 caracteres']]);
        }
        $r = EmissionService::cce($req->params['chave'], $c);
        return Response::json($r, $r['sucesso'] ? 200 : 422);
    }

    public function inutilizar(Request $req): Response
    {
        $j = $req->json();
        $e = new Errors();
        $serie = $j['serie'] ?? null;
        $modelo = $j['modelo'] ?? 55;
        if (!in_array($modelo, [55, 65], true)) {
            $e->add('modelo', 'Modelo inválido: use 55 (NF-e) ou 65 (NFC-e)');
        }
        $ini = $j['numero_inicial'] ?? null;
        $fim = $j['numero_final'] ?? null;
        if (!is_int($serie) || $serie < 0 || $serie > 889) {
            $e->add('serie', 'Inteiro entre 0 e 889');
        }
        if (!is_int($ini) || $ini < 1 || $ini > 999999999) {
            $e->add('numero_inicial', 'Inteiro entre 1 e 999999999');
        }
        if (!is_int($fim) || $fim < 1 || $fim > 999999999 || (is_int($ini) && $fim < $ini)) {
            $e->add('numero_final', 'Inteiro >= numero_inicial');
        }
        $just = Validate::text($j['justificativa'] ?? null, 15, 255);
        if ($just === null) {
            $e->add('justificativa', 'Entre 15 e 255 caracteres');
        }
        $e->throwIfAny();
        $r = EmissionService::inutilizar($serie, $ini, $fim, $just, $modelo);
        return Response::json($r, $r['sucesso'] ? 200 : 422);
    }

}
