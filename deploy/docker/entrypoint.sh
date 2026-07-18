#!/bin/sh
set -eu

storage="${UNREALDB_STORAGE_PATH:-/var/www/html/catalog/storage}"

for directory in \
    "$storage" \
    "$storage/cache" \
    "$storage/federation/incoming" \
    "$storage/games" \
    "$storage/jobs" \
    "$storage/locks" \
    "$storage/upload-bucket"
do
    mkdir -p "$directory"
done

if [ "$(id -u)" = "0" ]; then
    chown www-data:www-data "$storage" \
        "$storage/cache" \
        "$storage/federation" \
        "$storage/federation/incoming" \
        "$storage/games" \
        "$storage/jobs" \
        "$storage/locks" \
        "$storage/upload-bucket"
fi

session_secure="${UNREALDB_SESSION_COOKIE_SECURE:-1}"
session_ini=/usr/local/etc/php/conf.d/zz-unrealdb-session.ini
{
    printf 'session.cookie_secure = %s\n' "$session_secure"
    if [ -n "${UNREALDB_SESSION_REDIS_DSN:-}" ]; then
        printf 'session.save_handler = redis\n'
        printf 'session.save_path = "%s"\n' "$UNREALDB_SESSION_REDIS_DSN"
    fi
} > "$session_ini"

if [ ! -f "${UNREALDB_CATALOG_CONFIG:-/var/www/html/deploy/docker/config.php}" ]; then
    echo "UnrealDB configuration file is missing." >&2
    exit 1
fi

exec "$@"
