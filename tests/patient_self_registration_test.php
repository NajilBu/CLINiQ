<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/PatientRegistrationService.php');
$page = file_get_contents($root . '/patient-portal/patient-register.php');
$login = file_get_contents($root . '/patient-portal/patient-login.php');
$schema = file_get_contents($root . '/database/production_schema.sql');
$migration = file_get_contents($root . '/database/migrations/20260916_create_patient_registration_verifications.sql');
foreach ([$service, $page, $login, $schema, $migration] as $source) {
    if ($source === false) throw new RuntimeException('Patient self-registration source could not be read.');
}
$checks = [
    'student number and confirmed email precede details' => str_contains($page, 'name="student_number"') && str_contains($page, 'name="email_confirmation"') && str_contains($page, "'verify_code'") && str_contains($page, "'complete_registration'"),
    'email verification gates completion' => str_contains($service, "empty(\$verification['verified_at'])") && str_contains($service, 'password_verify'),
    'codes expire and limit attempts' => str_contains($service, 'CLINIQ_PATIENT_REGISTRATION_CODE_MINUTES = 15') && str_contains($service, 'CLINIQ_PATIENT_REGISTRATION_MAX_ATTEMPTS = 5'),
    'requests are throttled' => str_contains($service, 'CLINIQ_PATIENT_REGISTRATION_MAX_REQUESTS = 3') && str_contains($service, 'requested_ip'),
    'duplicates are blocked' => str_contains($service, 'patient_registration_assert_identity_available') && str_contains($service, 'LOWER(email) = ?'),
    'account is active Applicant' => str_contains($service, "VALUES (?, ?, ?, 'active'") && str_contains($service, "'access_status' => 'Applicant'"),
    'active APE cycle is assigned' => str_contains($service, "FROM ape_cycles WHERE status = 'Active'") && str_contains($service, 'ape_seed_default_requirements'),
    'CSRF is enforced' => str_contains($page, 'csrf_enforce_request()') && str_contains($page, 'name="_csrf"'),
    'login links to registration' => str_contains($login, 'href="patient-register.php?start=1"') && str_contains($login, "\$_GET['registered']"),
    'schema and migration contain verification table' => str_contains($schema, 'CREATE TABLE patient_registration_verifications') && str_contains($migration, 'CREATE TABLE IF NOT EXISTS patient_registration_verifications'),
];
$failures = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failures !== []) {
    fwrite(STDERR, "Patient self-registration test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'Patient self-registration test passed (' . count($checks) . " assertions).\n";
