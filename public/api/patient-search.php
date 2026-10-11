<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers/view.php';

require_login();
$user = current_user();
if (!in_array((string) ($user['role'] ?? ''), ['admin', 'doctor', 'nurse'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Not authorized.']);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($query) < 2) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['patients' => []]);
    exit;
}

$like = '%' . $query . '%';
$stmt = auth_db()->prepare("SELECT p.id, TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS name, a.email
    FROM people p
    INNER JOIN patients pt ON pt.person_id = p.id
    INNER JOIN accounts a ON a.person_id = p.id
    WHERE a.account_status = 'active'
      AND a.email IS NOT NULL
      AND TRIM(a.email) <> ''
      AND (CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) LIKE ? OR a.email LIKE ?)
    ORDER BY name
    LIMIT 30");
$stmt->execute([$like, $like]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['patients' => array_map(static fn(array $patient): array => [
    'id' => (int) $patient['id'],
    'name' => (string) ($patient['name'] ?: 'Patient'),
    'email' => (string) $patient['email'],
], $stmt->fetchAll() ?: [])], JSON_UNESCAPED_UNICODE);
