# Emissor de serviços — bancos restaurados MariaDB/MySQL

Esta é a configuração vigente desde 02/11/2026. Substitui a configuração PostgreSQL anteriormente preparada para o MadBuilder. A API Fiscal continua usando seu próprio PostgreSQL.

## Bancos e conexões

O servidor já disponível no Docker é **MySQL 8.4**, container `madbuilder-local-db-1`. Os arquivos fornecidos são dumps MariaDB 10.11; ambos foram restaurados e as conexões PHP/Adianti foram validadas.

| Conexão do projeto | Banco restaurado | Uso |
|---|---|---|
| emissor | emite | Dados do sistema |
| permission | emite | Usuários e permissões |
| communication | emite | Mensagens e notificações |
| log | log_emite | Auditoria |

Parâmetros dentro do container PHP:

```text
host: madbuilder-local-db-1
porta: 3306
tipo Adianti: mysql
usuário: emissor_servicos_app
charset da conexão: utf8mb4
```

A senha gerada está no campo `pass` dos arquivos `app/config/emissor.php`, `permission.php`, `communication.php` e `log.php` do projeto `D:\fontes\php\www\emissor_servicos`. Não foi copiada para a documentação. Esses arquivos estão no `.gitignore`.

O usuário tem somente SELECT, INSERT, UPDATE e DELETE nos dois bancos e autentica a partir do IP atual do PHP, `172.18.0.3`. Não possui acesso administrativo ou permissão para alterar estruturas. Se recriar a rede/container e o IP mudar, atualize a restrição de host do usuário no MySQL usando a administração local; não substitua por `%` indiscriminadamente. Para migrações de schema, use uma credencial administrativa separada.

Para ferramentas no Windows, o servidor já publica **127.0.0.1:3307**. No DBeaver, use o driver MySQL e o banco `emite` ou `log_emite`. A conta da aplicação é restrita ao IP do container PHP; não funcionará automaticamente a partir do Windows. Para o DBeaver, use uma conta administrativa local existente ou prepare uma conta específica limitada ao endereço de origem do Windows.

## Restauração realizada

Fontes preservadas:

```text
D:\fontes\php\www\dump-emite-202611022018.sql
D:\fontes\php\www\dump-log_emite-202611022024.sql
```

O banco `emite` anterior foi salvo em backup e substituído pelo dump. O segundo dump foi restaurado em `log_emite`. O banco preexistente `emite_log` não foi substituído e não é usado por estas conexões.

Foram removidos somente das cópias de importação o comando inicial de sandbox específico do cliente MariaDB e o DEFINER da view referente ao servidor antigo. A view usa `SQL SECURITY INVOKER`. As fontes originais permanecem intactas. Não foi aplicada conversão dos dados para PostgreSQL.

Resultado: 85 objetos de tabela/view em `emite`, 5 tabelas em `log_emite`, 4 empresas e 59 notas. O usuário e os registros de negócio vieram dos dumps; não foram substituídos por dados de demonstração. Nenhum usuário do sistema teve a senha redefinida.

Backup anterior e configurações antigas:

```text
D:\fontes\php\www\emissor\.migration-backups\madbuilder-mysql-20261102-203006\antes-emissor.sql
```

Essa pasta contém também as cópias de configuração anteriores e os SQL ajustados para a restauração. Proteja-a como dados privados.

## Docker e operação

O MySQL e o PHP usam a rede `madbuilder-local_default`, já declarada no Compose original:

```text
C:\Users\sergi\Documents\Codex\2026-09-08\tenh\outputs\docker-php\compose.yaml
```

```powershell
Set-Location C:\Users\sergi\Documents\Codex\2026-09-08\tenh\outputs\docker-php
docker compose up -d
```

O volume `mysql_data` mantém os bancos ao recriar os containers. Não use `down -v` para atualizar. O PostgreSQL da API não participa das conexões de negócio do MadBuilder. A rede adicional `emissor-nfe_default` foi retirada do PHP/Compose MadBuilder; o HTTP para a API continua usando `http://host.docker.internal:8081/api/v1`.

O banco PostgreSQL `emissor_servicos`, criado durante a configuração anterior, foi preservado como teste antigo e não é usado pelo projeto. Não o confunda com os dados restaurados no MySQL.

Validações realizadas: quatro conexões Adianti, leitura de empresas/notas, permissões de UPDATE em transações revertidas, HTTP 200 no MadBuilder e saúde da API Fiscal. Nenhuma transmissão fiscal foi realizada.

Para backup futuro, use `mysqldump --single-transaction` ou a ferramenta de backup do DBeaver com credencial apropriada e inclua **emite e log_emite**. Os scripts de backup PostgreSQL da API não exportam esses bancos.
