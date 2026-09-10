<?php

require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/ProfilePhoto.php';

$profile = student_require_login();
$personId = (int) ($profile['person_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: patient-dashboard.php');
    exit;
}

student_start_session();
try {
    save_profile_photo_upload($_FILES['profile_photo'] ?? [], $personId);
    audit_log_event('profile', 'patient_profile_photo_updated', $personId, 'student', 'person', $personId);
    $_SESSION['student_flash_success'] = 'Your profile picture has been updated.';
} catch (Throwable $e) {
    $_SESSION['student_flash_error'] = $e->getMessage();
}

$returnTo = basename((string) ($_POST['return_to'] ?? 'patient-dashboard.php'));
$allowed = ['patient-dashboard.php', 'patient-passport.php', 'patient-ape-status.php', 'patient-appointment.php'];
header('Location: ' . (in_array($returnTo, $allowed, true) ? $returnTo : 'patient-dashboard.php'));
exit;
