<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../../app/services/ApeCycleService.php';
require_login();
ensure_ape_workflow_schema();

$activeQueue = $_GET['queue'] ?? 'examination';
$search = trim($_GET['q'] ?? '');
$queues = ape_work_queues();

$todayScheduleDate = date('Y-m-d');
$activeApeCycle = ape_cycle_current();
$schoolYearBatches = [];
$scheduledBatchesById = [];
if (($activeApeCycle['status'] ?? '') === 'Active') {
    foreach (ape_schedule_batches((int) $activeApeCycle['ape_cycle_id']) as $batch) {
        if (($batch['status'] ?? '') !== 'Scheduled') {
            continue;
        }
        $schoolYearBatches[] = $batch;
        $scheduledBatchesById[(int) $batch['batch_id']] = $batch;
    }
}
$requestedBatchId = filter_input(INPUT_GET, 'batch', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$selectedBatchId = $requestedBatchId && isset($scheduledBatchesById[(int) $requestedBatchId])
    ? (int) $requestedBatchId
    : null;
$selectedBatch = $selectedBatchId !== null ? $scheduledBatchesById[$selectedBatchId] : null;

$todayScheduleRecords = ape_fetch_records('', null, $todayScheduleDate);
$scopeRecords = $selectedBatchId !== null
    ? ape_fetch_records('', null, null, $selectedBatchId)
    : $todayScheduleRecords;
$allRecords = $search === ''
    ? $scopeRecords
    : ($selectedBatchId !== null
        ? ape_fetch_records($search, null, null, $selectedBatchId)
        : ape_fetch_records($search, null, $todayScheduleDate));
$todayScheduledBatches = [];
foreach ($todayScheduleRecords as $scheduledRecord) {
    if (!empty($scheduledRecord['schedule_batch_id'])) {
        $todayScheduledBatches[(int) $scheduledRecord['schedule_batch_id']] = [
            'name' => (string) ($scheduledRecord['batch_name'] ?? 'APE Batch'),
            'start_time' => (string) ($scheduledRecord['batch_start_time'] ?? ''),
            'end_time' => (string) ($scheduledRecord['batch_end_time'] ?? ''),
        ];
    }
}
$todayBatchCount = count($todayScheduledBatches);
$todayScheduledPatientCount = count($todayScheduleRecords);
$todayBatchIndicator = match (true) {
    $todayBatchCount === 0 => 'No APE batch scheduled today',
    $todayBatchCount === 1 => sprintf(
        '%s • %s–%s',
        reset($todayScheduledBatches)['name'],
        date('g:i A', strtotime(reset($todayScheduledBatches)['start_time'])),
        date('g:i A', strtotime(reset($todayScheduledBatches)['end_time']))
    ),
    default => sprintf('%d batches • %d patients', $todayBatchCount, $todayScheduledPatientCount),
};
$batchIndicator = $selectedBatch !== null
    ? sprintf(
        '%s • %s, %s–%s',
        (string) $selectedBatch['batch_name'],
        date('M j', strtotime((string) $selectedBatch['schedule_date'])),
        date('g:i A', strtotime((string) $selectedBatch['start_time'])),
        date('g:i A', strtotime((string) $selectedBatch['end_time']))
    )
    : $todayBatchIndicator;
$scopeDescription = $selectedBatch !== null
    ? 'Showing patients assigned to ' . (string) $selectedBatch['batch_name'] . '. Click a queue to focus the page.'
    : 'Today’s scheduled patients only. Click a queue to focus the page.';
$scopeEmptyText = $selectedBatch !== null
    ? 'No patients from this scheduled batch are in this queue.'
    : 'No patients are scheduled for today in this queue.';
$scopeCountLabel = $selectedBatch !== null ? 'Selected batch' : 'Scheduled today';
$batchQuerySuffix = $selectedBatchId !== null ? '&batch=' . $selectedBatchId : '';

$recordsByQueue = array_fill_keys(array_keys($queues), []);
foreach ($allRecords as $record) {
    $recordsByQueue[ape_record_queue($record)][] = $record;
}

$visibleQueues = $activeQueue === 'all'
    ? array_keys($queues)
    : (isset($queues[$activeQueue]) ? [$activeQueue] : array_keys($queues));

$needsAction = 0;
foreach (['examination', 'digital_submission', 'final_decision', 'follow_up'] as $key) {
    $needsAction += count($recordsByQueue[$key]);
}

$metrics = [
    'total' => count($allRecords),
    'clinic_action' => $needsAction,
    'digitized' => count(array_filter($allRecords, static fn(array $record): bool => !empty($record['document_path']))),
    'follow_up' => count($recordsByQueue['follow_up']),
    'completed' => count($recordsByQueue['completed']),
];
$clearanceRate = $metrics['total'] > 0 ? round(($metrics['completed'] / $metrics['total']) * 100) : 0;

// Top bar stats
$activePatients = $metrics['total'] - $metrics['completed'];
$appointmentsStmt = appointment_db()->query("SELECT COUNT(*) AS total FROM appointments WHERE DATE(appointment_datetime) = CURDATE()");
$appointmentsToday = (int)($appointmentsStmt->fetch()['total'] ?? 0);

$overdueRecords = [];
foreach ($allRecords as $rec) {
    $priority = ape_priority_badge($rec);
    if (in_array($priority['label'], ['Missed', 'Overdue', 'Urgent'], true)) {
        $overdueRecords[] = $rec;
    }
}

$apeQueueColumns = [
    ['headerName' => 'Priority', 'field' => 'priorityHtml', 'cellRenderer' => 'html', 'sortField' => 'prioritySort', 'sortType' => 'number', 'width' => 140],
    ['headerName' => 'Patient', 'field' => 'studentHtml', 'cellRenderer' => 'html', 'sortField' => 'studentSort', 'minWidth' => 250],
    ['headerName' => 'Program', 'field' => 'programHtml', 'cellRenderer' => 'html', 'sortField' => 'programSort', 'minWidth' => 220],
    ['headerName' => 'APE Schedule', 'field' => 'scheduleHtml', 'cellRenderer' => 'html', 'sortField' => 'scheduleSort', 'minWidth' => 250],
    ['headerName' => 'Waiting', 'field' => 'waiting', 'sortField' => 'waitingSort', 'sortType' => 'number', 'width' => 140],
    ['headerName' => 'Next Action', 'field' => 'nextActionHtml', 'cellRenderer' => 'html', 'sortField' => 'nextActionSort', 'minWidth' => 260],
    ['headerName' => 'Actions', 'field' => 'actionHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 100, 'minWidth' => 90],
];

render_header('APE Work Queues');

$apeUser = current_user() ?? [];
$apeDisplayName = trim((string) ($apeUser['name'] ?? '')) ?: 'Nurse';
$apeHeaderActions = ''
    . '<div class="bg-white border border-slate-200 rounded-2xl p-4 flex items-center gap-4 min-w-[140px]">'
    . '<span class="material-symbols-outlined text-slate-400 text-[28px]">group</span>'
    . '<div><p class="font-headline text-2xl font-extrabold text-[#17261d] leading-none mb-1">' . (int) $activePatients . '</p>'
    . '<p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none">' . e($scopeCountLabel) . '</p></div></div>'
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
    $selectedBatch !== null ? 'Reviewing the selected scheduled APE batch.' : "Here's what needs your attention today. Start at the top.",
    $apeHeaderActions
);
?>

<?php if (count($overdueRecords) > 0): ?>
<div class="bg-red-50 border border-red-200 rounded-2xl p-1 mb-8">
    <div class="px-5 py-4 text-red-700 flex items-center gap-2 border-b border-red-100/50">
        <span class="material-symbols-outlined text-[18px]">error</span>
        <h2 class="font-headline font-extrabold text-sm m-0"><?= count($overdueRecords) ?> patient(s) need immediate attention</h2>
    </div>
    <div class="divide-y divide-red-100/50">
        <?php foreach (array_slice($overdueRecords, 0, 5) as $rec): 
            $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
            $next = ape_next_action($rec);
            $priority = ape_priority_badge($rec);
            $deadline = ape_deadline_status($rec);
            $days = (int) ($deadline['days'] ?? 0);
            $warningClass = in_array($priority['label'], ['Missed', 'Overdue'], true) ? 'text-red-600' : 'text-amber-600';
            $badgeIcon = $priority['label'] === 'Missed' ? 'event_busy' : 'error';
            $deadlineText = match ($priority['label']) {
                'Missed' => 'Assigned batch was missed',
                'Overdue' => $priority['label'] . ' - ' . $days . 'd',
                default => 'Due in ' . $days . 'd',
            };
        ?>
            <div class="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-red-100/30 transition-colors">
                <div>
                    <h3 class="font-bold text-slate-800 text-base mb-1"><?= e($fullName) ?></h3>
                    <p class="text-xs font-bold text-slate-500 m-0">
                        <?= e($rec['id_number']) ?> &bull; <?= e($rec['course_section'] ?: 'No course') ?> &bull; <?= e($next['label']) ?> &mdash; <?= strtolower(e($priority['label'])) ?>
                    </p>
                </div>
                <div class="flex items-center gap-6 shrink-0">
                    <span class="text-xs font-bold <?= $warningClass ?> flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]"><?= $badgeIcon ?></span>
                        <?= e($deadlineText) ?>
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
    <form method="get">
    <div class="flex flex-col lg:flex-row justify-between gap-4 lg:items-center mb-5">
        <div>
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Work Queue Map</h2>
            <p class="text-xs font-bold text-slate-500 mb-0"><?= e($scopeDescription) ?></p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full lg:w-auto">
            <?php if (in_array($apeUser['role'] ?? '', ['admin', 'doctor'], true)): ?>
                <a href="scheduling.php" class="btn btn-outline w-full sm:w-auto justify-center text-decoration-none">
                    <span class="material-symbols-outlined text-[18px]">calendar_month</span>
                    Manage Scheduling
                </a>
            <?php endif; ?>
            <button type="button" onclick="showModal('apeBatchPickerModal')" class="w-full sm:w-auto min-h-12 px-4 py-2 rounded-xl border border-outline-variant bg-primary-fixed flex items-center gap-3 text-left hover:border-primary transition-colors" title="Choose a scheduled APE batch">
                <span class="material-symbols-outlined text-primary text-[20px]">event_available</span>
                <div class="min-w-0">
                    <p class="text-[9px] font-black uppercase tracking-widest text-primary mb-0">Scheduled Batch</p>
                    <p class="text-xs font-extrabold text-slate-700 mb-0 whitespace-nowrap"><?= e($batchIndicator) ?></p>
                </div>
                <span class="material-symbols-outlined text-primary text-[18px]">expand_more</span>
            </button>
            <div class="search-input-wrap w-full sm:w-80">
                <span class="search-icon material-symbols-outlined">search</span>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search APE records..." class="search-input">
            </div>
            <?php if ($activeQueue !== 'all'): ?>
                <input type="hidden" name="queue" value="<?= e($activeQueue) ?>">
            <?php endif; ?>
            <?php if ($selectedBatchId !== null): ?>
                <input type="hidden" name="batch" value="<?= (int) $selectedBatchId ?>">
            <?php endif; ?>
        </div>
    </div>
    </form>
    <div class="ape-queue-map-grid">
        <?php foreach ($queues as $key => $queue): ?>
            <a href="?queue=<?= urlencode($key) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?><?= e($batchQuerySuffix) ?>" class="ape-queue-map-card rounded-2xl border <?= $activeQueue === $key ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-3 text-decoration-none hover:bg-primary-fixed transition-colors">
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
        $shownRecords = $records;
        $gridSuffix = preg_replace('/[^A-Za-z0-9_-]/', '', $queueKey);
        $paginationId = 'apePagination' . $gridSuffix;
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
                $hasBatch = !empty($rec['schedule_batch_id']) && ($rec['batch_status'] ?? '') !== 'Cancelled';
                $scheduleSort = $hasBatch ? (string) $rec['batch_schedule_date'] . ' ' . (string) $rec['batch_start_time'] : '9999-12-31 23:59:59';
                $scheduleHtml = $hasBatch
                    ? '<strong class="text-sm text-slate-800 block">' . e($rec['batch_name']) . '</strong><span class="text-xs font-bold text-slate-500">' . e(date('M j, Y', strtotime($rec['batch_schedule_date']))) . ' &bull; ' . e(date('g:i A', strtotime($rec['batch_start_time']))) . '–' . e(date('g:i A', strtotime($rec['batch_end_time']))) . '</span>'
                    : '<span class="badge badge-pending">Unscheduled</span>';
                $apeRows[] = [
                    'rowUrl' => 'view.php?id=' . (int)$rec['id'],
                    'prioritySort' => array_search($priority['label'], ['Missed', 'Overdue', 'Urgent', 'Clinical', 'Waiting', 'Ready', 'Done'], true),
                    'priorityHtml' => '<span class="badge ' . e($priority['class']) . '">' . e($priority['label']) . '</span>',
                    'studentSort' => trim($rec['last_name'] . ' ' . $rec['first_name']),
                    'studentHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-xs font-bold text-slate-400">' . e($rec['id_number']) . '</div></div></div>',
                    'programSort' => $rec['course_section'] ?: '',
                    'programHtml' => '<p class="text-sm font-bold text-slate-700 mb-1">' . e($rec['course_section'] ?: 'No course set') . '</p><p class="text-xs font-bold text-slate-400 mb-0">' . e($rec['document_type'] ?: 'APE documents') . '</p>',
                    'scheduleSort' => $scheduleSort,
                    'scheduleHtml' => $scheduleHtml,
                    'waiting' => ape_waiting_label($rec),
                    'waitingSort' => ape_waiting_days($rec),
                    'nextActionSort' => $next['label'],
                    'nextActionHtml' => '<div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[18px]">' . e($next['icon']) . '</span><div><strong class="block text-sm text-slate-800">' . e($next['label']) . '</strong><span class="block text-xs font-bold text-slate-400">' . e(ape_missing_item($rec)) . '</span></div></div>',
                    'actionHtml' => row_actions_button('APE actions', $queueKey === 'completed' ? '' : '<a href="view.php?id=' . (int)$rec['id'] . '" class="btn btn-primary btn-sm text-decoration-none"><span class="material-symbols-outlined text-[14px]">' . e($next['icon']) . '</span>' . e($next['label']) . '</a>'),
                ];
            }
            render_ag_grid('apeGrid' . $gridSuffix, $apeQueueColumns, $apeRows, [
                'pageSize' => 10,
                'pagination' => true,
                'paginationControls' => $paginationId,
                'height' => 'compact',
                'emptyTitle' => 'No patients here',
                'emptyText' => $scopeEmptyText,
            ]);
            ?>
            <nav id="<?= e($paginationId) ?>" class="pagination" aria-label="<?= e($queue['title']) ?> pages"></nav>
        </section>
    <?php endforeach; ?>
