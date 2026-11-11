#!/usr/bin/env bash
# Backup diário do PostgreSQL da API Fiscal (instalado em /usr/local/sbin/phpfiscal-backup pelo instalador).
# Formato custom do pg_dump; restaura com: pg_restore --clean --if-exists -d fiscal arquivo.dump
# O backup é inútil sem a CERT_ENCRYPTION_KEY de /etc/phpfiscal/bootstrap.env: guarde as duas coisas fora da VPS.
set -euo pipefail
umask 077
. /etc/phpfiscal/backup.conf
DESTINO=/var/backups/phpfiscal
DIAS="${DIAS:-14}"
arquivo="$DESTINO/fiscal-$(date +%Y%m%d-%H%M%S).dump"
docker compose --env-file /etc/phpfiscal/compose.env -f "$APP_DIR/docker/compose.online.yml" exec -T db \
  pg_dump -U fiscal -d fiscal -Fc > "$arquivo.parcial"
[ -s "$arquivo.parcial" ] || { echo "$(date -Is) backup vazio" >&2; rm -f "$arquivo.parcial"; exit 1; }
mv "$arquivo.parcial" "$arquivo"
find "$DESTINO" -name 'fiscal-*.dump' -mtime +"$DIAS" -delete
echo "$(date -Is) backup ok: $arquivo ($(du -h "$arquivo" | cut -f1))"
