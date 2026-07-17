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

$where = 'a.status = ?';
$params = [$filterStatus];

$alerts = db()->prepare("SELECT a.*, p.first_name, p.last_name FROM nurse_alerts a LEFT JOIN patients p ON p.id = a.patient_id WHERE {$where} ORDER BY a.created_at DESC LIMIT 100");
$alerts->execute($params);
$alerts = $alerts->fetchAll();

// Status counts
$statusCountQuery = db()->query("SELECT status, COUNT(*) AS cnt FROM nurse_alerts GROUP BY status");
$statusCounts = [];
foreach ($statusCountQuery->fetchAll() as $sc) {
    $statusCounts[strtolower($sc['status'])] = (int)$sc['cnt'];
}

$alertColumns = [
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'width' => 150],
    ['headerName' => 'Risk', 'field' => 'riskHtml', 'cellRenderer' => 'html', 'width' => 130],
    ['headerName' => 'Patient', 'field' => 'patient', 'width' => 180],
    ['headerName' => 'Reporter', 'field' => 'reporterHtml', 'cellRenderer' => 'html', 'minWidth' => 190],
    ['headerName' => 'Location', 'field' => 'location'],
    ['headerName' => 'Concern', 'field' => 'concernHtml', 'cellRenderer' => 'html', 'minWidth' => 240],
    ['headerName' => 'Created', 'field' => 'created', 'width' => 150],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 220],
];
$alertRows = [];
foreach ($alerts as $alert) {
    $patientName = trim(($alert['first_name'] ?? '') . ' ' . ($alert['last_name'] ?? ''));
    $riskLevel = $alert['risk_level'] ?? 'Low';
    $riskScore = (int) ($alert['risk_score'] ?? 0);
    $actions = '';
    if ($alert['status'] === 'Pending') {
        $actions = '<div class="flex justify-end gap-2">'
            . '<form method="post" action="update.php"><input type="hidden" name="id" value="' . (int)$alert['id'] . '"><input type="hidden" name="status" value="In Progress"><button type="submit" class="btn btn-sm btn-outline" title="Acknowledge" data-confirm-submit data-confirm-type="primary" data-confirm-title="Acknowledge this alert?" data-confirm-message="This will move the alert to In Progress so staff can handle it." data-confirm-toast="Acknowledging alert..."><span class="material-symbols-outlined text-[14px]">play_arrow</span> Ack</button></form>'
            . '<form method="post" action="update.php"><input type="hidden" name="id" value="' . (int)$alert['id'] . '"><input type="hidden" name="status" value="Cancelled"><button type="submit" class="btn btn-sm btn-ghost btn-cancel-icon" title="Cancel alert" aria-label="Cancel alert" data-confirm-submit data-confirm-type="danger" data-confirm-title="Cancel this alert?" data-confirm-message="This will mark the alert as Cancelled and remove it from the active queue." data-confirm-toast="Cancelling alert..."><span class="material-symbols-outlined text-[14px]">cancel</span></button></form>'
            . '</div>';
    } elseif ($alert['status'] === 'In Progress') {
        $actions = '<div class="flex justify-end gap-2">'
            . '<a href="view.php?id=' . (int)$alert['id'] . '" class="btn btn-sm btn-primary text-decoration-none"><span class="material-symbols-outlined text-[14px]">assignment</span> Report</a>'
            . '<form method="post" action="update.php"><input type="hidden" name="id" value="' . (int)$alert['id'] . '"><input type="hidden" name="status" value="Cancelled"><button type="submit" class="btn btn-sm btn-ghost btn-cancel-icon" title="Cancel alert" aria-label="Cancel alert" data-confirm-submit data-confirm-type="danger" data-confirm-title="Cancel this alert?" data-confirm-message="This will mark the alert as Cancelled and remove it from the active queue." data-confirm-toast="Cancelling alert..."><span class="material-symbols-outlined text-[14px]">cancel</span></button></form>'
            . '</div>';
    } else {
        $actions = '';
    }

    $alertRows[] = [
        'rowUrl' => 'view.php?id=' . (int)$alert['id'],
        'statusHtml' => '<span class="badge ' . e(status_badge_class($alert['status'])) . '">' . e($alert['status']) . '</span>',
        'riskHtml' => '<span class="badge ' . e(risk_badge_class($riskLevel)) . '">' . e($riskLevel) . '</span><p class="text-[10px] font-bold text-slate-400 mb-0 mt-1">Score ' . $riskScore . '</p>',
        'patient' => $patientName !== '' ? $patientName : 'Unlisted',
        'reporterHtml' => '<p class="text-sm font-bold text-slate-600 mb-0">' . e($alert['reporter_name']) . '</p>' . ($alert['reporter_role'] ? '<p class="text-xs font-bold text-slate-400 mb-0">' . e($alert['reporter_role']) . '</p>' : ''),
        'location' => $alert['location'],
        'concernHtml' => '<a href="view.php?id=' . (int)$alert['id'] . '" class="block text-decoration-none"><p class="text-sm font-bold text-slate-800 mb-0">' . e($alert['concern']) . '</p>' . (!empty($alert['incident_type']) ? '<p class="text-[10px] font-black text-red-500 uppercase tracking-widest mt-0.5 mb-0">' . e($alert['incident_type']) . '</p>' : '') . ($alert['report_answers'] ? '<p class="text-xs font-bold text-slate-400 mt-0.5 mb-0 truncate">' . e($alert['report_answers']) . '</p>' : ($alert['details'] ? '<p class="text-xs font-bold text-slate-400 mt-0.5 mb-0 truncate">' . e($alert['details']) . '</p>' : '')) . '</a>',
        'created' => date('M d, g:i A', strtotime($alert['created_at'])),
        'actionsHtml' => $actions,
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
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4"><div><h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Alert Queue</h2><p class="text-xs font-bold text-slate-500 mb-0">Newest reports appear first.</p></div><div class="search-input-wrap w-full md:w-auto shrink-0" style="min-width: 280px;"><span class="search-icon material-symbols-outlined">search</span><input id="alertsGridSearch" type="text" placeholder="Search alerts..." class="search-input"></div></div>

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

    <?php render_ag_grid('alertsGrid', $alertColumns, $alertRows, [
        'searchInput' => 'alertsGridSearch',
        'emptyTitle' => 'No alerts found',
        'emptyText' => 'No alerts with this status.',
    ]); ?>
</section>
<?php render_footer(); ?>
