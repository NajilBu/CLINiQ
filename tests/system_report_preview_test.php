<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../public/reports/pdf.php');
$preview = file_get_contents(__DIR__ . '/../public/reports/preview.php');
$reportPage = file_get_contents(__DIR__ . '/../public/reports/index.php');
$exportPayload = file_get_contents(__DIR__ . '/../public/reports/export-data.php');
$exportScript = file_get_contents(__DIR__ . '/../public/assets/js/system-report-export.js');
$chartScript = file_get_contents(__DIR__ . '/../public/assets/js/system-report-charts.js');
$renderer = file_get_contents(__DIR__ . '/../app/services/SystemReportRenderer.php');
$reportStyles = file_get_contents(__DIR__ . '/../public/assets/css/reports.css');

if ($source === false || $preview === false || $reportPage === false || $exportPayload === false || $exportScript === false || $chartScript === false || $renderer === false || $reportStyles === false) {
    throw new RuntimeException('Unable to read the report preview source.');
}

$checks = [
    'preview loads the shared view helper before rendering page chrome' => str_contains($preview, "require_once __DIR__ . '/../../app/helpers/view.php';")
        && str_contains($preview, 'set_page_back_link(')
        && str_contains($preview, "render_header('Report Preview')")
        && !str_contains($preview, 'clinic-command.css'),
    'analytics and Preview Report share the scoped minimalist typography' => str_contains($renderer, '.report-document-dashboard:not(.report-document-paper-preview) .report-section h2 { color: var(--cliniq-foreground, #17261d); font-family: Manrope, ui-sans-serif, system-ui, sans-serif; font-size: 1.3rem; font-weight: 700; letter-spacing: 0;')
        && str_contains($renderer, '.report-document-dashboard:not(.report-document-paper-preview) .report-metric-value { color: var(--cliniq-primary, #205f3d); font-family: Inter, ui-sans-serif, system-ui, sans-serif;')
        && str_contains($renderer, '.report-document-dashboard:not(.report-document-paper-preview) .report-chart h3 { margin-bottom: 1.125rem; color: var(--cliniq-foreground, #334155); font-family: Manrope, ui-sans-serif, system-ui, sans-serif;')
        && str_contains($renderer, '.report-document-dashboard:not(.report-document-paper-preview) .report-data-table,')
        && str_contains($reportStyles, '.report-preview-guidance h2 { margin: 0 0 .35rem; color: var(--cliniq-foreground, #17261d); font-family: var(--cliniq-font-display); font-size: 1.125rem; font-weight: 700; letter-spacing: 0; }')
        && str_contains($reportStyles, '.reports-page .report-section-navigation-name { min-width: 0; font-family: var(--cliniq-font-display); font-size: .75rem; font-weight: 700; letter-spacing: 0; line-height: 1.3; }'),
    'report preview opens through a full page navigation in the desktop app' => str_contains($reportPage, 'id="reportHeaderPreviewLink"')
        && str_contains($reportPage, 'data-no-ajax="true"'),
    'PDF preview renders shared dashboard markup as separated A4 pages' => str_contains($source, 'id="reportExportForm"')
        && str_contains($source, "require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';")
        && str_contains($source, 'grid-template-columns: repeat(2, minmax(0, 1fr));')
        && str_contains($source, 'width: min(210mm, 100%);')
        && str_contains($source, 'height: 297mm;')
        && str_contains($source, "'presentation' => 'paper-preview'")
        && str_contains($source, 'paginatePaperPreview(report, gallery)')
        && str_contains($source, '.report-preview-page.report-document-dashboard .report-charts { grid-template-columns: repeat(3, minmax(0, 1fr)); }')
        && str_contains($source, '.report-preview-page.report-document-dashboard .report-charts--one-chart { grid-template-columns: 1fr; }')
        && str_contains($source, '.report-preview-page.report-document-dashboard .report-charts--two-charts { grid-template-columns: repeat(2, minmax(0, 1fr)); }')
        && str_contains($source, '.report-preview-page.report-document-dashboard .report-charts--visits { grid-template-columns: repeat(6, minmax(0, 1fr)); }')
        && str_contains($source, '.report-preview-page .report-data-table-scroll { max-height: none; overflow: visible; }')
        && str_contains($source, '.report-preview-page .report-chart > details.report-data-table { display: none; }')
        && str_contains($source, "assets/vendor/fonts/inter-manrope.css?v=offline-1")
        && str_contains($source, '.report-preview-page .report-section h2,')
        && str_contains($source, 'font-family: Manrope, Arial, sans-serif; font-weight: 700; letter-spacing: 0;')
        && str_contains($source, 'font-family: Inter, Arial, sans-serif;')
        && str_contains($source, '.report-preview-page .report-chart h3 { font-size: .8rem; line-height: 1.35; }')
        && str_contains($source, '.report-preview-page .report-data-table td { font-weight: 600; }')
        && str_contains($exportScript, 'const chartRows = (charts) =>')
        && str_contains($exportScript, 'chartRows(child).forEach((row) => {')
        && str_contains($exportScript, 'report-charts--two-charts-one-table')
        && str_contains($exportScript, 'report-charts--two-charts-three-tables')
        && str_contains($exportScript, 'report-charts--visits')
        && str_contains($exportScript, 'report-charts--one-chart')
        && !str_contains($source, 'assets/vendor/pdfjs/pdf.min.js')
        && !str_contains($source, 'previewPdf(form, pages)')
        && str_contains($source, 'window.print()'),
    'A4 pagination preserves every Preview Report row and full-width table span' => str_contains($renderer, "elseif (\$chartCardCount === 2 && \$tableCardCount === 1) \$analysisLayoutClass = ' report-charts--two-charts-one-table';")
        && str_contains($renderer, "elseif (\$chartCardCount === 2 && \$tableCardCount === 3) \$analysisLayoutClass = ' report-charts--two-charts-three-tables';")
        && str_contains($renderer, "elseif (\$chartCardCount === 3 && \$tableCardCount === 1) \$analysisLayoutClass = ' report-charts--three-charts-one-table';")
        && str_contains($renderer, "elseif (\$sectionKey === 'visits' && \$chartCardCount === 8 && \$tableCardCount === 1) \$analysisLayoutClass = ' report-charts--visits';")
        && str_contains($renderer, "elseif (\$analysisCardCount === 5) \$analysisLayoutClass = ' report-charts--five-cards';")
        && str_contains($exportScript, 'if (charts.classList.contains("report-charts--two-charts-one-table")) return [row(0, 2, "report-charts--two-charts"), row(2, 3, "report-charts--one-chart")];')
        && str_contains($exportScript, 'if (charts.classList.contains("report-charts--two-charts-three-tables")) return [row(0, 2, "report-charts--two-charts"), row(2, 5)];')
        && str_contains($exportScript, 'if (charts.classList.contains("report-charts--three-charts-one-table")) return [row(0, 3), row(3, 4, "report-charts--one-chart")];')
        && str_contains($exportScript, 'if (charts.classList.contains("report-charts--visits")) return [row(0, 3), row(3, 6), row(6, 8, "report-charts--two-charts"), row(8, 9, "report-charts--one-chart")];')
        && str_contains($exportScript, 'if (charts.classList.contains("report-charts--five-cards")) return [row(0, 3), row(3, 5, "report-charts--two-charts")];'),
    'A4 table fragments expand without scrolling and move each complete row exactly once' => str_contains($renderer, '.report-data-table-scroll { max-height: 280px; overflow: auto; }')
        && str_contains($exportScript, 'const isSplittableTableRow = (row) =>')
        && str_contains($exportScript, 'const appendTableRow = (section, row) =>')
        && str_contains($exportScript, 'rows: Array.from(card.querySelector(".report-data-table-static tbody").children)')
        && str_contains($exportScript, 'tbody.replaceChildren();')
        && str_contains($exportScript, 'state.fragmentBody.append(tableRow);')
        && str_contains($exportScript, 'state.next += 1;')
        && str_contains($exportScript, '`${title.textContent} (continued)`')
        && str_contains($exportScript, 'if (isSplittableTableRow(row)) appendTableRow(section, row);'),
    'generated PDF keeps the formal school-year letterhead' => str_contains($exportScript, 'SCHOOL YEAR ${schoolYearStart}-${schoolYearStart + 1}')
        && str_contains($exportScript, 'String(branding.institution_name || "").toUpperCase()'),
    'preview and paper view use shared report markup while preserving editable preview remarks' => str_contains($preview, "'presentation' => 'preview'")
        && str_contains($preview, "'remarks_mode' => 'input'")
        && str_contains($source, "'remarks_mode' => 'print'")
        && str_contains($source, 'render_system_report_document($report, false')
        && str_contains($preview, 'Open A4 Preview'),
    'preview and browser exports honor matching transaction content' => str_contains($preview, 'name="modules[]"')
        && str_contains($preview, 'All selected clinic transaction groups are included in this report.')
        && str_contains($preview, 'data-no-loading="true"')
        && !str_contains($preview, 'data-report-export-format="xlsx"')
        && str_contains($preview, 'formaction="pdf.php"')
        && str_contains($source, 'systemReportExportPayload'),
    'download controls cannot expose unloaded icon-font tokens' => !str_contains($preview, 'picture_as_pdfDownload PDF')
        && !str_contains($preview, '>picture_as_pdf</span>Download PDF'),
    'browser XLSX exporter is available only after the A4 snapshot is ready' => str_contains($source, 'assets/vendor/exceljs/exceljs.min.js')
        && str_contains($source, 'assets/vendor/apexcharts/apexcharts.min.js')
        && str_contains($source, 'assets/js/system-report-charts.js')
        && str_contains($source, 'assets/js/system-report-export.js')
        && str_contains($source, 'data-report-export-format="xlsx" disabled')
        && str_contains($source, 'exportButton.disabled = false;'),
    'PDF preview loads the chart runtime before paginating shared dashboard markup' => str_contains($source, 'assets/vendor/apexcharts/apexcharts.min.js')
        && str_contains($source, 'assets/js/system-report-charts.js')
        && str_contains($source, 'assets/js/system-report-export.js')
        && !str_contains($source, 'assets/vendor/jspdf/jspdf.umd.min.js')
        && !str_contains($source, 'assets/vendor/pdfjs/pdf.min.js')
        && strpos($source, 'assets/js/system-report-charts.js') < strpos($source, 'assets/js/system-report-export.js'),
    'browser report charts mount through the ApexCharts adapter and preserve their fallback on failure' => str_contains($reportPage, 'cliniqSystemReportCharts?.mount(visual, chartData')
        && str_contains($preview, "cliniqSystemReportCharts?.mount(card.querySelector('.report-chart-visual'), chart")
        && str_contains($chartScript, 'host.replaceChildren(canvas);')
        && str_contains($chartScript, 'host.replaceChildren(...fallback);')
        && str_contains($chartScript, 'new window.ApexCharts(canvas, optionFor(chart, { dark, pdf }))')
        && str_contains($reportPage, 'if (!window.ApexCharts) return;')
        && str_contains($chartScript, 'window.cliniqSystemReportCharts = { optionFor, imageFor, mount };')
        && str_contains($exportScript, 'window.cliniqSystemReportCharts.imageFor'),
    'jump navigation scrolls within the app content pane' => str_contains($reportPage, "const scrollRoot = document.querySelector('.app-content');")
        && str_contains($reportPage, 'scrollRoot.scrollTo({')
        && !str_contains($reportPage, 'target.scrollIntoView({'),
    'donut charts keep category labels and values visible with external connector callouts' => str_contains($chartScript, 'external: {')
        && str_contains($chartScript, 'connector: { show: true, length: 12, width: 1 }')
        && str_contains($chartScript, 'formatter: (name, options) => [name, String(values[options.seriesIndex] ?? 0)]')
        && str_contains($chartScript, 'if (rows.length > 4) return horizontalBarOptions(rows, dark, { axisMax, decimals, colors, pdf });')
        && str_contains($chartScript, 'expandOnClick: false'),
    'export payload is protected and canonical' => str_contains($exportPayload, 'require_report_access();')
        && str_contains($exportPayload, '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'')
        && str_contains($exportPayload, 'build_system_report($dateFrom, $dateTo, $modules)'),
    'browser XLSX export uses the protected snapshot and keeps data tables only' => str_contains($exportScript, 'const data = await payload(form)')
        && str_contains($exportScript, 'await xlsxExport(data)')
        && str_contains($exportScript, 'const dataTables = [...(section.charts || []), ...(section.tables || [])];')
        && substr_count($exportScript, 'sheet.addImage(') === 2,
    'printable PDF preview uses square bar and column corners without changing its layout' => str_contains($source, 'mount(card.querySelector(\'.report-chart-visual\'), chart, { dark, pdf: true })')
        && str_contains($chartScript, 'borderRadius: pdf ? 0 : 6')
        && str_contains($chartScript, 'const groupedPdfXAxis = (chart, labels) =>')
        && str_contains($chartScript, 'chart?.title === "Visits by Hour"')
        && str_contains($chartScript, 'const hideOverlappingPdfXAxisLabels = (host) =>')
        && str_contains($chartScript, 'if (pdf) hideOverlappingPdfXAxisLabels(canvas);')
        && !str_contains($renderer, 'system_report_paper_chart_is_figure')
        && str_contains($exportScript, 'charts.classList.contains("report-charts--visits")')
        && str_contains($exportScript, 'child.classList.contains("visit-metric-groups")'),
    'PDF toolbar prints the prepared A4 pages and keeps XLSX on their snapshot' => str_contains($source, 'data-report-print disabled')
        && str_contains($source, 'window.print()')
        && str_contains($source, "printButton.textContent = 'Preparing PDF…';")
        && str_contains($source, 'CLINiQ-Clinic-Transaction-Summary-${form.__systemReportPayload.report.date_from}-to-${form.__systemReportPayload.report.date_to}')
        && str_contains($source, 'form.__systemReportPayload = JSON.parse')
        && !str_contains($source, 'download.php')
        && !str_contains($source, 'data-report-export-format="pdf"'),
    'toolbar downloads use the prepared report snapshot' => str_contains($preview, 'name="modules[]"')
        && str_contains($exportScript, 'if (form.__systemReportPayload) return form.__systemReportPayload;')
        && !str_contains($source, 'fetch("export-data.php"'),
    'returning from PDF preview preserves report state without placing remarks in the URL' => str_contains($source, 'method="post" action="preview.php"')
        && str_contains($source, 'name="remarks[')
        && str_contains($preview, '$_SERVER[\'REQUEST_METHOD\'] === \'POST\'')
        && str_contains($preview, 'csrf_enforce_request();'),
    'shared browser payload keeps fixed and optional logos together' => str_contains($renderer, "app_url('assets/img/clinic-logo.png')")
        && str_contains($renderer, "'custom_logo_url' => \$customLogo === \$fixedLogo ? '' : \$customLogo")
        && str_contains($exportPayload, 'system_report_browser_export_payload'),
    'non-matching server PDF download is removed' => !is_file(__DIR__ . '/../public/reports/download.php')
        && !is_file(__DIR__ . '/../app/services/SystemReportPdf.php'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Report preview check failed: ' . $label);
    }
}

echo "System report multi-page preview checks passed.\n";
