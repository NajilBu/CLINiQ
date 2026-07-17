<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$filterStatus = $_GET['status'] ?? 'all';

$where = '1=1';
$params = [];
if ($filterStatus !== 'all') {
    $where = 'r.status = ?';
    $params[] = $filterStatus;
}

$stmt = db()->prepare("
    SELECT r.*, p.first_name, p.last_name, p.student_number
    FROM referrals r
    JOIN patients p ON p.id = r.patient_id
    WHERE {$where}
    ORDER BY r.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$referrals = $stmt->fetchAll();

$statusCounts = ['all' => 0];
$countQuery = db()->query("SELECT status, COUNT(*) AS cnt FROM referrals GROUP BY status");
foreach ($countQuery->fetchAll() as $sc) {
    $statusCounts[strtolower($sc['status'])] = (int)$sc['cnt'];
    $statusCounts['all'] += (int)$sc['cnt'];
}

$referralColumns = [
    ['headerName' => 'Patient', 'field' => 'patientHtml', 'cellRenderer' => 'html', 'minWidth' => 240],
    ['headerName' => 'Referred To', 'field' => 'referredTo', 'minWidth' => 200],
    ['headerName' => 'Reason', 'field' => 'reason', 'minWidth' => 240],
    ['headerName' => 'Date', 'field' => 'date', 'width' => 150],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'width' => 150],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 170],
];
$referralRows = [];
foreach ($referrals as $ref) {
    $fullName = trim($ref['first_name'] . ' ' . $ref['last_name']);
    $actions = '';
    if ($ref['status'] === 'Pending') {
        $actions = '<form method="post" action="update.php" style="display:inline;"><input type="hidden" name="id" value="' . (int)$ref['id'] . '"><input type="hidden" name="status" value="Completed"><button class="btn btn-sm btn-primary" title="Mark Completed" data-confirm-submit data-confirm-type="primary" data-confirm-title="Complete this referral?" data-confirm-message="This will mark the referral as Completed." data-confirm-toast="Completing referral..."><span class="material-symbols-outlined text-[14px]">check</span> Complete</button></form>';
    }
    $referralRows[] = [
        'rowUrl' => app_url('patients/view.php?id=' . (int)$ref['patient_id']),
        'patientHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-xs font-bold text-slate-400">' . e($ref['student_number']) . '</div></div></div>',
        'referredTo' => $ref['referred_to'],
        'reason' => $ref['reason'],
        'date' => date('M d, Y', strtotime($ref['referral_date'])),
        'statusHtml' => '<span class="badge ' . e(status_badge_class($ref['status'])) . '">' . e($ref['status']) . '</span>',
        'actionsHtml' => $actions,
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
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Referral Records</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= $statusCounts['all'] ?> referral(s)</p>
            </div>
            <div class="search-input-wrap w-full md:w-auto shrink-0" style="min-width: 280px;">
                <span class="search-icon material-symbols-outlined">search</span>
                <input id="referralsGridSearch" type="text" placeholder="Search referrals..." class="search-input">
            </div>
        </div>

        <div class="flex items-center gap-2 mt-4 border-t border-slate-100 pt-4 overflow-x-auto scrollbar-hide">
            <?php
            $tabs = ['all' => 'All', 'pending' => 'Pending', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
            foreach ($tabs as $key => $label):
                $isActive = $filterStatus === $key;
                $count = $statusCounts[$key] ?? 0;
                $href = $key === 'all' ? '?' : '?status=' . urlencode($key);
            ?>
                <a href="<?= $href ?>" class="status-tab <?= $isActive ? 'active' : '' ?> text-decoration-none whitespace-nowrap">
                    <?= $label ?>
                    <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $isActive ? 'bg-blue-100 text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= $count ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php render_ag_grid('referralsGrid', $referralColumns, $referralRows, [
        'searchInput' => 'referralsGridSearch',
        'emptyTitle' => 'No referrals',
        'emptyText' => 'Create a referral record to track external patient transfers.',
    ]); ?>
</section>
<?php render_footer(); ?>
