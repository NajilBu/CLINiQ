<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/config/env.php';

$rootPassword = (string) env_value('MARIADB_ROOT_PASSWORD', '');
if ($rootPassword === '') {
    fwrite(STDERR, "[CLINiQ Backup Restore Test] MariaDB maintenance credential is unavailable.\n");
    exit(1);
}

putenv('DB_USER=root');
putenv('DB_PASS=' . $rootPassword);

require_once dirname(__DIR__, 2) . '/app/services/BackupService.php';

try {
    $result = cliniq_backup_restore_test();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[CLINiQ Backup Restore Test] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
