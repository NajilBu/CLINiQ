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
    ['Pending', 'Cancelled'],
    ['Scheduled', 'For Confirmation'],
    ['Scheduled', 'Cancelled'],
    ['For Confirmation', 'Completed'],
    ['For Confirmation', 'No Show'],
];
foreach ($allowedTransitions as [$from, $to]) {
    if (!appointment_status_transition_is_allowed($from, $to)) {
        throw new RuntimeException("Expected {$from} to transition to {$to}.");
    }
}
foreach ([['Pending', 'Completed'], ['Scheduled', 'No Show'], ['Cancelled', 'Scheduled']] as [$from, $to]) {
    if (appointment_status_transition_is_allowed($from, $to)) {
        throw new RuntimeException("Unexpected {$from} to {$to} transition.");
    }
}

if (appointment_actionable_statuses() !== ['Pending', 'For Confirmation']) {
    throw new RuntimeException('Sidebar appointment counts must use the shared actionable appointment statuses.');
}

$staffUpdate = file_get_contents(dirname(__DIR__) . '/public/appointments/update.php');
$patientBooking = file_get_contents(dirname(__DIR__) . '/patient-portal/patient-appointment.php');
if (!str_contains($staffUpdate, 'appointment_status_transition_is_allowed')
    || !str_contains($staffUpdate, 'appointment_time_is_blocked')
    || !str_contains($patientBooking, 'Appointments may begin up to 15 minutes late')) {
    throw new RuntimeException('Appointment workflow safeguards are not wired into the staff and patient pages.');
}

echo "Appointment workflow guard checks passed. No database writes.\n";
