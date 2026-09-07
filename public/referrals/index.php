<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$where = '1=1';
$params = [];
if ($dateFrom !== '') {
    $where .= ' AND DATE(r.referral_date) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= ' AND DATE(r.referral_date) <= ?';
    $params[] = $dateTo;
}

$stmt = auth_db()->prepare("
    SELECT r.*, pe.first_name, pe.last_name, pe.id_number
    FROM referrals r
    JOIN patients pt ON pt.person_id = r.patient_person_id
    JOIN people pe ON pe.id = pt.person_id
    WHERE {$where}
    ORDER BY r.referral_date DESC, r.referral_id DESC
");
$stmt->execute($params);
$referrals = $stmt->fetchAll();

$referralColumns = [
    ['headerName' => 'Patient', 'field' => 'patientHtml', 'cellRenderer' => 'html', 'sortField' => 'patientSort', 'minWidth' => 240],
    ['headerName' => 'Referred To', 'field' => 'referredTo', 'minWidth' => 200],
    ['headerName' => 'Reason', 'field' => 'reason', 'minWidth' => 240],
    ['headerName' => 'Date', 'field' => 'date', 'sortField' => 'dateSort', 'sortType' => 'date', 'width' => 150],
];
$referralRows = [];
foreach ($referrals as $ref) {
    $fullName = trim($ref['first_name'] . ' ' . $ref['last_name']);
    $referralRows[] = [
        'rowModalId' => 'referralDetails-' . (int)$ref['referral_id'],
        'patientSort' => trim($ref['last_name'] . ' ' . $ref['first_name']),
        'patientHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-xs font-bold text-slate-400">' . e($ref['id_number']) . '</div></div></div>',
        'referredTo' => $ref['referred_to'],
        'reason' => $ref['reason'],
        'date' => date('M d, Y', strtotime($ref['referral_date'])),
        'dateSort' => $ref['referral_date'],
    ];
}

render_header('Referrals');

render_clinic_command_header(
    'External Care',
    'Referrals',
    'Track patient referrals to external facilities and specialists.',
    '<a class="btn btn-primary text-decoration-none" href="create.php"><span class="material-symbols-outlined text-[20px]">send</span>New Referral</a>'
);
?>

<section class="clinic-card overflow-hidden">
    <form method="get">
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Referral Records</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= count($referrals) ?> referral(s)</p>
            </div>
            <div class="flex flex-col sm:flex-row items-center gap-3 w-full md:w-auto">
                <div class="search-input-wrap w-full sm:w-80">
                    <span class="search-icon material-symbols-outlined">search</span>
                    <input id="referralsGridSearch" type="text" placeholder="Search referrals..." class="search-input">
                </div>
                <button type="button" onclick="showModal('referralAdvancedFilterModal')" class="btn btn-outline w-full sm:w-auto">
                    <span class="material-symbols-outlined text-[18px]">filter_list</span>
                    Filters
                </button>
            </div>
        </div>

        
    </div>
    <div id="referralAdvancedFilterModal" class="modal-backdrop">
        <div class="modal-content bg-white rounded-[2rem] w-full max-w-2xl p-8 shadow-2xl border border-outline-variant/10">
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-primary-fixed text-primary rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">filter_alt</span>
                    </div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#1c2a59] m-0">Advanced Filters</h3>
                </div>
                <button type="button" onclick="closeModal('referralAdvancedFilterModal')" class="btn-icon btn-icon-slate">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                
                <div>
                    <label class="clinic-label">Date From</label>
                    <input class="clinic-input" type="date" name="date_from" value="<?= e($dateFrom) ?>">
                </div>
                <div>
                    <label class="clinic-label">Date To</label>
                    <input class="clinic-input" type="date" name="date_to" value="<?= e($dateTo) ?>">
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
    <?php render_ag_grid('referralsGrid', $referralColumns, $referralRows, [
        'searchInput' => 'referralsGridSearch',
        'pageSize' => 10,
        'pagination' => true,
        'paginationControls' => 'referralsPagination',
        'emptyTitle' => 'No referrals',
        'emptyText' => 'Create a referral record to track external patient transfers.',
    ]); ?>
    <nav id="referralsPagination" class="pagination" aria-label="Referral pages"></nav>
</section>
<?php foreach ($referrals as $ref):
    $referralModalId = 'referralDetails-' . (int) $ref['referral_id'];
?>
<div id="<?= e($referralModalId) ?>" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="<?= e($referralModalId) ?>-title">
    <div class="modal-content bg-white rounded-2xl w-full max-w-2xl p-6 md:p-8 shadow-2xl">
        <div class="flex items-center justify-between gap-4 mb-6">
            <h2 id="<?= e($referralModalId) ?>-title" class="font-headline text-2xl font-extrabold m-0">Referral Details</h2>
            <button type="button" class="btn-icon btn-icon-slate" onclick="closeModal('<?= e($referralModalId) ?>')" aria-label="Close referral details"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div><span class="clinic-label">Patient</span><strong><?= e(trim($ref['first_name'] . ' ' . $ref['last_name'])) ?></strong><p class="text-sm text-slate-500 mt-1"><?= e($ref['id_number']) ?></p></div>
            <div><span class="clinic-label">Referral Date</span><strong><?= e(date('M d, Y', strtotime($ref['referral_date']))) ?></strong></div>
            <div class="md:col-span-2"><span class="clinic-label">Referred To</span><p class="m-0" style="overflow-wrap:anywhere"><?= e($ref['referred_to']) ?></p></div>
            <div class="md:col-span-2"><span class="clinic-label">Reason</span><p class="m-0" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($ref['reason']) ?></p></div>
            <?php if (trim((string) ($ref['notes'] ?? '')) !== ''): ?>
                <div class="md:col-span-2"><span class="clinic-label">Notes</span><p class="m-0" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($ref['notes']) ?></p></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php render_footer(); ?>
