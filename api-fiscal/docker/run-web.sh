#!/usr/bin/env bash
# Emissor NF-e 2.9.1 · servidor do painel/API dentro do container (equivalente ao start.sh).
# Só sobe com PHP corrigido; log de acesso com os tokens de link público mascarados (OWASP A09/A02).
set -euo pipefail
umask 077
cd /app
php bin/banco.php
mkdir -p storage/logs
st=0
out="$(bash ./verificar-php.sh 2>&1)" || st=$?
if [ "$st" -ne 0 ]; then
  echo "$out" | grep -E '^(RESULTADO|O que fazer)' >&2 || true
  [ "$st" -eq 1 ] && { echo "Recusado: PHP vulnerável. Reconstrua a imagem (docker compose build --pull --no-cache)." >&2; exit 1; }
fi
POST_READING=0
[ "$(grep -E '^PERMITIR_MULTIPART=' .env 2>/dev/null | cut -d= -f2)" = "true" ] && POST_READING=1
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php \
  -d expose_php=0 -d display_errors=0 -d log_errors=1 \
  -d post_max_size=3M -d upload_max_filesize=1M -d max_file_uploads=1 \
  -d max_execution_time=120 -d memory_limit=256M \
  -d enable_post_data_reading="$POST_READING" -d session.upload_progress.enabled=0 \
  -d max_input_vars=1000 -d max_input_nesting_level=16 \
  -S 0.0.0.0:8000 -t public public/index.php \
  > >(sed -u -E 's#(/publico/|/compartilhado/)[^/ ?"]+#\1[token-oculto]#g' >> storage/logs/server.log) 2>&1
