<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqVisitWorkflow.php';
require_login();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

$stmt = cliniq_visit_db()->prepare("
    SELECT v.visit_datetime, p.id_number, p.first_name, p.last_name,
           v.chief_complaint, ve.symptoms, vs.temperature, vs.blood_pressure,
           vs.pulse_rate, v.status, v.visit_purpose, v.visit_source, v.action_taken,
           TRIM(CONCAT_WS(' ', rp.first_name, rp.middle_name, rp.last_name)) AS recorded_by
    FROM visits v
    JOIN patients pt ON pt.person_id = v.patient_person_id
    JOIN people p ON p.id = pt.person_id
    LEFT JOIN people rp ON rp.id = v.recorded_by_person_id
    LEFT JOIN visit_entries ve ON ve.entry_id = (
        SELECT ve2.entry_id FROM visit_entries ve2
        WHERE ve2.visit_id = v.visit_id
        ORDER BY ve2.created_at DESC, ve2.entry_id DESC LIMIT 1
    )
    LEFT JOIN vital_signs vs ON vs.vital_id = (
        SELECT vs2.vital_id FROM vital_signs vs2
        WHERE vs2.visit_id = v.visit_id
        ORDER BY vs2.measured_at DESC, vs2.vital_id DESC LIMIT 1
    )
    WHERE DATE(v.visit_datetime) BETWEEN ? AND ?
    ORDER BY v.visit_datetime DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$visits = $stmt->fetchAll();

$filename = 'cliniq_visits_' . $dateFrom . '_to_' . $dateTo . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// BOM for Excel
fwrite($output, "\xEF\xBB\xBF");

// Header row
fputcsv($output, [
    'Date/Time', 'Patient ID', 'First Name', 'Last Name',
    'Chief Complaint', 'Symptoms', 'Temperature', 'Blood Pressure',
    'Pulse Rate', 'Status', 'Purpose', 'Source', 'Action Taken', 'Recorded By'
]);

foreach ($visits as $v) {
    fputcsv($output, [
        $v['visit_datetime'],
        $v['id_number'],
        $v['first_name'],
        $v['last_name'],
        $v['chief_complaint'],
        $v['symptoms'],
        $v['temperature'],
        $v['blood_pressure'],
        $v['pulse_rate'],
        $v['status'],
        $v['visit_purpose'],
        $v['visit_source'],
        $v['action_taken'],
        $v['recorded_by'],
    ]);
}

fclose($output);
