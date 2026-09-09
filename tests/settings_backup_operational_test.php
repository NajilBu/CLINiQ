<?php

declare(strict_types=1);

$settings = file_get_contents(dirname(__DIR__) . '/public/settings/index.php');
$service = file_get_contents(dirname(__DIR__) . '/app/services/BackupService.php');
$runner = file_get_contents(dirname(__DIR__) . '/scripts/backup/run_backup.php');
$scheduler = file_get_contents(dirname(__DIR__) . '/scripts/backup/register_backup_task.ps1');
$restoreTest = file_get_contents(dirname(__DIR__) . '/scripts/backup/test_restore.php');
$electron = file_get_contents(dirname(__DIR__) . '/electron/main.js');
$containerScheduler = file_get_contents(dirname(__DIR__) . '/scripts/backup/container_scheduler.sh');
$decryptTool = file_get_contents(dirname(__DIR__) . '/scripts/backup/decrypt_backup.php');

function expect_operational_backup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['run_backup', 'verify_backup', 'run_semester_backup', 'Run Backup Now', 'Verify Latest', 'Semester Archive', '8:00 AM', 'Recent Backups'] as $expected) {
    expect_operational_backup(str_contains($settings, $expected), "Backup Settings is missing {$expected}.");
}
expect_operational_backup(!str_contains($settings, 'data-backup-placeholder'), 'The Backup tab must no longer be marked as a placeholder.');
expect_operational_backup(!str_contains($settings, 'Run Backup · Coming soon'), 'The Run Backup control must be operational.');

foreach (['--single-transaction', '--routines', '--events', '--triggers', 'storage/documents', 'public/uploads', 'hash_file', 'CLINIQ_BACKUP_DAILY_RETENTION', 'CLINIQ_BACKUP_WEEKLY_RETENTION', 'aes-256-gcm', 'cliniq_backup_encrypt_payloads', 'cliniq_backup_decrypt_file'] as $expected) {
    expect_operational_backup(str_contains($service, $expected), "Backup service is missing {$expected}.");
}
expect_operational_backup(str_contains($runner, '--scheduled'), 'The backup runner must support scheduled execution.');
expect_operational_backup(str_contains($restoreTest, 'cliniq_backup_restore_test'), 'A temporary-database restore test must be available.');
expect_operational_backup(str_contains($service, 'DROP DATABASE IF EXISTS'), 'Restore verification must clean up only its temporary database.');
expect_operational_backup(str_contains($scheduler, "New-ScheduledTaskTrigger -Daily -At '8:00 AM'"), 'The Windows backup task must run at 8:00 AM.');
expect_operational_backup(str_contains($scheduler, 'New-ScheduledTaskTrigger -AtLogOn'), 'The Windows task must catch up at logon.');
expect_operational_backup(str_contains($scheduler, '-StartWhenAvailable'), 'The Windows task must run after a missed start.');
expect_operational_backup(str_contains($containerScheduler, '--scheduled'), 'The Docker backup service must request scheduled daily backups.');
expect_operational_backup(str_contains($containerScheduler, 'sleep 300'), 'The Docker backup service must catch up after startup and remain active.');
expect_operational_backup(!str_contains($electron, 'requestMissedDailyBackup'), 'Docker Electron must not run the legacy XAMPP backup task.');
expect_operational_backup(str_contains($decryptTool, 'cliniq_backup_materialize_file'), 'Encrypted snapshots need a document recovery command.');

echo "PASS: Operational backup service, Settings controls, 8 AM scheduler, retention, verification, and Electron catch-up are wired. No backup was created by this test.\n";
