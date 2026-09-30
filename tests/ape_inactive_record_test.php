<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/app/services/ApeWorkflow.php');
$staffView = file_get_contents($root . '/public/ape/view.php');
$patientView = file_get_contents($root . '/patient-portal/patient-ape-status.php');

if ($workflow === false || $staffView === false || $patientView === false) {
    throw new RuntimeException('APE inactive-record sources must be readable.');
}

foreach (['function ape_record_is_inactive', "return (\$record['workflow_status'] ?? '') === 'Inactive';", "ar.workflow_status <> 'Inactive'", 'if (ape_record_is_inactive($record))'] as $marker) {
    if (!str_contains($workflow, $marker)) throw new RuntimeException("Missing APE inactive guard: {$marker}");
}
foreach (["name=\"action\" value=\"mark_ape_inactive\"", "name=\"inactive_reason\"", "name=\"action\" value=\"reopen_ape_record\"", "workflow_status = 'Inactive'", "workflow_status = 'Registered'"] as $marker) {
    if (!str_contains($staffView, $marker)) throw new RuntimeException("Missing staff inactive control: {$marker}");
}
foreach (['ape_record_is_inactive($apeRecord)', 'Your APE record is currently inactive', 'Need help continuing?'] as $marker) {
    if (!str_contains($patientView, $marker)) throw new RuntimeException("Missing patient inactive protection: {$marker}");
}

echo "APE inactive-record coverage test passed.\n";
