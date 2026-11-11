# Instalação local e online

A impressão de NFS-e usa o modelo DANFSe v2.0 gerado na API e pode retornar Base64. Nos dois perfis, prepare também as fontes privadas conforme [DANFSE-NACIONAL.md](DANFSE-NACIONAL.md).

> Após a reorganização, a instalação atual usa `docker/compose.yml` e `.env` dentro de `api-fiscal/`. Consulte [Docker local — recuperação e comandos atuais](DOCKER-LOCAL-RECUPERACAO.md). As referências ao Compose da raiz descrevem a estrutura anterior.

| Perfil | Endereço da API | Banco | XML |
|---|---|---|---|
| Local | `http://localhost:8081/api/v1` | PostgreSQL no Docker, no PC do PDV | No próprio banco |
| Online | `https://SEU-DOMINIO/api/v1` | PostgreSQL próprio na VPS (container, sem porta publicada) | No banco ou no Backblaze B2 (janela `/configuracao`) |

O painel fica na raiz do endereço e o Swagger em `/docs`. O perfil online publica **HTTPS na porta 443** (Caddy, certificado automático). Configuração do sistema: janela `/configuracao`, cifrada no banco — veja [VPS-POSTGRESQL.md](VPS-POSTGRESQL.md). Os nomes das instalações e dos volumes são diferentes; não há sincronização automática entre elas. Cada uma tem seus usuários, empresas, certificados e chaves.

## Antes de instalar

### Qual Compose e configuração usar

| Instalação | Compose | Inicialização (fora do banco) | Demais configurações |
|---|---|---|---|
| Local existente neste PC | `docker/compose.yml` | `.env` da edição ativa | Banco: janela `/configuracao` (o `.env` antigo é importado no início) |
| Nova instalação local | `docker/compose.local.yml` | `api-fiscal/.env.local` (`php bin/preparar-perfil.php local`) | Banco: janela `/configuracao` |
| Online na VPS | `docker/compose.online.yml` | `/etc/phpfiscal/*.env` (gerados por `deploy/instalar-vps.sh`) | Banco: janela `/configuracao` |

Fora do banco ficam só a conexão com o PostgreSQL e a chave-mestra `CERT_ENCRYPTION_KEY`. O certificado A1 da empresa é cadastrado pelo painel/API em qualquer perfil, e o ambiente fiscal (`ambiente_nfe`, `ambiente_nfce`, `ambiente_nfse`) pertence à empresa. Estar online não ativa produção automaticamente.

Local e online não sincronizam automaticamente empresas, notas, números ou usuários. Para migrar, use `bin/backup-banco.php` e preserve `CERT_ENCRYPTION_KEY`. Nunca use `down -v` numa atualização.

As dependências PHP ficam em `api-fiscal/vendor/`. Para instalá-las via Docker:

```powershell
docker run --rm -v "${PWD}:/app" composer:2 install --no-dev --prefer-dist --ignore-platform-reqs --no-scripts
```

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

O PostgreSQL não publica porta no Windows; a API é publicada somente em `127.0.0.1:8081`. Os XML deste perfil ficam no PostgreSQL local. Faça backup do banco e do segredo `CERT_ENCRYPTION_KEY`.

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

Se quiser transferi-la para o perfil nomeado, pare web/worker, exporte o banco e importe no novo perfil com `bin/backup-banco.php exportar|importar`. Preserve `CERT_ENCRYPTION_KEY` e use o namespace de destino escolhido. Não substitua o `.env` da instalação existente por um perfil recém-gerado.

## Online na VPS Linux

Guia completo, script de instalação e operação: [VPS com PostgreSQL próprio](VPS-POSTGRESQL.md).

```bash
cd /opt/phpfiscal/api-fiscal
sudo FONTES_DANFSE=$HOME/fontes bash deploy/instalar-vps.sh api.suaempresa.com.br voce@suaempresa.com.br
```

## URL e chave no Delphi

Consulte [Integração Delphi](DELPHI-CONFIGURACAO.md) e os modelos em `examples/delphi/`. Uma chave criada localmente não autentica no servidor online. Configure explicitamente qual instalação emite para cada empresa/série; não alterne automaticamente de servidor após timeout.

## Atualizações

Atualize o código, instale dependências conforme o lock e execute novamente `up -d --build` com o Compose do perfil correto. Faça backup antes e preserve arquivos `.env.*`, `CERT_ENCRYPTION_KEY`, volumes do banco, certificados e bucket. Homologação fiscal por empresa não substitui um ambiente separado para testar mudanças no software.
