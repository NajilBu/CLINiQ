<?php

require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/ProfilePhoto.php';

$profile = student_require_login();
$personId = (int) ($profile['person_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . student_portal_url('dashboard'));
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

$returnTo = (string) ($_POST['return_to'] ?? 'dashboard');
$allowed = ['dashboard', 'health-passport', 'ape-status', 'appointments'];
header('Location: ' . student_portal_url(in_array($returnTo, $allowed, true) ? $returnTo : 'dashboard'));
exit;
