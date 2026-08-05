<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqPatientProfile.php';
require_login();

// ── Search & pagination ─────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$perPage = 15;

$totalRows = cliniq_patient_profile_count();
$patients = cliniq_patient_profile_list('', max(1, $totalRows), 0);

$patientColumns = [
    ['headerName' => 'No.', 'field' => 'rowNumber', 'width' => 70, 'minWidth' => 70, 'maxWidth' => 70, 'flex' => 0, 'suppressSizeToFit' => true, 'sortable' => false, 'filter' => false],
    ['headerName' => 'ID Number', 'field' => 'idNumber', 'width' => 150],
    ['headerName' => 'Name', 'field' => 'nameHtml', 'cellRenderer' => 'html', 'minWidth' => 240],
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
        'nameHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($displayName)) . '">' . e(initials($displayName)) . '</div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong></div>',
        'patientType' => $patient['patient_type'],
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
    <!-- Header + Search -->
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Patient Registry</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= $totalRows ?> registered patient(s) in Cliniq_db. Open a profile to review clinical history.</p>
            </div>
            <div class="search-input-wrap" style="max-width: 280px;">
                <span class="search-icon material-symbols-outlined">search</span>
                <input id="patientsGridSearch" type="text" name="q" value="<?= e($search) ?>" placeholder="Search name or ID number..." class="search-input">
            </div>
        </div>
    </div>

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
