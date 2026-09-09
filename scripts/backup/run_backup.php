<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/BackupService.php';

$type = in_array('--semester', $argv, true) ? 'semester' : 'daily';
$scheduled = in_array('--scheduled', $argv, true);
$force = in_array('--force', $argv, true);

try {
    $result = cliniq_backup_run($type, $force, $scheduled);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[CLINiQ Backup] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

