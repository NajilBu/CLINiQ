<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers/auth.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['authorized' => false], JSON_UNESCAPED_SLASHES);
    exit;
}
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['authorized' => false], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['authorized' => true], JSON_UNESCAPED_SLASHES);
