<?php

require_once __DIR__ . '/../../app/config/database.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$databaseReady = false;
try {
    $databaseReady = auth_db()->query('SELECT 1')->fetchColumn() !== false;
} catch (Throwable $e) {
    error_log('[CLINiQ Health] Database check failed: ' . $e->getMessage());
}

$healthy = $databaseReady;
http_response_code($healthy ? 200 : 503);

echo json_encode([
    'status' => $healthy ? 'ready' : 'unavailable',
    'services' => [
        'web' => 'ready',
        'database' => $databaseReady ? 'ready' : 'unavailable',
    ],
    'checked_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