</div>

<div id="apeBatchPickerModal" class="modal-backdrop" data-no-row-click>
    <div class="modal-content bg-white rounded-[2rem] w-full max-w-3xl p-8 shadow-2xl border border-outline-variant/10">
        <div class="flex items-start justify-between gap-4 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-11 h-11 bg-primary-fixed text-primary rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined">calendar_month</span>
                </div>
                <div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">Scheduled APE Batches</h3>
                    <p class="text-sm font-bold text-slate-500 mb-0">
                        <?= e((string) ($activeApeCycle['academic_year'] ?? 'Current school year')) ?> &bull; Select a batch to populate the work queues.
                    </p>
                </div>
            </div>
            <button type="button" onclick="closeModal('apeBatchPickerModal')" class="btn-icon btn-icon-slate" aria-label="Close scheduled batch list">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <?php
        $todayQuery = ['queue' => $activeQueue];
        if ($search !== '') {
            $todayQuery['q'] = $search;
        }
        ?>
        <a href="?<?= e(http_build_query($todayQuery)) ?>" class="flex items-center justify-between gap-4 rounded-2xl border <?= $selectedBatchId === null ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-4 mb-3 text-decoration-none hover:border-primary transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <span class="w-10 h-10 rounded-xl bg-white border border-outline-variant text-primary flex items-center justify-center material-symbols-outlined shrink-0">today</span>
                <div class="min-w-0">
                    <strong class="block text-sm text-slate-800">Today&rsquo;s Scheduled Batches</strong>
                    <span class="block text-xs font-bold text-slate-500"><?= e(date('F j, Y', strtotime($todayScheduleDate))) ?> &bull; <?= $todayScheduledPatientCount ?> patient(s)</span>
                </div>
            </div>
            <?php if ($selectedBatchId === null): ?>
                <span class="badge badge-completed">Viewing</span>
            <?php else: ?>
                <span class="material-symbols-outlined text-primary">arrow_forward</span>
            <?php endif; ?>
        </a>

        <div class="space-y-3 max-h-[55vh] overflow-y-auto pr-1">
            <?php if ($schoolYearBatches === []): ?>
                <div class="rounded-2xl border border-dashed border-outline-variant p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-slate-300 mb-2">event_busy</span>
                    <p class="font-extrabold text-slate-700 mb-1">No scheduled batches</p>
                    <p class="text-xs font-bold text-slate-500 mb-0">Create an APE batch from Settings &gt; APE Cycle.</p>
                </div>
            <?php else: ?>
                <?php foreach ($schoolYearBatches as $batch): ?>
                    <?php
                    $batchId = (int) $batch['batch_id'];
                    $batchQuery = ['queue' => $activeQueue, 'batch' => $batchId];
                    if ($search !== '') {
                        $batchQuery['q'] = $search;
                    }
                    $isSelectedBatch = $selectedBatchId === $batchId;
                    ?>
                    <a href="?<?= e(http_build_query($batchQuery)) ?>" class="flex items-center justify-between gap-4 rounded-2xl border <?= $isSelectedBatch ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-4 text-decoration-none hover:border-primary hover:bg-primary-fixed transition-colors">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <strong class="text-sm text-slate-800"><?= e((string) $batch['batch_name']) ?></strong>
                                <span class="badge badge-in-progress"><?= e((string) $batch['patient_category']) ?></span>
                            </div>
                            <p class="text-xs font-bold text-slate-500 mb-0">
                                <?= e(date('F j, Y', strtotime((string) $batch['schedule_date']))) ?>
                                &bull; <?= e(date('g:i A', strtotime((string) $batch['start_time']))) ?>&ndash;<?= e(date('g:i A', strtotime((string) $batch['end_time']))) ?>
                                &bull; <?= (int) $batch['assigned_count'] ?> patient(s)
                            </p>
                        </div>
                        <?php if ($isSelectedBatch): ?>
                            <span class="badge badge-completed shrink-0">Viewing</span>
                        <?php else: ?>
                            <span class="material-symbols-outlined text-primary shrink-0">arrow_forward</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php render_footer(); ?>
