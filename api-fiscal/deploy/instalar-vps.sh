#!/usr/bin/env bash
# =====================================================================================================
#  API Fiscal (phpfiscal) — instalação e atualização em VPS Ubuntu 22.04/24.04 limpa
#
#  Uso (dentro da pasta api-fiscal já copiada/clonada no servidor, ex.: /opt/phpfiscal/api-fiscal):
#     sudo bash deploy/instalar-vps.sh api.seudominio.com.br admin@seudominio.com.br
#
#  Migrando de uma instalação existente? Informe a chave-mestra antiga antes (senão os certificados A1
#  e a configuração gravados com ela ficam ilegíveis):
#     sudo CHAVE_MESTRA='valor-de-CERT_ENCRYPTION_KEY' bash deploy/instalar-vps.sh ...
#
#  Rodar de novo = atualizar: preserva banco, chave-mestra, certificados HTTPS e configuração.
#
#  O que fica fora do banco (e fora do projeto), em /etc/phpfiscal, root, permissão 600:
#     bootstrap.env  DATABASE_PASSWORD + CERT_ENCRYPTION_KEY   (lidos pela API)
#     postgres.env   POSTGRES_PASSWORD                          (lido pelo PostgreSQL)
#     compose.env    DOMINIO                                    (não é segredo)
#  Todo o resto é configurado em https://<dominio>/configuracao e fica cifrado no banco.
# =====================================================================================================
set -Eeuo pipefail
umask 077

DOMINIO="${1:-}"
EMAIL_ADMIN="${2:-}"
CONF=/etc/phpfiscal
BACKUPS=/var/backups/phpfiscal
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE=(docker compose --env-file "$CONF/compose.env" -f "$APP_DIR/docker/compose.online.yml")

passo() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
aviso() { printf '\033[1;33m[aviso]\033[0m %s\n' "$*"; }
erro()  { printf '\033[1;31m[erro]\033[0m %s\n' "$*" >&2; exit 1; }
trap 'erro "falhou na linha $LINENO. Nada foi apagado; corrija e rode o script de novo."' ERR

