<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/PatientEmail.php');
$audit = file_get_contents($root . '/public/audit/index.php');
$patientView = file_get_contents($root . '/public/patients/view.php');
$auditService = file_get_contents($root . '/app/services/AuditLog.php');
$workCenter = file_get_contents($root . '/app/services/ClinicWorkCenter.php');
$viewHelper = file_get_contents($root . '/app/helpers/view.php');
$settings = file_get_contents($root . '/app/services/SystemSettings.php');
$migration = file_get_contents($root . '/database/migrations/20260923_create_patient_email_center.sql');
$operationsMigration = file_get_contents($root . '/database/migrations/20260923_z_email_center_operations.sql');

if ($service === false || $audit === false || $patientView === false || $auditService === false || $workCenter === false || $viewHelper === false || $settings === false || $migration === false || $operationsMigration === false) {
    throw new RuntimeException('Unable to read Patient Email Center sources.');
}

foreach (['custom_patient_email', "'dedupe_suffix' => \$manualSendKey", 'Email queue #', 'SMTP accepted. Final delivery updates'] as $expected) {
    if (!str_contains($audit, $expected)) {
        throw new RuntimeException("Email Center manual-send truthfulness guard is missing {$expected}.");
    }
}
foreach (['manual_patient_email', "'dedupe_suffix' => 'manual-' . bin2hex(random_bytes(16))", 'SMTP accepted for'] as $expected) {
    if (!str_contains($patientView, $expected)) {
        throw new RuntimeException("Patient-page manual-send isolation guard is missing {$expected}.");
    }
}

