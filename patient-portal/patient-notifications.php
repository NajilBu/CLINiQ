<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/PatientNotification.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$profile = student_current_profile();
if ($profile === null || !empty($profile['first_registration'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your patient session has expired.']);
    exit;
}

$patientPersonId = (int) ($profile['person_id'] ?? 0);
if ($patientPersonId < 1) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'A patient account is required.']);
    exit;
}

try {
    $db = auth_db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'mark_read') {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            if ($notificationId < 1) {
                throw new InvalidArgumentException('Choose a valid notification.');
            }
            patient_notification_mark_read($db, $patientPersonId, $notificationId);
        } elseif ($action === 'mark_all_read') {
            patient_notification_mark_all_read($db, $patientPersonId);
        } else {
            throw new InvalidArgumentException('Choose a valid notification action.');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'unread_count' => patient_notification_unread_count($db, $patientPersonId),
        'notifications' => patient_notification_recent($db, $patientPersonId),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Notifications are temporarily unavailable.']);
}
