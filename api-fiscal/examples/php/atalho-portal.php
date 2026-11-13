<?php
/**
 * Atalho "Portal Fiscal" para sistemas web/PHP (ex.: MadBuilder).
 *
 * O servidor do sistema pede à API um link de uso único e redireciona o navegador
 * do usuário para ele. A chave de API nunca vai para o navegador.
 *
 * Configuração (variáveis de ambiente do sistema, não do portal):
 *   FISCAL_API_URL   ex.: http://localhost:8080   (online: https://contador.meuclickonline.com.br)
 *   FISCAL_API_CHAVE chave de API da empresa
 */

declare(strict_types=1);

function abrirPortalFiscal(array $usuario, string $perfil = 'fiscal', string $tipoUsuario = 'cliente', string $aba = 'inicio'): never
{
    $base = rtrim((string) getenv('FISCAL_API_URL'), '/');
    $chave = (string) getenv('FISCAL_API_CHAVE');
    if ($base === '' || $chave === '') {
        throw new RuntimeException('Configure FISCAL_API_URL e FISCAL_API_CHAVE.');
    }

    $pedido = [
        'usuario' => array_filter([
            'id' => (string) $usuario['id'],
            'nome' => (string) $usuario['nome'],
            'email' => $usuario['email'] ?? null,
        ]),
        'perfil' => $perfil,             // fiscal (altera regras) | consulta (somente leitura)
        'tipo_usuario' => $tipoUsuario,  // cliente | contador
        'abrir' => ['aba' => $aba],      // inicio (abas antigas da tributação abrem o início)
        'sistema' => 'MadBuilder',
    ];

    $ch = curl_init($base . '/api/v1/portal/acessos');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($pedido, JSON_UNESCAPED_UNICODE),
    ]);
    $corpo = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $resp = is_string($corpo) ? json_decode($corpo, true) : null;
    if ($status !== 201 || !is_string($resp['url'] ?? null)) {
        $msg = $resp['erro']['mensagem'] ?? 'Falha ao gerar o acesso';
        throw new RuntimeException("Portal Fiscal indisponível (HTTP $status): $msg");
    }

    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $resp['url'], true, 303);
    exit;
}

// Exemplo: botão "Portal Fiscal" do sistema
// abrirPortalFiscal(['id' => 42, 'nome' => 'Maria Souza', 'email' => 'maria@empresa.com.br'], 'fiscal', 'cliente', 'inicio');
