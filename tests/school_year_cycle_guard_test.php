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
if (next_school_year_from_cycle(['academic_year' => '2026-2027']) !== '2027-2028'
    || school_year_default_promotion('1') !== '2'
    || school_year_default_promotion('3') !== '4'
    || school_year_default_promotion('4') !== '4'
    || school_year_default_promotion('4', true) !== 'graduated') {
    throw new RuntimeException('School-year progression defaults are incorrect.');
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
    'INNER JOIN students s ON s.person_id = p.id',
    "cliniq_notification_email('student_re_enrollment'",
    'Faculty, school personnel, clinic staff, and other patients remain active.',
    'student_school_year_enrollments',
    'graduation_clearances',
    'Student promotion for school year',
] as $expected) {
    if (!str_contains($cycleService, $expected)) {
        throw new RuntimeException("The student-only school-year reset is missing: {$expected}");
    }
}

if (str_contains($cycleService, "SET a.account_status = 'inactive',\n            a.activated_at = NULL")) {
    throw new RuntimeException('The school-year reset must preserve activation history so students enter re-enrollment instead of first registration.');
}

if (!str_contains($cycleService, "if (\$target === 'graduated' && (!\$graduationCleared")
    || !str_contains($cycleService, "\$notificationPatients[] = \$student;")) {
    throw new RuntimeException('Graduation must require clearance and skip the re-enrollment email path.');
}
if (($pendingCheck = strpos($cycleService, "\$previousPending->execute([\$personId, (string) \$currentCycle['academic_year']]);")) === false
    || ($snapshot = strpos($cycleService, "\$snapshotYear->execute([\$personId, \$previousAcademicYear")) === false
    || $pendingCheck >= $snapshot
    || !str_contains($cycleService, 'INSERT IGNORE INTO student_school_year_enrollments')) {
    throw new RuntimeException('The prior pending state must be read before a missing-history snapshot is created.');
}

if (substr_count($cycleService, "pt.access_status = 'Official'") < 2
    || !str_contains($cycleService, "pt.access_status = 'Applicant'")
    || !str_contains($cycleService, "'excluded_applicants' =>")
    || !str_contains($cycleService, "'confirmation_required' =>")) {
    throw new RuntimeException('Only active Official students may enter the reset cohort; Applicants must be reported as excluded.');
}

foreach ([
    'active student patient accounts only',
    'Faculty, personnel, clinic staff, and other patient accounts remain active.',
    'Continuing students must submit their current enrollment status on their next login;',
    'Review Student Promotion',
    'data-promotion-year',
    'Confirm New School Year',
] as $expected) {
    if (!str_contains($settingsPage, $expected)) {
        throw new RuntimeException("The school-year reset explanation is missing: {$expected}");
    }
}

$patientLogin = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-login.php');
$patientDashboard = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-dashboard.php');
$patientLayout = file_get_contents(dirname(__DIR__) . '/patient-portal/includes/patient-layout.php');
$patientNotifications = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-notifications.php');
$patientNfcAuthorize = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-nfc-authorize.php');
$patientAppointment = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-appointment.php');
$patientPassport = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-passport.php');
$authHelper = file_get_contents(dirname(__DIR__) . '/app/helpers/auth.php');
$productionSchema = file_get_contents(dirname(__DIR__) . '/database/production_schema.sql');
$declarationMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20260915_create_student_enrollment_declarations.sql');
$schoolYearMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20260916_create_student_school_year_enrollments.sql');
if (!str_contains((string) $patientLogin, "\$wasActivated && \$schoolYearReset && (\$patient['account_type'] ?? '') === 'student'")
    || !str_contains((string) $patientLogin, "'New school year enrollment status required'")
    || str_contains((string) $patientDashboard, 'Confirm Employment')
    || str_contains((string) $patientDashboard, 'still employed')) {
    throw new RuntimeException('Only students should enter or see the school-year confirmation flow.');
}

