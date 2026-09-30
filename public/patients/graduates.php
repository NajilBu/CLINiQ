<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/GraduationService.php';
require_login();

$batchInput = trim((string) ($_GET['batch_year'] ?? ''));
$batchYear = preg_match('/^\d{4}$/', $batchInput) ? (int) $batchInput : null;
$search = trim((string) ($_GET['q'] ?? ''));
$batchOptions = graduation_batch_years();
$graduates = graduation_list($batchYear);
$columns = [
    ['headerName' => 'No.', 'field' => 'rowNumber', 'width' => 70, 'minWidth' => 70, 'maxWidth' => 70, 'flex' => 0, 'suppressSizeToFit' => true, 'sortable' => false, 'filter' => false],
    ['headerName' => 'ID Number', 'field' => 'idNumber', 'width' => 150],
    ['headerName' => 'Student', 'field' => 'studentHtml', 'cellRenderer' => 'html', 'sortField' => 'studentName', 'minWidth' => 240],
    ['headerName' => 'Program', 'field' => 'program', 'minWidth' => 130],
    ['headerName' => 'Batch', 'field' => 'batchYear', 'width' => 115],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'sortField' => 'status', 'minWidth' => 205],
    ['headerName' => 'Cleared On', 'field' => 'clearedOn', 'minWidth' => 155],
];
$rows = [];
foreach ($graduates as $index => $graduate) {
    $name = (string) $graduate['student_name'];
    $status = (string) $graduate['status'];
    $rows[] = [
        'rowUrl' => 'view.php?id=' . (int) $graduate['student_person_id'],
        'rowNumber' => $index + 1,
        'idNumber' => (string) $graduate['id_number'],
        'studentName' => $name,
        'studentHtml' => '<div class="flex items-center gap-3"><span class="avatar ' . e(avatar_color($name)) . '">' . e(initials($name)) . '</span><strong class="text-sm text-slate-800">' . e($name) . '</strong></div>',
        'program' => (string) ($graduate['program_code'] ?: '—'),
        'batchYear' => (int) $graduate['batch_year'],
        'status' => $status,
        'statusHtml' => '<span class="badge ' . ($status === 'Graduated' ? 'badge-completed' : 'badge-in-progress') . '">' . e($status) . '</span>',
        'clearedOn' => $graduate['cleared_at'] ? date('M j, Y', strtotime((string) $graduate['cleared_at'])) : '—',
    ];
}

set_page_back_link('index.php', 'Patients');
render_header('Graduates');
render_clinic_command_header('Patient Registry', 'Graduates', 'Fourth-year students cleared for graduation and students marked graduated during the school-year cycle.');
?>
<section class="clinic-card overflow-hidden">
    <form method="get">
        <div class="p-6 border-b border-slate-100">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Graduation List</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0"><?= count($graduates) ?> student(s) shown. Clearance and graduation are separate decisions.</p>
                </div>
                <div class="flex flex-col sm:flex-row items-center gap-3 w-full md:w-auto">
                    <div class="search-input-wrap w-full sm:w-80">
                        <span class="search-icon material-symbols-outlined">search</span>
                        <input id="graduatesGridSearch" type="search" name="q" value="<?= e($search) ?>" placeholder="Search name or ID number..." class="search-input" aria-label="Search graduates">
                    </div>
                    <button type="button" onclick="showModal('graduateFilterModal')" class="btn btn-outline w-full sm:w-auto">
                        <span class="material-symbols-outlined text-[18px]">filter_list</span> Filters
                    </button>
                </div>
            </div>
        </div>
        <div id="graduateFilterModal" class="modal-backdrop">
            <div class="modal-content bg-white rounded-[1.5rem] w-full max-w-lg p-8 shadow-2xl border border-outline-variant/10">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-[var(--cliniq-surface-low)] text-primary rounded-xl flex items-center justify-center"><span class="material-symbols-outlined">filter_alt</span></div>
                        <h3 class="font-headline text-2xl font-extrabold text-[#17261d] m-0">Advanced Filters</h3>
                    </div>
                    <button type="button" onclick="closeModal('graduateFilterModal')" class="btn-icon btn-icon-slate" aria-label="Close filters"><span class="material-symbols-outlined">close</span></button>
                </div>
                <label class="clinic-label" for="graduateBatchYear">Batch year / school year</label>
                <select class="clinic-select mt-1" id="graduateBatchYear" name="batch_year">
                    <option value="">All batches</option>
                    <?php foreach ($batchOptions as $year): ?>
                        <option value="<?= (int) $year ?>" <?= $batchYear === $year ? 'selected' : '' ?>>Batch <?= (int) $year ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="flex flex-col sm:flex-row gap-3 pt-6 mt-6 border-t border-slate-100">
                    <a href="graduates.php" class="btn btn-ghost flex-1 text-decoration-none">Reset All</a>
                    <button type="submit" class="btn btn-primary flex-1"><span class="material-symbols-outlined text-[18px]">check</span> Apply Filters</button>
                </div>
            </div>
        </div>
    </form>
    <?php render_ag_grid('graduatesGrid', $columns, $rows, [
        'searchInput' => 'graduatesGridSearch',
        'normalizeStudentIdSearch' => true,
        'pageSize' => 10,
        'pagination' => true,
        'paginationControls' => 'graduatesPagination',
        'rowHeight' => 56,
        'height' => 'patient-registry',
        'emptyTitle' => 'No graduates found',
        'emptyText' => 'Try another batch year or search term.',
    ]); ?>
    <nav id="graduatesPagination" class="pagination border-t border-slate-100" aria-label="Graduate list pages"></nav>
</section>
<?php render_footer(); ?>
