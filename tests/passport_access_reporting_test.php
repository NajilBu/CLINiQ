<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$public = file_get_contents($root . '/public/emergency.php');
$passportCss = file_get_contents($root . '/public/assets/css/emergency-passport.css');
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
    'emergency dock uses concise report actions without inline styling' => str_contains($public, '<div class="emergency-action-dock" aria-label="Emergency report actions">') && str_contains($public, 'Report emergency') && str_contains($public, 'Send to clinic'),
    'emergency dock leaves scroll space for passport content' => str_contains(file_get_contents($root . '/public/assets/css/emergency-passport.css'), 'padding-bottom: max(7rem, calc(5.5rem + env(safe-area-inset-bottom)));'),
    'public access keeps a dedicated protected passport gate' => str_contains($public, 'class="passport-access-shell"') && str_contains($public, 'class="passport-access-form"'),
    'public access uses the shared square alert family' => str_contains($public, 'passport-access-alert is-danger') && !str_contains($public, 'rounded-2xl'),
    'public view and student preview share the clinical passport presentation' => str_contains($passportCss, 'Unified emergency passport: a calm clinical record') && str_contains($passportCss, '.passport-access-shell,') && str_contains($passportCss, 'border-radius: 0 !important;'),
    'reporting starts with location and keeps extra details optional' => str_contains($public, 'class="emergency-report-sheet"') && str_contains($public, 'class="emergency-report-optional"') && str_contains($public, 'Where is help needed?'),
    'report sheet supports focus return and escape dismissal' => str_contains($public, 'function setReportOpen') && str_contains($public, "event.key === 'Escape'") && str_contains($public, 'locationInput.focus()'),
    'incident reporting requires an authenticated viewer' => str_contains($public, "(\$_POST['action'] ?? '') === 'incident_report'") && str_contains($public, 'value="incident_report"') && str_contains($public, '$viewerPersonId > 0'),
    'passport authentication supports every active account type' => !str_contains(file_get_contents($root . '/app/services/PassportAccess.php'), 'JOIN students'),
    'passport login formats viewer ID numbers' => str_contains($public, 'name="student_number" required placeholder="Your ID number" autocomplete="username" data-id-number-format'),
    'passport includes the complete emergency profile' => str_contains($public, 'passport-modern-info-allergy') && str_contains($public, 'passport-modern-info-bmi') && str_contains($public, 'passport-modern-contact'),
    'public passport respects body measurements visibility preference' => str_contains($public, "if (\$latestVitals && (int) (\$patient['show_bmi_on_passport'] ?? 1) === 1)") && substr_count($public, "show_bmi_on_passport'] ?? 1") === 1,
    'only location is required' => substr_count($public, 'name="location" required') === 1,
    'risk questions are optional' => !str_contains($public, 'name="incident_type" required'),
    'reporter urgency is only offered when classifiers are omitted' => str_contains($public, 'id="emergency-urgency-choice"') && str_contains($public, 'id="missing-risk-dialog"') && str_contains($public, 'id="report-urgency-instead"'),
    'unclassified reports require an explicit decision before sending' => str_contains($public, 'send-without-risk-details') && str_contains($public, 'No risk classifier answers will be saved') && str_contains($public, 'data-risk-classifiers'),
    'emergency submission has an owned loading state' => str_contains($public, 'function setSubmitting') && str_contains($public, "'Sending report…'") && str_contains($public, "classList.add('is-loading')"),
    'incomplete reports support not assessed when no urgency is reported' => str_contains($classifier, "\$level = 'Not assessed'"),
    'reported urgency becomes the alert level when classifiers are omitted' => str_contains($classifier, "in_array(\$reportedUrgency, ['Low', 'Moderate', 'High', 'Critical'], true)") && str_contains($classifier, "\$level = \$reportedUrgency") && str_contains($public, "\$answers['reporter_risk_rating'] = \$reporterRiskRating"),
    'reported urgency is visibly distinguished from classifier answers' => str_contains($classifier, 'no clinical classifier answers were submitted') && str_contains(file_get_contents($root . '/public/alerts/view.php'), 'incident answers or reported urgency'),
    'clinic UI does not show a classifier score for reporter-selected urgency' => str_contains(file_get_contents($root . '/public/alerts/view.php'), '$usesReporterUrgency') && str_contains(file_get_contents($root . '/public/alerts/index.php'), "'Reported urgency'") && str_contains(file_get_contents($root . '/public/api/alerts.php'), 'reporter_risk_rating'),
    'clinic alert queue sorts by priority before recency' => str_contains(file_get_contents($root . '/public/alerts/index.php'), "CASE COALESCE(a.risk_level, 'Not assessed')") && str_contains(file_get_contents($root . '/public/alerts/index.php'), 'Highest urgency appears first'),
    'work queue treats high and critical alerts as urgent' => str_contains(file_get_contents($root . '/app/services/WorkflowAttention.php'), "['critical', 'high']"),
    'audit metadata uses the formatter risk-rating key' => str_contains($public, "'risk_rating' => \$reporterRiskRating"),
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

require_once $root . '/app/services/AlertWorkflow.php';
$reportedModerate = classify_reported_incident(['reporter_risk_rating' => 'Moderate']);
$unassessed = classify_reported_incident([]);
$clinicalModerate = classify_reported_incident(['pain_level' => '7']);
if (
    $reportedModerate['level'] !== 'Moderate'
    || $reportedModerate['score'] !== 0
    || !str_contains(implode(' ', $reportedModerate['reasons']), 'Reporter selected Moderate urgency')
    || $unassessed['level'] !== 'Not assessed'
    || $clinicalModerate['level'] !== 'Moderate'
) {
    fwrite(STDERR, "Passport alert classification behavior failed.\n");
    exit(1);
}

if (!str_contains(audit_log_metadata_summary(json_encode(['risk_rating' => 'Moderate'])), 'Reported urgency: Moderate')) {
    fwrite(STDERR, "Passport alert audit detail failed.\n");
    exit(1);
}

echo "Passport access/reporting test passed (" . count($assertions) . " assertions).\n";
