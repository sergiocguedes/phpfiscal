# Instalação local e online

A impressão de NFS-e usa o modelo DANFSe v2.0 gerado na API e pode retornar Base64. Nos dois perfis, prepare também as fontes privadas conforme [DANFSE-NACIONAL.md](DANFSE-NACIONAL.md).

> Após a reorganização, a instalação atual usa `docker/compose.yml` e `.env` dentro de `api-fiscal/`. Consulte [Docker local — recuperação e comandos atuais](DOCKER-LOCAL-RECUPERACAO.md). As referências ao Compose da raiz descrevem a estrutura anterior.

| Perfil | Endereço da API | Banco | XML |
|---|---|---|---|
| Local | `http://localhost:8081/api/v1` | PostgreSQL no Docker, no PC do PDV | Backend local, no volume PostgreSQL do PC, sem B2 |
| Online | `https://SEU-DOMINIO:8080/api/v1` | Supabase externo, com TLS | Backblaze B2 privado; payload pendente no banco até arquivamento confirmado |

O painel fica na raiz do endereço e o Swagger em `/docs`. O perfil online publica **HTTPS na porta 8080**. Os nomes das instalações e dos volumes são diferentes; não há sincronização automática entre elas. Cada uma tem seus usuários, empresas, certificados e chaves.

## Antes de instalar

### Qual Compose e configuração usar

| Instalação | Pasta de onde executar | Compose | Configuração lida pela aplicação | Serviços |
|---|---|---|---|---|
| Local existente neste PC | `D:\fontes\php\www\emissor\api-fiscal` | `docker/compose.yml` | `.env` da edição ativa | web, worker, PostgreSQL existente |
| Nova instalação local | pasta `api-fiscal/` | `docker/compose.local.yml` | `api-fiscal/.env.local` | web, worker, PostgreSQL |
| Online na VPS | pasta `api-fiscal/` | `docker/compose.online.yml` | `api-fiscal/.env.online` | preparação do banco, PHP-FPM, Nginx HTTPS, worker |

O Docker lê o arquivo montado pelo Compose; editar `.env.online` não muda a instalação local atual. O parâmetro `--env-file .env.local` também fornece a senha para o serviço PostgreSQL do perfil local. O Compose online monta explicitamente `.env.online`, sem precisar de `--env-file` para essa montagem. Depois de alterar uma configuração, reinicie os serviços da instalação correspondente.

| Configuração | Local | Online |
|---|---|---|
| `STORAGE_DRIVER` | `pgsql` | `pgsql` |
| `DATABASE_DSN` | host `db`, porta 5432, banco `fiscal`, rede privada Docker | host do Supabase Session pooler, porta 5432, banco `postgres`, `sslmode=verify-full` e CA |
| `DATABASE_USER` | `fiscal` | usuário exibido pelo Supabase, normalmente `postgres.<projeto>` |
| `DATABASE_PASSWORD` | senha do PostgreSQL local | senha do banco Supabase |
| `DATABASE_CONNECTION_MODE` | `direct` | `session` ao usar Session pooler |
| `DATABASE_REQUIRE_TLS` | `false`, banco na rede privada Docker | `true` |
| `DATABASE_SCHEMA` / `STORAGE_NAMESPACE` | valores do perfil local | valores próprios do perfil online; devem permanecer estáveis |
| `XML_STORAGE` | `local`: XML persistido no backend PostgreSQL local | `s3`: Backblaze B2 privado, com pendências preservadas no banco |
| `S3_ACCESS_KEY_ID` / `S3_SECRET_ACCESS_KEY` | não utilizados | keyID e applicationKey do B2, somente no servidor |
| `APP_URL` | `http://localhost:8081` | `https://SEU-DOMINIO:8080` |
| `SESSION_COOKIE_SECURE` | `false` para HTTP local | `true` para HTTPS |
| Certificados de infraestrutura | não exigidos para HTTP local | CA Supabase e certificado/chave HTTPS do domínio |

O certificado fiscal A1 da empresa é distinto dos certificados de infraestrutura. Cadastre o A1 pelo painel/API em qualquer perfil. O ambiente fiscal também é independente da instalação: `ambiente_nfe`, `ambiente_nfce` e `ambiente_nfse` pertencem à empresa (1 produção, 2 homologação). Estar online não ativa produção automaticamente.

### Operação e integração Delphi

No Delphi, altere a URL base para `http://localhost:8081/api/v1` ou `https://SEU-DOMINIO:8080/api/v1` e use uma chave da empresa criada na instalação de destino. As credenciais de Supabase/B2 ficam na API. Consulte [configuração do Delphi](DELPHI-CONFIGURACAO.md).

Local e online não sincronizam automaticamente empresas, notas, números ou usuários. Trocar a URL não transfere dados. Para migrar, use backup/importação e preserve `CERT_ENCRYPTION_KEY` para decifrar os certificados existentes. Coordene a numeração antes de emitir pela nova instalação. O perfil local continua dependendo da internet para autorização fiscal; a contingência NFC-e segue o fluxo próprio documentado.

