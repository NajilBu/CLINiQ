<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_login();
ensure_appointment_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $cancellationReason = trim($_POST['cancellation_reason'] ?? '');
    $allowed = ['Pending', 'Scheduled', 'For Confirmation', 'Completed', 'Cancelled', 'No Show'];
    $redirect = $_POST['redirect'] ?? 'index.php';
    $allowedRedirects = ['index.php', '../dashboard.php'];
    $reviewedByPersonId = (int) (current_user()['person_id'] ?? 0) ?: null;

    if ($id > 0 && in_array($status, $allowed, true)) {
        if ($status === 'Cancelled' && $cancellationReason === '') {
            flash_message('error', 'Please provide a reason before cancelling the appointment.');
            header('Location: ' . (in_array($redirect, $allowedRedirects, true) ? $redirect : 'index.php'));
            exit;
        }

        if ($status === 'Cancelled') {
            $stmt = appointment_db()->prepare('UPDATE appointments SET status = ?, cancellation_reason = ?, cancelled_by = ?, reviewed_by_person_id = ? WHERE appointment_id = ?');
            $stmt->execute([$status, $cancellationReason, 'Clinic', $reviewedByPersonId, $id]);
        } else {
            $stmt = appointment_db()->prepare('UPDATE appointments SET status = ?, cancellation_reason = NULL, cancelled_by = NULL, reviewed_by_person_id = ? WHERE appointment_id = ?');
            $stmt->execute([$status, $reviewedByPersonId, $id]);
        }

        $message = match ($status) {
            'Scheduled' => 'Appointment request approved and added to the clinic schedule.',
            'For Confirmation' => 'Appointment moved to clinic confirmation.',
            'Cancelled' => 'Appointment request cancelled.',
            'Completed' => 'Appointment marked as completed.',
            'No Show' => 'Appointment marked as no-show.',
            default => 'Appointment status updated.',
        };
        flash_message('success', $message);
    }

    header('Location: ' . (in_array($redirect, $allowedRedirects, true) ? $redirect : 'index.php'));
    exit;
}

header('Location: index.php');
