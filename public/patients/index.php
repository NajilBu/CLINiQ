<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqPatientProfile.php';
require_login();

// ── Search & pagination ─────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$filterType = trim((string) ($_GET['type'] ?? 'all'));
$filterStatus = trim((string) ($_GET['status'] ?? 'all'));
$allowedPatientTypes = ['all', 'Student', 'Faculty', 'Personnel', 'Clinic Staff', 'Patient'];
$allowedAccountStatuses = ['all', 'Active', 'Inactive'];
if (!in_array($filterType, $allowedPatientTypes, true)) {
    $filterType = 'all';
}
if (!in_array($filterStatus, $allowedAccountStatuses, true)) {
    $filterStatus = 'all';
}
$perPage = 10;

$totalRows = cliniq_patient_profile_count();
$patients = cliniq_patient_profile_list('', max(1, $totalRows), 0);
$patients = array_values(array_filter($patients, static function (array $patient) use ($filterType, $filterStatus): bool {
    $type = (string) ($patient['patient_type'] ?? 'Patient');
    if ($type === 'School Personnel') {
        $type = 'Personnel';
    }
    $status = (string) ($patient['account_status'] ?? 'Inactive');
    if ($filterType !== 'all' && $type !== $filterType) {
        return false;
    }
    if ($filterStatus !== 'all' && $status !== $filterStatus) {
        return false;
    }
    return true;
}));
$filteredRows = count($patients);

$patientColumns = [
    ['headerName' => 'No.', 'field' => 'rowNumber', 'width' => 70, 'minWidth' => 70, 'maxWidth' => 70, 'flex' => 0, 'suppressSizeToFit' => true, 'sortable' => false, 'filter' => false],
    ['headerName' => 'ID Number', 'field' => 'idNumber', 'width' => 150],
    ['headerName' => 'Name', 'field' => 'nameHtml', 'cellRenderer' => 'html', 'sortField' => 'nameSort', 'minWidth' => 240],
    ['headerName' => 'Patient Type', 'field' => 'patientType', 'minWidth' => 150],
    ['headerName' => 'Program / Department', 'field' => 'courseSection', 'minWidth' => 190],
    ['headerName' => 'Contact', 'field' => 'guardianContact', 'minWidth' => 170],
];
$patientRows = [];
foreach ($patients as $patientIndex => $patient) {
    $fullName = trim($patient['last_name'] . ', ' . $patient['first_name']);
    $displayName = trim($patient['first_name'] . ' ' . $patient['last_name']);
    $patientRows[] = [
        'rowUrl' => 'view.php?id=' . (int)$patient['id'],
        'rowNumber' => $patientIndex + 1,
        'idNumber' => $patient['id_number'],
        'nameSort' => trim($patient['last_name'] . ' ' . $patient['first_name'] . ' ' . ($patient['middle_name'] ?? '')),
        'nameHtml' => '<div class="flex items-center gap-3" data-tooltip-text="' . e($fullName) . '"><div class="avatar ' . e(avatar_color($displayName)) . '">' . e(initials($displayName)) . '</div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong></div>',
        'patientType' => $patient['patient_type'] === 'School Personnel' ? 'Personnel' : $patient['patient_type'],
        'courseSection' => $patient['course_section'] ?: 'Patient',
        'guardianContact' => $patient['guardian_contact'] ?: '-',
    ];
}

render_header('Patients');
?>

<?php render_clinic_command_header(
    'Patient Registry',
    'Patients',
    $totalRows . ' registered patient(s) in Cliniq_db. Staff profiles contain private health data.'
); ?>

<!-- ═══ Patient Registry ═══ -->
<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <form method="get">
    <!-- Header + Search -->
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Patient Registry</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= $filteredRows ?> registered patient(s) shown. Open a profile to review clinical history.</p>
            </div>
            <div class="flex flex-col sm:flex-row items-center gap-3 w-full md:w-auto">
                <div class="search-input-wrap w-full sm:w-80">
                    <span class="search-icon material-symbols-outlined">search</span>
                    <input id="patientsGridSearch" type="text" name="q" value="<?= e($search) ?>" placeholder="Search name or ID number..." class="search-input">
                </div>
                <button type="button" onclick="showModal('patientAdvancedFilterModal')" class="btn btn-outline w-full sm:w-auto">
                    <span class="material-symbols-outlined text-[18px]">filter_list</span>
                    Filters
                </button>
            </div>
        </div>
    </div>

    <?php if ($filterType !== 'all' || $filterStatus !== 'all'): ?>
        <div class="flex flex-wrap items-center gap-2 px-6 py-4 bg-white border-b border-outline-variant/10">
            <span class="material-symbols-outlined text-slate-400 text-sm">filter_alt</span>
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">Active Filters</span>
            <?php if ($filterType !== 'all'): ?>
                <span class="px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold border border-slate-200"><?= e($filterType) ?></span>
            <?php endif; ?>
            <?php if ($filterStatus !== 'all'): ?>
                <span class="px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold border border-slate-200"><?= e($filterStatus) ?></span>
            <?php endif; ?>
            <a href="index.php" class="ml-auto text-[10px] font-black text-primary uppercase tracking-widest hover:underline text-decoration-none">Clear All</a>
        </div>
    <?php endif; ?>

    <div id="patientAdvancedFilterModal" class="modal-backdrop">
        <div class="modal-content bg-white rounded-[2rem] w-full max-w-2xl p-8 shadow-2xl border border-outline-variant/10">
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-primary-fixed text-primary rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">filter_alt</span>
                    </div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#1c2a59] m-0">Advanced Filters</h3>
                </div>
                <button type="button" onclick="closeModal('patientAdvancedFilterModal')" class="btn-icon btn-icon-slate">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="clinic-label">Patient Type</label>
                    <select class="clinic-select" name="type">
                        <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All</option>
                        <?php foreach (['Student', 'Faculty', 'Personnel', 'Clinic Staff'] as $type): ?>
                            <option value="<?= e($type) ?>" <?= $filterType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Account Status</label>
                    <select class="clinic-select" name="status">
                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="Active" <?= $filterStatus === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $filterStatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 pt-6 mt-6 border-t border-slate-100">
                <a href="index.php" class="btn btn-ghost flex-1 text-decoration-none">Reset All</a>
                <button class="btn btn-primary flex-1">
                    <span class="material-symbols-outlined text-[18px]">check</span>
                    Apply Filters
                </button>
            </div>
        </div>
    </div>
    </form>

    <?php render_ag_grid('patientsGrid', $patientColumns, $patientRows, [
        'searchInput' => 'patientsGridSearch',
        'pageSize' => $perPage,
        'pagination' => true,
        'paginationControls' => 'patientsPagination',
        'rowHeight' => 56,
        'height' => 'patient-registry',
        'emptyTitle' => $search ? 'No patients found' : 'No patients yet',
        'emptyText' => $search ? 'Try a different search term.' : 'Add a patient to get started.',
    ]); ?>

    <nav id="patientsPagination" class="pagination border-t border-slate-100" aria-label="Patient registry pages"></nav>
</section>
<?php render_footer(); ?>
