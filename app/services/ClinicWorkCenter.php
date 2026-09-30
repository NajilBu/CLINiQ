<?php

declare(strict_types=1);

require_once __DIR__ . '/WorkflowAttention.php';
require_once __DIR__ . '/PatientEmail.php';

/**
 * Combine clinic workflow work and email-delivery work into one action inbox.
 * Source workflows remain responsible for completing workflow records.
 *
 * @return array<int, array<string, mixed>>
 */
function clinic_work_center_items(array $filters = [], int $limit = 1000): array
{
    $workflowFilters = [
        'search' => (string) ($filters['search'] ?? ''),
        'priority' => (string) ($filters['priority'] ?? ''),
        'type' => (string) ($filters['type'] ?? ''),
        'area' => (string) ($filters['area'] ?? ''),
        'status' => (string) ($filters['status'] ?? ''),
        'due_date' => (string) ($filters['due_date'] ?? ''),
        'email_relevant' => array_key_exists('email_relevant', $filters) ? (bool) $filters['email_relevant'] : null,
        'age' => (string) ($filters['age'] ?? ''),
    ];
    $items = [];
    $workflowEmailKeys = [];
    foreach (workflow_attention_items($workflowFilters, $limit) as $item) {
        if (!empty($item['email_relevant']) && (string) ($item['email_event_type'] ?? '') !== '' && (int) ($item['email_source_id'] ?? 0) > 0) {
            $emailCheck = auth_db()->prepare('SELECT status FROM email_queue WHERE patient_person_id = ? AND event_type = ? AND source_type = ? AND source_id = ? AND status <> \'cancelled\' ORDER BY id DESC LIMIT 1');
            $emailCheck->execute([
                (int) ($item['patient_person_id'] ?? 0),
                (string) $item['email_event_type'],
                (string) ($item['email_source_type'] ?? $item['source_type'] ?? ''),
                (int) $item['email_source_id'],
            ]);
            $emailStatus = (string) ($emailCheck->fetchColumn() ?: '');
            $item['email_status'] = $emailStatus !== '' ? $emailStatus : 'not_queued';
            if ($emailStatus === 'sent') {
                $item['title'] = 'Patient has not completed the required APE action';
                $item['explanation'] = 'An automatic email was sent, but the APE requirement is still overdue. Review the patient response and contact them again if needed.';
                $item['action_label'] = 'Review patient upload status';
                $item['contact_status'] = 'awaiting_patient';
            }
        }
        $item['kind'] = 'workflow';
        $existingEmailStatus = (string) ($item['email_status'] ?? 'not_queued');
        if (!empty($item['email_relevant']) && $existingEmailStatus !== 'not_queued') {
            $item['action_type'] = 'view_email';
            $item['action_label'] = match ($existingEmailStatus) {
                'pending' => 'View queued email',
                'processing' => 'View processing email',
                'failed', 'blocked' => 'Review email issue',
                'sent' => 'View email history',
                default => 'View email details',
            };
            $item['category'] = 'Email delivery';
        } else {
            $item['action_type'] = !empty($item['email_relevant']) ? 'compose_email' : ((string) ($item['action_type'] ?? 'open_source'));
        }
        $item['category'] = 'Clinic work';
        if ($item['action_type'] === 'view_email' && $existingEmailStatus !== 'not_queued') {
            $item['category'] = 'Email delivery';
        } elseif ($item['action_type'] === 'compose_email') {
            $item['action_label'] = 'Send email';
            $item['email_subject'] = match ((string) ($item['email_event_type'] ?? '')) {
                'ape_exam_missed' => 'Missed APE examination — action needed',
                'ape_documents_overdue' => 'Your APE documents are still overdue',
                'ape_follow_up_overdue' => 'Your APE follow-up is overdue',
                'ape_follow_up_required' => 'APE follow-up required',
                'ape_document_correction_required' => 'APE document correction required',
                'ape_hard_copy_correction_required' => 'APE hard-copy correction required',
                default => 'Clinic action required',
            };
            $item['email_message'] = match ((string) ($item['email_event_type'] ?? '')) {
                'ape_exam_missed' => 'Our records show that you were unable to complete your scheduled APE examination. Please contact the clinic to arrange the next step.',
                'ape_documents_overdue' => 'Our records show that your required APE documents are still incomplete. Please upload the outstanding documents or contact the clinic if you need assistance.',
                'ape_follow_up_overdue' => 'Your APE follow-up is overdue. Please complete the required action or contact the clinic as soon as possible.',
                'ape_follow_up_required' => 'The clinic requires a follow-up action for your APE. Please open your patient portal or contact the clinic for instructions.',
                'ape_document_correction_required' => 'One or more APE documents need correction. Please open your patient portal and submit the requested documents.',
                'ape_hard_copy_correction_required' => 'One or more APE hard-copy requirements need correction. Please open your patient portal for the clinic instructions.',
                default => (string) ($item['explanation'] ?? 'Please contact the clinic regarding your outstanding action.'),
            };
        }
        $items[] = $item;
        if (!empty($item['email_relevant']) && (string) ($item['email_event_type'] ?? '') !== '') {
            $workflowEmailKeys[implode(':', [(int) ($item['patient_person_id'] ?? 0), (string) ($item['email_source_type'] ?? $item['source_type'] ?? ''), (int) ($item['email_source_id'] ?? $item['source_id'] ?? 0), (string) $item['email_event_type']])] = true;
        }
    }

    foreach (patient_email_attention_items(100) as $email) {
        $status = (string) ($email['status'] ?? 'pending');
        $kind = (string) ($email['kind'] ?? 'overdue');
        $action = (string) ($email['action'] ?? 'process');
        $sourceType = (string) ($email['source_type'] ?? '');
        $sourceId = (int) ($email['source_id'] ?? 0);
        $patientId = (int) ($email['patient_person_id'] ?? 0);
        $eventType = (string) ($email['event_type'] ?? '');
        if (isset($workflowEmailKeys[implode(':', [$patientId, $sourceType, $sourceId, $eventType])])) continue;
        $actionType = match ($action) {
            'retry' => 'retry_email',
            'open_patient' => 'open_patient',
            'process' => 'view_email',
            default => 'view_email',
        };
        $sourceUrl = $patientId > 0 ? '../patients/view.php?id=' . $patientId : '';
        if ($sourceType === 'ape' && $sourceId > 0) $sourceUrl = '../ape/view.php?id=' . $sourceId;
        if ($sourceType === 'appointment' && $sourceId > 0) $sourceUrl = '../appointments/index.php?status=Scheduled';
        $items[] = [
            'kind' => 'email',
            'category' => 'Email delivery',
            'type' => 'email_' . $kind,
            'label' => 'Patient email',
            'title' => (string) ($email['title'] ?? 'Email needs attention'),
            'explanation' => (string) ($email['message'] ?? 'This email needs review.'),
            'patient_name' => (string) ($email['patient_name'] ?? 'Patient'),
            'patient_email' => (string) ($email['recipient_email'] ?? ''),
            'priority' => $kind === 'overdue' ? 'overdue' : 'urgent',
            'status' => $status,
            'area' => 'Email delivery',
            'created_at' => (string) ($email['created_at'] ?? date('Y-m-d H:i:s')),
            'due_at' => (string) ($email['available_at'] ?? ''),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'patient_person_id' => $patientId,
            'email_id' => (int) ($email['email_id'] ?? 0),
            'action_type' => $actionType,
            'action_label' => $actionType === 'retry_email' ? 'Retry email' : ($actionType === 'open_patient' ? 'Open patient' : 'View email details'),
            'source_url' => $sourceUrl,
        ];
    }

    $search = strtolower(trim((string) ($filters['search'] ?? '')));
    $kindFilter = trim((string) ($filters['kind'] ?? ''));
    $typeFilter = trim((string) ($filters['type'] ?? ''));
    $priorityFilter = trim((string) ($filters['priority'] ?? ''));
    $areaFilter = trim((string) ($filters['area'] ?? ''));
    $statusFilter = strtolower(trim((string) ($filters['status'] ?? '')));
    $dueDate = trim((string) ($filters['due_date'] ?? ''));
    $items = array_values(array_filter($items, static function (array $item) use ($search, $kindFilter, $typeFilter, $priorityFilter, $areaFilter, $statusFilter, $dueDate): bool {
        if ($search !== '' && !str_contains(strtolower(implode(' ', [(string) $item['patient_name'], (string) $item['title'], (string) $item['explanation'], (string) $item['area'], (string) ($item['patient_email'] ?? '')])), $search)) return false;
        if ($kindFilter !== '' && $item['kind'] !== $kindFilter) return false;
        if ($typeFilter !== '' && $item['type'] !== $typeFilter) return false;
        if ($priorityFilter !== '' && $item['priority'] !== $priorityFilter) return false;
        if ($areaFilter !== '' && $item['area'] !== $areaFilter) return false;
        if ($statusFilter !== '' && strtolower((string) $item['status']) !== $statusFilter) return false;
        if ($dueDate !== '' && substr((string) ($item['due_at'] ?? ''), 0, 10) !== $dueDate) return false;
        return true;
    }));
    usort($items, static function (array $left, array $right): int {
        $rank = ['urgent' => 1, 'overdue' => 2, 'clinic_action' => 3, 'waiting_on_patient' => 4, 'scheduled' => 5];
        return (($rank[$left['priority']] ?? 9) <=> ($rank[$right['priority']] ?? 9))
            ?: strcmp((string) ($left['due_at'] ?: $left['created_at']), (string) ($right['due_at'] ?: $right['created_at']));
    });
    return array_slice($items, 0, max(1, min(1000, $limit)));
}