foreach (["clinic_work_center_items(['email_relevant' => true]", "\$emailWorkSummary['urgent'] + (int) \$emailWorkSummary['overdue'] + (int) \$emailWorkSummary['email_issues']"] as $expected) {
    if (!str_contains($viewHelper, $expected)) {
        throw new RuntimeException("Email Center sidebar badge is missing its shared action-work count: {$expected}.");
    }
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

foreach (['Email Center', 'email_action', 'retry', 'cancel', 'resend', 'custom_send', 'Send patient email', 'Delivery controls and diagnostics'] as $expected) {
    if (!str_contains($audit, $expected)) {
        throw new RuntimeException("Email Center UI is missing {$expected}.");
    }
}
foreach (['collapseWorkItems', 'Clinic work actions', 'One warning for this issue type', 'Correct online documents'] as $expected) {
    if (!str_contains($audit, $expected)) {
        throw new RuntimeException("Grouped Email Center UI is missing {$expected}.");
    }
}
foreach (['clinic_work_center_reminder_history', 'clinic_work_center_reminder_candidates', 'clinic_work_center_send_reminders', 'send_clinic_reminders', 'clinicReminderReviewModal', 'recently-reminded', 'Review first'] as $expected) {
    if (!str_contains($audit . $workCenter, $expected)) {
        throw new RuntimeException("Clinic reminder flow is missing {$expected}.");
    }
}
foreach (['clinicReminderReviewModal', 'reminders[', 'follow_up_number', 'Review APE follow-ups', 'edited subject', 'plpuhs.dpdns.org', 'clinic_reminder_review_invalid'] as $expected) {
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
foreach (['patient_email_capacity_state', 'patient_email_capacity_snapshot', 'patient_email_capacity_claim', 'patient_email_is_quota_error', 'CLINIQ_EMAIL_INITIAL_SAFETY_CAPACITY', 'email_capacity_reduced', 'remaining_capacity', 'capacity_source'] as $expected) {
    if (!str_contains($service, $expected)) {
        throw new RuntimeException("Adaptive email capacity is missing {$expected}.");
    }
}
if (!str_contains($service, "'MAIL_CAPACITY_GROWTH', CLINIQ_EMAIL_CAPACITY_GROWTH, 0")) {
    throw new RuntimeException('Email capacity growth cannot be disabled for a provider-capped queue.');
}
foreach (['</strong> SMTP accepted', '</strong> remaining', 'Delivery needs review'] as $expected) {
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
foreach (["workflow_status NOT IN ('Cleared', 'Inactive')", "'worker_healthy'", 'max(180, $workerInterval * 3)', 'actionable_issues', "q.origin = 'automatic'", "q.status = 'processing' AND q.locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"] as $expected) {
    if (!str_contains($service, $expected)) {
        throw new RuntimeException("Email safety guard is missing {$expected}.");
    }
}
foreach (['$adminEmailActions', 'count($ids) > 50', 'ape_schedule_updates', 'SMTP accepted', 'Review urgent work', 'Review APE follow-ups', '$canManageEmailOperations && $needsReminderItems', 'Quick filters'] as $expected) {
    if (!str_contains($audit, $expected)) {
        throw new RuntimeException("Email Center operation safeguard is missing {$expected}.");
    }
}
foreach (['patient_person_id', 'q.patient_person_id = ?', 'patient_person_id='] as $expected) {
    if (!str_contains($service . $audit, $expected)) {
        throw new RuntimeException("Patient-scoped email history guard is missing {$expected}.");
    }
}
foreach (['view=work', "['work', 'send', 'history', 'admin']", 'view=history', 'view=admin', 'email-view-work', 'patient-search.php', 'DOMContentLoaded', 'urgentPatientIds', 'patientEmailComposeModal'] as $expected) {
    if (!str_contains($audit, $expected)) {
        throw new RuntimeException("Email Center mode or scale guard is missing {$expected}.");
    }
}
if (str_contains($audit, 'view=send#send-email')) throw new RuntimeException('Send email must remain a button action, not a navigation tab.');
if (!str_contains($audit, "showModal(\\'patientEmailComposeModal\\')")) throw new RuntimeException('Send email button must open the composer modal.');
$patientSearch = file_get_contents($root . '/public/api/patient-search.php');
foreach (['require_login', 'LIMIT 30', "['admin', 'doctor', 'nurse']"] as $expected) {
    if ($patientSearch === false || !str_contains($patientSearch, $expected)) {
        throw new RuntimeException("Patient search endpoint guard is missing {$expected}.");
    }
}
foreach (["str_starts_with((string) (\$row['event_type'] ?? ''), 'ape_')", "str_starts_with((string) (\$row['source_type'] ?? ''), 'ape')"] as $expected) {
    if (!str_contains($service, $expected)) {
        throw new RuntimeException('Raw APE emails must not be resent outside the reviewed follow-up flow.');
    }
}
foreach (['legacy-email-attention', '>Recent activity</h2>', '<span class="btn btn-sm btn-outline">Open history</span>'] as $removed) {
    if (str_contains($audit, $removed)) {
        throw new RuntimeException("Redundant Email Center UI remains: {$removed}.");
    }
}
foreach (['email-center-intro', 'Blocked emails need a recipient fix', 'Email follow-up work'] as $removed) {
    if (str_contains($audit, $removed)) {
        throw new RuntimeException("A redundant Email Center surface remains: {$removed}.");
    }
}
$queueApi = file_get_contents($root . '/public/api/email-queue.php');
foreach (["!== 'admin'", "!== 'POST'", '$_POST[\'queue_key\']', '$_POST[\'action\']'] as $expected) {
    if ($queueApi === false || !str_contains($queueApi, $expected)) {
        throw new RuntimeException("Email queue API safeguard is missing {$expected}.");
    }
}
foreach (['manual_attempts', 'retry_reason', 'follow_up_note', 'follow_up_assigned_to_person_id', 'follow_up_due_at', 'edited_at', 'delivery_state'] as $expected) {
    if (!str_contains($operationsMigration, $expected)) {
        throw new RuntimeException("Email operations migration is missing {$expected}.");
    }
}

echo "Patient Email Center structural test passed.\n";