# ----------------------------------------------------------------------------------------- verificações
[ "$(id -u)" -eq 0 ] || erro "Execute com sudo."
[ -f "$APP_DIR/bootstrap.php" ] && [ -f "$APP_DIR/docker/compose.online.yml" ] || erro "Rode a partir da pasta api-fiscal do projeto."
[ -f "$APP_DIR/src/Storage/JsonStore.php" ] || erro "src/Storage ausente: o clone veio sem essa pasta (corrija o .gitignore e faça commit dela)."
if [ -f "$CONF/compose.env" ] && [ -z "$DOMINIO" ]; then DOMINIO="$(sed -n 's/^DOMINIO=//p' "$CONF/compose.env")"; fi
[[ "$DOMINIO" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || erro "Uso: sudo bash deploy/instalar-vps.sh <dominio> <email-admin>"
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || aviso "Testado em Ubuntu; sistema atual: ${PRETTY_NAME:-desconhecido}."

# ----------------------------------------------------------------------------------------- sistema
passo "Atualizando o sistema e instalando pacotes básicos"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get -y -q upgrade
apt-get -y -q install ca-certificates curl gnupg ufw unattended-upgrades openssl dnsutils
dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true
timedatectl set-ntp true 2>/dev/null || true   # relógio certo evita rejeição de horário (E0008) na NFS-e

if ! command -v docker >/dev/null 2>&1; then
  passo "Instalando Docker (repositório oficial)"
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list
  chmod 644 /etc/apt/sources.list.d/docker.list
  apt-get update -q
  apt-get -y -q install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
systemctl enable --now docker >/dev/null

passo "Firewall: somente SSH, HTTP e HTTPS"
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 443/udp >/dev/null
ufw --force enable >/dev/null
aviso "O Docker publica portas por fora do ufw: só o Caddy (80/443) é publicado; o banco não tem porta."

# ----------------------------------------------------------------------------------------- inicialização
passo "Dados de inicialização em $CONF (fora do projeto)"
install -d -m 700 -o root -g root "$CONF"
if [ ! -f "$CONF/bootstrap.env" ]; then
  SENHA_DB="$(openssl rand -hex 32)"
  if [ -n "${CHAVE_MESTRA:-}" ]; then
    [ "$(printf '%s' "$CHAVE_MESTRA" | base64 -d 2>/dev/null | wc -c)" -eq 32 ] || erro "CHAVE_MESTRA não é base64 de 32 bytes."
    CHAVE="$CHAVE_MESTRA"
  else
    CHAVE="$(openssl rand -base64 32)"
  fi
  printf 'DATABASE_PASSWORD=%s\nCERT_ENCRYPTION_KEY=%s\n' "$SENHA_DB" "$CHAVE" > "$CONF/bootstrap.env"
  printf 'POSTGRES_PASSWORD=%s\n' "$SENHA_DB" > "$CONF/postgres.env"
  unset SENHA_DB CHAVE CHAVE_MESTRA
  NOVA_CHAVE=1
else
  echo "bootstrap.env existente preservado."
  NOVA_CHAVE=0
fi
printf 'DOMINIO=%s\n' "$DOMINIO" > "$CONF/compose.env"
chown root:root "$CONF"/*.env
chmod 600 "$CONF"/*.env

# ----------------------------------------------------------------------------------------- código
passo "Preparando o código"
# Código montado somente leitura nos containers (usuário 10096): precisa ser legível, nunca gravável por eles.
chmod -R u+rwX,go+rX,go-w "$APP_DIR"
# Nenhum .env dentro do projeto na VPS: se houver um antigo, fica ilegível para os containers
for f in "$APP_DIR"/.env "$APP_DIR"/.env.*; do
  case "$f" in *.example) continue;; esac
  if [ -f "$f" ]; then chmod 600 "$f"; aviso "$(basename "$f") encontrado no projeto e ignorado; a VPS usa $CONF. Apague-o depois de conferir."; fi
done
if [ -d "$APP_DIR/secrets" ]; then chmod 755 "$APP_DIR/secrets"; fi
# Ponto de montagem do volume de storage: o código é montado somente leitura, então a pasta precisa existir antes
install -d -m 755 "$APP_DIR/storage"
docker run --rm -v "$APP_DIR:/app" -w /app composer:2 install --no-dev --prefer-dist --no-interaction --no-progress --ignore-platform-reqs --no-scripts
chmod -R go+rX,go-w "$APP_DIR/vendor"

# Fontes do DANFSe (Arial/Microsoft Sans Serif; não distribuídas). Opcional: FONTES_DANFSE=/pasta/com/ttf
if [ -n "${FONTES_DANFSE:-}" ]; then
  passo "Preparando fontes do DANFSe a partir de $FONTES_DANFSE"
  docker run --rm -v "$APP_DIR:/app" -v "$FONTES_DANFSE:/fontes:ro" -w /app python:3.12-slim \
    sh -c "pip install -q fonttools && python bin/preparar-fontes-danfse.py /fontes secrets/danfse-fonts"
  chmod -R go+rX,go-w "$APP_DIR/secrets/danfse-fonts"
fi
[ -d "$APP_DIR/secrets/danfse-fonts" ] || aviso "Fontes do DANFSe ausentes: a impressão do DANFSe gerado na API falhará até rodar com FONTES_DANFSE=/pasta (arial.ttf, arialbd.ttf, micross.ttf)."

# ----------------------------------------------------------------------------------------- containers
passo "Construindo e iniciando (PostgreSQL, API, worker, HTTPS)"
"${COMPOSE[@]}" build --pull php
"${COMPOSE[@]}" up --no-start --remove-orphans
# Volume de storage (logs, lock do worker) pertence ao usuário fixo dos containers, não ao root
docker run --rm -v phpfiscal_fiscal-storage:/s alpine:3 sh -c 'mkdir -p /s/logs /s/locks /s/tmp && chown -R 10096:10096 /s && chmod 700 /s'
"${COMPOSE[@]}" up -d
"${COMPOSE[@]}" ps

# Configuração inicial no banco (não sobrescreve o que já foi ajustado na janela)
"${COMPOSE[@]}" exec -T php php bin/configuracao.php definir-se-vazio APP_URL "https://$DOMINIO"
"${COMPOSE[@]}" exec -T php php bin/configuracao.php definir-se-vazio SESSION_COOKIE_SECURE 1

if [ ! -f "$CONF/.admin-criado" ]; then
  [ -n "$EMAIL_ADMIN" ] || erro "Primeira instalação: informe o e-mail do administrador (2º parâmetro)."
  passo "Criando o administrador central"
  "${COMPOSE[@]}" exec -T php php bin/administrador.php "$EMAIL_ADMIN" --gerar-senha
  touch "$CONF/.admin-criado"; chmod 600 "$CONF/.admin-criado"
fi

# ----------------------------------------------------------------------------------------- backup
passo "Backup diário do banco (02:30, guarda 14 dias em $BACKUPS)"
install -d -m 700 "$BACKUPS"
install -m 700 "$APP_DIR/deploy/backup.sh" /usr/local/sbin/phpfiscal-backup
printf 'APP_DIR=%s\n' "$APP_DIR" > "$CONF/backup.conf"; chmod 600 "$CONF/backup.conf"
printf 'SHELL=/bin/bash\n30 2 * * * root /usr/local/sbin/phpfiscal-backup >> /var/log/phpfiscal-backup.log 2>&1\n' > /etc/cron.d/phpfiscal-backup
chmod 644 /etc/cron.d/phpfiscal-backup

# ----------------------------------------------------------------------------------------- verificação
passo "Verificando https://$DOMINIO"
IP_DNS="$(dig +short "$DOMINIO" A | tail -n1)"; IP_VPS="$(curl -fsS4 https://api.ipify.org 2>/dev/null || true)"
[ -n "$IP_DNS" ] && [ "$IP_DNS" = "$IP_VPS" ] || aviso "DNS de $DOMINIO aponta para '${IP_DNS:-nada}', esta VPS é '${IP_VPS:-?}'. O certificado HTTPS só sai quando o DNS apontar para cá."
ok=0
for _ in $(seq 1 30); do
  if curl -fsS "https://$DOMINIO/api/v1/health" >/dev/null 2>&1; then ok=1; break; fi
  sleep 5
done
trap - ERR
if [ "$ok" = 1 ]; then echo "API respondendo com HTTPS."; else aviso "Ainda sem resposta HTTPS. Veja: ${COMPOSE[*]} logs web php"; fi

cat <<FIM

=====================================================================================================
 Pronto.
   Painel ............ https://$DOMINIO/
   Configuração ...... https://$DOMINIO/configuracao   (SMTP, sessões, limites, NFS-e/NFC-e)
   API ............... https://$DOMINIO/api/v1
   Documentação ...... https://$DOMINIO/docs

 Próximos passos:
   1. Entre no painel com o administrador acima, ative a verificação em duas etapas (Segurança).
   2. Em Configuração, preencha o e-mail (SMTP), o responsável técnico e, se for usar,
      Armazenamento de XML → Backblaze B2 (use "Testar conexão" antes de salvar).
   3. Cadastre a empresa em homologação e crie uma chave restrita para cada sistema integrado.
FIM
if [ "$NOVA_CHAVE" = 1 ]; then cat <<FIM

 IMPORTANTE — guarde AGORA, fora deste servidor, uma cópia de $CONF/bootstrap.env:
     sudo cat $CONF/bootstrap.env
 Sem a CERT_ENCRYPTION_KEY os certificados A1 e a configuração do banco não podem ser lidos,
 nem a partir de um backup.
FIM
fi
echo "====================================================================================================="
