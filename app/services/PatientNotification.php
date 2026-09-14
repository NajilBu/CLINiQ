<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function patient_notification_create(
    PDO $db,
    int $patientPersonId,
    ?int $actorPersonId,
    string $category,
    string $title,
    string $message,
    ?string $targetUrl = null,
    ?string $sourceType = null,
    ?int $sourceId = null
): int {
    $category = trim($category);
    $title = trim($title);
    $message = trim($message);
    $targetUrl = trim((string) $targetUrl) ?: null;
    $sourceType = trim((string) $sourceType) ?: null;

    if ($patientPersonId < 1 || $category === '' || $title === '' || $message === '') {
        throw new InvalidArgumentException('A notification requires a patient, category, title, and message.');
    }
    if (mb_strlen($category) > 40 || mb_strlen($title) > 160 || mb_strlen((string) $targetUrl) > 255 || mb_strlen((string) $sourceType) > 50) {
        throw new InvalidArgumentException('Notification metadata is too long.');
    }
    if ($targetUrl !== null && !preg_match('/^patient-[a-z0-9-]+\.php(?:\?[a-z0-9_=&%-]+)?$/i', $targetUrl)) {
        throw new InvalidArgumentException('Notification links must stay inside the patient portal.');
    }

    $stmt = $db->prepare('
        INSERT INTO patient_notifications
            (patient_person_id, actor_person_id, category, title, message, target_url, source_type, source_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $patientPersonId,
        ($actorPersonId ?? 0) > 0 ? $actorPersonId : null,
        $category,
        $title,
        $message,
        $targetUrl,
        $sourceType,
        ($sourceId ?? 0) > 0 ? $sourceId : null,
    ]);

    return (int) $db->lastInsertId();
}

function patient_notification_recent(PDO $db, int $patientPersonId, int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    $stmt = $db->prepare("\n        SELECT notification_id, category, title, message, target_url, read_at, created_at\n        FROM patient_notifications\n        WHERE patient_person_id = ?\n        ORDER BY created_at DESC, notification_id DESC\n        LIMIT {$limit}\n    ");
    $stmt->execute([$patientPersonId]);
    return $stmt->fetchAll();
}

function patient_notification_unread_count(PDO $db, int $patientPersonId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM patient_notifications WHERE patient_person_id = ? AND read_at IS NULL');
    $stmt->execute([$patientPersonId]);
    return (int) $stmt->fetchColumn();
}

function patient_notification_mark_read(PDO $db, int $patientPersonId, int $notificationId): bool
{
    $stmt = $db->prepare('UPDATE patient_notifications SET read_at = COALESCE(read_at, NOW()) WHERE notification_id = ? AND patient_person_id = ?');
    $stmt->execute([$notificationId, $patientPersonId]);
    return $stmt->rowCount() === 1;
}

function patient_notification_mark_all_read(PDO $db, int $patientPersonId): int
{
    $stmt = $db->prepare('UPDATE patient_notifications SET read_at = NOW() WHERE patient_person_id = ? AND read_at IS NULL');
    $stmt->execute([$patientPersonId]);
    return $stmt->rowCount();
}

function patient_notification_for_appointment(PDO $db, array $appointment, string $status, ?int $actorPersonId): ?int
{
    $patientId = (int) ($appointment['patient_id'] ?? 0);
    $appointmentId = (int) ($appointment['appointment_id'] ?? 0);
    if ($patientId < 1 || $appointmentId < 1) {
        return null;
    }

    $when = !empty($appointment['appointment_datetime'])
        ? date('F j, Y \a\t g:i A', strtotime((string) $appointment['appointment_datetime']))
        : 'the selected date';
    $reason = trim((string) ($appointment['cancellation_reason'] ?? ''));
    [$title, $message] = match ($status) {
        'Scheduled' => ['Appointment approved', "Your clinic appointment for {$when} has been approved."],
        'For Confirmation' => ['Appointment needs confirmation', "Your appointment for {$when} is awaiting clinic confirmation."],
        'Completed' => ['Appointment completed', "Your clinic appointment for {$when} was marked completed."],
        'Cancelled' => ['Appointment cancelled', "Your clinic appointment for {$when} was cancelled." . ($reason !== '' ? " Reason: {$reason}" : '')],
        'No Show' => ['Appointment marked no-show', "Your clinic appointment for {$when} was marked as a no-show."],
        default => ['Appointment updated', "Your clinic appointment for {$when} was updated to {$status}."],
    };

    return patient_notification_create($db, $patientId, $actorPersonId, 'appointment', $title, $message, 'patient-appointment.php', 'appointment', $appointmentId);
}

function patient_notification_for_ape_action(PDO $db, array $record, string $action, ?int $actorPersonId): ?int
{
    $patientId = (int) ($record['patient_id'] ?? 0);
    $apeId = (int) ($record['ape_id'] ?? 0);
    if ($patientId < 1 || $apeId < 1) {
        return null;
    }

    $patientNote = trim((string) ($record['patient_visible_note'] ?? ''));
    $dueDate = !empty($record['follow_up_due_date'])
        ? date('F j, Y', strtotime((string) $record['follow_up_due_date']))
        : null;

    $notification = match ($action) {
        'record_examination' => ['APE examination recorded', 'The clinic recorded your APE examination results. Open your APE status for the latest details.'],
        'save_document_review', 'save_requirements', 'save_hard_copy_review', 'mark_requirements_complete', 'mark_missing_requirements' =>
            ['APE requirements reviewed', 'The clinic reviewed your APE requirements. Check your APE status for any required corrections or follow-up documents.'],
        'approve_documents' => ['APE documents accepted', 'The clinic reviewed and accepted your uploaded APE documents.'],
        'request_document_correction' => ['APE document correction needed', 'The clinic returned one or more APE documents for correction. Open your APE status to see what needs attention.'],
        'finalize_exam_clear', 'approve_clearance' => ['APE clearance approved', 'Your APE has been cleared by the clinic.' . ($patientNote !== '' ? " {$patientNote}" : '')],
        'finalize_exam_follow_up', 'keep_follow_up_open' => ['APE follow-up required', 'The clinic requires follow-up for your APE.' . ($dueDate ? " Please respond by {$dueDate}." : '') . ($patientNote !== '' ? " {$patientNote}" : '')],
        'return_clearance' => ['APE clearance correction needed', 'Your submitted clearance needs correction. Open your APE status for the clinic instructions.'],
        'save_notes' => ['Clinic note updated', $patientNote !== '' ? $patientNote : 'The clinic updated the note on your APE record.'],
        default => null,
    };

    if ($notification === null) {
        return null;
    }

    return patient_notification_create($db, $patientId, $actorPersonId, 'ape', $notification[0], $notification[1], 'patient-ape-status.php', 'ape', $apeId);
}
