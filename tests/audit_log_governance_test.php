<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$audit = file_get_contents($root . '/public/audit/index.php');
$email = file_get_contents($root . '/app/services/PatientEmail.php');
$auditService = file_get_contents($root . '/app/services/AuditLog.php');
$view = file_get_contents($root . '/app/helpers/view.php');
$criticalSources = implode("\n", array_map(static fn(string $path): string => (string) file_get_contents($root . $path), [
    '/app/services/ApeWorkflow.php',
    '/app/services/PatientAccountService.php',
    '/app/services/CliniqVisitWorkflow.php',
    '/app/services/CliniqInventoryWorkflow.php',
    '/app/services/PassportAccess.php',
    '/public/settings/index.php',
    '/public/emergency.php',
    '/patient-portal/patient-passport.php',
]));

if ($audit === false || $email === false || $auditService === false || $view === false) {
    throw new RuntimeException('Unable to read audit-governance sources.');
}
foreach (["'Audit Log' =>", "'Email Center' =>", "'tab' => 'audit'", "'tab' => 'email'"] as $expected) {
    if (!str_contains($view, $expected)) {
        throw new RuntimeException("Governance navigation is missing {$expected}.");
    }
}

foreach (['audit_log_governance_excluded_actions', 'Apply filters', 'Choose dates to print', "'details' => \$details"] as $expected) {
    if (!str_contains($audit, $expected)) {
        throw new RuntimeException("Audit governance UI is missing {$expected}.");
    }
}
foreach (['apeDataQualityHeading', 'Governance navigation', 'metadataJson', '<pre class="m-0 mt-2 whitespace-pre-wrap break-words text-sm font-bold text-slate-600">'] as $removed) {
    if (str_contains($audit, $removed)) {
        throw new RuntimeException("Audit Log must not retain {$removed}.");
    }
}
foreach (['email_retry_requested', 'email_cancelled', 'email_delivery_failed', 'email_sent_manually', 'email_automation_updated'] as $expected) {
    if (!str_contains($email . $auditService, $expected)) {
        throw new RuntimeException("Critical email audit action is missing {$expected}.");
    }
}
foreach (['ape_', 'patient_account_', 'visit_created', 'inventory_transaction_recorded', 'passport_viewed', "audit_log_event('settings'"] as $expected) {
    if (!str_contains($criticalSources, $expected)) {
        throw new RuntimeException("Critical governance coverage is missing {$expected}.");
    }
}
foreach (["audit_log_event('email', 'email_queued'", "audit_log_event('email', 'email_claimed'", "audit_log_event('email', 'email_deferred_capacity'", "audit_log_event('email', 'email_sent'"] as $removed) {
    if (str_contains($email, $removed)) {
        throw new RuntimeException("Routine email queue event must not enter governance history: {$removed}.");
    }
}

echo "Audit log governance test passed. No database writes.\n";
