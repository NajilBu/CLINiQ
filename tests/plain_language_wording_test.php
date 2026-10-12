<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$appointment = file_get_contents($root . '/app/services/AppointmentWorkflow.php');
$ape = file_get_contents($root . '/app/services/ApeWorkflow.php');
$feedback = file_get_contents($root . '/patient-portal/patient-feedback-consent.php');
$catalogue = file_get_contents($root . '/docs/plain-language-wording-catalogue.md');

foreach ([$appointment, $ape, $feedback, $catalogue] as $source) {
    if ($source === false) {
        throw new RuntimeException('Plain-language wording source is unreadable.');
    }
}

$checks = [
    'appointment display labels are patient-friendly' => str_contains($appointment, 'Visit record being completed by the clinic')
        && str_contains($appointment, 'Missed appointment'),
    'APE schedule and queue labels explain the next step' => str_contains($ape, 'Waiting for the clinic to schedule your examination')
        && str_contains($ape, 'Clinical clearance decision'),
    'feedback privacy explains the protected completion link' => str_contains($feedback, 'protected link only to record that this requirement was completed')
        && str_contains($feedback, 'Data Privacy Act of 2012 (RA 10173)'),
    'approved wording catalogue is present' => str_contains($catalogue, 'Clinic access pending APE completion')
        && str_contains($catalogue, 'Scheduled examination group'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Plain-language wording check failed: ' . $label);
    }
}

echo 'Plain-language wording checks passed (' . count($checks) . " assertions).\n";
