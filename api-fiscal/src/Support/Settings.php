<?php
declare(strict_types=1);
namespace App\Support;

use App\Http\ApiException;
use App\Security\Crypto;
use App\Storage\{JsonStore, PostgresStore};

/**
 * Configurações do sistema guardadas no PostgreSQL, cifradas com a chave-mestra (CERT_ENCRYPTION_KEY).
 *
 * Fora do banco ficam só os dados de inicialização (Env::BOOTSTRAP): conexão com o banco e a chave-mestra.
 * Todo o resto é lido daqui por Env::get(), com cache por processo; a janela /configuracao altera os valores.
 * O documento inteiro é cifrado (XSalsa20-Poly1305): no banco só aparecem versão, data e autor.
 */
final class Settings
{
    public const PATH = 'system/configuracao.json';

    /** Grupos exibidos na janela de configuração: chave => [título, descrição]. */
    public const GRUPOS = [
        'acesso' => ['Endereço e acesso', 'Endereço público da API e do portal, proxies confiáveis e cookie seguro.'],
        'sessao' => ['Sessões e login', 'Tempo de sessão, bloqueio por tentativas e verificação em duas etapas.'],
        'portal' => ['Portal do Contador', 'Validade do link de acesso e das sessões do portal.'],
        'api' => ['Limites da API', 'Proteção contra força bruta nas chaves de API e envio de arquivos.'],
        'email' => ['E-mail (SMTP)', 'Servidor usado para convites e recuperação de senha.'],
        'fiscal' => ['NFS-e e NFC-e', 'Tempo limite, leiaute e contingência dos documentos fiscais.'],
        'armazenamento' => ['Armazenamento de XML', 'Onde ficam os XML fiscais: no banco ou no Backblaze B2. Com o B2, o XML fica no banco até o worker enviar e conferir.'],
    ];

