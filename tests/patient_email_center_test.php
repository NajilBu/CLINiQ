<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/PatientEmail.php');
$audit = file_get_contents($root . '/public/audit/index.php');
$auditService = file_get_contents($root . '/app/services/AuditLog.php');
$workCenter = file_get_contents($root . '/app/services/ClinicWorkCenter.php');
$settings = file_get_contents($root . '/app/services/SystemSettings.php');
$migration = file_get_contents($root . '/database/migrations/20260923_create_patient_email_center.sql');
$operationsMigration = file_get_contents($root . '/database/migrations/20260923_email_center_operations.sql');

if ($service === false || $audit === false || $auditService === false || $workCenter === false || $settings === false || $migration === false || $operationsMigration === false) {
    throw new RuntimeException('Unable to read Patient Email Center sources.');
}

$requiredServiceSymbols = [
    'patient_email_queue',
    'patient_email_process_queue',
    'patient_email_retry',
    'patient_email_cancel',
    'patient_email_resend',
    'patient_email_due_automations',
    'patient_email_recheck_recipient',
    'patient_email_retry_blocked',
    'patient_email_process_now',
    'patient_email_reschedule',
    'patient_email_update_pending',
    'patient_email_delivery_timeline',
    'patient_email_bulk_action',
    'appointment_reminders',
    'ape_follow_up_reminders',
    'ape_overdue',
];
foreach ($requiredServiceSymbols as $symbol) {
    if (!str_contains($service, $symbol)) {
        throw new RuntimeException("Email service is missing {$symbol}.");
    }
}

foreach (['Audit Log', 'Email Center', 'email_action', 'retry', 'cancel', 'resend', 'custom_send'] as $expected) {
    if (!str_contains($audit, $expected)) {
        throw new RuntimeException("Email Center UI is missing {$expected}.");
    }
}
foreach (['clinic_work_center_reminder_history', 'clinic_work_center_reminder_candidates', 'clinic_work_center_send_reminders', 'send_clinic_reminders', 'clinicReminderReviewModal', 'recently-reminded', 'Review first'] as $expected) {
    if (!str_contains($audit . $workCenter, $expected)) {
        throw new RuntimeException("Clinic reminder flow is missing {$expected}.");
    }
}
foreach (['clinicReminderReviewModal', 'reminders[', 'previous_deadline', 'Review clinic reminders', 'edited subject', 'plpuhs.dpdns.org', 'clinic_reminder_review_invalid'] as $expected) {
    if (!str_contains($audit . $workCenter, $expected)) {
        throw new RuntimeException("Reviewed reminder preview is missing {$expected}.");
    }
}
if (!str_contains($workCenter, "event_type'] ?? '') === 'ape_exam_missed'")) {
    throw new RuntimeException('Missed examination reminders must remain eligible alongside clinic examination work.');
}
foreach (['$requestedStatus', 'candidateKey', 'ape_documents_overdue', 'status <> \'Verified\''] as $expected) {
    if (!str_contains($service . $workCenter, $expected)) {
        throw new RuntimeException("Reminder reliability guard is missing {$expected}.");
    }
}
if (!str_contains($auditService, 'email_automation_updated')) {
    throw new RuntimeException('Audit service is missing email automation action labeling.');
}

foreach (['patient_person_id', 'event_type', 'dedupe_key', 'automation_key', 'resent_from_id', 'cancelled_at', "'cancelled'"] as $expected) {
    if (!str_contains($migration, $expected)) {
        throw new RuntimeException("Email queue migration is missing {$expected}.");
    }
}
foreach (['patient_email_capacity_state', 'patient_email_capacity_snapshot', 'patient_email_capacity_claim', 'patient_email_is_quota_error', 'CLINIQ_EMAIL_INITIAL_SAFETY_CAPACITY', 'email_deferred_capacity', 'email_capacity_reduced', 'remaining_capacity', 'capacity_source'] as $expected) {
    if (!str_contains($service, $expected)) {
        throw new RuntimeException("Adaptive email capacity is missing {$expected}.");
    }
}
foreach (['</strong> sent', '</strong> remaining', 'Initial safety capacity', 'Observed provider capacity', 'Capacity reached'] as $expected) {
    if (!str_contains($audit, $expected)) {
        throw new RuntimeException("Email capacity UI is missing {$expected}.");
    }
}
foreach (['appointment_confirmed', 'appointment_reminder', 'appointment_changes', 'ape_document_correction', 'ape_hard_copy_correction', 'ape_clearance_correction', 'ape_follow_up_required', 'ape_follow_up_reminder', 'ape_follow_up_overdue', 'ape_documents_overdue', 'ape_exam_missed', 'patient_access_restricted'] as $templateKey) {
    if (!str_contains($settings, "'{$templateKey}'") || !str_contains($service, "'template_key' => '{$templateKey}'")) {
        throw new RuntimeException("Email template mapping is missing {$templateKey}.");
    }
}
if (!str_contains($service, 'cliniq_notification_email($templateKey')) {
    throw new RuntimeException('Email dispatcher is not rendering Settings templates.');
}
foreach (['manual_attempts', 'retry_reason', 'follow_up_note', 'follow_up_assigned_to_person_id', 'follow_up_due_at', 'edited_at', 'delivery_state'] as $expected) {
    if (!str_contains($operationsMigration, $expected)) {
        throw new RuntimeException("Email operations migration is missing {$expected}.");
    }
}

echo "Patient Email Center structural test passed.\n";
