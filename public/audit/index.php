<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AuditLog.php';
require_once __DIR__ . '/../../app/services/PatientEmail.php';
require_once __DIR__ . '/../../app/services/ClinicWorkCenter.php';

require_login();
$user = current_user();
$tab = ($_GET['tab'] ?? 'audit') === 'email' ? 'email' : 'audit';
if ($tab === 'audit' && ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('You are not authorized to view the audit log.');
}
ensure_audit_log_schema();

if ($tab === 'email') {
    $actorPersonId = (int) ($user['person_id'] ?? 0) ?: null;
    $emailRole = (string) ($user['role'] ?? '');
    $canManageEmailOperations = in_array($emailRole, ['admin', 'doctor', 'it_expert'], true);
    $emailOpsActions = ['process', 'process_now', 'retry_blocked', 'resend', 'automation', 'custom_send', 'pause_queue', 'resume_queue', 'reschedule', 'edit_pending', 'assign_follow_up', 'send_clinic_reminders'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $emailAction = (string) ($_POST['email_action'] ?? '');
        try {
            if (in_array($emailAction, $emailOpsActions, true) && !$canManageEmailOperations) {
                throw new RuntimeException('This email action requires an administrator, doctor, or IT expert.');
            }
            if ($emailAction === 'process') {
                patient_email_due_automations();
                $result = patient_email_process_queue(null, 50);
                audit_log_event('email', 'email_queue_processed', $actorPersonId, 'staff', 'email', null, $result);
                flash_message(!empty($result['quota_limited']) ? 'warning' : 'success', !empty($result['paused']) ? 'The email queue is paused. No automatic emails were processed.' : "Processed {$result['processed']} email(s): {$result['sent']} sent, {$result['failed']} failed, " . (int) ($result['deferred'] ?? 0) . ' deferred by capacity, ' . (int) ($result['quota_limited'] ?? 0) . ' quota-limited.');
            } elseif ($emailAction === 'send_clinic_reminders') {
                $reviewed = [];
                foreach ((array) ($_POST['reminders'] ?? []) as $entry) {
                    if (!is_array($entry)) continue;
                    $reviewed[] = [
                        'patient_person_id' => (int) ($entry['patient_person_id'] ?? 0),
                        'source_type' => trim((string) ($entry['source_type'] ?? 'ape')),
                        'source_id' => (int) ($entry['source_id'] ?? 0),
                        'event_type' => trim((string) ($entry['event_type'] ?? '')),
                        'previous_deadline' => trim((string) ($entry['previous_deadline'] ?? '')),
                        'subject' => (string) ($entry['subject'] ?? ''),
                        'message' => (string) ($entry['message'] ?? ''),
                    ];
                }
                $result = clinic_work_center_send_reminders((int) $actorPersonId, 3, $reviewed);
                flash_message(($result['blocked'] > 0 || $result['failed'] > 0) ? 'warning' : 'success', sprintf(
                    'Reminders complete: %d sent, %d queued, %d deferred, %d skipped, %d blocked, %d failed. %d deadline(s) extended.',
                    $result['sent'], $result['queued'], $result['deferred'], $result['skipped'], $result['blocked'], $result['failed'], $result['extended']
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
                $queued = 0;
                foreach ($ids as $patientId) {
                    $queued += patient_email_dispatch_event([
                        'patient_person_id' => $patientId,
                        'event_type' => 'custom_patient_email',
                        'origin' => 'manual',
                        'created_by_person_id' => $actorPersonId,
                        'subject' => $subject,
                        'message' => $message,
                        'deliver_now' => true,
                    ]) > 0 ? 1 : 0;
                }
                flash_message($queued > 0 ? 'success' : 'error', $queued > 0 ? "Queued {$queued} custom email(s) and recorded each delivery attempt." : 'No selected patient had an active account with a valid email address.');
            }
        } catch (Throwable $e) { flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage()); }
        header('Location: index.php?tab=email#needs-attention'); exit;
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
    $workItems = clinic_work_center_items(array_merge($workFilters, ['kind' => 'workflow', 'email_relevant' => true]), 1000);
    $allWorkItems = clinic_work_center_items(['kind' => 'workflow', 'email_relevant' => true], 1000);
    $reminderCandidates = clinic_work_center_reminder_candidates();
    $needsReminderItems = array_values(array_filter($reminderCandidates, static fn(array $item): bool => ($item['reminder_state'] ?? '') === 'needs_attention' && !empty($item['can_send_reminder'])));
    $recentlyRemindedItems = array_values(array_filter($reminderCandidates, static fn(array $item): bool => ($item['reminder_state'] ?? '') === 'recently_reminded'));
    $workItemKey = static fn(array $item): string => implode(':', [(int) ($item['patient_person_id'] ?? 0), (string) ($item['email_source_type'] ?? $item['source_type'] ?? ''), (int) ($item['email_source_id'] ?? $item['source_id'] ?? 0), (string) ($item['email_event_type'] ?? '')]);
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
        'date_from' => trim((string) ($_GET['date_from'] ?? '')),
        'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    ];
    $summary = patient_email_summary();
    $attentionItems = patient_email_attention_items();
    $upcomingItems = patient_email_upcoming_items();
    $emailIssueCount = (int) $summary['failed'] + (int) $summary['blocked'] + (int) $summary['max_attempts'] + (int) $summary['follow_up'];
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
    $recent = patient_email_history(['status' => 'sent'], 1, 5);
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
    $patients = auth_db()->query("SELECT p.id, TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS name, a.email FROM people p JOIN patients pt ON pt.person_id = p.id JOIN accounts a ON a.person_id = p.id WHERE a.account_status = 'active' AND a.email IS NOT NULL AND TRIM(a.email) <> '' ORDER BY name LIMIT 500")->fetchAll();
    $composePatients = $patients;
    $patientEmailIds = [];
    $patientDirectory = [];
    foreach ($patients as $patient) $patientEmailIds[(int) $patient['id']] = true;
    foreach ($patients as $patient) $patientDirectory[(int) $patient['id']] = $patient;
    $contextEmailLast = [];
    foreach ($allWorkItems as $workItem) {
        $patientId = (int) ($workItem['patient_person_id'] ?? 0);
        if (($workItem['action_type'] ?? '') !== 'compose_email' || $patientId < 1 || isset($contextEmailLast[$patientId])) continue;
        $lastEmail = auth_db()->prepare('SELECT subject, sent_at, created_at FROM email_queue WHERE patient_person_id = ? ORDER BY COALESCE(sent_at, created_at) DESC, id DESC LIMIT 1');
        $lastEmail->execute([$patientId]);
        $contextEmailLast[$patientId] = $lastEmail->fetch() ?: null;
    }
    $mailConfig = mail_settings_configured() ? mail_settings() : [];
    $mailUser = trim((string) ($mailConfig['username'] ?? env_value('MAIL_USER', '')));
    $mailHost = trim((string) ($mailConfig['host'] ?? env_value('MAIL_HOST', '')));
    $mailFrom = (string) ($mailConfig['from_email'] ?? env_value('MAIL_FROM', $mailUser));
    $mailReady = $mailHost !== '' && $mailUser !== '' && filter_var($mailFrom, FILTER_VALIDATE_EMAIL);
    $automationEnabled = count(array_filter($automation));
    $allEmailOpen = $filters['search'] !== '' || $filters['status'] !== '' || $filters['event_type'] !== '' || $filters['source_type'] !== '' || $filters['origin'] !== '' || $filters['sender'] !== '' || $filters['delivery_state'] !== '' || $filters['follow_up'] !== '' || $filters['assigned_to'] > 0 || $filters['date_from'] !== '' || $filters['date_to'] !== '';
    $sourceUrl = static function (array $item): string {
        $sourceType = (string) ($item['source_type'] ?? '');
        $sourceId = (int) ($item['source_id'] ?? 0);
        $patientId = (int) ($item['patient_person_id'] ?? 0);
        if ($sourceType === 'ape' && $sourceId > 0) return '../ape/view.php?id=' . $sourceId;
        if ($sourceType === 'appointment' && $sourceId > 0) return '../appointments/index.php?status=Scheduled';
        return $patientId > 0 ? '../patients/view.php?id=' . $patientId : '';
    };
    $ageLabel = static function (string $timestamp): string {
        if ($timestamp === '') return 'Needs review';
        $seconds = max(0, time() - strtotime($timestamp));
        if ($seconds < 3600) return max(1, (int) floor($seconds / 60)) . ' min ago';
        if ($seconds < 86400) return (int) floor($seconds / 3600) . ' hr ago';
        return (int) floor($seconds / 86400) . ' days ago';
    };
    render_header('Email Center');
    render_clinic_command_header('Governance', 'Email Center', 'Start with what needs attention now, then manage delivery history and automation.', $canManageEmailOperations ? '<button type="button" class="btn btn-primary" onclick="showModal(\'patientEmailComposeModal\')"><span class="material-symbols-outlined text-[18px]">edit</span>Send patient email</button>' : '');
    ?>
    <div class="audit-page">
        <nav class="clinic-card p-2 mb-6 grid grid-cols-1 md:grid-cols-2 gap-2" aria-label="Governance navigation">
            <a class="btn w-full justify-center <?= $tab === 'audit' ? 'btn-primary' : 'btn-outline' ?> text-decoration-none" href="index.php?tab=audit">Audit Log</a>
            <a class="btn w-full justify-center <?= $tab === 'email' ? 'btn-primary' : 'btn-outline' ?> text-decoration-none" href="index.php?tab=email">Email Center</a>
        </nav>
        <section id="email-center-intro" class="clinic-card p-6 mb-6 bg-[#edf7ef] border border-[#cde5d2]" hidden>
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                <div><p class="text-[11px] font-black uppercase tracking-widest text-primary mb-2">How to use Email Center</p><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">What needs attention now?</h2><p class="text-sm text-slate-600 mb-0">Review urgent clinic tasks, resolve email problems, and open the related patient record. Each item has one recommended next action.</p></div>
                <button type="button" class="btn btn-outline shrink-0" data-dismiss-email-intro>Got it</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-5 text-sm"><div class="rounded-xl bg-white/80 p-4"><strong class="block text-[#17261d]">1. Review</strong><span class="text-slate-500">Start with urgent or overdue clinic and email work.</span></div><div class="rounded-xl bg-white/80 p-4"><strong class="block text-[#17261d]">2. Act</strong><span class="text-slate-500">Use the one recommended action on each item.</span></div><div class="rounded-xl bg-white/80 p-4"><strong class="block text-[#17261d]">3. Manage</strong><span class="text-slate-500">Open history or automation only when needed.</span></div></div>
        </section>

        <?php if ($followUpHistory['rows']): ?><section class="clinic-card overflow-hidden mb-6" aria-label="Email follow-up work"><div class="p-5 border-b border-slate-100"><h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Email follow-up work</h2><p class="text-sm text-slate-500 mb-0">Close issues after the patient profile or delivery problem has been handled.</p></div><div class="divide-y divide-slate-100"><?php foreach ($followUpHistory['rows'] as $row): ?><div class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3"><div><p class="font-bold text-[#17261d] mb-1"><?= e($row['patient_name'] ?: $row['recipient_name']) ?></p><p class="text-xs text-slate-500 mb-0"><?= e($row['subject']) ?> · <?= e(ucfirst((string) $row['status'])) ?> · <?= (int) $row['attempts'] ?> attempt(s)</p></div><div class="flex flex-wrap gap-2"><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="resolve_follow_up"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-primary" data-confirm-submit data-confirm-title="Resolve follow-up?" data-confirm-message="Confirm that this email issue has been handled.">Mark resolved</button></form><a class="btn btn-sm btn-outline text-decoration-none" href="../patients/view.php?id=<?= (int) $row['patient_person_id'] ?>">Open patient</a></div></div><?php endforeach; ?></div></section><?php endif; ?>
        <?php $blockedEmailItems = array_values(array_filter($attentionItems, static fn(array $item): bool => ($item['kind'] ?? '') === 'blocked')); ?>
        <?php if ($blockedEmailItems): ?><section class="clinic-card overflow-hidden mb-6 border border-amber-200" aria-label="Blocked patient emails"><div class="p-5 border-b border-amber-100 bg-amber-50"><h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Blocked emails need a recipient fix</h2><p class="text-sm text-slate-600 mb-0">Recheck the patient address after updating the profile. The email will not be queued until a valid active address is found.</p></div><div class="divide-y divide-slate-100"><?php foreach ($blockedEmailItems as $item): ?><div class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3"><div><p class="font-bold text-[#17261d] mb-1"><?= e($item['patient_name']) ?></p><p class="text-sm text-slate-500 mb-0"><?= e($item['message']) ?></p></div><div class="flex flex-wrap gap-2"><a class="btn btn-sm btn-outline text-decoration-none" href="../patients/view.php?id=<?= (int) $item['patient_person_id'] ?>">Open patient</a><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="retry_blocked"><input type="hidden" name="id" value="<?= (int) $item['email_id'] ?>"><button class="btn btn-sm btn-primary" data-confirm-submit data-confirm-title="Recheck recipient?" data-confirm-message="The patient address will be revalidated before this email is queued.">Recheck and retry</button></form></div></div><?php endforeach; ?></div></section><?php endif; ?>
        <section class="clinic-card overflow-hidden mb-6" aria-label="Email Center status and clinic work">
            <div class="p-6 pb-0"><div class="grid grid-cols-1 md:grid-cols-3 gap-3" aria-label="Email Center status">
            <?php $capacity = (array) ($summary['capacity'] ?? []); $capacityLabel = !$mailReady ? 'Email service unavailable' : (!empty($summary['queue_paused']) ? 'Queue paused' : ((string) ($capacity['capacity_status'] ?? '') === 'reached' ? 'Capacity reached' : (!empty($capacity['last_quota_rejection_at']) ? 'Provider limit detected' : 'Capacity available'))); ?>
            <div class="clinic-card p-4 flex items-center gap-3"><span class="w-11 h-11 rounded-xl <?= !$mailReady ? 'bg-red-50 text-red-600' : ((string) ($capacity['capacity_status'] ?? '') === 'reached' ? 'bg-amber-50 text-amber-700' : 'bg-[#e7f4e9] text-primary') ?> flex items-center justify-center"><span class="material-symbols-outlined text-[22px]"><?= !$mailReady ? 'error' : ((string) ($capacity['capacity_status'] ?? '') === 'reached' ? 'hourglass_top' : 'check_circle') ?></span></span><div><p class="font-extrabold text-[#17261d] text-lg mb-1"><?= e($capacityLabel) ?></p><p class="text-sm text-slate-600 mb-0"><strong><?= number_format((int) ($capacity['accepted_count'] ?? 0)) ?></strong> sent <span class="text-slate-400">·</span> <strong><?= number_format((int) ($capacity['remaining_capacity'] ?? 0)) ?></strong> remaining</p><p class="text-[10px] text-slate-400 mb-0"><?= e(($capacity['capacity_source'] ?? 'initial_safety') === 'initial_safety' ? 'Initial safety capacity' : 'Observed provider capacity') ?>: <?= number_format((int) ($capacity['observed_capacity'] ?? 0)) ?></p></div></div>
            <div class="clinic-card p-4 flex flex-col gap-3"><div class="flex items-center justify-between gap-3"><div class="flex items-center gap-3"><span class="w-10 h-10 rounded-xl <?= !empty($summary['queue_paused']) ? 'bg-amber-50 text-amber-700' : 'bg-[#e7f4e9] text-primary' ?> flex items-center justify-center"><span class="material-symbols-outlined"><?= !empty($summary['queue_paused']) ? 'pause_circle' : 'sync' ?></span></span><div><p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Queue</p><p class="font-extrabold text-[#17261d] mb-0"><?= !empty($summary['queue_paused']) ? 'Paused' : 'Running' ?> · <?= number_format($summary['pending']) ?> pending</p><p class="text-xs text-slate-500 mb-0"><?= number_format($summary['failed']) ?> failed · <?= number_format($summary['blocked']) ?> blocked</p></div></div><?php if ($canManageEmailOperations): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="<?= !empty($summary['queue_paused']) ? 'resume_queue' : 'pause_queue' ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="<?= !empty($summary['queue_paused']) ? 'Resume queue?' : 'Pause queue?' ?>" data-confirm-message="<?= !empty($summary['queue_paused']) ? 'New automatic emails will be processed again.' : 'Automatic emails will remain queued but will not be delivered until resumed.' ?>"><?= !empty($summary['queue_paused']) ? 'Resume' : 'Pause' ?></button></form><?php endif; ?></div><?php if ($canManageEmailOperations): ?><form method="post" class="flex justify-center"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="process"><button class="btn btn-sm btn-outline w-full justify-center" data-confirm-submit data-confirm-title="Process email queue now?" data-confirm-message="Due automated emails will be created and the queue will process up to 50 messages."><span class="material-symbols-outlined text-[16px] align-middle">play_arrow</span> Process queue now</button></form><?php endif; ?></div>
            <div class="clinic-card p-4 flex items-center gap-3"><span class="w-10 h-10 rounded-xl <?= $automationEnabled === count($automation) ? 'bg-[#e7f4e9] text-primary' : 'bg-amber-50 text-amber-700' ?> flex items-center justify-center"><span class="material-symbols-outlined">tune</span></span><div><p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Automation</p><p class="font-extrabold text-[#17261d] mb-0"><?= $automationEnabled ?>/<?= count($automation) ?> categories enabled</p></div></div>
            </div></div>
            <?php if ($emailIssueCount > 0): ?><div class="mx-6 mt-4 rounded-xl border border-red-200 bg-red-50 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3"><div><p class="font-extrabold text-red-800 mb-1"><?= number_format($emailIssueCount) ?> patient email issue<?= $emailIssueCount === 1 ? '' : 's' ?> need attention</p><p class="text-sm text-red-700 mb-0"><?= number_format($summary['failed']) ?> failed · <?= number_format($summary['blocked']) ?> blocked · <?= number_format($summary['follow_up']) ?> follow-up</p></div><a class="btn btn-sm btn-outline text-decoration-none" href="index.php?tab=email&amp;status=failed">Review email issues</a></div><?php endif; ?>


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
                 <form method="get" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end" data-table-filter="email-clinic-work" data-filter-region="email-clinic-work"><input type="hidden" name="tab" value="email"><?php if ($workFilters['priority'] !== ''): ?><input type="hidden" name="work_priority" value="<?= e($workFilters['priority']) ?>"><?php endif; ?><div><label class="clinic-label">Search clinic work</label><input class="clinic-input" name="work_search" value="<?= e($workFilters['search']) ?>" placeholder="Patient or task" aria-label="Search clinic work"></div><div><label class="clinic-label">Age / due status</label><select class="clinic-select" name="work_age" onchange="this.form.submit()"><option value="">All work</option><?php foreach(['overdue', 'due_today', 'due_soon', 'older_than_7_days', 'no_due_date'] as $value): ?><option value="<?= e($value) ?>" <?= $workFilters['age'] === $value ? 'selected' : '' ?>><?= e($workLabel($value)) ?></option><?php endforeach; ?></select></div><div><label class="clinic-label">Area</label><select class="clinic-select" name="work_area" onchange="this.form.submit()"><option value="">All areas</option><?php foreach($workAreas as $value): ?><option value="<?= e($value) ?>" <?= $workFilters['area'] === $value ? 'selected' : '' ?>><?= e($value) ?></option><?php endforeach; ?></select></div><div class="md:col-span-3 flex flex-wrap items-center justify-between gap-3"><span class="text-xs text-slate-400">Priority is selected above. Age and due status update automatically.</span><div class="flex items-center gap-2"><a class="btn btn-sm btn-outline text-decoration-none" href="index.php?tab=email#needs-attention" data-table-filter-link>Clear</a><?php if ($canManageEmailOperations): ?><button type="button" class="btn btn-primary px-4 py-2 text-sm shadow-md" onclick="showModal('clinicReminderReviewModal')"><span class="material-symbols-outlined text-[17px] align-middle">mail</span> Send clinic reminders <span class="ml-1 rounded-full bg-white/20 px-2 py-0.5 text-xs font-black"><?= number_format(count($needsReminderItems)) ?> ready</span></button><?php endif; ?></div></div></form>
            </div>
            <?php if (!$workItems): ?>
                <div class="p-10 flex flex-col items-center text-center"><span class="w-14 h-14 rounded-2xl bg-[#e7f4e9] text-primary flex items-center justify-center mb-3"><span class="material-symbols-outlined text-3xl">task_alt</span></span><h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">No patient-contact work needs attention</h3><p class="text-sm text-slate-500 mb-0">There are no unresolved reminders, nonresponses, or email delivery problems. Routine clinic work remains in notifications, and completed email activity is in history below.</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" aria-label="Clinic work actions">
                        <thead class="bg-slate-50 text-left text-[10px] uppercase tracking-widest text-slate-500"><tr><th class="px-5 py-3 font-black">Priority</th><th class="px-5 py-3 font-black">Patient</th><th class="px-5 py-3 font-black">What needs attention</th><th class="px-5 py-3 font-black">Category / source</th><th class="px-5 py-3 font-black">When</th><th class="sticky right-0 z-10 bg-slate-50 px-5 py-3 font-black text-right min-w-[170px]">Action</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($displayWorkItems as $item): ?>
                                <tr class="align-top hover:bg-slate-50/70">
                                    <td class="px-5 py-4 whitespace-nowrap"><span class="badge <?= e($workBadge((string) $item['priority'])) ?>"><?= e($workLabel((string) $item['priority'])) ?></span></td>
                                    <td class="px-5 py-4 min-w-[180px]"><strong class="block text-[#17261d]"><?= e($item['patient_name']) ?></strong><?php if ((int) $item['patient_person_id'] > 0): ?><a class="text-xs text-primary font-bold text-decoration-none" href="../patients/view.php?id=<?= (int) $item['patient_person_id'] ?>">Open patient</a><?php endif; ?><?php if (($item['patient_email'] ?? '') !== ''): ?><span class="block text-xs text-slate-500 mt-1"><?= e($item['patient_email']) ?></span><?php endif; ?></td>
                                    <td class="px-5 py-4 min-w-[260px]"><strong class="block text-[#17261d]"><?= e($item['title']) ?></strong><span class="block text-xs text-slate-600 mt-1"><?= e($item['explanation']) ?></span><?php if ((int) ($item['collapsed_count'] ?? 1) > 1): ?><span class="block text-xs font-bold text-slate-500 mt-2">One warning for this issue type; details remain in the source record.</span><?php endif; ?></td>
                                    <td class="px-5 py-4 min-w-[150px]"><span class="badge badge-outline"><?= e($item['category']) ?></span><span class="block text-xs text-slate-500 mt-2"><?= e($item['label']) ?></span></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-xs font-bold text-slate-500"><?= e($item['due_at'] !== '' ? 'Due ' . $item['due_at'] : 'Created ' . $ageLabel((string) $item['created_at'])) ?></td>
                                    <td class="sticky right-0 z-[1] bg-white px-5 py-4 min-w-[170px]"><div class="flex flex-wrap justify-end gap-2"><?php if ($item['action_type'] === 'retry_email'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="retry"><input type="hidden" name="id" value="<?= (int) $item['email_id'] ?>"><button class="btn btn-sm btn-primary whitespace-nowrap" data-confirm-submit data-confirm-title="Retry email?" data-confirm-message="This will attempt delivery again to the patient.">Retry email</button></form><?php elseif ($item['action_type'] === 'cancel_email'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="cancel"><input type="hidden" name="id" value="<?= (int) $item['email_id'] ?>"><button class="btn btn-sm btn-primary whitespace-nowrap" data-confirm-submit data-confirm-title="Cancel email?" data-confirm-message="This queued email will not be delivered.">Cancel email</button></form><?php elseif ($item['action_type'] === 'view_email'): ?><a class="btn btn-sm btn-primary text-decoration-none whitespace-nowrap" href="#all-email">View details</a><?php elseif ($item['action_type'] === 'compose_email' && $canManageEmailOperations && isset($patientEmailIds[(int) $item['patient_person_id']])): ?><button type="button" class="btn btn-sm btn-primary whitespace-nowrap" data-open-patient-email data-patient-id="<?= (int) $item['patient_person_id'] ?>" data-subject="<?= e((string) ($item['email_subject'] ?? 'Patient follow-up')) ?>" data-message="<?= e((string) ($item['email_message'] ?? 'Please review the outstanding clinic action and contact the clinic if you need assistance.')) ?>">Send email</button><?php if ($item['source_url'] !== ''): ?><a class="btn btn-sm btn-outline text-decoration-none whitespace-nowrap" href="<?= e($item['source_url']) ?>">Open source</a><?php endif; ?><?php elseif ($item['source_url'] !== ''): ?><a class="btn btn-sm btn-primary text-decoration-none whitespace-nowrap" href="<?= e($item['source_url']) ?>"><?= e($item['action_label']) ?></a><?php endif; ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        </section>

        <section class="clinic-card overflow-hidden mb-6" id="recently-reminded" aria-label="Recently reminded students">
            <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Recently reminded</h2><p class="text-sm text-slate-500 mb-0">Students already contacted stay here until their three-day deadline passes.</p></div>
                <span class="badge badge-pending"><?= number_format(count($recentlyRemindedItems)) ?> being monitored</span>
            </div>
            <?php if (!$recentlyRemindedItems): ?>
                <div class="p-8 text-center text-sm text-slate-500">No students are inside an active reminder window.</div>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($recentlyRemindedItems as $item): ?>
                        <article class="p-5 flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                            <div class="min-w-0"><p class="font-extrabold text-[#17261d] mb-1"><?= e($item['patient_name']) ?></p><p class="text-sm text-slate-700 mb-1"><?= e($item['title']) ?></p><p class="text-xs text-slate-500 mb-0"><?= e((string) ($item['reminder_count'] ?? 0)) ?> reminder<?= ((int) ($item['reminder_count'] ?? 0)) === 1 ? '' : 's' ?> · <?= $item['last_reminded_at'] ? e(date('M j, Y g:i A', strtotime((string) $item['last_reminded_at']))) : 'Queued recently' ?> · Status: <?= e(ucfirst((string) ($item['last_reminder_status'] ?? 'queued'))) ?></p></div>
                            <div class="flex flex-wrap items-center gap-2 shrink-0"><span class="text-xs font-bold text-slate-500">New deadline: <?= $item['current_deadline'] ? e(date('M j, Y', strtotime((string) $item['current_deadline']))) : 'Pending' ?></span><?php if (!empty($item['source_url'])): ?><a class="btn btn-sm btn-outline text-decoration-none" href="<?= e($item['source_url']) ?>">Open APE record</a><?php endif; ?><a class="btn btn-sm btn-outline text-decoration-none" href="index.php?tab=email&amp;origin=manual#all-email">View email history</a></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="clinic-card overflow-hidden mb-6" id="legacy-email-attention" hidden>
            <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-3"><div><div class="flex items-center gap-2"><span class="w-8 h-8 rounded-lg bg-red-50 text-red-600 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">priority_high</span></span><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-0">Needs attention</h2></div><p class="text-sm text-slate-500 mt-2 mb-0">Start here. These messages need a staff decision before the patient can be contacted.</p></div><a class="btn btn-sm btn-outline text-decoration-none" href="#all-email">Open all email</a></div>
            <?php if (!$attentionItems): ?><div class="p-8 flex flex-col items-center text-center"><span class="w-14 h-14 rounded-2xl bg-[#e7f4e9] text-primary flex items-center justify-center mb-3"><span class="material-symbols-outlined text-3xl">task_alt</span></span><h3 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Nothing needs attention</h3><p class="text-sm text-slate-500 mb-0">Automatic emails are running normally. New urgent items will appear here.</p></div><?php else: ?><div class="divide-y divide-slate-100"><?php foreach ($attentionItems as $item): $source = $sourceUrl($item); ?><article class="p-5 flex flex-col xl:flex-row xl:items-center gap-4"><div class="flex items-start gap-3 min-w-0 flex-1"><span class="w-9 h-9 rounded-xl <?= $item['kind'] === 'failed' ? 'bg-red-50 text-red-600' : ($item['kind'] === 'missing_address' ? 'bg-amber-50 text-amber-700' : 'bg-orange-50 text-orange-700') ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[19px]"><?= $item['kind'] === 'failed' ? 'mail_failed' : ($item['kind'] === 'missing_address' ? 'contact_mail' : 'schedule') ?></span></span><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h3 class="font-extrabold text-[#17261d] mb-0"><?= e($item['title']) ?></h3><span class="badge <?= $item['kind'] === 'failed' ? 'badge-high' : 'badge-pending' ?>"><?= e(ucfirst(str_replace('_', ' ', $item['kind']))) ?></span></div><p class="text-sm text-slate-600 mt-1 mb-1"><?= e($item['message']) ?></p><p class="text-xs font-bold text-slate-500 mb-0"><strong><?= e($item['patient_name']) ?></strong> · <?= e($item['event_label']) ?> · <?= e($ageLabel((string) $item['created_at'])) ?><?php if ($item['recipient_email'] !== ''): ?> · <?= e($item['recipient_email']) ?><?php endif; ?></p></div></div><div class="flex flex-wrap items-center gap-2 xl:justify-end shrink-0"><?php if ($item['action'] === 'retry'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="retry"><input type="hidden" name="id" value="<?= (int) $item['email_id'] ?>"><button class="btn btn-sm btn-primary" data-confirm-submit data-confirm-title="Retry email?" data-confirm-message="This will attempt delivery again to the patient."><?= e($item['action_label']) ?></button></form><?php elseif ($item['action'] === 'process'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="process"><button class="btn btn-sm btn-primary" data-confirm-submit data-confirm-title="Review and send overdue email?" data-confirm-message="The overdue email will be delivered to the patient if the email service is ready."><?= e($item['action_label']) ?></button></form><?php else: ?><a class="btn btn-sm btn-primary text-decoration-none" href="../patients/view.php?id=<?= (int) $item['patient_person_id'] ?>"><?= e($item['action_label']) ?></a><?php endif; ?><?php if ($source !== ''): ?><a class="btn btn-sm btn-outline text-decoration-none" href="<?= e($source) ?>">Open related record</a><?php endif; ?><?php if ((int) $item['email_id'] > 0): ?><a class="btn btn-sm btn-outline text-decoration-none" href="#all-email">Details</a><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
            <section class="clinic-card overflow-hidden"><div class="p-5 border-b border-slate-100 flex items-center justify-between"><div><h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Upcoming automatic emails</h2><p class="text-xs text-slate-500 mb-0">Scheduled reminders waiting for their send time.</p></div><span class="badge badge-pending"><?= number_format(count($upcomingItems)) ?> queued</span></div><?php if (!$upcomingItems): ?><div class="p-6 text-sm text-slate-500">There are no scheduled emails waiting to be processed.</div><?php else: ?><div class="divide-y divide-slate-100"><?php foreach ($upcomingItems as $row): ?><div class="p-4 flex items-center justify-between gap-3"><div class="min-w-0"><p class="font-bold text-[#17261d] truncate mb-1"><?= e($row['patient_name'] ?: $row['recipient_name']) ?></p><p class="text-xs text-slate-500 mb-0"><?= e(ucwords(str_replace('_', ' ', (string) ($row['event_type'] ?: 'automatic email')))) ?> · Sends <?= e(date('M j, g:i A', strtotime($row['available_at']))) ?></p></div><form method="post" class="shrink-0"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="cancel"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="Cancel scheduled email?" data-confirm-message="This message will stay in history but will not be delivered.">Cancel</button></form></div><?php endforeach; ?></div><?php endif; ?></section>
            <section class="clinic-card overflow-hidden"><div class="p-5 border-b border-slate-100 flex items-center justify-between"><div><h2 class="font-headline text-lg font-extrabold text-[#17261d] mb-1">Recent activity</h2><p class="text-xs text-slate-500 mb-0">The latest messages already delivered to patients.</p></div><span class="badge badge-completed"><?= number_format($summary['sent']) ?> sent</span></div><?php if (!$recent['rows']): ?><div class="p-6 text-sm text-slate-500">No sent email history yet. Automated and manual emails will appear here.</div><?php else: ?><div class="divide-y divide-slate-100"><?php foreach ($recent['rows'] as $row): ?><div class="p-4 flex items-center justify-between gap-3"><div class="min-w-0"><p class="font-bold text-[#17261d] truncate mb-1"><?= e($row['patient_name'] ?: $row['recipient_name']) ?></p><p class="text-xs text-slate-500 mb-0"><?= e($row['subject']) ?> · <?= e(date('M j, g:i A', strtotime($row['sent_at'] ?: $row['created_at']))) ?></p></div><a class="btn btn-sm btn-outline text-decoration-none" href="#all-email">View details</a></div><?php endforeach; ?></div><?php endif; ?></section>
        </section>

        <details class="clinic-card overflow-hidden mb-6" id="all-email" <?= $allEmailOpen ? 'open' : '' ?>><summary class="p-6 cursor-pointer list-none flex flex-col md:flex-row md:items-center justify-between gap-3"><div><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">All email</h2><p class="text-sm text-slate-500 mb-0"><?= number_format($history['total']) ?> matching records · Search, filter, export, and open delivery details.</p></div><div class="flex gap-2"><a class="btn btn-sm btn-outline" href="<?= e('index.php?' . http_build_query(array_merge($_GET, ['tab' => 'email', 'export' => 'csv']))) ?>">Export CSV</a><span class="btn btn-sm btn-outline">Open history</span></div></summary><div class="border-t border-slate-100"><form method="get" class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4"><input type="hidden" name="tab" value="email"><div class="lg:col-span-2"><label class="clinic-label">Search</label><input class="clinic-input" name="search" value="<?= e($filters['search']) ?>" placeholder="Patient, email, subject, or ID"></div><div><label class="clinic-label">Status</label><select class="clinic-select" name="status"><option value="">All statuses</option><?php foreach(['pending','processing','sent','failed','blocked','cancelled'] as $value): ?><option value="<?= $value ?>" <?= $filters['status']===$value?'selected':'' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div><div><label class="clinic-label">Event</label><select class="clinic-select" name="event_type"><option value="">All event types</option><?php foreach($eventTypes as $value): ?><option value="<?= e($value) ?>" <?= $filters['event_type']===$value?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$value))) ?></option><?php endforeach; ?></select></div><div class="flex items-end"><button class="btn btn-outline w-full justify-center">Filter history</button></div><div><label class="clinic-label">Source</label><input class="clinic-input" name="source_type" value="<?= e($filters['source_type']) ?>" placeholder="appointment, ape"></div><div><label class="clinic-label">Origin</label><select class="clinic-select" name="origin"><option value="">All origins</option><?php foreach(['automatic','manual','system'] as $value): ?><option value="<?= $value ?>" <?= $filters['origin']===$value?'selected':'' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div><div><label class="clinic-label">Sender</label><input class="clinic-input" name="sender" value="<?= e($filters['sender']) ?>" placeholder="Name or email"></div><div><label class="clinic-label">From</label><input class="clinic-input" type="date" name="date_from" value="<?= e($filters['date_from']) ?>"></div><div><label class="clinic-label">To</label><input class="clinic-input" type="date" name="date_to" value="<?= e($filters['date_to']) ?>"></div></form><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-[10px] uppercase tracking-widest text-slate-400"><th class="p-4">Created / sent</th><th class="p-4">Patient / recipient</th><th class="p-4">Event / source</th><th class="p-4">Status / delivery</th><th class="p-4">Actions</th></tr></thead><tbody><?php foreach($history['rows'] as $row): ?><tr class="border-t border-slate-100 align-top"><td class="p-4 whitespace-nowrap"><?= e(date('M j, Y g:i A', strtotime($row['created_at']))) ?><br><span class="text-xs text-slate-500"><?= $row['sent_at'] ? 'Sent ' . e(date('M j, g:i A', strtotime($row['sent_at']))) : 'Not sent' ?></span></td><td class="p-4"><?php if((int)$row['patient_person_id']>0): ?><a class="font-bold text-primary" href="../patients/view.php?id=<?= (int)$row['patient_person_id'] ?>"><?= e($row['patient_name'] ?: $row['recipient_name']) ?></a><?php else: ?><strong><?= e($row['patient_name'] ?: $row['recipient_name']) ?></strong><?php endif; ?><br><span class="text-xs text-slate-500"><?= e($row['recipient_email'] ?: 'No recipient address') ?></span><br><span class="text-xs text-slate-400">Sender: <?= e($row['sender_email'] ?: ($row['sender_name'] ?: 'Unknown')) ?></span></td><td class="p-4"><strong><?= e($row['subject']) ?></strong><br><span class="text-xs text-slate-500"><?= e(ucwords(str_replace('_',' ',(string)($row['event_type'] ?: 'email')))) ?> · <?= e((string)($row['source_type'] ?: '—')) ?> <?= (int)$row['source_id'] > 0 ? '#' . (int)$row['source_id'] : '' ?> · <?= e((string)($row['origin'] ?: '—')) ?></span><?php if($row['source_type']==='ape' && (int)$row['source_id']>0): ?><br><a class="text-xs text-primary font-bold" href="../ape/view.php?id=<?= (int)$row['source_id'] ?>">Open APE record</a><?php endif; ?><details class="mt-2 text-xs"><summary class="cursor-pointer font-bold text-slate-500">View delivery details</summary><p class="mt-2 text-slate-600">Scheduled: <?= e((string)($row['available_at'] ?: 'Immediate')) ?><br>Last attempt: <?= e((string)($row['last_attempt_at'] ?: '—')) ?><br>Provider ID: <?= e((string)($row['provider_message_id'] ?: '—')) ?></p><pre class="mt-2 max-w-xl whitespace-pre-wrap text-[11px] text-slate-600"><?= e(strip_tags((string)$row['html_body'])) ?></pre></details></td><td class="p-4"><span class="badge <?= $row['status']==='sent'?'badge-completed':(in_array($row['status'],['failed','blocked'],true)?'badge-high':($row['status']==='cancelled'?'badge-cancelled':'badge-pending')) ?>"><?= e(ucfirst($row['status'])) ?></span><p class="text-xs text-slate-500 mt-2 mb-0"><?= (int)$row['attempts'] ?> attempt(s)</p><?php if($row['last_error'] || $row['blocked_reason']): ?><p class="text-xs text-red-600 mt-2"><?= e($row['last_error'] ?: $row['blocked_reason']) ?></p><?php endif; ?></td><td class="p-4"><div class="flex flex-wrap gap-2"><?php if($row['status']==='failed'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="retry"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="Retry email?" data-confirm-message="This will attempt delivery again to the patient.">Retry</button></form><?php endif; ?><?php if(in_array($row['status'],['pending','failed','processing'],true)): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="cancel"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="Cancel email?" data-confirm-message="This queued email will not be delivered.">Cancel</button></form><?php endif; ?><?php if($canManageEmailOperations && $row['status']==='sent'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="resend"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline" data-confirm-submit data-confirm-title="Resend email?" data-confirm-message="This will create and deliver a duplicate email.">Resend</button></form><?php endif; ?><?php if(in_array($row['status'],['failed','blocked'],true)): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="follow_up"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline">Mark follow-up</button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php if(!$history['rows']): ?><div class="p-8 text-center text-slate-500">No emails match these filters. Try clearing the filters to see all activity.</div><?php endif; ?><?php if($history['pages']>1): ?><div class="p-4 flex justify-between border-t border-slate-100"><span class="text-xs font-bold text-slate-500">Page <?= $history['page'] ?> of <?= $history['pages'] ?></span><div class="flex gap-2"><?php if($history['page']>1): ?><a class="btn btn-sm btn-outline" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$history['page']-1]))) ?>">Previous</a><?php endif; ?><?php if($history['page']<$history['pages']): ?><a class="btn btn-sm btn-outline" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$history['page']+1]))) ?>">Next</a><?php endif; ?></div></div><?php endif; ?></div></details>

        <details class="clinic-card overflow-hidden mb-6"><summary class="p-6 cursor-pointer list-none flex flex-col md:flex-row md:items-center justify-between gap-3"><div><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Automation</h2><p class="text-sm text-slate-500 mb-0"><?= $automationEnabled === count($automation) ? 'All urgent automation categories are enabled.' : 'Some urgent automation categories are paused.' ?> Changes affect future emails only; existing queue records are preserved.</p></div><span class="btn btn-sm btn-outline">Manage automation</span></summary><form method="post" class="p-6 border-t border-slate-100 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="automation"><?php $labels=['appointment_reminders'=>'Appointment confirmations and reminders','appointment_changes'=>'Appointment cancellations and changes','ape_corrections'=>'APE document and clearance corrections','ape_follow_up_reminders'=>'APE follow-up reminders','ape_overdue'=>'APE overdue escalations','access_restrictions'=>'Patient access restrictions','school_year_enrollment'=>'School-year enrollment']; foreach($labels as $key=>$label): ?><label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm font-bold"><input class="mt-1" type="checkbox" name="automation[<?= e($key) ?>]" value="1" <?= !empty($automation[$key]) ? 'checked' : '' ?>><span><span class="block text-[#17261d]"><?= e($label) ?></span><span class="block text-xs font-normal text-slate-500 mt-1">Send only for urgent or time-sensitive patient events.</span></span></label><?php endforeach; ?><div class="md:col-span-2 lg:col-span-3 flex items-center justify-between gap-3"><p class="text-xs text-slate-500 mb-0">Who changed these settings and when is recorded in Audit Log.</p><button class="btn btn-primary" type="submit">Save automation settings</button></div></form></details>

        <div class="mx-6 mt-4 mb-6 rounded-xl bg-slate-50 border border-slate-100 p-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-sm" aria-label="Email queue health"><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Processing</span><strong><?= number_format($summary['processing']) ?></strong></div><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Retryable</span><strong><?= number_format($summary['retryable']) ?></strong></div><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Max attempts reached</span><strong><?= number_format($summary['max_attempts']) ?></strong></div><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Oldest pending</span><strong><?= $summary['oldest_pending'] ? e(date('M j, g:i A', strtotime($summary['oldest_pending']))) : '—' ?></strong></div><div><span class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Worker</span><strong><?= !empty($summary['worker_heartbeat']['at']) ? e(date('M j, g:i A', strtotime((string) $summary['worker_heartbeat']['at']))) : 'No heartbeat' ?></strong></div></div>

        <?php if ($canManageEmailOperations): ?>
        <div id="clinicReminderReviewModal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="clinicReminderReviewTitle">
            <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-4xl shadow-2xl max-h-[90vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-4 mb-6"><div><p class="text-[11px] font-black uppercase tracking-widest text-primary mb-2">Clinic work</p><h2 id="clinicReminderReviewTitle" class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">Review clinic reminders</h2><p class="text-sm text-slate-500 mb-0">Review and edit each student’s message before anything is sent.</p></div><button type="button" class="btn btn-sm btn-ghost" onclick="closeModal('clinicReminderReviewModal')">Close</button></div>
                <div class="grid grid-cols-3 gap-3 mb-5"><div class="rounded-xl bg-[#edf7ef] p-3"><strong class="block text-2xl text-[#17261d]"><?= number_format(count($needsReminderItems)) ?></strong><span class="text-xs text-slate-600">Ready now</span></div><div class="rounded-xl bg-slate-50 p-3"><strong class="block text-2xl text-[#17261d]"><?= number_format(count($recentlyRemindedItems)) ?></strong><span class="text-xs text-slate-600">Recently reminded</span></div><div class="rounded-xl bg-slate-50 p-3"><strong class="block text-2xl text-[#17261d]"><?= !empty($summary['queue_paused']) ? 'Paused' : 'Running' ?></strong><span class="text-xs text-slate-600">Queue</span></div></div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 mb-5"><strong class="block mb-1">Before sending</strong>These messages are personalized from each student’s APE issue. Your edited subject and message will be sent exactly as reviewed. Each successful reminder extends only the related deadline by three days.</div>
                <?php if (!$needsReminderItems): ?><div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-600 mb-6">No students are currently eligible for a new reminder.</div><?php endif; ?>
                <form method="post" id="clinicReminderReviewForm"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="send_clinic_reminders">
                    <div class="space-y-3 mb-6">
                    <?php foreach ($needsReminderItems as $index => $item): $eventType = (string) ($item['email_event_type'] ?? ''); $previousDeadline = (string) ($item['current_deadline'] ?? '') ?: date('Y-m-d'); $subject = (string) ($item['email_subject'] ?? 'APE reminder — action needed'); $message = (string) ($item['email_message'] ?? 'Please log in to your student portal and complete the outstanding APE action, or contact the clinic if you need assistance.'); if (!str_contains($message, 'https://plpuhs.dpdns.org')) $message .= ' Student portal: https://plpuhs.dpdns.org'; ?>
                        <details class="rounded-xl border border-slate-200 bg-white p-4"><summary class="cursor-pointer list-none flex flex-wrap items-center justify-between gap-3"><span><strong class="text-[#17261d]"><?= e($item['patient_name']) ?></strong><span class="block text-xs text-slate-500 mt-1"><?= e($item['title']) ?> · Due <?= e($previousDeadline) ?> · Reminded <?= (int) ($item['reminder_count'] ?? 0) ?> time<?= (int) ($item['reminder_count'] ?? 0) === 1 ? '' : 's' ?></span></span><span class="badge badge-pending">Edit email</span></summary><div class="mt-4 grid gap-3"><div class="text-xs text-slate-500">Recipient: <strong><?= e((string) ($item['patient_email'] ?? 'Email available after validation')) ?></strong> · <a class="text-primary font-bold" href="<?= e((string) ($item['source_url'] ?? '../ape/view.php?id=' . (int) $item['source_id'])) ?>">Open APE record</a></div><input type="hidden" name="reminders[<?= $index ?>][patient_person_id]" value="<?= (int) $item['patient_person_id'] ?>"><input type="hidden" name="reminders[<?= $index ?>][source_type]" value="<?= e((string) ($item['source_type'] ?? 'ape')) ?>"><input type="hidden" name="reminders[<?= $index ?>][source_id]" value="<?= (int) ($item['source_id'] ?? 0) ?>"><input type="hidden" name="reminders[<?= $index ?>][event_type]" value="<?= e($eventType) ?>"><input type="hidden" name="reminders[<?= $index ?>][previous_deadline]" value="<?= e($previousDeadline) ?>"><label class="clinic-label">Subject<input class="clinic-input" name="reminders[<?= $index ?>][subject]" maxlength="180" required value="<?= e($subject) ?>"></label><label class="clinic-label">Message<textarea class="clinic-input min-h-32" name="reminders[<?= $index ?>][message]" maxlength="10000" required><?= e($message) ?></textarea></label></div></details>
                    <?php endforeach; ?>
                    </div>
                    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3"><button type="button" class="btn btn-outline" onclick="closeModal('clinicReminderReviewModal'); document.getElementById('needs-attention')?.scrollIntoView({behavior:'smooth', block:'start'});">Review first</button><button class="btn btn-primary" <?= !$needsReminderItems ? 'disabled' : '' ?>>Send reminders</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div id="patientEmailComposeModal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="patientEmailComposeTitle"><div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-6xl shadow-2xl max-h-[92vh] overflow-y-auto"><div class="flex items-start justify-between gap-4 mb-6"><div><p class="text-[11px] font-black uppercase tracking-widest text-primary mb-2">Manual delivery</p><h2 id="patientEmailComposeTitle" class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">Send a patient email</h2><p class="text-sm text-slate-500 mb-0">The message will be recorded in Email Center and delivered through the same queue.</p></div><button type="button" class="btn btn-sm btn-ghost" onclick="closeModal('patientEmailComposeModal')" aria-label="Close">Close</button></div><form method="post" id="patientEmailComposeForm"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="custom_send"><div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:items-start"><div class="grid gap-4"><label class="clinic-label">Patient<select id="manualEmailPatients" class="clinic-select min-h-32" name="patient_ids[]" multiple required><?php foreach($composePatients as $patient): ?><option value="<?= (int)$patient['id'] ?>"><?= e(($patient['name'] ?: 'Patient').' — '.$patient['email']) ?></option><?php endforeach; ?></select><div id="manualPatientPicker"></div><span class="block text-xs font-normal text-slate-500 mt-1">Select one or more active patients. Only valid patient email addresses can be used.</span></label><label class="clinic-label">Template<select id="manualEmailTemplate" class="clinic-select"><option value="">Choose a message template…</option><?php foreach ($manualMailTemplates as $templateKey => $template): ?><option value="<?= e((string) $templateKey) ?>"><?= e((string) $template['label']) ?></option><?php endforeach; ?></select></label><label class="clinic-label">Subject<input id="manualEmailSubject" class="clinic-input" name="subject" maxlength="180" required></label><label class="clinic-label">Message<textarea id="manualEmailMessage" class="clinic-input min-h-40" name="message" maxlength="10000" required></textarea></label><div class="flex justify-end gap-3 pt-2"><button type="button" class="btn btn-ghost" onclick="closeModal('patientEmailComposeModal')">Cancel</button><button class="btn btn-primary" data-confirm-submit data-confirm-title="Send patient email?" data-confirm-message="This will deliver the message to the selected patients and record it in Email Center.">Review and send</button></div></div><aside class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:sticky lg:top-2" aria-label="Live email preview"><div class="bg-[#3f8256] p-5 text-white"><div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-[#3f8256] font-black">C</span><strong class="text-lg">CLINiQ</strong></div></div><div class="p-6"><p class="mb-2 text-[10px] font-black uppercase tracking-widest text-slate-400">Live preview</p><p id="manualEmailPreviewSubject" class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Subject preview</p><h3 id="manualEmailPreviewHeading" class="mb-4 text-xl font-extrabold text-[#17261d]">Your email subject</h3><p id="manualEmailPreviewMessage" class="whitespace-pre-wrap text-sm leading-6 text-slate-600">Your message preview will appear here.</p></div></aside></div></form></div></div>
        <?php if ($canManageEmailOperations): ?>
        <div id="patientEmailContextModal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="patientEmailContextTitle"><div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-2xl shadow-2xl"><div class="flex items-start justify-between gap-4 mb-6"><div><p class="text-[11px] font-black uppercase tracking-widest text-primary mb-2">Patient follow-up</p><h2 id="patientEmailContextTitle" class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">Review and send email</h2><p class="text-sm text-slate-500 mb-0">This message is linked to the clinic task you selected.</p></div><button type="button" class="btn btn-sm btn-ghost" onclick="closeModal('patientEmailContextModal')" aria-label="Close">Close</button></div><form id="patientEmailContextForm" method="post" class="grid grid-cols-1 gap-4"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="email_action" value="custom_send"><input type="hidden" id="contextPatientId" name="patient_ids[]" value=""><div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Recipient</p><p id="contextPatientName" class="font-extrabold text-[#17261d] mb-1">—</p><p id="contextPatientEmail" class="text-sm text-slate-600 mb-0">—</p></div><div id="contextLastEmail" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" hidden></div><label class="clinic-label">Subject<input id="contextSubject" class="clinic-input" name="subject" maxlength="180" required></label><label class="clinic-label">Message<textarea id="contextMessage" class="clinic-input min-h-32" name="message" maxlength="10000" required></textarea></label><div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600"><strong class="block text-slate-800 mb-1">Preview</strong><span id="contextPreview"></span></div><p class="text-xs text-slate-500 mb-0">Review the recipient and previous email warning before sending. This will be recorded in Email Center.</p><div class="flex justify-end gap-3"><button type="button" class="btn btn-ghost" onclick="closeModal('patientEmailContextModal')">Cancel</button><button class="btn btn-primary" data-confirm-submit data-confirm-title="Send this patient email?" data-confirm-message="This will deliver the reviewed message to the selected patient and record it in Email Center.">Confirm and send</button></div></form></div></div>
        <?php endif; ?>
    </div>
    <script>
        (() => {
            const intro = document.getElementById('email-center-intro');
            const key = 'cliniq.emailCenter.intro.dismissed.v1';
            if (intro && window.localStorage.getItem(key) !== '1') intro.hidden = false;
            document.querySelector('[data-dismiss-email-intro]')?.addEventListener('click', () => {
                window.localStorage.setItem(key, '1');
                if (intro) intro.hidden = true;
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
            const contextLastEmails = <?= json_encode($contextEmailLast, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            document.querySelectorAll('[data-open-context-email], #needs-attention [data-open-patient-email]').forEach((button) => {
                button.addEventListener('click', () => {
                    const patientId = button.getAttribute('data-patient-id');
                    if (!contextForm || !patientId) return;
                    const option = Array.from(document.querySelectorAll('#patientEmailComposeModal select[name="patient_ids[]"] option')).find((candidate) => candidate.value === patientId);
                    const patientLabel = option?.textContent?.split(' — ')[0] || 'Patient';
                    const patientEmail = option?.textContent?.split(' — ')[1] || 'Valid patient email address';
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
            const patientSelect = document.getElementById('manualEmailPatients');
            const template = document.getElementById('manualEmailTemplate');
            const subjectInput = document.getElementById('manualEmailSubject');
            const messageInput = document.getElementById('manualEmailMessage');
            const previewSubject = document.getElementById('manualEmailPreviewSubject');
            const previewHeading = document.getElementById('manualEmailPreviewHeading');
            const previewMessage = document.getElementById('manualEmailPreviewMessage');
            const picker = document.getElementById('manualPatientPicker');
            const configuredTemplates = <?= json_encode($manualMailTemplates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

            if (composeForm && patientSelect && template && subjectInput && messageInput && picker) {
                patientSelect.classList.add('sr-only');
                picker.innerHTML = '<div class="relative"><input type="search" class="clinic-input" placeholder="Search active patients by name or email…" aria-label="Search patients"><div class="absolute z-20 left-0 right-0 top-full mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl" data-patient-results hidden></div></div><div class="mt-2 flex flex-wrap gap-2 min-h-8" data-patient-chips></div><p class="mt-2 text-xs text-slate-500 mb-0" data-patient-count>No patients selected</p>';
                const search = picker.querySelector('input[type="search"]');
                const results = picker.querySelector('[data-patient-results]');
                const chips = picker.querySelector('[data-patient-chips]');
                const count = picker.querySelector('[data-patient-count]');
                const options = Array.from(patientSelect.options);
                const updatePreview = () => {
                    const subject = subjectInput.value.trim();
                    previewSubject.textContent = subject || 'Subject preview';
                    previewHeading.textContent = subject || 'Your email subject';
                    previewMessage.textContent = messageInput.value.trim() || 'Your message preview will appear here.';
                };
                const renderSelectedPatients = () => {
                    chips.innerHTML = '';
                    const selected = options.filter((option) => option.selected);
                    selected.forEach((option) => {
                        const chip = document.createElement('span');
                        chip.className = 'inline-flex items-center gap-1 rounded-full bg-[#e7f4e9] px-3 py-1 text-xs font-bold text-[#285c39]';
                        const label = document.createElement('span');
                        label.textContent = option.textContent || option.value;
                        const remove = document.createElement('button');
                        remove.type = 'button'; remove.className = 'font-black ml-1'; remove.setAttribute('aria-label', 'Remove ' + (option.textContent || 'patient')); remove.textContent = '×';
                        remove.addEventListener('click', () => { option.selected = false; renderSelectedPatients(); });
                        chip.append(label, remove); chips.appendChild(chip);
                    });
                    count.textContent = selected.length ? `${selected.length} patient${selected.length === 1 ? '' : 's'} selected` : 'No patients selected';
                };
                const showResults = () => {
                    const query = (search.value || '').toLowerCase().trim();
                    results.innerHTML = '';
                    options.filter((option) => !option.selected && (!query || (option.textContent || '').toLowerCase().includes(query))).slice(0, 30).forEach((option) => {
                        const button = document.createElement('button');
                        button.type = 'button'; button.className = 'block w-full text-left px-3 py-2 text-sm hover:bg-[#edf7ef]'; button.textContent = option.textContent;
                        button.addEventListener('click', () => { option.selected = true; search.value = ''; results.hidden = true; renderSelectedPatients(); });
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
                search.addEventListener('input', showResults);
                search.addEventListener('focus', showResults);
                document.addEventListener('click', (event) => { if (!picker.contains(event.target)) results.hidden = true; });
                patientSelect.addEventListener('change', renderSelectedPatients);
                renderSelectedPatients();
                updatePreview();
            }
        }
    </script>
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
$perPage = 10;

$where = ['1 = 1'];
$params = [];
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
        TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS actor_name,
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
    $metadata = json_decode((string) ($log['metadata'] ?? ''), true);
    $metadataJson = is_array($metadata)
        ? json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : trim((string) ($log['metadata'] ?? ''));
    $auditRows[] = [
        'created' => date('M d, Y g:i A', strtotime($log['created_at'])),
        'actor' => '<p class="audit-primary-text">' . e($log['actor_name'] ?: ucfirst((string) $log['actor_type'])) . '</p><p class="audit-secondary-text">' . e($log['actor_id_number'] ?: audit_log_module_label((string) $log['actor_type'])) . '</p>',
        'activity' => '<p class="audit-primary-text">' . e(audit_log_action_label((string) $log['action'])) . '</p><p class="audit-secondary-text">' . e(audit_log_module_label((string) $log['module'])) . '</p>',
        'target' => audit_log_target_label($log),
        'outcome' => '<span class="badge ' . e($log['outcome'] === 'success' ? 'badge-completed' : 'badge-high') . '">' . e($log['outcome'] === 'success' ? 'Successful' : 'Failed') . '</span>',
        'details' => '<span class="audit-detail-summary">' . e(audit_log_metadata_summary($log['metadata'] ?? null)) . '</span>',
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
        'metadata' => $metadataJson !== '' ? $metadataJson : 'No additional metadata recorded.',
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
$actions = auth_db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
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
    '<a class="btn btn-primary text-decoration-none" data-no-ajax="true" href="print.php'
        . ($printQuery !== '' ? '?' . e($printQuery) : '')
        . '"><span class="material-symbols-outlined text-[18px]">print</span>Print Audit Log</a>'
);
?>
<div class="audit-page">
<nav class="clinic-card p-2 mb-6 grid grid-cols-1 md:grid-cols-2 gap-2" aria-label="Governance navigation">
    <a class="btn w-full justify-center btn-primary text-decoration-none" href="index.php?tab=audit">Audit Log</a>
    <a class="btn w-full justify-center btn-outline text-decoration-none" href="index.php?tab=email">Email Center</a>
</nav>
<section class="clinic-card overflow-hidden mb-6">
    <div class="p-6 border-b border-slate-100"><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Audit Filters</h2><p class="text-xs font-bold text-slate-500 mb-0">Filter sensitive system activity by module, actor, result, or date.</p></div>
    <form method="get" class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="lg:col-span-2"><label class="clinic-label" for="auditSearch">Search</label><input id="auditSearch" class="clinic-input" name="search" value="<?= e($search) ?>" placeholder="Search names, ID numbers, or affected records"></div>
        <div><label class="clinic-label" for="auditModule">Activity area</label><select id="auditModule" class="clinic-select" name="module"><option value="">All activity areas</option><?php foreach ($modules as $value): ?><option value="<?= e($value) ?>" <?= $module === $value ? 'selected' : '' ?>><?= e(audit_log_module_label((string) $value)) ?></option><?php endforeach; ?></select></div>
        <div><label class="clinic-label" for="auditAction">Activity</label><select id="auditAction" class="clinic-select" name="action"><option value="">All activities</option><?php foreach ($actions as $value): ?><option value="<?= e($value) ?>" <?= $action === $value ? 'selected' : '' ?>><?= e(audit_log_action_label((string) $value)) ?></option><?php endforeach; ?></select></div>
        <div><label class="clinic-label" for="auditOutcome">Result</label><select id="auditOutcome" class="clinic-select" name="outcome"><option value="">All results</option><option value="success" <?= $outcome === 'success' ? 'selected' : '' ?>>Successful</option><option value="unsuccessful" <?= in_array($outcome, ['unsuccessful', 'failure', 'failed'], true) ? 'selected' : '' ?>>Unsuccessful</option></select></div>
        <div><label class="clinic-label" for="auditFrom">Date from</label><input id="auditFrom" class="clinic-input" type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
        <div><label class="clinic-label" for="auditTo">Date to</label><input id="auditTo" class="clinic-input" type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
        <div class="flex items-end"><a class="btn btn-outline text-decoration-none w-full justify-center" href="index.php">Clear</a></div>
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
                    <pre class="m-0 mt-2 whitespace-pre-wrap break-words text-sm font-bold text-slate-600"><?= e($detail['metadata']) ?></pre>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($pages > 1): ?><div class="p-4 flex items-center justify-between border-t border-slate-100"><span class="text-xs font-bold text-slate-500">Page <?= $page ?> of <?= $pages ?></span><div class="flex gap-2"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline text-decoration-none" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?><?php if ($page < $pages): ?><a class="btn btn-sm btn-outline text-decoration-none" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?></div></div><?php endif; ?>
</section>
</div>
<script>
(() => {
    const form = document.querySelector('.audit-page form[method="get"]');
    if (!form) return;
    form.querySelectorAll('select, input[type="date"]').forEach((control) => {
        control.addEventListener('change', () => form.submit());
    });
    const search = form.querySelector('input[name="search"]');
    search?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            form.submit();
        }
    });
})();
</script>
<?php render_footer(); ?>
