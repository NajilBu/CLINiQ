<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/config/env.php';
require_once __DIR__ . '/../../app/services/AuditLog.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST.']);
    exit;
}

$secret = trim((string) env_value('BREVO_WEBHOOK_SECRET', ''));
$provided = trim((string) ($_SERVER['HTTP_X_CLINIQ_BREVO_WEBHOOK'] ?? ''));
if ($secret === '' || $provided === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON.']);
    exit;
}

$event = strtolower(trim((string) ($payload['event'] ?? '')));
$messageId = trim((string) ($payload['message-id'] ?? ''));
$eventTimestamp = filter_var($payload['ts_event'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$deliveryState = match ($event) {
    'delivered' => 'delivered',
    'deferred' => 'deferred',
    'soft_bounce', 'hard_bounce' => 'bounced',
    'blocked' => 'blocked',
    'invalid_email', 'error' => 'rejected',
    'spam' => 'complained',
    default => null,
};

if ($deliveryState === null || $messageId === '' || strlen($messageId) > 255 || $eventTimestamp === false) {
    http_response_code(202);
    echo json_encode(['updated' => false]);
    exit;
}

$db = auth_db();
$find = $db->prepare('SELECT id FROM email_queue WHERE provider_message_id = ? LIMIT 1');
$find->execute([$messageId]);
$queueId = (int) $find->fetchColumn();
if ($queueId < 1) {
    http_response_code(202);
    echo json_encode(['updated' => false]);
    exit;
}

$update = $db->prepare('UPDATE email_queue SET delivery_state = ?, delivery_state_updated_at = NOW(), delivery_event_at = FROM_UNIXTIME(?) WHERE id = ? AND (delivery_event_at IS NULL OR delivery_event_at <= FROM_UNIXTIME(?))');
$update->execute([$deliveryState, $eventTimestamp, $queueId, $eventTimestamp]);
if ($update->rowCount() !== 1) {
    http_response_code(204);
    exit;
}
audit_log_event('email', 'email_delivery_webhook', null, 'system', 'email', $queueId, [
    'event' => $event,
    'delivery_state' => $deliveryState,
]);

http_response_code(204);