    /**
     * Definição de cada configuração: grupo, rótulo, tipo (text|url|int|bool|secret|select|email), padrão,
     * ajuda e restrições (min/max/opcoes). O padrão é o mesmo que o código usava quando a variável faltava.
     */
    public static function definicoes(): array
    {
        if (self::$defs !== null) { return self::$defs; }
        $leiautes = [['', 'Padrão do ambiente']];
        foreach (\App\Nfse\NfseXml::LEIAUTES as $k => $v) { $leiautes[] = [$k, $k . ' · ' . $v]; }
        return self::$defs = [
            'APP_URL' => ['acesso', 'Endereço público da API', 'url', 'http://127.0.0.1:8095', 'Ex.: https://api.suaempresa.com.br — usado nos links de e-mail e do portal.'],
            'PORTAL_URL' => ['acesso', 'Endereço do Portal do Contador', 'url', '', 'Vazio = endereço da API + /portal.'],
            'TRUSTED_PROXIES' => ['acesso', 'Proxies confiáveis (IPs)', 'text', '', 'IPs separados por vírgula cujo X-Forwarded-For é aceito. Vazio = nenhum.'],
            'SESSION_COOKIE_SECURE' => ['acesso', 'Cookie de sessão só em HTTPS', 'bool', '1', 'Desligue apenas em teste local por HTTP (http://localhost).'],
            'CENTRAL_AUTH' => ['acesso', 'Administração central ativa', 'bool', '1', 'Permite usuários e chaves centrais que gerenciam várias empresas.'],

            'SESSION_IDLE_SECONDS' => ['sessao', 'Inatividade máxima da sessão (s)', 'int', '1800', '', 300, 86400],
            'SESSION_ABSOLUTE_SECONDS' => ['sessao', 'Duração máxima da sessão (s)', 'int', '43200', '', 900, 604800],
            'LOGIN_MAX_FAILURES' => ['sessao', 'Tentativas de login antes do bloqueio', 'int', '5', '', 3, 50],
            'LOGIN_LOCK_SECONDS' => ['sessao', 'Bloqueio da conta (s)', 'int', '900', '', 60, 86400],
            'LOGIN_IP_MAX' => ['sessao', 'Logins por IP na janela', 'int', '20', '', 5, 1000],
            'SIGNUP_IP_MAX' => ['sessao', 'Cadastros por IP na janela', 'int', '5', '', 1, 100],
            'FORGOT_IP_MAX' => ['sessao', 'Recuperações de senha por IP', 'int', '5', '', 1, 100],
            'MFA_OBRIGATORIO_DONO' => ['sessao', 'Exigir verificação em duas etapas do proprietário', 'bool', '0', 'Proprietário sem MFA só acessa o cadastro do MFA.'],

            'PORTAL_LINK_SECONDS' => ['portal', 'Validade do link de acesso (s)', 'int', '300', '', 30, 900],
            'PORTAL_IDLE_SECONDS' => ['portal', 'Inatividade máxima (s)', 'int', '3600', '', 300, 86400],
            'PORTAL_ABSOLUTE_SECONDS' => ['portal', 'Duração máxima da sessão (s)', 'int', '28800', '', 900, 604800],
            'PORTAL_SESSAO_IP_MAX' => ['portal', 'Aberturas de sessão por IP', 'int', '30', '', 5, 1000],

            'AUTH_MAX_FAILURES' => ['api', 'Falhas de chave antes do bloqueio', 'int', '10', '', 3, 100],
            'AUTH_WINDOW_SECONDS' => ['api', 'Janela de contagem de falhas (s)', 'int', '300', '', 60, 3600],
            'AUTH_BLOCK_SECONDS' => ['api', 'Bloqueio após falhas (s)', 'int', '900', '', 60, 86400],
            'PERMITIR_MULTIPART' => ['api', 'Aceitar envio multipart/form-data', 'bool', '0', 'Mantenha desligado: arquivos são enviados em JSON/base64.'],
            'WEBHOOK_ALLOW_PRIVATE_URLS' => ['api', 'Permitir URLs de rede privada', 'bool', '0', 'Somente para testes locais.'],

            'SMTP_HOST' => ['email', 'Servidor SMTP', 'text', '', 'Vazio = e-mails não são enviados.'],
            'SMTP_PORT' => ['email', 'Porta', 'int', '587', '', 1, 65535],
            'SMTP_SECURE' => ['email', 'Segurança', 'select', 'tls', '', null, null, [['tls', 'STARTTLS'], ['ssl', 'SSL/TLS'], ['none', 'Sem criptografia']]],
            'SMTP_USER' => ['email', 'Usuário', 'text', ''],
            'SMTP_PASSWORD' => ['email', 'Senha', 'secret', '', 'Guardada cifrada. Deixe em branco para manter a atual.'],
            'SMTP_FROM' => ['email', 'Remetente (e-mail)', 'email', 'no-reply@localhost'],
            'SMTP_FROM_NAME' => ['email', 'Remetente (nome)', 'text', 'Sistema NF-e'],
            'MAIL_DEV_LOG' => ['email', 'Gravar e-mails em log em vez de enviar', 'bool', '0', 'Somente desenvolvimento: o log contém links de redefinição.'],

            'NFSE_TIMEOUT' => ['fiscal', 'Tempo limite Sefin/ADN (s)', 'int', '60', '', 10, 120],
            'NFSE_RATE_MAX' => ['fiscal', 'Emissões de NFS-e por minuto (por empresa)', 'int', '60', '', 1, 1000],
            'NFSE_DHEMI_RECUO_SEGUNDOS' => ['fiscal', 'Recuo do horário de emissão (s)', 'int', '30', 'Margem contra relógio adiantado (E0008).', 30, 300],
            'NFSE_DANFSE_TIMEOUT' => ['fiscal', 'Tempo limite do DANFSe no ADN (s)', 'int', '8', '', 2, 60],
            'NFSE_DANFSE_PAUSA_ADN' => ['fiscal', 'Pausa após falha do ADN (s)', 'int', '300', '', 0, 3600],
            'NFSE_LEIAUTE_PRODUCAO' => ['fiscal', 'Leiaute NFS-e em produção', 'select', '', '', null, null, $leiautes],
            'NFSE_LEIAUTE_HOMOLOGACAO' => ['fiscal', 'Leiaute NFS-e em homologação', 'select', '', '', null, null, $leiautes],
            'XML_STORAGE' => ['armazenamento', 'Destino dos XML', 'select', 'banco', 'Ao voltar para "banco", XML já enviados continuam sendo lidos do B2: mantenha as credenciais.', null, null, [['banco', 'Banco de dados (PostgreSQL)'], ['s3', 'Backblaze B2']]],
            'S3_ENDPOINT' => ['armazenamento', 'Endpoint S3 do B2', 'url', '', 'Ex.: https://s3.us-east-005.backblazeb2.com (Buckets → Endpoint).'],
            'S3_REGION' => ['armazenamento', 'Região', 'text', '', 'Parte do endpoint, ex.: us-east-005.'],
            'S3_BUCKET' => ['armazenamento', 'Bucket (privado)', 'text', ''],
            'S3_ACCESS_KEY_ID' => ['armazenamento', 'keyID', 'text', '', 'Application Key com acesso só a este bucket.'],
            'S3_SECRET_ACCESS_KEY' => ['armazenamento', 'applicationKey', 'secret', '', 'Guardada cifrada. Deixe em branco para manter a atual.'],
            'S3_PREFIX' => ['armazenamento', 'Prefixo dos objetos', 'text', 'fiscal', 'Pasta dentro do bucket. Não altere depois de enviar XML.'],
            'S3_SSE' => ['armazenamento', 'Criptografia no B2 (SSE-B2)', 'bool', '1'],
            'S3_TIMEOUT' => ['armazenamento', 'Tempo limite (s)', 'int', '20', '', 5, 120],
            'NFCE_CONTINGENCIA_UFS' => ['fiscal', 'UFs com contingência NFC-e homologada', 'text', '', 'Siglas separadas por vírgula, ex.: AM,PA.'],
        ];
    }

