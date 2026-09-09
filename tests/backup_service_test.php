<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/BackupService.php';

if (cliniq_backup_format_bytes(1000) !== '1000 B') {
    throw new RuntimeException('Small backup sizes must be displayed in bytes.');
}
if (cliniq_backup_format_bytes(1024) !== '1.00 KB') {
    throw new RuntimeException('Backup sizes must be converted to readable units.');
}
if (CLINIQ_BACKUP_START_HOUR !== 8 || CLINIQ_BACKUP_DAILY_RETENTION !== 14 || CLINIQ_BACKUP_WEEKLY_RETENTION !== 12) {
    throw new RuntimeException('The agreed backup schedule or retention policy changed unexpectedly.');
}

$source = file_get_contents(dirname(__DIR__) . '/app/services/BackupService.php');
if (!str_contains($source, "if (\$type === 'daily' && !\$force && cliniq_backup_today_exists())")) {
    throw new RuntimeException('Daily duplicate protection is missing.');
}
if (!str_contains($source, "if (\$scheduled && (int) date('G') < CLINIQ_BACKUP_START_HOUR)")) {
    throw new RuntimeException('Scheduled backups must wait until 8:00 AM.');
}

echo "Backup service policy test passed. No filesystem or database writes.\n";
