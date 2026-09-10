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
    'passport accepts the authenticated viewer account' => str_contains($public, 'your active CLINiQ account') && !str_contains($public, "!== (int) \$patient['person_id']"),
    'passport is the primary scan view' => str_contains($public, 'Emergency Health Passport') && str_contains($public, 'passport-modern-name'),
    'emergency form is behind a dedicated button' => str_contains($public, 'id="toggle-emergency-report"') && str_contains($public, 'id="emergency-report-panel"'),
    'emergency button controls the report panel accessibly' => str_contains($public, 'aria-controls="emergency-report-panel"') && str_contains($public, "button.setAttribute('aria-expanded'"),
    'incident reporting requires an authenticated viewer' => str_contains($public, "(\$_POST['action'] ?? '') === 'incident_report'") && str_contains($public, 'value="incident_report"') && str_contains($public, '$viewerPersonId > 0'),
    'passport authentication supports every active account type' => !str_contains(file_get_contents($root . '/app/services/PassportAccess.php'), 'JOIN students'),
    'passport login formats viewer ID numbers' => str_contains($public, 'name="student_number" required placeholder="Your ID number" autocomplete="username" data-id-number-format'),
    'passport includes the complete emergency profile' => str_contains($public, 'passport-modern-info-allergy') && str_contains($public, 'passport-modern-info-bmi') && str_contains($public, 'passport-modern-contact'),
    'public passport respects BMI visibility preference' => str_contains($public, "show_bmi_on_passport'] ?? 1"),
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
