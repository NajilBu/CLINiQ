<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

$stmt = db()->prepare("
    SELECT v.visit_datetime, p.student_number, p.first_name, p.last_name,
           v.chief_complaint, v.symptoms, v.temperature, v.blood_pressure,
           v.pulse_rate, v.status, v.visit_purpose, v.visit_source, v.action_taken, u.name AS recorded_by
    FROM clinic_visits v
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users u ON u.id = v.recorded_by
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
    'Date/Time', 'Student No.', 'First Name', 'Last Name',
    'Chief Complaint', 'Symptoms', 'Temperature', 'Blood Pressure',
    'Pulse Rate', 'Status', 'Purpose', 'Source', 'Action Taken', 'Recorded By'
]);

foreach ($visits as $v) {
    fputcsv($output, [
        $v['visit_datetime'],
        $v['student_number'],
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
