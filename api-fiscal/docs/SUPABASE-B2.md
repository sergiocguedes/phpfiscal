# Banco externo Supabase e XML no Backblaze B2

## Distribuição

```text
PDV Delphi / outros sistemas
              |
            HTTPS
              |
         API PHP na VPS
          /          \
Supabase PostgreSQL   Backblaze B2 privado
configurações,        XML assinado, autorizado
operações e fila     e retornos/eventos
```

Supabase fornece PostgreSQL gerenciado; a API usa PDO PostgreSQL e SQL, sem depender da Data API REST, Supabase Auth ou Supabase Storage. O login administrativo permanece na própria API. Separar o banco da VPS reduz a concentração dos dados, mas exige proteção das credenciais, TLS e backup.

No desenvolvimento local, `docker/compose.yml` inicia PostgreSQL 17 em volume persistente e sem publicar sua porta. Para banco externo, `docker/compose.externo.yml` inicia somente web/worker. O servidor HTTP desses Compose continua sendo o ambiente interno de desenvolvimento; antes de exposição pública, preparar PHP-FPM/Nginx e HTTPS. Não publicar o servidor embutido do PHP diretamente na internet.

## Configurar Supabase

1. Crie um projeto e obtenha a conexão em **Connect** no painel Supabase.
2. Use conexão direta para um host com conectividade compatível ou **Session pooler**, normalmente porta 5432, para VPS IPv4. Não use Transaction pooler/porta 6543: a API utiliza locks de sessão e prepared statements. O driver recusa esse modo.
3. Configure `.env` conforme `.env.supabase.example`. Os valores de host e usuário devem ser copiados do projeto. A senha é a do banco, não a chave `anon` ou `service_role`.
4. Habilite TLS. O exemplo usa `sslmode=verify-full` e o certificado CA baixado do painel Supabase em `/app/secrets/supabase-ca.crt`. O diretório `secrets/` fica fora do Git. Para conexão direta, ajuste host, usuário e `DATABASE_CONNECTION_MODE=direct`.
5. Use `DATABASE_SCHEMA=fiscal_private`. A identidade do backend deve ser proprietária do schema/tabelas; não conceda acesso a `anon`/`authenticated` nem exponha esse schema na Data API. As tabelas têm RLS habilitado e nenhuma política pública. Um usuário comum sem propriedade ou política explícita não terá acesso.
6. Execute `php bin/banco.php` com a conexão configurada para criar o schema/tabelas. A inicialização do web também aplica o schema idempotente.

