<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';
require_report_access();

$dateFrom = normalize_system_report_date($_GET['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($_GET['to'] ?? null, date('Y-m-d'));
$report = build_system_report($dateFrom, $dateTo, []);
$adjustQuery = http_build_query([
    'from' => $report['date_from'],
    'to' => $report['date_to'],
    'period' => (string) ($_GET['period'] ?? 'monthly'),
    'semester' => (int) ($_GET['semester'] ?? 0),
]);

set_page_back_link('index.php?' . $adjustQuery, 'Back to Reports');
render_header('Report Preview');
render_clinic_command_header(
    'Reports',
    'Report Preview',
    'Review the clinic transaction summary, add optional remarks, and export the final report.'
);
?>
<link rel="stylesheet" href="<?= e(app_url('assets/css/reports.css?v=' . filemtime(__DIR__ . '/../assets/css/reports.css'))) ?>">
<div class="reports-page report-preview-page">

<section class="report-preview-guidance clinic-card" role="status">
    <span class="material-symbols-outlined" aria-hidden="true">fact_check</span>
    <div><p class="report-preview-guidance-eyebrow">Export workspace</p><h2>Review the clinic transaction summary before exporting</h2><p>The PDF preview and downloaded PDF are the same generated document. XLSX includes the transaction summary and the matching supporting transaction sheets. These controls never modify clinical records.</p></div>
</section>

<form method="post" action="pdf.php" data-no-ajax="true" data-no-loading="true" id="reportExportForm" class="space-y-6 report-preview-export-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="from" value="<?= e($report['date_from']) ?>">
    <input type="hidden" name="to" value="<?= e($report['date_to']) ?>">

    <section class="clinic-card overflow-hidden report-preview-controls"><div class="p-6 report-preview-export-row"><p class="report-preview-table-option"><span><strong>All clinic transaction groups are included in this report.</strong><small>Tables provide the complete summary. Diagrams appear only where they clarify a status or trend.</small></span></p><div class="flex flex-wrap gap-2"><button class="btn btn-outline justify-center" type="submit" formaction="pdf.php">Preview PDF</button><button class="btn btn-outline justify-center" type="button" data-report-export-format="xlsx">Download XLSX</button><button class="btn btn-primary justify-center" type="submit" formaction="download.php">Download PDF</button></div></div></section>

    <style><?= system_report_styles() ?></style>
    <?= render_system_report_document($report, false, ['presentation' => 'preview', 'remarks_mode' => 'input']) ?>
</form>

<script src="<?= e(app_url('assets/vendor/exceljs/exceljs.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/vendor/echarts/echarts.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/system-report-charts.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-charts.js'))) ?>"></script>
<script src="<?= e(app_url('assets/js/system-report-export.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-export.js'))) ?>"></script>
<script>
(() => {
    const dark = document.documentElement.classList.contains('cliniq-dark');
    document.querySelectorAll('[data-report-chart]').forEach((card) => {
        let chart;
        try { chart = JSON.parse(card.dataset.reportChart || '{}'); } catch (_) { return; }
        if (chart.presentation !== 'diagram' || !Array.isArray(chart.rows) || !chart.rows.length) return;
        const mounted = window.cliniqSystemReportCharts?.mount(card.querySelector('.report-chart-visual'), chart, { dark });
        if (!mounted) return;
        new ResizeObserver(() => mounted.chart.resize()).observe(mounted.canvas);
    });
})();
</script>

</div>
<?php render_footer(); ?>
