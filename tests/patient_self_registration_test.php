<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/PatientRegistrationService.php');
$page = file_get_contents($root . '/patient-portal/patient-register.php');
$onboarding = file_get_contents($root . '/patient-portal/patient-onboarding.php');
$layout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');
$login = file_get_contents($root . '/patient-portal/patient-login.php');
$schema = file_get_contents($root . '/database/production_schema.sql');
$migration = file_get_contents($root . '/database/migrations/20260916_create_patient_registration_verifications.sql');
$verifyStart = strpos($service, 'function verify_patient_registration_code(');
$completeStart = strpos($service, 'function patient_registration_verified_onboarding_context(');
$verificationBody = ($verifyStart !== false && $completeStart !== false) ? substr($service, $verifyStart, $completeStart - $verifyStart) : '';
foreach ([$service, $page, $onboarding, $layout, $login, $schema, $migration] as $source) {
    if ($source === false) throw new RuntimeException('Patient self-registration source could not be read.');
}
$checks = [
    'student number and confirmed email precede profile setup' => str_contains($page, 'name="student_number"') && str_contains($page, 'name="email_confirmation"') && str_contains($page, "'verify_code'") && str_contains($onboarding, "'complete_registration'"),
    'email verification gates completion' => str_contains($service, "empty(\$verification['verified_at'])") && str_contains($service, 'password_verify'),
    'codes expire and limit attempts' => str_contains($service, 'CLINIQ_PATIENT_REGISTRATION_CODE_MINUTES = 15') && str_contains($service, 'CLINIQ_PATIENT_REGISTRATION_MAX_ATTEMPTS = 5'),
    'requests are throttled' => str_contains($service, 'CLINIQ_PATIENT_REGISTRATION_MAX_REQUESTS = 3') && str_contains($service, 'requested_ip'),
    'duplicates are blocked' => str_contains($service, 'patient_registration_assert_identity_available') && str_contains($service, 'LOWER(email) = ?'),
    'account is active Applicant' => str_contains($service, "VALUES (?, ?, ?, 'active'") && str_contains($service, "'access_status' => 'Applicant'"),
    'active APE cycle is assigned' => str_contains($service, "FROM ape_cycles WHERE status = 'Active'") && str_contains($service, 'ape_seed_default_requirements'),
    'CSRF is enforced' => str_contains($page, 'csrf_enforce_request()') && str_contains($onboarding, 'csrf_enforce_request()') && str_contains($onboarding, 'name="_csrf"'),
    'login links to registration' => str_contains($login, 'href="patient-register.php?start=1"') && str_contains($login, "\$_GET['registered']"),
    'schema and migration contain verification table' => str_contains($schema, 'CREATE TABLE patient_registration_verifications') && str_contains($migration, 'CREATE TABLE IF NOT EXISTS patient_registration_verifications'),
    'verified onboarding has an authoritative expiry guard' => str_contains($service, 'patient_registration_verified_onboarding_context') && str_contains($layout, 'student_verified_onboarding_context') && str_contains($layout, 'student_redirect_pending_onboarding'),
    'verification and account creation rotate separate sessions' => str_contains($page, 'student_begin_verified_onboarding') && str_contains($layout, 'session_regenerate_id(true)') && str_contains($onboarding, 'student_upgrade_verified_onboarding_session') && str_contains($onboarding, "Location: patient-dashboard.php"),
    'restricted shell preserves recorded sex without redundant navigation' => str_contains($onboarding, 'name="sex"') && str_contains($onboarding, 'student-onboarding-start-over') && !str_contains($onboarding, 'student-nav') && !str_contains($onboarding, 'patient-notifications.php'),
    'setup account wording, single-card layout, step flow, and mobile shell are explicit' => str_contains($onboarding, 'Verified Setup Account') && str_contains($onboarding, 'setup account is signed in') && str_contains($onboarding, 'normal Applicant account will be created after setup') && str_contains($onboarding, 'student-onboarding-start-over') && str_contains($onboarding, 'student-onboarding-account-note') && str_contains($onboarding, 'student-onboarding-legal') && str_contains($onboarding, 'student-onboarding-identity') && str_contains($onboarding, 'data-onboarding-progress') && str_contains($onboarding, 'student-onboarding-step-actions') && str_contains($onboarding, 'student-onboarding-shell') && !str_contains($onboarding, 'Onboarding navigation'),
    'email verification creates no provisional database records' => $verificationBody !== '' && !str_contains($verificationBody, 'INSERT INTO people') && !str_contains($verificationBody, 'INSERT INTO accounts') && !str_contains($verificationBody, 'INSERT INTO patients') && !str_contains($verificationBody, 'INSERT INTO students') && !str_contains($verificationBody, 'INSERT INTO ape_records'),
    'login and start over preserve or clear setup deliberately' => str_contains($layout, "header('Location: patient-onboarding.php')") && str_contains($page, "unset(\$_SESSION['patient_registration'], \$_SESSION['patient_onboarding'])"),
];
$failures = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failures !== []) {
    fwrite(STDERR, "Patient self-registration test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'Patient self-registration test passed (' . count($checks) . " assertions).\n";
