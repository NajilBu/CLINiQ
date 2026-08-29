<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportPdf.php';

require_login();

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$dateFrom = normalize_system_report_date($input['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($input['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($input['modules'] ?? []));
$remarks = normalize_system_report_remarks((array) ($input['remarks'] ?? []), $modules);
$report = build_system_report($dateFrom, $dateTo, $modules);
$currentUser = current_user() ?? [];
$pdf = render_system_report_pdf($report, [
    'remarks' => $remarks,
    'prepared_by' => $currentUser,
]);

$filename = 'CLINiQ-System-Analytics-' . $report['date_from'] . '-to-' . $report['date_to'] . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');

echo $pdf;
