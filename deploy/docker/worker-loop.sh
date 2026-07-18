#!/bin/sh
set -u

max_jobs="${UNREALDB_WORKER_MAX_JOBS:-100}"
sleep_ms="${UNREALDB_WORKER_SLEEP_MS:-250}"
idle_seconds="${UNREALDB_WORKER_IDLE_SECONDS:-2}"
error_seconds="${UNREALDB_WORKER_ERROR_SECONDS:-10}"
worker_id="${UNREALDB_WORKER_ID:-$(hostname):$$}"

term=0
trap 'term=1' TERM INT

while [ "$term" -eq 0 ]; do
    php /var/www/html/catalog/bin/catalog-worker.php \
        --queue="${UNREALDB_QUEUE_NAME:-catalog}" \
        --max-jobs="$max_jobs" \
        --sleep-ms="$sleep_ms" \
        --worker-id="$worker_id"
    status=$?

    if [ "$term" -ne 0 ]; then
        break
    fi

    if [ "$status" -eq 0 ]; then
        sleep "$idle_seconds"
    else
        echo "UnrealDB worker exited with status $status; retrying after backoff." >&2
        sleep "$error_seconds"
    fi
done

exit 0
