# API Fiscal — edição para ClickPDV

API de NF-e, NFC-e e NFS-e com administração central. Administradores e operadores autorizados gerenciam todas as empresas; não há autocadastro público. Integrações usam chaves por empresa ou credenciais administrativas. A impressão da NFC-e pode continuar no ACBr Delphi.

## Acesso

Dois perfis de instalação estão disponíveis: [local com PostgreSQL no Docker e online com Supabase/B2, HTTPS na porta 8080](docs/INSTALACAO-LOCAL-ONLINE.md). Para configurar o consumidor, consulte [URL e chave no Delphi](docs/DELPHI-CONFIGURACAO.md).

- Painel: [http://localhost:8081/](http://localhost:8081/).
- Documentação OpenAPI: [http://localhost:8081/docs](http://localhost:8081/docs).
- Saúde: [http://localhost:8081/api/v1/health](http://localhost:8081/api/v1/health).

O painel permite cadastrar a empresa, preencher endereço/regime/numeração/CSC/responsável técnico, cadastrar certificado A1, criar/revogar chaves de API, configurar o prestador NFS-e, alterar perfil/senha e administrar MFA. Não possui telas para emitir, cancelar ou reimprimir notas.

## Fluxos fiscais

- [JSON do Delphi, XML protocolado, contingência e recuperação após timeout](docs/PDV-NFCE.md).
- [Reforma Tributária calculada pelo Delphi](docs/RTC-INFORMADA-DELPHI.md).
- [Mapa completo de campos RTC](docs/RTC-CAMPOS-INFORMADOS.md).
- [API de NFS-e](docs/NFSE.md).
- [Cálculo de ICMS, IPI, PIS/COFINS, ISSQN e tributos aproximados](docs/TRIBUTOS-CALCULO.md): `POST /api/v1/tributos/calcular`.
- [Portal do Contador](docs/PORTAL-CONTADOR.md): acesso sem senha pelo atalho dos sistemas (`/portal`), regras fiscais e simulador.
- [Administração central, empresas e usuários permitidos](docs/ADMINISTRACAO-CENTRAL.md).
- [PostgreSQL/Supabase, migração e XML no Backblaze B2](docs/SUPABASE-B2.md).
- Exemplos completos na pasta `examples/`.

As APIs preservam validação, assinatura, transmissão, consulta, cancelamento, inutilização, CC-e para modelo 55 e XML. NFS-e mantém emissão, sincronização, cancelamento e DANFSe via API. O único trabalho automático do worker desta edição é a recuperação das operações NFC-e do PDV.

PDF auxiliar sob demanda: `GET /api/v1/nfe/{chave}/danfe`, com autenticação da empresa. Retorna DANFE A4 para NF-e ou DANFC-e de 80 mm para NFC-e, como `application/pdf`. A NFS-e mantém `GET /api/v1/nfse/{ref}/danfse`. Não existem telas de impressão no painel.

## Instalação local existente

A instalação atual usa `docker/compose.yml` desta pasta, `.env` e `vendor/` em `api-fiscal/`. O armazenamento operacional permanece em `../storage` e o Compose referencia explicitamente o volume existente `emissor-nfe_fiscal-db`. Não usa mais as montagens da raiz antiga. Consulte [recuperação e manutenção do Docker local](docs/DOCKER-LOCAL-RECUPERACAO.md).

No PowerShell, a partir de `D:\fontes\php\www\emissor\api-fiscal`:

```powershell
docker compose --env-file .env -f docker/compose.yml up -d
docker compose --env-file .env -f docker/compose.yml ps
docker compose --env-file .env -f docker/compose.yml logs --tail 50
```

Para uma nova instalação independente, use o [guia de perfis local e online](docs/INSTALACAO-LOCAL-ONLINE.md): `docker/compose.local.yml` lê `.env.local`; `docker/compose.online.yml` lê `.env.online`. O Compose `docker/compose.yml` atende a instalação existente e lê o `.env` desta pasta. Os perfis não sincronizam dados automaticamente. O painel usa HTML/CSS/JavaScript locais, sem Node, React ou etapa de compilação.

## Estrutura

```text
src/          autenticação, configuração e serviços fiscais
public/       front controller HTTP
resources/    painel administrativo, Swagger e schemas fiscais
bin/          instalação, recuperação administrativa e worker fiscal
docs/         integração e operação desta edição
examples/     JSON de referência
tests/        testes da edição com dados temporários e SEFAZ/Sefin simuladas
```

Na edição central, a persistência usa PostgreSQL (local no desenvolvimento ou externo no Supabase). O schema armazena documentos JSONB e binários; XML pode ser arquivado em bucket B2 privado. Preserve backup do banco, dos XML e do `.env`, necessário para decifrar os certificados. Os dados ficam separados por empresa e ambiente; chaves restritas permanecem vinculadas à sua empresa. Os três tipos começam em homologação; cada um possui seu próprio parâmetro de ambiente na empresa. Regras de ativação, transmissão NFS-e e contingência por UF estão documentadas nos contratos fiscais.

Esta edição não contém os módulos de marketplace, WooCommerce, MCP, contador, recorrência, lotes CSV, catálogo, marca própria, alertas comerciais, SMTP de notas ou webhooks. A recuperação de senha mantém o e-mail global do sistema ou o log local de desenvolvimento.

O projeto original permanece na pasta superior como referência. O código executado pelo Docker é o desta pasta.

## Administração inicial

Crie/promova o administrador com `php bin/administrador.php seu-email@empresa.com --gerar-senha` (a opção substitui a senha se o usuário já existir). A empresa é cadastrada no painel ou em `POST /api/v1/empresas`; configurações e emissão com credencial central exigem `X-Empresa-Id`. Consulte os guias acima antes de migrar ou publicar. O PostgreSQL do Docker é para desenvolvimento; o Compose externo não inicia banco na VPS.

Os serviços da NFS-e e todos os códigos/valores RTC são enviados pelo consumidor no JSON. Não há catálogo de serviços nem tabelas locais CST/cClassTrib. O responsável técnico do desenvolvedor é uma configuração geral em `/api/v1/admin/config`, com armazenamento próprio em `fiscal_api_settings`.
