<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$welcome = file_get_contents($root . '/patient-portal/patient-welcome.php');
$gateway = file_get_contents($root . '/docker/public-gateway.conf');
$styles = file_get_contents($root . '/patient-portal/assets/css/patient.css');

foreach ([$welcome, $gateway, $styles] as $source) {
    if ($source === false) {
        throw new RuntimeException('Unable to read welcome-page source.');
    }
}

foreach (["student_e(\$clinicProfile['system_name']) ?> Patient Portal", 'student-welcome-header', 'student-welcome-access', 'student-welcome-services', 'Sign in to the portal', 'APE status', 'Appointments', 'Health Passport', 'student-welcome-checklist', 'student_current_profile()'] as $marker) {
    if (!str_contains($welcome, $marker)) {
        throw new RuntimeException('Welcome page is missing: ' . $marker);
    }
}

if (substr_count($gateway, 'return 302 /patient-portal/patient-welcome.php;') !== 3) {
    throw new RuntimeException('All public entry routes must resolve to the welcome page.');
}

foreach (['.student-welcome-header', '.student-welcome-hero', '.student-welcome-access', '.student-welcome-services', '.student-welcome-checklist', '@media (max-width: 640px)'] as $marker) {
    if (!str_contains($styles, $marker)) {
        throw new RuntimeException('Welcome page styling is incomplete: ' . $marker);
    }
}

echo "Patient welcome page test passed.\n";
