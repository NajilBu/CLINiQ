<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqPatientProfile.php';
require_login();
$user = current_user() ?? [];

// ── Search & pagination ─────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$filterType = trim((string) ($_GET['type'] ?? 'all'));
$filterStatus = trim((string) ($_GET['status'] ?? 'all'));
$filterSection = strtoupper(trim((string) ($_GET['section'] ?? 'all')));
$filterProgram = strtoupper(trim((string) ($_GET['program'] ?? 'all')));
$filterDepartment = strtoupper(trim((string) ($_GET['department'] ?? 'all')));
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
$sectionOptions = array_values(array_unique(array_filter(array_map(static fn(array $patient): string => strtoupper(trim((string) ($patient['section'] ?? ''))), $patients))));
$programOptions = array_values(array_unique(array_filter(array_map(static fn(array $patient): string => strtoupper(trim((string) ($patient['program_code'] ?? ''))), $patients))));
$departmentOptions = array_values(array_unique(array_filter(array_map(static fn(array $patient): string => strtoupper(trim((string) ($patient['program_department_code'] ?? $patient['employee_department_code'] ?? $patient['staff_department_code'] ?? ''))), $patients))));
sort($sectionOptions, SORT_NATURAL);
sort($programOptions, SORT_NATURAL);
sort($departmentOptions, SORT_NATURAL);
$patients = array_values(array_filter($patients, static function (array $patient) use ($filterType, $filterStatus, $filterSection, $filterProgram, $filterDepartment): bool {
    $type = (string) ($patient['patient_type'] ?? 'Patient');
    if ($type === 'Non-Teaching Personnel') {
        $type = 'Personnel';
    }
    // Clinic staff have their own staff-management context. Keep them out of
    // the default patient registry, but allow the explicit Clinic Staff filter.
    if ($filterType === 'all' && $type === 'Clinic Staff') {
        return false;
    }
    $status = (string) ($patient['account_status'] ?? 'Inactive');
    if ($filterType !== 'all' && $type !== $filterType) {
        return false;
    }
    if ($filterStatus !== 'all' && $status !== $filterStatus) {
        return false;
    }
    if ($filterSection !== 'ALL' && strtoupper(trim((string) ($patient['section'] ?? ''))) !== $filterSection) {
        return false;
    }
    if ($filterProgram !== 'ALL' && strtoupper(trim((string) ($patient['program_code'] ?? ''))) !== $filterProgram) {
        return false;
    }
    $department = strtoupper(trim((string) ($patient['program_department_code'] ?? $patient['employee_department_code'] ?? $patient['staff_department_code'] ?? '')));
    if ($filterDepartment !== 'ALL' && $department !== $filterDepartment) {
        return false;
    }
    return true;
}));
$filteredRows = count($patients);
$totalRows = $filteredRows;

$patientColumns = [
    ['headerName' => 'No.', 'field' => 'rowNumber', 'width' => 70, 'minWidth' => 70, 'maxWidth' => 70, 'flex' => 0, 'suppressSizeToFit' => true, 'sortable' => false, 'filter' => false],
    ['headerName' => 'ID Number', 'field' => 'idNumber', 'width' => 150],
    ['headerName' => '', 'field' => 'sexIconHtml', 'cellRenderer' => 'html', 'width' => 52, 'minWidth' => 52, 'maxWidth' => 52, 'flex' => 0, 'suppressSizeToFit' => true, 'sortable' => false, 'filter' => false, 'headerClass' => 'patient-sex-indicator-header', 'cellClass' => 'patient-sex-indicator-cell'],
    ['headerName' => 'Name', 'field' => 'nameHtml', 'cellRenderer' => 'html', 'sortField' => 'nameSort', 'minWidth' => 240],
    ['headerName' => 'Patient Type', 'field' => 'patientType', 'minWidth' => 150],
    ['headerName' => 'Program / Department', 'field' => 'courseSection', 'minWidth' => 190],
    ['headerName' => 'Contact', 'field' => 'guardianContact', 'minWidth' => 170],
];
$patientRows = [];
foreach ($patients as $patientIndex => $patient) {
    $fullName = trim($patient['last_name'] . ', ' . $patient['first_name']);
    $displayName = trim($patient['first_name'] . ' ' . $patient['last_name']);
    $profilePhotoPath = profile_photo_normalize_path($patient['profile_photo_path'] ?? null);
    $avatarHtml = '<div class="avatar ' . e(avatar_color($displayName)) . '">';
    if ($profilePhotoPath !== null) {
        $avatarHtml .= '<img class="avatar-photo" src="' . e(app_url($profilePhotoPath)) . '" alt="' . e($displayName) . ' profile picture">';
    } else {
        $avatarHtml .= e(initials($displayName));
    }
    $avatarHtml .= '</div>';
    $sex = strtolower(trim((string) ($patient['sex'] ?? '')));
    $sexIconHtml = match ($sex) {
        'female', 'f' => '<span class="material-symbols-outlined patient-sex-icon patient-sex-icon-female" aria-label="Female" title="Female">female</span>',
        'male', 'm' => '<span class="material-symbols-outlined patient-sex-icon patient-sex-icon-male" aria-label="Male" title="Male">male</span>',
        default => '',
    };
    $patientRows[] = [
        'rowUrl' => 'view.php?id=' . (int)$patient['id'],
        'rowNumber' => $patientIndex + 1,
        'idNumber' => $patient['id_number'],
        'sexIconHtml' => $sexIconHtml,
        'nameSort' => trim($patient['last_name'] . ' ' . $patient['first_name'] . ' ' . ($patient['middle_name'] ?? '')),
        'nameHtml' => '<div class="flex items-center gap-3" data-tooltip-text="' . e($fullName) . '">' . $avatarHtml . '<strong class="text-sm text-slate-800">' . e($fullName) . '</strong></div>',
        'patientType' => $patient['patient_type'] === 'Non-Teaching Personnel' ? 'NTP' : $patient['patient_type'],
        'courseSection' => $patient['course_section'] ?: 'Patient',
        'guardianContact' => $patient['guardian_contact'] ?: '-',
    ];
}

