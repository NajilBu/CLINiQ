#!/bin/sh
set -eu

interval="${EMAIL_WORKER_INTERVAL_SECONDS:-60}"
case "$interval" in
    ''|*[!0-9]*) interval=60 ;;
esac

if [ "$interval" -lt 15 ]; then
    interval=15
fi

attempt=1
until php -r 'require "/var/www/html/app/config/database.php"; auth_db()->query("SELECT 1");' >/dev/null 2>&1; do
    if [ "$attempt" -ge 60 ]; then
        echo "CLINiQ email worker could not reach the database." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep 2
done

echo "CLINiQ email worker is active; polling every ${interval} seconds."

while true; do
    if ! php /var/www/html/scripts/email/process_queue.php; then
        echo "CLINiQ email worker cycle failed; retrying after ${interval} seconds." >&2
    fi
    sleep "$interval"
done