foreach ([
    'Still Enrolled',
    'Not Currently Enrolled',
    'name="non_enrollment_reason"',
    'complete_re_enrollment($selectedEnrollmentStatus, $selectedNonEnrollmentReason,',
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
foreach ([$productionSchema, $schoolYearMigration] as $schemaSource) {
    if (!str_contains((string) $schemaSource, 'student_school_year_enrollments')
        || !str_contains((string) $schemaSource, 'promotion_source')
        || !str_contains((string) $schemaSource, 'uq_student_school_year')) {
        throw new RuntimeException('The student school-year history schema is incomplete.');
    }
}
if (!str_contains((string) $authHelper, 'UPDATE student_school_year_enrollments')
    || !str_contains((string) $authHelper, 'function student_enrollment_is_confirmed')) {
    throw new RuntimeException('Enrollment confirmation must update history and provide the shared appointment assertion.');
}
if (!str_contains((string) $authHelper, 'e.academic_year COLLATE utf8mb4_unicode_ci = s.academic_year COLLATE utf8mb4_unicode_ci')
    || !str_contains((string) $authHelper, '$status === null')) {
    throw new RuntimeException('The shared student enrollment check must use a common collation for academic years.');
}
foreach ([
    'dashboard' => [(string) $patientDashboard, '$profile = student_require_login();', 'ensure_appointment_schema();'],
    'appointments' => [(string) $patientAppointment, '$profile = student_require_official_access(\'Appointment booking\');', 'ensure_appointment_schema();'],
    'passport' => [(string) $patientPassport, '$profile = student_require_official_access(\'Health Passport\');', 'ensure_alert_workflow_schema();'],
] as $route => [$source, $guard, $sideEffect]) {
    $guardPosition = strpos($source, $guard);
    $sideEffectPosition = strpos($source, $sideEffect);
    if ($guardPosition === false || $sideEffectPosition === false || $guardPosition >= $sideEffectPosition) {
        throw new RuntimeException("The {$route} enrollment guard must run before schema or appointment side effects.");
    }
}
if (!str_contains($cycleService, "'Pending Confirmation'")
    || !str_contains($cycleService, 'Enrollment was not confirmed for two consecutive school years.')
    || !str_contains((string) $patientDashboard, 'complete_active_enrollment_confirmation')) {
    throw new RuntimeException('Pending enrollment must keep the first cycle active and deactivate only on the second consecutive cycle.');
}
if (!str_contains((string) $patientLayout, 'function student_enrollment_confirmation_required')
    || !str_contains((string) $patientLayout, 'student_redirect_pending_enrollment_confirmation()')
    || !str_contains((string) $patientLayout, "'patient-dashboard.php', 'patient-login.php'")
    || !str_contains((string) $patientDashboard, "render_student_auth_header('Enrollment confirmation required')")
    || !str_contains((string) $patientDashboard, 'name="_csrf"')
    || !str_contains((string) $patientNotifications, 'http_response_code(403)')
    || !str_contains((string) $patientNfcAuthorize, 'Enrollment confirmation is required')) {
    throw new RuntimeException('Pending active students must be locked to the enrollment checkpoint until they submit a response.');
}

$repairScript = file_get_contents(dirname(__DIR__) . '/scripts/database/repair_current_official_enrollments.php');
if ($repairScript === false
    || !str_contains($repairScript, "const REPAIR_ACADEMIC_YEAR = '2026-2027';")
    || !str_contains($repairScript, "const EXPECTED_CANDIDATES = 2;")
    || !str_contains($repairScript, "'Administrative Repair'")
    || !str_contains($repairScript, "'Not Currently Enrolled'")
    || !str_contains($repairScript, "TRIM(COALESCE(s.academic_year, '')) = ''")
    || !str_contains($repairScript, "e.promotion_source = 'Administrative Repair'")
    || !str_contains($repairScript, "UPDATE students SET academic_year = ?")
    || !str_contains($repairScript, "current_official_enrollment_reconciled")
    || !str_contains($repairScript, '--apply')
    || !str_contains($repairScript, 'audit_log_event')) {
    throw new RuntimeException('The current-year Official enrollment repair must remain opt-in, bounded, and auditable.');
}

$migrationRunner = file_get_contents(dirname(__DIR__) . '/scripts/database/migrate.php');
$entrypoint = file_get_contents(dirname(__DIR__) . '/docker/entrypoint.sh');
$compose = file_get_contents(dirname(__DIR__) . '/compose.yaml');
$proof = file_get_contents(dirname(__DIR__) . '/tests/student_enrollment_checkpoint_e2e_test.php');
if ($migrationRunner === false || $entrypoint === false || $compose === false || $proof === false
    || !str_contains($migrationRunner, "['--check', '--apply']")
    || !str_contains($migrationRunner, 'Pending migrations require explicit --apply')
    || !str_contains($entrypoint, 'migrate.php --check')
    || !str_contains($compose, 'migrate.php", "--apply')
    || !str_contains($proof, 'ENROLLMENT_CHECKPOINT_E2E')) {
    throw new RuntimeException('Migration release gating and isolated enrollment checkpoint proof must remain available.');
}

echo "School year cycle guard test passed. No database writes.\n";
