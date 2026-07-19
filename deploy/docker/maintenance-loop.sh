#!/bin/sh
set -eu

interval="${UNREALDB_MAINTENANCE_INTERVAL_SECONDS:-86400}"
case "$interval" in
  ''|*[!0-9]*) interval=86400 ;;
esac
if [ "$interval" -lt 3600 ]; then interval=3600; fi
if [ "$interval" -gt 604800 ]; then interval=604800; fi

while true; do
  php /var/www/html/catalog/bin/job-control.php enqueue-prune-artifacts --incoming-max-age-seconds="${UNREALDB_STAGED_IMPORT_MAX_AGE_SECONDS:-172800}" || true
  php /var/www/html/catalog/bin/job-control.php enqueue-reconcile-unverified --max-files="${UNREALDB_RECONCILE_MAX_FILES:-1000}" || true
  sleep "$interval"
done