function clinic_work_center_summary(array $items): array
{
    $summary = ['total' => count($items), 'urgent' => 0, 'overdue' => 0, 'waiting_on_clinic' => 0, 'waiting_on_patient' => 0, 'email_issues' => 0];
    foreach ($items as $item) {
        $priority = (string) ($item['priority'] ?? '');
        if ($priority === 'clinic_action') $summary['waiting_on_clinic']++;
        elseif ($priority === 'waiting_on_patient') $summary['waiting_on_patient']++;
        elseif (isset($summary[$priority])) $summary[$priority]++;
        if (($item['kind'] ?? '') === 'email') $summary['email_issues']++;
    }
    return $summary;
}

function clinic_work_center_export(array $items): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cliniq-work-email-center.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Category', 'Type', 'Priority', 'Status', 'Patient', 'Email', 'Title', 'Explanation', 'Created', 'Due', 'Source type', 'Source ID', 'Email ID']);
    foreach ($items as $item) fputcsv($out, [$item['kind'], $item['type'], $item['priority'], $item['status'], $item['patient_name'], $item['patient_email'] ?? '', $item['title'], $item['explanation'], $item['created_at'], $item['due_at'] ?? '', $item['source_type'], $item['source_id'], $item['email_id'] ?? '']);
    fclose($out);
}

