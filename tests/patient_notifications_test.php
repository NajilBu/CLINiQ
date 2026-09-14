<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/PatientNotification.php');
$endpoint = file_get_contents($root . '/patient-portal/patient-notifications.php');
$layout = file_get_contents($root . '/patient-portal/includes/patient-layout.php');
$appointment = file_get_contents($root . '/public/appointments/update.php');
$ape = file_get_contents($root . '/public/ape/view.php');
$migration = file_get_contents($root . '/database/migrations/20260913_create_patient_notifications.sql');

foreach (compact('service', 'endpoint', 'layout', 'appointment', 'ape', 'migration') as $name => $contents) {
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$name} notification source.");
    }
}

if (!str_contains($migration, 'FOREIGN KEY (patient_person_id) REFERENCES patients(person_id) ON DELETE CASCADE')) {
    throw new RuntimeException('Notifications must be deleted with their patient record.');
}
if (!str_contains($service, 'WHERE notification_id = ? AND patient_person_id = ?')) {
    throw new RuntimeException('Mark-read updates must be scoped to the logged-in patient.');
}
if (!str_contains($endpoint, 'student_current_profile()') || !str_contains($endpoint, "'unread_count'")) {
    throw new RuntimeException('The patient endpoint must authenticate and return an unread count.');
}
if (!str_contains($layout, 'data-patient-notifications') || !str_contains($layout, 'patient-notifications.php')) {
    throw new RuntimeException('The shared patient layout must render and refresh the notification center.');
}
if (!str_contains($appointment, 'patient_notification_for_appointment')) {
    throw new RuntimeException('Appointment status changes must create patient notifications.');
}
if (!str_contains($ape, 'patient_notification_for_ape_action')) {
    throw new RuntimeException('APE clinical actions must create patient notifications.');
}

echo "Patient notifications structural test passed.\n";
