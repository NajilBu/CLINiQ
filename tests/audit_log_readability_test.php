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

$page = file_get_contents(dirname(__DIR__) . '/public/audit/index.php');
foreach (['Performed by', 'Affected record', 'Additional information', 'target_name', '$parameterIndex++'] as $expected) {
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
if (!str_contains($page, '$perPage = 10;')) {
    throw new RuntimeException('Audit history must be limited to 10 entries per page.');
}

echo "Audit log readability test passed. No database writes.\n";
