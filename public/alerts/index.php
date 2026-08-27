<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AlertWorkflow.php';
require_login();
ensure_alert_workflow_schema();

$allowedStatuses = [
    'pending' => 'Pending',
    'in progress' => 'In Progress',
    'resolved' => 'Resolved',
    'cancelled' => 'Cancelled',
];
$filterKey = strtolower(trim($_GET['status'] ?? 'pending'));
$filterStatus = $allowedStatuses[$filterKey] ?? 'Pending';
$allowedRisks = ['all', 'Critical', 'High', 'Moderate', 'Low'];
$filterRisk = trim((string) ($_GET['risk'] ?? 'all'));
if (!in_array($filterRisk, $allowedRisks, true)) {
    $filterRisk = 'all';
}
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$where = 'a.status = ?';
$params = [$filterStatus];
if ($filterRisk !== 'all') {
    $where .= ' AND a.risk_level = ?';
    $params[] = $filterRisk;
}
if ($dateFrom !== '') {
    $where .= ' AND DATE(a.created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= ' AND DATE(a.created_at) <= ?';
    $params[] = $dateTo;
}

$alerts = auth_db()->prepare("
    SELECT a.*, pe.first_name, pe.last_name
    FROM nurse_alerts a
    LEFT JOIN patients pt ON pt.person_id = a.patient_id
    LEFT JOIN people pe ON pe.id = pt.person_id
    WHERE {$where}
    ORDER BY a.created_at DESC
    LIMIT 100
");
$alerts->execute($params);
$alerts = $alerts->fetchAll();

// Status counts
$statusCountQuery = auth_db()->query("SELECT status, COUNT(*) AS cnt FROM nurse_alerts GROUP BY status");
$statusCounts = [];
foreach ($statusCountQuery->fetchAll() as $sc) {
    $statusCounts[strtolower($sc['status'])] = (int)$sc['cnt'];
}

$alertColumns = [
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'sortField' => 'statusSort', 'sortType' => 'number', 'width' => 150],
    ['headerName' => 'Risk', 'field' => 'riskHtml', 'cellRenderer' => 'html', 'sortField' => 'riskSort', 'sortType' => 'number', 'width' => 130],
    ['headerName' => 'Patient', 'field' => 'patient', 'width' => 180],
    ['headerName' => 'Reporter', 'field' => 'reporterHtml', 'cellRenderer' => 'html', 'sortField' => 'reporterSort', 'minWidth' => 190],
    ['headerName' => 'Location', 'field' => 'location'],
    ['headerName' => 'Concern', 'field' => 'concernHtml', 'cellRenderer' => 'html', 'sortField' => 'concernSort', 'minWidth' => 240],
    ['headerName' => 'Created', 'field' => 'created', 'sortField' => 'createdSort', 'sortType' => 'date', 'width' => 150],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 100, 'minWidth' => 90],
];
$alertRows = [];
foreach ($alerts as $alert) {
    $patientName = trim(($alert['first_name'] ?? '') . ' ' . ($alert['last_name'] ?? ''));
    $riskLevel = $alert['risk_level'] ?? 'Low';
    $riskScore = (int) ($alert['risk_score'] ?? 0);
    $actions = '';
    if ($alert['status'] === 'Pending') {
        $actions = '<div class="row-actions-list">'
            . '<form method="post" action="update.php"><input type="hidden" name="id" value="' . (int)$alert['id'] . '"><input type="hidden" name="status" value="In Progress"><button type="submit" class="btn btn-sm btn-outline" title="Acknowledge" data-confirm-submit data-confirm-type="primary" data-confirm-title="Acknowledge this alert?" data-confirm-message="This will move the alert to In Progress so staff can handle it." data-confirm-toast="Acknowledging alert..."><span class="material-symbols-outlined text-[14px]">play_arrow</span> Ack</button></form>'
            . '<form method="post" action="update.php"><input type="hidden" name="id" value="' . (int)$alert['id'] . '"><input type="hidden" name="status" value="Cancelled"><button type="submit" class="btn btn-sm btn-ghost" title="Cancel alert" aria-label="Cancel alert" data-confirm-submit data-confirm-type="danger" data-confirm-title="Cancel this alert?" data-confirm-message="This will mark the alert as Cancelled and remove it from the active queue." data-confirm-toast="Cancelling alert..."><span class="material-symbols-outlined text-[14px]">cancel</span> Cancel</button></form>'
            . '</div>';
    } elseif ($alert['status'] === 'In Progress') {
        $actions = '<div class="row-actions-list">'
            . '<a href="view.php?id=' . (int)$alert['id'] . '" class="btn btn-sm btn-primary text-decoration-none"><span class="material-symbols-outlined text-[14px]">assignment</span> Report</a>'
            . '<form method="post" action="update.php"><input type="hidden" name="id" value="' . (int)$alert['id'] . '"><input type="hidden" name="status" value="Cancelled"><button type="submit" class="btn btn-sm btn-ghost" title="Cancel alert" aria-label="Cancel alert" data-confirm-submit data-confirm-type="danger" data-confirm-title="Cancel this alert?" data-confirm-message="This will mark the alert as Cancelled and remove it from the active queue." data-confirm-toast="Cancelling alert..."><span class="material-symbols-outlined text-[14px]">cancel</span> Cancel</button></form>'
            . '</div>';
    } else {
        $actions = '';
    }

    $alertRows[] = [
        'rowUrl' => 'view.php?id=' . (int)$alert['id'],
        'statusSort' => array_search($alert['status'], ['Pending', 'In Progress', 'Resolved', 'Cancelled'], true),
        'statusHtml' => '<span class="badge ' . e(status_badge_class($alert['status'])) . '">' . e($alert['status']) . '</span>',
        'riskSort' => array_search($riskLevel, ['Critical', 'High', 'Moderate', 'Low'], true),
        'riskHtml' => '<span class="badge ' . e(risk_badge_class($riskLevel)) . '">' . e($riskLevel) . '</span><p class="text-[10px] font-bold text-slate-400 mb-0 mt-1">Score ' . $riskScore . '</p>',
        'patient' => $patientName !== '' ? $patientName : 'Unlisted',
        'reporterSort' => $alert['reporter_name'],
        'reporterHtml' => '<p class="text-sm font-bold text-slate-600 mb-0">' . e($alert['reporter_name']) . '</p>' . ($alert['reporter_role'] ? '<p class="text-xs font-bold text-slate-400 mb-0">' . e($alert['reporter_role']) . '</p>' : ''),
        'location' => $alert['location'],
        'concernSort' => $alert['concern'],
        'concernHtml' => '<a href="view.php?id=' . (int)$alert['id'] . '" class="block text-decoration-none"><p class="text-sm font-bold text-slate-800 mb-0">' . e($alert['concern']) . '</p>' . (!empty($alert['incident_type']) ? '<p class="text-[10px] font-black text-red-500 uppercase tracking-widest mt-0.5 mb-0">' . e($alert['incident_type']) . '</p>' : '') . ($alert['report_answers'] ? '<p class="text-xs font-bold text-slate-400 mt-0.5 mb-0 truncate">' . e($alert['report_answers']) . '</p>' : ($alert['details'] ? '<p class="text-xs font-bold text-slate-400 mt-0.5 mb-0 truncate">' . e($alert['details']) . '</p>' : '')) . '</a>',
        'created' => date('M d, g:i A', strtotime($alert['created_at'])),
        'createdSort' => $alert['created_at'],
        'actionsHtml' => row_actions_button('Alert actions', $actions),
    ];
}

