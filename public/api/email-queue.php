<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/helpers/mail.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$user = current_user();
if ($user === null || !in_array($user['role'] ?? '', ['admin', 'doctor'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$key = trim((string) ($_GET['queue_key'] ?? $_POST['queue_key'] ?? ''));
if ($key === '' || !preg_match('/^school_year_[0-9]{4}-[0-9]{4}_[a-f0-9]+$/', $key)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid queue.']);
    exit;
}
$db = auth_db();
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'status');
if ($action === 'process') {
    $rows = $db->prepare("SELECT * FROM email_queue WHERE queue_key = ? AND status IN ('pending','failed') AND available_at <= NOW() ORDER BY id LIMIT 5");
    $rows->execute([$key]);
    $sent = 0; $failed = 0;
    $mark = $db->prepare("UPDATE email_queue SET status = ?, attempts = attempts + 1, sent_at = IF(? = 'sent', NOW(), sent_at), last_error = ? WHERE id = ?");
    foreach ($rows->fetchAll() as $row) {
        try {
            $ok = send_cliniq_email((string) $row['recipient_email'], (string) $row['recipient_name'], (string) $row['subject'], (string) $row['html_body']);
            if ($ok) { $mark->execute(['sent', 'sent', null, (int) $row['id']]); $sent++; }
            else { $mark->execute(['failed', 'failed', 'SMTP delivery failed', (int) $row['id']]); $failed++; }
        } catch (Throwable $e) {
            $mark->execute(['failed', 'failed', substr($e->getMessage(), 0, 500), (int) $row['id']]); $failed++;
        }
    }
}
$summary = $db->prepare("SELECT COUNT(*) total, SUM(status = 'sent') sent, SUM(status IN ('pending','processing')) pending, SUM(status = 'failed') failed FROM email_queue WHERE queue_key = ?");
$summary->execute([$key]);
$result = $summary->fetch() ?: ['total' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0];
echo json_encode(['queue_key' => $key, 'total' => (int) $result['total'], 'sent' => (int) $result['sent'], 'pending' => (int) $result['pending'], 'failed' => (int) $result['failed'], 'complete' => ((int) $result['pending'] === 0)]);
