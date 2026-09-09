#!/bin/sh
set -eu

backup_root="${BACKUP_ROOT:-/var/backups/cliniq}"
mkdir -p "$backup_root"
chown -R www-data:www-data "$backup_root"

attempt=1
until php -r 'require "/var/www/html/app/config/database.php"; auth_db()->query("SELECT 1");' >/dev/null 2>&1; do
    if [ "$attempt" -ge 60 ]; then
        echo "CLINiQ backup scheduler could not reach the database." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep 2
done

echo "CLINiQ backup scheduler is active; daily catch-up begins at 8:00 AM."

while true; do
    result="$(php /var/www/html/scripts/backup/run_backup.php --scheduled 2>&1)" || {
        echo "$result" >&2
        sleep 300
        continue
    }

    case "$result" in
        *'"state": "success"'*) echo "$result" ;;
    esac

    sleep 300
done
