# VPS com PostgreSQL próprio e configuração cifrada no banco

Versão 3.2. O banco é um PostgreSQL na própria VPS. Os XML fiscais ficam no banco ou, se configurado, no **Backblaze B2**. Conexões de saída: SEFAZ, Sefin/ADN da NFS-e, Let's Encrypt e o B2 (quando ligado).

## O que fica onde

| Dado | Onde | Quem altera |
|---|---|---|
| Senha do PostgreSQL e chave-mestra (`CERT_ENCRYPTION_KEY`) | `/etc/phpfiscal/bootstrap.env` e `postgres.env`, com dono root e permissão 600, fora do projeto | Gerados pelo instalador; nunca exibidos pela API |
| Domínio | `/etc/phpfiscal/compose.env` | Instalador |
| URL pública, sessões, login, Portal do Contador, limites da API, SMTP (senha cifrada), NFS-e/NFC-e | Tabela `fiscal_documents`, documento `system/configuracao.json` **inteiro cifrado** com a chave-mestra | Janela **/configuracao** (administrador central) |
| Responsável técnico NF-e/NFC-e | Banco (`fiscal_api_settings`) | Janela /configuracao → Responsável técnico |
| Empresas, usuários, chaves, certificados A1 (cifrados) e notas | Banco (`fiscal_documents` / `fiscal_blobs`) | Painel e API |
| XML fiscais | Banco, ou Backblaze B2 (bucket privado); com B2, ficam no banco até o worker enviar e conferir | Janela /configuracao → Armazenamento de XML |
| Credenciais do B2 (keyID, applicationKey) | Configuração cifrada no banco; a applicationKey nunca é exibida | Janela /configuracao |
| Certificado HTTPS | Volume `caddy-data`; o Caddy emite e renova sozinho | Automático |
| Logs | Volume `fiscal-storage` | — |

A chave-mestra e a senha do banco não podem ficar no banco: sem elas a API não conecta nem decifra o que está gravado lá. São os únicos segredos em arquivo, e ficam fora da pasta do projeto, legíveis só pelo root. O Docker os entrega aos containers como variáveis de ambiente.

**Guarde uma cópia de `/etc/phpfiscal/bootstrap.env` fora da VPS.** Sem a chave-mestra, nem o backup do banco recupera os certificados A1 e a configuração.

## Instalação (Ubuntu 22.04/24.04 limpo)

Antes de começar:

1. Aponte o DNS (registro A) do domínio, por exemplo `api.suaempresa.com.br`, para o IP da VPS.
2. Envie o projeto para a VPS. Exemplo com o repositório privado:

```bash
sudo mkdir -p /opt/phpfiscal && sudo chown "$USER" /opt/phpfiscal
git clone https://github.com/sergiocguedes/phpfiscal.git /opt/phpfiscal   # token ou deploy key do GitHub
cd /opt/phpfiscal/api-fiscal
```

3. Para o DANFSe gerado na API, copie para a VPS `arial.ttf`, `arialbd.ttf` e `micross.ttf` (de um Windows licenciado), por exemplo em `~/fontes`.

Instale:

```bash
sudo FONTES_DANFSE=$HOME/fontes bash deploy/instalar-vps.sh api.suaempresa.com.br voce@suaempresa.com.br
```

O script, idempotente:

1. Atualiza o sistema, liga as atualizações automáticas de segurança e o NTP.
2. Instala o Docker oficial.
3. Configura o firewall para liberar só 22, 80 e 443.
4. Gera `/etc/phpfiscal/*.env`, sem sobrescrever se já existirem.
5. Instala o `vendor` com o Composer em container e prepara as fontes do DANFSe.
6. Sobe os serviços `db` (PostgreSQL 17, sem porta publicada e numa rede interna sem internet), `preparar` (schema e migrações), `php` (FPM), `worker` e `web` (Caddy com HTTPS).
7. Grava `APP_URL=https://<domínio>` e o cookie seguro na configuração, sem sobrescrever o que já foi ajustado.
8. Cria o administrador central (a senha aparece uma única vez).
9. Agenda o backup diário.

Para atualizar, basta `git pull` e rodar o mesmo comando, sem parâmetros novos.

### Migrando dados de outra instalação

Informe a chave-mestra antiga **na primeira execução**: `sudo CHAVE_MESTRA='...' bash deploy/instalar-vps.sh ...`. Em seguida, com `web` e `worker` parados, importe o backup lógico com `php bin/backup-banco.php importar` dentro do container `preparar`. XML que versões anteriores gravaram em arquivo (`storage/xml`) são trazidos para o banco automaticamente a cada início (`bin/banco.php`), com conferência de SHA-256. O índice só é removido depois da conferência, e o arquivo de origem é preservado.

