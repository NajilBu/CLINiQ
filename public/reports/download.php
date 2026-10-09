<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportPdf.php';
require_report_access();

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_enforce_request();

$dateFrom = normalize_system_report_date($input['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($input['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($input['modules'] ?? []));
$remarks = normalize_system_report_remarks((array) ($input['remarks'] ?? []), $modules);
$report = build_system_report($dateFrom, $dateTo, $modules);
$pdf = render_system_report_pdf($report, ['prepared_by' => current_user() ?? [], 'remarks' => $remarks]);

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (($input['inline'] ?? '') === '1' ? 'inline' : 'attachment') . '; filename="CLINiQ-Clinic-Transaction-Summary-' . $report['date_from'] . '-to-' . $report['date_to'] . '.pdf"');
header('Cache-Control: private, no-store, max-age=0');
echo $pdf;
