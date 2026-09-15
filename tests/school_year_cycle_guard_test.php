<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/ApeCycleService.php';

if (!can_start_new_school_year(['status' => 'Closed'])) {
    throw new RuntimeException('A closed APE cycle must allow the new-school-year action.');
}

foreach ([null, [], ['status' => 'Active'], ['status' => 'Archived']] as $cycle) {
    if (can_start_new_school_year($cycle)) {
        throw new RuntimeException('The new-school-year action must be unavailable unless the current APE cycle is closed.');
    }
}

$settingsPage = file_get_contents(dirname(__DIR__) . '/public/settings/index.php');
if (!str_contains($settingsPage, '<?php if ($canStartNewSchoolYear): ?>')) {
    throw new RuntimeException('The settings page must hide Start New School Year until the current cycle is closed.');
}

$cycleService = file_get_contents(dirname(__DIR__) . '/app/services/ApeCycleService.php');
if (!str_contains($cycleService, 'if (!can_start_new_school_year($currentCycle))')) {
    throw new RuntimeException('The reset endpoint must enforce the closed-cycle requirement server-side.');
}

foreach ([
    'INNER JOIN students s ON s.person_id = a.person_id',
    "cliniq_notification_email('student_re_enrollment'",
    'Faculty, school personnel, clinic staff, and other patients remain active.',
] as $expected) {
    if (!str_contains($cycleService, $expected)) {
        throw new RuntimeException("The student-only school-year reset is missing: {$expected}");
    }
}

if (str_contains($cycleService, "SET a.account_status = 'inactive',\n            a.activated_at = NULL")) {
    throw new RuntimeException('The school-year reset must preserve activation history so students enter re-enrollment instead of first registration.');
}

foreach ([
    'active student patient accounts only',
    'Faculty, personnel, clinic staff, and other patient accounts remain active.',
    'Reset active student accounts?',
] as $expected) {
    if (!str_contains($settingsPage, $expected)) {
        throw new RuntimeException("The school-year reset explanation is missing: {$expected}");
    }
}

$patientLogin = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-login.php');
$patientDashboard = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-dashboard.php');
if (!str_contains((string) $patientLogin, "\$wasActivated && (\$patient['account_type'] ?? '') === 'student'")
    || str_contains((string) $patientDashboard, 'Confirm Employment')
    || str_contains((string) $patientDashboard, 'still employed')) {
    throw new RuntimeException('Only students should enter or see the school-year confirmation flow.');
}

echo "School year cycle guard test passed. No database writes.\n";