## Janela de configuração

Fica em `https://<domínio>/configuracao` e segue o padrão do Portal do Contador. Exige a sessão de um **administrador central** e é recusada para chaves de API, inclusive as centrais.

- **Todos os parâmetros**: listagem com filtros por grupo, origem (banco, arquivo antigo, padrão) e busca. "Alterar" abre o grupo.
- **Grupos**: Endereço e acesso, Sessões e login, Portal do Contador, Limites da API, E-mail (SMTP), NFS-e e NFC-e, Armazenamento de XML. Cada um abre em janela própria com Voltar e Salvar. Campo vazio volta ao padrão do sistema. A senha SMTP nunca é exibida: em branco mantém a atual, e para apagar há uma caixa específica.
- **Inicialização e segurança**: mostra só leitura, sem valores, se a senha do banco e a chave-mestra estão definidas, e avisa se ainda há parâmetros num `.env` antigo.
- **Responsável técnico**: o grupo infRespTec da NF-e/NFC-e.

As alterações valem na requisição seguinte, sem reiniciar; o worker relê a configuração a cada ciclo. Toda gravação entra na trilha de auditoria (`configuracao.atualizada`).

Pela linha de comando:

```bash
docker compose --env-file /etc/phpfiscal/compose.env -f docker/compose.online.yml exec php php bin/configuracao.php listar
```

## Backblaze B2 (XML)

1. No Backblaze, crie um **bucket privado** e uma **Application Key** com acesso de leitura e gravação só a esse bucket. Anote o keyID, a applicationKey e o endpoint S3 (ex.: `https://s3.us-east-005.backblazeb2.com`; a região é `us-east-005`).
2. Em `/configuracao` → **Armazenamento de XML**, escolha "Backblaze B2" e preencha os campos.
3. Clique em **Testar conexão**: a API grava, lê, compara o SHA-256 e apaga um objeto de teste sem dados fiscais, usando os valores digitados (nada é salvo).
4. Salve. A partir daí:
   - cada XML emitido é gravado no banco como cópia pendente, com um índice;
   - o **worker**, a cada 2 segundos, envia ao B2, baixa de volta, confere o SHA-256, marca como arquivado e só então apaga a cópia do banco;
   - falha no B2 mantém a cópia e repete com espera crescente, de 1 minuto até 1 hora. A emissão nunca espera o B2;
   - os objetos são imutáveis: `<prefixo>/<caminho do XML>/<sha256>.xml`. Uma nova versão do mesmo XML gera um objeto novo.
5. A situação (aguardando envio, enviados, com falha) aparece na mesma janela e em Administração no painel. Linha de comando: `php bin/testar-s3.php` e `php bin/arquivar-xml.php` (esvazia a fila na hora).

Para voltar para "banco", basta trocar o destino. Os XML já enviados continuam sendo lidos do B2, então mantenha as credenciais e o bucket. O backup do banco **não contém** os XML que já estão no B2: o próprio bucket é a cópia deles. Ative o versionamento ou o *Object Lock* no bucket, se quiser proteção contra exclusão.

## Instalação local (Windows/PDV)

Continua igual: `docker/compose.local.yml` com `.env.local`, gerado por `php bin/preparar-perfil.php local`, que agora contém só a inicialização e dois valores iniciais. No primeiro início, `bin/banco.php` copia para o banco qualquer parâmetro que ainda esteja no `.env`. Depois disso, ajuste tudo em `http://localhost:8081/configuracao` e apague essas linhas do arquivo.

## Operação

| Tarefa | Comando (na pasta `api-fiscal`) |
|---|---|
| Situação | `sudo docker compose --env-file /etc/phpfiscal/compose.env -f docker/compose.online.yml ps` |
| Logs | `... logs -f --tail=100 php worker web` |
| Backup manual | `sudo /usr/local/sbin/phpfiscal-backup` (arquivos em `/var/backups/phpfiscal`, 14 dias) |
| Restaurar backup | `... exec -T db pg_restore -U fiscal -d fiscal --clean --if-exists < arquivo.dump` |
| Parar sem perder dados | `... stop` (nunca use `down -v`: o `-v` apaga o banco) |

Copie os backups e o `bootstrap.env` para fora da VPS, por exemplo com `rsync` ou `rclone` para outro servidor. Backup que só existe na própria VPS não protege contra perda da VPS.
