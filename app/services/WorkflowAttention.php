<?php

declare(strict_types=1);

require_once __DIR__ . '/ApeWorkflow.php';

function workflow_attention_age_bucket(array $item, ?int $now = null): string
{
    $now ??= time();
    $today = date('Y-m-d', $now);
    $tomorrow = date('Y-m-d', strtotime('+1 day', $now));
    $sevenDaysFromNow = date('Y-m-d', strtotime('+7 days', $now));
    $sevenDaysAgo = strtotime('-7 days', $now);
    $itemDueDate = substr(trim((string) ($item['due_at'] ?? '')), 0, 10);
    $createdAt = strtotime((string) ($item['created_at'] ?? '')) ?: 0;
    $isOverdue = (string) ($item['priority'] ?? '') === 'overdue'
        || ($itemDueDate !== '' && $itemDueDate < $today);

    if ($isOverdue) return 'overdue';
    if ($itemDueDate === $today) return 'due_today';
    if ($itemDueDate >= $tomorrow && $itemDueDate <= $sevenDaysFromNow) return 'due_soon';
    if ($createdAt > 0 && $createdAt < $sevenDaysAgo) return 'older_than_7_days';
    if ($itemDueDate === '') return 'no_due_date';
    return '';
}

function workflow_attention_age_matches(array $item, string $age, ?int $now = null): bool
{
    return $age === '' || workflow_attention_age_bucket($item, $now) === $age;
}

/**
 * Build a normalized, read-only work queue for clinic staff.
 * Email delivery remains separate; this service describes workflow work.
 *
 * @return array<int, array<string, mixed>>
 */
