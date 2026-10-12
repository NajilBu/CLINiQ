<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$builder = file_get_contents($root . '/app/services/SystemReport.php');
$renderer = file_get_contents($root . '/app/services/SystemReportRenderer.php');
$index = file_get_contents($root . '/public/reports/index.php');
$tables = file_get_contents($root . '/public/reports/tables.php');
$preview = file_get_contents($root . '/public/reports/preview.php');
$pdf = file_get_contents($root . '/public/reports/pdf.php');
$exportPayload = file_get_contents($root . '/public/reports/export-data.php');
$alerts = file_get_contents($root . '/public/alerts/index.php');
$referrals = file_get_contents($root . '/public/referrals/index.php');

if ($builder === false || $renderer === false || $index === false || $tables === false || $preview === false || $pdf === false || $exportPayload === false || $alerts === false || $referrals === false) {
    throw new RuntimeException('Unable to read the report sources.');
}

foreach (['index.php', 'tables.php', 'preview.php', 'pdf.php'] as $endpoint) {
    $source = file_get_contents($root . '/public/reports/' . $endpoint);
    if ($source === false || !str_contains($source, 'require_report_access();')) {
        throw new RuntimeException("{$endpoint} must enforce report access.");
    }
}

$checks = [
    'report building does not modify alert schema' => !str_contains($builder, 'ensure_alert_workflow_schema'),
    'weekly period is seven inclusive days' => str_contains($index, "modify('-6 days')") && str_contains($index, 'anchor.getDate() - 6'),
    'dashboard retains a static chart fallback' => str_contains($renderer, '<?= render_system_report_chart($chart) ?>'),
    'analytics charts use three desktop columns without changing print layout' => str_contains($renderer, '.report-document-dashboard .report-charts { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--five-cards { grid-template-columns: repeat(6, minmax(0, 1fr)); }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--five-cards > .report-chart { grid-column: span 2; }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--five-cards > .report-chart:nth-last-child(-n + 2) { grid-column: span 3; }')
        && str_contains($renderer, '$analysisCardCount === 5')
        && str_contains($renderer, '.report-document-dashboard .report-charts--two-charts-three-tables { grid-template-columns: repeat(6, minmax(0, 1fr)); }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--two-charts-three-tables > .report-chart:nth-child(-n + 2) { grid-column: span 3; }')
        && str_contains($renderer, '$chartCardCount === 2 && $tableCardCount === 3')
        && str_contains($renderer, '.report-document-dashboard .report-charts--one-chart { grid-template-columns: 1fr; }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--two-charts { grid-template-columns: repeat(2, minmax(0, 1fr)); }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--two-charts-one-table > .report-chart:nth-child(-n + 2) { grid-column: span 3; }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--two-charts-one-table > .report-chart:last-child { grid-column: 1 / -1; }')
        && str_contains($renderer, '$chartCardCount === 2 && $tableCardCount === 1')
         && str_contains($renderer, '.report-document-dashboard .report-charts--feedback > .report-chart:first-child { grid-column: span 4; }')
         && str_contains($renderer, '.report-document-dashboard .report-charts--feedback > .report-chart:nth-child(2) { order: 2; align-self: start; min-height: 12rem; grid-column: span 2; }')
         && str_contains($renderer, '.report-document-dashboard .report-charts--feedback > .report-chart:last-child { order: 3; grid-column: 1 / -1; }')
         && str_contains($renderer, '$sectionKey === \'feedback\' && $chartCardCount === 2 && $tableCardCount === 1')
        && str_contains($renderer, '.report-document-dashboard .report-charts--three-charts-one-table > .report-chart:last-child { grid-column: 1 / -1; }')
        && str_contains($renderer, '$chartCardCount === 3 && $tableCardCount === 1')
        && str_contains($renderer, '.report-document-dashboard .report-charts--visits { grid-template-columns: repeat(6, minmax(0, 1fr)); }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--visits > .report-chart:nth-last-child(3),')
        && str_contains($renderer, '$sectionKey === \'visits\' && $chartCardCount === 8 && $tableCardCount === 1')
        && str_contains($renderer, '@media (max-width: 60rem)')
        && str_contains($renderer, '.report-document-dashboard .report-charts { grid-template-columns: repeat(2, minmax(0, 1fr)); }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--five-cards > .report-chart { grid-column: auto; }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--two-charts-three-tables > .report-chart { grid-column: auto; }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--visits > .report-chart:last-child { grid-column: 1 / -1; }')
        && str_contains($renderer, '@media print')
        && str_contains($renderer, '.report-charts { grid-template-columns: repeat(2, minmax(0, 1fr)); }')
        && str_contains($renderer, '.report-document-dashboard .report-charts--five-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }'),
    'analytics metrics use four desktop columns with responsive fallbacks' => str_contains($renderer, '.report-document-dashboard .report-metrics { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }')
        && str_contains($renderer, '.report-document-dashboard .report-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); }')
        && str_contains($renderer, '.report-document-dashboard .report-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }'),
    'alerts retains urgent-risk attention without rendering redundant metric cards' => str_contains($builder, "'attention_metrics' => [")
        && str_contains($builder, "system_report_metric('Pending Alerts'")
        && str_contains($builder, "system_report_metric('High or Critical Risk'")
        && str_contains($builder, "array_merge(\$section['metrics'], \$section['summary_metrics'] ?? [], \$section['attention_metrics'] ?? [])"),
    'visits keeps summary-only counts out of visible metric cards' => str_contains($builder, "'summary_metrics' => [")
        && str_contains($renderer, "'Clinical visit activity' => ['Clinical Visits', 'Visits with Vitals', 'Median Time to First Action', 'Median Time to Completion']"),
    'inventory uses the seamless standard four-column metrics grid' => str_contains($renderer, "<?php elseif (!\$tableOnly): ?>")
        && !str_contains($renderer, 'inventory-metric-groups')
        && !str_contains($renderer, "'Stock overview'"),
    'Visits separates clinical and appointment metrics' => str_contains($renderer, "\$sectionKey === 'visits'")
        && str_contains($renderer, "'Clinical visit activity'")
        && str_contains($renderer, "'Appointment activity'")
        && str_contains($renderer, 'class="visit-metric-groups"')
        && str_contains($renderer, 'class="visit-metric-group"')
        && str_contains($renderer, '.report-document-dashboard .visit-metric-groups { display: grid; grid-template-columns: 1fr; gap: 1rem; margin-bottom: 1.5rem; }')
        && str_contains($renderer, '.report-document-dashboard .visit-metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .625rem; }')
        && str_contains($renderer, '.report-document-dashboard .visit-metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }'),
    'date filters work without JavaScript' => str_contains($index, 'type="submit">Apply period</button>'),
    'filters replace only report analytics' => str_contains($index, 'const refreshAnalytics = async () =>')
        && str_contains($index, "nextDocument.querySelector('.report-analytics-layout')")
        && str_contains($index, 'layout.replaceWith(nextLayout)'),
    'live action summary is aggregate-only' => str_contains($builder, 'function system_report_action_summary')
        && str_contains($builder, 'Low-stock medicine')
        && str_contains($builder, 'Incomplete referrals'),
    'live action summary disappears when clear' => str_contains($renderer, 'if (!$actions) {')
        && str_contains($renderer, "return '';"),
    'chart and table views switch in place' => str_contains($index, "'include_tables' => false")
        && str_contains($index, "reportView = (\$_GET['view'] ?? '') === 'tables'")
        && str_contains($index, "tablesLink?.addEventListener('click'")
        && str_contains($tables, "Location: index.php?view=tables"),
    'legacy table screen redirects to the in-place view' => str_contains($tables, "Location: index.php?view=tables"),
    'every chart has an accessible data table' => str_contains($renderer, 'function render_system_report_data_table')
        && str_contains($renderer, '<caption>')
        && str_contains($renderer, 'View data table'),
    'standalone table cards omit the redundant data-table disclosure' => str_contains($renderer, 'function render_system_report_data_table(array $chart, bool $open = false, bool $showSummary = true)')
        && str_contains($renderer, 'report-data-table-static')
        && str_contains($renderer, 'render_system_report_data_table($table, true, false)')
        && str_contains($renderer, 'render_system_report_data_table($chart, true, false)'),
    'visual charts limit categories without limiting table data' => str_contains($builder, 'function system_report_visual_rows')
        && str_contains($builder, "['label' => 'Other', 'value' => \$other]")
        && str_contains($builder, "'visual_rows' => \$visualRows")
        && str_contains($renderer, "'rows' => \$chart['visual_rows'] ?? \$chart['rows']"),
    'printable preview is protected and is the only PDF source' => str_contains($preview, 'name="modules[]"')
        && str_contains($preview, 'Open the A4 preview when your remarks are ready.')
        && str_contains($preview, 'formaction="pdf.php"')
        && str_contains($preview, '>Open A4 Preview</button>')
        && !str_contains($preview, 'Download XLSX')
        && str_contains($pdf, 'require_report_access();')
        && str_contains($pdf, 'csrf_enforce_request();')
        && str_contains($pdf, "'presentation' => 'paper-preview'")
        && str_contains($pdf, 'paginatePaperPreview(report, gallery)')
        && !str_contains($pdf, 'assets/vendor/pdfjs/pdf.min.js')
        && !str_contains($pdf, 'previewPdf(form, pages)')
        && str_contains($pdf, 'data-report-print disabled')
        && str_contains($pdf, 'window.print()')
        && str_contains($pdf, 'systemReportExportPayload')
        && !str_contains($pdf, 'download.php')
        && str_contains($pdf, 'assets/vendor/apexcharts/apexcharts.min.js')
        && str_contains($pdf, 'assets/js/system-report-charts.js')
        && str_contains($exportPayload, 'build_system_report($dateFrom, $dateTo, $modules)')
        && !is_file($root . '/public/reports/download.php')
        && !is_file($root . '/app/services/SystemReportPdf.php'),
    'legacy patient CSV route is retired' => !is_file($root . '/public/reports/export.php'),
    'dashboard CSS cache key changes with the asset' => str_contains($index, "filemtime(__DIR__ . '/../assets/css/reports.css')"),
    'report filters use index-friendly half-open date ranges' => str_contains($builder, 'function system_report_range')
        && str_contains($builder, 'visit_datetime >= ? AND visit_datetime < ?')
        && str_contains($builder, 'created_at >= ? AND created_at < ?')
        && str_contains($builder, 'appointment_datetime >= ? AND appointment_datetime < ?')
        && !str_contains($builder, 'DATE(visit_datetime) BETWEEN')
        && !str_contains($builder, 'DATE(created_at) BETWEEN'),
    'browser period presets use the same reporting-date anchor as the server' => str_contains($index, 'const reportAnchor = new Date(')
        && str_contains($index, 'const anchor = new Date(reportAnchor);'),
    'dark mode styles the action summary as an intentional surface' => str_contains($renderer, 'html.cliniq-dark .report-action-summary')
        && str_contains($renderer, 'html.cliniq-dark .report-action-card--red'),
    'action queues have matching filters' => str_contains($alerts, "'high-critical'")
        && str_contains($referrals, "['all', 'incomplete', 'completed']"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException("Report hardening check failed: {$label}.");
    }
}

echo "System report hardening checks passed. No database writes.\n";