    /** @var array<string,string>|null */
    private static ?array $cache = null;
    private static ?array $defs = null;
    private static bool $loading = false;

    public static function reset(): void { self::$cache = null; }

    /** Valor gravado no banco, ou null se não definido / banco indisponível durante a inicialização. */
    public static function get(string $key): ?string
    {
        if (self::$loading || !isset(self::definicoes()[$key])) { return null; }
        $all = self::load();
        return $all[$key] ?? null;
    }

    /** @return array<string,string> */
    private static function load(): array
    {
        if (self::$cache !== null) { return self::$cache; }
        self::$loading = true;
        try {
            $doc = JsonStore::read(self::PATH);
        } catch (\PDOException $e) {
            // tabela ainda não criada (bin/banco.php) ou banco fora do ar: usa os padrões sem guardar cache
            return [];
        } finally {
            self::$loading = false;
        }
        if ($doc === null || !isset($doc['cifrado'])) { return self::$cache = []; }
        try {
            $plain = json_decode(Crypto::decrypt((string) $doc['cifrado']), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Configurações do banco ilegíveis: a chave-mestra (CERT_ENCRYPTION_KEY) não é a mesma usada para gravá-las.');
        }
        return self::$cache = array_map('strval', is_array($plain) ? $plain : []);
    }

    /** @param array<string,string> $values */
    private static function store(array $values, string $autor): void
    {
        ksort($values);
        JsonStore::write(self::PATH, [
            'versao' => 1,
            'cifrado' => Crypto::encrypt(json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'atualizado_em' => date('c'),
            'atualizado_por' => $autor,
        ]);
        self::$cache = $values;
    }

    /** Valor efetivo e sua origem: banco, arquivo de inicialização legado ou padrão do sistema. */
    private static function efetivo(string $key, array $def): array
    {
        $db = self::get($key);
        if ($db !== null) { return [$db, 'banco']; }
        $legacy = Env::fileValue($key);
        if ($legacy !== null) { return [$legacy, 'arquivo']; }
        return [(string) $def[3], 'padrao'];
    }

    /** Visão para a janela: segredos nunca saem do servidor, só a indicação de que estão definidos. */
    public static function publicView(): array
    {
        $doc = JsonStore::read(self::PATH);
        $itens = [];
        foreach (self::definicoes() as $key => $d) {
            [$valor, $origem] = self::efetivo($key, $d);
            $item = ['chave' => $key, 'grupo' => $d[0], 'rotulo' => $d[1], 'tipo' => $d[2], 'padrao' => $d[2] === 'secret' ? '' : (string) $d[3],
                'ajuda' => $d[4] ?? '', 'origem' => $origem, 'min' => $d[5] ?? null, 'max' => $d[6] ?? null, 'opcoes' => $d[7] ?? null];
            if ($d[2] === 'secret') { $item['definido'] = $valor !== ''; $item['valor'] = ''; }
            else { $item['valor'] = $valor; }
            $itens[] = $item;
        }
        $grupos = [];
        foreach (self::GRUPOS as $k => [$t, $desc]) { $grupos[] = ['codigo' => $k, 'titulo' => $t, 'descricao' => $desc]; }
        return ['grupos' => $grupos, 'itens' => $itens, 'atualizado_em' => $doc['atualizado_em'] ?? null, 'atualizado_por' => $doc['atualizado_por'] ?? null,
            'inicializacao' => Env::bootstrapView()];
    }

    /**
     * Salva os valores de um grupo. Campo omitido no grupo = volta ao padrão; segredo vazio = mantém o atual
     * (para apagar, envie {"CHAVE": null} explicitamente ou "limpar_segredos": ["CHAVE"]).
     */
    public static function saveGroup(string $grupo, array $in, string $autor): array
    {
        if (!isset(self::GRUPOS[$grupo])) { throw new ApiException(404, 'grupo_inexistente', 'Grupo de configuração inexistente'); }
        $e = new Errors();
        $all = self::load();
        $limpar = is_array($in['limpar_segredos'] ?? null) ? $in['limpar_segredos'] : [];
        foreach (self::definicoes() as $key => $d) {
            if ($d[0] !== $grupo) { continue; }
            $has = array_key_exists($key, $in);
            $v = $has ? $in[$key] : null;
            if ($d[2] === 'secret') {
                if (in_array($key, $limpar, true)) { unset($all[$key]); continue; }
                if ($v === null || $v === '') { continue; }
            }
            if ($v === null || (is_string($v) && trim($v) === '')) {
                if ($d[2] === 'bool' && $has) { $all[$key] = '0'; } else { unset($all[$key]); }
                continue;
            }
            $ok = self::normalize($key, $d, $v, $e);
            if ($ok === null) { continue; }
            if ($d[2] !== 'secret' && $ok === (string) $d[3] && Env::fileValue($key) === null) { unset($all[$key]); continue; } // igual ao padrão: não grava
            $all[$key] = $ok;
        }
        if ($grupo === 'armazenamento' && ($all['XML_STORAGE'] ?? Env::fileValue('XML_STORAGE')) === 's3') {
            foreach (['S3_ENDPOINT', 'S3_REGION', 'S3_BUCKET', 'S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY'] as $k) {
                if (($all[$k] ?? Env::fileValue($k)) === null) { $e->add($k, 'Obrigatório para usar o Backblaze B2'); }
            }
        }
        $e->throwIfAny();
        JsonStore::withLock('configuracao-sistema', fn() => self::store($all, $autor));
        Logger::audit('configuracao.atualizada', 'sucesso', ['grupo' => $grupo]);
        return self::publicView();
    }

    /**
     * Valores do grupo armazenamento como ficariam se salvos (segredo em branco = o atual), para testar o B2
     * antes de gravar. Nada é persistido.
     * @return array<string,string>
     */
    public static function previewArmazenamento(array $in): array
    {
        $e = new Errors(); $out = [];
        foreach (self::definicoes() as $key => $d) {
            if ($d[0] !== 'armazenamento') { continue; }
            $v = $in[$key] ?? null;
            if ($v === null || (is_string($v) && trim($v) === '')) { $cur = Env::get($key); if ($cur !== null) { $out[$key] = $cur; } continue; }
            $ok = self::normalize($key, $d, $v, $e);
            if ($ok !== null) { $out[$key] = $ok; }
        }
        $e->throwIfAny();
        return $out;
    }

    private static function normalize(string $key, array $d, mixed $v, Errors $e): ?string
    {
        if (is_bool($v)) { $v = $v ? '1' : '0'; }
        if (!is_scalar($v)) { $e->add($key, 'Valor inválido'); return null; }
        $v = trim((string) $v);
        if (preg_match('/[\x00-\x1f\x7f]/', $v)) { $e->add($key, 'Caracteres de controle não são permitidos'); return null; }
        if (mb_strlen($v) > 500) { $e->add($key, 'Máximo de 500 caracteres'); return null; }
        switch ($d[2]) {
            case 'int':
                if (!preg_match('/^\d{1,9}$/', $v)) { $e->add($key, 'Informe um número inteiro'); return null; }
                if (($d[5] ?? null) !== null && ((int) $v < $d[5] || (int) $v > $d[6])) { $e->add($key, "Entre {$d[5]} e {$d[6]}"); return null; }
                return (string) (int) $v;
            case 'bool':
                return in_array(strtolower($v), ['1', 'true', 'on', 'sim', 'yes'], true) ? '1' : '0';
            case 'url':
                $p = parse_url($v);
                if ($key === 'S3_ENDPOINT' && (($p['scheme'] ?? '') !== 'https' || !empty($p['path']) && $p['path'] !== '/')) { $e->add($key, 'Use https://… sem caminho'); return null; }
                if (!filter_var($v, FILTER_VALIDATE_URL) || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) {
                    $e->add($key, 'URL http(s) sem usuário, consulta ou fragmento'); return null;
                }
                return rtrim($v, '/');
            case 'email':
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) { $e->add($key, 'E-mail inválido'); return null; }
                return $v;
            case 'select':
                if (!in_array($v, array_map(fn($o) => (string) $o[0], $d[7]), true)) { $e->add($key, 'Opção inválida'); return null; }
                return $v;
            default:
                if ($key === 'S3_BUCKET' && !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $v)) { $e->add($key, 'Bucket: minúsculas, números, ponto e hífen'); return null; }
                if ($key === 'S3_REGION' && !preg_match('/^[a-z0-9-]{2,40}$/D', $v)) { $e->add($key, 'Região inválida'); return null; }
                if ($key === 'S3_PREFIX' && (!preg_match('~^[a-zA-Z0-9/_-]{0,100}$~D', $v) || str_contains($v, '..'))) { $e->add($key, 'Prefixo: letras, números, / _ -'); return null; }
                if ($key === 'S3_ACCESS_KEY_ID' && !preg_match('/^[A-Za-z0-9]{8,64}$/D', $v)) { $e->add($key, 'keyID inválido'); return null; }
                if ($key === 'TRUSTED_PROXIES') {
                    foreach (array_filter(array_map('trim', explode(',', $v))) as $ip) {
                        if (!filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP)) { $e->add($key, "IP inválido: $ip"); return null; }
                    }
                }
                if ($key === 'NFCE_CONTINGENCIA_UFS') {
                    $ufs = array_values(array_filter(array_map(fn($u) => strtoupper(trim($u)), explode(',', $v))));
                    foreach ($ufs as $u) { if (!preg_match('/^[A-Z]{2}$/', $u)) { $e->add($key, "UF inválida: $u"); return null; } }
                    return implode(',', $ufs);
                }
                return $v;
        }
    }

    /**
     * Importa para o banco as configurações que ainda estão no arquivo de inicialização (.env legado).
     * Não sobrescreve valores já gravados no banco. Retorna as chaves importadas.
     * @return list<string>
     */
    public static function importarArquivo(string $autor = 'importacao'): array
    {
        $all = self::load(); $novas = [];
        foreach (self::definicoes() as $key => $d) {
            $v = Env::fileValue($key);
            if ($v === null || isset($all[$key])) { continue; }
            $e = new Errors();
            $ok = self::normalize($key, $d, $v, $e);
            if ($ok !== null && !$e->any()) { $all[$key] = $ok; $novas[] = $key; }
        }
        if ($novas !== []) {
            JsonStore::withLock('configuracao-sistema', fn() => self::store($all, $autor));
            Logger::audit('configuracao.importada', 'sucesso', ['quantidade' => count($novas)]);
        }
        return $novas;
    }

    /** Garante que o armazenamento é o PostgreSQL antes de expor a janela (nos testes, arquivos). */
    public static function backend(): string { return PostgresStore::enabled() ? 'PostgreSQL' : 'arquivos (testes)'; }
}
