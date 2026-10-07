<?php

require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';
require_report_access();

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$dateFrom = normalize_system_report_date($input['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($input['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($input['modules'] ?? []));
$remarks = normalize_system_report_remarks((array) ($input['remarks'] ?? []), $modules);
$includeTables = ($input['include_tables'] ?? '') === '1';
$backQuery = http_build_query(['from' => $dateFrom, 'to' => $dateTo, 'modules' => $modules]);
$fields = '<input type="hidden" name="_csrf" value="' . system_report_escape(csrf_token()) . '">'
    . '<input type="hidden" name="from" value="' . system_report_escape($dateFrom) . '">'
    . '<input type="hidden" name="to" value="' . system_report_escape($dateTo) . '">';
foreach ($modules as $module) {
    $fields .= '<input type="hidden" name="modules[]" value="' . system_report_escape((string) $module) . '">';
}
foreach ($remarks as $module => $remark) {
    $fields .= '<input type="hidden" name="remarks[' . system_report_escape((string) $module) . ']" value="' . system_report_escape((string) $remark) . '">';
}
if ($includeTables) {
    $fields .= '<input type="hidden" name="include_tables" value="1">';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CLINiQ System Analytics Report</title>
    <style>
        * { box-sizing: border-box; }
        body.system-report-standalone { margin: 0; background: #e8efeb; padding-bottom: 36px; }
        .print-toolbar { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 18px; background: rgba(255,255,255,.94); border-bottom: 1px solid #dfe9e2; box-shadow: 0 10px 24px rgba(23,38,29,.08); font-family: Arial, Helvetica, sans-serif; }
        .print-toolbar strong { display: block; color: #17261d; font-size: 14px; }
        .print-toolbar span { display: block; margin-top: 2px; color: #64748b; font-size: 11px; font-weight: 700; }
        .print-toolbar-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .print-toolbar-actions form { display: contents; }
        .print-toolbar-actions .floating-back { position: fixed; top: 4.5rem; right: 1rem; z-index: 21; box-shadow: 0 8px 24px rgba(23,38,29,.16); }
        .print-toolbar button, .print-toolbar a { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 0 15px; border: 1px solid #cfded3; border-radius: 999px; background: #fff; color: #205f3d; font: 900 12px Arial, Helvetica, sans-serif; text-decoration: none; cursor: pointer; }
        .print-toolbar button.primary { border-color: #2f8553; background: #2f8553; color: #fff; }
        .print-toolbar button.secondary { border-color: #7bd19b; background: #eaf7ef; color: #14532d; }
        .print-toolbar button[aria-pressed="true"] { border-color: #205f3d; background: #205f3d; color: #fff; }
        .print-toolbar button:disabled { cursor: wait; opacity: .7; }
        .report-preview-status { width: min(1780px, calc(100vw - 32px)); min-height: 18px; margin: 16px auto -10px; color: #526155; font: 700 11px Arial, Helvetica, sans-serif; }
        .report-preview-status.error { color: #b42318; }
        .report-document { display: block; width: min(210mm, calc(100vw - 48px)); margin: 0 auto; padding: 26px 0; }
        .report-preview-page { width: 210mm; min-height: 297mm; margin: 0 auto 26px; background: #fff; box-shadow: 0 18px 46px rgba(15,23,42,.16); }
        .report-preview-page canvas { display: block; width: 100%; height: auto; }
        .system-report-standalone.report-preview-multi-page .report-document { display: grid; grid-template-columns: repeat(auto-fit, minmax(31rem, 1fr)); align-items: start; gap: 26px; width: min(1780px, calc(100vw - 32px)); }
        .system-report-standalone.report-preview-multi-page .report-preview-page { margin: 0 auto; zoom: .68; }
        @media (max-width: 900px) { .system-report-standalone.report-preview-multi-page .report-document { display: block; width: 100%; padding: 0; } .system-report-standalone.report-preview-multi-page .report-preview-page { width: min(210mm, calc(100vw - 32px)); min-height: auto; margin: 20px auto; zoom: 1; } .report-preview-status { width: calc(100vw - 32px); margin: 14px auto 0; } }
        @media print { .print-toolbar, .report-preview-status { display: none !important; } body.system-report-standalone { padding: 0; background: #fff; } .report-document, .system-report-standalone.report-preview-multi-page .report-document { display: block; width: auto; padding: 0; } .report-preview-page, .system-report-standalone.report-preview-multi-page .report-preview-page { width: auto; min-height: 0; margin: 0; box-shadow: none; zoom: 1; break-after: page; page-break-after: always; } }
    </style>
</head>
<body class="system-report-standalone report-preview-multi-page">
    <header class="print-toolbar">
        <div><strong>PDF Preview</strong><span id="reportPreviewHint">Preparing the exact export pages…</span></div>
        <div class="print-toolbar-actions">
            <a class="floating-back" href="preview.php?<?= system_report_escape($backQuery) ?>">Back to Preview</a>
            <button type="button" id="reportPreviewLayoutToggle" aria-pressed="true">Single Page View</button>
            <button type="button" class="secondary" onclick="window.print()">Print</button>
            <form method="post" action="pdf.php" data-no-ajax="true" id="reportExportForm"><?= $fields ?><button type="button" class="secondary" data-report-export-format="xlsx">Download XLSX</button><button type="button" class="primary" data-report-export-format="pdf">Download PDF</button></form>
        </div>
    </header>
    <p class="report-preview-status" id="reportPreviewStatus" role="status">Preparing the PDF preview…</p>
    <main class="report-document" id="reportPdfPages" aria-label="PDF report pages"></main>
    <script>window.cliniqReportPdfWorkerUrl = <?= json_encode(app_url('assets/vendor/pdfjs/pdf.worker.min.js')) ?>;</script>
    <script src="<?= system_report_escape(app_url('assets/vendor/pdfjs/pdf.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/vendor/jspdf/jspdf.umd.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/vendor/jspdf-autotable/jspdf.plugin.autotable.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/vendor/exceljs/exceljs.min.js')) ?>"></script>
    <script src="<?= system_report_escape(app_url('assets/js/system-report-export.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-export.js'))) ?>"></script>
    <script>
        (() => {
            const form = document.getElementById('reportExportForm');
            const pages = document.getElementById('reportPdfPages');
            const status = document.getElementById('reportPreviewStatus');
            const hint = document.getElementById('reportPreviewHint');
            const toggle = document.getElementById('reportPreviewLayoutToggle');
            const sync = () => { const multiple = document.body.classList.contains('report-preview-multi-page'); toggle.setAttribute('aria-pressed', multiple ? 'true' : 'false'); toggle.textContent = multiple ? 'Single Page View' : 'Multiple Pages View'; };
            toggle.addEventListener('click', () => { document.body.classList.toggle('report-preview-multi-page'); sync(); });
            sync();
            window.cliniqSystemReportExport.previewPdf(form, pages)
                .then(({ pages: count }) => { hint.textContent = `${count} pages shown from the exact downloadable PDF.`; status.textContent = 'Ready. The displayed pages and Download PDF use the same generated document.'; })
                .catch(() => { hint.textContent = 'PDF preview could not be prepared.'; status.textContent = 'Unable to prepare the PDF preview. Refresh the page and try again.'; status.classList.add('error'); });
        })();
    </script>
</body>
</html>
