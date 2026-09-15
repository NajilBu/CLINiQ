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
    'Students must submit their current enrollment status on their next login.',
    'Reset active student accounts?',
] as $expected) {
    if (!str_contains($settingsPage, $expected)) {
        throw new RuntimeException("The school-year reset explanation is missing: {$expected}");
    }
}

$patientLogin = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-login.php');
$patientDashboard = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-dashboard.php');
$authHelper = file_get_contents(dirname(__DIR__) . '/app/helpers/auth.php');
$productionSchema = file_get_contents(dirname(__DIR__) . '/database/production_schema.sql');
$declarationMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20260915_create_student_enrollment_declarations.sql');
if (!str_contains((string) $patientLogin, "\$wasActivated && (\$patient['account_type'] ?? '') === 'student'")
    || str_contains((string) $patientDashboard, 'Confirm Employment')
    || str_contains((string) $patientDashboard, 'still employed')) {
    throw new RuntimeException('Only students should enter or see the school-year confirmation flow.');
}

foreach ([
    'Still Enrolled',
    'Not Currently Enrolled',
    'name="non_enrollment_reason"',
    'complete_re_enrollment($selectedEnrollmentStatus, $selectedNonEnrollmentReason)',
] as $expected) {
    if (!str_contains((string) $patientDashboard, $expected)) {
        throw new RuntimeException("The student enrollment declaration form is missing: {$expected}");
    }
}

if (preg_match('/<textarea[^>]*name=["\'](?:explanation|reason)["\']/i', (string) $patientDashboard)
    || preg_match('/<input[^>]*type=["\']file["\']/i', (string) $patientDashboard)) {
    throw new RuntimeException('The enrollment declaration must not request an explanation or supporting file.');
}

foreach ([
    'INSERT INTO student_enrollment_declarations',
    "SET a.account_status = 'active'",
    "['Leave of Absence', 'Graduated', 'Transferred', 'Withdrawn', 'Other']",
] as $expected) {
    if (!str_contains((string) $authHelper, $expected)) {
        throw new RuntimeException("The immediate student reactivation workflow is missing: {$expected}");
    }
}

foreach ([$productionSchema, $declarationMigration] as $schemaSource) {
    if (!str_contains((string) $schemaSource, 'student_enrollment_declarations')
        || !str_contains((string) $schemaSource, 'non_enrollment_reason')) {
        throw new RuntimeException('The enrollment declaration database schema is incomplete.');
    }
}

echo "School year cycle guard test passed. No database writes.\n";
