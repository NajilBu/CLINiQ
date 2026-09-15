<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/../../app/services/PatientNotification.php';
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
        $db = appointment_db();
        try {
            if ($status === 'Cancelled' && $cancellationReason === '') {
                throw new InvalidArgumentException('Please provide a reason before cancelling the appointment.');
            }

            $db->beginTransaction();
            $appointmentStmt = $db->prepare('SELECT appointment_id, patient_id, appointment_datetime, purpose, status, cancellation_reason FROM appointments WHERE appointment_id = ? FOR UPDATE');
            $appointmentStmt->execute([$id]);
            $appointment = $appointmentStmt->fetch();
            if (!$appointment) {
                throw new RuntimeException('Appointment not found.');
            }

            if ($status === 'Scheduled') {
                $appointmentDatetime = (string) $appointment['appointment_datetime'];
                if (!appointment_slot_is_open(substr($appointmentDatetime, 0, 10), substr($appointmentDatetime, 11, 8))) {
                    throw new InvalidArgumentException('This request is outside the clinic working days or hours. Choose a valid time before approving it.');
                }
                $apeConflict = appointment_ape_batch_conflict($appointmentDatetime);
                if ($apeConflict !== null) {
                    $batchTime = date('g:i A', strtotime((string) $apeConflict['start_time']))
                        . '–' . date('g:i A', strtotime((string) $apeConflict['end_time']));
                    throw new InvalidArgumentException('This appointment overlaps the APE batch "' . $apeConflict['batch_name'] . '" at ' . $batchTime . '. Choose another appointment time before approving it.');
                }
            }

            $changed = (string) $appointment['status'] !== $status
                || ($status === 'Cancelled' && (string) ($appointment['cancellation_reason'] ?? '') !== $cancellationReason);
            if ($changed) {
                if ($status === 'Cancelled') {
                    $stmt = $db->prepare('UPDATE appointments SET status = ?, cancellation_reason = ?, cancelled_by = ?, reviewed_by_person_id = ? WHERE appointment_id = ?');
                    $stmt->execute([$status, $cancellationReason, 'Clinic', $reviewedByPersonId, $id]);
                    $appointment['cancellation_reason'] = $cancellationReason;
                } else {
                    $stmt = $db->prepare('UPDATE appointments SET status = ?, cancellation_reason = NULL, cancelled_by = NULL, reviewed_by_person_id = ? WHERE appointment_id = ?');
                    $stmt->execute([$status, $reviewedByPersonId, $id]);
                    $appointment['cancellation_reason'] = null;
                }
                patient_notification_for_appointment($db, $appointment, $status, $reviewedByPersonId);
            }
            $db->commit();

            $message = match ($status) {
                'Scheduled' => 'Appointment request approved and added to the clinic schedule.',
                'For Confirmation' => 'Appointment moved to clinic confirmation.',
                'Cancelled' => 'Appointment request cancelled.',
                'Completed' => 'Appointment marked as completed.',
                'No Show' => 'Appointment marked as no-show.',
                default => 'Appointment status updated.',
            };
            flash_message('success', $changed ? $message : 'Appointment status is already up to date.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        }
    }

    header('Location: ' . (in_array($redirect, $allowedRedirects, true) ? $redirect : 'index.php'));
    exit;
}

header('Location: index.php');
