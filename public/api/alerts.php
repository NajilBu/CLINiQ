<?php

require_once __DIR__ . '/../../app/config/database.php';

header('Content-Type: application/json');

$alerts = db()->query("SELECT id, reporter_name, location, concern, status, created_at FROM nurse_alerts WHERE status = 'Pending' ORDER BY created_at DESC LIMIT 5")->fetchAll();
$count = db()->query("SELECT COUNT(*) AS total FROM nurse_alerts WHERE status = 'Pending'")->fetch()['total'] ?? 0;

echo json_encode([
    'pending_count' => (int) $count,
    'alerts' => $alerts,
]);
