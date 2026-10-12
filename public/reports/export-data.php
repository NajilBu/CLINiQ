<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';

require_report_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
csrf_enforce_request();

$dateFrom = normalize_system_report_date($_POST['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($_POST['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($_POST['modules'] ?? []));
$remarks = normalize_system_report_remarks((array) ($_POST['remarks'] ?? []), $modules);
$report = build_system_report($dateFrom, $dateTo, $modules);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
echo json_encode(system_report_browser_export_payload($report, $remarks, (array) (current_user() ?? [])), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
