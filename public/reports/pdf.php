<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';
require_report_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_enforce_request();
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$dateFrom = normalize_system_report_date($input['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($input['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($input['modules'] ?? []));
$remarks = normalize_system_report_remarks((array) ($input['remarks'] ?? []), $modules);
$period = (string) ($input['period'] ?? 'monthly');
$semester = (int) ($input['semester'] ?? 0);
$report = build_system_report($dateFrom, $dateTo, $modules);
$fields = '<input type="hidden" name="_csrf" value="' . system_report_escape(csrf_token()) . '">'
    . '<input type="hidden" name="from" value="' . system_report_escape($dateFrom) . '">'
    . '<input type="hidden" name="to" value="' . system_report_escape($dateTo) . '">'
    . '<input type="hidden" name="period" value="' . system_report_escape($period) . '">'
    . '<input type="hidden" name="semester" value="' . system_report_escape((string) $semester) . '">';
foreach ($modules as $module) {
    $fields .= '<input type="hidden" name="modules[]" value="' . system_report_escape((string) $module) . '">';
}
foreach ($remarks as $module => $remark) {
    $fields .= '<input type="hidden" name="remarks[' . system_report_escape((string) $module) . ']" value="' . system_report_escape((string) $remark) . '">';
}
$browserExportPayload = json_encode(
    system_report_browser_export_payload($report, $remarks, (array) (current_user() ?? [])),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CLINiQ Clinic Transaction Summary</title>
    <link rel="stylesheet" href="<?= system_report_escape(app_url('assets/vendor/fonts/inter-manrope.css?v=offline-1')) ?>">
    <style>
        <?= system_report_styles() ?>
        * { box-sizing: border-box; }
        body.system-report-standalone { margin: 0; background: #e8efeb; padding-bottom: 36px; }
        .print-toolbar { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 18px; background: rgba(255,255,255,.94); border-bottom: 1px solid #dfe9e2; box-shadow: 0 10px 24px rgba(23,38,29,.08); font-family: Arial, Helvetica, sans-serif; }
        .print-toolbar strong { display: block; color: #17261d; font-size: 14px; }
        .print-toolbar span { display: block; margin-top: 2px; color: #64748b; font-size: 11px; font-weight: 700; }
        .print-toolbar-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .print-toolbar-actions form { display: contents; }
        .print-toolbar button { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 0 15px; border: 1px solid #cfded3; border-radius: 999px; background: #fff; color: #205f3d; font: 900 12px Arial, Helvetica, sans-serif; cursor: pointer; }
        .print-toolbar button.secondary { border-color: #7bd19b; background: #eaf7ef; color: #14532d; }
        .print-report { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); justify-items: center; gap: 28px; width: min(1780px, calc(100% - 32px)); margin: 28px auto; }
        .report-paper-source { position: absolute; left: -100000px; top: 0; width: 210mm; visibility: hidden; pointer-events: none; }
        .report-preview-page { display: flex; flex-direction: column; width: min(210mm, 100%); height: 297mm; overflow: hidden; border: 1px solid #d7dfd9; background: #fff; box-shadow: 0 18px 46px rgba(23,38,29,.16); }
        .report-preview-page .report-paper-letterhead { flex: 0 0 auto; padding: 8mm 10mm 5mm; }
        .report-preview-page.report-document.report-document-dashboard.report-document-paper-preview .report-body { flex: 1 1 auto; min-height: 0; overflow: hidden; padding: 7mm 10mm; }
        .report-preview-page .report-footer { display: flex; flex: 0 0 auto; padding: 4mm 10mm 6mm; }
        .report-preview-page.report-document.report-document-dashboard.report-document-paper-preview .report-section { margin: 0; padding: 0; border: 0; box-shadow: none; }
        .report-preview-page .report-section-heading { margin-bottom: 1rem; }
        .report-preview-page .report-charts { margin-bottom: 1rem; }
        .report-preview-page .report-data-table-scroll { max-height: none; overflow: visible; }
        .report-preview-page .report-chart > details.report-data-table { display: none; }
        .report-preview-page .report-section-number,
        .report-preview-page .report-section-description,
        .report-preview-page .report-metric-label,
        .report-preview-page .report-metric-value,
        .report-preview-page .report-metric-note,
        .report-preview-page .report-data-table,
        .report-preview-page .report-footer { font-family: Inter, Arial, sans-serif; }
        .report-preview-page .report-section-number { font-size: .7rem; font-weight: 700; }
        .report-preview-page .report-section h2,
        .report-preview-page .visit-metric-group-title,
        .report-preview-page .report-chart h3 { font-family: Manrope, Arial, sans-serif; font-weight: 700; letter-spacing: 0; }
        .report-preview-page .report-section h2 { font-size: 1.18rem; }
        .report-preview-page .report-section-description { font-size: .75rem; font-weight: 400; }
        .report-preview-page .visit-metric-group-title { font-size: .7rem; }
        .report-preview-page .report-metric-label { font-size: .5625rem; font-weight: 700; letter-spacing: .03em; }
        .report-preview-page .report-metric-value { font-size: 1.4rem; font-weight: 700; letter-spacing: 0; }
        .report-preview-page .report-metric-note { font-size: .625rem; font-weight: 500; }
        .report-preview-page .report-chart h3 { font-size: .8rem; line-height: 1.35; }
        .report-preview-page .report-chart-kind { font-size: .5rem; font-weight: 700; letter-spacing: .03em; }
        .report-preview-page .report-data-table table { font-size: .625rem; }
        .report-preview-page .report-data-table th { font-weight: 700; }
        .report-preview-page .report-data-table td { font-weight: 600; }
        .report-preview-page .report-footer { font-size: .5rem; font-weight: 500; }
        /* An A4 page is narrower than the dashboard's tablet breakpoint, but its report layout stays desktop-sized. */
        .report-preview-page.report-document-dashboard .report-metrics { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .visit-metric-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .report-charts { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .report-charts--one-chart { grid-template-columns: 1fr; }
        .report-preview-page.report-document-dashboard .report-charts--two-charts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .report-charts--five-cards { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .report-charts--five-cards > .report-chart { grid-column: span 2; }
        .report-preview-page.report-document-dashboard .report-charts--five-cards > .report-chart:nth-last-child(-n + 2) { grid-column: span 3; }
        .report-preview-page.report-document-dashboard .report-charts--two-charts-three-tables { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .report-charts--two-charts-three-tables > .report-chart { grid-column: span 2; }
        .report-preview-page.report-document-dashboard .report-charts--two-charts-three-tables > .report-chart:nth-child(-n + 2) { grid-column: span 3; }
        .report-preview-page.report-document-dashboard .report-charts--two-charts-one-table { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .report-charts--two-charts-one-table > .report-chart:nth-child(-n + 2) { grid-column: span 3; }
        .report-preview-page.report-document-dashboard .report-charts--two-charts-one-table > .report-chart:last-child { grid-column: 1 / -1; }
        .report-preview-page.report-document-dashboard .report-charts--feedback { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .report-charts--feedback > .report-chart:first-child { grid-column: span 4; }
        .report-preview-page.report-document-dashboard .report-charts--feedback > .report-chart:nth-child(2) { order: 2; align-self: start; min-height: 12rem; grid-column: span 2; }
        .report-preview-page.report-document-dashboard .report-charts--feedback > .report-chart:last-child { order: 3; grid-column: 1 / -1; }
        .report-preview-page.report-document-dashboard .report-charts--feedback-top { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .report-charts--feedback-top > .report-chart:first-child { grid-column: span 4; }
        .report-preview-page.report-document-dashboard .report-charts--feedback-top > .report-chart:last-child { grid-column: span 2; }
        .report-preview-page.report-document-dashboard .report-charts--feedback-top > .report-chart:last-child .report-chart-visual { min-height: 10rem; }
        .report-preview-page.report-document-dashboard .report-charts--feedback-top > .report-chart:last-child .report-apexcharts-canvas { height: 10.5rem; }
        .report-preview-page.report-document-dashboard .report-charts--three-charts-one-table > .report-chart:last-child { grid-column: 1 / -1; }
        .report-preview-page.report-document-dashboard .report-charts--visits { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .report-preview-page.report-document-dashboard .report-charts--visits > .report-chart { grid-column: span 2; }
        .report-preview-page.report-document-dashboard .report-charts--visits > .report-chart:nth-last-child(3),
        .report-preview-page.report-document-dashboard .report-charts--visits > .report-chart:nth-last-child(2) { grid-column: span 3; }
        .report-preview-page.report-document-dashboard .report-charts--visits > .report-chart:last-child { grid-column: 1 / -1; }
        .report-preview-status { grid-column: 1 / -1; color: #526155; font: 700 13px Arial, Helvetica, sans-serif; }
        @media (max-width: 980px) { .print-report { grid-template-columns: minmax(0, 1fr); width: min(210mm, calc(100% - 32px)); } }
        @media print {
            .print-toolbar { display: none !important; }
            body.system-report-standalone { padding: 0; background: #fff; }
            .print-report { display: block; width: auto; margin: 0; }
            .report-paper-source { display: none !important; }
            .report-preview-page { width: 210mm; height: 297mm; margin: 0; border: 0; box-shadow: none; break-after: page; page-break-after: always; }
            .report-preview-page:last-child { break-after: auto; page-break-after: auto; }
            .report-preview-status { display: none; }
        }
    </style>
</head>
<body class="system-report-standalone">
    <header class="print-toolbar">
        <div><strong>PDF Preview</strong><span>Uses the same dashboard cards and charts as the report preview.</span></div>
        <div class="print-toolbar-actions">
            <form method="post" action="preview.php"><?= $fields ?><button type="submit">Back to Preview</button></form>
            <form id="reportExportForm"><?= $fields ?><button type="button" class="secondary" data-report-print disabled>Save PDF</button><button type="button" class="secondary" data-report-export-format="xlsx" disabled>Download XLSX</button></form>
        </div>
    </header>
    <main class="print-report" aria-live="polite">
        <p class="report-preview-status" role="status">Preparing printable A4 pages...</p>
        <div class="report-paper-source">
            <?= render_system_report_document($report, false, ['presentation' => 'paper-preview', 'remarks_mode' => 'print', 'remarks' => $remarks]) ?>
        </div>
    </main>
    <script id="systemReportExportPayload" type="application/json"><?= $browserExportPayload ?></script>
    <script src="<?= system_report_escape(app_url('assets/vendor/exceljs/exceljs.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/vendor/apexcharts/apexcharts.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/js/system-report-charts.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-charts.js'))) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/js/system-report-export.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-export.js'))) ?>"></script>
    <script>
        (() => {
            const report = document.querySelector('#systemReportDocument');
            const gallery = document.querySelector('.print-report');
            const status = gallery.querySelector('.report-preview-status');
            const form = document.getElementById('reportExportForm');
            const printButton = form.querySelector('[data-report-print]');
            const exportButton = form.querySelector('[data-report-export-format="xlsx"]');
            const dark = document.documentElement.classList.contains('cliniq-dark');
            const mounted = [];

            try {
                form.__systemReportPayload = JSON.parse(document.getElementById('systemReportExportPayload').textContent);
            } catch (_) {
                status.textContent = 'Unable to prepare the report export.';
                return;
            }

            printButton.addEventListener('click', () => {
                const readyTitle = document.title;
                const filename = `CLINiQ-Clinic-Transaction-Summary-${form.__systemReportPayload.report.date_from}-to-${form.__systemReportPayload.report.date_to}`;
                printButton.disabled = true;
                printButton.textContent = 'Preparing PDF…';
                document.title = filename;
                window.addEventListener('afterprint', () => {
                    document.title = readyTitle;
                    printButton.textContent = 'Save PDF';
                    printButton.disabled = false;
                }, { once: true });
                window.requestAnimationFrame(() => window.print());
            });

            report.querySelectorAll('[data-report-chart]').forEach((card) => {
                let chart;
                try { chart = JSON.parse(card.dataset.reportChart || '{}'); } catch (_) { return; }
                if (chart.presentation !== 'diagram' || !Array.isArray(chart.rows) || !chart.rows.length) return;
                const instance = window.cliniqSystemReportCharts?.mount(card.querySelector('.report-chart-visual'), chart, { dark, pdf: true });
                if (instance) mounted.push(instance);
            });

            window.requestAnimationFrame(() => window.requestAnimationFrame(async () => {
                try {
                    const result = await window.cliniqSystemReportExport.paginatePaperPreview(report, gallery);
                    await Promise.all(mounted.map((instance) => instance.resize()));
                    document.title = `CLINiQ Clinic Transaction Summary (${result.pages} pages)`;
                    printButton.disabled = false;
                    exportButton.disabled = false;
                } catch (error) {
                    status.textContent = error instanceof Error ? error.message : 'Unable to prepare the printable report preview.';
                    gallery.replaceChildren(status);
                }
            }));
        })();
    </script>
</body>
</html>
