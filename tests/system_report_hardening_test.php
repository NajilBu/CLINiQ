<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$builder = file_get_contents($root . '/app/services/SystemReport.php');
$renderer = file_get_contents($root . '/app/services/SystemReportRenderer.php');
$index = file_get_contents($root . '/public/reports/index.php');
$tables = file_get_contents($root . '/public/reports/tables.php');
$preview = file_get_contents($root . '/public/reports/preview.php');
$pdf = file_get_contents($root . '/public/reports/pdf.php');
$download = file_get_contents($root . '/public/reports/download.php');
$serverPdf = file_get_contents($root . '/app/services/SystemReportPdf.php');
$exportPayload = file_get_contents($root . '/public/reports/export-data.php');
$alerts = file_get_contents($root . '/public/alerts/index.php');
$referrals = file_get_contents($root . '/public/referrals/index.php');

if ($builder === false || $renderer === false || $index === false || $tables === false || $preview === false || $pdf === false || $download === false || $serverPdf === false || $exportPayload === false || $alerts === false || $referrals === false) {
    throw new RuntimeException('Unable to read the report sources.');
}

foreach (['index.php', 'tables.php', 'preview.php', 'pdf.php', 'download.php'] as $endpoint) {
    $source = file_get_contents($root . '/public/reports/' . $endpoint);
    if ($source === false || !str_contains($source, 'require_report_access();')) {
        throw new RuntimeException("{$endpoint} must enforce report access.");
    }
}

$checks = [
    'report building does not modify alert schema' => !str_contains($builder, 'ensure_alert_workflow_schema'),
    'weekly period is seven inclusive days' => str_contains($index, "modify('-6 days')") && str_contains($index, 'anchor.getDate() - 6'),
    'dashboard retains a static chart fallback' => str_contains($renderer, '<?= render_system_report_chart($chart) ?>'),
    'date filters work without JavaScript' => str_contains($index, 'type="submit">Apply period</button>'),
    'filters replace only report analytics' => str_contains($index, 'const refreshAnalytics = async () =>')
        && str_contains($index, "nextDocument.querySelector('.report-analytics-layout')")
        && str_contains($index, 'layout.replaceWith(nextLayout)'),
    'live action summary is aggregate-only' => str_contains($builder, 'function system_report_action_summary')
        && str_contains($builder, 'Low-stock medicine')
        && str_contains($builder, 'Incomplete referrals'),
    'live action summary remains visible when clear' => str_contains($renderer, 'There are no current operational exceptions needing attention.'),
    'chart and table views switch in place' => str_contains($index, "'include_tables' => false")
        && str_contains($index, "reportView = (\$_GET['view'] ?? '') === 'tables'")
        && str_contains($index, "tablesLink?.addEventListener('click'")
        && str_contains($tables, "Location: index.php?view=tables"),
    'legacy table screen redirects to the in-place view' => str_contains($tables, "Location: index.php?view=tables"),
    'every chart has an accessible data table' => str_contains($renderer, 'function render_system_report_data_table')
        && str_contains($renderer, '<caption>')
        && str_contains($renderer, 'View data table'),
    'visual charts limit categories without limiting table data' => str_contains($index, 'tableRows.slice(0, 10)'),
    'server PDF is the authoritative protected document' => str_contains($preview, 'All clinic transaction groups are included in this report.')
        && str_contains($preview, 'formaction="pdf.php"')
        && str_contains($preview, 'formaction="download.php"')
        && str_contains($download, 'render_system_report_pdf')
        && str_contains($download, "header('Content-Type: application/pdf')")
        && str_contains($serverPdf, '%PDF-1.4')
        && str_contains($serverPdf, 'Transaction Summary')
        && str_contains($pdf, "require __DIR__ . '/download.php';")
        && str_contains($exportPayload, 'build_system_report($dateFrom, $dateTo, $modules)')
        && str_contains($download, 'csrf_enforce_request'),
    'legacy patient CSV route is retired' => !is_file($root . '/public/reports/export.php'),
    'dashboard CSS cache key changes with the asset' => str_contains($index, "filemtime(__DIR__ . '/../assets/css/reports.css')"),
    'report filters use index-friendly half-open date ranges' => str_contains($builder, 'function system_report_range')
        && str_contains($builder, 'visit_datetime >= ? AND visit_datetime < ?')
        && str_contains($builder, 'created_at >= ? AND created_at < ?')
        && !str_contains($builder, 'DATE(visit_datetime) BETWEEN')
        && !str_contains($builder, 'DATE(created_at) BETWEEN'),
    'action queues have matching filters' => str_contains($alerts, "'high-critical'")
        && str_contains($referrals, "['all', 'incomplete', 'completed']"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException("Report hardening check failed: {$label}.");
    }
}

echo "System report hardening checks passed. No database writes.\n";
