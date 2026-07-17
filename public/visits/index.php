<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/VisitWorkflow.php';
require_login();
ensure_visit_workflow_schema();

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'status' => $_GET['status'] ?? 'Unaddressed',
    'purpose' => $_GET['purpose'] ?? 'all',
    'staff' => $_GET['staff'] ?? 'all',
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? ''),
];

if ($filters['status'] !== 'all' && !in_array($filters['status'], visit_statuses(), true)) {
    $filters['status'] = 'all';
}
if ($filters['purpose'] !== 'all' && $filters['purpose'] === '') {
    $filters['purpose'] = 'all';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
    $filters['date_from'] = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
    $filters['date_to'] = '';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$buildWhere = function (bool $includeStatus = true) use ($filters): array {
    $where = ['1=1'];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR p.student_number LIKE ? OR v.chief_complaint LIKE ? OR v.symptoms LIKE ? OR v.action_taken LIKE ? OR v.visit_purpose LIKE ?)";
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    if ($includeStatus && $filters['status'] !== 'all') {
        $where[] = 'v.status = ?';
        $params[] = $filters['status'];
    }

    if ($filters['purpose'] !== 'all') {
        $where[] = 'v.visit_purpose = ?';
        $params[] = $filters['purpose'];
    }

    if ($filters['staff'] !== 'all') {
        $where[] = 'v.attended_by = ?';
        $params[] = (int) $filters['staff'];
    }

    if ($filters['date_from'] !== '') {
        $where[] = 'DATE(v.visit_datetime) >= ?';
        $params[] = $filters['date_from'];
    }

    if ($filters['date_to'] !== '') {
        $where[] = 'DATE(v.visit_datetime) <= ?';
        $params[] = $filters['date_to'];
    }

    return [implode(' AND ', $where), $params];
};

[$whereSQL, $params] = $buildWhere();

