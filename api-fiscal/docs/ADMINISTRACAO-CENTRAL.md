# Administração central da API Fiscal

A partir de 3.1.0-api, as empresas não fazem autocadastro e não precisam de login próprio. O painel e as APIs administrativas usam usuários autorizados. As rotas públicas `/cadastro` e `POST /api/v1/auth/cadastro` foram retiradas.

## Acessos

| Acesso | Permissões |
|---|---|
| Administrador (`acesso_central=admin`) | Gerencia todas as empresas, usuários, chaves administrativas e configurações fiscais |
| Operador (`acesso_central=operador`) | Gerencia todas as empresas e suas configurações, certificados e chaves por empresa; não gerencia usuários nem chaves globais |
| Chave administrativa (`adm_...`) | Integração com acesso administrativo a todas as empresas; deve selecionar a empresa nas operações fiscais |
| Chave da empresa (`nfk_...`) | Integração restrita a uma empresa; não lista nem gerencia outras empresas |

Usuários anteriores não são promovidos automaticamente. As chaves de API existentes continuam restritas à empresa original. O último administrador ativo não pode ser bloqueado ou rebaixado. Alterar o acesso de um usuário invalida suas sessões anteriores.

O primeiro administrador é criado pelo terminal, sem rota pública de bootstrap:

```bash
php bin/administrador.php seu-email@empresa.com --gerar-senha
```

A senha aleatória aparece somente no terminal e fica armazenada como hash. Para promover um usuário existente preservando sua senha, execute sem `--gerar-senha`. Com essa opção, a senha existente será substituída. Nunca inclua senhas em scripts versionados.

## Painel

Depois do login, abra **Todas as empresas** e clique em **Configurar empresa**. O cadastro e todas as configurações ficam em uma única tela, com as abas **Cadastro e ambiente**, **Dados fiscais e séries**, **Certificado A1**, **Chaves de API**, **Prestador NFS-e** e **Resumo**. O CNPJ da configuração deve corresponder ao cadastro selecionado. Cada aba tem seu próprio botão de salvar; alternar entre abas preserva os campos ainda não salvos enquanto a tela da mesma empresa permanecer aberta.

Na aba **Cadastro e ambiente** da empresa, configure:

- `ativa`: permite novas emissões. Inativar não remove documentos, consultas, cancelamentos ou recuperação de operações já existentes.
- `ambiente_nfe`, `ambiente_nfce` e `ambiente_nfse`: `1` produção ou `2` homologação, independentes entre si.

Cada ambiente tem configuração, CSC, numeração, documentos, operações PDV e idempotência separados. Ao mudar o ambiente, configure o perfil correspondente antes de emitir. Trocar o ambiente não converte documentos existentes. Salve os formulários das outras abas antes de trocar o ambiente, mudar de empresa ou recarregar a página. O certificado A1 e as chaves por empresa são compartilhados entre os dois perfis. Não existe trava global de produção: o ambiente é definido exclusivamente pelos três parâmetros da empresa. Dados do emitente são compartilhados; numeração e CSC usam o perfil do respectivo modelo.

A URL da configuração inclui o identificador da empresa e a aba, permitindo atualizar a página e abrir links diretamente. Os endereços antigos `/certificado`, `/chaves` e `/nfse` abrem a aba correspondente da empresa selecionada. Sem empresa selecionada, a tela pede explicitamente que uma empresa seja escolhida; não exibe silenciosamente outro menu.

Em **Usuários permitidos**, o administrador cadastra ou bloqueia acessos. Em **Administração e arquivos**, acompanha o arquivamento e administra as credenciais globais. O painel não possui telas de emissão direta.

## APIs administrativas

