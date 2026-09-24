<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/mail.php';
require_once __DIR__ . '/../services/AuditLog.php';
require_once __DIR__ . '/../services/SystemSettings.php';
require_once __DIR__ . '/../services/PatientNotification.php';
require_once __DIR__ . '/ApeWorkflow.php';

const CLINIQ_EMAIL_AUTOMATION_DEFAULTS = [
    'appointment_reminders' => true,
    'appointment_changes' => true,
    'ape_corrections' => true,
    'ape_follow_up_reminders' => true,
    'ape_overdue' => true,
    'access_restrictions' => true,
    'school_year_enrollment' => true,
];

const CLINIQ_EMAIL_MAX_ATTEMPTS = 5;
const CLINIQ_EMAIL_INITIAL_SAFETY_CAPACITY = 3;
const CLINIQ_EMAIL_MAX_ADAPTIVE_CAPACITY = 500;
const CLINIQ_EMAIL_CAPACITY_GROWTH = 1;
const CLINIQ_EMAIL_CAPACITY_WINDOW_SECONDS = 86400;

function patient_email_capacity_config(string $key, int $default, int $minimum, int $maximum): int
{
    $value = (int) env_value($key, (string) $default);
    return max($minimum, min($maximum, $value));
}

function patient_email_initial_capacity(): int
{
    return patient_email_capacity_config(
        'MAIL_CAPACITY_INITIAL',
        CLINIQ_EMAIL_INITIAL_SAFETY_CAPACITY,
        1,
        patient_email_max_capacity()
    );
}

function patient_email_max_capacity(): int
{
    return patient_email_capacity_config('MAIL_CAPACITY_MAX', CLINIQ_EMAIL_MAX_ADAPTIVE_CAPACITY, 1, 100000);
}

function patient_email_capacity_growth(): int
{
    return patient_email_capacity_config('MAIL_CAPACITY_GROWTH', CLINIQ_EMAIL_CAPACITY_GROWTH, 1, patient_email_max_capacity());
}

function patient_email_provider_identifier(): string
{
    $settings = mail_settings_configured() ? mail_settings() : [];
    return strtolower(trim((string) ($settings['host'] ?? env_value('MAIL_HOST', 'smtp.gmail.com'))));
}

function patient_email_capacity_state(): array
{
    $provider = patient_email_provider_identifier();
    $state = (array) cliniq_setting_read('mail.capacity.' . sha1($provider), []);
    $state += [
        'window_started_at' => date('c'),
        'window_duration_seconds' => CLINIQ_EMAIL_CAPACITY_WINDOW_SECONDS,
        'accepted_count' => 0,
        'observed_capacity' => patient_email_initial_capacity(),
        'initial_safety_capacity' => patient_email_initial_capacity(),
        'capacity_source' => 'initial_safety',
        'last_quota_rejection_at' => null,
        'last_quota_error' => null,
        'last_capacity_update_at' => null,
        'provider_identifier' => $provider,
    ];
    return $state;
}

function patient_email_is_quota_error(?string $error): bool
{
    $value = strtolower(trim((string) $error));
    if ($value === '') return false;
    foreach (['quota exceeded', 'rate limit exceeded', 'daily sending limit', 'too many messages', 'too many recipients', 'sending limits', 'send limit'] as $needle) {
        if (str_contains($value, $needle)) return true;
    }
    return false;
}