$countStmt = db()->prepare("SELECT COUNT(*) AS total FROM clinic_visits v JOIN patients p ON p.id = v.patient_id WHERE {$whereSQL}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetch()['total'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$stmt = db()->prepare("
    SELECT v.*, p.first_name, p.last_name, p.student_number, p.course_section, au.name AS attended_by_name
    FROM clinic_visits v
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users au ON au.id = v.attended_by
    WHERE {$whereSQL}
    ORDER BY CASE COALESCE(v.status, 'Unaddressed')
        WHEN 'Unaddressed' THEN 0
        WHEN 'Active' THEN 1
        WHEN 'Cancelled' THEN 2
        WHEN 'Completed' THEN 3
        ELSE 4
    END, v.visit_datetime DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$visits = $stmt->fetchAll();

[$statusWhereSQL, $statusParams] = $buildWhere(false);
$statusCounts = ['all' => 0, 'Unaddressed' => 0, 'Active' => 0, 'Completed' => 0, 'Cancelled' => 0];
$statusCountQuery = db()->prepare("
    SELECT v.status, COUNT(*) AS cnt
    FROM clinic_visits v
    JOIN patients p ON p.id = v.patient_id
    WHERE {$statusWhereSQL}
    GROUP BY v.status
");
$statusCountQuery->execute($statusParams);
foreach ($statusCountQuery->fetchAll() as $row) {
    $status = $row['status'] ?: 'Unaddressed';
    $statusCounts[$status] = (int) $row['cnt'];
    $statusCounts['all'] += (int) $row['cnt'];
}

$staffMembers = db()->query("
    SELECT DISTINCT u.id, u.name
    FROM users u
    JOIN clinic_visits v ON v.attended_by = u.id
    ORDER BY u.name
")->fetchAll();

$purposeRows = db()->query("
    SELECT DISTINCT visit_purpose
    FROM clinic_visits
    WHERE visit_purpose IS NOT NULL AND visit_purpose <> ''
    ORDER BY visit_purpose
")->fetchAll();
$purposeOptions = array_values(array_unique(array_merge(visit_purposes(), array_column($purposeRows, 'visit_purpose'))));

$baseParams = array_filter($filters, fn ($value) => $value !== '' && $value !== 'all');
$paginationParams = $baseParams;

$activeChips = [];
$chipParams = $baseParams;
foreach ([
    'status' => 'Status',
    'purpose' => 'Purpose',
    'staff' => 'Attended By',
    'date_from' => 'From',
    'date_to' => 'To',
    'q' => 'Search',
] as $key => $label) {
    if (!isset($baseParams[$key])) {
        continue;
    }
    $value = $baseParams[$key];
    if ($key === 'staff') {
        foreach ($staffMembers as $staff) {
            if ((string) $staff['id'] === (string) $value) {
                $value = $staff['name'];
                break;
            }
        }
    }
    $remove = $chipParams;
    unset($remove[$key]);
    $activeChips[] = ['label' => $label . ': ' . $value, 'href' => '?' . http_build_query($remove)];
}

$visitColumns = [
    ['headerName' => 'Date/Time', 'field' => 'dateTimeHtml', 'cellRenderer' => 'html', 'width' => 150],
    ['headerName' => 'Patient', 'field' => 'patientHtml', 'cellRenderer' => 'html', 'minWidth' => 230],
    ['headerName' => 'Complaint', 'field' => 'complaint', 'minWidth' => 210],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'width' => 145],
    ['headerName' => 'Attended By', 'field' => 'attendedBy', 'minWidth' => 165],
    ['headerName' => 'Open', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 210],
];

$visitRows = [];
foreach ($visits as $visit) {
    $fullName = trim($visit['first_name'] . ' ' . $visit['last_name']);
    $visitStatus = $visit['status'] ?? 'Unaddressed';
    $openLabel = match ($visitStatus) {
        'Unaddressed' => 'Address',
        'Active' => 'Treat',
        default => '',
    };
    $actionUrl = app_url('visits/view.php?id=' . (int) $visit['id'] . '&from=logbook' . ($visitStatus === 'Unaddressed' ? '&begin=1' : ''));
    $actionParts = [];
    if ($openLabel !== '') {
        $actionParts[] = '<a href="' . e($actionUrl) . '" class="btn btn-sm btn-ghost text-decoration-none"><span class="material-symbols-outlined text-[14px]">medical_services</span>' . e($openLabel) . '</a>';
    }
    if ($visitStatus === 'Unaddressed') {
        $actionParts[] = '<form method="post" action="' . e(app_url('visits/view.php?id=' . (int) $visit['id'] . '&from=logbook')) . '" style="margin:0;">'
            . '<input type="hidden" name="mode" value="no_show">'
            . '<input type="hidden" name="from" value="logbook">'
            . '<input type="hidden" name="return_to" value="index">'
            . '<button class="btn btn-sm btn-ghost btn-cancel-icon" title="Mark no show" aria-label="Mark no show" data-confirm-submit data-confirm-type="danger" data-confirm-title="Mark as no show?" data-confirm-message="This will cancel the unaddressed logbook entry because the patient did not proceed to the nurse station." data-confirm-toast="Marking no show..."><span class="material-symbols-outlined text-[14px]">cancel</span></button>'
            . '</form>';
    }
    $actionsHtml = $actionParts ? '<div class="flex items-center gap-2">' . implode('', $actionParts) . '</div>' : '';

    $visitRows[] = [
        'rowUrl' => $actionUrl,
        'dateTimeHtml' => '<p class="text-sm font-bold text-slate-700 mb-0">' . e(date('M d, Y', strtotime($visit['visit_datetime']))) . '</p><p class="text-xs font-bold text-slate-400 mb-0">' . e(date('g:i A', strtotime($visit['visit_datetime']))) . '</p>',
        'patientHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-xs font-bold text-slate-400">' . e($visit['student_number']) . '</div></div></div>',
        'complaint' => $visit['chief_complaint'],
        'statusHtml' => '<span class="badge ' . e(visit_status_badge_class($visitStatus)) . '">' . e($visitStatus) . '</span>',
        'attendedBy' => $visit['attended_by_name'] ?: 'Not yet attended',
        'actionsHtml' => $actionsHtml,
    ];
}

render_header('Clinic Visits');

render_clinic_command_header(
    'Clinic Logbook',
    'Visits',
    'Review clinic visits, open treatment records, and log emergency cases.',
    '<a class="btn btn-primary text-decoration-none justify-center" href="' . e(app_url('visits/create.php')) . '"><span class="material-symbols-outlined text-[20px]">add_notes</span>Manual Record Visit</a>'
);
?>

<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <form id="visitFilterForm" method="get">
        <div class="p-6 border-b border-slate-100">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Clinic Logbook</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Unaddressed and active visits appear first. <?= $totalRows ?> visit record(s).</p>
                </div>
                <div class="flex flex-col sm:flex-row items-center gap-3 w-full md:w-auto">
                    <div class="search-input-wrap w-full sm:w-80">
                        <span class="search-icon material-symbols-outlined">search</span>
                        <input id="visitsGridSearch" type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="Search name, ID, complaint..." class="search-input">
                    </div>
                    <button type="button" onclick="showModal('advancedFilterModal')" class="btn btn-outline w-full sm:w-auto">
                        <span class="material-symbols-outlined text-[18px]">filter_list</span>
                        Filters
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-2 mt-4 border-t border-slate-100 pt-4 overflow-x-auto scrollbar-hide">
                <?php
                $statusTabs = ['Unaddressed' => 'Unaddressed', 'Active' => 'Active', 'Completed' => 'Completed', 'Cancelled' => 'Cancelled'];
                foreach ($statusTabs as $key => $label):
                    $isActive = $filters['status'] === $key;
                    $hrefParams = $baseParams;
                    if ($key === 'all') {
                        unset($hrefParams['status']);
                    } else {
                        $hrefParams['status'] = $key;
                    }
                    unset($hrefParams['page']);
                    $count = $statusCounts[$key] ?? 0;
                ?>
                    <a href="?<?= http_build_query($hrefParams) ?>" class="status-tab <?= $isActive ? 'active' : '' ?> text-decoration-none">
                        <?= e($label) ?>
                        <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $isActive ? 'bg-blue-100 text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= (int) $count ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($activeChips): ?>
            <div class="flex flex-wrap items-center gap-2 px-6 py-4 bg-white border-b border-outline-variant/10">
                <span class="material-symbols-outlined text-slate-400 text-sm">filter_alt</span>
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">Active Filters</span>
                <?php foreach ($activeChips as $chip): ?>
                    <a href="<?= e($chip['href']) ?>" class="flex items-center gap-1.5 px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold border border-slate-200 text-decoration-none">
                        <?= e($chip['label']) ?>
                        <span class="material-symbols-outlined text-[14px]">close</span>
                    </a>
                <?php endforeach; ?>
                <a href="index.php" class="ml-auto text-[10px] font-black text-primary uppercase tracking-widest hover:underline text-decoration-none">Clear All</a>
            </div>
        <?php endif; ?>

        <div id="advancedFilterModal" class="modal-backdrop">
            <div class="modal-content bg-white rounded-[2rem] w-full max-w-2xl p-8 shadow-2xl border border-outline-variant/10">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-primary-fixed text-primary rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined">filter_alt</span>
                        </div>
                        <h3 class="font-headline text-2xl font-extrabold text-[#1c2a59] m-0">Advanced Filters</h3>
                    </div>
                    <button type="button" onclick="closeModal('advancedFilterModal')" class="btn-icon btn-icon-slate">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="clinic-label">Status</label>
                        <select class="clinic-select" name="status">
                            <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All Records</option>
                            <?php foreach (visit_statuses() as $status): ?>
                                <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Purpose</label>
                        <select class="clinic-select" name="purpose">
                            <option value="all">All Purposes</option>
                            <?php foreach ($purposeOptions as $purpose): ?>
                                <option value="<?= e($purpose) ?>" <?= $filters['purpose'] === $purpose ? 'selected' : '' ?>><?= e($purpose) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Attended By</label>
                        <select class="clinic-select" name="staff">
                            <option value="all">All Attending Staff</option>
                            <?php foreach ($staffMembers as $staff): ?>
                                <option value="<?= (int) $staff['id'] ?>" <?= (string) $filters['staff'] === (string) $staff['id'] ? 'selected' : '' ?>><?= e($staff['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Date From</label>
                        <input class="clinic-input" type="date" name="date_from" value="<?= e($filters['date_from']) ?>">
                    </div>
                    <div>
                        <label class="clinic-label">Date To</label>
                        <input class="clinic-input" type="date" name="date_to" value="<?= e($filters['date_to']) ?>">
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

    <?php render_ag_grid('visitsGrid', $visitColumns, $visitRows, [
        'searchInput' => 'visitsGridSearch',
        'emptyTitle' => $activeChips ? 'No matching visits' : 'No clinic visits yet',
        'emptyText' => $activeChips ? 'Try a different search or filter.' : 'Record a clinic visit to get started.',
    ]); ?>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $page - 1])) ?>">
                    <span class="material-symbols-outlined text-[18px]">chevron_left</span>
                </a>
            <?php else: ?>
                <span class="page-disabled"><span class="material-symbols-outlined text-[18px]">chevron_left</span></span>
            <?php endif; ?>

            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="page-active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $page + 1])) ?>">
                    <span class="material-symbols-outlined text-[18px]">chevron_right</span>
                </a>
            <?php else: ?>
                <span class="page-disabled"><span class="material-symbols-outlined text-[18px]">chevron_right</span></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
