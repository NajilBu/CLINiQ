<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [];
foreach ([
    'security' => 'app/helpers/security.php',
    'csrf_client' => 'public/assets/js/csrf.js',
    'auth' => 'app/helpers/auth.php',
    'patient_login' => 'patient-portal/patient-login.php',
    'schema' => 'database/production_schema.sql',
    'env' => 'app/config/env.php',
    'diagnostic' => 'check.php',
    'readme' => 'README.md',
] as $key => $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    if ($contents === false) throw new RuntimeException("Unreadable hardening source: {$relative}");
    $sources[$key] = $contents;
}

foreach (['csrf_enforce_request', 'hash_equals', 'HTTP_X_CSRF_TOKEN', "['_csrf']"] as $expected) {
    if (!str_contains($sources['security'], $expected)) throw new RuntimeException("Central CSRF protection is missing {$expected}.");
}
foreach (['MutationObserver', 'X-CSRF-Token', 'XMLHttpRequest.prototype.send', 'window.fetch'] as $expected) {
    if (!str_contains($sources['csrf_client'], $expected)) throw new RuntimeException("CSRF browser coverage is missing {$expected}.");
}
foreach (['auth_throttle_assert_allowed', 'auth_throttle_record_failure', 'auth_throttle_clear'] as $expected) {
    if (!str_contains($sources['auth'] . $sources['patient_login'], $expected)) throw new RuntimeException("Login throttling is missing {$expected}.");
}
if (!str_contains($sources['security'], ">= 5") || !str_contains($sources['security'], ">= 25")) throw new RuntimeException('School-network throttle thresholds changed unexpectedly.');
if (!str_contains($sources['schema'], 'CREATE TABLE login_attempts')) throw new RuntimeException('Production schema lacks login throttling storage.');
if (!str_contains($sources['env'], 'validate_production_environment')) throw new RuntimeException('Production environment is not fail-closed.');
if (!str_contains($sources['diagnostic'], "PHP_SAPI !== 'cli'") || str_contains($sources['diagnostic'], '$db = db()')) throw new RuntimeException('Diagnostic must be CLI-only and use the primary database.');
if (str_contains($sources['readme'], 'admin@cliniq.local') || str_contains($sources['readme'], 'Password: `password`')) throw new RuntimeException('README still advertises default credentials.');
if (str_contains(file_get_contents($root . '/patient-portal/includes/patient-layout.php'), 'STUDENT_DEMO_PASSWORD')) throw new RuntimeException('Patient portal still contains a demo password.');
$passportPreview = file_get_contents($root . '/patient-portal/passport-demo.php');
if (str_contains($passportPreview, 'Sofia') || str_contains($passportPreview, 'REQUEST_METHOD')) throw new RuntimeException('Passport preview still contains a public sample workflow.');

echo "Security hardening source test passed.\n";
