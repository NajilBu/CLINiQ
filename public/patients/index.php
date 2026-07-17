<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

// ── Search & pagination ─────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Count total
if ($search !== '') {
    $countStmt = db()->prepare("SELECT COUNT(*) AS total FROM patients WHERE first_name LIKE ? OR last_name LIKE ? OR student_number LIKE ?");
    $like = "%{$search}%";
    $countStmt->execute([$like, $like, $like]);
} else {
    $countStmt = db()->query("SELECT COUNT(*) AS total FROM patients");
}
$totalRows = (int)$countStmt->fetch()['total'];
$totalPages = max(1, ceil($totalRows / $perPage));

// Fetch page
if ($search !== '') {
    $stmt = db()->prepare("SELECT * FROM patients WHERE first_name LIKE ? OR last_name LIKE ? OR student_number LIKE ? ORDER BY last_name, first_name LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute([$like, $like, $like]);
} else {
    $stmt = db()->prepare("SELECT * FROM patients ORDER BY last_name, first_name LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute();
}
$patients = $stmt->fetchAll();

$patientColumns = [
    ['headerName' => 'Student No.', 'field' => 'studentNumber', 'width' => 150],
    ['headerName' => 'Name', 'field' => 'nameHtml', 'cellRenderer' => 'html', 'minWidth' => 240],
    ['headerName' => 'Course/Section', 'field' => 'courseSection', 'minWidth' => 190],
    ['headerName' => 'Guardian Contact', 'field' => 'guardianContact', 'minWidth' => 180],
    ['headerName' => 'QR', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 120],
];
$patientRows = [];
foreach ($patients as $patient) {
    $fullName = trim($patient['last_name'] . ', ' . $patient['first_name']);
    $displayName = trim($patient['first_name'] . ' ' . $patient['last_name']);
    $patientRows[] = [
        'rowUrl' => 'view.php?id=' . (int)$patient['id'],
        'studentNumber' => $patient['student_number'],
        'nameHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($displayName)) . '">' . e(initials($displayName)) . '</div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong></div>',
        'courseSection' => $patient['course_section'],
        'guardianContact' => $patient['guardian_contact'] ?: '-',
        'actionsHtml' => '<a class="btn btn-sm btn-outline text-decoration-none" href="' . e(app_url('emergency.php?token=' . $patient['emergency_token'])) . '" target="_blank"><span class="material-symbols-outlined text-[14px]">qr_code_2</span> QR</a>',
    ];
}

render_header('Patients');
?>

<?php render_clinic_command_header(
    'Patient Registry',
    'Patients',
    $totalRows . ' registered patient(s). Public QR page is for reporting only; staff profile contains private health data.',
    '<a class="btn btn-primary text-decoration-none" href="' . e(app_url('patients/create.php')) . '"><span class="material-symbols-outlined text-[20px]">person_add</span>Add Patient</a>'
); ?>

<!-- ═══ Patient Registry ═══ -->
<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <!-- Header + Search -->
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Patient Registry</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= $totalRows ?> registered patient(s). Public QR page is for reporting only; staff profile contains private health data.</p>
            </div>
            <form method="get" class="search-input-wrap" style="max-width: 280px;">
                <span class="search-icon material-symbols-outlined">search</span>
                <input id="patientsGridSearch" type="text" name="q" value="<?= e($search) ?>" placeholder="Search name or student no..." class="search-input">
            </form>
        </div>
    </div>

    <?php render_ag_grid('patientsGrid', $patientColumns, $patientRows, [
        'searchInput' => 'patientsGridSearch',
        'emptyTitle' => $search ? 'No patients found' : 'No patients yet',
        'emptyText' => $search ? 'Try a different search term.' : 'Add a patient to get started.',
    ]); ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
                <span class="material-symbols-outlined text-[18px]">chevron_left</span>
            </a>
        <?php else: ?>
            <span class="page-disabled"><span class="material-symbols-outlined text-[18px]">chevron_left</span></span>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="page-active"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?><?= $search ? '&q=' . urlencode($search) : '' ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
                <span class="material-symbols-outlined text-[18px]">chevron_right</span>
            </a>
        <?php else: ?>
            <span class="page-disabled"><span class="material-symbols-outlined text-[18px]">chevron_right</span></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
