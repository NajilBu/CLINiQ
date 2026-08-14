<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportPdf.php';
require_login();

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$dateFrom = normalize_system_report_date($input['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($input['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($input['modules'] ?? []));
$remarks = normalize_system_report_remarks((array) ($input['remarks'] ?? []), $modules);

try {
    $report = build_system_report($dateFrom, $dateTo, $modules);
    $pdfPath = generate_system_report_pdf($report, ['remarks_mode' => 'print', 'remarks' => $remarks]);
    $filename = 'CLINiQ_System_Report_' . $report['date_from'] . '_to_' . $report['date_to'] . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, no-store, max-age=0');
    readfile($pdfPath);
    @unlink($pdfPath);
    exit;
} catch (Throwable $e) {
    flash_message('error', 'PDF export failed: ' . $e->getMessage());
    $query = http_build_query(['from' => $dateFrom, 'to' => $dateTo, 'modules' => $modules]);
    header('Location: preview.php?' . $query);
    exit;
}
