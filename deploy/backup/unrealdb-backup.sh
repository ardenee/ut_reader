#!/bin/sh
set -eu

backup_root="${UNREALDB_BACKUP_PATH:-/var/backups/unrealdb}"
storage_path="${UNREALDB_STORAGE_PATH:-/var/www/html/catalog/storage}"
retention_days="${UNREALDB_BACKUP_RETENTION_DAYS:-30}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
destination="$backup_root/$timestamp"

case "$retention_days" in ''|*[!0-9]*) retention_days=30 ;; esac
if [ "$retention_days" -lt 1 ]; then retention_days=1; fi
if [ "$retention_days" -gt 3650 ]; then retention_days=3650; fi

: "${UNREALDB_DB_HOST:?UNREALDB_DB_HOST is required}"
: "${UNREALDB_DB_NAME:?UNREALDB_DB_NAME is required}"
: "${UNREALDB_DB_USER:?UNREALDB_DB_USER is required}"
: "${UNREALDB_DB_PASSWORD:?UNREALDB_DB_PASSWORD is required}"

mkdir -p "$destination"
umask 077

MYSQL_PWD="$UNREALDB_DB_PASSWORD" mysqldump \
  --host="$UNREALDB_DB_HOST" \
  --port="${UNREALDB_DB_PORT:-3306}" \
  --user="$UNREALDB_DB_USER" \
  --single-transaction \
  --quick \
  --routines \
  --events \
  --triggers \
  --hex-blob \
  --set-gtid-purged=OFF \
  "$UNREALDB_DB_NAME" | gzip -9 > "$destination/database.sql.gz"

if [ ! -d "$storage_path" ]; then
  echo "Storage path is unavailable: $storage_path" >&2
  exit 1
fi

tar -C "$storage_path" -czf "$destination/storage.tar.gz" .
(
  cd "$destination"
  sha256sum database.sql.gz storage.tar.gz > SHA256SUMS
  printf '%s\n' \
    "created_at=$timestamp" \
    "database=$UNREALDB_DB_NAME" \
    "storage_path=$storage_path" \
    "app_version=${APP_VERSION:-unknown}" > metadata.txt
)

find "$backup_root" -mindepth 1 -maxdepth 1 -type d -mtime "+$retention_days" -exec rm -rf -- {} +
printf '%s\n' "$destination"