| Método | Rota | Uso |
|---|---|---|
| GET/POST | `/api/v1/empresas` | Listar/cadastrar empresas |
| PUT | `/api/v1/empresas/{id}` | Alterar nome, ativação e ambiente |
| GET/POST | `/api/v1/admin/usuarios` | Listar/criar usuários autorizados |
| PUT | `/api/v1/admin/usuarios/{id}` | Alterar `ativo` e `acesso_central` |
| GET/POST | `/api/v1/admin/chaves` | Listar/criar chaves administrativas |
| DELETE | `/api/v1/admin/chaves/{id}` | Revogar chave administrativa |
| GET | `/api/v1/admin/armazenamento` | Backend e totais de arquivamento |

Autentique com sessão e CSRF ou `Authorization: Bearer adm_...`. Operadores têm acesso às três operações de empresas, mas não a `/admin/*`.

```http
POST /api/v1/empresas
Authorization: Bearer adm_SUA_CHAVE
Content-Type: application/json

{"nome":"Minha empresa","cnpj":"11222333000181","ativa":true,"ambiente_nfe":2,"ambiente_nfce":2,"ambiente_nfse":2}
```

Use o `id` devolvido para configurar a empresa com as rotas já existentes:

```http
PUT /api/v1/config
Authorization: Bearer adm_SUA_CHAVE
X-Empresa-Id: t_ID_RETORNADO
Content-Type: application/json

{ ... dados completos do emitente, "ambiente": 2 ... }
```

O mesmo cabeçalho seleciona o contexto em `/certificado`, `/chaves`, `/nfse/config` e nas rotas fiscais. Uma credencial central sem `X-Empresa-Id` nessas rotas recebe 422; uma chave restrita tentando selecionar outra empresa recebe 403. Sessões continuam exigindo `X-CSRF-Token` nas mutações.

Crie uma chave restrita para cada sistema que só precisa de uma empresa. Se uma integração realmente administra várias empresas, use uma chave administrativa exclusiva para ela. O valor completo de cada chave aparece uma única vez. Revogação tem efeito na próxima requisição.

Dados fiscais continuam isolados por empresa e ambiente, inclusive quando o mesmo `operacao_id` do PDV ou número da DPS é utilizado em homologação e produção. O contexto é resolvido no servidor; o cabeçalho sozinho não concede permissão.

## Compatibilidade e migração

O primeiro acesso copia os registros fiscais do formato anterior para `tenants/{id}/ambientes/{1|2}/...`, preservando os arquivos originais. Configurações e metadados determinam o ambiente de origem. Conflitos de conteúdo interrompem a migração em vez de sobrescrever documentos.

Os usuários que eram proprietários de uma empresa não recebem acesso central por esse motivo. O parâmetro interno `CENTRAL_AUTH=false` existe apenas para fixtures de testes/compatibilidade e não deve ser usado na VPS. Os testes antigos usam criação interna de fixtures; a rota de autocadastro permanece inexistente.

Detalhes de banco externo e B2: [Supabase e Backblaze B2](SUPABASE-B2.md).

## Configuração geral do desenvolvedor

`GET/PUT /api/v1/admin/config` administra `responsavel_tecnico: {cnpj, contato, email, fone}`. Somente administradores ou chaves administrativas podem alterar. No painel, use Administração e arquivos → Responsável técnico do desenvolvedor. O registro fica na tabela `fiscal_api_settings`, separado das empresas, e é utilizado por todas as NF-e/NFC-e. Enviar `responsavel_tecnico` em `/config` da empresa retorna 422. `responsavel_tecnico: null` no endpoint global remove a configuração.

Na migração, valores antigos idênticos são consolidados automaticamente. Se houver responsáveis diferentes, a API não escolhe um arbitrariamente: revise e cadastre a configuração global.

Exemplo de ambientes mistos:

```json
{"ambiente_nfe":1,"ambiente_nfce":2,"ambiente_nfse":1}
```

Use esse corpo em `PUT /api/v1/empresas/{id}`. O campo antigo `ambiente` nesse endpoint é recusado para evitar alterar os três tipos sem intenção. Registros anteriores recebem inicialmente seus três ambientes a partir da configuração anterior; documentos não são renumerados.
