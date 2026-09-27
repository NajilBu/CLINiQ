<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'This action requires a POST request.']);
    exit;
}

$profile = student_require_login();
if (!patient_has_official_access($profile)) {
    http_response_code(403);
    echo json_encode(['error' => 'Health Passport access is unavailable for this account.']);
    exit;
}

if (!csrf_request_is_valid()) {
    http_response_code(419);
    echo json_encode(['error' => 'This page has expired. Reload it and try again.']);
    exit;
}

$password = $_POST['password'] ?? null;
if (!is_string($password) || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Enter your current password.']);
    exit;
}

try {
    $db = auth_db();
    $identity = (string) ($profile['id_number'] ?? '');
    auth_throttle_assert_allowed($db, 'patient_nfc', $identity);
    if (!student_password_is_valid($profile, $password)) {
        auth_throttle_record_failure($db, 'patient_nfc', $identity);
        http_response_code(401);
        echo json_encode(['error' => 'Incorrect password. The NFC tag was not changed.']);
        exit;
    }

    auth_throttle_clear($db, 'patient_nfc', $identity);
    http_response_code(204);
} catch (LoginThrottleException) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many attempts. Wait 15 minutes before trying again.']);
} catch (Throwable $error) {
    error_log('NFC overwrite authorization: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Password verification is temporarily unavailable. Try again later.']);
}