Para atualizar uma instalação existente, preserve `.env`, banco, volumes e certificados; atualize o código e recrie os serviços com o mesmo Compose. Não gere novas chaves de criptografia sobre uma instalação com dados. Nunca use `down -v` para uma atualização.

### Testes do perfil online sem publicar a API

Na pasta `api-fiscal/`, com `vendor/` instalado:

```powershell
docker compose -f docker/compose.online.yml run --rm --no-deps preparar php bin/banco.php
docker compose -f docker/compose.online.yml run --rm --no-deps preparar php bin/testar-s3.php
```

O primeiro comando cria/atualiza a estrutura no banco configurado; não importa os dados locais. O segundo grava um XML sem dados fiscais, baixa, compara SHA-256 e remove somente esse objeto. Esses comandos não iniciam o painel online nem trocam a instalação local. Não é necessário liberar a porta 8080 apenas para esses testes.

As dependências ficam em `api-fiscal/vendor/`, junto do código e do composer.lock. Os perfis não precisam mais montar o vendor da raiz antiga.

Na validação realizada em 02/11/2026, o Session pooler Supabase conectou com `verify-full` e o B2 passou no teste de upload/download/remoção. Isso valida as conexões; a publicação na VPS e a migração dos dados são etapas separadas. Não distribua `.env.online` preenchido junto com o código público.

Distribua a pasta `api-fiscal/` inteira, com `composer.lock`. Instale Docker/Compose. As dependências PHP devem existir em `vendor/`; use `composer install --no-dev --prefer-dist` com PHP compatível e as extensões exigidas. Se necessário, use a própria imagem PHP para executar o Composer, conforme abaixo. Não é necessário Node para o painel.

Os comandos deste guia são executados **dentro de `api-fiscal/`**, salvo onde indicado. Os arquivos `.env.local` e `.env.online` são completos e independentes; os `.example` são modelos complementares. `bin/preparar-perfil.php` combina o modelo geral com o perfil e gera os segredos de uma instalação nova, sem sobrescrever arquivos existentes.

Para construir o PHP local e preparar o perfil sem instalar PHP no Windows:

```powershell
docker build --target fiscal-cli -t emissor-fiscal-local:3.1 docker
docker run --rm -v "${PWD}:/app" emissor-fiscal-local:3.1 php bin/preparar-perfil.php local
```

Se já houver PHP instalado, basta `php bin/preparar-perfil.php local`. Para preparar o perfil online, troque `local` por `online`. Em Linux, para criar o arquivo com o usuário do host, use `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" emissor-fiscal-local:3.1 php bin/preparar-perfil.php online`.

Para instalar dependências via Docker, com a imagem acima já construída:

```powershell
docker run --rm -v "${PWD}:/app" composer:2 install --no-dev --prefer-dist --ignore-platform-reqs --no-scripts
```

O Composer apenas baixa as dependências travadas; a execução e os testes são feitos com o PHP da imagem da API. Em Linux, corrija a propriedade dos diretórios criados por esse container antes de iniciar a aplicação. Não execute `bin/install.php` esperando que ele escolha um perfil: o instalador antigo prepara somente `.env`.

## Local no computador Windows do PDV

1. Crie `.env.local` com o comando acima. A senha do PostgreSQL é gerada automaticamente.
2. Crie `storage-local` na pasta do projeto. No Docker Desktop, permita acesso à pasta do projeto.
3. Inicie:

```powershell
docker compose --env-file .env.local -f docker/compose.local.yml up -d --build
docker compose --env-file .env.local -f docker/compose.local.yml ps
docker compose --env-file .env.local -f docker/compose.local.yml exec web php bin/administrador.php seu-email@empresa.com --gerar-senha
```

4. Acesse `http://localhost:8081/`, entre com o administrador e cadastre a empresa.
5. Complete configuração fiscal, certificado, CSC e prestador NFS-e quando utilizado. Os dados de homologação e produção são separados.
6. Em **Chaves da empresa**, crie uma chave exclusiva para o PDV e configure-a no Delphi.

O PostgreSQL não publica porta no Windows; a API é publicada somente em `127.0.0.1:8081`. Os XML deste perfil ficam no backend PostgreSQL local, junto do armazenamento persistente do computador; não são enviados ao Backblaze. Faça backup do banco e do segredo `CERT_ENCRYPTION_KEY`.

Para parar sem apagar o banco:

```powershell
docker compose --env-file .env.local -f docker/compose.local.yml stop
```

Não use `down -v`: isso remove o volume do banco. Atualizar/recriar containers preserva o volume.

### Instalação que já está funcionando neste computador

A instalação existente usa `api-fiscal/docker/compose.yml`, o `.env` desta edição e o volume externo `emissor-nfe_fiscal-db`. O armazenamento operacional permanece em `emissor/storage`. Não inicie outro perfil na mesma porta.