Referência oficial: [conexões PostgreSQL do Supabase](https://supabase.com/docs/guides/database/connecting-to-postgres).

## Persistência e locks

`fiscal_documents` armazena os registros existentes como documentos JSONB, com chave primária composta por namespace e caminho lógico. `fiscal_blobs` armazena certificados já cifrados, arquivos locais e payloads pendentes. `fiscal_api_settings` guarda a configuração geral do desenvolvedor. Esta migração não transforma cada campo fiscal em uma coluna relacional; preserva o contrato e os registros existentes.

Listagem, leitura, atualização, remoção e coleta de sessões/idempotência passam pelo backend escolhido. Locks PostgreSQL substituem `flock` nas atualizações dos registros e reservas de numeração. O backend não faz fallback silencioso para arquivos se o banco falhar.

`DATABASE_NAMESPACE` não é utilizado: a variável correta é `STORAGE_NAMESPACE`. Use nomes diferentes para bancos lógicos de teste e instalação. O ambiente fiscal é uma divisão adicional dentro de cada empresa, e não um namespace de banco separado.

## Migrar arquivos locais para PostgreSQL

Faça backup e pare web/worker antes de copiar registros:

```bash
# Na raiz api-fiscal/, para instalação independente com banco local:
docker compose --env-file .env -f docker/compose.yml up -d db
docker compose --env-file .env -f docker/compose.yml stop web worker
docker compose --env-file .env -f docker/compose.yml run --rm --no-deps web php bin/migrar-postgres.php --confirmar-parada
```

O importador usa uma transação, compara os conteúdos e preserva os originais. Conflitos interrompem toda a importação. Configure `STORAGE_DRIVER=pgsql` e reinicie os serviços após o sucesso. Em instalação nova, sem dados legados, basta criar o schema.

Na instalação Windows atual, o Compose fica na pasta superior: use `docker compose --env-file .env -f docker/docker-compose.yml ...` a partir da raiz `emissor/`. Ele monta o `storage` e `.env` da raiz em `/app`.

## Transferir PostgreSQL local para Supabase

Não use os arquivos antigos como origem depois que a API já estiver gravando no PostgreSQL: eles não incluem os novos usuários e operações. Para transferir:

1. Pare web e worker. Preserve o `.env`, principalmente `CERT_ENCRYPTION_KEY`.
2. Com a conexão local, exporte: `php bin/backup-banco.php exportar /caminho/novo-backup.jsonl`.
3. Configure a conexão Supabase e crie o schema: `php bin/banco.php`.
4. Importe: `php bin/backup-banco.php importar /caminho/novo-backup.jsonl`.
5. Inicie a API, confira login, empresas, XML e worker antes de liberar clientes.

O backup contém hashes de senha, configurações e certificados cifrados; trate-o como segredo. A exportação usa snapshot consistente. A importação confere contagem e SHA-256 e recusa truncamento ou conteúdo diferente no destino; é uma transação. XML já arquivado no B2 continua referenciado pelo banco e deve permanecer no bucket.

Se a migração falhar antes de liberar clientes, restaure a conexão anterior e reinicie. Depois de novas gravações no destino, voltar exige conciliar/exportar esses novos dados; trocar o driver para arquivos antigos causaria perda lógica.

## Backblaze B2

Configuração conhecida desta instalação:

```dotenv
S3_ENDPOINT=https://s3.us-east-005.backblazeb2.com
S3_REGION=us-east-005
S3_BUCKET=emissor
S3_PREFIX=fiscal
S3_SSE=true
S3_TIMEOUT=20
```

Crie uma Application Key restrita ao bucket privado, com leitura e escrita necessárias. Configure `S3_ACCESS_KEY_ID` e `S3_SECRET_ACCESS_KEY` apenas no `.env` do servidor. Não use a master key. O cliente utiliza HTTPS e assinatura AWS V4 do libcurl, e solicita criptografia SSE para os uploads quando `S3_SSE=true`.

Depois das credenciais, ative `XML_STORAGE=s3`, reinicie os serviços e faça uma verificação de upload/download. Sem credenciais, mantenha `XML_STORAGE=local`: o backend PostgreSQL continua preservando os XML. Nunca ative S3 com configurações incompletas esperando uma cópia remota.

O comando `php bin/testar-s3.php` envia um XML técnico sem dados fiscais, baixa e confere o hash e remove o objeto de teste por nome. Ele não transmite notas à Sefaz/Sefin. Em buckets versionados podem permanecer versões históricas do objeto técnico. Se a chave de produção não tiver permissão de exclusão, o comando informará falha ao remover; o arquivamento fiscal precisa somente de leitura/escrita.

Referências: [S3 compatível do B2](https://www.backblaze.com/apidocs/introduction-to-the-s3-compatible-api) e [assinatura V4 no libcurl](https://curl.se/libcurl/c/CURLOPT_AWS_SIGV4.html).

## Arquivamento independente da emissão

1. Ao salvar XML, grave payload e referência duráveis no banco, antes da transmissão fiscal quando aplicável. A gravação não depende de rede S3.
2. O worker envia o objeto ao B2 com uma chave que inclui empresa, ambiente, caminho e SHA-256. Mudanças de conteúdo produzem outra chave; não sobrescrevem a versão anterior no bucket.
3. O worker baixa o objeto para conferir SHA-256. Só depois registra `arquivado` e remove o payload pendente/cópia original do backend.
4. Em erro de upload ou verificação, mantém a cópia durável, registra tentativa e aguarda backoff. Não altera o resultado da autorização e não retransmite a nota por causa do B2.
5. As rotas XML e PDF continuam autenticadas e buscam o XML pendente ou remoto. Downloads remotos também conferem SHA-256. Os clientes não recebem as credenciais B2.

As operações novas do PDV armazenam referências aos XML em vez de XML inline no JSON. A fila possui `estado`, `tentativas`, `proxima_tentativa`, hash e chave do objeto. O painel administrativo mostra quantos estão arquivados, pendentes ou com falha. PDF pode ser regenerado a partir do XML; o cache de DANFSe permanece no backend nesta etapa.

Para enfileirar XML históricos e extrair XML inline de operações antigas, execute:

```bash
php bin/arquivar-xml.php
```

A rotina considera documentos já separados por empresa/ambiente. Os originais preservados pela migração de arquivos e as cópias de segurança antigas não são apagados automaticamente. Após conferência e política de retenção definida, podem ser removidos separadamente. A ausência de remoção automática evita destruir o único backup de migração.

O bucket é um arquivo fiscal primário, não substitui backup do banco, da chave de criptografia e da própria configuração B2. Não configure exclusão automática dos XML sem uma política de retenção definida.

## Operação e manutenção

- Preserve `CERT_ENCRYPTION_KEY`: sem ela os certificados cifrados importados não podem ser recuperados.
- Guarde senhas de banco e B2 fora do Git e das aplicações clientes.
- Mantenha o worker ativo para recuperação NFC-e e arquivamento de XML.
- Registros, índices e filas ficam no banco; logs e arquivos temporários continuam na VPS. XML pendente permanece no banco até confirmação do B2.
- Homologação/produção fiscal por empresa não substitui uma instalação separada para testar atualizações do software.
- Os testes com PostgreSQL usam namespace `tests_*`; o transporte B2 dos testes é simulado. A conexão com um projeto Supabase e o bucket real requer validação com as credenciais da instalação.