render_header('Nurse Alerts');

render_clinic_command_header(
    'Emergency',
    'Nurse Alerts',
    'Live emergency reports from staff and QR/NFC scans.',
    '<div class="flex items-center gap-2 text-xs font-semibold text-slate-400 bg-slate-100/50 px-3 py-1.5 rounded-full border border-slate-200/50"><span class="material-symbols-outlined text-[14px]">sync</span>Auto-refreshing every 5s</div><a class="btn btn-danger text-decoration-none" href="create.php"><span class="material-symbols-outlined text-[20px]">emergency_home</span>Submit Alert</a>'
);
?>

<!-- Alert Queue -->
<section class="clinic-card overflow-hidden">
    <form method="get">
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Alert Queue</h2>
                <p class="text-xs font-bold text-slate-500 mb-0">Newest reports appear first.</p>
            </div>
            <div class="flex flex-col sm:flex-row items-center gap-3 w-full md:w-auto">
                <div class="search-input-wrap w-full sm:w-80">
                    <span class="search-icon material-symbols-outlined">search</span>
                    <input id="alertsGridSearch" type="text" placeholder="Search alerts..." class="search-input">
                </div>
                <button type="button" onclick="showModal('alertAdvancedFilterModal')" class="btn btn-outline w-full sm:w-auto">
                    <span class="material-symbols-outlined text-[18px]">filter_list</span>
                    Filters
                </button>
            </div>
        </div>

        <!-- Status Filter Tabs -->
        <div class="flex items-center gap-2 mt-4 border-t border-slate-100 pt-4 overflow-x-auto scrollbar-hide">
            <?php
            $statusTabs = [
                'pending' => 'Pending',
                'in progress' => 'In Progress',
                'resolved' => 'Resolved',
                'cancelled' => 'Cancelled',
            ];
            foreach ($statusTabs as $key => $label):
                $isActive = strtolower($filterStatus) === $key;
                $count = $statusCounts[$key] ?? 0;
                $href = $key === 'pending' ? '?' : '?status=' . urlencode($key);
            ?>
                <a href="<?= $href ?>" class="status-tab <?= $isActive ? 'active' : '' ?> text-decoration-none">
                    <?= $label ?>
                    <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $isActive ? 'bg-blue-100 text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= $count ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($filterKey !== 'pending' || $filterRisk !== 'all' || $dateFrom !== '' || $dateTo !== ''): ?>
        <div class="flex flex-wrap items-center gap-2 px-6 py-4 bg-white border-b border-outline-variant/10">
            <span class="material-symbols-outlined text-slate-400 text-sm">filter_alt</span>
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">Active Filters</span>
            <?php if ($filterKey !== 'pending'): ?>
                <span class="px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold border border-slate-200"><?= e($filterStatus) ?></span>
            <?php endif; ?>
            <?php if ($filterRisk !== 'all'): ?>
                <span class="px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold border border-slate-200"><?= e($filterRisk) ?> Risk</span>
            <?php endif; ?>
            <?php if ($dateFrom !== ''): ?>
                <span class="px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold border border-slate-200">From <?= e($dateFrom) ?></span>
            <?php endif; ?>
            <?php if ($dateTo !== ''): ?>
                <span class="px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold border border-slate-200">To <?= e($dateTo) ?></span>
            <?php endif; ?>
            <a href="index.php" class="ml-auto text-[10px] font-black text-primary uppercase tracking-widest hover:underline text-decoration-none">Clear All</a>
        </div>
    <?php endif; ?>

    <div id="alertAdvancedFilterModal" class="modal-backdrop">
        <div class="modal-content bg-white rounded-[2rem] w-full max-w-2xl p-8 shadow-2xl border border-outline-variant/10">
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-primary-fixed text-primary rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">filter_alt</span>
                    </div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#1c2a59] m-0">Advanced Filters</h3>
                </div>
                <button type="button" onclick="closeModal('alertAdvancedFilterModal')" class="btn-icon btn-icon-slate">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="clinic-label">Status</label>
                    <select class="clinic-select" name="status">
                        <?php foreach ($allowedStatuses as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $filterKey === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Risk</label>
                    <select class="clinic-select" name="risk">
                        <option value="all" <?= $filterRisk === 'all' ? 'selected' : '' ?>>All</option>
                        <?php foreach (['Critical', 'High', 'Moderate', 'Low'] as $risk): ?>
                            <option value="<?= e($risk) ?>" <?= $filterRisk === $risk ? 'selected' : '' ?>><?= e($risk) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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

    <?php render_ag_grid('alertsGrid', $alertColumns, $alertRows, [
        'searchInput' => 'alertsGridSearch',
        'emptyTitle' => 'No alerts found',
        'emptyText' => 'No alerts with this status.',
    ]); ?>
</section>
<?php render_footer(); ?>
