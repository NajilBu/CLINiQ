<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$public = file_get_contents($root . '/public/emergency.php');
$workflow = file_get_contents($root . '/app/services/AlertWorkflow.php');
$classifier = file_get_contents($root . '/app/services/AlertWorkflow.php');
$audit = file_get_contents($root . '/app/services/AuditLog.php');

$assertions = [
    'public route authenticates viewers' => str_contains($public, 'passport_authenticate_viewer'),
    'public route records viewer identity' => str_contains($public, 'viewer_person_id'),
    'only location is required' => substr_count($public, 'name="location" required') === 1,
    'risk questions are optional' => !str_contains($public, 'name="incident_type" required'),
    'reporter urgency is optional' => str_contains($public, 'name="reporter_risk_rating"'),
    'incomplete reports support not assessed' => str_contains($classifier, "\$level = 'Not assessed'"),
    'workflow stores reporter urgency' => str_contains($workflow, 'reporter_risk_rating'),
    'central audit service exists' => str_contains($audit, 'CREATE TABLE IF NOT EXISTS audit_logs'),
];

$failures = [];
foreach ($assertions as $label => $passed) {
    if (!$passed) {
        $failures[] = $label;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Passport access/reporting test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Passport access/reporting test passed (" . count($assertions) . " assertions).\n";
