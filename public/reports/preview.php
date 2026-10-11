<?php

require_once __DIR__ . '/../../app/helpers/view.php';
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
$adjustQuery = http_build_query([
    'from' => $report['date_from'],
    'to' => $report['date_to'],
    'period' => $period,
    'semester' => $semester,
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
<link rel="stylesheet" href="<?= e(app_url('assets/css/clinic-command.css?v=' . filemtime(__DIR__ . '/../assets/css/clinic-command.css'))) ?>">

<section class="report-preview-guidance clinic-card" role="status">
    <span class="material-symbols-outlined" aria-hidden="true">fact_check</span>
    <div><p class="report-preview-guidance-eyebrow">Export workspace</p><h2>Review the clinic transaction summary before exporting</h2><p>The printable PDF uses the same report layout shown below. XLSX includes the matching section data. These controls never modify clinical records.</p></div>
</section>

<form method="post" action="pdf.php" data-no-ajax="true" data-no-loading="true" id="reportExportForm" class="space-y-6 report-preview-export-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="from" value="<?= e($report['date_from']) ?>">
    <input type="hidden" name="to" value="<?= e($report['date_to']) ?>">
    <input type="hidden" name="period" value="<?= e($period) ?>">
    <input type="hidden" name="semester" value="<?= e((string) $semester) ?>">
    <?php foreach ($modules as $module): ?>
        <input type="hidden" name="modules[]" value="<?= e($module) ?>">
    <?php endforeach; ?>

    <section class="clinic-card overflow-hidden report-preview-controls"><div class="p-6 report-preview-export-row"><p class="report-preview-table-option"><span><strong>All selected clinic transaction groups are included in this report.</strong><small>Tables provide the complete summary. Diagrams appear only where they clarify a status or trend.</small></span></p><div class="flex flex-wrap gap-2"><button class="btn btn-outline justify-center" type="submit" formaction="pdf.php">Preview PDF</button><button class="btn btn-outline justify-center" type="button" data-report-export-format="xlsx">Download XLSX</button><button class="btn btn-primary justify-center" type="submit" formaction="pdf.php">Print / Save PDF</button></div></div></section>

    <style><?= system_report_styles() ?></style>
    <?= render_system_report_document($report, false, ['presentation' => 'preview', 'remarks_mode' => 'input', 'remarks' => $remarks]) ?>
</form>

<script src="<?= e(app_url('assets/vendor/exceljs/exceljs.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/vendor/apexcharts/apexcharts.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/vendor/jspdf/jspdf.umd.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/vendor/jspdf-autotable/jspdf.plugin.autotable.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/system-report-charts.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-charts.js'))) ?>"></script>
<script src="<?= e(app_url('assets/js/system-report-export.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-export.js'))) ?>"></script>
<script>
(() => {
    const form = document.getElementById('reportExportForm');
    const dark = document.documentElement.classList.contains('cliniq-dark');
    document.querySelectorAll('[data-report-chart]').forEach((card) => {
        let chart;
        try { chart = JSON.parse(card.dataset.reportChart || '{}'); } catch (_) { return; }
        if (chart.presentation !== 'diagram' || !Array.isArray(chart.rows) || !chart.rows.length) return;
        const mounted = window.cliniqSystemReportCharts?.mount(card.querySelector('.report-chart-visual'), chart, { dark });
        if (!mounted) return;
        new ResizeObserver(() => mounted.resize()).observe(mounted.canvas);
    });
    form.querySelectorAll('textarea[name^="remarks["]').forEach((textarea) => textarea.addEventListener('input', () => {
        form.__systemReportPayload = null;
    }));
})();
</script>

<?php render_footer(); ?>
