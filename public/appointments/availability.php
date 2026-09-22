<?php
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_login();
ensure_appointment_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $week = trim((string) ($_POST['week'] ?? ''));
    $redirect = 'availability.php' . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) ? '?week=' . rawurlencode($week) : '');
    $userId = (int) (current_user()['person_id'] ?? 0) ?: null;
    try {
        if ($action === 'save_schedule') {
            appointment_save_weekly_schedule(appointment_schedule_from_form($_POST), $userId);
            flash_message('success', 'Regular clinic working hours were saved.');
        } elseif ($action === 'save_month_schedule') {
            appointment_save_monthly_schedule((string) ($_POST['schedule_month'] ?? ''), appointment_schedule_from_form($_POST), $userId);
            flash_message('success', 'Future-month clinic working hours were saved.');
        } elseif ($action === 'delete_month_schedule') {
            appointment_delete_monthly_schedule((string) ($_POST['schedule_month'] ?? ''), $userId);
            flash_message('success', 'The future-month arrangement was removed.');
        } else {
            throw new InvalidArgumentException('Unknown availability action.');
        }
    } catch (Throwable $exception) {
        flash_message($exception instanceof InvalidArgumentException ? 'warning' : 'error', $exception->getMessage());
    }
    header('Location: ' . $redirect);
    exit;
}

render_header('Clinic Availability');
render_clinic_command_header(
    'Scheduling',
    'Clinic Availability',
    'Manage working hours, future schedules, and unavailable appointment periods.',
    '<a href="' . e(app_url('appointments/index.php')) . '" class="btn btn-outline text-decoration-none"><span class="material-symbols-outlined">arrow_back</span> Back to Appointments</a>'
);
require __DIR__ . '/_availability_section.php';
render_footer();
