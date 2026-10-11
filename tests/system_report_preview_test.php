<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../public/reports/pdf.php');
$preview = file_get_contents(__DIR__ . '/../public/reports/preview.php');
$reportPage = file_get_contents(__DIR__ . '/../public/reports/index.php');
$exportPayload = file_get_contents(__DIR__ . '/../public/reports/export-data.php');
$exportScript = file_get_contents(__DIR__ . '/../public/assets/js/system-report-export.js');
$chartScript = file_get_contents(__DIR__ . '/../public/assets/js/system-report-charts.js');

if ($source === false || $preview === false || $reportPage === false || $exportPayload === false || $exportScript === false || $chartScript === false) {
    throw new RuntimeException('Unable to read the report preview source.');
}

$checks = [
    'preview loads the shared view helper before rendering page chrome' => str_contains($preview, "require_once __DIR__ . '/../../app/helpers/view.php';")
        && str_contains($preview, 'set_page_back_link(')
        && str_contains($preview, "render_header('Report Preview')"),
    'PDF preview renders the generated printable document as separated A4 pages' => str_contains($source, 'id="reportExportForm"')
        && str_contains($source, 'grid-template-columns: repeat(2, minmax(0, 1fr));')
        && str_contains($source, 'width: min(210mm, 100%);')
        && str_contains($source, 'aspect-ratio: 210 / 297;')
        && str_contains($source, 'assets/vendor/pdfjs/pdf.min.js')
        && str_contains($source, 'previewPdf(form, pages)')
        && str_contains($source, 'onclick="window.print()"'),
    'generated PDF keeps the formal school-year letterhead' => str_contains($exportScript, 'SCHOOL YEAR ${schoolYearStart}-${schoolYearStart + 1}')
        && str_contains($exportScript, 'String(branding.institution_name || "").toUpperCase()'),
    'preview and PDF use canonical report data while preserving editable preview remarks' => str_contains($preview, "'presentation' => 'preview'")
        && str_contains($preview, "'remarks_mode' => 'input'")
        && str_contains($source, 'previewPdf(form, pages)')
        && str_contains($preview, 'Print / Save PDF'),
    'preview and browser exports honor matching transaction content' => str_contains($preview, 'name="modules[]"')
        && str_contains($preview, 'All selected clinic transaction groups are included in this report.')
        && str_contains($preview, 'data-no-loading="true"')
        && str_contains($preview, 'data-report-export-format="xlsx"')
        && str_contains($preview, 'formaction="pdf.php"'),
    'download controls cannot expose unloaded icon-font tokens' => !str_contains($preview, 'picture_as_pdfDownload PDF')
        && !str_contains($preview, '>picture_as_pdf</span>Download PDF'),
    'browser XLSX exporter uses only local bundled libraries' => str_contains($preview, 'assets/vendor/exceljs/exceljs.min.js')
        && str_contains($preview, 'assets/vendor/jspdf/jspdf.umd.min.js')
        && str_contains($preview, 'assets/vendor/jspdf-autotable/jspdf.plugin.autotable.min.js')
        && str_contains($preview, 'assets/vendor/apexcharts/apexcharts.min.js')
        && str_contains($preview, 'assets/js/system-report-charts.js')
        && str_contains($preview, 'assets/js/system-report-export.js'),
    'PDF preview loads the PDF and chart runtimes before rendering the generated document' => str_contains($source, 'assets/vendor/apexcharts/apexcharts.min.js')
        && str_contains($source, 'assets/js/system-report-charts.js')
        && str_contains($source, 'assets/vendor/pdfjs/pdf.min.js')
        && strpos($source, 'assets/js/system-report-charts.js') < strpos($source, 'assets/js/system-report-export.js'),
    'browser report charts mount through the ApexCharts adapter and preserve their fallback on failure' => str_contains($reportPage, 'cliniqSystemReportCharts?.mount(visual, chartData')
        && str_contains($preview, "cliniqSystemReportCharts?.mount(card.querySelector('.report-chart-visual'), chart")
        && str_contains($chartScript, 'host.replaceChildren(canvas);')
        && str_contains($chartScript, 'host.replaceChildren(...fallback);')
        && str_contains($chartScript, 'new window.ApexCharts(canvas, optionFor(chart, { dark }))')
        && str_contains($reportPage, 'if (!window.ApexCharts) return;')
        && str_contains($chartScript, 'window.cliniqSystemReportCharts = { optionFor, imageFor, mount };')
        && str_contains($exportScript, 'window.cliniqSystemReportCharts.imageFor'),
    'jump navigation scrolls within the app content pane' => str_contains($reportPage, "const scrollRoot = document.querySelector('.app-content');")
        && str_contains($reportPage, 'scrollRoot.scrollTo({')
        && !str_contains($reportPage, 'target.scrollIntoView({'),
    'donut charts keep category labels and values visible with external connector callouts' => str_contains($chartScript, 'external: {')
        && str_contains($chartScript, 'connector: { show: true, length: 12, width: 1 }')
        && str_contains($chartScript, 'formatter: (name, options) => [name, String(values[options.seriesIndex] ?? 0)]')
        && str_contains($chartScript, 'if (rows.length > 4) return horizontalBarOptions(rows, dark, { axisMax, decimals, colors });')
        && str_contains($chartScript, 'expandOnClick: false'),
    'export payload is protected and canonical' => str_contains($exportPayload, 'require_report_access();')
        && str_contains($exportPayload, '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'')
        && str_contains($exportPayload, 'build_system_report($dateFrom, $dateTo, $modules)'),
    'browser exports use the protected canonical payload and chart source' => str_contains($exportScript, 'const data = await payload(form)')
        && str_contains($exportScript, 'await xlsxExport(data)')
        && str_contains($exportScript, 'const chartImage = (chart, compact = false) => window.cliniqSystemReportCharts.imageFor')
        && str_contains($chartScript, 'const imageFor = async (chart, { width = 960, height = 360 } = {}) =>')
        && str_contains($chartScript, 'const image = await instance.dataURI({ scale: 2 });'),
    'PDF preview prints and downloads the same generated report document' => str_contains($source, 'onclick="window.print()"')
        && str_contains($source, 'Print / Save PDF')
        && str_contains($source, 'data-report-export-format="pdf"')
        && str_contains($preview, 'Print / Save PDF'),
    'toolbar downloads include selected modules' => str_contains($preview, 'name="modules[]"')
        && str_contains($exportScript, 'if (form.__systemReportPayload) return form.__systemReportPayload;'),
    'returning from PDF preview preserves report state without placing remarks in the URL' => str_contains($source, 'method="post" action="preview.php"')
        && str_contains($source, 'name="remarks[')
        && str_contains($preview, '$_SERVER[\'REQUEST_METHOD\'] === \'POST\'')
        && str_contains($preview, 'csrf_enforce_request();'),
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
