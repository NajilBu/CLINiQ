<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app/config/database.php';

try {
    $db = auth_db();
    $tableCount = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    )->fetchColumn();
    echo "CLINiQ database connection is healthy ({$tableCount} tables).", PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[CLINiQ Check] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
