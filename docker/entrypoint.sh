#!/bin/sh
set -eu

mkdir -p \
    /var/www/html/storage/documents/ape \
    /var/www/html/public/uploads \
    "${BACKUP_ROOT:-/var/backups/cliniq}"

chown -R www-data:www-data \
    /var/www/html/storage/documents \
    /var/www/html/public/uploads \
    "${BACKUP_ROOT:-/var/backups/cliniq}"

attempt=1
until php -r 'require "/var/www/html/app/config/database.php"; auth_db()->query("SELECT 1");' >/dev/null 2>&1; do
    if [ "$attempt" -ge 60 ]; then
        echo "CLINiQ database did not become ready in time." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep 2
done

php /var/www/html/scripts/database/migrate.php

exec "$@"
