<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';
require_report_access();

$dateFrom = normalize_system_report_date($_GET['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($_GET['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($_GET['modules'] ?? []));
$moduleLabels = system_report_module_labels();
$report = build_system_report($dateFrom, $dateTo, array_keys($moduleLabels));
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
    'Choose the sections to export, add optional remarks, and review the final report.'
);
?>
<link rel="stylesheet" href="<?= e(app_url('assets/css/reports.css?v=' . filemtime(__DIR__ . '/../assets/css/reports.css'))) ?>">
<div class="reports-page report-preview-page">

<section class="report-preview-guidance clinic-card" role="status">
    <span class="material-symbols-outlined" aria-hidden="true">fact_check</span>
    <div><p class="report-preview-guidance-eyebrow">Export workspace</p><h2>Review the exact report content before exporting</h2><p>Both downloads use the selected sections, metrics, charts, tables, and remarks shown below. PDF changes the page layout; XLSX provides a metric summary in Overview and full detail in each module worksheet. These controls never modify clinical records.</p></div>
</section>

<form method="post" action="pdf.php" data-no-ajax="true" id="reportExportForm" class="space-y-6 report-preview-export-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="from" value="<?= e($report['date_from']) ?>">
    <input type="hidden" name="to" value="<?= e($report['date_to']) ?>">

    <section class="clinic-card overflow-hidden report-preview-controls">
        <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <p class="report-preview-guidance-eyebrow">Step 1</p><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Choose report sections</h2>
                <p class="text-xs font-bold text-slate-500 mb-0">Uncheck a section to hide it from the live preview and the exported report.</p>
            </div>
            <span class="badge badge-in-progress shrink-0" id="selectedModuleCount"><?= count($modules) ?> selected</span>
        </div>
        <div class="p-6 space-y-5">
            <div class="flex justify-end gap-4">
                <button class="report-helper-action" type="button" id="selectAllReportModules">Select all</button>
                <button class="report-helper-action secondary" type="button" id="clearReportModules">Clear all</button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                <?php foreach ($moduleLabels as $moduleKey => $moduleLabel): ?>
                    <label class="report-module-card">
                        <input class="w-4 h-4 accent-[var(--cliniq-primary)]" type="checkbox" name="modules[]" value="<?= e($moduleKey) ?>" <?= in_array($moduleKey, $modules, true) ? 'checked' : '' ?>>
                        <span><?= e($moduleLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="report-module-error hidden" id="reportModuleError" role="alert">Select at least one section before exporting.</p>
            <input type="hidden" name="include_tables" value="1">
            <div class="report-preview-export-row"><p class="report-preview-table-option"><span><strong>Charts and complete data tables are included in every format.</strong><small>PDF uses report pages. XLSX uses an Overview worksheet and one worksheet for each selected module.</small></span></p><div class="flex flex-wrap gap-2"><button class="btn btn-outline justify-center" type="submit" id="exportReportPdf">Printable view</button><button class="btn btn-outline justify-center" type="button" data-report-export-format="xlsx">Download XLSX</button><button class="btn btn-primary justify-center" type="button" data-report-export-format="pdf">Download PDF</button></div></div>
        </div>
    </section>

    <style><?= system_report_styles() ?></style>
    <?= render_system_report_document($report, false, ['presentation' => 'preview', 'remarks_mode' => 'input']) ?>
</form>

<script>
(() => {
    const form = document.getElementById('reportExportForm');
    const checkboxes = [...form.querySelectorAll('input[name="modules[]"]')];
    const countBadge = document.getElementById('selectedModuleCount');
    const error = document.getElementById('reportModuleError');
    const exportButton = document.getElementById('exportReportPdf');
    const syncSections = () => {
        let selected = 0;
        checkboxes.forEach((checkbox) => {
            const section = form.querySelector(`[data-report-section="${checkbox.value}"]`);
            if (section) section.hidden = !checkbox.checked;
            if (checkbox.checked) {
                selected++;
                const number = section ? section.querySelector('.report-section-number') : null;
                if (number) number.textContent = selected;
            }
        });
        countBadge.textContent = `${selected} selected`;
        const coverCount = form.querySelector('[data-report-module-total]');
        if (coverCount) coverCount.textContent = `${selected} module(s)`;
        exportButton.disabled = selected === 0;
        if (selected > 0) error.classList.add('hidden');
    };
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', syncSections));
    document.getElementById('selectAllReportModules').addEventListener('click', () => {
        checkboxes.forEach((checkbox) => { checkbox.checked = true; });
        syncSections();
    });
    document.getElementById('clearReportModules').addEventListener('click', () => {
        checkboxes.forEach((checkbox) => { checkbox.checked = false; });
        syncSections();
        error.classList.remove('hidden');
    });
    form.addEventListener('submit', (event) => {
        if (!checkboxes.some((checkbox) => checkbox.checked)) {
            event.preventDefault();
            error.classList.remove('hidden');
        }
    });
    syncSections();
})();
</script>
<script src="<?= e(app_url('assets/vendor/jspdf/jspdf.umd.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/vendor/jspdf-autotable/jspdf.plugin.autotable.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/vendor/exceljs/exceljs.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/system-report-export.js?v=' . filemtime(__DIR__ . '/../assets/js/system-report-export.js'))) ?>"></script>

</div>
<?php render_footer(); ?>
