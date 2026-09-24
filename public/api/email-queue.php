<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/services/PatientEmail.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$user = current_user();
if ($user === null || trim((string) ($user['role'] ?? '')) === '') {
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
    patient_email_due_automations();
    $processed = patient_email_process_queue($key, 5);
    $sent = (int) ($processed['sent'] ?? 0);
    $failed = (int) ($processed['failed'] ?? 0);
}
$summary = $db->prepare("SELECT COUNT(*) total, SUM(status = 'sent') sent, SUM(status IN ('pending','processing')) pending, SUM(status = 'failed') failed FROM email_queue WHERE queue_key = ?");
$summary->execute([$key]);
$result = $summary->fetch() ?: ['total' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0];
echo json_encode(['queue_key' => $key, 'total' => (int) $result['total'], 'sent' => (int) $result['sent'], 'pending' => (int) $result['pending'], 'failed' => (int) $result['failed'], 'complete' => ((int) $result['pending'] === 0)]);
