#!/bin/sh
set -eu

backup_dir="${1:-}"
restore_storage="${UNREALDB_RESTORE_STORAGE:-0}"
storage_path="${UNREALDB_STORAGE_PATH:-/var/www/html/catalog/storage}"

if [ -z "$backup_dir" ] || [ ! -d "$backup_dir" ]; then
  echo "Usage: $0 /path/to/backup-directory" >&2
  exit 2
fi
: "${UNREALDB_DB_HOST:?UNREALDB_DB_HOST is required}"
: "${UNREALDB_DB_NAME:?UNREALDB_DB_NAME is required}"
: "${UNREALDB_DB_USER:?UNREALDB_DB_USER is required}"
: "${UNREALDB_DB_PASSWORD:?UNREALDB_DB_PASSWORD is required}"
: "${UNREALDB_RESTORE_CONFIRM:?Set UNREALDB_RESTORE_CONFIRM to the exact database name}"

if [ "$UNREALDB_RESTORE_CONFIRM" != "$UNREALDB_DB_NAME" ]; then
  echo "Restore confirmation does not match the target database." >&2
  exit 1
fi

(
  cd "$backup_dir"
  sha256sum -c SHA256SUMS
)

if [ ! -f "$backup_dir/database.sql.gz" ]; then
  echo "Database backup is missing." >&2
  exit 1
fi

MYSQL_PWD="$UNREALDB_DB_PASSWORD" mysql \
  --host="$UNREALDB_DB_HOST" \
  --port="${UNREALDB_DB_PORT:-3306}" \
  --user="$UNREALDB_DB_USER" \
  "$UNREALDB_DB_NAME" < /dev/null

gzip -dc "$backup_dir/database.sql.gz" | MYSQL_PWD="$UNREALDB_DB_PASSWORD" mysql \
  --host="$UNREALDB_DB_HOST" \
  --port="${UNREALDB_DB_PORT:-3306}" \
  --user="$UNREALDB_DB_USER" \
  "$UNREALDB_DB_NAME"

if [ "$restore_storage" = "1" ]; then
  if [ ! -f "$backup_dir/storage.tar.gz" ]; then
    echo "Storage backup is missing." >&2
    exit 1
  fi
  mkdir -p "$storage_path"
  find "$storage_path" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  tar -C "$storage_path" -xzf "$backup_dir/storage.tar.gz"
fi

php /var/www/html/catalog/bin/migrate.php verify
printf '%s\n' "Restore completed and schema verification passed."
