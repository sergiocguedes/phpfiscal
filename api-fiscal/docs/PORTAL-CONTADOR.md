# Portal do Contador

Aplicação web (HTML/CSS/JS, sem build) servida pela própria API em `/portal`, para o cliente e o contador acompanharem a empresa, sem senha: o acesso vem de um **atalho** dentro de cada sistema da LSI.

A tributação (perfis, operações, regras de ICMS, PIS/COFINS e ISS e o simulador) foi **retirada** do portal e da API: os códigos fiscais vêm no JSON de cada nota, enviados pelo sistema que integra, e a API calcula no envio (ver [TRIBUTOS-CALCULO.md](TRIBUTOS-CALCULO.md)). Hoje o portal tem só a página **Início**; notas fiscais e relatórios virão depois.

| Ambiente | API | Portal |
| --- | --- | --- |
| Local | `http://localhost:8081/api/v1/...` | `http://localhost:8081/portal` |
| Online | `https://contador.meuclickonline.com.br/api/v1/...` | `https://contador.meuclickonline.com.br/portal` |

Um único domínio e o mesmo banco PostgreSQL: o portal é uma tela a mais da API, não um serviço separado. Por isso o cookie da sessão do portal é de primeira parte e o CSP continua `'self'`.

## Fluxo do atalho

```
Sistema (ERP/PDV)                 API                                  Navegador
      | POST /api/v1/portal/acessos  |                                      |
      |  Authorization: Bearer chave |                                      |
      |----------------------------->| grava hash do token (5 min, 1 uso)   |
      |<---- 201 {url, expira_em} ---|                                      |
      | abre o navegador em url ----------------------------------------->  |
      |                              |<-- POST /api/v1/portal/sessao {token}|
      |                              |--- cookie de sessão + contexto ----->|
```

- O token vai no fragmento da URL (`/portal/acesso#t=...`), que o navegador não envia ao servidor nem em `Referer`. O portal troca o token pela sessão e apaga o fragmento do histórico.
- O link vale uma vez. Reaberto, mostra "Este link já foi usado"; basta clicar de novo no atalho do sistema.
- A chave de API nunca chega ao navegador.

### Pedido

`POST /api/v1/portal/acessos` com a chave de API da empresa:

```json
{
  "usuario": { "id": "42", "nome": "Maria Souza", "email": "maria@empresa.com.br" },
  "perfil": "fiscal",
  "tipo_usuario": "cliente",
  "abrir": { "aba": "inicio" },
  "sistema": "ClickPDV"
}
```

| Campo | Obrigatório | Valores |
| --- | --- | --- |
| `usuario.id` | sim | identificador do usuário no sistema (1–60) |
| `usuario.nome` | sim | 1–80 caracteres |
| `usuario.email` | não | e-mail válido |
| `perfil` | não (`fiscal`) | `fiscal` ou `consulta` (somente leitura) |
| `tipo_usuario` | não (`cliente`) | `cliente` ou `contador` (identificação e auditoria) |
| `abrir.aba` | não (`inicio`) | `inicio`. As abas antigas (`perfis`, `operacoes`, `regras-icms`, `regras-piscofins`, `regras-iss`, `simulador`), usadas por atalhos já distribuídos, continuam aceitas e abrem o início (com `abrir.acao`/`abrir.chave` ignorados) |
| `sistema` | não | nome do sistema que abriu o portal |

Resposta `201`:

```json
{ "id": "acs_3f1c...", "url": "http://localhost:8081/portal/acesso#t=...", "expira_em": "2026-11-07T14:05:00-03:00" }
```

A empresa da sessão é sempre a da chave de API; não há como trocar de empresa dentro do portal.

**Suporte:** o administrador ou operador do painel abre o portal de qualquer empresa ativa pelo botão **Abrir Portal Fiscal**. A mesma rota, chamada com a sessão do painel e `X-Empresa-Id`, ignora `usuario` e `tipo_usuario` do corpo: usa o usuário logado e o tipo `suporte` (exibido no portal com uma faixa de aviso). Pedidos com chave de API e `tipo_usuario: suporte` recebem 422.

### Exemplos prontos

- Delphi: `examples/delphi/AtalhoPortalFiscal.pas` (usa `FiscalApiCliente.pas` e o mesmo INI).
- PHP/MadBuilder: `examples/php/atalho-portal.php` (o servidor do sistema pede o link e redireciona com `303`).

## O que a sessão do portal pode fazer

A sessão do portal só é aceita nas rotas marcadas para ele. Qualquer outra responde `403 portal_sem_permissao` e fica na auditoria (`portal.rota`).

| Liberado | Bloqueado |
| --- | --- |
| `GET /portal/contexto`, `POST /portal/sair` | emissão NF-e, NFC-e, NFS-e e PDV |
| `GET /nfse/servicos-nacionais` (lista pública) | cancelamento, inutilização, carta de correção |
| | cálculo de tributos (`POST /tributos/calcular`), configuração da empresa e certificado |
| | gerar novos acessos (`POST /portal/acessos`) |

Cancelamento continua vindo só do ERP.

## Segurança da sessão

- Cookie `__Host-portal_sessao` em HTTPS (`portal_sessao` em HTTP local), `HttpOnly`, `SameSite=Strict`, sem domínio.
- Toda escrita exige o cabeçalho `X-CSRF-Token` e origem igual à da API. O front envia `X-Portal: 1`; sem sessão válida a resposta é `401 portal_sessao_expirada`.
- Expira após 1 h sem uso e, de qualquer forma, após 8 h.
- A troca de token é limitada por IP. Tokens e sessões ficam gravados só como hash SHA-256.
- Ações ficam na auditoria com o ator `portal:<usuario.id>`.

## Variáveis de ambiente

| Variável | Padrão | Uso |
| --- | --- | --- |
| `PORTAL_URL` | `APP_URL` + `/portal` | base do link devolvido ao sistema |
| `PORTAL_LINK_SECONDS` | `300` (30–900) | validade do link de uso único |
| `PORTAL_IDLE_SECONDS` | `3600` | expiração por inatividade |
| `PORTAL_ABSOLUTE_SECONDS` | `28800` | duração máxima da sessão |
| `PORTAL_SESSAO_IP_MAX` | `30` | trocas de token por IP a cada 15 min |

Online, com tudo em `contador.meuclickonline.com.br`, basta `APP_URL=https://contador.meuclickonline.com.br`. O proxy reverso encaminha o domínio inteiro ao mesmo serviço: `/api/` são as rotas da API, `/portal` é o portal e a raiz continua sendo o painel administrativo.

## Telas atuais

- **Início**: boas-vindas e o que vem a seguir.

Em breve: notas fiscais (consulta, XML, DANFE) e relatórios.

As tabelas padrão (IBPT e outras tabelas auxiliares comuns a todos os clientes) não aparecem para cliente nem contador: serão mantidas só pelo administrador do portal, em etapa posterior.
