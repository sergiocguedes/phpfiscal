# Docker local — instalação existente após reorganização

A pasta ativa é `D:\fontes\php\www\emissor\api-fiscal`. O Compose antigo da raiz não existe mais. Não use montagens para `emissor/vendor` ou `emissor/.env`: esses arquivos pertencem agora à edição ativa.

| Item | Localização |
|---|---|
| Compose atual | `api-fiscal/docker/compose.yml` |
| Configuração da API | `api-fiscal/.env` |
| Dependências | `api-fiscal/vendor/` |
| Código montado em /app | `api-fiscal/`, somente leitura |
| Armazenamento operacional | `emissor/storage/`, montado em /app/storage |
| PostgreSQL existente | volume externo `emissor-nfe_fiscal-db` |
| Nome do projeto Docker | `emissor-nfe`, preservado |
| API | `http://localhost:8081` |
| PostgreSQL no Windows/DBeaver | `127.0.0.1:5433` |

O volume externo é obrigatório para esta instalação: não cria um banco vazio com outro nome. Para uma instalação nova, use `compose.local.yml` e seu perfil independente. Não use `down -v` para manutenção.

## Dependências e operação

Execute dentro de `api-fiscal/`:

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install --no-interaction --prefer-dist --no-scripts --ignore-platform-reqs
docker compose --env-file .env -f docker/compose.yml config --quiet
docker compose --env-file .env -f docker/compose.yml up -d
docker compose --env-file .env -f docker/compose.yml ps
```

O instalador usa as versões travadas no lock. `--no-scripts` evita executar o instalador antigo, que pode criar diretórios e segredos. A opção de plataforma é necessária somente para baixar no container Composer; a execução e a validação de extensões são feitas no PHP da API. Não use `composer update` para corrigir simplesmente a falta de vendor.

Nesta recuperação, o `.env` vazio foi restaurado da configuração existente na pasta de referência `D:\fontes\php\www\emissor e api wp`. Foram preservados a chave de criptografia de certificados, as credenciais e o namespace `fiscal`; nenhuma chave foi gerada para substituir as existentes. A emissão de NFS-e segue o ambiente fiscal configurado na empresa, sem chave global de habilitação. `.env.online` não foi usado para ligar a instalação local ao Supabase.

## Testes sem transmitir

```powershell
docker compose --env-file .env -f docker/compose.yml exec -e FISCAL_TEST_PG=1 web php vendor/bin/phpunit
docker compose --env-file .env -f docker/compose.yml exec web php integracoes/madbuilder/tests/contrato.php
```

A suíte utiliza namespace `tests_<pid>` separado no PostgreSQL e transportes SEFAZ/Sefin simulados. Não execute os scripts de transmissão/cancelamento para verificar dependências. Saúde da API: `GET /api/v1/health`; o Swagger está em `/docs`.

O retorno da nota 60 deve continuar como cancelado. Certificados e arquivos fiscais são conferidos apenas por leitura; não é necessário emitir uma nova nota para validar a recuperação.

## Resultado da recuperação

Dependências instaladas: 35 pacotes, incluindo PHPUnit 10.5.66, com as versões do lock preservadas. O hash de conteúdo do lock foi sincronizado com composer.json sem atualizar pacotes. O runtime PHP 8.3.36 foi conferido com DOM, GD, ZIP, mbstring, bcmath, SOAP, cURL, pdo_pgsql e OpenSSL disponíveis.

Web saudável, worker ativo e PostgreSQL existente preservado. A1 decifrado com a chave anterior; API acessível pelo MadBuilder com HTTP 200. NFS-e 360 continua cancelada em ambiente 2.

Suíte PostgreSQL isolada: **81 testes / 1.477 verificações**. Adaptador MadBuilder: **41 verificações**. Transmissão NFS-e desligada durante toda a recuperação; nenhuma nova nota ou evento enviado.
