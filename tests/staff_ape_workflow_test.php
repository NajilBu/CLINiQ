<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/ApeWorkflow.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$awaiting = [
    'entry_mode' => 'Clinic Manual',
    'employee_role_classification' => 'Faculty',
    'workflow_status' => 'Registered',
    'clearance_status' => 'Pending',
];
$submitted = array_replace($awaiting, ['employee_role_classification' => 'Non-Teaching Personnel', 'workflow_status' => 'Submitted']);
$manualNonStaff = array_replace($awaiting, ['employee_role_classification' => '']);

$assert(ape_is_staff_managed_record($awaiting), 'Faculty clinic-manual records must use the staff APE lane.');
$assert(ape_is_staff_managed_record($submitted), 'NTP clinic-manual records must use the staff APE lane.');
$assert(!ape_is_staff_managed_record($manualNonStaff), 'Unclassified clinic-manual records must not enter the staff APE lane.');
$assert(ape_record_queue($awaiting) === 'staff_awaiting_certificate', 'Opened staff APEs must wait only for a medical certificate.');
$assert(ape_record_queue($submitted) === 'staff_submitted', 'Submitted staff APEs must bypass examination and final decision queues.');
$assert(ape_next_action($awaiting)['label'] === 'Submit Medical Certificate', 'Staff queue must expose only certificate submission.');
$assert(ape_priority_badge($submitted)['label'] === 'Done', 'Submitted staff APEs must be complete in the clinic queue.');

$create = (string) file_get_contents(dirname(__DIR__) . '/public/ape/create.php');
$view = (string) file_get_contents(dirname(__DIR__) . '/public/ape/view.php');
$portal = (string) file_get_contents(dirname(__DIR__) . '/patient-portal/patient-ape-status.php');
$cycleService = (string) file_get_contents(dirname(__DIR__) . '/app/services/ApeCycleService.php');
$startCycle = substr($cycleService, (int) strpos($cycleService, 'function start_ape_cycle('), (int) strpos($cycleService, 'function close_ape_cycle(') - (int) strpos($cycleService, 'function start_ape_cycle('));
$assert(!str_contains($startCycle, 'populate_manual_ape_records_for_staff'), 'Starting an APE cycle must not automatically create Faculty/NTP APE records.');
$assert(!str_contains($cycleService, 'function populate_manual_ape_records_for_staff'), 'The retired staff auto-population path must not remain available.');
foreach ([
    "'Medical Certificate', 'Missing'",
    "'Clinic Manual', 'Not Checked', 'Registered', 'Pending', 0",
    "role_classification IN ('Faculty', 'Non-Teaching Personnel')",
] as $marker) {
    $assert(str_contains($create, $marker), 'Staff APE creation must remain Faculty/NTP-only and certificate-only.');
}
$assert(str_contains($create, 'patient_ids[]'), 'The staff APE form must support selecting multiple people.');
$assert(str_contains($create, 'NOT EXISTS ('), 'The staff APE form must exclude people who already have an APE record in the active cycle.');
$assert(str_contains($create, 'foreach ($patientIds as $patientId)'), 'All selected staff APE records must be created in one transaction.');
foreach (['submit_staff_medical_certificate', "workflow_status = 'Submitted'", 'Replaced staff medical certificate'] as $marker) {
    $assert(str_contains($view, $marker), 'Staff APE certificate submission must remain clinic-controlled and auditable.');
}
foreach (['Clinic-Controlled APE', 'Awaiting Medical Certificate', 'View Medical Certificate'] as $marker) {
    $assert(str_contains($portal, $marker), 'Faculty/NTP portal APE status must remain read-only and certificate-visible.');
}
$portalDocument = (string) file_get_contents(dirname(__DIR__) . '/patient-portal/patient-ape-document.php');
$assert(str_contains($portalDocument, "d.document_type = 'Medical Certificate'"), 'Faculty/NTP document access must be restricted to the medical certificate.');
$queue = (string) file_get_contents(dirname(__DIR__) . '/public/ape/index.php');
$assert(!str_contains($queue, 'Clinic-managed · No schedule'), 'Faculty/NTP queues must not show a redundant workflow context chip.');
$assert(str_contains($queue, 'ape-work-queue-primary-action--staff'), 'Faculty/NTP actions must reserve the student batch column and stay in the shared fourth slot.');
$assert(str_contains($queue, 'ape-work-queue-primary-action'), 'Student and staff queue actions must share the stable action slot.');

echo "Staff APE workflow checks passed.\n";