/** Return delivery history for one APE action; sent means SMTP accepted. */
function clinic_work_center_reminder_history(int $patientId, string $sourceType, int $sourceId, string $eventType): array
{
    $empty = ['sent_count' => 0, 'automatic_sent_count' => 0, 'manual_follow_up_count' => 0, 'pending_count' => 0, 'last_sent_at' => null, 'latest_status' => null, 'latest_id' => 0];
    if ($patientId < 1 || $sourceId < 1 || $eventType === '') return $empty;
    $stmt = auth_db()->prepare("SELECT id, origin, status, created_at, sent_at
        FROM email_queue
        WHERE patient_person_id = ? AND source_type = ? AND source_id = ?
          AND event_type = ? AND status <> 'cancelled'
        ORDER BY id DESC");
    $stmt->execute([$patientId, $sourceType, $sourceId, $eventType]);
    $rows = $stmt->fetchAll();
    $sent = array_values(array_filter($rows, static fn(array $row): bool => (string) $row['status'] === 'sent'));
    $manualSent = array_values(array_filter($sent, static fn(array $row): bool => (string) $row['origin'] === 'manual'));
    $automaticSent = array_values(array_filter($sent, static fn(array $row): bool => (string) $row['origin'] === 'automatic'));
    $lastSent = $sent[0] ?? null;
    return [
        'sent_count' => count($sent),
        'automatic_sent_count' => count($automaticSent),
        'manual_follow_up_count' => count($manualSent),
        'pending_count' => count(array_filter($rows, static fn(array $row): bool => in_array((string) $row['status'], ['pending', 'processing'], true))),
        'last_sent_at' => $lastSent ? (string) ($lastSent['sent_at'] ?: $lastSent['created_at']) : null,
        'latest_status' => isset($rows[0]) ? (string) $rows[0]['status'] : null,
        'latest_id' => isset($rows[0]) ? (int) $rows[0]['id'] : 0,
    ];
}

/** Pure reminder state calculation; keeps the safety policy testable without a database. */
function clinic_work_center_reminder_state(array $history, bool $recipientAvailable, ?int $now = null): array
{
    $now ??= time();
    $state = 'ready';
    $reason = 'A staff-reviewed follow-up can be sent now.';
    $nextEligibleAt = null;
    if (!$recipientAvailable) {
        $state = 'recipient_unavailable';
        $reason = 'The patient does not have an active account with a valid email address.';
    } elseif ((int) ($history['pending_count'] ?? 0) > 0) {
        $state = 'delivery_pending';
        $reason = 'An earlier email is still waiting to be processed.';
    } elseif (in_array((string) ($history['latest_status'] ?? ''), ['failed', 'blocked'], true)) {
        $state = 'delivery_attention';
        $reason = 'The latest email needs delivery attention before another reminder is sent.';
    } elseif ((int) ($history['sent_count'] ?? 0) < 1) {
        $state = 'initial_delivery_required';
        $reason = 'The initial automatic email has not been sent yet.';
    } elseif ((int) ($history['manual_follow_up_count'] ?? 0) >= 2) {
        $state = 'limit_reached';
        $reason = 'The two staff follow-ups for this APE action have already been used.';
    } elseif (!empty($history['last_sent_at'])) {
        try {
            $nextEligibleAt = (new DateTimeImmutable((string) $history['last_sent_at']))->modify('+72 hours')->format('Y-m-d H:i:s');
            if (strtotime($nextEligibleAt) > $now) {
                $state = 'cooldown';
                $reason = 'A staff follow-up becomes available 72 hours after the latest email.';
            }
        } catch (Throwable $e) {
            $state = 'delivery_attention';
            $reason = 'The latest email timestamp cannot be verified.';
        }
    }
    return ['state' => $state, 'reason' => $reason, 'next_eligible_at' => $nextEligibleAt, 'can_send' => $state === 'ready'];
}

/** Apply the one shared, server-authoritative APE follow-up policy. */
function clinic_work_center_apply_reminder_policy(array $item): array
{
    $patientId = (int) ($item['patient_person_id'] ?? 0);
    $sourceType = (string) ($item['email_source_type'] ?? $item['source_type'] ?? '');
    $sourceId = (int) ($item['email_source_id'] ?? $item['source_id'] ?? 0);
    $eventType = (string) ($item['email_event_type'] ?? '');
    $history = clinic_work_center_reminder_history($patientId, $sourceType, $sourceId, $eventType);
    $recipientStmt = auth_db()->prepare("SELECT a.email FROM accounts a INNER JOIN patients pt ON pt.person_id = a.person_id WHERE a.person_id = ? AND a.account_status = 'active' LIMIT 1");
    $recipientStmt->execute([$patientId]);
    $recipientEmail = (string) ($recipientStmt->fetchColumn() ?: '');
    $recipientAvailable = filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) !== false;
    $policy = clinic_work_center_reminder_state($history, $recipientAvailable);
    $item['patient_email'] = $recipientEmail;
    $item['sent_count'] = $history['sent_count'];
    $item['automatic_sent_count'] = $history['automatic_sent_count'];
    $item['manual_follow_up_count'] = $history['manual_follow_up_count'];
    $item['reminder_count'] = $history['sent_count'];
    $item['last_reminded_at'] = $history['last_sent_at'];
    $item['last_reminder_status'] = $history['latest_status'];
    $item['reminder_state'] = $policy['state'];
    $item['reminder_reason'] = $policy['reason'];
    $item['next_eligible_at'] = $policy['next_eligible_at'];
    $item['follow_up_number'] = $history['manual_follow_up_count'] + 1;
    $item['can_send_reminder'] = $policy['can_send'];
    $item['recipient_available'] = $recipientAvailable;
    $item['reminder_key_base'] = implode(':', [$patientId, $sourceType, $sourceId, $eventType]);
    return $item;
}

