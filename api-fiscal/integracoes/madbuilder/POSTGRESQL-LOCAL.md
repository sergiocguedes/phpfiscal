# PostgreSQL local compartilhado: API e MadBuilder

> HISTÓRICO: esta configuração do MadBuilder foi substituída após a identificação do banco correto. O projeto agora usa os dumps MariaDB restaurados em `emite` e `log_emite` no MySQL local. Consulte [MYSQL-LOCAL.md](MYSQL-LOCAL.md). Não reaplique as configurações deste documento ao emissor de serviços. O PostgreSQL continua sendo o banco da API Fiscal.

Configurado em 02/11/2026. Existe um único servidor PostgreSQL 17, no container `emissor-nfe-db-1`, com dois bancos independentes:

| Aplicação | Banco | Usuário |
|---|---|---|
| API Fiscal | `fiscal` | `fiscal` |
| MadBuilder emissor de serviços | `emissor_servicos` | `emissor_servicos_app` |

O banco novo tem 89 tabelas, estrutura e dados iniciais dos scripts do projeto. Não é uma cópia do banco de produção. A empresa e os usuários iniciais vieram desses inserts; o cadastro fiscal real e os usuários devem ser revisados antes dos testes de emissão. Nenhuma nota foi transmitida durante a instalação.

## Acesso pelo MadBuilder no Docker

```text
host: emissor-nfe-db-1
porta: 5432
banco: emissor_servicos
usuário: emissor_servicos_app
```

A senha aleatória exclusiva está no campo `pass` dos arquivos locais abaixo; não é reproduzida neste documento:

```text
D:\fontes\php\www\emissor_servicos\app\config\emissor.php
D:\fontes\php\www\emissor_servicos\app\config\permission.php
D:\fontes\php\www\emissor_servicos\app\config\communication.php
D:\fontes\php\www\emissor_servicos\app\config\log.php
```

As quatro conexões lógicas usam o mesmo banco porque o schema principal já contém as tabelas de negócio, permissões e comunicação. O script de logs acrescenta as tabelas de auditoria. A conexão `log` não registra seu próprio SQL para evitar recursão. Os quatro arquivos foram incluídos no `.gitignore`; suas versões preenchidas são segredos locais.

O usuário é proprietário somente dos objetos do banco do MadBuilder e não tem `SUPERUSER`, `CREATEDB` ou `CREATEROLE`. Não use a senha do usuário fiscal no sistema consumidor. Para o DBeaver no Windows, a instalação atual publica `127.0.0.1:5433` para a porta 5432 do PostgreSQL. A publicação está no Compose da API e aceita somente conexões do próprio PC; entre containers, continue usando `emissor-nfe-db-1:5432`. No DBeaver, escolha PostgreSQL, host `127.0.0.1`, porta `5433`, banco `emissor_servicos`, usuário `emissor_servicos_app` e a senha do campo `pass` em `app/config/emissor.php`. SSL não é exigido nessa conexão local. Para consultar o banco fiscal, use banco `fiscal` e as credenciais do `.env` da API.

## Rede e Compose persistente

O container `madbuilder-local-web-1` está conectado a duas redes:

- `madbuilder-local_default`, mantida para os outros projetos/MySQL existentes;
- `emissor-nfe_default`, para acessar o PostgreSQL local da API.

O Compose original do MadBuilder foi atualizado, preservando seus serviços, portas, volumes e a dependência MySQL:

```text
C:\Users\sergi\Documents\Codex\2026-09-08\tenh\outputs\docker-php\compose.yaml
```

A declaração acrescentada é equivalente a:

```yaml
services:
  web:
    networks: [default, fiscal_postgres]
networks:
  fiscal_postgres:
    external: true
    name: emissor-nfe_default
```

Isso mantém a conexão quando o MadBuilder for recriado pelo Compose. A rede externa precisa existir: inicie a instalação atual da API primeiro. Para a rede entre containers não é necessário publicar portas. A publicação adicional `127.0.0.1:5433:5432` atende apenas ao DBeaver local.

No PowerShell:

```powershell
# 1. API local existente e PostgreSQL
Set-Location D:\fontes\php\www\emissor
docker compose --env-file .env -f docker/docker-compose.yml up -d

# 2. MadBuilder com a rede persistente
Set-Location C:\Users\sergi\Documents\Codex\2026-09-08\tenh\outputs\docker-php
docker compose up -d
```

Se renomear o projeto Compose da API ou migrar para o perfil `emissor-local`, atualize o nome da rede externa e o hostname PostgreSQL nos arquivos do MadBuilder. O perfil novo usa outro volume: ele não contém automaticamente os bancos desta instalação.

O banco do MadBuilder reside no mesmo volume PostgreSQL da instalação API. Parar o PostgreSQL afeta os dois sistemas; `down -v` pode apagar os dois bancos. Atualizar/recriar containers com o volume preservado mantém os dados.

## Estrutura importada e ajustes

Origem: `emissor_servicos/app/database/`:

1. `emissor-pgsql.sql`;
2. `log.sql`;
3. `emissor-inserts.sql`;
4. `emissor-pgsql-adjust-sequences.sql`.

A carga foi feita em transação única. O exportador reutiliza o nome `fk_account_2` para duas relações da mesma tabela; na importação, os nomes repetidos receberam sufixos para preservar ambas. As constraints foram aplicadas depois dos inserts para permitir as referências circulares dos dados iniciais. Os arquivos exportados originais não foram sobrescritos.

Não execute essa carga de inicialização novamente sobre um banco com dados. Para novas instalações, use uma migração revisada ou restaure o backup, preservando as sequências.

Os arquivos anteriores de configuração e o Compose original estão em:

```text
D:\fontes\php\www\emissor\.migration-backups\madbuilder-postgres-20261102-195711
```

## Backup e restauração

O backup funcional da API (`bin/backup-banco.php`) não exporta as tabelas de negócio do MadBuilder. Faça backup independente deste banco. Exemplo, sem colocar senha no comando:

```powershell
docker exec emissor-nfe-db-1 pg_dump -U fiscal -d emissor_servicos -Fc -f /tmp/emissor-servicos.backup
docker cp emissor-nfe-db-1:/tmp/emissor-servicos.backup D:/backups/emissor-servicos.backup
docker exec emissor-nfe-db-1 rm /tmp/emissor-servicos.backup
```

Crie antes a pasta de destino. Para restaurar, prepare um banco vazio de destino cujo proprietário seja `emissor_servicos_app`, copie o arquivo para o container e utilize `pg_restore --no-owner --role=emissor_servicos_app -U fiscal -d NOME_DO_BANCO /tmp/emissor-servicos.backup`. Não use `--clean` sobre o banco em uso. Guarde também os arquivos de configuração e a chave de integração em local protegido.

## Validação realizada

- Conexão pelo PHP/Adianti nas quatro conexões: OK.
- 89 tabelas pertencentes a `emissor_servicos_app` e leitura dos dados iniciais: OK.
- Usuário sem privilégios administrativos: OK.
- Compose atualizado validado: OK.
- `http://localhost:8080/emissor_servicos/`: HTTP 200.
- Saúde da API `http://localhost:8081/api/v1/health`: OK.

Para a integração fiscal, consulte [API-FISCAL-PHP.md](API-FISCAL-PHP.md) no projeto consumidor. A instalação inicial do banco não preenche automaticamente o cadastro fiscal da empresa na API nem importa o histórico da API C#.
