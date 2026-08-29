<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/SystemReport.php';
require_once __DIR__ . '/../../app/services/SystemReportRenderer.php';
require_login();

$dateFrom = normalize_system_report_date($_GET['from'] ?? null, date('Y-m-01'));
$dateTo = normalize_system_report_date($_GET['to'] ?? null, date('Y-m-d'));
$modules = normalize_system_report_modules((array) ($_GET['modules'] ?? []));
$moduleLabels = system_report_module_labels();
$report = build_system_report($dateFrom, $dateTo, array_keys($moduleLabels));
$adjustQuery = http_build_query(['from' => $report['date_from'], 'to' => $report['date_to']]);

render_header('Report Preview');
render_clinic_command_header(
    'Reports',
    'Report Preview',
    'Choose the sections to export, add optional remarks, and review the final report.',
    '<a class="btn btn-outline text-decoration-none" data-no-ajax="true" href="index.php?' . e($adjustQuery) . '"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Back to Reports</a>'
);
?>

<div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-5 py-4 text-sm font-bold text-emerald-800 flex items-start gap-3">
    <span class="material-symbols-outlined text-[20px]">visibility</span>
    <p class="m-0">The checklist controls which report sections are included in the printable PDF view. The checklist itself is never included in the report.</p>
</div>

<form method="post" action="pdf.php" data-no-ajax="true" id="reportExportForm" class="space-y-6">
    <input type="hidden" name="from" value="<?= e($report['date_from']) ?>">
    <input type="hidden" name="to" value="<?= e($report['date_to']) ?>">

    <section class="clinic-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Sections Included in PDF</h2>
                <p class="text-xs font-bold text-slate-500 mb-0">Uncheck a section to hide it from this preview and exclude it from the printable report.</p>
            </div>
            <span class="badge badge-in-progress shrink-0" id="selectedModuleCount"><?= count($modules) ?> selected</span>
        </div>
        <div class="p-6 space-y-5">
            <div class="flex justify-end gap-4">
                <button class="text-xs font-extrabold text-primary bg-transparent border-0 p-0" type="button" id="selectAllReportModules">Select all</button>
                <button class="text-xs font-extrabold text-slate-500 bg-transparent border-0 p-0" type="button" id="clearReportModules">Clear all</button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                <?php foreach ($moduleLabels as $moduleKey => $moduleLabel): ?>
                    <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 cursor-pointer hover:border-primary/40 transition-colors">
                        <input class="w-4 h-4 accent-[var(--cliniq-primary)]" type="checkbox" name="modules[]" value="<?= e($moduleKey) ?>" <?= in_array($moduleKey, $modules, true) ? 'checked' : '' ?>>
                        <span class="text-sm font-extrabold text-slate-700"><?= e($moduleLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-bold text-red-700" id="reportModuleError" role="alert">Select at least one section before exporting.</p>
            <div class="flex justify-end">
                <button class="btn btn-primary justify-center" type="submit" id="exportReportPdf"><span class="material-symbols-outlined text-[18px]">print</span>Open Printable PDF View</button>
            </div>
        </div>
    </section>

    <style><?= system_report_styles() ?></style>
    <?= render_system_report_document($report, false, ['remarks_mode' => 'input']) ?>
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

<?php render_footer(); ?>