function clinic_work_center_reminder_deadline(array $item): ?string
{
    $itemDue = trim((string) ($item['due_at'] ?? ''));
    if ($itemDue !== '') return substr($itemDue, 0, 10);
    $db = auth_db();
    $sourceType = (string) ($item['source_type'] ?? $item['email_source_type'] ?? '');
    $sourceId = (int) ($item['source_id'] ?? $item['email_source_id'] ?? 0);
    $eventType = (string) ($item['email_event_type'] ?? '');
    try {
        if (in_array($eventType, ['ape_follow_up_required', 'ape_follow_up_overdue'], true) && $sourceId > 0) {
            $stmt = $db->prepare('SELECT follow_up_due_date FROM ape_records WHERE ape_id = ? LIMIT 1');
            $stmt->execute([$sourceId]);
            return ($value = $stmt->fetchColumn()) ? (string) $value : null;
        }
        if ($sourceType === 'ape_requirement' && $sourceId > 0) {
            $stmt = $db->prepare('SELECT upload_due_date FROM ape_requirements WHERE requirement_id = ? LIMIT 1');
            $stmt->execute([$sourceId]);
            return ($value = $stmt->fetchColumn()) ? (string) $value : null;
        }
        if ($sourceType === 'ape_document' && $sourceId > 0) {
            $stmt = $db->prepare('SELECT r.upload_due_date
                FROM ape_documents d INNER JOIN ape_requirements r
                  ON r.ape_id = d.ape_id AND r.requirement_name = d.document_type
                WHERE d.document_id = ? LIMIT 1');
            $stmt->execute([$sourceId]);
            return ($value = $stmt->fetchColumn()) ? (string) $value : null;
        }
    } catch (Throwable $e) {
        // A missing optional relation should not make Email Center unavailable.
    }
    $due = trim((string) ($item['due_at'] ?? ''));
    return $due !== '' ? substr($due, 0, 10) : null;
}

/**
 * Build reminder candidates without changing data. This is intentionally
 * separate from clinic_work_center_items(): sent automatic email records must
 * not hide a patient-owned APE action that staff may need to remind again.
 */
function clinic_work_center_reminder_candidates(?int $batchId = null): array
{
    $items = [];
    $candidateKeys = [];
    $batchApeIds = null;
    if ($batchId !== null && $batchId > 0) {
        $batchRecords = auth_db()->prepare('SELECT ape_id FROM ape_records WHERE schedule_batch_id = ?');
        $batchRecords->execute([$batchId]);
        $batchApeIds = array_fill_keys(array_map('intval', $batchRecords->fetchAll(PDO::FETCH_COLUMN)), true);
    }
    foreach (workflow_attention_items(['email_relevant' => true], 1000) as $item) {
        if (strtolower((string) ($item['area'] ?? '')) !== 'ape') continue;
        // A missed examination has two valid owners: the clinic must handle
        // the missed appointment, and the patient may need a reminder. Keep
        // ordinary clinic-only work out of the reminder flow, but allow this
        // explicit patient-facing event through without changing its clinic
        // ownership or its Examine Patient action.
        $isMissedExamination = (string) ($item['email_event_type'] ?? '') === 'ape_exam_missed';
        if ((string) ($item['owner'] ?? 'patient') !== 'patient' && !$isMissedExamination) continue;
        $patientId = (int) ($item['patient_person_id'] ?? 0);
        $eventType = (string) ($item['email_event_type'] ?? '');
        $sourceType = (string) ($item['email_source_type'] ?? $item['source_type'] ?? '');
        $sourceId = (int) ($item['email_source_id'] ?? $item['source_id'] ?? 0);
        if ($patientId < 1 || $eventType === '' || $sourceId < 1) continue;
        if ($batchApeIds !== null && !isset($batchApeIds[$sourceId])) continue;
        $candidateKey = implode(':', [$patientId, $sourceType, $sourceId, $eventType]);
        if (isset($candidateKeys[$candidateKey])) continue;
        $item['current_deadline'] = clinic_work_center_reminder_deadline($item);
        $item = clinic_work_center_apply_reminder_policy($item);
        $items[] = $item;
        $candidateKeys[$item['reminder_key_base']] = true;
    }
    // Keep unresolved manual reminder cycles visible even after the broad
    // workflow query stops returning their original overdue action.
    try {
        $manualRows = auth_db()->query("SELECT DISTINCT q.patient_person_id, q.source_id, q.event_type,
                q.created_at, p.first_name, p.middle_name, p.last_name
            FROM email_queue q INNER JOIN people p ON p.id = q.patient_person_id
            WHERE q.origin = 'manual' AND q.source_type = 'ape' AND q.status <> 'cancelled'
              AND q.event_type IN ('ape_documents_overdue', 'ape_document_correction_required',
                'ape_hard_copy_correction_required', 'ape_follow_up_required', 'ape_follow_up_overdue')
            ORDER BY q.id DESC")->fetchAll();
        foreach ($manualRows as $row) {
            $patientId = (int) $row['patient_person_id'];
            $sourceId = (int) $row['source_id'];
            $eventType = (string) $row['event_type'];
            $key = implode(':', [$patientId, 'ape', $sourceId, $eventType]);
            if ($patientId < 1 || $sourceId < 1 || isset($candidateKeys[$key])) continue;
            $db = auth_db();
            $recordStmt = $db->prepare('SELECT ape_id, schedule_batch_id, follow_up_due_date, workflow_status, clearance_status FROM ape_records WHERE ape_id = ? LIMIT 1');
            $recordStmt->execute([$sourceId]);
            $record = $recordStmt->fetch();
            if (!$record || in_array((string) ($record['workflow_status'] ?? ''), ['Cleared', 'Completed', 'Inactive'], true) || (string) ($record['clearance_status'] ?? '') === 'Cleared') continue;
            if ($batchId !== null && $batchId > 0 && (int) ($record['schedule_batch_id'] ?? 0) !== $batchId) continue;
            $deadline = null;
            if (in_array($eventType, ['ape_follow_up_required', 'ape_follow_up_overdue'], true)) {
                $deadline = $record['follow_up_due_date'] ? (string) $record['follow_up_due_date'] : null;
            } else {
                $req = $db->prepare("SELECT MIN(upload_due_date) FROM ape_requirements WHERE ape_id = ? AND status <> 'Verified'");
                $req->execute([$sourceId]);
                $deadline = ($value = $req->fetchColumn()) ? (string) $value : null;
            }
            if ($deadline === null) continue;
            $item = [
                'kind' => 'workflow', 'type' => 'ape_reminder_cycle', 'label' => 'APE reminder cycle',
                'title' => $eventType === 'ape_documents_overdue' ? 'APE documents still incomplete' : 'APE action still incomplete',
                'explanation' => 'The student was reminded, but the patient-owned APE action is still unresolved.',
                'patient_name' => trim((string) $row['first_name'] . ' ' . (string) ($row['middle_name'] ?? '') . ' ' . (string) $row['last_name']) ?: 'Patient',
                'patient_person_id' => $patientId, 'patient_email' => '', 'priority' => 'overdue',
                'status' => 'APE action still incomplete', 'area' => 'APE',
                'created_at' => (string) $row['created_at'], 'due_at' => $deadline,
                'source_type' => 'ape', 'source_id' => $sourceId, 'source_url' => '../ape/view.php?id=' . $sourceId,
                'email_relevant' => true, 'email_event_type' => $eventType, 'email_source_type' => 'ape', 'email_source_id' => $sourceId,
                'current_deadline' => $deadline,
            ];
            $item = clinic_work_center_apply_reminder_policy($item);
            $items[] = $item;
            $candidateKeys[$key] = true;
        }
    } catch (Throwable $e) {
        // Existing records remain available even if an optional history query fails.
    }
    return $items;
}

function clinic_work_center_send_reminders(int $actorPersonId, ?array $reviewedItems = null, ?int $batchId = null): array
{
    $result = ['eligible' => 0, 'sent' => 0, 'queued' => 0, 'deferred' => 0, 'skipped' => 0, 'blocked' => 0, 'failed' => 0];
    $reviewedByKey = [];
    if ($reviewedItems !== null) {
        foreach ($reviewedItems as $reviewed) {
            if (!is_array($reviewed)) continue;
            $key = implode(':', [
                (int) ($reviewed['patient_person_id'] ?? 0),
                (string) ($reviewed['source_type'] ?? 'ape'),
                (int) ($reviewed['source_id'] ?? 0),
                (string) ($reviewed['event_type'] ?? ''),
                (int) ($reviewed['follow_up_number'] ?? 0),
            ]);
            if ((int) ($reviewed['patient_person_id'] ?? 0) > 0 && (int) ($reviewed['source_id'] ?? 0) > 0 && (string) ($reviewed['event_type'] ?? '') !== '') {
                $reviewedByKey[$key] = [
                    'subject' => trim((string) ($reviewed['subject'] ?? '')),
                    'message' => trim((string) ($reviewed['message'] ?? '')),
                ];
            }
        }
    }
    audit_log_event('email', 'clinic_reminder_batch_started', $actorPersonId ?: null, 'staff', 'email', null, ['batch_id' => $batchId]);
    foreach (clinic_work_center_reminder_candidates($batchId) as $item) {
        if (empty($item['can_send_reminder'])) {
            $result['skipped']++;
            continue;
        }
        $result['eligible']++;
        $sourceType = (string) ($item['source_type'] ?? $item['email_source_type'] ?? 'ape');
        $sourceId = (int) ($item['source_id'] ?? $item['email_source_id'] ?? 0);
        $emailSourceType = (string) ($item['email_source_type'] ?? $sourceType);
        $emailSourceId = (int) ($item['email_source_id'] ?? $sourceId);
        $eventType = (string) ($item['email_event_type'] ?? '');
        $followUpNumber = (int) ($item['follow_up_number'] ?? 0);
        $reviewKey = implode(':', [(int) $item['patient_person_id'], $sourceType, $sourceId, $eventType, $followUpNumber]);
        if ($reviewedItems !== null && !isset($reviewedByKey[$reviewKey])) {
            continue;
        }
        if ($reviewedItems !== null) {
            $submitted = $reviewedByKey[$reviewKey];
            if ($submitted['subject'] === '' || mb_strlen($submitted['subject']) > 180 || $submitted['message'] === '' || mb_strlen($submitted['message']) > 10000) {
                $result['failed']++;
                audit_log_event('email', 'clinic_reminder_review_invalid', $actorPersonId ?: null, 'staff', 'email', null, ['patient_person_id' => (int) $item['patient_person_id'], 'source_id' => $sourceId, 'event_type' => $eventType], 'failure');
                continue;
            }
        }
        $dedupeKey = implode(':', ['clinic_reminder', (int) $item['patient_person_id'], $emailSourceType, $emailSourceId, $eventType, 'follow_up', $followUpNumber]);
        $existing = auth_db()->prepare("SELECT id, status FROM email_queue WHERE dedupe_key = ? AND status <> 'cancelled' LIMIT 1");
        $existing->execute([$dedupeKey]);
        if ($existing->fetch()) {
            $result['skipped']++;
            audit_log_event('email', 'clinic_reminder_skipped_duplicate', $actorPersonId ?: null, 'staff', 'email', null, ['dedupe_key' => $dedupeKey]);
            continue;
        }
        $catalog = patient_email_event_catalog()[$eventType] ?? [];
        $portalUrl = 'https://plpuhs.dpdns.org';
        $reminderContent = match ($eventType) {
            'ape_exam_missed' => [
                'subject' => 'Missed APE examination — action needed',
                'message' => 'Our records show that you were unable to complete your scheduled APE examination. Please contact the clinic to arrange the next step. Student portal: ' . $portalUrl,
            ],
            'ape_documents_overdue' => [
                'subject' => 'Your APE documents are still overdue',
                'message' => 'Our records show that your required APE documents are still incomplete. Please upload the outstanding documents or contact the clinic if you need assistance. Student portal: ' . $portalUrl,
            ],
            'ape_follow_up_overdue' => [
                'subject' => 'Your APE follow-up is overdue',
                'message' => 'Your APE follow-up is overdue. Please complete the required action or contact the clinic as soon as possible. Student portal: ' . $portalUrl,
            ],
            'ape_follow_up_required' => [
                'subject' => 'APE follow-up required',
                'message' => 'The clinic requires a follow-up action for your APE. Please open your patient portal or contact the clinic for instructions: ' . $portalUrl,
            ],
            'ape_document_correction_required' => [
                'subject' => 'APE document correction required',
                'message' => 'One or more APE documents need correction. Please open your patient portal and submit the requested documents: ' . $portalUrl,
            ],
            'ape_hard_copy_correction_required' => [
                'subject' => 'APE hard-copy correction required',
                'message' => 'One or more APE hard-copy requirements need correction. Please open your patient portal for the clinic instructions: ' . $portalUrl,
            ],
            default => [
                'subject' => 'APE reminder — action needed',
                'message' => 'Please log in to your student portal and complete the outstanding APE action, or contact the clinic if you need assistance: ' . $portalUrl,
            ],
        };
        $queueId = patient_email_dispatch_event([
            'patient_person_id' => (int) $item['patient_person_id'],
            'event_type' => $eventType,
            'automation_key' => (string) ($catalog['automation_key'] ?? ''),
            'source_type' => $emailSourceType,
            'source_id' => $emailSourceId,
            'origin' => 'manual',
            'created_by_person_id' => $actorPersonId,
            'dedupe_key' => $dedupeKey,
            'subject' => $reviewedItems !== null ? $reviewedByKey[$reviewKey]['subject'] : (string) ($item['email_subject'] ?? $reminderContent['subject']),
            'message' => $reviewedItems !== null ? $reviewedByKey[$reviewKey]['message'] : (string) ($item['email_message'] ?? $reminderContent['message']),
        ]);
        if ($queueId < 1) {
            $result['blocked']++;
            continue;
        }
        $statusStmt = auth_db()->prepare('SELECT status FROM email_queue WHERE id = ?');
        $statusStmt->execute([$queueId]);
        $status = (string) ($statusStmt->fetchColumn() ?: 'blocked');
        if ($status === 'blocked') { $result['blocked']++; continue; }
        if ($status === 'failed') { $result['failed']++; continue; }
        if ($status === 'pending' && !patient_email_queue_is_paused()) {
            $processResult = patient_email_process_queue('patient_email', 1, null, $queueId);
            if (!empty($processResult['deferred']) || !empty($processResult['quota_limited'])) $result['deferred']++;
            $statusStmt->execute([$queueId]);
            $status = (string) ($statusStmt->fetchColumn() ?: $status);
        }
        if ($status === 'blocked') { $result['blocked']++; continue; }
        if ($status === 'failed') { $result['failed']++; continue; }
        if ($status === 'sent') $result['sent']++; else $result['queued']++;
        audit_log_event('email', $status === 'sent' ? 'clinic_reminder_sent' : 'clinic_reminder_queued', $actorPersonId ?: null, 'staff', 'email', $queueId, [
            'patient_person_id' => (int) $item['patient_person_id'],
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'event_type' => $eventType,
            'follow_up_number' => $followUpNumber,
        ], 'success');
    }
    audit_log_event('email', 'clinic_reminder_batch_completed', $actorPersonId ?: null, 'staff', 'email', null, $result, 'success');
    return $result;
}
