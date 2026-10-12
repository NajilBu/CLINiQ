<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/app/services/ApeWorkflow.php');
$cycle = (string) file_get_contents($root . '/app/services/ApeCycleService.php');
$staff = (string) file_get_contents($root . '/public/ape/view.php');
$student = (string) file_get_contents($root . '/patient-portal/patient-ape-status.php');
$dashboard = (string) file_get_contents($root . '/patient-portal/patient-dashboard.php');
$schema = (string) file_get_contents($root . '/database/production_schema.sql');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($workflow, 'ac.status AS cycle_status'), 'APE records must expose cycle status.');
$assert(str_contains($workflow, "\$conditions[] = '1 = 0';"), 'APE operational queues must be empty when no cycle is active.');
$assert(str_contains($workflow, 'function ape_assert_record_mutation_allowed'), 'APE mutations need one shared cycle guard.');
$assert(str_contains($staff, 'ape_assert_record_mutation_allowed($record);'), 'Staff POST updates must use the shared cycle guard.');
$assert(str_contains($student, 'ape_assert_record_mutation_allowed($apeRecord);'), 'Student upload POST must use the shared cycle guard.');
$assert(str_contains($cycle, "status = 'Active' FOR UPDATE"), 'Batch changes must lock an active cycle.');
$assert(str_contains($workflow, 'function ape_add_record_amendment'), 'Historical amendments require an append-only service.');
$assert(str_contains($workflow, "['Closed', 'Archived']"), 'Historical amendments must be limited to closed or archived cycles.');
$assert(str_contains($schema, 'CREATE TABLE ape_record_amendments'), 'Production schema must include APE amendments.');
$assert(str_contains($schema, 'CREATE TABLE student_enrollment_correction_requests'), 'Production schema must include correction requests.');
$assert(str_contains($dashboard, 'request_enrollment_correction'), 'The dashboard must expose placement correction requests.');
$assert(!str_contains($dashboard, 'if ($enrollmentPending) {\n    render_student_auth_header'), 'Pending enrollment must not render the legacy portal-blocking gate.');

echo "APE cycle history safety checks passed.\n";
