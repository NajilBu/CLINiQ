<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'migration' => 'database/migrations/20261013_link_appointments_to_visits.sql',
    'schema' => 'database/production_schema.sql',
    'visits' => 'app/services/CliniqVisitWorkflow.php',
    'checkin' => 'public/appointments/check-in.php',
    'update' => 'public/appointments/update.php',
    'index' => 'public/appointments/index.php',
    'appointment_view' => 'public/appointments/view.php',
    'view' => 'public/visits/view.php',
    'dashboard' => 'public/dashboard.php',
    'reports' => 'app/services/SystemReport.php',
];

foreach ($files as $name => $path) {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$name} source.");
    }
    $files[$name] = $contents;
}

if (!str_contains($files['migration'], 'ADD COLUMN appointment_id')
    || !str_contains($files['migration'], 'UNIQUE INDEX uq_visits_appointment')
    || !str_contains($files['migration'], 'FOREIGN KEY (appointment_id) REFERENCES appointments')) {
    throw new RuntimeException('The appointment-to-visit migration must enforce a one-to-one foreign-key link.');
}
if (!str_contains($files['schema'], 'appointment_id BIGINT UNSIGNED NULL')
    || !str_contains($files['schema'], 'uq_visits_appointment')
    || !str_contains($files['schema'], 'fk_visits_appointment')) {
    throw new RuntimeException('The production schema must include the appointment-to-visit link.');
}
if (!str_contains($files['visits'], 'function cliniq_visit_create_from_appointment')
    || !str_contains($files['visits'], 'appointment_can_check_in($appointment)')
    || !str_contains($files['visits'], 'function cliniq_visit_sync_linked_appointment_outcome')
    || !str_contains($files['visits'], 'INNER JOIN appointments a ON a.appointment_id = v.appointment_id')
    || !str_contains($files['visits'], "'status' => 'Active'")
    || !str_contains($files['visits'], "'visit_source' => 'Appointment'")
    || !str_contains($files['visits'], "'chief_complaint' => \$chiefComplaint")) {
    throw new RuntimeException('Appointment check-in must require a same-day eligible appointment and create an active Appointment visit.');
}
if (!str_contains($files['checkin'], 'name="chief_complaint"')
    || !str_contains($files['checkin'], 'required')
    || !str_contains($files['checkin'], 'cliniq_visit_create_from_appointment')) {
    throw new RuntimeException('The check-in form must require a clinical complaint before creating the visit.');
}
if (str_contains($files['update'], "\$status === 'Completed' && ((\$linkedVisit['status'] ?? '') !== 'Completed')")
    || !str_contains($files['update'], "\$status === 'No Show'")
    || !str_contains($files['update'], 'checked-in appointment cannot be marked as no-show')) {
    throw new RuntimeException('Only the clinical visit may complete a checked-in appointment, and no-show must still reject checked-in appointments.');
}
if (!str_contains($files['index'], 'appointments/check-in.php?id=')
    || !str_contains($files['index'], 'Open Visit')
    || !str_contains($files['dashboard'], 'appointments/check-in.php?id=')
    || !str_contains($files['dashboard'], 'Open Visit')) {
    throw new RuntimeException('Staff appointment surfaces must expose check-in and linked visit actions.');
}
if (!str_contains($files['appointment_view'], 'v.visit_id AS linked_visit_id')
    || !str_contains($files['appointment_view'], 'Open Clinical Visit')
    || !str_contains($files['appointment_view'], "visits/view.php?id=")) {
    throw new RuntimeException('Appointment details must directly open a linked clinical visit and distinguish legacy attendance review.');
}
if (!str_contains($files['visits'], 'cliniq_visit_sync_linked_appointment_outcome($db, $visitId, $staffId)')
    || substr_count($files['view'], 'cliniq_visit_sync_linked_appointment_outcome($visitDb, $id, $staffPersonId)') !== 4
    || substr_count($files['checkin'], 'cliniq_visit_create_from_appointment') !== 1) {
    throw new RuntimeException('Every linked visit terminal path must reconcile its appointment without duplicating the check-in flow.');
}
if (!str_contains($files['reports'], "'Visit Source'")) {
    throw new RuntimeException('Analytics must retain its visit-source reporting surface.');
}

echo "Appointment-to-visit link tests passed.\n";
