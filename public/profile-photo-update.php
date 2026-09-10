<?php

require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/services/ProfilePhoto.php';

require_login();
$user = current_user() ?? [];
$personId = (int) ($user['person_id'] ?? $user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . app_url('dashboard.php'));
    exit;
}

try {
    $path = save_profile_photo_upload($_FILES['profile_photo'] ?? [], $personId);
    $_SESSION['user']['profile_photo_path'] = $path;
    audit_log_event('profile', 'staff_profile_photo_updated', $personId, 'staff', 'person', $personId);
    flash_message('success', 'Your profile picture has been updated.');
} catch (Throwable $e) {
    flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
}

$returnTo = trim((string) ($_POST['return_to'] ?? 'dashboard.php'));
if ($returnTo === '' || str_contains($returnTo, '://') || str_starts_with($returnTo, '//') || str_contains($returnTo, '..')) {
    $returnTo = 'dashboard.php';
}
header('Location: ' . app_url(ltrim($returnTo, '/')));
exit;
