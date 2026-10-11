<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_report_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_enforce_request();
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$dateFrom = normalize_system_report_date($input['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($input['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($input['modules'] ?? []));
$remarks = normalize_system_report_remarks((array) ($input['remarks'] ?? []), $modules);
$period = (string) ($input['period'] ?? 'monthly');
$semester = (int) ($input['semester'] ?? 0);
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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CLINiQ Clinic Transaction Summary</title>
    <style>
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
        .report-preview-page { width: min(210mm, 100%); aspect-ratio: 210 / 297; overflow: hidden; border: 1px solid #d7dfd9; background: #fff; box-shadow: 0 18px 46px rgba(23,38,29,.16); }
        .report-preview-page canvas { display: block; width: 100%; height: 100%; }
        .report-preview-status { grid-column: 1 / -1; color: #526155; font: 700 13px Arial, Helvetica, sans-serif; }
        @media (max-width: 980px) { .print-report { grid-template-columns: minmax(0, 1fr); width: min(210mm, calc(100% - 32px)); } }
        @media print { .print-toolbar { display: none !important; } body.system-report-standalone { padding: 0; background: #fff; } .print-report { display: block; width: auto; margin: 0; } .report-preview-page { width: 210mm; height: 297mm; margin: 0; border: 0; box-shadow: none; break-after: page; } .report-preview-page:last-child { break-after: auto; } .report-preview-status { display: none; } }
    </style>
</head>
<body class="system-report-standalone">
    <header class="print-toolbar">
        <div><strong>PDF Preview</strong><span>Each A4 page below is rendered from the printable PDF.</span></div>
        <div class="print-toolbar-actions">
            <form method="post" action="preview.php"><?= $fields ?><button type="submit">Back to Preview</button></form>
            <button type="button" class="secondary" onclick="window.print()">Print / Save PDF</button>
            <form method="post" action="pdf.php" id="reportExportForm"><?= $fields ?><button type="button" class="secondary" data-report-export-format="pdf">Download PDF</button><button type="button" class="secondary" data-report-export-format="xlsx">Download XLSX</button></form>
        </div>
    </header>
    <main class="print-report">
        <p class="report-preview-status" role="status">Preparing printable A4 pages...</p>
    </main>
    <script src="<?= system_report_escape(app_url('assets/vendor/exceljs/exceljs.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/vendor/apexcharts/apexcharts.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/vendor/jspdf/jspdf.umd.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/vendor/jspdf-autotable/jspdf.plugin.autotable.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/vendor/pdfjs/pdf.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/js/system-report-charts.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-charts.js'))) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/js/system-report-export.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-export.js'))) ?>"></script>
    <script>
        (() => {
            const form = document.getElementById('reportExportForm');
            const pages = document.querySelector('.print-report');
            const status = pages.querySelector('.report-preview-status');
            window.cliniqReportPdfWorkerUrl = <?= json_encode(app_url('assets/vendor/pdfjs/pdf.worker.min.js')) ?>;
            window.cliniqSystemReportExport.previewPdf(form, pages)
                .then(({ pages: count }) => { document.title = `CLINiQ Clinic Transaction Summary (${count} pages)`; })
                .catch((error) => { status.textContent = error instanceof Error ? error.message : 'Unable to render the printable PDF preview.'; pages.replaceChildren(status); });
        })();
    </script>
</body>
</html>
