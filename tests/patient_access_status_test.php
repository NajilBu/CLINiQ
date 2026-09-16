<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/PatientAccessStatus.php');
$accounts = file_get_contents($root . '/app/services/PatientAccountService.php');
$accountPage = file_get_contents($root . '/public/patient-accounts/index.php');
$apeView = file_get_contents($root . '/public/ape/view.php');
$layout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');
$dashboard = file_get_contents($root . '/patient-portal/patient-dashboard.php');
$appointment = file_get_contents($root . '/patient-portal/patient-appointment.php');
$passport = file_get_contents($root . '/patient-portal/patient-passport.php');
$passportAccess = file_get_contents($root . '/app/services/PassportAccess.php');
$emergency = file_get_contents($root . '/public/emergency.php');
$clinicShell = file_get_contents($root . '/app/helpers/view.php');
$settingsPage = file_get_contents($root . '/public/settings/index.php');
$schema = file_get_contents($root . '/database/production_schema.sql');
$migration = file_get_contents($root . '/database/migrations/20260916_add_patient_access_status.sql');
$studentOnlyMigration = file_get_contents($root . '/database/migrations/20260916_student_only_applicant_status.sql');

foreach ([$service, $accounts, $accountPage, $apeView, $layout, $dashboard, $appointment, $passport, $passportAccess, $emergency, $clinicShell, $settingsPage, $schema, $migration, $studentOnlyMigration] as $source) {
    if ($source === false) {
        throw new RuntimeException('Patient access status test source could not be read.');
    }
}

foreach ([$schema, $migration] as $source) {
    if (!str_contains($source, "access_status ENUM('Applicant', 'Official') NOT NULL DEFAULT 'Official'")) {
        throw new RuntimeException('Existing patients must remain Official when the access-status schema is introduced.');
    }
}

foreach ([
    "patient_access_status_normalize(\$input['access_status'] ?? 'Applicant')",
    'INSERT INTO patients (person_id, emergency_token, token_enabled, access_status)',
    'function change_patient_access_status(',
    'pt.access_status',
] as $expected) {
    if (!str_contains($accounts, $expected)) {
        throw new RuntimeException("Patient account creation or management is missing: {$expected}");
    }
}

if (!str_contains($clinicShell, "'Patient Accounts' => ['url' => app_url('patient-accounts/index.php')")
    || str_contains($settingsPage, '<span>Patient Accounts</span>')) {
    throw new RuntimeException('Patient Accounts must be located in the main clinic sidebar, not the Settings submenu.');
}

if (!str_contains($service, "\$current === 'Official' && \$status === 'Applicant'")
    || !str_contains($service, 'An Official student cannot be changed back to Applicant.')
    || !str_contains($service, 'Applicant access is available only to students.')
    || !str_contains($accountPage, "if (\$currentAccess === 'Applicant')")
    || str_contains($accountPage, '>No action<')) {
    throw new RuntimeException('Official student access must be a one-way status in both the service and account controls.');
}

if (!str_contains($accounts, "\$accessStatus = \$type === 'student'")
    || !str_contains($accountPage, "importType === 'student' ? String(row.access_status || '').trim() : 'Official'")
    || !str_contains($accountPage, 'Only students may be Applicants.')
    || !str_contains($studentOnlyMigration, "pt.access_status = 'Applicant'")) {
    throw new RuntimeException('Applicant status must be restricted to students during creation, import, and migration.');
}

foreach ([
    'name="access_status"',
    'Applicant — APE and records only',
    'Official — passport and appointments enabled',
    "const requiredColumns = ['id_number', 'patient_type', 'access_status'",
    'data-recent-access-status-filter',
    'change_access_status',
] as $expected) {
    if (!str_contains($accountPage, $expected)) {
        throw new RuntimeException("Applicant/Official account UI is missing: {$expected}");
    }
}

if (substr_count($apeView, 'patient_access_promote_after_ape_clearance(') !== 2) {
    throw new RuntimeException('Both direct final clearance and follow-up clearance must promote Applicants to Official.');
}

foreach ([
    "\$profile = student_require_official_access('Appointment booking');" => $appointment,
    "\$profile = student_require_official_access('Health Passport');" => $passport,
    "function student_require_official_access(" => $layout,
    "unset(\$items['appointment'], \$items['passport'])" => $layout,
    'Applicant access' => $dashboard,
    "pt.access_status = \\'Official\\'" => $passportAccess,
    "pt.access_status = 'Official'" => $emergency,
] as $expected => $source) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException("Applicant portal restriction is missing: {$expected}");
    }
}

foreach ([
    'function patient_access_status_set(',
    'patient_notification_create(',
    "'patient_access_promoted'",
    "'patient_access_changed_to_applicant'",
] as $expected) {
    if (!str_contains($service, $expected)) {
        throw new RuntimeException("Patient access lifecycle behavior is missing: {$expected}");
    }
}

echo "Patient Applicant/Official access lifecycle test passed. No database writes.\n";