function patient_email_capacity_snapshot(?PDO $db = null): array
{
    $db ??= auth_db();
    $state = patient_email_capacity_state();
    $windowSeconds = max(60, (int) ($state['window_duration_seconds'] ?? CLINIQ_EMAIL_CAPACITY_WINDOW_SECONDS));
    $sent = (int) $db->query("SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND delivery_state = 'smtp_accepted' AND sent_at >= DATE_SUB(NOW(), INTERVAL {$windowSeconds} SECOND)")->fetchColumn();
    $reserved = (int) $db->query("SELECT COUNT(*) FROM email_queue WHERE status = 'processing' AND locked_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn();
    $capacity = max(1, min(patient_email_max_capacity(), (int) ($state['observed_capacity'] ?? patient_email_initial_capacity())));
    $remaining = max(0, $capacity - $sent - $reserved);
    $lastQuotaRejection = strtotime((string) ($state['last_quota_rejection_at'] ?? '')) ?: 0;
    $quotaRecentlyRejected = $lastQuotaRejection > (time() - $windowSeconds);
    if ($remaining <= 0 && !$quotaRecentlyRejected) {
        // A successful window at the current estimate gets one controlled probe.
        // The next accepted message raises the estimate; a provider rejection lowers it.
        $remaining = 1;
    }
    $state['accepted_count'] = $sent;
    $state['window_started_at'] = date('c', time() - $windowSeconds);
    return array_merge($state, [
        'accepted_count' => $sent,
        'reserved_count' => $reserved,
        'remaining_capacity' => $remaining,
        'capacity_status' => $remaining > 0 ? ($sent + $reserved >= $capacity ? 'probe' : 'available') : 'reached',
    ]);
}

function patient_email_capacity_claim(PDO $db): array
{
    $lock = (int) $db->query("SELECT GET_LOCK('cliniq_email_capacity', 5)")->fetchColumn();
    if ($lock !== 1) return ['allowed' => false, 'reason' => 'capacity_lock_unavailable'];
    try {
        $snapshot = patient_email_capacity_snapshot($db);
    } catch (Throwable $e) {
        $db->query("SELECT RELEASE_LOCK('cliniq_email_capacity')");
        throw $e;
    }
    if ($snapshot['remaining_capacity'] <= 0) {
        $db->query("SELECT RELEASE_LOCK('cliniq_email_capacity')");
        return ['allowed' => false, 'reason' => 'capacity_reached', 'snapshot' => $snapshot];
    }
    return ['allowed' => true, 'reason' => null, 'snapshot' => $snapshot, 'lock_held' => true];
}

function patient_email_record_quota_rejection(PDO $db, string $error): void
{
    $state = patient_email_capacity_state();
    $snapshot = patient_email_capacity_snapshot($db);
    $current = max(1, min(patient_email_max_capacity(), (int) ($state['observed_capacity'] ?? patient_email_initial_capacity())));
    $accepted = max(0, (int) ($snapshot['accepted_count'] ?? 0));
    $capacity = max(1, min($current, max($accepted, (int) floor($current / 2))));
    $state['observed_capacity'] = $capacity;
    $state['capacity_source'] = 'provider_confirmed';
    $state['last_quota_rejection_at'] = date('c');
    $state['last_quota_error'] = substr($error, 0, 500);
    $state['last_capacity_update_at'] = date('c');
    cliniq_setting_write('mail.capacity.' . sha1(patient_email_provider_identifier()), $state, null);
    audit_log_event('email', 'email_capacity_reduced', null, 'system', 'email', null, ['observed_capacity' => $state['observed_capacity'], 'error' => $state['last_quota_error']], 'failure');
}

function patient_email_record_success(PDO $db): void
{
    $state = patient_email_capacity_state();
    $current = max(1, min(patient_email_max_capacity(), (int) ($state['observed_capacity'] ?? patient_email_initial_capacity())));
    $next = min(patient_email_max_capacity(), $current + patient_email_capacity_growth());
    if ($next === $current) return;

    $state['observed_capacity'] = $next;
    $state['capacity_source'] = 'adaptive_success';
    $state['last_capacity_update_at'] = date('c');
    cliniq_setting_write('mail.capacity.' . sha1(patient_email_provider_identifier()), $state, null);
    audit_log_event('email', 'email_capacity_increased', null, 'system', 'email', null, [
        'previous_capacity' => $current,
        'observed_capacity' => $next,
        'provider' => patient_email_provider_identifier(),
    ], 'success');
}

function patient_email_event_catalog(): array
{
    return [
        'appointment_confirmed' => ['label' => 'Appointment confirmed', 'automation_key' => 'appointment_reminders', 'template_key' => 'appointment_confirmed', 'email' => true, 'notification' => true],
        'appointment_confirmation_required' => ['label' => 'Appointment confirmation required', 'automation_key' => 'appointment_reminders', 'template_key' => 'appointment_confirmed', 'email' => true, 'notification' => true],
        'appointment_reminder' => ['label' => 'Appointment reminder', 'automation_key' => 'appointment_reminders', 'template_key' => 'appointment_reminder', 'email' => true, 'notification' => true],
        'appointment_cancelled' => ['label' => 'Appointment cancelled', 'automation_key' => 'appointment_changes', 'template_key' => 'appointment_changes', 'email' => true, 'notification' => true],
        'appointment_no_show' => ['label' => 'Appointment no-show update', 'automation_key' => 'appointment_changes', 'template_key' => 'appointment_changes', 'email' => true, 'notification' => true],
        'appointment_rescheduled' => ['label' => 'Appointment rescheduled', 'automation_key' => 'appointment_changes', 'template_key' => 'appointment_changes', 'email' => true, 'notification' => true],
        'ape_document_correction' => ['label' => 'APE document correction', 'automation_key' => 'ape_corrections', 'template_key' => 'ape_document_correction', 'email' => true, 'notification' => true],
        'ape_document_correction_required' => ['label' => 'APE online document correction required', 'automation_key' => 'ape_corrections', 'template_key' => 'ape_document_correction', 'email' => true, 'notification' => true],
        'ape_hard_copy_correction_required' => ['label' => 'APE hard-copy correction required', 'automation_key' => 'ape_corrections', 'template_key' => 'ape_hard_copy_correction', 'email' => true, 'notification' => true],
        'ape_clearance_correction' => ['label' => 'APE clearance correction', 'automation_key' => 'ape_corrections', 'template_key' => 'ape_clearance_correction', 'email' => true, 'notification' => true],
        'ape_follow_up_required' => ['label' => 'APE follow-up required', 'automation_key' => 'ape_follow_up_reminders', 'template_key' => 'ape_follow_up_required', 'email' => true, 'notification' => true],
        'ape_follow_up_reminder' => ['label' => 'APE follow-up reminder', 'automation_key' => 'ape_follow_up_reminders', 'template_key' => 'ape_follow_up_reminder', 'email' => true, 'notification' => true],
        'ape_follow_up_overdue' => ['label' => 'APE follow-up overdue', 'automation_key' => 'ape_overdue', 'template_key' => 'ape_follow_up_overdue', 'email' => true, 'notification' => true],
        'ape_documents_overdue' => ['label' => 'APE documents overdue', 'automation_key' => 'ape_overdue', 'template_key' => 'ape_documents_overdue', 'email' => true, 'notification' => true],
        'ape_exam_missed' => ['label' => 'Missed APE examination', 'automation_key' => '', 'template_key' => 'ape_exam_missed', 'email' => true, 'notification' => true],
        'patient_access_restricted' => ['label' => 'Patient access restricted', 'automation_key' => 'access_restrictions', 'template_key' => 'patient_access_restricted', 'email' => true, 'notification' => true],
        'school_year_enrollment' => ['label' => 'School-year enrollment', 'automation_key' => 'school_year_enrollment', 'email' => true, 'notification' => true],
        'enrollment_confirmation' => ['label' => 'Enrollment confirmation', 'automation_key' => 'school_year_enrollment', 'email' => true, 'notification' => false],
        'student_re_enrollment' => ['label' => 'Student re-enrollment', 'automation_key' => 'school_year_enrollment', 'email' => true, 'notification' => false],
        'password_reset' => ['label' => 'Password reset', 'automation_key' => '', 'email' => true, 'notification' => false],
        'registration_verification' => ['label' => 'Registration verification', 'automation_key' => '', 'email' => true, 'notification' => false],
        'custom_patient_email' => ['label' => 'Manual patient email', 'automation_key' => '', 'email' => true, 'notification' => false],
        'manual_patient_email' => ['label' => 'Manual patient email', 'automation_key' => '', 'email' => true, 'notification' => false],
        'smtp_test' => ['label' => 'SMTP test', 'automation_key' => '', 'email' => true, 'notification' => false],
        'custom_account_email' => ['label' => 'Custom account email', 'automation_key' => '', 'email' => true, 'notification' => false],
    ];
}

function patient_email_sender(): array
{
    $settings = mail_settings_configured() ? mail_settings() : [];
    $email = (string) ($settings['from_email'] ?? env_value('MAIL_FROM', $settings['username'] ?? env_value('MAIL_USER', '')));
    $name = (string) ($settings['from_name'] ?? env_value('MAIL_FROM_NAME', clinic_profile_settings()['system_name'] ?? 'CLINiQ Clinic'));
    return ['email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null, 'name' => trim($name) ?: 'CLINiQ Clinic'];
}

function patient_email_automation_settings(): array
{
    $saved = cliniq_setting_read('mail.automation', CLINIQ_EMAIL_AUTOMATION_DEFAULTS);
    foreach (CLINIQ_EMAIL_AUTOMATION_DEFAULTS as $key => $default) {
        $saved[$key] = !empty($saved[$key]);
    }
    return $saved;
}

function patient_email_save_automation_settings(array $input, ?int $actorPersonId): array
{
    $settings = [];
    foreach (CLINIQ_EMAIL_AUTOMATION_DEFAULTS as $key => $default) {
        $settings[$key] = !empty($input[$key]);
    }
    cliniq_setting_write('mail.automation', $settings, $actorPersonId);
    audit_log_event('email', 'email_automation_updated', $actorPersonId, 'staff', 'settings', null, ['settings' => $settings]);
    return $settings;
}

function patient_email_automation_enabled(string $key): bool
{
    $settings = patient_email_automation_settings();
    return !array_key_exists($key, $settings) || $settings[$key] === true;
}

function patient_email_queue_is_paused(): bool
{
    $setting = cliniq_setting_read('mail.queue_paused', ['paused' => false]);
    return !empty($setting['paused']);
}

function patient_email_set_queue_paused(bool $paused, ?int $actorPersonId): bool
{
    cliniq_setting_write('mail.queue_paused', ['paused' => $paused], $actorPersonId);
    audit_log_event('email', $paused ? 'email_queue_paused' : 'email_queue_resumed', $actorPersonId, 'staff', 'settings', null, ['paused' => $paused]);
    return $paused;
}

function patient_email_worker_heartbeat(?string $workerId = null): void
{
    cliniq_setting_write('mail.worker_heartbeat', [
        'worker_id' => $workerId ?: 'worker',
        'at' => date('c'),
    ], null);
}

function patient_email_event_key(string $eventType, ?int $sourceId, ?int $patientPersonId, string $suffix = ''): string
{
    return implode(':', [$eventType, (int) $sourceId, (int) $patientPersonId, $suffix]);
}

function patient_email_queue(array $data): int
{
    $db = auth_db();
    $recipientEmail = trim((string) ($data['recipient_email'] ?? ''));
    $isBlocked = ($data['status'] ?? '') === 'blocked';
    if (!$isBlocked && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    $dedupeKey = trim((string) ($data['dedupe_key'] ?? '')) ?: null;
    if ($dedupeKey !== null) {
        $existing = $db->prepare('SELECT id FROM email_queue WHERE dedupe_key = ? AND status <> \'cancelled\' LIMIT 1');
        $existing->execute([$dedupeKey]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }
    }

    $sender = patient_email_sender();
    $requestedStatus = (string) ($data['status'] ?? 'pending');
    $status = in_array($requestedStatus, ['pending', 'blocked'], true) ? $requestedStatus : 'pending';
    $insert = $db->prepare('INSERT INTO email_queue
         (queue_key, patient_person_id, event_type, source_type, source_id, dedupe_key, automation_key, origin,
          created_by_person_id, resent_from_id, recipient_email, recipient_name, subject, html_body, status, available_at,
          next_attempt_at, max_attempts, retryable, sender_email, sender_name, blocked_reason, follow_up_required,
          delivery_state, delivery_state_updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()), COALESCE(?, NOW()), ?, ?, ?, ?, ?, ?, NULL, NULL)');
    $insert->execute([
        trim((string) ($data['queue_key'] ?? 'patient_email')) ?: 'patient_email',
        (int) ($data['patient_person_id'] ?? 0) ?: null,
        trim((string) ($data['event_type'] ?? '')) ?: null,
        trim((string) ($data['source_type'] ?? '')) ?: null,
        (int) ($data['source_id'] ?? 0) ?: null,
        $dedupeKey,
        trim((string) ($data['automation_key'] ?? '')) ?: null,
        in_array(($data['origin'] ?? 'automatic'), ['automatic', 'manual', 'system'], true) ? $data['origin'] : 'automatic',
        (int) ($data['created_by_person_id'] ?? 0) ?: null,
        (int) ($data['resent_from_id'] ?? 0) ?: null,
        $recipientEmail,
        trim((string) ($data['recipient_name'] ?? 'Patient')) ?: 'Patient',
        trim((string) ($data['subject'] ?? '')),
        (string) ($data['html_body'] ?? ''),
        $status,
        !empty($data['available_at']) ? $data['available_at'] : null,
        !empty($data['available_at']) ? $data['available_at'] : null,
        (int) ($data['max_attempts'] ?? CLINIQ_EMAIL_MAX_ATTEMPTS),
        $isBlocked ? 0 : 1,
        $sender['email'],
        $sender['name'],
        trim((string) ($data['blocked_reason'] ?? '')) ?: null,
         !empty($data['follow_up_required']) ? 1 : 0,
     ]);
    return (int) $db->lastInsertId();
}

function patient_email_recipient(int $patientPersonId): ?array
{
    if ($patientPersonId < 1) return null;
    patient_email_repair_student_address($patientPersonId);
    $stmt = auth_db()->prepare('SELECT p.id, p.first_name, p.middle_name, p.last_name, a.email, a.account_status
        FROM people p INNER JOIN accounts a ON a.person_id = p.id INNER JOIN patients pt ON pt.person_id = p.id
        WHERE p.id = ? AND a.account_status = \'active\' LIMIT 1');
    $stmt->execute([$patientPersonId]);
    $row = $stmt->fetch();
    if (!$row || !filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL)) return null;
    return $row;
}

function patient_email_repair_student_address(int $personId): ?string
{
    if ($personId < 1) return null;
    $db = auth_db();
    $lookup = $db->prepare('SELECT p.first_name, p.middle_name, p.last_name, a.email
        FROM people p INNER JOIN students s ON s.person_id = p.id INNER JOIN accounts a ON a.person_id = p.id
        WHERE p.id = ? LIMIT 1');
    $lookup->execute([$personId]);
    $row = $lookup->fetch();
    if (!$row || trim((string) ($row['email'] ?? '')) !== '') return filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $row['email'] : null;

    $normalize = static function (string $value): string {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
        $value = strtolower($ascii === false ? trim($value) : $ascii);
        return trim(preg_replace('/[^a-z0-9]+/', '', $value) ?? '');
    };
    $first = $normalize(trim((string) $row['first_name'] . ' ' . (string) ($row['middle_name'] ?? '')));
    $last = $normalize((string) $row['last_name']);
    if ($first === '' || $last === '') return null;
    $candidate = $last . '_' . $first . '@plpasig.edu.ph';
    $existing = $db->prepare('SELECT person_id FROM accounts WHERE LOWER(email) = LOWER(?) AND person_id <> ? LIMIT 1');
    $existing->execute([$candidate, $personId]);
    if ($existing->fetchColumn()) return null;
    $update = $db->prepare('UPDATE accounts SET email = ? WHERE person_id = ? AND (email IS NULL OR TRIM(email) = \'\')');
    $update->execute([$candidate, $personId]);
    return $candidate;
}

function patient_email_queue_notification(
    int $patientPersonId,
    string $eventType,
    string $automationKey,
    string $subject,
    string $message,
    ?string $sourceType,
    ?int $sourceId,
    ?int $actorPersonId = null,
    ?string $availableAt = null,
    string $dedupeSuffix = '',
    bool $createNotification = false
): int {
    return patient_email_dispatch_event([
        'patient_person_id' => $patientPersonId,
        'event_type' => $eventType,
        'automation_key' => $automationKey,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'dedupe_suffix' => $dedupeSuffix,
        'origin' => 'automatic',
        'created_by_person_id' => $actorPersonId,
        'subject' => $subject,
        'message' => $message,
        'available_at' => $availableAt,
        'notification' => $createNotification ? [
            'enabled' => true,
            'category' => 'email',
            'title' => $subject,
            'message' => $message,
            'target_url' => $sourceType === 'ape' ? 'patient-ape-status.php' : 'patient-appointment.php',
        ] : [],
    ]);
}

function patient_email_dispatch_event(array $event): int
{
    $eventType = trim((string) ($event['event_type'] ?? ''));
    $patientId = (int) ($event['patient_person_id'] ?? 0);
    $origin = in_array(($event['origin'] ?? 'automatic'), ['automatic', 'manual', 'system'], true) ? $event['origin'] : 'automatic';
    $catalog = patient_email_event_catalog()[$eventType] ?? null;
    $automationKey = trim((string) ($event['automation_key'] ?? ($catalog['automation_key'] ?? '')));
    $automatic = $origin === 'automatic';
    $shouldEmail = !array_key_exists('email_enabled', $event) || (bool) $event['email_enabled'];
    if ($automatic && $automationKey !== '' && !patient_email_automation_enabled($automationKey)) $shouldEmail = false;

    if ($eventType === '' || $patientId < 1) return 0;
    $sourceId = (int) ($event['source_id'] ?? 0) ?: null;
    $dedupeKey = trim((string) ($event['dedupe_key'] ?? ''))
        ?: patient_email_event_key($eventType, $sourceId, $patientId, (string) ($event['dedupe_suffix'] ?? ''));
    $notification = (array) ($event['notification'] ?? []);
    if (!empty($notification['enabled'])) {
        $db = auth_db();
        $existing = $db->prepare('SELECT notification_id FROM patient_notifications WHERE patient_person_id = ? AND source_type = ? AND source_id = ? AND title = ? LIMIT 1');
        $existing->execute([$patientId, (string) ($event['source_type'] ?? 'email'), $sourceId, (string) ($notification['title'] ?? $eventType)]);
        if ($existing->fetchColumn() === false) {
            patient_notification_create(
                $db,
                $patientId,
                (int) ($event['created_by_person_id'] ?? 0) ?: null,
                (string) ($notification['category'] ?? 'email'),
                (string) ($notification['title'] ?? $eventType),
                (string) ($notification['message'] ?? $event['message'] ?? ''),
                (string) ($notification['target_url'] ?? 'patient-dashboard.php'),
                (string) ($event['source_type'] ?? 'email'),
                $sourceId
            );
        }
    }
    if (!$shouldEmail) return 0;

    $recipient = patient_email_recipient($patientId);
    $clinic = clinic_profile_settings();
    $recipientName = $recipient
        ? (trim($recipient['first_name'] . ' ' . ($recipient['middle_name'] ?? '') . ' ' . $recipient['last_name']) ?: 'Patient')
        : 'Patient';
    $templateKey = trim((string) ($catalog['template_key'] ?? ''));
    $subject = trim((string) ($event['subject'] ?? ($catalog['label'] ?? 'Patient email')));
    $htmlBody = (string) ($event['html_body'] ?? cliniq_custom_email_body((string) ($event['message'] ?? ''), (string) ($clinic['system_name'] ?? 'CLINiQ Clinic')));
    if ($templateKey !== '') {
        $portalBase = rtrim((string) env_value('PATIENT_PORTAL_URL', 'http://localhost/CLINiQ/patient-portal'), '/');
        $actionUrl = (string) ($event['action_url'] ?? ($portalBase . '/' . match ((string) ($event['source_type'] ?? '')) {
            'appointment' => 'patient-appointment.php',
            'patient_access' => 'patient-dashboard.php',
            default => 'patient-ape-status.php',
        }));
        $templateContext = array_merge([
            'patient_name' => $recipientName,
            'clinic_name' => (string) ($clinic['system_name'] ?? 'CLINiQ Clinic'),
        ], (array) ($event['template_context'] ?? []));
        $renderedTemplate = cliniq_notification_email($templateKey, $templateContext, $actionUrl);
        $subject = $renderedTemplate['subject'];
        $htmlBody = $renderedTemplate['html'];
    }
    $base = [
        'queue_key' => trim((string) ($event['queue_key'] ?? '')) ?: 'patient_email',
        'patient_person_id' => $patientId,
        'event_type' => $eventType,
        'automation_key' => $automationKey,
        'source_type' => $event['source_type'] ?? null,
        'source_id' => $sourceId,
        'dedupe_key' => $dedupeKey,
        'origin' => $origin,
        'created_by_person_id' => (int) ($event['created_by_person_id'] ?? 0) ?: null,
        'subject' => $subject,
        'html_body' => $htmlBody,
        'available_at' => $event['available_at'] ?? null,
        'follow_up_required' => !empty($event['follow_up_required']),
    ];
    if (!$recipient) {
        $id = patient_email_queue($base + [
            'status' => 'blocked',
            'recipient_email' => null,
            'recipient_name' => 'Patient',
            'blocked_reason' => 'Patient has no active account with a valid email address.',
        ]);
        audit_log_event('email', 'email_blocked', (int) ($event['created_by_person_id'] ?? 0) ?: null, 'system', 'email', $id, ['event_type' => $eventType, 'reason' => 'missing_or_invalid_recipient'], 'failure');
        return $id;
    }
    $id = patient_email_queue($base + [
        'recipient_email' => $recipient['email'],
        'recipient_name' => $recipientName,
    ]);
    if ($id > 0) audit_log_event('email', 'email_queued', (int) ($event['created_by_person_id'] ?? 0) ?: null, $origin === 'manual' ? 'staff' : 'system', 'email', $id, ['event_type' => $eventType, 'origin' => $origin]);
    if (!empty($event['deliver_now']) && $id > 0) patient_email_process_queue('patient_email', 1, null, $id);
    return $id;
}

function patient_email_process_queue(?string $queueKey = null, int $limit = 5, ?string $workerId = null, ?int $specificId = null): array
{
    $db = auth_db();
    $limit = max(1, min(50, $limit));
    $workerId = $workerId ?: 'worker-' . bin2hex(random_bytes(8));
    $sent = 0; $failed = 0; $processed = 0; $deferred = 0; $quotaLimited = 0;
    patient_email_worker_heartbeat($workerId);
    if ($specificId === null && patient_email_queue_is_paused()) {
        return ['sent' => 0, 'failed' => 0, 'processed' => 0, 'paused' => true, 'worker_id' => $workerId];
    }
    $db->exec("UPDATE email_queue SET status = 'pending', locked_at = NULL, locked_by = NULL, next_attempt_at = NOW()
        WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");

    for ($i = 0; $i < $limit; $i++) {
        $capacityClaim = patient_email_capacity_claim($db);
        if (empty($capacityClaim['allowed'])) {
            $deferred++;
            audit_log_event('email', 'email_deferred_capacity', null, 'system', 'email', null, ['reason' => $capacityClaim['reason'] ?? 'capacity_reached']);
            break;
        }
        try {
            $db->beginTransaction();
            $sql = "SELECT * FROM email_queue
                WHERE status IN ('pending','failed') AND retryable = 1
                  AND COALESCE(next_attempt_at, available_at) <= NOW()
                  AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))";
            $params = [];
            if ($queueKey !== null && $queueKey !== '') { $sql .= ' AND queue_key = ?'; $params[] = $queueKey; }
            if ($specificId !== null && $specificId > 0) { $sql .= ' AND id = ?'; $params[] = $specificId; }
            $sql .= ' ORDER BY id LIMIT 1 FOR UPDATE';
            $claim = $db->prepare($sql); $claim->execute($params); $row = $claim->fetch();
            if (!$row) { $db->commit(); if (!empty($capacityClaim['lock_held'])) $db->query("SELECT RELEASE_LOCK('cliniq_email_capacity')"); break; }
            $markClaimed = $db->prepare("UPDATE email_queue SET status = 'processing', locked_at = NOW(), locked_by = ?, last_attempt_at = NOW(), attempts = attempts + 1 WHERE id = ? AND status IN ('pending','failed')");
            $markClaimed->execute([$workerId, (int) $row['id']]);
            $db->commit();
            if (!empty($capacityClaim['lock_held'])) $db->query("SELECT RELEASE_LOCK('cliniq_email_capacity')");
            audit_log_event('email', 'email_claimed', (int) ($row['created_by_person_id'] ?? 0) ?: null, 'system', 'email', (int) $row['id'], ['worker_id' => $workerId]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if (!empty($capacityClaim['lock_held'])) $db->query("SELECT RELEASE_LOCK('cliniq_email_capacity')");
            throw $e;
        }

        $processed++;
        $result = send_cliniq_email_result((string) $row['recipient_email'], (string) $row['recipient_name'], (string) $row['subject'], (string) $row['html_body']);
        $attempts = (int) $row['attempts'] + 1;
        if ($result['ok']) {
            $done = $db->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), last_error = NULL, failed_at = NULL, retryable = 0, locked_at = NULL, locked_by = NULL, provider_message_id = ?, delivery_state = 'smtp_accepted', delivery_state_updated_at = NOW() WHERE id = ? AND status = 'processing' AND locked_by = ?");
            $done->execute([$result['provider_message_id'], (int) $row['id'], $workerId]);
            patient_email_record_success($db);
            $sent++;
            audit_log_event('email', 'email_sent', (int) ($row['created_by_person_id'] ?? 0) ?: null, 'system', 'email', (int) $row['id'], ['event_type' => $row['event_type'], 'recipient' => $row['recipient_email'], 'provider_message_id' => $result['provider_message_id']], 'success');
            continue;
        }
        if (patient_email_is_quota_error($result['error'] ?? null)) {
            patient_email_record_quota_rejection($db, (string) ($result['error'] ?? 'Provider sending limit reached.'));
            $quotaLimited++;
        }
        $maxAttempts = max(1, (int) ($row['max_attempts'] ?? CLINIQ_EMAIL_MAX_ATTEMPTS));
        $terminal = $attempts >= $maxAttempts;
        $delays = [300, 1800, 7200, 43200, 86400];
        $delay = $delays[min(max(0, $attempts - 1), count($delays) - 1)];
        $failedUpdate = $db->prepare("UPDATE email_queue SET status = 'failed', last_error = ?, failed_at = NOW(), retryable = ?, next_attempt_at = ?, locked_at = NULL, locked_by = NULL WHERE id = ? AND status = 'processing' AND locked_by = ?");
        $failedUpdate->execute([$result['error'] ?: 'SMTP delivery failed.', $terminal ? 0 : 1, $terminal ? null : date('Y-m-d H:i:s', time() + $delay), (int) $row['id'], $workerId]);
        $failed++;
        audit_log_event('email', 'email_delivery_failed', (int) ($row['created_by_person_id'] ?? 0) ?: null, 'system', 'email', (int) $row['id'], ['event_type' => $row['event_type'], 'recipient' => $row['recipient_email'], 'attempts' => $attempts, 'terminal' => $terminal, 'error' => $result['error']], 'failure');
    }
    patient_email_worker_heartbeat($workerId);
    return ['sent' => $sent, 'failed' => $failed, 'processed' => $processed, 'deferred' => $deferred, 'quota_limited' => $quotaLimited, 'paused' => false, 'worker_id' => $workerId];
}

function patient_email_retry(int $id, ?int $actorPersonId): bool
{
    $stmt = auth_db()->prepare("UPDATE email_queue SET status = 'pending', available_at = NOW(), next_attempt_at = NOW(), last_error = NULL, failed_at = NULL, retryable = 1, locked_at = NULL, locked_by = NULL, manual_attempts = manual_attempts + 1, last_manual_retry_at = NOW() WHERE id = ? AND status = 'failed'");
    $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) return false;
    audit_log_event('email', 'email_retry_requested', $actorPersonId, 'staff', 'email', $id);
    return true;
}

function patient_email_recheck_recipient(int $id, ?int $actorPersonId): bool
{
    $db = auth_db();
    $stmt = $db->prepare('SELECT patient_person_id, status FROM email_queue WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || (int) ($row['patient_person_id'] ?? 0) < 1 || (string) $row['status'] !== 'blocked') return false;
    $recipient = patient_email_recipient((int) $row['patient_person_id']);
    if (!$recipient) return false;
    $update = $db->prepare("UPDATE email_queue SET recipient_email = ?, recipient_name = ?, blocked_reason = NULL, retryable = 1, next_attempt_at = NOW(), delivery_state = NULL, delivery_state_updated_at = NULL WHERE id = ? AND status = 'blocked'");
    $update->execute([
        (string) $recipient['email'],
        trim($recipient['first_name'] . ' ' . ($recipient['middle_name'] ?? '') . ' ' . $recipient['last_name']) ?: 'Patient',
        $id,
    ]);
    if ($update->rowCount() !== 1) return false;
    audit_log_event('email', 'email_recipient_repaired', $actorPersonId, 'staff', 'email', $id, ['recipient' => $recipient['email']]);
    return true;
}

function patient_email_retry_blocked(int $id, ?int $actorPersonId, string $reason = ''): bool
{
    if (!patient_email_recheck_recipient($id, $actorPersonId)) return false;
    $db = auth_db();
    $stmt = $db->prepare("UPDATE email_queue SET status = 'pending', available_at = NOW(), next_attempt_at = NOW(), retryable = 1, manual_attempts = manual_attempts + 1, last_manual_retry_at = NOW(), retry_reason = ? WHERE id = ? AND status = 'blocked'");
    $stmt->execute([trim($reason) ?: null, $id]);
    if ($stmt->rowCount() !== 1) return false;
    audit_log_event('email', 'email_blocked_retry_requested', $actorPersonId, 'staff', 'email', $id, ['reason' => trim($reason)]);
    return true;
}

function patient_email_process_now(int $id, ?int $actorPersonId): array
{
    $db = auth_db();
    $check = $db->prepare("SELECT status, queue_key FROM email_queue WHERE id = ? AND status = 'pending' LIMIT 1");
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'paused' => false];
    $result = patient_email_process_queue((string) ($row['queue_key'] ?? ''), 1, null, $id);
    audit_log_event('email', 'email_processed_now', $actorPersonId, 'staff', 'email', $id, $result);
    return $result;
}

function patient_email_reschedule(int $id, string $scheduledAt, ?int $actorPersonId): bool
{
    $timestamp = strtotime($scheduledAt);
    if ($timestamp === false || $timestamp <= time()) return false;
    $stmt = auth_db()->prepare("UPDATE email_queue SET available_at = ?, next_attempt_at = ?, status = 'pending', retryable = 1 WHERE id = ? AND status = 'pending'");
    $formatted = date('Y-m-d H:i:s', $timestamp);
    $stmt->execute([$formatted, $formatted, $id]);
    if ($stmt->rowCount() !== 1) return false;
    audit_log_event('email', 'email_rescheduled', $actorPersonId, 'staff', 'email', $id, ['scheduled_at' => $formatted]);
    return true;
}

function patient_email_update_pending(int $id, string $subject, string $body, ?int $actorPersonId): bool
{
    $subject = trim($subject);
    $body = trim($body);
    if ($subject === '' || mb_strlen($subject) > 180 || $body === '' || mb_strlen($body) > 10000) return false;
    $stmt = auth_db()->prepare("UPDATE email_queue SET subject = ?, html_body = ?, edited_at = NOW(), edited_by_person_id = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([$subject, cliniq_custom_email_body($body, (string) (clinic_profile_settings()['system_name'] ?? 'CLINiQ Clinic')), $actorPersonId ?: null, $id]);
    if ($stmt->rowCount() !== 1) return false;
    audit_log_event('email', 'email_edited', $actorPersonId, 'staff', 'email', $id);
    return true;
}

function patient_email_add_note(int $id, string $note, ?int $actorPersonId): bool
{
    $note = trim($note);
    if ($note === '' || mb_strlen($note) > 2000) return false;
    $stmt = auth_db()->prepare('UPDATE email_queue SET follow_up_note = ? WHERE id = ?');
    $stmt->execute([$note, $id]);
    if ($stmt->rowCount() !== 1) return false;
    audit_log_event('email', 'email_follow_up_note_added', $actorPersonId, 'staff', 'email', $id, ['note' => $note]);
    return true;
}

function patient_email_assign_follow_up(int $id, ?int $staffId, ?string $dueAt, ?int $actorPersonId): bool
{
    $due = $dueAt ? strtotime($dueAt) : false;
    if ($dueAt !== null && $due === false) return false;
    $stmt = auth_db()->prepare('UPDATE email_queue SET follow_up_required = 1, resolved_at = NULL, resolved_by_person_id = NULL, follow_up_assigned_to_person_id = ?, follow_up_due_at = ? WHERE id = ? AND status IN (\'failed\', \'blocked\', \'sent\')');
    $stmt->execute([$staffId ?: null, $due === false ? null : date('Y-m-d H:i:s', $due), $id]);
    if ($stmt->rowCount() !== 1) return false;
    audit_log_event('email', 'email_follow_up_assigned', $actorPersonId, 'staff', 'email', $id, ['assigned_to_person_id' => $staffId, 'due_at' => $due === false ? null : date('Y-m-d H:i:s', $due)]);
    return true;
}

function patient_email_delivery_timeline(int $id): array
{
    ensure_audit_log_schema();
    $stmt = auth_db()->prepare('SELECT a.*, p.first_name, p.middle_name, p.last_name FROM audit_logs a LEFT JOIN people p ON p.id = a.actor_person_id WHERE a.target_type = \'email\' AND a.target_id = ? ORDER BY a.created_at, a.id');
    $stmt->execute([$id]);
    return $stmt->fetchAll() ?: [];
}

function patient_email_bulk_action(array $ids, string $action, ?int $actorPersonId): array
{
    $allowed = ['retry', 'cancel', 'follow_up', 'resolve_follow_up'];
    if (!in_array($action, $allowed, true)) return ['processed' => 0, 'failed' => count($ids)];
    $processed = 0; $failed = 0;
    foreach (array_values(array_unique(array_filter(array_map('intval', $ids)))) as $id) {
        $ok = match ($action) {
            'retry' => patient_email_retry($id, $actorPersonId),
            'cancel' => patient_email_cancel($id, $actorPersonId, 'Bulk cancellation'),
            'follow_up' => patient_email_mark_follow_up($id, $actorPersonId, true),
            'resolve_follow_up' => patient_email_resolve_follow_up($id, $actorPersonId),
        };
        $ok ? $processed++ : $failed++;
    }
    audit_log_event('email', 'email_bulk_action', $actorPersonId, 'staff', 'email', null, ['action' => $action, 'processed' => $processed, 'failed' => $failed]);
    return ['processed' => $processed, 'failed' => $failed];
}

function patient_email_cancel(int $id, ?int $actorPersonId, string $reason = 'Cancelled by staff'): bool
{
    $stmt = auth_db()->prepare("UPDATE email_queue SET status = 'cancelled', cancelled_at = NOW(), cancelled_by_person_id = ?, cancellation_reason = ? WHERE id = ? AND status IN ('pending','failed')");
    $stmt->execute([$actorPersonId ?: null, trim($reason) ?: 'Cancelled by staff', $id]);
    if ($stmt->rowCount() !== 1) return false;
    audit_log_event('email', 'email_cancelled', $actorPersonId, 'staff', 'email', $id);
    return true;
}

function patient_email_mark_follow_up(int $id, ?int $actorPersonId, bool $required = true): bool
{
    $stmt = auth_db()->prepare('UPDATE email_queue SET follow_up_required = ?, resolved_at = NULL, resolved_by_person_id = NULL WHERE id = ? AND status IN (\'failed\', \'blocked\', \'sent\')');
    $stmt->execute([$required ? 1 : 0, $id]);
    if ($stmt->rowCount() !== 1) return false;
    audit_log_event('email', $required ? 'email_marked_for_follow_up' : 'email_follow_up_resolved', $actorPersonId, 'staff', 'email', $id);
    return true;
}

function patient_email_resolve_follow_up(int $id, ?int $actorPersonId): bool
{
    $stmt = auth_db()->prepare('UPDATE email_queue SET follow_up_required = 0, resolved_at = NOW(), resolved_by_person_id = ? WHERE id = ? AND follow_up_required = 1');
    $stmt->execute([$actorPersonId ?: null, $id]);
    if ($stmt->rowCount() !== 1) return false;
    audit_log_event('email', 'email_follow_up_resolved', $actorPersonId, 'staff', 'email', $id);
    return true;
}

function patient_email_resend(int $id, ?int $actorPersonId): int
{
    $stmt = auth_db()->prepare('SELECT * FROM email_queue WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return 0;
    $newId = patient_email_queue(array_merge($row, [
        'dedupe_key' => null,
        'origin' => 'manual',
        'created_by_person_id' => $actorPersonId,
        'resent_from_id' => $id,
        'queue_key' => 'patient_email',
    ]));
    if ($newId) audit_log_event('email', 'email_resent', $actorPersonId, 'staff', 'email', $newId, ['resent_from_id' => $id]);
    return $newId;
}

function patient_email_due_automations(): void
{
    // The queue endpoint and Email Center call this idempotently. Staff requests
    // therefore keep reminders moving even when the optional scheduler is absent.
    $db = auth_db();
    if (patient_email_automation_enabled('appointment_reminders')) {
        $appointments = $db->query("SELECT appointment_id, patient_id, appointment_datetime FROM appointments WHERE status = 'Scheduled' AND appointment_datetime >= NOW() AND appointment_datetime < DATE_ADD(NOW(), INTERVAL 25 HOUR)")->fetchAll();
        foreach ($appointments as $appointment) {
            $when = date('F j, Y \\a\\t g:i A', strtotime((string) $appointment['appointment_datetime']));
            patient_email_queue_notification((int) $appointment['patient_id'], 'appointment_reminder', 'appointment_reminders', 'Appointment reminder', "This is a reminder that your clinic appointment is scheduled for {$when}.", 'appointment', (int) $appointment['appointment_id'], null, null, 'reminder', true);
        }
    }
    $today = date('Y-m-d');
    foreach (ape_fetch_records() as $record) {
        foreach (ape_patient_document_action_summaries($record) as $action) {
            $due = trim((string) ($action['due_at'] ?? ''));
            $eventType = trim((string) ($action['email_event_type'] ?? ''));
            $apeId = (int) ($action['email_source_id'] ?? $record['ape_id'] ?? 0);
            $patientId = (int) ($action['patient_person_id'] ?? $record['patient_id'] ?? 0);
            if ($due === '' || $eventType === '' || $apeId < 1 || $patientId < 1) continue;

            // A document due tomorrow receives one follow-up reminder. Corrections
            // are queued by the return workflow itself and are never duplicated here.
            if (($action['requirement_group'] ?? '') === 'follow_up'
                && ($action['priority'] ?? '') === 'waiting_on_patient'
                && date('Y-m-d', strtotime($due . ' -1 day')) === $today) {
                patient_email_queue_notification($patientId, 'ape_follow_up_reminder', 'ape_follow_up_reminders', 'APE follow-up due tomorrow', 'Your APE follow-up is due tomorrow. Please submit the requested document or contact the clinic if you need assistance.', 'ape', $apeId, null, null, 'reminder', true);
            }

            if (($action['priority'] ?? '') !== 'overdue') continue;
            $isFollowUp = ($action['requirement_group'] ?? '') !== 'initial';
            patient_email_queue_notification(
                $patientId,
                $eventType,
                $isFollowUp ? 'ape_follow_up_overdue' : 'ape_overdue',
                $isFollowUp ? 'APE follow-up is overdue' : 'APE documents are overdue',
                $isFollowUp
                    ? 'Your APE follow-up is overdue. Please submit the requested document or contact the clinic as soon as possible.'
                    : 'Your remaining APE documents are overdue. Please upload the outstanding documents or contact the clinic.',
                'ape',
                $apeId,
                null,
                null,
                $isFollowUp ? 'overdue' : 'documents_overdue',
                true
            );
        }
    }
}

function patient_email_summary(): array
{
    $db = auth_db();
    $row = $db->query("SELECT
        COUNT(*) AS total,
        SUM(status = 'pending') AS pending,
        SUM(status = 'processing') AS processing,
        SUM(status = 'sent') AS sent,
        SUM(status = 'failed') AS failed,
        SUM(status = 'blocked') AS blocked,
        SUM(status = 'cancelled') AS cancelled
        ,SUM(status IN ('pending','failed') AND retryable = 1) AS retryable,
         SUM(follow_up_required = 1 AND resolved_at IS NULL) AS follow_up,
         SUM(status = 'failed' AND retryable = 0) AS max_attempts,
        MIN(CASE WHEN status IN ('pending','failed') AND retryable = 1 THEN COALESCE(next_attempt_at, available_at) END) AS oldest_pending
        FROM email_queue")->fetch() ?: [];
    $capacity = patient_email_capacity_snapshot($db);
    return [
        'total' => $row['total'] ?? 0,
        'pending' => $row['pending'] ?? 0,
        'processing' => $row['processing'] ?? 0,
        'sent' => $row['sent'] ?? 0,
        'failed' => $row['failed'] ?? 0,
        'blocked' => $row['blocked'] ?? 0,
        'cancelled' => $row['cancelled'] ?? 0,
        'retryable' => $row['retryable'] ?? 0,
        'follow_up' => $row['follow_up'] ?? 0,
        'max_attempts' => $row['max_attempts'] ?? 0,
        'oldest_pending' => $row['oldest_pending'] ?? null,
        'queue_paused' => patient_email_queue_is_paused(),
        'worker_heartbeat' => cliniq_setting_read('mail.worker_heartbeat', []),
        'capacity' => $capacity,
    ];
}

/**
 * Return the small set of email records that require a staff decision now.
 * This is deliberately separate from the full history query so the Email
 * Center can open as a task inbox instead of an undifferentiated log.
 *
 * @return array<int, array<string, mixed>>
 */
function patient_email_attention_items(int $limit = 12): array
{
    $db = auth_db();
    $limit = max(1, min(50, $limit));
    $items = [];

        $queue = $db->query("SELECT q.*,
            TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS patient_name
        FROM email_queue q
        LEFT JOIN people p ON p.id = q.patient_person_id
        WHERE q.status IN ('failed', 'blocked')
           OR (q.status IN ('pending', 'processing') AND q.available_at <= NOW())
        ORDER BY q.created_at ASC, q.id ASC
        LIMIT 100")->fetchAll();

    foreach ($queue as $row) {
        $isFailed = (string) $row['status'] === 'failed';
        $isBlocked = (string) $row['status'] === 'blocked';
        $isDue = !$isFailed && in_array((string) $row['status'], ['pending', 'processing'], true);
        $eventLabel = ucwords(str_replace('_', ' ', (string) ($row['event_type'] ?: 'patient email')));
        $items[] = [
            'kind' => $isBlocked ? 'blocked' : ($isFailed ? 'failed' : 'overdue'),
            'priority' => ($isFailed || $isBlocked) ? 1 : 2,
            'title' => $isBlocked ? 'Email blocked' : ($isFailed ? 'Delivery failed' : 'Email is waiting to be sent'),
            'message' => $isBlocked
                ? ((string) ($row['blocked_reason'] ?: 'The email could not be sent until the recipient issue is resolved.'))
                : ($isFailed
                    ? ((string) ($row['last_error'] ?: 'The email service could not deliver this message.'))
                    : 'This urgent email is past its scheduled send time and needs review.'),
            'patient_name' => (string) ($row['patient_name'] ?: $row['recipient_name'] ?: 'Patient'),
            'recipient_email' => (string) ($row['recipient_email'] ?? ''),
            'event_label' => $eventLabel,
            'email_id' => (int) $row['id'],
            'patient_person_id' => (int) ($row['patient_person_id'] ?? 0),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'source_id' => (int) ($row['source_id'] ?? 0),
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'available_at' => (string) $row['available_at'],
            'event_type' => (string) ($row['event_type'] ?? ''),
            'action' => $isBlocked ? 'retry_blocked' : ($isFailed ? 'retry' : 'process'),
            'action_label' => $isBlocked ? 'Recheck recipient' : ($isFailed ? 'Retry email' : 'Review and send'),
        ];
    }

    $missingAddressQueries = [
        "SELECT a.appointment_id AS source_id, a.patient_id AS patient_person_id,
                TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS patient_name,
                COALESCE(ac.email, '') AS recipient_email
            FROM appointments a
            INNER JOIN people p ON p.id = a.patient_id
            LEFT JOIN accounts ac ON ac.person_id = a.patient_id
            WHERE a.status = 'Scheduled'
              AND a.appointment_datetime >= NOW()
              AND a.appointment_datetime < DATE_ADD(NOW(), INTERVAL 25 HOUR)
            LIMIT 100",
        "SELECT ar.ape_id AS source_id, ar.patient_id AS patient_person_id,
                TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS patient_name,
                COALESCE(ac.email, '') AS recipient_email,
                ar.follow_up_required, ar.follow_up_due_date, ar.exam_date,
                EXISTS (SELECT 1 FROM ape_requirements r
                    WHERE r.ape_id = ar.ape_id
                      AND COALESCE(r.upload_group, 'initial') = 'initial'
                      AND r.status <> 'Verified'
                      AND COALESCE(r.upload_due_date, DATE_ADD(ar.exam_date, INTERVAL 7 DAY)) < CURDATE()
                ) AS documents_overdue
            FROM ape_records ar
            INNER JOIN people p ON p.id = ar.patient_id
            LEFT JOIN accounts ac ON ac.person_id = ar.patient_id
            WHERE ar.workflow_status <> 'Cleared'
              AND ar.clearance_status <> 'Cleared'
              AND ((ar.follow_up_required = 1 AND ar.follow_up_due_date <= DATE_ADD(CURDATE(), INTERVAL 1 DAY))
                   OR (ar.exam_date IS NOT NULL AND EXISTS (SELECT 1 FROM ape_requirements r2
                       WHERE r2.ape_id = ar.ape_id
                         AND COALESCE(r2.upload_group, 'initial') = 'initial'
                         AND r2.status <> 'Verified'
                         AND COALESCE(r2.upload_due_date, DATE_ADD(ar.exam_date, INTERVAL 7 DAY)) <= CURDATE())))
            LIMIT 100",
    ];

    foreach ($missingAddressQueries as $queryIndex => $query) {
        try {
            foreach ($db->query($query)->fetchAll() as $row) {
                if (filter_var((string) ($row['recipient_email'] ?? ''), FILTER_VALIDATE_EMAIL)) continue;
                $isAppointment = $queryIndex === 0;
                $items[] = [
                    'kind' => 'missing_address',
                    'priority' => 1,
                    'title' => 'Patient email address needed',
                    'message' => 'Automatic delivery was not queued because this patient has no valid email address.',
                    'patient_name' => (string) ($row['patient_name'] ?: 'Patient'),
                    'recipient_email' => (string) ($row['recipient_email'] ?? ''),
                    'event_label' => $isAppointment ? 'Appointment reminder' : 'APE reminder or escalation',
                    'email_id' => 0,
                    'patient_person_id' => (int) ($row['patient_person_id'] ?? 0),
                    'source_type' => $isAppointment ? 'appointment' : 'ape',
                    'source_id' => (int) $row['source_id'],
                    'status' => 'blocked',
                    'created_at' => date('Y-m-d H:i:s'),
                    'available_at' => '',
                    'action' => 'open_patient',
                    'action_label' => 'Open patient',
                ];
            }
        } catch (Throwable $e) {
            // A missing optional workflow table must not prevent Email Center
            // from showing the durable queue records.
        }
    }

    usort($items, static function (array $left, array $right): int {
        $priority = ((int) $left['priority']) <=> ((int) $right['priority']);
        if ($priority !== 0) return $priority;
        return strcmp((string) $left['created_at'], (string) $right['created_at']);
    });

    return array_slice($items, 0, $limit);
}

/**
 * Return scheduled automatic emails for the secondary upcoming section.
 *
 * @return array<int, array<string, mixed>>
 */
function patient_email_upcoming_items(int $limit = 8): array
{
    $limit = max(1, min(30, $limit));
    $stmt = auth_db()->prepare("SELECT q.*,
            TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS patient_name
        FROM email_queue q
        LEFT JOIN people p ON p.id = q.patient_person_id
        WHERE q.status = 'pending' AND q.available_at > NOW()
        ORDER BY q.available_at ASC, q.id ASC
        LIMIT {$limit}");
    $stmt->execute();
    return $stmt->fetchAll();
}

function patient_email_history(array $filters = [], int $page = 1, int $perPage = 20): array
{
    $db = auth_db();
    $where = ['1 = 1']; $params = [];
    $search = trim((string) ($filters['search'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));
    $eventType = trim((string) ($filters['event_type'] ?? ''));
    $sourceType = trim((string) ($filters['source_type'] ?? ''));
    $origin = trim((string) ($filters['origin'] ?? ''));
    $sender = trim((string) ($filters['sender'] ?? ''));
    $deliveryState = trim((string) ($filters['delivery_state'] ?? ''));
    $followUp = trim((string) ($filters['follow_up'] ?? ''));
    $assignedTo = (int) ($filters['assigned_to'] ?? 0);
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($search !== '') {
        $where[] = '(q.recipient_email LIKE ? OR q.recipient_name LIKE ? OR q.subject LIKE ? OR q.sender_email LIKE ? OR CONCAT_WS(" ", p.first_name, p.middle_name, p.last_name) LIKE ? OR CAST(q.id AS CHAR) = ?)';
        array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%", $search);
    }
    if (in_array($status, ['pending', 'processing', 'sent', 'failed', 'blocked', 'cancelled'], true)) { $where[] = 'q.status = ?'; $params[] = $status; }
    if ($eventType !== '') { $where[] = 'q.event_type = ?'; $params[] = $eventType; }
    if ($sourceType !== '') { $where[] = 'q.source_type = ?'; $params[] = $sourceType; }
    if (in_array($origin, ['automatic', 'manual', 'system'], true)) { $where[] = 'q.origin = ?'; $params[] = $origin; }
    if ($sender !== '') { $where[] = '(q.sender_email LIKE ? OR CONCAT_WS(" ", actor.first_name, actor.middle_name, actor.last_name) LIKE ?)'; $params[] = "%{$sender}%"; $params[] = "%{$sender}%"; }
    if (in_array($deliveryState, ['smtp_accepted', 'delivered', 'bounced', 'rejected', 'unknown'], true)) { $where[] = 'q.delivery_state = ?'; $params[] = $deliveryState; }
    if ($followUp === 'open') { $where[] = 'q.follow_up_required = 1 AND q.resolved_at IS NULL'; }
    if ($followUp === 'resolved') { $where[] = 'q.follow_up_required = 0 AND q.resolved_at IS NOT NULL'; }
    if ($assignedTo > 0) { $where[] = 'q.follow_up_assigned_to_person_id = ?'; $params[] = $assignedTo; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $where[] = 'DATE(q.created_at) >= ?'; $params[] = $dateFrom; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) { $where[] = 'DATE(q.created_at) <= ?'; $params[] = $dateTo; }
    $whereSql = implode(' AND ', $where);
    $count = $db->prepare("SELECT COUNT(*) FROM email_queue q LEFT JOIN people p ON p.id = q.patient_person_id LEFT JOIN people actor ON actor.id = q.created_by_person_id WHERE {$whereSql}"); $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage)); $page = min(max(1, $page), $pages); $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT q.*, TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS patient_name,
        TRIM(CONCAT_WS(' ', actor.first_name, actor.middle_name, actor.last_name)) AS sender_name
        FROM email_queue q
        LEFT JOIN people p ON p.id = q.patient_person_id
        LEFT JOIN people actor ON actor.id = q.created_by_person_id
        WHERE {$whereSql} ORDER BY q.created_at DESC, q.id DESC LIMIT ?, ?");
    $index = 1; foreach ($params as $value) $stmt->bindValue($index++, $value);
    $stmt->bindValue($index++, $offset, PDO::PARAM_INT); $stmt->bindValue($index, $perPage, PDO::PARAM_INT); $stmt->execute();
    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function patient_email_event_types(): array
{
    return auth_db()->query("SELECT DISTINCT event_type FROM email_queue WHERE event_type IS NOT NULL AND event_type <> '' ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);
}
