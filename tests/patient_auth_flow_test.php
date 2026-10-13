<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pages = [
    'patient-login.php',
    'patient-forgot-password.php',
    'patient-reset-password.php',
    'patient-register.php',
    'patient-onboarding.php',
];
$css = file_get_contents($root . '/patient-portal/assets/css/patient.css');
$layout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');

if ($css === false || $layout === false) {
    throw new RuntimeException('Unable to read patient account-flow source.');
}

foreach ($pages as $page) {
    $source = file_get_contents($root . '/patient-portal/' . $page);
    if ($source === false || !str_contains($source, 'render_student_auth_header') || !str_contains($source, 'student-auth-shell')) {
        throw new RuntimeException("{$page} must use the shared patient account shell.");
    }
}

foreach ([
    '.student-auth-shell:not(.student-onboarding-shell)',
    '.student-portal-legal-footer',
    '.student-password-dialog',
    '@media (max-width: 720px)',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("Shared account-flow styling is missing {$marker}.");
    }
}

foreach ([
    "\$_POST['action'] ?? '') === 'change_patient_password'",
    'csrf_enforce_request();',
    'name="_csrf" value="<?= student_e(csrf_token()) ?>"',
    'student-password-dialog',
] as $marker) {
    if (!str_contains($layout, $marker)) {
        throw new RuntimeException("Change-password protection or design hook is missing {$marker}.");
    }
}

echo "Patient account flow test passed.\n";
