<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/AppointmentWorkflow.php';

$schedule = [
    'Medical Consult' => ['configured' => true, 'doctors' => [11 => ['days' => []], 12 => ['days' => [2, 4]]]],
    'Dental' => ['configured' => true, 'doctors' => [13 => ['days' => [1, 3, 5]]]],
];
$active = [11, 12, 13];
if (appointment_doctor_ids_for_weekday($schedule, 'Medical Consult', 1, $active) !== [11]
    || appointment_doctor_ids_for_weekday($schedule, 'Medical Consult', 2, $active) !== [11, 12]
    || appointment_doctor_ids_for_weekday($schedule, 'Dental', 2, $active) !== []
    || appointment_doctor_ids_for_weekday($schedule, 'Dental', 3, [11, 12]) !== []) {
    throw new RuntimeException('Default or restricted doctor weekdays are incorrect.');
}

$defaultSelection = appointment_normalize_doctor_selection([], []);
$restrictedSelection = appointment_normalize_doctor_selection(['Dental'], ['4', '2', '4']);
if ($defaultSelection !== ['purposes' => ['Medical Consult', 'Dental'], 'days' => []]
    || $restrictedSelection !== ['purposes' => ['Dental'], 'days' => [2, 4]]) {
    throw new RuntimeException('Blank purpose or weekday selections did not receive the intended defaults.');
}

$root = dirname(__DIR__);
$booking = file_get_contents($root . '/patient-portal/patient-appointment.php');
$approval = file_get_contents($root . '/public/appointments/update.php');
$migration = file_get_contents($root . '/database/migrations/20260929_allow_parallel_consult_appointments.sql');
$doctorPage = file_get_contents($root . '/public/appointments/doctors.php');
if (!str_contains($booking, 'appointment_purpose_has_doctor_on_date')
    || !str_contains($booking, 'doctorAvailable')
    || !str_contains($approval, 'appointment_purpose_has_doctor_on_date')
    || !str_contains($migration, 'uq_appointments_reserved_slot (reserved_slot, purpose)')
    || substr_count($doctorPage, 'name="days[]"') !== 1) {
    throw new RuntimeException('Doctor coverage or parallel consultation slot guards are missing.');
}

echo "Appointment doctor schedule checks passed. No database writes.\n";
