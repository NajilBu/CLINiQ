<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';

require_report_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$dateFrom = normalize_system_report_date($_POST['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($_POST['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($_POST['modules'] ?? []));
$remarks = normalize_system_report_remarks((array) ($_POST['remarks'] ?? []), $modules);
$report = build_system_report($dateFrom, $dateTo, $modules);
$profile = clinic_profile_settings();
$fixedLogo = app_url('assets/img/clinic-logo.png');
$customLogo = app_url(clinic_profile_logo_path($profile));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
echo json_encode([
    'report' => $report,
    'remarks' => $remarks,
    'prepared_by' => current_user() ?? [],
    'branding' => [
        'system_name' => $profile['system_name'] ?? 'CLINiQ',
        'institution_name' => $profile['institution_name'] ?? 'Pamantasan ng Lungsod ng Pasig',
        'department' => $profile['department'] ?? 'University Health Services',
        'plp_logo_url' => $fixedLogo,
        'custom_logo_url' => $customLogo === $fixedLogo ? '' : $customLogo,
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
