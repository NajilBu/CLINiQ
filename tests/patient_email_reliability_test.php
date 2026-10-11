<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/PatientEmail.php');
$mail = file_get_contents($root . '/app/helpers/mail.php');
$lifecycle = file_get_contents($root . '/database/migrations/20260923_email_delivery_lifecycle.sql');
$normalization = file_get_contents($root . '/database/migrations/20260923_normalize_email_terminal_retryability.sql');

foreach (['service' => $service, 'mail' => $mail, 'lifecycle' => $lifecycle, 'normalization' => $normalization] as $name => $contents) {
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$name} reliability source.");
    }
}

$serviceContracts = [
    'patient_email_dispatch_event',
    'patient_email_recipient',
    'patient_email_repair_student_address',
    "status = 'blocked'",
    'dedupe_key',
    'FOR UPDATE',
    "status = 'processing'",
    'DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
    "status = 'sent' AND sender_email = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL {\$windowSeconds} SECOND)",
    'retryable = 0',
    'email_delivery_failed',
    'patient_email_mark_follow_up',
    'patient_email_set_queue_paused',
    'mail.worker_heartbeat',
    "'ape_exam_missed'",
    "ape_record_queue(\$record) === 'examination'",
    "(ape_priority_badge(\$record)['label'] ?? '') === 'Missed'",
];
foreach ($serviceContracts as $contract) {
    if (!str_contains($service, $contract)) {
        throw new RuntimeException("Central email service is missing reliability contract: {$contract}.");
    }
}

if (!str_contains($service, "patient_email_capacity_growth() > 0 && !\$quotaRecentlyRejected")) {
    throw new RuntimeException('A fixed provider cap must not use adaptive probe sends.');
}

foreach (['send_cliniq_email_result', 'provider_message_id', 'smtp_code', 'ErrorInfo', 'reply_to_email', 'addReplyTo'] as $contract) {
    if (!str_contains($mail, $contract)) {
        throw new RuntimeException("Mail helper is missing structured result field: {$contract}.");
    }
}

foreach (['#3f7d52', '#f4fbf6', 'UNIVERSITY HEALTH SERVICES', 'Questions or concerns? Reply to this email', 'CLINIQ_EMAIL_LOGO_PLACEHOLDER'] as $contract) {
    if (!str_contains($mail, $contract)) {
        throw new RuntimeException("Student email presentation contract is missing {$contract}.");
    }
}

foreach (['cliniq_email_logo_file', 'CLINIQ_EMAIL_LOGO_CID', 'addEmbeddedImage', 'src="cid:'] as $contract) {
    if (!str_contains($mail, $contract)) {
        throw new RuntimeException("Student email logo contract is missing {$contract}.");
    }
}
if (str_contains($mail, 'cliniq_email_logo_url') || str_contains($mail, 'src=\"https://')) {
    throw new RuntimeException('Student email logos must not depend on a remote URL.');
}

foreach (['blocked_reason', 'last_attempt_at', 'failed_at', 'next_attempt_at', 'locked_at', 'locked_by', 'max_attempts', 'retryable', 'sender_email', 'provider_message_id', 'follow_up_required', 'resolved_by_person_id', 'uq_email_queue_dedupe_key'] as $column) {
    if (!str_contains($lifecycle, $column)) {
        throw new RuntimeException("Lifecycle migration is missing {$column}.");
    }
}

if (!str_contains($normalization, "status IN ('sent', 'blocked', 'cancelled')")) {
    throw new RuntimeException('Terminal retryability normalization is missing.');
}

echo "Patient email reliability test passed.\n";
