<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_login();
ensure_ape_workflow_schema();

$activeQueue = $_GET['queue'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$queues = ape_work_queues();

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.student_number LIKE ? OR p.course_section LIKE ? OR a.document_type LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}

$stmt = db()->prepare("
    SELECT a.*, p.first_name, p.last_name, p.student_number, p.course_section, u.name AS verified_by_name
    FROM ape_records a
    JOIN patients p ON p.id = a.patient_id
    LEFT JOIN users u ON u.id = a.verified_by
    WHERE {$where}
    ORDER BY a.updated_at DESC, a.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$allRecords = $stmt->fetchAll();

$recordsByQueue = array_fill_keys(array_keys($queues), []);
foreach ($allRecords as $record) {
    $recordsByQueue[ape_record_queue($record)][] = $record;
}

$visibleQueues = $activeQueue === 'all'
    ? array_keys($queues)
    : (isset($queues[$activeQueue]) ? [$activeQueue] : array_keys($queues));

$needsAction = 0;
foreach (['document_review', 'digital_submission', 'follow_up'] as $key) {
    $needsAction += count($recordsByQueue[$key]);
}

$metrics = [
    'total' => count($allRecords),
    'clinic_action' => $needsAction,
    'digitized' => count($recordsByQueue['digital_submission']) + count($recordsByQueue['follow_up']) + count($recordsByQueue['completed']),
    'follow_up' => count($recordsByQueue['follow_up']),
    'completed' => count($recordsByQueue['completed']),
];
$clearanceRate = $metrics['total'] > 0 ? round(($metrics['completed'] / $metrics['total']) * 100) : 0;

// Top bar stats
$activeStudents = $metrics['total'] - $metrics['completed'];
$appointmentsStmt = db()->query("SELECT COUNT(*) AS total FROM appointments WHERE DATE(appointment_datetime) = CURDATE()");
$appointmentsToday = (int)($appointmentsStmt->fetch()['total'] ?? 0);

$overdueRecords = [];
foreach ($allRecords as $rec) {
    $priority = ape_priority_badge($rec);
    if ($priority['label'] === 'Overdue' || $priority['label'] === 'Urgent') {
        $overdueRecords[] = $rec;
    }
}

$apeQueueColumns = [
    ['headerName' => 'Priority', 'field' => 'priorityHtml', 'cellRenderer' => 'html', 'width' => 140],
    ['headerName' => 'Student', 'field' => 'studentHtml', 'cellRenderer' => 'html', 'minWidth' => 250],
    ['headerName' => 'Program', 'field' => 'programHtml', 'cellRenderer' => 'html', 'minWidth' => 220],
    ['headerName' => 'Waiting', 'field' => 'waiting', 'width' => 140],
    ['headerName' => 'Next Action', 'field' => 'nextActionHtml', 'cellRenderer' => 'html', 'minWidth' => 260],
    ['headerName' => 'Action', 'field' => 'actionHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 210],
];

render_header('APE Work Queues');

$apeUser = current_user() ?? [];
$apeDisplayName = trim((string) ($apeUser['name'] ?? '')) ?: 'Nurse';
$apeHeaderActions = ''
    . '<div class="bg-white border border-slate-200 rounded-2xl p-4 flex items-center gap-4 min-w-[140px]">'
    . '<span class="material-symbols-outlined text-slate-400 text-[28px]">group</span>'
    . '<div><p class="font-headline text-2xl font-extrabold text-[#17261d] leading-none mb-1">' . (int) $activeStudents . '</p>'
    . '<p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none">Active students</p></div></div>'
    . '<div class="bg-white border border-slate-200 rounded-2xl p-4 flex items-center gap-4 min-w-[140px]">'
    . '<span class="material-symbols-outlined text-slate-400 text-[28px]">notification_important</span>'
    . '<div><p class="font-headline text-2xl font-extrabold text-[#17261d] leading-none mb-1">' . count($overdueRecords) . '</p>'
    . '<p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none">Needs attention</p></div></div>'
    . '<div class="bg-white border border-slate-200 rounded-2xl p-4 flex items-center gap-4 min-w-[140px]">'
    . '<span class="material-symbols-outlined text-slate-400 text-[28px]">show_chart</span>'
    . '<div><p class="font-headline text-2xl font-extrabold text-[#17261d] leading-none mb-1">' . (int) $metrics['completed'] . '</p>'
    . '<p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none">Completed</p></div></div>';

render_clinic_command_header(
    'APE Work Queues',
    'Good day, ' . $apeDisplayName,
    "Here's what needs your attention today. Start at the top.",
    $apeHeaderActions
);
?>

<?php if (count($overdueRecords) > 0): ?>
<div class="bg-red-50 border border-red-200 rounded-2xl p-1 mb-8">
    <div class="px-5 py-4 text-red-700 flex items-center gap-2 border-b border-red-100/50">
        <span class="material-symbols-outlined text-[18px]">error</span>
        <h2 class="font-headline font-extrabold text-sm m-0"><?= count($overdueRecords) ?> student(s) need immediate attention</h2>
    </div>
    <div class="divide-y divide-red-100/50">
        <?php foreach (array_slice($overdueRecords, 0, 5) as $rec): 
            $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
            $next = ape_next_action($rec);
            $days = ape_waiting_days($rec);
            $priority = ape_priority_badge($rec);
            $warningClass = $priority['label'] === 'Overdue' ? 'text-red-600' : 'text-amber-600';
            $badgeIcon = 'error';
        ?>
            <div class="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-red-100/30 transition-colors">
                <div>
                    <h3 class="font-bold text-slate-800 text-base mb-1"><?= e($fullName) ?></h3>
                    <p class="text-xs font-bold text-slate-500 m-0">
                        <?= e($rec['student_number']) ?> &bull; <?= e($rec['course_section'] ?: 'No course') ?> &bull; <?= e($next['label']) ?> &mdash; <?= strtolower(e($priority['label'])) ?>
                    </p>
                </div>
                <div class="flex items-center gap-6 shrink-0">
                    <span class="text-xs font-bold <?= $warningClass ?> flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]"><?= $badgeIcon ?></span>
                        <?= e($priority['label']) ?> - <?= $days ?>d
                    </span>
                    <a href="view.php?id=<?= (int)$rec['id'] ?>" class="text-xs font-bold text-red-700 hover:text-red-800 text-decoration-none flex items-center gap-1">
                        Resolve <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<section class="clinic-card p-6">
    <div class="flex flex-col lg:flex-row justify-between gap-4 lg:items-center mb-5">
        <div>
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Work Queue Map</h2>
            <p class="text-xs font-bold text-slate-500 mb-0">Click a queue to focus the page. Each student shows one next clinic action.</p>
        </div>
    </div>
    <div class="ape-queue-map-grid">
        <a href="?<?= $search !== '' ? 'q=' . urlencode($search) : '' ?>" class="ape-queue-map-card rounded-2xl border <?= $activeQueue === 'all' ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-4 text-decoration-none transition-colors">
            <div class="flex items-center justify-between gap-2">
                <span class="w-8 h-8 rounded-xl bg-white text-primary border border-outline-variant flex items-center justify-center material-symbols-outlined text-[16px]">view_list</span>
                <strong class="font-headline text-xl text-[#17261d]"><?= count($allRecords) ?></strong>
            </div>
            <p class="ape-queue-map-label">All Queues</p>
        </a>
        <?php foreach ($queues as $key => $queue): ?>
            <a href="?queue=<?= urlencode($key) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="ape-queue-map-card rounded-2xl border <?= $activeQueue === $key ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-4 text-decoration-none hover:bg-primary-fixed transition-colors">
                <div class="flex items-center justify-between gap-2">
                    <span class="w-8 h-8 rounded-xl bg-white text-primary border border-outline-variant flex items-center justify-center material-symbols-outlined text-[16px]"><?= e($queue['icon']) ?></span>
                    <strong class="font-headline text-xl text-[#17261d]"><?= count($recordsByQueue[$key]) ?></strong>
                </div>
                <p class="ape-queue-map-label"><?= e($queue['short_title'] ?? $queue['title']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<div class="grid grid-cols-1 gap-6">
    <?php foreach ($visibleQueues as $queueKey):
        $queue = $queues[$queueKey];
        $records = $recordsByQueue[$queueKey];
        $limit = $activeQueue === 'all' ? 6 : 200;
        $shownRecords = array_slice($records, 0, $limit);
    ?>
        <section class="clinic-card overflow-hidden">
            <div class="p-6 border-b border-outline-variant">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div class="flex items-start gap-4">
                        <span class="w-11 h-11 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined"><?= e($queue['icon']) ?></span>
                        <div>
                            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1"><?= e($queue['title']) ?></h2>
                            <p class="text-xs font-bold text-slate-500 mb-0"><?= e($queue['description']) ?></p>
                        </div>
                    </div>
                    <span class="badge <?= $queueKey === 'completed' ? 'badge-completed' : ($queueKey === 'follow_up' ? 'badge-high' : 'badge-in-progress') ?>">
                        <?= count($records) ?> record(s)
                    </span>
                </div>
            </div>

            <?php
            $apeRows = [];
            foreach ($shownRecords as $rec) {
                $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
                $next = ape_next_action($rec);
                $priority = ape_priority_badge($rec);
                $apeRows[] = [
                    'rowUrl' => 'view.php?id=' . (int)$rec['id'],
                    'priorityHtml' => '<span class="badge ' . e($priority['class']) . '">' . e($priority['label']) . '</span>',
                    'studentHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-xs font-bold text-slate-400">' . e($rec['student_number']) . '</div></div></div>',
                    'programHtml' => '<p class="text-sm font-bold text-slate-700 mb-1">' . e($rec['course_section'] ?: 'No course set') . '</p><p class="text-xs font-bold text-slate-400 mb-0">' . e($rec['document_type'] ?: 'APE documents') . '</p>',
                    'waiting' => ape_waiting_label($rec),
                    'nextActionHtml' => '<div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[18px]">' . e($next['icon']) . '</span><div><strong class="block text-sm text-slate-800">' . e($next['label']) . '</strong><span class="block text-xs font-bold text-slate-400">' . e(ape_missing_item($rec)) . '</span></div></div>',
                    'actionHtml' => $queueKey === 'completed' ? '' : '<a href="view.php?id=' . (int)$rec['id'] . '" class="btn btn-primary btn-sm text-decoration-none"><span class="material-symbols-outlined text-[14px]">' . e($next['icon']) . '</span>' . e($next['label']) . '</a>',
                ];
            }
            render_ag_grid('apeGrid' . preg_replace('/[^A-Za-z0-9_-]/', '', $queueKey), $apeQueueColumns, $apeRows, [
                'pageSize' => $activeQueue === 'all' ? 10 : 25,
                'height' => 'compact',
                'emptyTitle' => 'No students here',
                'emptyText' => 'This queue is clear for now.',
            ]);
            ?>
            <?php if ($activeQueue === 'all' && count($records) > $limit): ?>
                <div class="px-6 py-4 border-t border-outline-variant bg-slate-50/40 text-right">
                    <a class="btn btn-sm btn-ghost text-decoration-none" href="?queue=<?= urlencode($queueKey) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">View all <?= count($records) ?></a>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<?php render_footer(); ?>
