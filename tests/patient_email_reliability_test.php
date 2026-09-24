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
    'retryable = 0',
    'email_delivery_failed',
    'patient_email_mark_follow_up',
    'patient_email_set_queue_paused',
    'mail.worker_heartbeat',
];
foreach ($serviceContracts as $contract) {
    if (!str_contains($service, $contract)) {
        throw new RuntimeException("Central email service is missing reliability contract: {$contract}.");
    }
}

foreach (['send_cliniq_email_result', 'provider_message_id', 'smtp_code', 'ErrorInfo'] as $contract) {
    if (!str_contains($mail, $contract)) {
        throw new RuntimeException("Mail helper is missing structured result field: {$contract}.");
    }
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
