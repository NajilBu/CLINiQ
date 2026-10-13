<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AuditLog.php';
require_once __DIR__ . '/../../app/services/PatientEmail.php';
require_once __DIR__ . '/../../app/services/ClinicWorkCenter.php';

require_login();
$user = current_user();
$tab = ($_GET['tab'] ?? 'audit') === 'email' ? 'email' : 'audit';
$requestedEmailView = (string) ($_GET['view'] ?? (isset($_GET['patient_person_id']) ? 'history' : 'work'));
$emailView = in_array($requestedEmailView, ['work', 'send', 'history', 'admin'], true) ? $requestedEmailView : 'work';
$emailRoles = ['admin', 'doctor', 'nurse'];
if ($tab === 'audit' && ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('You are not authorized to view the audit log.');
}
if ($tab === 'email' && !in_array((string) ($user['role'] ?? ''), $emailRoles, true)) {
    http_response_code(403);
    exit('You are not authorized to use Email Center.');
}
if ($tab === 'email' && $emailView === 'admin' && (string) ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Only administrators can use Email Center controls.');
}
ensure_audit_log_schema();

if ($tab === 'email') {
    $actorPersonId = (int) ($user['person_id'] ?? 0) ?: null;
    $emailRole = (string) ($user['role'] ?? '');
    $canManageEmailOperations = in_array($emailRole, $emailRoles, true);
    $canManageEmailSettings = $emailRole === 'admin';
    $apeReminderBatchId = filter_var($_GET['ape_batch'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $apeFollowUpApeId = filter_var($_GET['ape_follow_up_ape_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $highlightReadyApeFollowUps = $emailView === 'work' && (string) ($_GET['ape_follow_up_ready'] ?? '') === '1';
    $emailOpsActions = ['process', 'process_now', 'retry', 'retry_blocked', 'cancel', 'resend', 'follow_up', 'resolve_follow_up', 'add_note', 'automation', 'custom_send', 'pause_queue', 'resume_queue', 'reschedule', 'edit_pending', 'assign_follow_up', 'send_clinic_reminders'];
    $adminEmailActions = ['process', 'process_now', 'automation', 'pause_queue', 'resume_queue'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $emailAction = (string) ($_POST['email_action'] ?? '');
        try {
            if (!in_array($emailAction, $emailOpsActions, true)) {
                throw new InvalidArgumentException('Unknown email action.');
            }
            if (!$canManageEmailOperations) {
                throw new RuntimeException('This email action requires an administrator, doctor, or nurse.');
            }
            if (in_array($emailAction, $adminEmailActions, true) && !$canManageEmailSettings) {
                throw new RuntimeException('Only an administrator can change queue-wide email controls.');
            }
            if ($emailAction === 'process') {
                patient_email_due_automations();
                $result = patient_email_process_queue(null, 50);
                audit_log_event('email', 'email_queue_processed', $actorPersonId, 'staff', 'email', null, $result);
                flash_message(!empty($result['quota_limited']) ? 'warning' : 'success', !empty($result['paused']) ? 'The email queue is paused. No automatic emails were processed.' : "Processed {$result['processed']} email(s): {$result['sent']} sent, {$result['failed']} failed, " . (int) ($result['deferred'] ?? 0) . ' deferred by capacity, ' . (int) ($result['quota_limited'] ?? 0) . ' quota-limited.');
            } elseif ($emailAction === 'send_clinic_reminders') {
                $reviewed = [];
                foreach ((array) ($_POST['reminders'] ?? []) as $entry) {
                    if (!is_array($entry) || empty($entry['selected'])) continue;
                    $reviewed[] = [
                        'patient_person_id' => (int) ($entry['patient_person_id'] ?? 0),
                        'source_type' => trim((string) ($entry['source_type'] ?? 'ape')),
                        'source_id' => (int) ($entry['source_id'] ?? 0),
                        'event_type' => trim((string) ($entry['event_type'] ?? '')),
                        'follow_up_number' => (int) ($entry['follow_up_number'] ?? 0),
                        'subject' => (string) ($entry['subject'] ?? ''),
                        'message' => (string) ($entry['message'] ?? ''),
                    ];
                }
                $postedBatchId = filter_var($_POST['ape_batch'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
                $result = clinic_work_center_send_reminders((int) $actorPersonId, $reviewed, $postedBatchId);
                flash_message(($result['blocked'] > 0 || $result['failed'] > 0) ? 'warning' : 'success', sprintf(
                    'Follow-ups complete: %d sent, %d queued, %d deferred, %d skipped, %d blocked, %d failed. APE deadlines were not changed.',
                    $result['sent'], $result['queued'], $result['deferred'], $result['skipped'], $result['blocked'], $result['failed']
                ));
            } elseif ($emailAction === 'retry') {
                flash_message('success', patient_email_retry((int) ($_POST['id'] ?? 0), $actorPersonId) ? 'Email queued for retry.' : 'Only failed emails can be retried.');
            } elseif ($emailAction === 'retry_blocked') {
                flash_message('success', patient_email_retry_blocked((int) ($_POST['id'] ?? 0), $actorPersonId, (string) ($_POST['retry_reason'] ?? '')) ? 'Recipient verified and email queued for retry.' : 'The recipient is still unavailable or invalid.');
            } elseif ($emailAction === 'process_now') {
                $result = patient_email_process_now((int) ($_POST['id'] ?? 0), $actorPersonId);
                flash_message(!empty($result['sent']) ? 'success' : 'warning', !empty($result['sent']) ? 'Email sent through the queue.' : 'The email was not sent. Review its delivery details.');
            } elseif ($emailAction === 'cancel') {
                flash_message('success', patient_email_cancel((int) ($_POST['id'] ?? 0), $actorPersonId, (string) ($_POST['cancellation_reason'] ?? 'Cancelled by staff')) ? 'Email cancelled.' : 'Only pending or failed emails can be cancelled.');
            } elseif ($emailAction === 'resend') {
                $newId = patient_email_resend((int) ($_POST['id'] ?? 0), $actorPersonId);
                if ($newId) { patient_email_process_queue('patient_email', 1); flash_message('success', 'Email resent and recorded in the Email Center.'); }
                else flash_message('error', 'The email could not be resent.');
            } elseif ($emailAction === 'follow_up') {
                $ok = patient_email_mark_follow_up((int) ($_POST['id'] ?? 0), $actorPersonId, true);
                flash_message($ok ? 'success' : 'error', $ok ? 'Email issue marked for follow-up.' : 'The email issue could not be marked for follow-up.');
            } elseif ($emailAction === 'resolve_follow_up') {
                $ok = patient_email_resolve_follow_up((int) ($_POST['id'] ?? 0), $actorPersonId);
                flash_message($ok ? 'success' : 'error', $ok ? 'Email follow-up marked resolved.' : 'The follow-up item could not be resolved.');
            } elseif ($emailAction === 'add_note') {
                $ok = patient_email_add_note((int) ($_POST['id'] ?? 0), (string) ($_POST['follow_up_note'] ?? ''), $actorPersonId);
                flash_message($ok ? 'success' : 'error', $ok ? 'Follow-up note saved.' : 'The follow-up note could not be saved.');
            } elseif ($emailAction === 'assign_follow_up') {
                $ok = patient_email_assign_follow_up((int) ($_POST['id'] ?? 0), (int) ($_POST['staff_id'] ?? 0) ?: null, trim((string) ($_POST['follow_up_due_at'] ?? '')) ?: null, $actorPersonId);
                flash_message($ok ? 'success' : 'error', $ok ? 'Follow-up assignment saved.' : 'The follow-up assignment could not be saved.');
            } elseif ($emailAction === 'reschedule') {
                $ok = patient_email_reschedule((int) ($_POST['id'] ?? 0), (string) ($_POST['scheduled_at'] ?? ''), $actorPersonId);
                flash_message($ok ? 'success' : 'error', $ok ? 'Email rescheduled.' : 'Choose a future time for a pending email.');
            } elseif ($emailAction === 'edit_pending') {
                $ok = patient_email_update_pending((int) ($_POST['id'] ?? 0), (string) ($_POST['subject'] ?? ''), (string) ($_POST['message'] ?? ''), $actorPersonId);
                flash_message($ok ? 'success' : 'error', $ok ? 'Pending email updated.' : 'Only pending emails with valid content can be edited.');
            } elseif ($emailAction === 'automation') {
                patient_email_save_automation_settings($_POST['automation'] ?? [], $actorPersonId);
                flash_message('success', 'Email automation settings updated.');
            } elseif ($emailAction === 'pause_queue' || $emailAction === 'resume_queue') {
                patient_email_set_queue_paused($emailAction === 'pause_queue', $actorPersonId);
                flash_message('success', $emailAction === 'pause_queue' ? 'Automatic email processing paused.' : 'Automatic email processing resumed.');
            } elseif ($emailAction === 'custom_send') {
                $subject = trim((string) ($_POST['subject'] ?? '')); $message = trim((string) ($_POST['message'] ?? ''));
                if ($subject === '' || mb_strlen($subject) > 180 || $message === '' || mb_strlen($message) > 10000) throw new InvalidArgumentException('Enter a valid subject and message.');
                $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['patient_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
                if ($ids === []) throw new InvalidArgumentException('Select at least one patient.');
                if (count($ids) > 50) throw new InvalidArgumentException('Send to no more than 50 patients at a time.');
                $queued = 0; $accepted = 0; $pending = 0; $failed = []; $queueIds = [];
                $manualSendKey = 'manual-' . bin2hex(random_bytes(16));
                foreach ($ids as $patientId) {
                    $queueId = patient_email_dispatch_event([
                        'patient_person_id' => $patientId,
                        'event_type' => 'custom_patient_email',
                        'origin' => 'manual',
                        'created_by_person_id' => $actorPersonId,
                        'dedupe_suffix' => $manualSendKey,
                        'subject' => $subject,
                        'message' => $message,
                        'deliver_now' => true,
                    ]);
                    if ($queueId <= 0) continue;
                    $queued++;
                    $queueIds[] = $queueId;
                    $statusStmt = auth_db()->prepare('SELECT status, last_error FROM email_queue WHERE id = ? LIMIT 1');
                    $statusStmt->execute([$queueId]);
                    $delivery = $statusStmt->fetch() ?: [];
                    if (($delivery['status'] ?? '') === 'sent') $accepted++;
                    elseif (($delivery['status'] ?? '') === 'pending') $pending++;
                    else $failed[] = trim((string) ($delivery['last_error'] ?? 'Delivery failed.')) ?: 'Delivery failed.';
                }
                $queueLabel = implode(', ', $queueIds);
                if ($queued === 0) flash_message('error', 'No selected patient had an active account with a valid email address.');
                elseif ($failed !== []) flash_message('error', "Email queue #{$queueLabel}: {$accepted} SMTP accepted, {$pending} pending, and " . count($failed) . " failed. " . $failed[0]);
                elseif ($pending > 0) flash_message('warning', "Email queue #{$queueLabel}: {$accepted} SMTP accepted and {$pending} pending for delivery.");
                else flash_message('success', "Email queue #{$queueLabel}: SMTP accepted. Final delivery updates when the provider reports it.");
            }
        } catch (Throwable $e) { flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage()); }
        header('Location: index.php?tab=email' . ($apeReminderBatchId ? '&ape_batch=' . $apeReminderBatchId : '') . '#needs-attention'); exit;
    }

    $workFilters = [
        'search' => trim((string) ($_GET['work_search'] ?? '')),
        'kind' => trim((string) ($_GET['work_kind'] ?? '')),
        'priority' => trim((string) ($_GET['work_priority'] ?? '')),
        'type' => trim((string) ($_GET['work_type'] ?? '')),
        'age' => trim((string) ($_GET['work_age'] ?? '')),
        'area' => trim((string) ($_GET['work_area'] ?? '')),
        'status' => trim((string) ($_GET['work_status'] ?? '')),
        'due_date' => trim((string) ($_GET['work_due_date'] ?? '')),
    ];
    $allWorkItems = $emailView === 'history' ? [] : clinic_work_center_items(['kind' => 'workflow', 'email_relevant' => true], 1000);
    $workItems = clinic_work_center_filter_items($allWorkItems, $workFilters, 1000);
    $reminderCandidates = $emailView === 'history' ? [] : clinic_work_center_reminder_candidates($apeReminderBatchId);
    $needsReminderItems = array_values(array_filter($reminderCandidates, static fn(array $item): bool => !empty($item['can_send_reminder'])));
    $cooldownReminderItems = array_values(array_filter($reminderCandidates, static fn(array $item): bool => ($item['reminder_state'] ?? '') === 'cooldown'));
    $deliveryReminderItems = array_values(array_filter($reminderCandidates, static fn(array $item): bool => in_array((string) ($item['reminder_state'] ?? ''), ['delivery_pending', 'delivery_attention', 'initial_delivery_required', 'recipient_unavailable'], true)));
    $limitedReminderItems = array_values(array_filter($reminderCandidates, static fn(array $item): bool => ($item['reminder_state'] ?? '') === 'limit_reached'));
    $workItemKey = static fn(array $item): string => clinic_work_center_email_key($item);
    $readyReminderKeys = array_fill_keys(array_map($workItemKey, $needsReminderItems), true);
    $existingWorkKeys = array_fill_keys(array_map($workItemKey, $allWorkItems), true);
    foreach ($needsReminderItems as $reminderItem) {
        $key = $workItemKey($reminderItem);
        if (isset($existingWorkKeys[$key])) continue;
        $reminderItem['kind'] = 'workflow';
        $reminderItem['category'] = 'Clinic work';
        $reminderItem['action_type'] = 'compose_email';
        $reminderItem['action_label'] = 'Send reminder';
        $reminderItem['email_subject'] = 'APE reminder — action needed';
        $reminderItem['email_message'] = 'Our records show that you still have an outstanding APE action. Please log in to your student portal and complete the required step, or contact the clinic if you need assistance.';
        $reminderItem['status'] = 'Reminder due';
        if (!empty($reminderItem['current_deadline'])) $reminderItem['due_at'] = $reminderItem['current_deadline'];
        $allWorkItems[] = $reminderItem;
        $existingWorkKeys[$key] = true;
        $matches = true;
        if ($workFilters['search'] !== '' && !str_contains(strtolower(implode(' ', [(string) $reminderItem['patient_name'], (string) $reminderItem['title'], (string) $reminderItem['explanation']])), strtolower($workFilters['search']))) $matches = false;
        if ($workFilters['priority'] !== '' && (string) $reminderItem['priority'] !== $workFilters['priority']) $matches = false;
        if ($workFilters['area'] !== '' && (string) $reminderItem['area'] !== $workFilters['area']) $matches = false;
        if ($workFilters['age'] !== '' && !workflow_attention_age_matches($reminderItem, $workFilters['age'])) $matches = false;
        if ($matches) $workItems[] = $reminderItem;
    }
    if (($_GET['export'] ?? '') === 'work_csv') {
        clinic_work_center_export($workItems);
        exit;
    }
    $workSummary = clinic_work_center_summary($allWorkItems);
    $workAreas = array_values(array_unique(array_map(static fn(array $item): string => (string) $item['area'], $allWorkItems)));
    $workTypes = array_values(array_unique(array_map(static fn(array $item): string => (string) $item['type'], $allWorkItems)));
    $workStatuses = array_values(array_unique(array_map(static fn(array $item): string => (string) $item['status'], $allWorkItems)));
    sort($workAreas); sort($workTypes); sort($workStatuses);
    $workLabel = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
    $workBadge = static function (string $priority): string {
        return match ($priority) {
            'urgent', 'overdue' => 'badge-high',
            'waiting_on_patient' => 'badge-pending',
            'scheduled' => 'badge-completed',
            default => 'badge-in-progress',
        };
    };
    $workCardUrl = static function (array $changes): string {
        $query = ['tab' => 'email'];
        foreach ($changes as $key => $value) {
            if ($value !== '') $query[$key] = $value;
        }
        return 'index.php?' . http_build_query($query) . '#needs-attention';
    };
    $filters = [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'status' => trim((string) ($_GET['status'] ?? '')),
        'event_type' => trim((string) ($_GET['event_type'] ?? '')),
        'source_type' => trim((string) ($_GET['source_type'] ?? '')),
        'origin' => trim((string) ($_GET['origin'] ?? '')),
        'sender' => trim((string) ($_GET['sender'] ?? '')),
        'delivery_state' => trim((string) ($_GET['delivery_state'] ?? '')),
        'follow_up' => trim((string) ($_GET['follow_up'] ?? '')),
        'assigned_to' => (int) ($_GET['assigned_to'] ?? 0),
        'patient_person_id' => (int) ($_GET['patient_person_id'] ?? 0),
        'date_from' => trim((string) ($_GET['date_from'] ?? '')),
        'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    ];
    $summary = patient_email_summary();
    $attentionItems = patient_email_attention_items();
    $upcomingItems = $emailView === 'work' ? patient_email_upcoming_items() : [];
    $emailIssueCount = (int) $summary['actionable_issues'];
    if (in_array(($_GET['export'] ?? ''), ['csv', 'email_csv'], true)) {
        $export = patient_email_history($filters, 1, 5000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cliniq-email-history.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['ID', 'Patient', 'Recipient email', 'Event type', 'Source type', 'Source ID', 'Origin', 'Subject', 'Sender', 'Status', 'Attempts', 'Created', 'Scheduled', 'Last attempt', 'Sent', 'Failed', 'Provider message ID', 'Failure reason']);
        foreach ($export['rows'] as $row) {
            fputcsv($out, [(int) $row['id'], $row['patient_name'] ?: $row['recipient_name'], $row['recipient_email'], $row['event_type'], $row['source_type'], $row['source_id'], $row['origin'], $row['subject'], $row['sender_email'] ?: $row['sender_name'], $row['status'], (int) $row['attempts'], $row['created_at'], $row['available_at'], $row['last_attempt_at'], $row['sent_at'], $row['failed_at'], $row['provider_message_id'], $row['last_error'] ?: $row['blocked_reason']]);
        }
        fclose($out);
        exit;
    }
    $history = patient_email_history($filters, (int) ($_GET['page'] ?? 1));
    $followUpHistory = patient_email_history(['follow_up' => 'open'], 1, 20);
    $automation = patient_email_automation_settings();
    $manualMailTemplates = [];
    foreach (cliniq_mail_templates() as $templateKey => $templateDefinition) {
        if (!in_array($templateKey, ['ape_documents_overdue', 'ape_follow_up_required', 'ape_follow_up_reminder', 'ape_follow_up_overdue'], true)) continue;
        $template = (array) ($templateDefinition['template'] ?? []);
        if ((string) ($template['subject'] ?? '') === '' || (string) ($template['message'] ?? '') === '') continue;
        $manualMailTemplates[$templateKey] = [
            'label' => (string) ($templateDefinition['label'] ?? ucwords(str_replace('_', ' ', $templateKey))),
            'subject' => (string) ($template['subject'] ?? ''),
            'message' => (string) ($template['message'] ?? ''),
        ];
    }
    $manualMailTemplates = [
        'ape_action_needed' => [
            'label' => 'APE action needed',
            'subject' => 'APE action needed',
            'message' => 'Our records show that you still have an outstanding APE action. Please log in to https://plpuhs.dpdns.org and complete the required step, or contact the clinic if you need assistance.',
        ],
        'ape_documents_overdue' => [
            'label' => 'Missing APE documents',
            'subject' => 'Your APE documents are incomplete',
            'message' => 'Our records show that your required APE documents are still incomplete. Please log in to https://plpuhs.dpdns.org and upload the outstanding documents, or contact the clinic if you need assistance.',
        ],
    ] + $manualMailTemplates;
    $eventTypes = patient_email_event_types();
    $patientEmailIds = [];
    foreach ($allWorkItems as $workItem) {
        $patientId = (int) ($workItem['patient_person_id'] ?? 0);
        if ($patientId > 0) $patientEmailIds[$patientId] = true;
    }
    $contextEmailLast = [];
    if ($patientEmailIds) {
        $patientIdList = array_keys($patientEmailIds);
        $placeholders = implode(',', array_fill(0, count($patientIdList), '?'));
        $lastEmails = auth_db()->prepare("SELECT patient_person_id, subject, sent_at, created_at FROM email_queue WHERE patient_person_id IN ({$placeholders}) ORDER BY COALESCE(sent_at, created_at) DESC, id DESC");
        $lastEmails->execute($patientIdList);
        foreach ($lastEmails->fetchAll() as $lastEmail) {
            $patientId = (int) ($lastEmail['patient_person_id'] ?? 0);
            if ($patientId > 0 && !isset($contextEmailLast[$patientId])) $contextEmailLast[$patientId] = $lastEmail;
        }
    }
    $mailConfig = mail_settings_configured() ? mail_settings() : [];
    $mailUser = trim((string) ($mailConfig['username'] ?? env_value('MAIL_USER', '')));
    $mailHost = trim((string) ($mailConfig['host'] ?? env_value('MAIL_HOST', '')));
    $mailFrom = (string) ($mailConfig['from_email'] ?? env_value('MAIL_FROM', $mailUser));
    $mailReady = $mailHost !== '' && $mailUser !== '' && filter_var($mailFrom, FILTER_VALIDATE_EMAIL);
    $automationEnabled = count(array_filter($automation));
    $allEmailOpen = $filters['search'] !== '' || $filters['status'] !== '' || $filters['event_type'] !== '' || $filters['source_type'] !== '' || $filters['origin'] !== '' || $filters['sender'] !== '' || $filters['delivery_state'] !== '' || $filters['follow_up'] !== '' || $filters['assigned_to'] > 0 || $filters['patient_person_id'] > 0 || $filters['date_from'] !== '' || $filters['date_to'] !== '';
    $ageLabel = static function (string $timestamp): string {
        if ($timestamp === '') return 'Needs review';
        $seconds = max(0, time() - strtotime($timestamp));
        if ($seconds < 3600) return max(1, (int) floor($seconds / 60)) . ' min ago';
        if ($seconds < 86400) return (int) floor($seconds / 3600) . ' hr ago';
        return (int) floor($seconds / 86400) . ' days ago';
    };
    render_header('Email Center');
    $emailPreviewLogoUrl = app_url(clinic_profile_logo_path(clinic_profile_settings()));
    $emailNav = '<div class="email-command-actions"><nav class="email-view-nav flex flex-wrap items-center gap-2" aria-label="Email Center views">'
        . '<a class="btn btn-sm inline-flex items-center gap-2 ' . ($emailView === 'work' ? 'btn-outline ring-2 ring-primary/30 bg-[#edf7ef] text-primary' : 'btn-outline') . '" href="index.php?tab=email&view=work"' . ($emailView === 'work' ? ' aria-current="page"' : '') . '><span class="material-symbols-outlined text-[18px]" aria-hidden="true">task_alt</span><span>Work</span></a>'
        . '<a class="btn btn-sm inline-flex items-center gap-2 ' . ($emailView === 'history' ? 'btn-outline ring-2 ring-primary/30 bg-[#edf7ef] text-primary' : 'btn-outline') . '" href="index.php?tab=email&view=history#email-history"' . ($emailView === 'history' ? ' aria-current="page"' : '') . '><span class="material-symbols-outlined text-[18px]" aria-hidden="true">history</span><span>History</span></a>'
        . ($canManageEmailSettings ? '<a class="email-admin-link btn btn-sm inline-flex items-center gap-2 ' . ($emailView === 'admin' ? 'btn-outline ring-2 ring-primary/30 bg-[#edf7ef] text-primary' : 'btn-outline') . '" href="index.php?tab=email&view=admin#email-admin"' . ($emailView === 'admin' ? ' aria-current="page"' : '') . '><span class="material-symbols-outlined text-[18px]" aria-hidden="true">admin_panel_settings</span><span>Admin</span></a>' : '')
        . '</nav><span class="email-action-divider" aria-hidden="true"></span>'
        . ($canManageEmailOperations ? '<button type="button" class="btn btn-sm btn-primary email-send-action inline-flex items-center gap-2" onclick="showModal(\'patientEmailComposeModal\')" aria-label="Send a patient email"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span><span>Send email</span></button>' : '')
        . '</div>';
    render_clinic_command_header('Clinical communication', 'Email Center', 'Start with patient work. Open history or administration only when needed.', $emailNav);
    ?>
    <div class="audit-page email-view-<?= e($emailView) ?>">
        <style>
            .email-view-work #all-email, .email-view-work #automation, .email-view-work #queue-controls { display: none; }
            .email-view-history #needs-attention, .email-view-history #recently-reminded, .email-view-history #automation, .email-view-history #queue-controls { display: none; }
            .email-view-send #needs-attention, .email-view-send #recently-reminded, .email-view-send #all-email, .email-view-send #automation, .email-view-send #queue-controls { display: none; }
            .email-view-admin #needs-attention, .email-view-admin #recently-reminded, .email-view-admin #all-email { display: none; }
            .email-view-admin #automation, .email-view-admin #queue-controls { display: block; }
            .email-send-action { border: 2px solid #1f6f47; box-shadow: 0 5px 14px rgba(31, 111, 71, .2); }
            .email-send-action:hover, .email-send-action:focus-visible { box-shadow: 0 7px 18px rgba(31, 111, 71, .28); transform: translateY(-1px); }
            .email-command-actions { display: flex; align-items: center; justify-content: flex-end; gap: .75rem; }
            .email-action-divider { width: 1px; height: 2rem; background: #d8e5dc; }
            .email-admin-link { color: #64748b; }
            @media (max-width: 640px) { .email-command-actions { width: 100%; justify-content: flex-start; flex-wrap: wrap; } .email-action-divider { display: none; } }
            #patientEmailComposeModal .modal-content { max-width: 60rem; padding: 1.5rem; }
            #patientEmailComposeModal .modal-content > div:first-child { border-bottom: 1px solid #e2ebe5; padding-bottom: 1rem; margin-bottom: 1.25rem; }
            #patientEmailComposeModal form > .grid { gap: 1.25rem; }
            #patientEmailComposeModal form > .grid > aside { position: sticky; top: 0; align-self: start; box-shadow: none; background: #fbfdfb; }
            #patientEmailComposeModal form > .grid > aside > div:first-child { padding: 1rem 1.25rem; }
            #patientEmailComposeModal form > .grid > aside > div:last-child { padding: 1.25rem; }
            #patientEmailComposeModal #manualEmailMessage { min-height: 9rem; }
            #patientEmailComposeModal [data-patient-chips] { max-height: 5.5rem; overflow-y: auto; }
            #patientEmailComposeModal form > .grid > div:first-child > div:last-child { border-top: 1px solid #e2ebe5; padding-top: .85rem; margin-top: .1rem; }
            #patientEmailComposeModal form > .grid > aside > div:first-child span:first-child { font-size: 0; background: #fff url('<?= e($emailPreviewLogoUrl) ?>') center / contain no-repeat; }
            #patientEmailComposeModal #manualEmailPreviewHeading:empty::before { content: 'Start writing to preview'; color: #94a3b8; font-size: .95rem; font-weight: 700; }
            @media (max-width: 1024px) { #patientEmailComposeModal .modal-content { max-width: calc(100vw - 2rem); } #patientEmailComposeModal form > .grid > aside { position: static; } }
            @media (max-width: 640px) { #patientEmailComposeModal .modal-content { padding: 1.1rem; border-radius: 1.25rem; } #patientEmailComposeModal .modal-content > div:first-child { margin-bottom: 1rem; } }
        </style>
        <?php $capacity = (array) ($summary['capacity'] ?? []); $workerHealthy = !empty($summary['worker_healthy']); $urgentPatientIds = []; foreach ($allWorkItems as $urgentItem) { if (in_array((string) ($urgentItem['priority'] ?? ''), ['urgent', 'overdue'], true) && (int) ($urgentItem['patient_person_id'] ?? 0) > 0) $urgentPatientIds[(int) $urgentItem['patient_person_id']] = true; } $urgentWorkCount = count($urgentPatientIds); $deliveryWarning = !$mailReady || !$workerHealthy || !empty($summary['queue_paused']) || (string) ($capacity['capacity_status'] ?? '') === 'reached'; ?>
        <section class="clinic-card mb-6 overflow-hidden" aria-label="Patient contact work status">
            <div class="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between <?= $urgentWorkCount > 0 ? 'border-l-4 border-red-500' : 'border-l-4 border-[#3f8256]' ?>">
                <div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl <?= $urgentWorkCount > 0 ? 'bg-red-50 text-red-600' : 'bg-[#e7f4e9] text-primary' ?>"><span class="material-symbols-outlined"><?= $urgentWorkCount > 0 ? 'priority_high' : 'task_alt' ?></span></span><div><p class="font-headline text-lg font-extrabold text-[#17261d] mb-1"><?= $urgentWorkCount > 0 ? number_format($urgentWorkCount) . ' patient' . ($urgentWorkCount === 1 ? '' : 's') . ' need immediate attention' : 'No urgent patient-contact work' ?></p><p class="text-sm text-slate-600 mb-0"><?= $urgentWorkCount > 0 ? 'Start with the urgent and overdue rows below. Each row has one recommended next action.' : 'Review routine follow-ups below or send a patient email when needed.' ?></p></div></div>
                <a class="btn <?= $urgentWorkCount > 0 ? 'btn-primary' : 'btn-outline' ?> text-decoration-none shrink-0" href="<?= e($workCardUrl($urgentWorkCount > 0 ? ['work_priority' => 'urgent'] : [])) ?>"><?= $urgentWorkCount > 0 ? 'Review urgent work' : 'View patient work' ?></a>
            </div>
            <?php if ($deliveryWarning): ?><div class="border-t border-amber-100 bg-amber-50 px-5 py-3 text-sm text-amber-900"><strong>Delivery needs review.</strong> <?= !$mailReady ? 'The email service is unavailable.' : (!empty($summary['queue_paused']) ? 'Automatic email processing is paused.' : (!$workerHealthy ? 'The queue worker is not processing automatically.' : 'The current delivery capacity has been reached.')) ?> <a class="font-bold text-amber-900 underline" href="#all-email">Open email history</a><?php if ($canManageEmailSettings): ?> or use delivery controls below.<?php endif; ?></div><?php endif; ?>


            <?php
            $workCards = [
                ['total', 'Needs attention', 'list_alt', []],
                ['urgent', 'Urgent', 'priority_high', ['work_priority' => 'urgent']],
                ['overdue', 'Overdue', 'schedule', ['work_priority' => 'overdue']],
            ];
            $selectedWorkCard = '';
    if ($workFilters['search'] === '' && $workFilters['type'] === '' && $workFilters['age'] === '' && $workFilters['area'] === '' && $workFilters['status'] === '' && $workFilters['due_date'] === '') {
                if ($workFilters['priority'] !== '') $selectedWorkCard = match ($workFilters['priority']) {
                'urgent', 'overdue' => $workFilters['priority'],
                    default => '',
                };
                elseif ($workFilters['priority'] === '') $selectedWorkCard = 'total';
            }
            ?>

        <?php if ($emailView === 'work'): ?>
        <section class="border-t border-slate-100" id="needs-attention" data-filter-region="email-clinic-work" aria-live="polite">
                <div class="p-6 border-b border-slate-100 flex flex-col gap-4">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-3"><div><div class="flex items-center gap-2"><span class="w-8 h-8 rounded-lg bg-red-50 text-red-600 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">priority_high</span></span><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-0">What needs attention now?</h2></div><p class="text-sm text-slate-500 mt-2 mb-0">Patient-contact work appears here: send reminders, review nonresponses, and resolve delivery issues. Routine clinic work stays in notifications.</p></div><a class="btn btn-sm btn-outline text-decoration-none" href="<?= e('index.php?' . http_build_query(array_merge($_GET, ['tab' => 'email', 'export' => 'work_csv']))) ?>">Export email work</a></div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" aria-label="Clinic work summary">
                    <?php foreach ($workCards as [$key, $label, $icon, $changes]): $cardUrl = $workCardUrl($changes); $isCardActive = $selectedWorkCard === $key; ?>
                        <a class="clinic-card min-w-0 p-4 text-decoration-none transition-all <?= $isCardActive ? 'ring-2 ring-primary border-primary bg-[#f4fbf5]' : 'hover:shadow-md hover:-translate-y-0.5' ?>" href="<?= e($cardUrl) ?>" data-table-filter-link aria-label="Show <?= e(strtolower($label)) ?> work" <?= $isCardActive ? 'aria-current="page"' : '' ?>><span class="material-symbols-outlined text-primary text-[20px]"><?= e($icon) ?></span><div class="flex items-end justify-between gap-2"><p class="font-headline text-2xl font-extrabold text-[#17261d] mb-0 mt-2"><?= number_format((int) $workSummary[$key]) ?></p><?php if ($isCardActive): ?><span class="text-[10px] font-black uppercase tracking-widest text-primary">Selected</span><?php endif; ?></div><p class="text-[10px] font-black uppercase tracking-widest leading-tight text-slate-400 mb-0 mt-1 break-words"><?= e($label) ?></p></a>
                    <?php endforeach; ?>
                </div>
                <?php
                $collapseWorkItems = static function (array $items): array {
                    $collapsed = [];
                    foreach ($items as $item) {
                        $patientId = (int) ($item['patient_person_id'] ?? 0);
                        $eventType = (string) ($item['email_event_type'] ?? '');
                        $sourceType = (string) ($item['email_source_type'] ?? $item['source_type'] ?? 'patient');
                        $sourceId = (int) ($item['email_source_id'] ?? $item['source_id'] ?? 0);
                        $key = implode(':', [$patientId, $eventType, $sourceType, $sourceId]);
                        if (!isset($collapsed[$key])) {
                            $item['collapsed_count'] = 1;
                            $item['collapsed_titles'] = [(string) ($item['title'] ?? '')];
                            $collapsed[$key] = $item;
                            continue;
                        }
                        $collapsed[$key]['collapsed_count']++;
                        $collapsed[$key]['collapsed_titles'][] = (string) ($item['title'] ?? '');
                    }
                    foreach ($collapsed as &$item) {
                        $count = (int) ($item['collapsed_count'] ?? 1);
                        if ($count < 2) continue;
                        $item['title'] = match ((string) ($item['email_event_type'] ?? '')) {
                            'ape_document_correction_required' => 'Correct online documents',
                            'ape_hard_copy_correction_required' => 'Review hard-copy corrections',
                            default => (string) ($item['title'] ?? 'Clinic action required'),
                        } . " ({$count})";
                        $item['explanation'] = $count . ' related items need attention. Open the source record to review the details.';
                    }
                    unset($item);
                    return array_values($collapsed);
                };
                $displayWorkItems = $collapseWorkItems($workItems);
                $displayAllWorkItems = $collapseWorkItems($allWorkItems);
                $workSummary = clinic_work_center_summary($displayAllWorkItems);
                ?>
                <p class="text-xs font-bold text-slate-400 mb-0">Showing <?= number_format(count($displayWorkItems)) ?> of <?= number_format(count($displayAllWorkItems)) ?> email-related clinic warnings</p>
                 <form method="get" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end" data-table-filter="email-clinic-work" data-filter-region="email-clinic-work"><input type="hidden" name="tab" value="email"><?php if ($workFilters['priority'] !== ''): ?><input type="hidden" name="work_priority" value="<?= e($workFilters['priority']) ?>"><?php endif; ?><div><label class="clinic-label">Search clinic work</label><input class="clinic-input" name="work_search" value="<?= e($workFilters['search']) ?>" placeholder="Patient or task" aria-label="Search clinic work"></div><div><label class="clinic-label">Age / due status</label><select class="clinic-select" name="work_age" onchange="this.form.submit()"><option value="">All work</option><?php foreach(['overdue', 'due_today', 'due_soon', 'older_than_7_days', 'no_due_date'] as $value): ?><option value="<?= e($value) ?>" <?= $workFilters['age'] === $value ? 'selected' : '' ?>><?= e($workLabel($value)) ?></option><?php endforeach; ?></select></div><div><label class="clinic-label">Area</label><select class="clinic-select" name="work_area" onchange="this.form.submit()"><option value="">All areas</option><?php foreach($workAreas as $value): ?><option value="<?= e($value) ?>" <?= $workFilters['area'] === $value ? 'selected' : '' ?>><?= e($value) ?></option><?php endforeach; ?></select></div><div class="md:col-span-3 flex flex-wrap items-center justify-between gap-3"><span class="text-xs text-slate-400">Priority is selected above. Age and due status update automatically.</span><div class="flex items-center gap-2"><a class="btn btn-sm btn-outline text-decoration-none" href="index.php?tab=email#needs-attention" data-table-filter-link>Clear</a><?php if ($canManageEmailOperations && $needsReminderItems): ?><button type="button" class="btn btn-primary px-4 py-2 text-sm shadow-md" onclick="showModal('clinicReminderReviewModal')"><span class="material-symbols-outlined text-[17px] align-middle">mail</span> Review APE follow-ups <span class="ml-1 rounded-full bg-white/20 px-2 py-0.5 text-xs font-black"><?= number_format(count($needsReminderItems)) ?> ready</span></button><?php endif; ?></div></div></form>
            </div>
            <?php if (!$workItems): ?>
                <div class="p-10 flex flex-col items-center text-center"><span class="w-14 h-14 rounded-2xl bg-[#e7f4e9] text-primary flex items-center justify-center mb-3"><span class="material-symbols-outlined text-3xl">task_alt</span></span><h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">No patient-contact work needs attention</h3><p class="text-sm text-slate-500 mb-0">There are no unresolved reminders, nonresponses, or email delivery problems. Routine clinic work remains in notifications, and completed email activity is in history below.</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" aria-label="Clinic work actions">
                        <thead class="bg-slate-50 text-left text-[10px] uppercase tracking-widest text-slate-500"><tr><th class="px-5 py-3 font-black">Priority</th><th class="px-5 py-3 font-black">Patient</th><th class="px-5 py-3 font-black">What needs attention</th><th class="px-5 py-3 font-black">Category / source</th><th class="px-5 py-3 font-black">When</th><th class="sticky right-0 z-10 bg-slate-50 px-5 py-3 font-black text-right min-w-[170px]">Action</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($displayWorkItems as $item): $isReadyApeFollowUp = $highlightReadyApeFollowUps && isset($readyReminderKeys[$workItemKey($item)]) && ($apeFollowUpApeId === null || (int) ($item['email_source_id'] ?? $item['source_id'] ?? 0) === $apeFollowUpApeId); ?>
                                <tr class="align-top hover:bg-slate-50/70"<?= $isReadyApeFollowUp ? ' data-ape-ready-follow-up' : '' ?>>
                                    <td class="px-5 py-4 whitespace-nowrap"><span class="badge <?= e($workBadge((string) $item['priority'])) ?>"><?= e($workLabel((string) $item['priority'])) ?></span></td>
                                    <td class="px-5 py-4 min-w-[180px]"><strong class="block text-[#17261d]"><?= e($item['patient_name']) ?></strong><?php if ((int) $item['patient_person_id'] > 0): ?><a class="text-xs text-primary font-bold text-decoration-none" href="../patients/view.php?id=<?= (int) $item['patient_person_id'] ?>">Open patient</a><?php endif; ?><?php if (($item['patient_email'] ?? '') !== ''): ?><span class="block text-xs text-slate-500 mt-1"><?= e($item['patient_email']) ?></span><?php endif; ?></td>
                                    <td class="px-5 py-4 min-w-[260px]"><strong class="block text-[#17261d]"><?= e($item['title']) ?></strong><span class="block text-xs text-slate-600 mt-1"><?= e($item['explanation']) ?></span><?php if ((int) ($item['collapsed_count'] ?? 1) > 1): ?><span class="block text-xs font-bold text-slate-500 mt-2">One warning for this issue type; details remain in the source record.</span><?php endif; ?></td>
                                    <td class="px-5 py-4 min-w-[150px]"><span class="badge badge-outline"><?= e($item['category']) ?></span><span class="block text-xs text-slate-500 mt-2"><?= e($item['label']) ?></span></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-xs font-bold text-slate-500"><?= e($item['due_at'] !== '' ? 'Due ' . $item['due_at'] : 'Created ' . $ageLabel((string) $item['created_at'])) ?></td>
                                    <td class="sticky right-0 z-[1] bg-white px-5 py-4 min-w-[170px]"><div class="flex flex-wrap justify-end gap-2"><?php if ($item['action_type'] === 'retry_email'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="retry"><input type="hidden" name="id" value="<?= (int) $item['email_id'] ?>"><button class="btn btn-sm btn-primary whitespace-nowrap" data-confirm-submit data-confirm-title="Retry email?" data-confirm-message="This will attempt delivery again to the patient.">Retry email</button></form><?php elseif ($item['action_type'] === 'cancel_email'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="cancel"><input type="hidden" name="id" value="<?= (int) $item['email_id'] ?>"><button class="btn btn-sm btn-primary whitespace-nowrap" data-confirm-submit data-confirm-title="Cancel email?" data-confirm-message="This queued email will not be delivered.">Cancel email</button></form><?php elseif ($item['action_type'] === 'view_email' && (int) ($item['email_id'] ?? 0) > 0): ?><a class="btn btn-sm btn-primary text-decoration-none whitespace-nowrap" href="index.php?tab=email&amp;view=history&amp;search=<?= (int) $item['email_id'] ?>#all-email">View details</a><?php elseif ($item['action_type'] === 'compose_email' && $canManageEmailOperations && isset($patientEmailIds[(int) $item['patient_person_id']])): ?><button type="button" class="btn btn-sm btn-primary whitespace-nowrap" data-open-patient-email data-patient-id="<?= (int) $item['patient_person_id'] ?>" data-subject="<?= e((string) ($item['email_subject'] ?? 'Patient follow-up')) ?>" data-message="<?= e((string) ($item['email_message'] ?? 'Please review the outstanding clinic action and contact the clinic if you need assistance.')) ?>">Send email</button><?php if ($item['source_url'] !== ''): ?><a class="btn btn-sm btn-outline text-decoration-none whitespace-nowrap" href="<?= e($item['source_url']) ?>">Open source</a><?php endif; ?><?php elseif ($item['source_url'] !== ''): ?><a class="btn btn-sm btn-primary text-decoration-none whitespace-nowrap" href="<?= e($item['source_url']) ?>"><?= e($item['action_label']) ?></a><?php endif; ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($emailView === 'work'): ?>
        <?php $trackedFollowUpCount = count($cooldownReminderItems) + count($deliveryReminderItems) + count($limitedReminderItems); ?>
        <?php if ($trackedFollowUpCount > 0): ?><details class="clinic-card overflow-hidden mb-6" id="recently-reminded" aria-label="APE email follow-up status"><summary class="cursor-pointer list-none p-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">APE follow-up status</h2><p class="text-sm text-slate-500 mb-0">Waiting, delivery, and follow-up-limit details.</p></div><span class="badge badge-pending"><?= number_format($trackedFollowUpCount) ?> tracked</span></summary><div class="border-t border-slate-100">
                <div class="divide-y divide-slate-100">
                    <?php foreach (array_merge($cooldownReminderItems, $deliveryReminderItems, $limitedReminderItems) as $item): ?>
                        <article class="p-5 flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                            <div class="min-w-0"><p class="font-extrabold text-[#17261d] mb-1"><?= e($item['patient_name']) ?></p><p class="text-sm text-slate-700 mb-1"><?= e($item['title']) ?></p><p class="text-xs text-slate-500 mb-0"><?= (int) ($item['sent_count'] ?? 0) ?> email<?= ((int) ($item['sent_count'] ?? 0)) === 1 ? '' : 's' ?> sent to email service · <?= (int) ($item['automatic_sent_count'] ?? 0) ?> automatic · <?= (int) ($item['manual_follow_up_count'] ?? 0) ?> of 2 staff follow-ups · <?= $item['last_reminded_at'] ? e(date('M j, Y g:i A', strtotime((string) $item['last_reminded_at']))) : 'No accepted email' ?></p></div>
                            <div class="flex flex-wrap items-center gap-2 shrink-0"><span class="text-xs font-bold text-slate-500"><?= e((string) ($item['reminder_reason'] ?? '')) ?><?= !empty($item['next_eligible_at']) && ($item['reminder_state'] ?? '') === 'cooldown' ? ' Available ' . e(date('M j, Y g:i A', strtotime((string) $item['next_eligible_at']))) . '.' : '' ?></span><?php if (!empty($item['source_url'])): ?><a class="btn btn-sm btn-outline text-decoration-none" href="<?= e($item['source_url']) ?>">Open APE record</a><?php endif; ?><a class="btn btn-sm btn-outline text-decoration-none" href="index.php?tab=email&amp;patient_person_id=<?= (int) ($item['patient_person_id'] ?? 0) ?>#all-email">View email history</a></div>
                        </article>
                    <?php endforeach; ?>
                </div>
                </div>
        </details><?php endif; ?>
        <?php endif; ?>
        <?php if ($upcomingItems): ?><details class="clinic-card overflow-hidden mb-6"><summary class="cursor-pointer list-none p-5 flex items-center justify-between gap-3"><div><h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Upcoming automatic emails</h2><p class="text-xs text-slate-500 mb-0">Scheduled reminders waiting for their send time.</p></div><span class="badge badge-pending"><?= number_format(count($upcomingItems)) ?> queued</span></summary><div class="divide-y divide-slate-100 border-t border-slate-100"><?php foreach ($upcomingItems as $row): ?><div class="p-4 flex items-center justify-between gap-3"><div class="min-w-0"><p class="font-bold text-[#17261d] truncate mb-1"><?= e($row['patient_name'] ?: $row['recipient_name']) ?></p><p class="text-xs text-slate-500 mb-0"><?= e(ucwords(str_replace('_', ' ', (string) ($row['event_type'] ?: 'automatic email')))) ?> · Sends <?= e(date('M j, g:i A', strtotime($row['available_at']))) ?></p></div><form method="post" class="shrink-0"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="cancel"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="Cancel scheduled email?" data-confirm-message="This message will stay in history but will not be delivered.">Cancel</button></form></div><?php endforeach; ?></div></details><?php endif; ?>

        <details class="clinic-card overflow-hidden mb-6" id="all-email" <?= $allEmailOpen ? 'open' : '' ?>><summary class="p-6 cursor-pointer list-none flex flex-col md:flex-row md:items-center justify-between gap-3"><div><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">All email</h2><p class="text-sm text-slate-500 mb-0"><?= number_format($history['total']) ?> matching records · Search, filter, export, and open delivery details.</p></div><div class="flex gap-2"><a class="btn btn-sm btn-outline" href="<?= e('index.php?' . http_build_query(array_merge($_GET, ['tab' => 'email', 'export' => 'csv']))) ?>">Export history CSV</a></div></summary><div class="border-t border-slate-100"><form method="get" class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4"><input type="hidden" name="tab" value="email"><div class="lg:col-span-5 flex flex-wrap items-center gap-2 text-xs"><span class="font-bold text-slate-600">Quick filters:</span><a class="text-primary font-bold" href="index.php?tab=email&amp;delivery_state=smtp_accepted#all-email">SMTP accepted</a><a class="text-primary font-bold" href="index.php?tab=email&amp;delivery_state=delivered#all-email">Delivered</a><a class="text-primary font-bold" href="index.php?tab=email&amp;delivery_state=deferred#all-email">Deferred</a><a class="text-primary font-bold" href="index.php?tab=email&amp;delivery_state=bounced#all-email">Bounced</a><a class="text-primary font-bold" href="index.php?tab=email&amp;delivery_state=complained#all-email">Complained</a><a class="text-primary font-bold" href="index.php?tab=email&amp;delivery_state=rejected#all-email">Rejected</a><a class="text-primary font-bold" href="index.php?tab=email&amp;follow_up=open#all-email">Open follow-up</a></div><div class="lg:col-span-2"><label class="clinic-label">Search</label><input class="clinic-input" name="search" value="<?= e($filters['search']) ?>" placeholder="Patient, email, subject, or ID"></div><div><label class="clinic-label">Status</label><select class="clinic-select" name="status"><option value="">All statuses</option><?php foreach(['pending','processing','sent','failed','blocked','cancelled'] as $value): ?><option value="<?= $value ?>" <?= $filters['status']===$value?'selected':'' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div><div><label class="clinic-label">Event</label><select class="clinic-select" name="event_type"><option value="">All event types</option><?php foreach($eventTypes as $value): ?><option value="<?= e($value) ?>" <?= $filters['event_type']===$value?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$value))) ?></option><?php endforeach; ?></select></div><div class="flex items-end"><button class="btn btn-outline w-full justify-center">Filter history</button></div><div><label class="clinic-label">Source</label><input class="clinic-input" name="source_type" value="<?= e($filters['source_type']) ?>" placeholder="appointment, ape"></div><div><label class="clinic-label">Origin</label><select class="clinic-select" name="origin"><option value="">All origins</option><?php foreach(['automatic','manual','system'] as $value): ?><option value="<?= $value ?>" <?= $filters['origin']===$value?'selected':'' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div><div><label class="clinic-label">Sender</label><input class="clinic-input" name="sender" value="<?= e($filters['sender']) ?>" placeholder="Name or email"></div><div><label class="clinic-label">From</label><input class="clinic-input" type="date" name="date_from" value="<?= e($filters['date_from']) ?>"></div><div><label class="clinic-label">To</label><input class="clinic-input" type="date" name="date_to" value="<?= e($filters['date_to']) ?>"></div></form><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-[10px] uppercase tracking-widest text-slate-400"><th class="p-4">Created / sent</th><th class="p-4">Patient / recipient</th><th class="p-4">Event / source</th><th class="p-4">Status / delivery</th><th class="p-4">Actions</th></tr></thead><tbody><?php foreach($history['rows'] as $row): ?><tr class="border-t border-slate-100 align-top"><td class="p-4 whitespace-nowrap"><?= e(date('M j, Y g:i A', strtotime($row['created_at']))) ?><br><span class="text-xs text-slate-500"><?= $row['sent_at'] ? 'Sent ' . e(date('M j, g:i A', strtotime($row['sent_at']))) : 'Not sent' ?></span></td><td class="p-4"><?php if((int)$row['patient_person_id']>0): ?><a class="font-bold text-primary" href="../patients/view.php?id=<?= (int)$row['patient_person_id'] ?>"><?= e($row['patient_name'] ?: $row['recipient_name']) ?></a><?php else: ?><strong><?= e($row['patient_name'] ?: $row['recipient_name']) ?></strong><?php endif; ?><br><span class="text-xs text-slate-500"><?= e($row['recipient_email'] ?: 'No recipient address') ?></span><br><span class="text-xs text-slate-400">Sender: <?= e($row['sender_email'] ?: ($row['sender_name'] ?: 'Unknown')) ?></span></td><td class="p-4"><strong><?= e($row['subject']) ?></strong><br><span class="text-xs text-slate-500"><?= e(ucwords(str_replace('_',' ',(string)($row['event_type'] ?: 'email')))) ?> · <?= e((string)($row['source_type'] ?: '—')) ?> <?= (int)$row['source_id'] > 0 ? '#' . (int)$row['source_id'] : '' ?> · <?= e((string)($row['origin'] ?: '—')) ?></span><?php if($row['source_type']==='ape' && (int)$row['source_id']>0): ?><br><a class="text-xs text-primary font-bold" href="../ape/view.php?id=<?= (int)$row['source_id'] ?>">Open APE record</a><?php endif; ?><details class="mt-2 text-xs"><summary class="cursor-pointer font-bold text-slate-500">View delivery details</summary><p class="mt-2 text-slate-600">Scheduled: <?= e((string)($row['available_at'] ?: 'Immediate')) ?><br>Last attempt: <?= e((string)($row['last_attempt_at'] ?: '—')) ?><br>Provider ID: <?= e((string)($row['provider_message_id'] ?: '—')) ?></p><pre class="mt-2 max-w-xl whitespace-pre-wrap text-[11px] text-slate-600"><?= e(strip_tags((string)$row['html_body'])) ?></pre></details></td><td class="p-4"><span class="badge <?= $row['status']==='sent'?'badge-completed':(in_array($row['status'],['failed','blocked'],true)?'badge-high':($row['status']==='cancelled'?'badge-cancelled':'badge-pending')) ?>"><?= e(ucfirst($row['status'])) ?></span><?php if($row['delivery_state']): ?><p class="text-xs text-slate-500 mt-2 mb-0">Delivery: <?= e(ucwords(str_replace('_', ' ', (string) $row['delivery_state']))) ?></p><?php endif; ?><p class="text-xs text-slate-500 mt-2 mb-0"><?= (int)$row['attempts'] ?> attempt(s)</p><?php if($row['last_error'] || $row['blocked_reason']): ?><p class="text-xs text-red-600 mt-2"><?= e($row['last_error'] ?: $row['blocked_reason']) ?></p><?php endif; ?></td><td class="p-4"><div class="flex flex-wrap gap-2"><?php if($row['status']==='failed'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="retry"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="Retry email?" data-confirm-message="This will attempt delivery again to the patient.">Retry</button></form><?php endif; ?><?php if(in_array($row['status'],['pending','failed'],true)): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="cancel"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="Cancel email?" data-confirm-message="This queued email will not be delivered.">Cancel</button></form><?php endif; ?><?php if($canManageEmailOperations && $row['status']==='sent' && !str_starts_with((string) $row['event_type'], 'ape_') && !str_starts_with((string) $row['source_type'], 'ape')): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="resend"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="Resend email?" data-confirm-message="This will create and deliver a duplicate email.">Resend</button></form><?php endif; ?><?php if(in_array($row['status'],['failed','blocked'],true)): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="follow_up"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline">Mark follow-up</button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php if(!$history['rows']): ?><div class="p-8 text-center text-slate-500">No emails match these filters. Try clearing the filters to see all activity.</div><?php endif; ?><?php if($history['pages']>1): ?><div class="p-4 flex justify-between border-t border-slate-100"><span class="text-xs font-bold text-slate-500">Page <?= $history['page'] ?> of <?= $history['pages'] ?></span><div class="flex gap-2"><?php if($history['page']>1): ?><a class="btn btn-sm btn-outline" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$history['page']-1]))) ?>">Previous</a><?php endif; ?><?php if($history['page']<$history['pages']): ?><a class="btn btn-sm btn-outline" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$history['page']+1]))) ?>">Next</a><?php endif; ?></div></div><?php endif; ?></div></details>

        <details class="clinic-card overflow-hidden mb-6" id="automation"><summary class="p-6 cursor-pointer list-none flex flex-col md:flex-row md:items-center justify-between gap-3"><div><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Automation</h2><p class="text-sm text-slate-500 mb-0"><?= $automationEnabled === count($automation) ? 'All urgent automation categories are enabled.' : 'Some urgent automation categories are paused.' ?> Changes affect future emails only; existing queue records are preserved.</p></div><span class="text-sm font-bold text-primary">Manage automation</span></summary><?php if (!$canManageEmailSettings): ?><p class="px-6 pb-4 text-sm text-slate-500">Only an administrator can change automation settings.</p><?php endif; ?><form method="post" class="p-6 border-t border-slate-100 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 <?= $canManageEmailSettings ? '' : 'pointer-events-none opacity-60' ?>"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="automation"><?php $labels=['appointment_reminders'=>'Appointment confirmations and reminders','appointment_changes'=>'Appointment cancellations and changes','ape_corrections'=>'APE document and clearance corrections','ape_follow_up_reminders'=>'APE follow-up reminders','ape_overdue'=>'APE overdue escalations','ape_schedule_updates'=>'APE schedule updates','access_restrictions'=>'Patient access restrictions','school_year_enrollment'=>'School-year enrollment']; foreach($labels as $key=>$label): ?><label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm font-bold"><input class="mt-1" type="checkbox" name="automation[<?= e($key) ?>]" value="1" <?= !empty($automation[$key]) ? 'checked' : '' ?>><span><span class="block text-[#17261d]"><?= e($label) ?></span><span class="block text-xs font-normal text-slate-500 mt-1">Send only for urgent or time-sensitive patient events.</span></span></label><?php endforeach; ?><div class="md:col-span-2 lg:col-span-3 flex items-center justify-between gap-3"><p class="text-xs text-slate-500 mb-0">Who changed these settings and when is recorded in Audit Log.</p><button class="btn btn-primary" type="submit" <?= $canManageEmailSettings ? '' : 'disabled' ?>>Save automation settings</button></div></form></details>

        <?php if ($canManageEmailSettings): ?><details class="clinic-card overflow-hidden mb-6" id="queue-controls"><summary class="cursor-pointer list-none p-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Delivery controls and diagnostics</h2><p class="text-sm text-slate-500 mb-0">Queue-wide controls for administrators only.</p></div><span class="text-sm font-bold text-primary">Open controls</span></summary><div class="border-t border-slate-100 p-5"><div class="grid grid-cols-2 lg:grid-cols-6 gap-3 text-sm" aria-label="Email queue health"><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Capacity</span><strong><?= number_format((int) ($capacity['accepted_count'] ?? 0)) ?></strong> SMTP accepted<br><strong><?= number_format((int) ($capacity['remaining_capacity'] ?? 0)) ?></strong> remaining</div><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Queue</span><strong><?= !empty($summary['queue_paused']) ? 'Paused' : 'Running' ?></strong><br><span class="text-xs text-slate-500"><?= number_format($summary['pending']) ?> pending</span></div><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Processing</span><strong><?= number_format($summary['processing']) ?></strong></div><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Retryable</span><strong><?= number_format($summary['retryable']) ?></strong></div><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Max attempts</span><strong><?= number_format($summary['max_attempts']) ?></strong></div><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Worker</span><strong><?= !empty($summary['worker_heartbeat']['at']) ? e(date('M j, g:i A', strtotime((string) $summary['worker_heartbeat']['at'])) ) : 'No heartbeat' ?></strong></div></div><div class="mt-5 flex flex-wrap gap-3"><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="<?= !empty($summary['queue_paused']) ? 'resume_queue' : 'pause_queue' ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="<?= !empty($summary['queue_paused']) ? 'Resume queue?' : 'Pause queue?' ?>" data-confirm-message="<?= !empty($summary['queue_paused']) ? 'New automatic emails will be processed again.' : 'Automatic emails will remain queued but will not be delivered until resumed.' ?>"><?= !empty($summary['queue_paused']) ? 'Resume queue' : 'Pause queue' ?></button></form><?php if (!$workerHealthy): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="process"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="Run queue recovery?" data-confirm-message="This will process up to 50 due messages while the worker is unavailable.">Run queue recovery</button></form><?php endif; ?></div></div></details><?php endif; ?>

        <?php if ($canManageEmailOperations): ?>
        <div id="clinicReminderReviewModal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="clinicReminderReviewTitle">
            <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-4xl shadow-2xl max-h-[90vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-4 mb-6"><div><p class="text-[11px] font-black uppercase tracking-widest text-primary mb-2">Clinic work<?= $apeReminderBatchId ? ' · Selected APE batch' : '' ?></p><h2 id="clinicReminderReviewTitle" class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">Review APE follow-ups</h2><p class="text-sm text-slate-500 mb-0">Select each student you want to contact, then review and edit their message before sending.</p></div><button type="button" class="btn btn-sm btn-ghost" onclick="closeModal('clinicReminderReviewModal')">Close</button></div>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5"><div class="rounded-xl bg-[#edf7ef] p-3"><strong class="block text-2xl text-[#17261d]"><?= number_format(count($needsReminderItems)) ?></strong><span class="text-xs text-slate-600">Follow-ups ready</span></div><div class="rounded-xl bg-slate-50 p-3"><strong class="block text-2xl text-[#17261d]"><?= number_format(count($cooldownReminderItems)) ?></strong><span class="text-xs text-slate-600">Waiting 72 hours</span></div><div class="rounded-xl bg-amber-50 p-3"><strong class="block text-2xl text-[#17261d]"><?= number_format(count($deliveryReminderItems)) ?></strong><span class="text-xs text-slate-600">Delivery attention</span></div><div class="rounded-xl bg-slate-50 p-3"><strong class="block text-2xl text-[#17261d]"><?= number_format(count($limitedReminderItems)) ?></strong><span class="text-xs text-slate-600">Follow-up limit reached</span></div></div>
                <?php if ($needsReminderItems): ?><label class="mb-5 flex items-center gap-2 text-sm font-extrabold text-[#17261d]"><input type="checkbox" data-reminder-select-all> Select all eligible students</label><?php endif; ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 mb-5"><strong class="block mb-1">Before sending</strong>These messages are personalized from each student’s APE issue. Your edited subject and message will be sent exactly as reviewed. Sending a reminder does not change any APE deadline.</div>
                <?php if (!$needsReminderItems): ?><div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-600 mb-6">No students are currently eligible for a new reminder.</div><?php endif; ?>
                <form method="post" id="clinicReminderReviewForm"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="send_clinic_reminders"><?php if ($apeReminderBatchId): ?><input type="hidden" name="ape_batch" value="<?= (int) $apeReminderBatchId ?>"><?php endif; ?>
                    <div class="space-y-3 mb-6">
                    <?php foreach ($needsReminderItems as $index => $item): $eventType = (string) ($item['email_event_type'] ?? ''); $followUpNumber = (int) ($item['follow_up_number'] ?? 1); $subject = (string) ($item['email_subject'] ?? 'APE reminder — action needed'); $message = (string) ($item['email_message'] ?? 'Please log in to your student portal and complete the outstanding APE action, or contact the clinic if you need assistance.'); if (!str_contains($message, 'https://plpuhs.dpdns.org')) $message .= ' Student portal: https://plpuhs.dpdns.org'; ?>
                        <details class="rounded-xl border border-slate-200 bg-white p-4"><summary class="cursor-pointer list-none flex flex-wrap items-center justify-between gap-3"><span><strong class="text-[#17261d]"><?= e($item['patient_name']) ?></strong><span class="block text-xs text-slate-500 mt-1"><?= e($item['title']) ?> · <?= (int) ($item['sent_count'] ?? 0) ?> email<?= ((int) ($item['sent_count'] ?? 0)) === 1 ? '' : 's' ?> sent to email service · <?= (int) ($item['automatic_sent_count'] ?? 0) ?> automatic · Follow-up <?= $followUpNumber ?> of 2</span></span><span class="badge badge-pending">Review follow-up</span></summary><div class="mt-4 grid gap-3"><label class="flex items-center gap-2 text-sm font-extrabold text-[#17261d]"><input type="checkbox" name="reminders[<?= $index ?>][selected]" value="1" data-reminder-select> Send follow-up <?= $followUpNumber ?> of 2</label><div class="text-xs text-slate-500">Recipient: <strong><?= e((string) ($item['patient_email'] ?? 'Email available after validation')) ?></strong> · Last accepted: <?= $item['last_reminded_at'] ? e(date('M j, Y g:i A', strtotime((string) $item['last_reminded_at']))) : '—' ?> · <a class="text-primary font-bold" href="<?= e((string) ($item['source_url'] ?? '../ape/view.php?id=' . (int) $item['source_id'])) ?>">Open APE record</a></div><input type="hidden" name="reminders[<?= $index ?>][patient_person_id]" value="<?= (int) $item['patient_person_id'] ?>"><input type="hidden" name="reminders[<?= $index ?>][source_type]" value="<?= e((string) ($item['source_type'] ?? 'ape')) ?>"><input type="hidden" name="reminders[<?= $index ?>][source_id]" value="<?= (int) ($item['source_id'] ?? 0) ?>"><input type="hidden" name="reminders[<?= $index ?>][event_type]" value="<?= e($eventType) ?>"><input type="hidden" name="reminders[<?= $index ?>][follow_up_number]" value="<?= $followUpNumber ?>"><label class="clinic-label">Subject<input class="clinic-input" name="reminders[<?= $index ?>][subject]" maxlength="180" required value="<?= e($subject) ?>"></label><label class="clinic-label">Message<textarea class="clinic-input min-h-32" name="reminders[<?= $index ?>][message]" maxlength="10000" required><?= e($message) ?></textarea></label></div></details>
                    <?php endforeach; ?>
                    </div>
                    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3"><button type="button" class="btn btn-outline" onclick="closeModal('clinicReminderReviewModal'); document.getElementById('needs-attention')?.scrollIntoView({behavior:'smooth', block:'start'});">Review first</button><button class="btn btn-primary" <?= !$needsReminderItems ? 'disabled' : '' ?> data-confirm-submit data-confirm-title="Send selected APE follow-ups?" data-confirm-message="Only selected students will receive another staff-reviewed follow-up. Each counts toward that APE action’s two-follow-up limit. APE deadlines will not change.">Send selected follow-ups</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div id="patientEmailComposeModal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="patientEmailComposeTitle"><div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-6xl shadow-2xl max-h-[92vh] overflow-y-auto"><div class="flex items-start justify-between gap-4 mb-6"><div><p class="text-[11px] font-black uppercase tracking-widest text-primary mb-2">Manual delivery</p><h2 id="patientEmailComposeTitle" class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">Send a patient email</h2><p class="text-sm text-slate-500 mb-0">The message will be recorded in Email Center and delivered through the same queue.</p></div><button type="button" class="btn btn-sm btn-ghost" onclick="closeModal('patientEmailComposeModal')" aria-label="Close">Close</button></div><form method="post" id="patientEmailComposeForm"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="custom_send"><span class="sr-only" data-loading-message="Sending patient email…">Sending patient email…</span><div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:items-start"><div class="grid gap-4"><div class="clinic-label"><span>Patient</span><div id="manualPatientPicker"></div><span class="block text-xs font-normal text-slate-500 mt-1">Search and select one or more active patients. Only valid patient email addresses can be used.</span></div><label class="clinic-label">Template<select id="manualEmailTemplate" class="clinic-select"><option value="">Choose a message template…</option><?php foreach ($manualMailTemplates as $templateKey => $template): ?><option value="<?= e((string) $templateKey) ?>"><?= e((string) $template['label']) ?></option><?php endforeach; ?></select></label><label class="clinic-label">Subject<input id="manualEmailSubject" class="clinic-input" name="subject" maxlength="180" required></label><label class="clinic-label">Message<textarea id="manualEmailMessage" class="clinic-input min-h-40" name="message" maxlength="10000" required></textarea></label><div class="flex justify-end gap-3 pt-2"><button type="button" class="btn btn-ghost" onclick="closeModal('patientEmailComposeModal')">Cancel</button><button class="btn btn-primary" data-confirm-submit data-loading-label="Sending…" data-confirm-title="Send patient email?" data-confirm-message="This will deliver the message to the selected patients and record it in Email Center."><span class="material-symbols-outlined text-[18px]" aria-hidden="true">send</span><span data-action-label>Review and send</span></button></div></div><aside class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:sticky lg:top-2" aria-label="Live email preview"><div class="bg-[#3f8256] p-5 text-white"><div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-[#3f8256] font-black">C</span><strong class="text-lg">CLINiQ</strong></div></div><div class="p-6"><p class="mb-2 text-[10px] font-black uppercase tracking-widest text-slate-400">Live preview</p><p id="manualEmailPreviewSubject" class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Subject preview</p><h3 id="manualEmailPreviewHeading" class="mb-4 text-xl font-extrabold text-[#17261d]">Your email subject</h3><p id="manualEmailPreviewMessage" class="whitespace-pre-wrap text-sm leading-6 text-slate-600">Your message preview will appear here.</p></div></aside></div></form></div></div>
        <?php if ($canManageEmailOperations): ?>
        <div id="patientEmailContextModal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="patientEmailContextTitle"><div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-2xl shadow-2xl"><div class="flex items-start justify-between gap-4 mb-6"><div><p class="text-[11px] font-black uppercase tracking-widest text-primary mb-2">Patient follow-up</p><h2 id="patientEmailContextTitle" class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">Review and send email</h2><p class="text-sm text-slate-500 mb-0">This message is linked to the clinic task you selected.</p></div><button type="button" class="btn btn-sm btn-ghost" onclick="closeModal('patientEmailContextModal')" aria-label="Close">Close</button></div><form id="patientEmailContextForm" method="post" class="grid grid-cols-1 gap-4"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="custom_send"><input type="hidden" id="contextPatientId" name="patient_ids[]" value=""><div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Recipient</p><p id="contextPatientName" class="font-extrabold text-[#17261d] mb-1">—</p><p id="contextPatientEmail" class="text-sm text-slate-600 mb-0">—</p></div><div id="contextLastEmail" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" hidden></div><label class="clinic-label">Subject<input id="contextSubject" class="clinic-input" name="subject" maxlength="180" required></label><label class="clinic-label">Message<textarea id="contextMessage" class="clinic-input min-h-32" name="message" maxlength="10000" required></textarea></label><div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600"><strong class="block text-slate-800 mb-1">Preview</strong><span id="contextPreview"></span></div><p class="text-xs text-slate-500 mb-0">Review the recipient and previous email warning before sending. This will be recorded in Email Center.</p><div class="flex justify-end gap-3"><button type="button" class="btn btn-ghost" onclick="closeModal('patientEmailContextModal')">Cancel</button><button class="btn btn-primary" data-confirm-submit data-confirm-title="Send this patient email?" data-confirm-message="This will deliver the reviewed message to the selected patient and record it in Email Center.">Confirm and send</button></div></form></div></div>
        <?php endif; ?>
    </div>
    <?php $contextPatients = []; foreach ($allWorkItems as $workItem) { $patientId = (int) ($workItem['patient_person_id'] ?? 0); if ($patientId > 0 && !isset($contextPatients[$patientId])) $contextPatients[$patientId] = ['id' => $patientId, 'name' => (string) ($workItem['patient_name'] ?? 'Patient'), 'email' => (string) ($workItem['patient_email'] ?? '')]; } ?>
    <script>
        const patientOptions = <?= json_encode(array_values($contextPatients), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        (() => {
            const allEmail = document.getElementById('all-email');
            document.querySelectorAll('a[href="#all-email"]').forEach((link) => {
                link.addEventListener('click', () => {
                    const history = document.getElementById('all-email');
                    if (history) history.open = true;
                });
            });
            const composeForm = document.querySelector('#patientEmailComposeForm');
            const message = document.getElementById('manualEmailMessage');
            document.querySelectorAll('[data-open-patient-email]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (button.hasAttribute('data-open-context-email') || button.closest('#needs-attention')) return;
                    const patientId = button.getAttribute('data-patient-id');
                const patientSelect = composeForm?.querySelector('select[name="patient_ids[]"]');
                const patientLabel = patientSelect?.closest('label');
                    if (!patientSelect || !patientId) return;
                    Array.from(patientSelect.options).forEach((option) => { option.selected = option.value === patientId; });
                    patientSelect.dispatchEvent(new Event('change'));
                    const subject = composeForm.querySelector('input[name="subject"]');
                    if (subject) subject.value = button.getAttribute('data-subject') || 'Patient follow-up';
                    if (message) { message.value = button.getAttribute('data-message') || ''; message.dispatchEvent(new Event('input')); }
                    showModal('patientEmailComposeModal');
                });
            });
            const contextForm = document.querySelector('#patientEmailContextForm');
            document.querySelector('[data-reminder-select-all]')?.addEventListener('change', (event) => {
                document.querySelectorAll('[data-reminder-select]').forEach((checkbox) => { checkbox.checked = event.target.checked; });
            });
            const contextLastEmails = <?= json_encode($contextEmailLast, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            document.querySelectorAll('[data-open-context-email], #needs-attention [data-open-patient-email]').forEach((button) => {
                button.addEventListener('click', () => {
                    const patientId = button.getAttribute('data-patient-id');
                    if (!contextForm || !patientId) return;
                    const option = patientOptions.find((candidate) => String(candidate.id) === patientId);
                    const patientLabel = option?.name || 'Patient';
                    const patientEmail = option?.email || 'Valid patient email address';
                    contextForm.querySelector('#contextPatientId').value = patientId;
                    contextForm.querySelector('#contextPatientName').textContent = patientLabel;
                    contextForm.querySelector('#contextPatientEmail').textContent = patientEmail;
                    contextForm.querySelector('#contextSubject').value = button.getAttribute('data-subject') || 'Patient follow-up';
                    contextForm.querySelector('#contextMessage').value = button.getAttribute('data-message') || '';
                    contextForm.querySelector('#contextPreview').textContent = contextForm.querySelector('#contextMessage').value;
                    const last = contextLastEmails[patientId];
                    const lastBox = contextForm.querySelector('#contextLastEmail');
                    if (last && lastBox) {
                        lastBox.hidden = false;
                        lastBox.textContent = 'Last email: ' + (last.subject || 'Patient email') + ' · ' + (last.sent_at || last.created_at || 'date unavailable') + '. Confirm that another message is appropriate.';
                    } else if (lastBox) {
                        lastBox.hidden = true;
                        lastBox.textContent = '';
                    }
                    showModal('patientEmailContextModal');
                });
            });
        })();
        const manualEmailModal = document.getElementById('patientEmailComposeModal');
        if (manualEmailModal) {
            const composeForm = document.getElementById('patientEmailComposeForm');
            const template = document.getElementById('manualEmailTemplate');
            const subjectInput = document.getElementById('manualEmailSubject');
            const messageInput = document.getElementById('manualEmailMessage');
            const previewSubject = document.getElementById('manualEmailPreviewSubject');
            const previewHeading = document.getElementById('manualEmailPreviewHeading');
            const previewMessage = document.getElementById('manualEmailPreviewMessage');
            const picker = document.getElementById('manualPatientPicker');
            const configuredTemplates = <?= json_encode($manualMailTemplates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

            if (composeForm && template && subjectInput && messageInput && picker) {
                picker.innerHTML = '<div class="relative"><input type="search" class="clinic-input" placeholder="Search active patients by name or email…" aria-label="Search patients"><div class="absolute z-20 left-0 right-0 top-full mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl" data-patient-results hidden></div></div><div class="mt-2 flex flex-wrap gap-2 min-h-8" data-patient-chips></div><div data-patient-inputs></div><p class="mt-2 text-xs text-slate-500 mb-0" data-patient-count>No patients selected</p>';
                const search = picker.querySelector('input[type="search"]');
                const results = picker.querySelector('[data-patient-results]');
                const chips = picker.querySelector('[data-patient-chips]');
                const inputs = picker.querySelector('[data-patient-inputs]');
                const count = picker.querySelector('[data-patient-count]');
                const selectedIds = new Set();
                const selectedOptions = new Map(patientOptions.map((option) => [String(option.id), option]));
                const updatePreview = () => {
                    const subject = subjectInput.value.trim();
                    previewSubject.textContent = subject || 'Subject will appear here';
                    previewHeading.textContent = subject || 'Start writing to preview';
                    previewMessage.textContent = messageInput.value.trim() || 'Your message will appear here as you write.';
                };
                const renderSelectedPatients = () => {
                    chips.innerHTML = '';
                    inputs.innerHTML = '';
                    const selected = Array.from(selectedOptions.values()).filter((option) => selectedIds.has(String(option.id)));
                    selected.forEach((option) => {
                        const input = document.createElement('input');
                        input.type = 'hidden'; input.name = 'patient_ids[]'; input.value = String(option.id); inputs.appendChild(input);
                        const chip = document.createElement('span');
                        chip.className = 'inline-flex items-center gap-1 rounded-full bg-[#e7f4e9] px-3 py-1 text-xs font-bold text-[#285c39]';
                        const label = document.createElement('span');
                        label.textContent = option.name + ' — ' + option.email;
                        const remove = document.createElement('button');
                        remove.type = 'button'; remove.className = 'font-black ml-1'; remove.setAttribute('aria-label', 'Remove ' + option.name); remove.textContent = '×';
                        remove.addEventListener('click', () => { selectedIds.delete(String(option.id)); selectedOptions.delete(String(option.id)); renderSelectedPatients(); showResults(); });
                        chip.append(label, remove); chips.appendChild(chip);
                    });
                    count.textContent = selected.length ? `${selected.length} patient${selected.length === 1 ? '' : 's'} selected` : 'No patients selected';
                };
                const showResults = () => {
                    const query = (search.value || '').toLowerCase().trim();
                    results.innerHTML = '';
                    patientOptions.filter((option) => !selectedIds.has(String(option.id)) && (!query || (option.name + ' ' + option.email).toLowerCase().includes(query))).slice(0, 30).forEach((option) => {
                        const button = document.createElement('button');
                        button.type = 'button'; button.className = 'block w-full text-left px-3 py-2 text-sm hover:bg-[#edf7ef]'; button.textContent = option.name + ' — ' + option.email;
                        button.addEventListener('click', () => { selectedIds.add(String(option.id)); selectedOptions.set(String(option.id), option); search.value = ''; results.hidden = true; renderSelectedPatients(); });
                        results.appendChild(button);
                    });
                    results.hidden = !results.children.length;
                };
                template.addEventListener('change', () => {
                    const value = configuredTemplates[template.value];
                    if (!value) return;
                    subjectInput.value = value.subject || '';
                    messageInput.value = value.message || '';
                    updatePreview();
                });
                subjectInput.addEventListener('input', updatePreview);
                messageInput.addEventListener('input', updatePreview);
                let searchTimer;
                search.addEventListener('input', () => {
                    window.clearTimeout(searchTimer);
                    const query = search.value.trim();
                    if (query.length < 2) { results.innerHTML = ''; results.hidden = true; return; }
                    searchTimer = window.setTimeout(async () => {
                        try {
                            const response = await fetch('../api/patient-search.php?q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json' } });
                            const data = await response.json();
                            patientOptions.splice(0, patientOptions.length, ...(Array.isArray(data.patients) ? data.patients : []));
                            showResults();
                        } catch (error) { results.innerHTML = '<p class="p-3 text-xs text-red-700">Patient search is unavailable.</p>'; results.hidden = false; }
                    }, 180);
                });
                search.addEventListener('focus', showResults);
                document.addEventListener('click', (event) => { if (!picker.contains(event.target)) results.hidden = true; });
                composeForm.addEventListener('reset', () => window.requestAnimationFrame(() => {
                    search.value = '';
                    results.hidden = true;
                    selectedIds.clear();
                    selectedOptions.clear();
                    renderSelectedPatients();
                    updatePreview();
                }));
                renderSelectedPatients();
                updatePreview();
            }
        }
        </script>
        <?php if ($emailView === 'send'): ?><script>window.addEventListener('DOMContentLoaded', () => showModal('patientEmailComposeModal'));</script><?php endif; ?>
    <?php render_footer(); exit;
}

$module = trim((string) ($_GET['module'] ?? ''));
$action = trim((string) ($_GET['action'] ?? ''));
$actor = trim((string) ($_GET['actor'] ?? ''));
$outcome = trim((string) ($_GET['outcome'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$hasPrintDateRange = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo);

$routineEmailActions = audit_log_governance_excluded_actions();
$where = ['1 = 1', 'a.action NOT IN (' . implode(',', array_fill(0, count($routineEmailActions), '?')) . ')'];
$params = $routineEmailActions;
foreach ([['a.module', $module], ['a.action', $action], ['a.actor_type', $actor]] as [$field, $value]) {
    if ($value !== '') {
        $where[] = "{$field} = ?";
        $params[] = $value;
    }
}
if ($outcome !== '') {
    if (in_array($outcome, ['unsuccessful', 'failure', 'failed'], true)) {
        $where[] = "a.outcome IN ('failure', 'failed')";
    } else {
        $where[] = 'a.outcome = ?';
        $params[] = $outcome;
    }
}
if ($search !== '') {
    $where[] = '(a.target_type LIKE ? OR CAST(a.target_id AS CHAR) LIKE ? OR a.metadata LIKE ? OR CONCAT_WS(" ", p.first_name, p.last_name) LIKE ? OR p.id_number LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%");
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'DATE(a.created_at) >= ?';
    $params[] = $dateFrom;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'DATE(a.created_at) <= ?';
    $params[] = $dateTo;
}
$whereSql = implode(' AND ', $where);

$count = auth_db()->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN people p ON p.id = a.actor_person_id WHERE {$whereSql}");
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = auth_db()->prepare("SELECT a.*,
        TRIM(CONCAT_WS(' ', NULLIF(actor_staff.honorific, ''), p.first_name, p.middle_name, p.last_name)) AS actor_name,
        p.id_number AS actor_id_number,
        COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', direct_target.first_name, direct_target.middle_name, direct_target.last_name)), ''),
            NULLIF(TRIM(CONCAT_WS(' ', account_target.first_name, account_target.middle_name, account_target.last_name)), ''),
            NULLIF(TRIM(CONCAT_WS(' ', ape_target.first_name, ape_target.middle_name, ape_target.last_name)), ''),
            NULLIF(TRIM(CONCAT_WS(' ', visit_target.first_name, visit_target.middle_name, visit_target.last_name)), ''),
            NULLIF(TRIM(CONCAT_WS(' ', alert_target.first_name, alert_target.middle_name, alert_target.last_name)), '')
        ) AS target_name,
        COALESCE(direct_target.id_number, account_target.id_number, ape_target.id_number, visit_target.id_number, alert_target.id_number) AS target_id_number,
        inventory_item.item_name AS target_item_name
    FROM audit_logs a
    LEFT JOIN people p ON p.id = a.actor_person_id
    LEFT JOIN clinic_staff actor_staff ON actor_staff.person_id = p.id
    LEFT JOIN people direct_target ON direct_target.id = a.target_id AND a.target_type IN ('person', 'patient')
    LEFT JOIN accounts target_account ON target_account.id = a.target_id AND a.target_type = 'account'
    LEFT JOIN people account_target ON account_target.id = target_account.person_id
    LEFT JOIN ape_records target_ape ON target_ape.ape_id = a.target_id AND a.target_type = 'ape_record'
    LEFT JOIN people ape_target ON ape_target.id = target_ape.patient_id
    LEFT JOIN visits target_visit ON target_visit.visit_id = a.target_id AND a.target_type = 'visit'
    LEFT JOIN people visit_target ON visit_target.id = target_visit.patient_person_id
    LEFT JOIN nurse_alerts target_alert ON target_alert.id = a.target_id AND a.target_type = 'nurse_alert'
    LEFT JOIN people alert_target ON alert_target.id = target_alert.patient_id
    LEFT JOIN inventory_transactions target_inventory ON target_inventory.transaction_id = a.target_id AND a.target_type = 'inventory_transaction'
    LEFT JOIN inventory_items inventory_item ON inventory_item.item_id = target_inventory.item_id
    WHERE {$whereSql} ORDER BY a.created_at DESC, a.id DESC LIMIT ?, ?");
$parameterIndex = 1;
foreach ($params as $value) {
    $stmt->bindValue($parameterIndex++, $value);
}
$stmt->bindValue($parameterIndex++, $offset, PDO::PARAM_INT);
$stmt->bindValue($parameterIndex, $perPage, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
$auditRows = [];
$auditDetailModals = [];
foreach ($logs as $log) {
    $modalId = 'auditDetailModal' . (int) $log['id'];
    $details = audit_log_event_details((string) $log['action'], $log['metadata'] ?? null);
    $auditRows[] = [
        'created' => date('M d, Y g:i A', strtotime($log['created_at'])),
        'actor' => '<p class="audit-primary-text">' . e($log['actor_name'] ?: ucfirst((string) $log['actor_type'])) . '</p><p class="audit-secondary-text">' . e($log['actor_id_number'] ?: audit_log_module_label((string) $log['actor_type'])) . '</p>',
        'activity' => '<p class="audit-primary-text">' . e(audit_log_action_label((string) $log['action'])) . '</p><p class="audit-secondary-text">' . e(audit_log_module_label((string) $log['module'])) . '</p>',
        'target' => audit_log_target_label($log),
        'outcome' => '<span class="badge ' . e($log['outcome'] === 'success' ? 'badge-completed' : 'badge-high') . '">' . e($log['outcome'] === 'success' ? 'Successful' : 'Failed') . '</span>',
        'details' => '<span class="audit-detail-summary">' . e($details) . '</span>',
        'rowModalId' => $modalId,
    ];
    $auditDetailModals[] = [
        'id' => $modalId,
        'created' => date('M d, Y g:i A', strtotime($log['created_at'])),
        'actor' => $log['actor_name'] ?: ucfirst((string) $log['actor_type']),
        'actorId' => $log['actor_id_number'] ?: audit_log_module_label((string) $log['actor_type']),
        'activity' => audit_log_action_label((string) $log['action']),
        'module' => audit_log_module_label((string) $log['module']),
        'target' => audit_log_target_label($log),
        'outcome' => $log['outcome'] === 'success' ? 'Successful' : 'Failed',
        'details' => $details,
    ];
}
$auditColumns = [
    ['headerName' => 'Date and time', 'field' => 'created', 'sortField' => 'created', 'sortType' => 'date', 'minWidth' => 170],
    ['headerName' => 'Performed by', 'field' => 'actor', 'cellRenderer' => 'html', 'minWidth' => 190],
    ['headerName' => 'Activity', 'field' => 'activity', 'cellRenderer' => 'html', 'minWidth' => 180],
    ['headerName' => 'Affected record', 'field' => 'target', 'minWidth' => 180],
    ['headerName' => 'Result', 'field' => 'outcome', 'cellRenderer' => 'html', 'minWidth' => 125, 'maxWidth' => 150],
    ['headerName' => 'Additional information', 'field' => 'details', 'cellRenderer' => 'html', 'minWidth' => 220],
];
$modules = auth_db()->query('SELECT DISTINCT module FROM audit_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
$actionOptions = auth_db()->prepare('SELECT DISTINCT action FROM audit_logs WHERE action NOT IN (' . implode(',', array_fill(0, count($routineEmailActions), '?')) . ') ORDER BY action');
$actionOptions->execute($routineEmailActions);
$actions = $actionOptions->fetchAll(PDO::FETCH_COLUMN);
$printQuery = http_build_query(array_filter([
    'search' => $search,
    'module' => $module,
    'action' => $action,
    'actor' => $actor,
    'outcome' => $outcome,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
], static fn ($value): bool => $value !== ''));

render_header('Audit Log');
render_clinic_command_header(
    'Governance',
    'System Audit Log',
    'Review sensitive actions across CLINiQ.',
    $hasPrintDateRange
        ? '<a class="btn btn-primary text-decoration-none" data-no-ajax="true" href="print.php?' . e($printQuery) . '"><span class="material-symbols-outlined text-[18px]">print</span>Print Audit Log</a>'
        : '<button class="btn btn-outline" type="button" disabled title="Choose a start and end date before printing."><span class="material-symbols-outlined text-[18px]">print</span>Choose dates to print</button>'
);
?>
<div class="audit-page">
<section class="clinic-card overflow-hidden mb-6">
    <div class="p-6 border-b border-slate-100"><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Audit Filters</h2><p class="text-xs font-bold text-slate-500 mb-0">Filter sensitive system activity by module, actor, result, or date. Choose both dates before printing.</p></div>
    <form method="get" class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="lg:col-span-2"><label class="clinic-label" for="auditSearch">Search</label><input id="auditSearch" class="clinic-input" name="search" value="<?= e($search) ?>" placeholder="Search names, ID numbers, or affected records"></div>
        <div><label class="clinic-label" for="auditModule">Activity area</label><select id="auditModule" class="clinic-select" name="module"><option value="">All activity areas</option><?php foreach ($modules as $value): ?><option value="<?= e($value) ?>" <?= $module === $value ? 'selected' : '' ?>><?= e(audit_log_module_label((string) $value)) ?></option><?php endforeach; ?></select></div>
        <div><label class="clinic-label" for="auditAction">Activity</label><select id="auditAction" class="clinic-select" name="action"><option value="">All activities</option><?php foreach ($actions as $value): ?><option value="<?= e($value) ?>" <?= $action === $value ? 'selected' : '' ?>><?= e(audit_log_action_label((string) $value)) ?></option><?php endforeach; ?></select></div>
        <div><label class="clinic-label" for="auditOutcome">Result</label><select id="auditOutcome" class="clinic-select" name="outcome"><option value="">All results</option><option value="success" <?= $outcome === 'success' ? 'selected' : '' ?>>Successful</option><option value="unsuccessful" <?= in_array($outcome, ['unsuccessful', 'failure', 'failed'], true) ? 'selected' : '' ?>>Unsuccessful</option></select></div>
        <div><label class="clinic-label" for="auditFrom">Date from</label><input id="auditFrom" class="clinic-input" type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
        <div><label class="clinic-label" for="auditTo">Date to</label><input id="auditTo" class="clinic-input" type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
        <div class="flex items-end gap-2"><button class="btn btn-primary w-full justify-center" type="submit">Apply filters</button><a class="btn btn-outline text-decoration-none justify-center" href="index.php">Clear</a></div>
    </form>
</section>
<section class="clinic-card overflow-hidden">
    <div class="p-6 border-b border-slate-100"><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Activity history</h2><p class="text-xs font-bold text-slate-500 mb-0"><?= number_format($total) ?> matching activities</p></div>
    <?php render_ag_grid('auditLogGrid', $auditColumns, $auditRows, [
        'height' => 'compact',
        'rowHeight' => 76,
        'fitColumns' => true,
        'emptyTitle' => 'No audit events found',
        'emptyText' => 'No audit events match these filters.',
    ]); ?>
    <?php foreach ($auditDetailModals as $detail): ?>
        <div id="<?= e($detail['id']) ?>" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="<?= e($detail['id']) ?>Title">
            <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-2xl p-7 shadow-2xl border border-outline-variant/10">
                <div class="flex items-start justify-between gap-4 mb-6">
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-widest text-primary mb-1">Audit event details</p>
                        <h3 id="<?= e($detail['id']) ?>Title" class="font-headline text-2xl font-extrabold text-[#17261d] mb-1"><?= e($detail['activity']) ?></h3>
                        <p class="text-sm font-bold text-slate-500 mb-0"><?= e($detail['created']) ?></p>
                    </div>
                    <button type="button" class="btn btn-ghost justify-center px-3" onclick="closeModal('<?= e($detail['id']) ?>')" aria-label="Close audit event details">
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </button>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                    <div class="patient-profile-field"><span class="clinic-label">Performed by</span><strong><?= e($detail['actor']) ?><br><span class="text-slate-500 font-bold text-sm"><?= e($detail['actorId']) ?></span></strong></div>
                    <div class="patient-profile-field"><span class="clinic-label">Activity area</span><strong><?= e($detail['module']) ?></strong></div>
                    <div class="patient-profile-field"><span class="clinic-label">Affected record</span><strong><?= e($detail['target']) ?></strong></div>
                    <div class="patient-profile-field"><span class="clinic-label">Result</span><strong><?= e($detail['outcome']) ?></strong></div>
                </div>
                <div class="patient-profile-note">
                    <span class="clinic-label">Additional information</span>
                    <p class="m-0 mt-2 text-sm font-bold text-slate-600"><?= e($detail['details']) ?></p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($pages > 1): ?><div class="p-4 flex items-center justify-between border-t border-slate-100"><span class="text-xs font-bold text-slate-500">Page <?= $page ?> of <?= $pages ?></span><div class="flex gap-2"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline text-decoration-none" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?><?php if ($page < $pages): ?><a class="btn btn-sm btn-outline text-decoration-none" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?></div></div><?php endif; ?>
</section>
</div>
<?php render_footer(); ?>
