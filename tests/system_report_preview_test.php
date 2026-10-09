<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../public/reports/pdf.php');
$preview = file_get_contents(__DIR__ . '/../public/reports/preview.php');
$reportPage = file_get_contents(__DIR__ . '/../public/reports/index.php');
$renderer = file_get_contents(__DIR__ . '/../app/services/SystemReportRenderer.php');
$exportPayload = file_get_contents(__DIR__ . '/../public/reports/export-data.php');
$exportScript = file_get_contents(__DIR__ . '/../public/assets/js/system-report-export.js');
$chartScript = file_get_contents(__DIR__ . '/../public/assets/js/system-report-charts.js');

if ($source === false || $preview === false || $reportPage === false || $renderer === false || $exportPayload === false || $exportScript === false || $chartScript === false) {
    throw new RuntimeException('Unable to read the report preview source.');
}

$checks = [
    'PDF preview is the protected inline server document' => str_contains($source, 'require_report_access();')
        && str_contains($source, "\$_POST['inline'] = '1'")
        && str_contains($source, "require __DIR__ . '/download.php';"),
    'preview uses the system-native presentation variant' => str_contains($preview, "'presentation' => 'preview'")
        && str_contains($renderer, 'report-preview-summary')
        && str_contains($renderer, 'report-chart-summary-card')
        && str_contains($renderer, '.report-document-dashboard .report-chart-summary-card { min-height: 0; }')
        && str_contains($renderer, '.report-document-dashboard .report-chart-summary-card .report-chart-visual { min-height: 0; }')
        && str_contains($renderer, "\$isDiagram = (\$chart['presentation'] ?? 'diagram') === 'diagram'"),
    'preview and both exports always include matching transaction content' => str_contains($preview, 'All clinic transaction groups are included in this report.')
        && str_contains($preview, 'PDF preview and downloaded PDF are the same generated document.')
        && str_contains($preview, 'data-no-loading="true"')
        && str_contains($preview, 'data-report-export-format="xlsx"')
        && str_contains($preview, 'formaction="pdf.php"')
        && str_contains($preview, 'formaction="download.php"'),
    'download controls cannot expose unloaded icon-font tokens' => !str_contains($preview, 'picture_as_pdfDownload PDF')
        && !str_contains($preview, '>picture_as_pdf</span>Download PDF'),
    'browser XLSX exporter uses only local bundled libraries' => str_contains($preview, 'assets/vendor/exceljs/exceljs.min.js')
        && str_contains($preview, 'assets/vendor/echarts/echarts.min.js')
        && str_contains($preview, 'assets/js/system-report-charts.js')
        && str_contains($preview, 'assets/js/system-report-export.js'),
    'browser report charts mount before ECharts measures them and preserve their fallback on failure' => str_contains($reportPage, 'cliniqSystemReportCharts?.mount(visual, chartData')
        && str_contains($preview, 'cliniqSystemReportCharts?.mount(card.querySelector(\'.report-chart-visual\'), chart')
        && str_contains($chartScript, 'host.replaceChildren(canvas);')
        && str_contains($chartScript, 'host.replaceChildren(...fallback);')
        && str_contains($chartScript, 'instance.on("finished", finish);')
        && str_contains($chartScript, 'requestAnimationFrame(() => requestAnimationFrame(finish));')
        && str_contains($chartScript, 'window.cliniqSystemReportCharts = { optionFor, imageFor, mount };')
        && str_contains($exportScript, 'window.cliniqSystemReportCharts.imageFor'),
    'export payload is protected and canonical' => str_contains($exportPayload, 'require_report_access();')
        && str_contains($exportPayload, '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'')
        && str_contains($exportPayload, 'build_system_report($dateFrom, $dateTo, $modules)'),
    'browser XLSX uses the protected canonical payload and chart source' => str_contains($exportScript, 'await xlsxExport(await payload(form))')
        && str_contains($exportScript, 'const chartImage = (chart, compact = false) => window.cliniqSystemReportCharts.imageFor')
        && str_contains($chartScript, 'const imageFor = async (chart, { width = 960, height = 360 } = {}) =>'),
    'PDF preview and download use the same server document' => str_contains($source, "require __DIR__ . '/download.php';")
        && str_contains(file_get_contents(__DIR__ . '/../public/reports/download.php') ?: '', 'render_system_report_pdf')
        && str_contains($preview, 'formaction="pdf.php"')
        && str_contains($preview, 'formaction="download.php"'),
    'toolbar downloads reuse the preview data without a module picker' => !str_contains($preview, 'name="modules[]"')
        && str_contains($exportScript, 'if (form.__systemReportPayload) return form.__systemReportPayload;'),
    'left logo is fixed and right logo is optional' => str_contains($exportPayload, "app_url('assets/img/clinic-logo.png')")
        && str_contains($exportPayload, '\'custom_logo_url\' => $customLogo === $fixedLogo ? \'\' : $customLogo'),
    'download route returns a server PDF while the inline route shares it' => str_contains(file_get_contents(__DIR__ . '/../public/reports/download.php') ?: '', 'render_system_report_pdf')
        && str_contains(file_get_contents(__DIR__ . '/../public/reports/download.php') ?: '', "header('Content-Type: application/pdf')"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Report preview check failed: ' . $label);
    }
}

echo "System report multi-page preview checks passed.\n";
