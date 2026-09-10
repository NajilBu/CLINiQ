<?php

declare(strict_types=1);

putenv('BACKUP_ENCRYPTION_KEY=test-only-backup-key-with-at-least-32-characters');
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

$fakeHistory = array_map(static fn(int $index): array => ['name' => 'backup-' . $index], range(1, 12));
$pageOne = cliniq_paginate_backup_history($fakeHistory, 1, 5);
$pageThree = cliniq_paginate_backup_history($fakeHistory, 99, 5);
if (count($pageOne['items']) !== 5 || $pageOne['page'] !== 1 || $pageOne['total_pages'] !== 3 || $pageOne['total'] !== 12) {
    throw new RuntimeException('Backup history must paginate at five records per page.');
}
if (count($pageThree['items']) !== 2 || $pageThree['page'] !== 3) {
    throw new RuntimeException('Backup history must clamp out-of-range pages and retain the final records.');
}

$source = file_get_contents(dirname(__DIR__) . '/app/services/BackupService.php');
if (!str_contains($source, "if (\$type === 'daily' && !\$force && cliniq_backup_today_exists())")) {
    throw new RuntimeException('Daily duplicate protection is missing.');
}
if (!str_contains($source, "if (\$scheduled && (int) date('G') < CLINIQ_BACKUP_START_HOUR)")) {
    throw new RuntimeException('Scheduled backups must wait until 8:00 AM.');
}

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliniq-backup-crypto-' . bin2hex(random_bytes(5));
if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    throw new RuntimeException('Unable to create backup encryption test directory.');
}
$plaintextPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'payload.sql';
$recoveredPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'recovered.sql';
$expected = "CREATE TABLE encrypted_test (id INT);\n";
file_put_contents($plaintextPath, $expected);
try {
    $details = cliniq_backup_encrypt_file($plaintextPath);
    $encryptedPath = $plaintextPath . '.enc';
    if (is_file($plaintextPath) || !is_file($encryptedPath)) {
        throw new RuntimeException('Backup encryption must replace the plaintext payload.');
    }
    if (!hash_equals($details['encrypted_sha256'], (string) hash_file('sha256', $encryptedPath))) {
        throw new RuntimeException('Encrypted payload checksum does not match.');
    }
    cliniq_backup_decrypt_file($encryptedPath, $recoveredPath);
    if (file_get_contents($recoveredPath) !== $expected) {
        throw new RuntimeException('Encrypted backup payload did not decrypt exactly.');
    }
} finally {
    @unlink($plaintextPath);
    @unlink($plaintextPath . '.enc');
    @unlink($recoveredPath);
    @rmdir($temporaryDirectory);
}

echo "Backup policy and authenticated-encryption test passed.\n";
