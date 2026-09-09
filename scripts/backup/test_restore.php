<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/BackupService.php';

$path = $argv[1] ?? null;
try {
    $result = cliniq_backup_restore_test($path);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[CLINiQ Backup Restore Test] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

