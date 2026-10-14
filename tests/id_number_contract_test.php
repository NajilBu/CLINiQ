<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/student_id.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(normalize_id_number('2300262') === '2300262', 'Seven-digit IDs must remain numeric in mixed contexts.');
$assert(normalize_student_id_number('2300262') === '23-00262', 'Student IDs must be formatted only in student contexts.');
$assert(normalize_student_id_number('1912345') === '19-12345', 'Student IDs from every year prefix must be formatted in student contexts.');
$assert(is_valid_id_number('19-12345') && is_valid_id_number('0000002'), 'Canonical student and numeric IDs must be valid.');
$assert(!is_valid_id_number('123-4567') && !is_valid_student_id_number('0000002') && !is_valid_student_id_number('00-00002'), 'Malformed and nonstudent IDs must be rejected in student contexts.');
$assert(visit_id_number_candidates('2300262') === ['2300262', '2300262'], 'Mixed lookup must not reinterpret a seven-digit ID as a student ID.');

$patientLogin = file_get_contents(__DIR__ . '/../patient-portal/patient-login.php') ?: '';
$passwordRecovery = file_get_contents(__DIR__ . '/../patient-portal/patient-forgot-password.php') ?: '';
$patientRegistration = file_get_contents(__DIR__ . '/../patient-portal/patient-register.php') ?: '';
$patientLayout = file_get_contents(__DIR__ . '/../patient-portal/includes/patient-layout.php') ?: '';
$assert(str_contains($patientLogin, 'normalize_id_number(trim($_POST[\'student_id\'] ?? \'\'))') && str_contains($patientLogin, 'is_valid_id_number($studentIdValue)') && str_contains($patientLogin, 'student_id_compact') && !str_contains($patientLogin, 'data-id-number-kind="student"'), 'Patient login must accept the mixed portal ID contract while preserving compact student input.');
$assert(str_contains($patientLogin, 'csrf_enforce_request();'), 'Patient login must enforce the CSRF token rendered by its form.');
$assert(str_contains($passwordRecovery, 'normalize_id_number(trim((string) ($_POST[\'id_number\'] ?? \'\')))') && str_contains($passwordRecovery, 'is_valid_id_number($idNumber)') && !str_contains($passwordRecovery, 'data-id-number-kind="student"'), 'Patient password recovery must accept the mixed portal ID contract.');
$assert(str_contains($patientRegistration, 'data-id-number-kind="student"'), 'Student registration must remain student-only.');
$assert(str_contains($patientLayout, 'EXISTS (SELECT 1 FROM students') && str_contains($patientLayout, 'EXISTS (SELECT 1 FROM school_employees') && str_contains($patientLayout, 'EXISTS (SELECT 1 FROM patients'), 'Portal lookup must keep student, employee, and patient account access.');
$assert(str_contains($patientLayout, 'visit_id_number_candidates($studentNumber, true)') && str_contains($patientLayout, 'WHERE p.id_number IN (?, ?)') && str_contains($patientLayout, 'ORDER BY CASE WHEN p.id_number = ? THEN 0 ELSE 1 END'), 'Patient login lookup must use the logbook exact-first compact-student fallback.');

$formatter = file_get_contents(__DIR__ . '/../public/assets/js/id-number-format.js') ?: '';
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261013_normalize_all_prefixed_person_ids.sql') ?: '';
$assert(!str_contains($formatter, '[A-Z]') && str_contains($formatter, 'const hasStudentHyphen') && str_contains($formatter, 'autoStudentFormat') && str_contains($formatter, 'digits.startsWith(\'00\')') && str_contains($formatter, 'student:') && str_contains($formatter, 'employee:'), 'Client formatter must preserve explicit student IDs in mixed contexts without treating a numeric employee ID as a student ID.');
$assert(str_contains($migration, "CONCAT('TEMP-ID-', p.id)") && str_contains($migration, 'CHECK (highest_required_id <= 9999999)'), 'The applied migration must retain its collision-safe normalization safeguards.');

echo "ID number contract tests passed ({$assertions} assertions).\n";