function workflow_attention_items(array $filters = [], int $limit = 500): array
{
    $db = auth_db();
    $items = [];
    $now = time();
    $add = static function (array &$items, array $item): void {
        $item['priority'] = in_array(($item['priority'] ?? 'clinic_action'), ['urgent', 'overdue', 'clinic_action', 'waiting_on_patient', 'scheduled'], true)
            ? $item['priority'] : 'clinic_action';
        $item['status'] = trim((string) ($item['status'] ?? 'pending')) ?: 'pending';
        $item['source_type'] = trim((string) ($item['source_type'] ?? ''));
        $item['source_id'] = (int) ($item['source_id'] ?? 0);
        $item['patient_person_id'] = (int) ($item['patient_person_id'] ?? 0);
        $item['created_at'] = (string) ($item['created_at'] ?? date('Y-m-d H:i:s'));
        $item['due_at'] = (string) ($item['due_at'] ?? '');
        $item['area'] = trim((string) ($item['area'] ?? 'General')) ?: 'General';
        $item['email_relevant'] = !empty($item['email_relevant']);
        $item['email_event_type'] = trim((string) ($item['email_event_type'] ?? ''));
        $item['email_source_type'] = trim((string) ($item['email_source_type'] ?? $item['source_type']));
        $item['email_source_id'] = (int) ($item['email_source_id'] ?? $item['source_id']);
        $item['item_key'] = (string) ($item['deduplication_key'] ?? ((string) ($item['source_type'] ?: 'workflow') . ':' . $item['source_id'] . ':' . ($item['type'] ?? 'item')));
        $items[] = $item;
    };

    foreach (ape_fetch_records() as $record) {
        $priority = ape_priority_badge($record);
        $missedExamination = $priority['label'] === 'Missed' && ape_record_queue($record) === 'examination';
        foreach (ape_normalized_action_items($record) as $actionItem) {
            $item = $actionItem;
            $item['patient_name'] = trim((string) ($record['first_name'] ?? '') . ' ' . (string) ($record['last_name'] ?? '')) ?: 'Patient';
            $item['patient_email'] = '';
            $item['priority'] = $missedExamination ? 'urgent' : ($item['priority'] ?? 'clinic_action');
            $item['status'] = $missedExamination ? 'Missed' : ($item['status'] ?? ($priority['label'] ?? 'Pending'));
            $item['created_at'] = (string) ($record['created_at'] ?? date('Y-m-d H:i:s'));
            $item['action_label'] = $missedExamination ? 'Review and contact patient' : ($item['action_label'] ?? 'Open APE record');
            $item['email_relevant'] = $missedExamination || !empty($item['email_relevant']);
            $item['email_event_type'] = $missedExamination ? 'ape_exam_missed' : (string) ($item['email_event_type'] ?? '');
            $item['email_source_type'] = $missedExamination ? 'ape' : ($item['email_source_type'] ?? $item['source_type'] ?? 'ape');
            $item['email_source_id'] = $missedExamination ? (int) ($record['id'] ?? 0) : ($item['email_source_id'] ?? $item['source_id'] ?? 0);
            $add($items, $item);
        }
    }

    try {
        $requirements = $db->query("SELECT r.requirement_id, r.ape_id, r.requirement_name, r.upload_due_date,
                ar.patient_id, ar.exam_date, p.first_name, p.last_name
            FROM ape_requirements r
            INNER JOIN ape_records ar ON ar.ape_id = r.ape_id
            INNER JOIN people p ON p.id = ar.patient_id
            WHERE r.status <> 'Verified'
              AND ar.exam_date IS NOT NULL
              AND COALESCE(r.upload_due_date, DATE_ADD(ar.exam_date, INTERVAL 7 DAY)) < CURDATE()
            ORDER BY COALESCE(r.upload_due_date, DATE_ADD(ar.exam_date, INTERVAL 7 DAY)), r.requirement_id")->fetchAll();
        foreach ($requirements as $row) {
            $add($items, [
                'type' => 'ape_overdue_requirement',
                'label' => 'APE overdue document',
                'title' => 'Review and contact patient',
                'explanation' => 'The required APE document is overdue and needs clinic follow-up.',
                'patient_name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']) ?: 'Patient',
                'priority' => 'overdue',
                'status' => 'Overdue',
                'area' => 'APE',
                'created_at' => date('Y-m-d H:i:s'),
                'due_at' => (string) ($row['upload_due_date'] ?: $row['exam_date']),
                'source_type' => 'ape_requirement',
                'source_id' => (int) $row['requirement_id'],
                'patient_person_id' => (int) $row['patient_id'],
                'action_label' => 'Review and contact patient',
                'source_url' => '../ape/view.php?id=' . (int) $row['ape_id'],
                'email_relevant' => true,
                'email_event_type' => 'ape_documents_overdue',
                'email_source_type' => 'ape',
                'email_source_id' => (int) $row['ape_id'],
            ]);
        }
    } catch (Throwable $e) {
        // Optional workflow tables must not prevent the work queue from loading.
    }

    $appointmentRows = $db->query("SELECT a.appointment_id, a.patient_id, a.appointment_datetime, a.status, a.purpose,
            a.created_at, TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) patient_name
        FROM appointments a INNER JOIN people p ON p.id = a.patient_id
        WHERE a.status IN ('Pending', 'For Confirmation', 'Scheduled')
        ORDER BY a.appointment_datetime, a.appointment_id")->fetchAll();
    foreach ($appointmentRows as $row) {
        $isPast = strtotime((string) $row['appointment_datetime']) < $now;
        $status = (string) $row['status'];
        $add($items, [
            'type' => 'appointment_' . strtolower(str_replace(' ', '_', $status)),
            'label' => 'Appointment',
            'title' => $status === 'Scheduled' ? ($isPast ? 'Appointment needs review' : 'Upcoming appointment') : 'Appointment request needs review',
            'explanation' => $status === 'Scheduled' ? 'This appointment is scheduled and remains visible for clinic coordination.' : 'This appointment is waiting for clinic confirmation.',
            'patient_name' => (string) ($row['patient_name'] ?: 'Patient'),
            'priority' => $isPast ? 'overdue' : ($status === 'Scheduled' ? 'scheduled' : 'clinic_action'),
            'status' => $status,
            'area' => 'Appointments',
            'created_at' => (string) $row['created_at'],
            'due_at' => (string) $row['appointment_datetime'],
            'source_type' => 'appointment',
            'source_id' => (int) $row['appointment_id'],
            'patient_person_id' => (int) $row['patient_id'],
            'action_label' => 'Review appointment',
            'source_url' => '../appointments/index.php?status=' . rawurlencode($status),
            'email_relevant' => $status === 'For Confirmation',
            'email_event_type' => $status === 'For Confirmation' ? 'appointment_confirmation_required' : '',
        ]);
    }

    foreach ($db->query("SELECT n.id, n.patient_id, n.concern, n.incident_type, n.risk_level, n.status, n.created_at,
            TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) patient_name
        FROM nurse_alerts n LEFT JOIN people p ON p.id = n.patient_id
        WHERE n.status NOT IN ('Resolved', 'Cancelled') ORDER BY n.created_at")->fetchAll() as $row) {
        $add($items, [
            'type' => 'emergency_alert',
            'label' => 'Emergency alert',
            'title' => 'Emergency alert requires response',
            'explanation' => trim((string) $row['concern'] . ' ' . (string) $row['incident_type']),
            'patient_name' => (string) ($row['patient_name'] ?: 'Patient alert'),
            'priority' => strtolower((string) $row['risk_level']) === 'critical' ? 'urgent' : 'clinic_action',
            'status' => (string) $row['status'],
            'area' => 'Emergency',
            'created_at' => (string) $row['created_at'],
            'source_type' => 'nurse_alert',
            'source_id' => (int) $row['id'],
            'patient_person_id' => (int) ($row['patient_id'] ?? 0),
            'action_label' => 'Open emergency alert',
            'source_url' => '../alerts/view.php?id=' . (int) $row['id'],
            'email_relevant' => false,
        ]);
    }

    foreach ($db->query("SELECT n.notification_id, n.patient_person_id, n.category, n.title, n.message, n.source_type,
            n.source_id, n.created_at, TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) patient_name
        FROM patient_notifications n LEFT JOIN people p ON p.id = n.patient_person_id
        WHERE n.read_at IS NULL ORDER BY n.created_at")->fetchAll() as $row) {
        $add($items, [
            'type' => 'unread_notification',
            'label' => 'Unread workflow notification',
            'title' => (string) $row['title'],
            'explanation' => (string) ($row['message'] ?: 'This patient notification has not been read.'),
            'patient_name' => (string) ($row['patient_name'] ?: 'Patient'),
            'priority' => 'clinic_action',
            'status' => 'Unread',
            'area' => ucwords((string) ($row['category'] ?: 'Workflow')),
            'created_at' => (string) $row['created_at'],
            'source_type' => (string) ($row['source_type'] ?: 'notification'),
            'source_id' => (int) ($row['source_id'] ?: $row['notification_id']),
            'patient_person_id' => (int) $row['patient_person_id'],
            'action_label' => 'Open related workflow',
            'source_url' => (string) ($row['source_type'] === 'ape'
                ? '../ape/view.php?id=' . (int) $row['source_id']
                : ($row['source_type'] === 'appointment'
                    ? '../appointments/index.php?status=Scheduled'
                    : '../patients/view.php?id=' . (int) $row['patient_person_id'])),
            'email_relevant' => (string) ($row['source_type'] ?? '') === 'email',
            'email_event_type' => (string) ($row['source_type'] ?? '') === 'email' ? 'patient_notification' : '',
        ]);
    }

    foreach ($db->query("SELECT r.referral_id, r.patient_person_id, r.referred_to, r.reason, r.status, r.created_at,
            TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) patient_name
        FROM referrals r INNER JOIN people p ON p.id = r.patient_person_id
        WHERE r.status NOT IN ('Completed', 'Cancelled') ORDER BY r.created_at")->fetchAll() as $row) {
        $add($items, [
            'type' => 'referral',
            'label' => 'Referral',
            'title' => 'Referral needs review',
            'explanation' => trim((string) $row['referred_to'] . ' ' . (string) $row['reason']),
            'patient_name' => (string) ($row['patient_name'] ?: 'Patient'),
            'priority' => 'clinic_action',
            'status' => (string) $row['status'],
            'area' => 'Referrals',
            'created_at' => (string) $row['created_at'],
            'source_type' => 'referral',
            'source_id' => (int) $row['referral_id'],
            'patient_person_id' => (int) $row['patient_person_id'],
            'action_label' => 'Review referral',
            'source_url' => '../referrals/index.php',
            'email_relevant' => false,
        ]);
    }

    foreach ($db->query("SELECT e.enrollment_id, e.student_person_id, e.academic_year, e.enrollment_status, e.created_at,
            TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) patient_name
        FROM student_school_year_enrollments e INNER JOIN people p ON p.id = e.student_person_id
        WHERE e.enrollment_status NOT IN ('Enrolled', 'Graduated', 'Confirmed') ORDER BY e.created_at")->fetchAll() as $row) {
        $add($items, [
            'type' => 'enrollment',
            'label' => 'School-year enrollment',
            'title' => 'Enrollment confirmation needed',
            'explanation' => 'This student enrollment is still waiting for confirmation or clinic action.',
            'patient_name' => (string) ($row['patient_name'] ?: 'Student'),
            'priority' => 'clinic_action',
            'status' => (string) $row['enrollment_status'],
            'area' => 'Enrollment',
            'created_at' => (string) $row['created_at'],
            'source_type' => 'student_school_year_enrollment',
            'source_id' => (int) $row['enrollment_id'],
            'patient_person_id' => (int) $row['student_person_id'],
            'action_label' => 'Review enrollment',
            'source_url' => '../settings/index.php#school-year',
            'email_relevant' => true,
            'email_event_type' => 'enrollment_confirmation',
        ]);
    }

    $search = strtolower(trim((string) ($filters['search'] ?? '')));
    $type = trim((string) ($filters['type'] ?? ''));
    $priority = trim((string) ($filters['priority'] ?? ''));
    $area = trim((string) ($filters['area'] ?? ''));
    $status = strtolower(trim((string) ($filters['status'] ?? '')));
    $dueDate = trim((string) ($filters['due_date'] ?? ''));
    $age = trim((string) ($filters['age'] ?? ''));
    $emailRelevant = array_key_exists('email_relevant', $filters) ? (bool) $filters['email_relevant'] : null;
    $items = array_values(array_filter($items, static function (array $item) use ($search, $type, $priority, $area, $status, $dueDate, $age, $emailRelevant, $now): bool {
        if ($search !== '' && !str_contains(strtolower(implode(' ', [(string) $item['patient_name'], (string) $item['title'], (string) $item['explanation'], (string) $item['area']])), $search)) return false;
        if ($type !== '' && $item['type'] !== $type) return false;
        if ($priority !== '' && $item['priority'] !== $priority) return false;
        if ($area !== '' && $item['area'] !== $area) return false;
        if ($status !== '' && strtolower((string) $item['status']) !== $status) return false;
        if ($dueDate !== '' && substr((string) $item['due_at'], 0, 10) !== $dueDate) return false;
        if (!workflow_attention_age_matches($item, $age, $now)) return false;
        if ($emailRelevant !== null && (bool) ($item['email_relevant'] ?? false) !== $emailRelevant) return false;
        return true;
    }));
    usort($items, static function (array $a, array $b): int {
        $rank = ['urgent' => 1, 'overdue' => 2, 'clinic_action' => 3, 'waiting_on_patient' => 4, 'scheduled' => 5];
        return (($rank[$a['priority']] ?? 9) <=> ($rank[$b['priority']] ?? 9)) ?: strcmp((string) $a['created_at'], (string) $b['created_at']);
    });
    return array_slice($items, 0, max(1, min(1000, $limit)));
}

function workflow_attention_summary(array $items): array
{
    $summary = ['total' => count($items), 'urgent' => 0, 'overdue' => 0, 'waiting_on_patient' => 0, 'clinic_action' => 0, 'active_emergency' => 0];
    foreach ($items as $item) {
        $key = (string) ($item['priority'] ?? 'clinic_action');
        if (isset($summary[$key])) $summary[$key]++;
        if (($item['type'] ?? '') === 'emergency_alert') $summary['active_emergency']++;
    }
    return $summary;
}