Para continuar operando a instalação existente, dentro de `emissor/api-fiscal/`:

```powershell
docker compose --env-file .env -f docker/compose.yml up -d
```

Se quiser transferi-la para o perfil nomeado, pare web/worker, exporte o banco e importe no novo perfil conforme [Supabase e B2](SUPABASE-B2.md). Preserve `CERT_ENCRYPTION_KEY` e use o namespace de destino escolhido. Não substitua o `.env` da instalação existente por um perfil recém-gerado.

## Online na VPS Linux — porta 8080

O perfil usa Nginx com TLS e PHP-FPM, além do worker. **Não inicia PostgreSQL na VPS e não publica a porta 9000 do FPM.**

1. Copie o projeto e instale as dependências.
2. Gere `.env.online` e preencha `DATABASE_PASSWORD`, `S3_ACCESS_KEY_ID` e `S3_SECRET_ACCESS_KEY`.
3. Configure `APP_URL=https://SEU-DOMINIO:8080`. O DNS deve apontar para a VPS, e o firewall deve liberar TCP 8080.
4. Salve o certificado CA do Supabase em `secrets/supabase-ca.crt`.
5. Salve o certificado HTTPS válido para seu domínio em `secrets/tls/fullchain.pem` e a chave privada em `secrets/tls/privkey.pem`. Providencie emissão e renovação desse certificado fora do Compose. O CA do Supabase e o certificado HTTPS da API são arquivos distintos.
6. Crie os diretórios e permissões no Linux:

```bash
mkdir -p storage-online secrets/tls
sudo chown -R 10096:10096 storage-online
sudo chmod 700 storage-online
sudo chown 101:101 secrets/tls/fullchain.pem secrets/tls/privkey.pem
sudo chmod 600 secrets/tls/privkey.pem
sudo chmod 644 secrets/tls/fullchain.pem secrets/supabase-ca.crt
sudo chmod 755 secrets secrets/tls
sudo chown 10096:10096 .env.online
chmod 600 .env.online
```

O diretório `secrets` deve permitir travessia aos usuários dos containers; não deixe suas pastas com modo 700 pertencendo a outro UID. A chave privada continua acessível somente ao UID 101 do Nginx. O código e as configurações são montados somente para leitura; apenas `storage-online` é gravável pela API.

7. Construa, crie o schema e teste o B2 antes de publicar:

```bash
docker compose -f docker/compose.online.yml build php
docker compose -f docker/compose.online.yml run --rm --no-deps preparar php bin/banco.php
docker compose -f docker/compose.online.yml run --rm --no-deps preparar php bin/testar-s3.php
```

8. Se esta for uma migração, importe o backup **antes de iniciar web/worker** e preserve a chave de criptografia original. Em instalação nova, crie o administrador:

```bash
docker compose -f docker/compose.online.yml run --rm --no-deps preparar php bin/administrador.php seu-email@empresa.com --gerar-senha
docker compose -f docker/compose.online.yml up -d
docker compose -f docker/compose.online.yml ps
```

9. Verifique `https://SEU-DOMINIO:8080/api/v1/health`, o painel e `/docs`. Cadastre uma empresa de homologação e uma chave restrita para cada aplicação que a usará.

O modelo online está preenchido com o host Supabase informado. A conexão direta pode exigir IPv6 na VPS; para rede IPv4, obtenha o **Session pooler** no painel Supabase, alterando host/usuário e `DATABASE_CONNECTION_MODE=session`. Não use o pooler de transação, porta 6543.

O modelo B2 usa o bucket `emissor`, endpoint `https://s3.us-east-005.backblazeb2.com` e região `us-east-005`. As credenciais não ficam no Delphi. O worker só libera a cópia pendente após conferir o XML remoto.

O arquivo `.env.supabase` já preparado na raiz pode servir de origem dos segredos/configuração durante uma migração. Revise URL com `:8080`, `XML_STORAGE=s3`, namespace e conexão; não copie um exemplo sobre ele. O Compose online lê especificamente `api-fiscal/.env.online`.

O envio NFS-e usa `ambiente_nfse` da empresa: 1 produção, 2 homologação. Não há configuração global de habilitação de transporte. Para validar sem transmitir, use `dry_run=1`.

## URL e chave no Delphi

Consulte [Integração Delphi](DELPHI-CONFIGURACAO.md) e os modelos em `examples/delphi/`. Uma chave criada localmente não autentica no servidor online. Configure explicitamente qual instalação emite para cada empresa/série; não alterne automaticamente de servidor após timeout.

## Atualizações

Atualize o código, instale dependências conforme o lock e execute novamente `up -d --build` com o Compose do perfil correto. Faça backup antes e preserve arquivos `.env.*`, `CERT_ENCRYPTION_KEY`, volumes do banco, certificados e bucket. Homologação fiscal por empresa não substitui um ambiente separado para testar mudanças no software.