render_header('Patients');
?>

<?php render_clinic_command_header(
    'Patient Registry',
    'Patients',
    $totalRows . ' registered patient(s) in Cliniq_db. Staff profiles contain private health data.',
    ($user['role'] ?? '') === 'admin'
        ? '<a href="' . e(app_url('patient-accounts/index.php')) . '" class="btn btn-primary text-decoration-none"><span class="material-symbols-outlined">manage_accounts</span> Patient Accounts</a>'
        : ''
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
                <div id="patientSectionFilterField" <?= $filterType === 'Student' || $filterType === 'all' ? '' : 'hidden' ?> >
                    <label class="clinic-label">Section</label>
                    <select class="clinic-select" name="section" id="patientSectionFilter">
                        <option value="all" <?= $filterSection === 'ALL' ? 'selected' : '' ?>>All sections</option>
                        <?php foreach ($sectionOptions as $section): ?><option value="<?= e($section) ?>" <?= $filterSection === $section ? 'selected' : '' ?>><?= e($section) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div id="patientProgramFilterField" <?= $filterType === 'Faculty' || $filterType === 'Personnel' || $filterType === 'Clinic Staff' ? 'hidden' : '' ?> >
                    <label class="clinic-label">Program</label>
                    <select class="clinic-select" name="program" id="patientProgramFilter"><option value="all">All programs</option><?php foreach ($programOptions as $program): ?><option value="<?= e($program) ?>" <?= $filterProgram === $program ? 'selected' : '' ?>><?= e($program) ?></option><?php endforeach; ?></select>
                </div>
                <div id="patientDepartmentFilterField" <?= $filterType === 'Student' ? 'hidden' : '' ?> >
                    <label class="clinic-label">Department</label>
                    <select class="clinic-select" name="department" id="patientDepartmentFilter"><option value="all">All departments</option><?php foreach ($departmentOptions as $department): ?><option value="<?= e($department) ?>" <?= $filterDepartment === $department ? 'selected' : '' ?>><?= e($department) ?></option><?php endforeach; ?></select>
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

    <script>
        (() => {
            const type = document.querySelector('#patientAdvancedFilterModal select[name="type"]');
            const sectionField = document.getElementById('patientSectionFilterField');
            const section = document.getElementById('patientSectionFilter');
            const programField = document.getElementById('patientProgramFilterField');
            const departmentField = document.getElementById('patientDepartmentFilterField');
            const program = document.getElementById('patientProgramFilter');
            const department = document.getElementById('patientDepartmentFilter');
            if (!type || !sectionField || !section || !programField || !departmentField || !program || !department) return;
            const sync = () => {
                const isStudent = type.value === 'Student' || type.value === 'all';
                sectionField.hidden = !isStudent;
                programField.hidden = !isStudent;
                departmentField.hidden = type.value === 'Student';
                if (!isStudent) section.value = 'all';
                if (!isStudent) program.value = 'all';
                if (type.value === 'Student') department.value = 'all';
            };
            type.addEventListener('change', sync);
            sync();
        })();
    </script>

    <?php render_ag_grid('patientsGrid', $patientColumns, $patientRows, [
        'searchInput' => 'patientsGridSearch',
        'normalizeStudentIdSearch' => true,
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
