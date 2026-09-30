<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/AuditLog.php';

if (audit_log_module_label('auth') !== 'Sign-in activity') {
    throw new RuntimeException('Audit modules must use staff-friendly labels.');
}
if (audit_log_action_label('staff_login_success') !== 'Signed in to the clinic system') {
    throw new RuntimeException('Audit actions must use staff-friendly descriptions.');
}
if (audit_log_target_label([
    'target_type' => 'patient',
    'target_id' => 29,
    'target_name' => 'Najil Bumacod',
    'target_id_number' => '23-00262',
]) !== 'Najil Bumacod (23-00262)') {
    throw new RuntimeException('Affected patient records must show names and ID numbers instead of database IDs.');
}

$metadata = json_encode(['route' => 'public/emergency.php', 'authenticated' => true], JSON_THROW_ON_ERROR);
$summary = audit_log_metadata_summary($metadata);
if (!str_contains($summary, 'Opened through QR/NFC') || !str_contains($summary, 'Viewer signed in')) {
    throw new RuntimeException('Passport metadata must be explained in plain language.');
}

$sensitiveSummary = audit_log_metadata_summary(json_encode(['notes' => 'Private note', 'message' => 'Private message'], JSON_THROW_ON_ERROR));
if ($sensitiveSummary !== 'Additional technical information recorded') {
    throw new RuntimeException('Audit details must not expose free-text metadata.');
}
$batchDetails = audit_log_event_details('clinic_reminder_batch_completed', json_encode([
    'eligible' => 3,
    'sent' => 1,
    'queued' => 1,
    'deferred' => 0,
    'skipped' => 1,
    'blocked' => 0,
    'failed' => 0,
], JSON_THROW_ON_ERROR));
foreach (['3 follow-ups were eligible', '1 sent to email service', '1 queued for delivery', '1 skipped', 'APE deadlines were not changed'] as $expected) {
    if (!str_contains($batchDetails, $expected)) {
        throw new RuntimeException("APE follow-up batch details must explain {$expected}.");
    }
}
if (audit_log_governance_excluded_actions() !== ['email_queued', 'email_claimed', 'email_deferred_capacity', 'email_sent']) {
    throw new RuntimeException('Routine email queue events must remain outside governance history.');
}

$page = file_get_contents(dirname(__DIR__) . '/public/audit/index.php');
foreach (['Performed by', 'Affected record', 'Additional information', 'target_name', '$parameterIndex++', 'audit_log_event_details'] as $expected) {
    if (!str_contains($page, $expected)) {
        throw new RuntimeException("The readable audit page is missing {$expected}.");
    }
}
if (str_contains($page, 'View technical details') || str_contains($page, 'audit_log_pretty_metadata')) {
    throw new RuntimeException('Raw technical metadata must not be exposed in the staff audit interface.');
}
if (str_contains($page, '<th>Module / Action</th>') || str_contains($page, '<th>Target</th>')) {
    throw new RuntimeException('Technical audit column headings must not be shown to staff.');
}
if (!str_contains($page, '$perPage = 25;') || !str_contains($page, 'Apply filters')) {
    throw new RuntimeException('Audit history must use explicit filtering and a practical page size.');
}

echo "Audit log readability test passed. No database writes.\n";
