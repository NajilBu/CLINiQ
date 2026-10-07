<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../public/reports/pdf.php');
$preview = file_get_contents(__DIR__ . '/../public/reports/preview.php');
$renderer = file_get_contents(__DIR__ . '/../app/services/SystemReportRenderer.php');
$exportPayload = file_get_contents(__DIR__ . '/../public/reports/export-data.php');
$exportScript = file_get_contents(__DIR__ . '/../public/assets/js/system-report-export.js');

if ($source === false || $preview === false || $renderer === false || $exportPayload === false || $exportScript === false) {
    throw new RuntimeException('Unable to read the report preview source.');
}

$checks = [
    'PDF preview restores the native CLINiQ page presentation using exact PDF pages' => str_contains($source, 'id="reportPdfPages"')
        && str_contains($source, 'assets/vendor/pdfjs/pdf.min.js')
        && str_contains($source, 'cliniqSystemReportExport.previewPdf(form, pages)')
        && !str_contains($source, 'render_system_report_document(')
        && str_contains($source, 'system-report-standalone report-preview-multi-page')
        && str_contains($source, 'grid-template-columns: repeat(auto-fit, minmax(31rem, 1fr))'),
    'PDF preview toolbar uses a protected export form' => str_contains($source, 'id="reportExportForm"')
        && str_contains($source, 'name="_csrf"') && str_contains($source, 'csrf_token()'),
    'PDF preview has usable print, layout, and return controls' => str_contains($source, 'reportPreviewLayoutToggle')
        && str_contains($source, 'Single Page View')
        && str_contains($source, 'Multiple Pages View')
        && str_contains($source, 'Back to Preview'),
    'preview uses the system-native presentation variant' => str_contains($preview, "'presentation' => 'preview'")
        && str_contains($renderer, 'report-preview-summary'),
    'preview and both exports always include matching data tables' => str_contains($preview, 'type="hidden" name="include_tables" value="1"')
        && str_contains($preview, 'Charts and complete data tables are included in every format.')
        && str_contains($preview, 'data-report-export-format="xlsx"')
        && str_contains($preview, 'data-report-export-format="pdf"'),
    'download controls cannot expose unloaded icon-font tokens' => !str_contains($preview, 'picture_as_pdfDownload PDF')
        && !str_contains($preview, '>picture_as_pdf</span>Download PDF'),
    'browser exporters use only local bundled libraries' => str_contains($preview, 'assets/vendor/jspdf/jspdf.umd.min.js')
        && str_contains($source, 'assets/vendor/exceljs/exceljs.min.js')
        && str_contains($preview, 'assets/js/system-report-export.js'),
    'export payload is protected and canonical' => str_contains($exportPayload, 'require_report_access();')
        && str_contains($exportPayload, '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'')
        && str_contains($exportPayload, 'build_system_report($dateFrom, $dateTo, $modules)'),
    'browser formats share one payload and chart source' => str_contains($exportScript, 'const data = await payload(form)')
        && str_contains($exportScript, 'await xlsxExport(data)')
        && str_contains($exportScript, 'await pdfExport(data, form.__systemReportPdf)')
        && str_contains($exportScript, 'function chartImage(chart)'),
    'PDF preview and download use the same document builder' => str_contains($exportScript, 'async function buildPdf(data)')
        && str_contains($exportScript, 'const doc = await buildPdf(data);')
        && str_contains($exportScript, 'async function renderPdfPages(doc, host)')
        && str_contains($exportScript, 'form.__systemReportPdf = doc;')
        && str_contains($exportScript, 'await pdfExport(data, form.__systemReportPdf);')
        && str_contains($exportScript, 'window.cliniqSystemReportExport = { previewPdf };'),
    'toolbar downloads accept hidden selected modules and reuse preview data' => str_contains($exportScript, 'input.type === "hidden" || input.checked')
        && str_contains($exportScript, 'if (form.__systemReportPayload) return form.__systemReportPayload;'),
    'left logo is fixed and right logo is optional' => str_contains($exportPayload, "app_url('assets/img/clinic-logo.png')")
        && str_contains($exportPayload, '\'custom_logo_url\' => $customLogo === $fixedLogo ? \'\' : $customLogo'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Report preview check failed: ' . $label);
    }
}

echo "System report multi-page preview checks passed.\n";
