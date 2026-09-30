<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/AppointmentWorkflow.php';

$schedule = [
    'Medical Consult' => ['configured' => true, 'doctors' => [11 => ['days' => []], 12 => ['days' => [2, 4]]]],
    'Dental' => ['configured' => true, 'doctors' => [13 => ['days' => [1, 3, 5]]]],
];
$active = [11, 12, 13];
if (appointment_doctor_ids_for_service($schedule, 'Medical Consult', $active) !== [11, 12]
    || appointment_doctor_ids_for_service($schedule, 'Dental', $active) !== [13]
    || appointment_doctor_ids_for_service($schedule, 'Dental', [11, 12]) !== []) {
    throw new RuntimeException('Doctor role assignments must apply to every open service day.');
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
    || !str_contains($doctorPage, 'Assign consultation roles')
    || str_contains($doctorPage, '[days][]')) {
    throw new RuntimeException('Doctor roles must remain separate from service availability and parallel consultation slot guards.');
}

echo "Appointment doctor schedule checks passed. No database writes.\n";
