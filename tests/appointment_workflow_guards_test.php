<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/AppointmentWorkflow.php';

$blocks = [
    '2026-10-05' => [[
        'start_time' => '10:30:00',
        'end_time' => '11:30:00',
        'reason' => 'Staff preparation',
    ]],
];

if (!appointment_time_is_blocked('2026-10-05', '10:00:00', $blocks)
    || !appointment_time_is_blocked('2026-10-05', '11:00:00', $blocks)
    || appointment_time_is_blocked('2026-10-05', '09:00:00', $blocks)) {
    throw new RuntimeException('Unavailable blocks must protect the complete appointment duration.');
}

$allowedTransitions = [
    ['Pending', 'Scheduled'],
    ['Pending', 'Declined'],
    ['Pending', 'Cancelled'],
    ['Scheduled', 'For Confirmation'],
    ['Scheduled', 'No Show'],
    ['Scheduled', 'Cancelled'],
    ['For Confirmation', 'Completed'],
    ['For Confirmation', 'No Show'],
];
foreach ($allowedTransitions as [$from, $to]) {
    if (!appointment_status_transition_is_allowed($from, $to)) {
        throw new RuntimeException("Expected {$from} to transition to {$to}.");
    }
}
foreach ([['Pending', 'Completed'], ['Pending', 'No Show'], ['Declined', 'Scheduled'], ['Cancelled', 'Scheduled']] as [$from, $to]) {
    if (appointment_status_transition_is_allowed($from, $to)) {
        throw new RuntimeException("Unexpected {$from} to {$to} transition.");
    }
}

if (appointment_actionable_statuses() !== ['Pending', 'For Confirmation']) {
    throw new RuntimeException('Sidebar appointment counts must use the shared actionable appointment statuses.');
}

if (appointment_status_display_label('For Confirmation') !== 'For Completion'
    || appointment_status_display_label('Scheduled') !== 'Scheduled') {
    throw new RuntimeException('Only overdue appointments should use the For Completion display label.');
}

$startedAppointment = ['status' => 'Scheduled', 'appointment_datetime' => '2026-10-05 09:00:00'];
$futureAppointment = ['status' => 'Scheduled', 'appointment_datetime' => '2026-10-05 11:00:00'];
$now = new DateTimeImmutable('2026-10-05 10:00:00');
if (!appointment_can_mark_no_show($startedAppointment, $now)
    || appointment_can_mark_no_show($futureAppointment, $now)
    || !appointment_can_mark_no_show(['status' => 'For Confirmation'], $now)
    || !appointment_user_can_mark_no_show(['role' => 'nurse'])
    || !appointment_user_can_mark_no_show(['role' => 'doctor'])
    || appointment_user_can_mark_no_show(['role' => 'staff'])) {
    throw new RuntimeException('No-show actions must be role-restricted and only available after the appointment starts.');
}
if (appointment_live_service_state(true, true) !== 'busy'
    || appointment_live_service_state(false, true) !== 'walk_in'
    || appointment_live_service_state(false, false) !== 'closed'
    || appointment_live_service_state(true, false) !== 'busy') {
    throw new RuntimeException('Live service status must prioritize an active patient over walk-in availability.');
}

$staffUpdate = file_get_contents(dirname(__DIR__) . '/public/appointments/update.php');
$staffIndex = file_get_contents(dirname(__DIR__) . '/public/appointments/index.php');
$patientBooking = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-appointment.php');
$patientDashboard = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-dashboard.php');
$patientNotifications = file_get_contents(dirname(__DIR__) . '/app/services/PatientNotification.php');
$patientEmails = file_get_contents(dirname(__DIR__) . '/app/services/PatientEmail.php');
if (!str_contains($staffUpdate, "'Declined'")
    || !str_contains($staffUpdate, 'Appointment request declined')
    || !str_contains($staffUpdate, 'appointment_status_transition_is_allowed')
    || !str_contains($staffUpdate, 'appointment_time_is_blocked')
    || !str_contains($staffUpdate, 'appointment_can_mark_no_show')
    || !str_contains($staffUpdate, 'appointment_user_can_mark_no_show')
    || !str_contains($patientDashboard, 'appointment_live_service_statuses')
    || !str_contains($patientDashboard, 'Refresh service status')
    || !str_contains($patientDashboard, '<div class="student-note student-note-warning mb-0 sm:col-span-2" role="status">')
    || !str_contains($patientDashboard, 'Clinic service access unlocks after final APE clearance.')
    || !str_contains($patientBooking, 'Appointments may begin up to 15 minutes late')) {
    throw new RuntimeException('Appointment workflow safeguards are not wired into the staff and patient pages.');
}
if (!str_contains($patientBooking, "'Declined', 'Cancelled', 'No Show' => 'student-badge-danger'")
    || !str_contains($patientBooking, "window.confirm('Cancel this appointment? Your reason will be shared with the clinic.')")
    || !str_contains($patientBooking, 'How availability works')
    || !str_contains($patientBooking, 'Choose a purpose')) {
    throw new RuntimeException('Patient appointment cancellation and compact layout guidance must remain clear.');
}
if (!str_contains($staffIndex, 'data-decision-status="Declined"')
    || !str_contains($patientDashboard, "'Declined' => 'This appointment request was declined by the clinic.'")
    || !str_contains($patientNotifications, "'Declined' => ['Appointment request declined'")
    || !str_contains($patientEmails, "'appointment_declined'")) {
    throw new RuntimeException('Declined appointment requests must use the distinct staff action and patient-facing copy.');
}

echo "Appointment workflow guard checks passed. No database writes.\n";
