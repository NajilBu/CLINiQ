<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../../app/services/ApeCycleService.php';
require_login();
ensure_ape_workflow_schema();

$apeStateKeys = ['queue', 'q', 'population', 'scope', 'batch'];
$hasExplicitApeState = false;
foreach ($apeStateKeys as $apeStateKey) {
    if (array_key_exists($apeStateKey, $_GET)) {
        $hasExplicitApeState = true;
        break;
    }
}
if (!$hasExplicitApeState && is_array($_SESSION['ape_work_queue_state'] ?? null)) {
    $_GET = array_merge($_GET, $_SESSION['ape_work_queue_state']);
}

$activeQueue = $_GET['queue'] ?? 'digital_submission';
$search = trim($_GET['q'] ?? '');
$requestedPopulationScope = strtolower(trim((string) ($_GET['population'] ?? '')));
if ($requestedPopulationScope !== '') {
    $_SESSION['ape_population_scope'] = $requestedPopulationScope === 'faculty_ntp' ? 'faculty_ntp' : 'students';
}
$populationScope = ($_SESSION['ape_population_scope'] ?? 'students') === 'faculty_ntp'
    ? 'faculty_ntp'
    : 'students';
$queues = ape_work_queues();
$combinedFinalDecisionQueue = 'final_decision_or_follow_up';
if ($activeQueue !== 'all' && $activeQueue !== $combinedFinalDecisionQueue && !isset($queues[$activeQueue])) {
    $activeQueue = 'digital_submission';
}

$activeApeCycle = ape_cycle_current();
$apeUser = current_user() ?? [];
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
usort($schoolYearBatches, static function (array $left, array $right): int {
    $leftSchedule = (string) $left['schedule_date'] . ' ' . (string) $left['start_time'];
    $rightSchedule = (string) $right['schedule_date'] . ' ' . (string) $right['start_time'];
    return strcmp($leftSchedule, $rightSchedule) ?: ((int) $left['batch_id'] <=> (int) $right['batch_id']);
});
$earliestUpcomingBatch = ape_earliest_upcoming_batch($schoolYearBatches);
$overallRequested = strtolower(trim((string) ($_GET['scope'] ?? ''))) === 'overall';
$requestedBatchId = filter_var($_GET['batch'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$selectedBatchId = null;
if ($populationScope === 'students' && !$overallRequested) {
    if ($requestedBatchId && isset($scheduledBatchesById[(int) $requestedBatchId])) {
        $selectedBatchId = (int) $requestedBatchId;
    } elseif ($earliestUpcomingBatch !== null) {
        $selectedBatchId = (int) $earliestUpcomingBatch['batch_id'];
    }
}
$selectedBatch = $selectedBatchId !== null ? $scheduledBatchesById[$selectedBatchId] : null;
$isOverallView = $selectedBatch === null;

$persistedApeState = [
    'queue' => $activeQueue,
    'population' => $populationScope,
];
if ($search !== '') {
    $persistedApeState['q'] = $search;
}
if ($selectedBatchId !== null) {
    $persistedApeState['batch'] = (string) $selectedBatchId;
} else {
    $persistedApeState['scope'] = 'overall';
}
$_SESSION['ape_work_queue_state'] = $persistedApeState;

$overallRecords = ape_fetch_records();
$scopeRecords = ape_fetch_records($search, null, null, $selectedBatchId);
$scopeRecords = array_values(array_filter($scopeRecords, static function (array $record) use ($populationScope): bool {
    $isClinicManual = ($record['entry_mode'] ?? '') === 'Clinic Manual';
    return $populationScope === 'faculty_ntp' ? $isClinicManual : !$isClinicManual;
}));
$batchProgress = ape_batch_progress($schoolYearBatches === [] ? [] : $overallRecords);
$allRecords = $scopeRecords;
$clearanceComplianceCompleted = count(array_filter(
    $allRecords,
    static fn(array $record): bool => ape_staff_queue_stage($record) === 'completed'
));
$clearanceComplianceTotal = count($allRecords);
$clearanceComplianceRate = $clearanceComplianceTotal > 0
    ? (int) round(($clearanceComplianceCompleted / $clearanceComplianceTotal) * 100)
    : 0;
$batchIndicator = $selectedBatch !== null
    ? sprintf(
        '%s • %s, %s–%s',
        (string) $selectedBatch['batch_name'],
        date('M j', strtotime((string) $selectedBatch['schedule_date'])),
        date('g:i A', strtotime((string) $selectedBatch['start_time'])),
        date('g:i A', strtotime((string) $selectedBatch['end_time']))
    )
    : 'Overall';
$scopeDescription = $selectedBatch !== null
    ? 'All queues and totals show only patients assigned to ' . (string) $selectedBatch['batch_name'] . '. Choose Overall to show every batch.'
    : ($populationScope === 'faculty_ntp'
        ? 'All queues and totals show clinic-manual Faculty and NTP records only.'
        : 'All queues and totals show student records from every batch.');
$scopeCountLabel = $populationScope === 'faculty_ntp' ? 'Faculty & NTP records' : ($selectedBatch !== null ? 'Selected batch' : 'Student records');
$batchQuerySuffix = $populationScope === 'faculty_ntp'
    ? '&scope=overall&population=faculty_ntp'
    : ($selectedBatchId !== null ? '&batch=' . $selectedBatchId : '&scope=overall');

$recordsByQueue = array_fill_keys(array_keys($queues), []);
foreach ($allRecords as $record) {
    $recordsByQueue[ape_staff_queue_stage($record)][] = $record;
}

$flowQueues = [
    'digital_submission' => $queues['digital_submission'],
    'examination' => $queues['examination'],
    $combinedFinalDecisionQueue => [
        'title' => 'Final Decision or Follow-up',
        'short_title' => 'Final Decision or Follow-up',
        'description' => 'Review completed examinations, archive documents, or manage any required follow-up.',
        'icon' => 'clinical_notes',
        'source_queues' => ['final_decision', 'follow_up'],
    ],
    'completed' => $queues['completed'],
];

$visibleQueues = $activeQueue === 'all'
    ? array_keys($flowQueues)
    : (isset($flowQueues[$activeQueue]) ? [$activeQueue] : array_keys($flowQueues));

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
    if ($priority['label'] === 'Missed' && ape_record_queue($rec) === 'examination') {
        $overdueRecords[] = ['record' => $rec, 'attention' => ['kind' => 'missed']];
        continue;
    }
    foreach (ape_patient_document_action_summaries($rec) as $action) {
        if (($action['priority'] ?? '') !== 'overdue') continue;
        $overdueRecords[] = ['record' => $rec, 'attention' => ['kind' => 'overdue', 'action' => $action]];
        break;
    }
}

$apeQueueColumns = [
    ['headerName' => 'Priority', 'field' => 'priorityHtml', 'cellRenderer' => 'html', 'sortField' => 'prioritySort', 'sortType' => 'number', 'width' => 140],
    ['headerName' => 'Patient', 'field' => 'studentHtml', 'cellRenderer' => 'html', 'sortField' => 'studentSort', 'minWidth' => 250],
    ['headerName' => 'Program', 'field' => 'programHtml', 'cellRenderer' => 'html', 'sortField' => 'programSort', 'minWidth' => 220],
    ['headerName' => 'Waiting', 'field' => 'waiting', 'sortField' => 'waitingSort', 'sortType' => 'number', 'width' => 140],
    ['headerName' => 'Next Action', 'field' => 'nextActionHtml', 'cellRenderer' => 'html', 'sortField' => 'nextActionSort', 'minWidth' => 260],
];
if ($populationScope === 'students') {
    array_splice($apeQueueColumns, 3, 0, [[
        'headerName' => 'APE Schedule', 'field' => 'scheduleHtml', 'cellRenderer' => 'html',
        'sortField' => 'scheduleSort', 'minWidth' => 250,
    ]]);
}

render_header('APE Work Queues');

$apeDisplayName = trim((string) ($apeUser['name'] ?? '')) ?: 'Nurse';
$apeHeaderActions = '<div class="bg-white border border-slate-200 rounded-2xl p-4 flex items-center gap-4 min-w-[140px]">'
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
<details class="bg-red-50 border border-red-200 rounded-2xl mb-8 group" data-ape-persistent-details="attention">
    <summary class="px-5 py-4 text-red-700 flex items-center justify-between gap-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
        <span class="flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">error</span><h2 class="font-headline font-extrabold text-sm m-0"><?= count($overdueRecords) ?> patient(s) need immediate attention</h2></span>
        <span class="flex items-center gap-3">
            <a href="../audit/index.php?tab=email#needs-attention" class="inline-flex items-center gap-2 rounded-lg bg-[#3f8155] px-3 py-2 text-xs font-extrabold text-white no-underline shadow-sm hover:bg-[#347047]" onclick="event.stopPropagation();">
                <span class="material-symbols-outlined text-[16px]">mail</span>
                Remind students
            </a>
            <span class="material-symbols-outlined text-[20px] transition-transform group-open:rotate-180">expand_more</span>
        </span>
    </summary>
    <div class="divide-y divide-red-100/50">
        <?php foreach (array_slice($overdueRecords, 0, 5) as $urgentItem): $rec = $urgentItem['record']; $attention = $urgentItem['attention'];
            $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
            $next = ape_next_action($rec);
            $isMissed = ($attention['kind'] ?? '') === 'missed';
            $dueDate = (string) ($attention['action']['due_at'] ?? '');
            $days = $dueDate === '' ? 0 : max(0, (int) ((new DateTimeImmutable('today'))->diff(new DateTimeImmutable($dueDate))->format('%r%a') * -1));
            $warningClass = 'text-red-600';
            $badgeIcon = $isMissed ? 'event_busy' : 'error';
            $deadlineText = $isMissed ? 'Assigned batch was missed' : 'Overdue - ' . $days . 'd';
            $canExamineNow = empty($rec['exam_date']) && ape_examination_is_available($rec);
        ?>
            <div class="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-red-100/30 transition-colors">
                <div>
                    <h3 class="font-bold text-slate-800 text-base mb-1"><?= e($fullName) ?></h3>
                    <p class="text-xs font-bold text-slate-500 m-0">
                        <?= e($rec['id_number']) ?> &bull; <?= e($rec['course_section'] ?: 'No course') ?> &bull; <?= e($next['label']) ?> &mdash; <?= $isMissed ? 'missed' : 'overdue' ?>
                    </p>
                </div>
                <div class="flex items-center gap-6 shrink-0">
                    <span class="text-xs font-bold <?= $warningClass ?> flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]"><?= $badgeIcon ?></span>
                        <?= e($deadlineText) ?>
                    </span>
                    <a href="view.php?id=<?= (int)$rec['id'] ?>" class="text-xs font-bold text-red-700 hover:text-red-800 text-decoration-none flex items-center gap-1">
                        <?= $canExamineNow ? 'Examine Patient' : 'Resolve' ?> <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>

<section class="clinic-card p-6" data-filter-region="ape-work-queue" aria-live="polite">
    <form method="get" data-table-filter="ape-work-queue" data-filter-region="ape-work-queue">
    <div class="ape-work-queue-toolbar-shell mb-5">
        <div class="ape-work-queue-heading min-w-0">
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Work Queue Map</h2>
            <p class="text-xs font-bold text-slate-500 mb-0"><?= e($scopeDescription) ?></p>
        </div>
        <div class="ape-work-queue-toolbar">
            <div class="ape-work-queue-toolbar-actions">
            <?php if (in_array($apeUser['role'] ?? '', ['admin', 'doctor'], true)): ?>
                <a href="scheduling.php" class="btn btn-outline ape-work-queue-scheduling text-decoration-none">
                    <span class="material-symbols-outlined text-[18px]">calendar_month</span>
                    Manage Scheduling
                </a>
            <?php endif; ?>
            <div class="ape-work-queue-population flex rounded-xl border border-outline-variant overflow-hidden" role="group" aria-label="APE population">
                <?php
                $studentScopeQuery = ['queue' => $activeQueue, 'population' => 'students'];
                if ($search !== '') $studentScopeQuery['q'] = $search;
                if ($selectedBatchId !== null) $studentScopeQuery['batch'] = $selectedBatchId;
                else $studentScopeQuery['scope'] = 'overall';
                $employeeScopeQuery = ['queue' => 'examination', 'scope' => 'overall', 'population' => 'faculty_ntp'];
                if ($search !== '') $employeeScopeQuery['q'] = $search;
                ?>
                <a href="?<?= e(http_build_query($studentScopeQuery)) ?>" class="px-4 py-3 text-sm font-extrabold text-decoration-none whitespace-nowrap <?= $populationScope === 'students' ? 'bg-primary text-white' : 'bg-white text-slate-700 hover:bg-slate-50' ?>">Students</a>
                <a href="?<?= e(http_build_query($employeeScopeQuery)) ?>" class="px-4 py-3 text-sm font-extrabold text-decoration-none whitespace-nowrap border-l border-outline-variant <?= $populationScope === 'faculty_ntp' ? 'bg-primary text-white' : 'bg-white text-slate-700 hover:bg-slate-50' ?>">Faculty &amp; NTP</a>
            </div>
            <button type="button" onclick="showModal('apeBatchPickerModal')" class="ape-work-queue-batch min-h-12 px-4 py-2 rounded-xl border border-outline-variant bg-primary-fixed flex items-center gap-3 text-left hover:border-primary transition-colors" title="<?= e($selectedBatch !== null ? 'All queues are filtered to this batch. Choose another batch or Overall.' : 'Overall view includes records from every batch.') ?>">
                <span class="material-symbols-outlined text-primary text-[20px]">event_available</span>
                <div class="min-w-0">
                    <p class="text-[9px] font-black uppercase tracking-widest text-primary mb-0">Scheduled Batch</p>
                    <p class="text-xs font-extrabold text-slate-700 mb-0 whitespace-nowrap"><?= e($batchIndicator) ?></p>
                </div>
                <span class="material-symbols-outlined text-primary text-[18px]">expand_more</span>
            </button>
            </div>
            <div class="ape-work-queue-search search-input-wrap">
                <span class="search-icon material-symbols-outlined">search</span>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search APE records..." class="search-input">
            </div>
            <?php if ($activeQueue !== 'all'): ?>
                <input type="hidden" name="queue" value="<?= e($activeQueue) ?>">
            <?php endif; ?>
            <input type="hidden" name="population" value="<?= e($populationScope) ?>">
            <?php if ($selectedBatchId !== null): ?>
                <input type="hidden" name="batch" value="<?= (int) $selectedBatchId ?>">
            <?php else: ?>
                <input type="hidden" name="scope" value="overall">
            <?php endif; ?>
        </div>
    </div>
    </form>
    <div class="ape-queue-map-grid">
        <?php foreach ($flowQueues as $key => $queue): ?>
            <?php $queueCount = array_sum(array_map(static fn(string $sourceQueue): int => count($recordsByQueue[$sourceQueue] ?? []), $queue['source_queues'] ?? [$key])); ?>
            <a href="?queue=<?= urlencode($key) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?><?= e($batchQuerySuffix) ?>" class="ape-queue-map-card rounded-2xl border <?= $activeQueue === $key ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-3 text-decoration-none hover:bg-primary-fixed transition-colors">
                <div class="flex items-center justify-between gap-2">
                    <span class="w-8 h-8 rounded-xl bg-white text-primary border border-outline-variant flex items-center justify-center material-symbols-outlined text-[16px]"><?= e($queue['icon']) ?></span>
                    <strong class="font-headline text-xl text-[#17261d]"><?= $queueCount ?></strong>
                </div>
                <p class="ape-queue-map-label"><?= e($queue['short_title'] ?? $queue['title']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="mt-5 border-t border-slate-100 pt-5" aria-label="Overall active APE cycle clearance compliance">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm font-bold text-slate-700">
            <span>Clearance compliance</span>
            <span><?= $clearanceComplianceRate ?>% &bull; <?= $clearanceComplianceCompleted ?> of <?= $clearanceComplianceTotal ?> patient(s)</span>
        </div>
        <div class="h-3 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Clearance compliance" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $clearanceComplianceRate ?>">
            <div class="h-full rounded-full bg-[#3f8256] transition-all duration-300" style="width: <?= $clearanceComplianceRate ?>%"></div>
        </div>
    </div>
</section>

<div class="grid grid-cols-1 gap-6">
    <?php foreach ($visibleQueues as $queueKey):
        $queue = $flowQueues[$queueKey];
        $sourceQueues = $queue['source_queues'] ?? [$queueKey];
        $records = [];
        foreach ($sourceQueues as $sourceQueue) {
            $records = array_merge($records, $recordsByQueue[$sourceQueue] ?? []);
        }
        $shownRecords = $records;
        $scopeEmptyText = $search !== ''
            ? 'No records match your search in this queue.'
                : ($selectedBatchId !== null
                    ? 'No patients from this scheduled batch are in this queue.'
                    : 'No patients are currently in this queue.');
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
                    <span class="badge <?= $queueKey === 'completed' ? 'badge-completed' : ($queueKey === $combinedFinalDecisionQueue ? 'badge-high' : 'badge-in-progress') ?>">
                        <?= count($records) ?> record(s)
                    </span>
                </div>
            </div>

            <?php
            $apeRows = [];
            foreach ($shownRecords as $rec) {
                $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
                $normalizedActions = ape_normalized_action_items($rec);
                $legacyNext = ape_next_action($rec);
                $next = $normalizedActions[0] ?? [
                    'title' => $legacyNext['label'],
                    'action_label' => $legacyNext['label'],
                    'description' => ape_missing_item($rec),
                ];
                $priority = ape_priority_badge($rec);
                $hasBatch = !empty($rec['schedule_batch_id']) && ($rec['batch_status'] ?? '') !== 'Cancelled';
                $scheduleSort = $hasBatch ? (string) $rec['batch_schedule_date'] . ' ' . (string) $rec['batch_start_time'] : '9999-12-31 23:59:59';
                $scheduleHtml = $hasBatch
                    ? '<strong class="text-sm text-slate-800 block">' . e($rec['batch_name']) . '</strong><span class="text-xs font-bold text-slate-500">' . e(date('M j, Y', strtotime($rec['batch_schedule_date']))) . ' &bull; ' . e(date('g:i A', strtotime($rec['batch_start_time']))) . '–' . e(date('g:i A', strtotime($rec['batch_end_time']))) . '</span>'
                    : '<span class="badge badge-pending">Unscheduled</span>';
                if (ape_staff_queue_stage($rec) === 'follow_up' && !empty($rec['follow_up_due_date'])) {
                    $scheduleSort = $rec['follow_up_due_date'];
                    $scheduleHtml = '<strong class="text-sm text-slate-800 block">Return Date</strong><span class="text-xs font-bold text-slate-500">'
                        . e(date('M j, Y', strtotime($rec['follow_up_due_date']))) . '</span>';
                }
                $apeRows[] = [
                    'rowUrl' => 'view.php?id=' . (int)$rec['id'],
                    'prioritySort' => array_search($priority['label'], ['Missed', 'Overdue', 'Urgent', 'Clinical', 'Waiting', 'Ready', 'Done'], true),
                    'priorityHtml' => '<span class="badge ' . e($priority['class']) . '">' . e($priority['label']) . '</span>',
                    'studentSort' => trim($rec['last_name'] . ' ' . $rec['first_name']),
                    'studentHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-xs font-bold text-slate-400">' . e($rec['id_number']) . '</div></div></div>',
                    'programSort' => $rec['course_section'] ?: '',
                    'programHtml' => '<p class="text-sm font-bold text-slate-700 mb-1">' . e($rec['course_section'] ?: 'No course set') . '</p><p class="text-xs font-bold text-slate-400 mb-0">' . e($rec['document_type'] ?: 'APE documents') . '</p>',
                    'waiting' => ape_waiting_label($rec),
                    'waitingSort' => ape_waiting_days($rec),
                    'nextActionSort' => (string) ($next['action_label'] ?? $next['title'] ?? ''),
                    'nextActionHtml' => '<div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[18px]">task_alt</span><div><strong class="block text-sm text-slate-800">' . e((string) ($next['action_label'] ?? $next['title'] ?? 'Review APE record')) . '</strong><span class="block text-xs font-bold text-slate-400">' . e((string) ($next['description'] ?? ape_missing_item($rec))) . '</span></div></div>',
                ];
                if ($populationScope === 'students') {
                    $apeRows[array_key_last($apeRows)]['scheduleSort'] = $scheduleSort;
                    $apeRows[array_key_last($apeRows)]['scheduleHtml'] = $scheduleHtml;
                }
            }
            render_ag_grid('apeGrid' . $gridSuffix, $apeQueueColumns, $apeRows, [
                'pageSize' => 10,
                'pagination' => true,
                'paginationControls' => $paginationId,
                'height' => 'compact',
                'emptyTitle' => 'No patients here',
                'emptyText' => $scopeEmptyText,
                'stateKey' => 'ape-work-queue-' . $populationScope . '-' . $queueKey,
            ]);
            ?>
            <nav id="<?= e($paginationId) ?>" class="pagination" aria-label="<?= e($queue['title']) ?> pages"></nav>
        </section>
    <?php endforeach; ?>
</div>

<script>
document.querySelectorAll('[data-ape-persistent-details]').forEach((details) => {
    const key = `cliniq-ape-details:${details.dataset.apePersistentDetails}`;
    const saved = window.localStorage.getItem(key);
    if (saved !== null) details.open = saved === 'open';
    details.addEventListener('toggle', () => window.localStorage.setItem(key, details.open ? 'open' : 'closed'));
});
</script>

<div id="apeBatchPickerModal" class="modal-backdrop" data-no-row-click>
    <div class="modal-content bg-white rounded-[2rem] w-full max-w-3xl p-8 shadow-2xl border border-outline-variant/10" style="max-height:90vh;display:flex;flex-direction:column;overflow:hidden;">
        <div class="flex items-start justify-between gap-4 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-11 h-11 bg-primary-fixed text-primary rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined">calendar_month</span>
                </div>
                <div>
                    <h3 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">Scheduled APE Batches</h3>
                    <p class="text-sm font-bold text-slate-500 mb-0">
                        <?= e((string) ($activeApeCycle['academic_year'] ?? 'Current school year')) ?> &bull; The earliest upcoming batch is selected automatically. Choose Overall to show every batch.
                    </p>
                </div>
            </div>
            <button type="button" onclick="closeModal('apeBatchPickerModal')" class="btn-icon btn-icon-slate" aria-label="Close scheduled batch list">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <?php
        $defaultQuery = ['queue' => $activeQueue, 'scope' => 'overall', 'population' => $populationScope];
        if ($search !== '') {
            $defaultQuery['q'] = $search;
        }
        ?>
        <a href="?<?= e(http_build_query($defaultQuery)) ?>" class="flex items-center justify-between gap-4 rounded-2xl border <?= $isOverallView ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-4 mb-3 text-decoration-none hover:border-primary transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <span class="w-10 h-10 rounded-xl bg-white border border-outline-variant text-primary flex items-center justify-center material-symbols-outlined shrink-0">today</span>
                <div class="min-w-0">
                    <strong class="block text-sm text-slate-800">Overall</strong>
                    <span class="block text-xs font-bold text-slate-500">Show every APE queue, metric, and patient record across all batches.</span>
                    <span class="block text-xs font-bold text-slate-500"><?= count($overallRecords) ?> total APE record(s)</span>
                </div>
            </div>
            <?php if ($isOverallView): ?>
                <span class="badge badge-completed">Viewing</span>
            <?php else: ?>
                <span class="material-symbols-outlined text-primary">arrow_forward</span>
            <?php endif; ?>
        </a>

        <div id="apeBatchPickerList" class="space-y-3 overflow-y-auto pr-1" style="min-height:0;flex:1;overscroll-behavior:contain;" tabindex="0" aria-label="Scheduled batches">
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
                    $batchQuery = ['queue' => $activeQueue, 'batch' => $batchId, 'population' => 'students'];
                    if ($search !== '') {
                        $batchQuery['q'] = $search;
                    }
                    $isSelectedBatch = $selectedBatchId === $batchId;
                    $progress = $batchProgress[$batchId] ?? ['total' => 0, 'completed' => 0];
                    ?>
                    <a data-batch-picker-card data-selected="<?= $isSelectedBatch ? 'true' : 'false' ?>" href="?<?= e(http_build_query($batchQuery)) ?>" class="flex items-center justify-between gap-4 rounded-2xl border <?= $isSelectedBatch ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-4 text-decoration-none hover:border-primary hover:bg-primary-fixed transition-colors">
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
                            <div class="flex flex-wrap items-center gap-2 mt-3" aria-label="Batch patient progress">
                                <?php foreach ([
                                    'examination' => ['Awaiting examination', 'badge-pending'],
                                    'incomplete' => ['Incomplete requirements', 'badge-high'],
                                    'correction' => ['Needs correction', 'badge-high'],
                                    'digital_submission' => ['Digital submission pending', 'badge-pending'],
                                    'final_decision' => ['Awaiting final decision', 'badge-in-progress'],
                                    'follow_up' => ['Follow-up', 'badge-high'],
                                ] as $progressKey => [$progressLabel, $progressClass]): ?>
                                    <?php if (($progress[$progressKey] ?? 0) > 0): ?>
                                        <span class="badge <?= e($progressClass) ?>"><?= (int) $progress[$progressKey] ?> <?= e($progressLabel) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <span class="badge <?= $progress['total'] > 0 && $progress['completed'] === $progress['total'] ? 'badge-completed' : 'badge-in-progress' ?>"><?= (int) $progress['completed'] ?> / <?= (int) $progress['total'] ?> completed</span>
                            </div>
                            <?php if (($progress['incomplete'] ?? 0) > 0 || ($progress['correction'] ?? 0) > 0): ?>
                                <p class="text-xs font-bold text-slate-500 mt-2 mb-0">Counts are patients. Requirement alerts may overlap with pending stages.</p>
                            <?php endif; ?>
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
        <div class="flex flex-wrap items-center justify-between gap-3 pt-4 mt-4 border-t border-outline-variant" style="flex-shrink:0;">
            <label class="flex items-center gap-2 text-xs font-bold text-slate-500" for="apeBatchPickerLimit">Batches per page
                <select id="apeBatchPickerLimit" class="clinic-select" style="width:auto;">
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="20">20</option>
                    <option value="all">All batches</option>
                </select>
            </label>
            <span id="apeBatchPickerRange" class="text-xs font-bold text-slate-500" role="status" aria-live="polite"></span>
            <nav class="flex items-center gap-2" aria-label="Batch list pages">
                <button id="apeBatchPickerPrevious" type="button" class="btn btn-secondary btn-sm">Previous</button>
                <span id="apeBatchPickerPage" class="text-xs font-bold text-slate-500"></span>
                <button id="apeBatchPickerNext" type="button" class="btn btn-secondary btn-sm">Next</button>
            </nav>
        </div>
    </div>
</div>

<script>
(() => {
    const list = document.getElementById('apeBatchPickerList');
    const cards = Array.from(list.querySelectorAll('[data-batch-picker-card]'));
    const limit = document.getElementById('apeBatchPickerLimit');
    const previous = document.getElementById('apeBatchPickerPrevious');
    const next = document.getElementById('apeBatchPickerNext');
    const selectedIndex = cards.findIndex(card => card.dataset.selected === 'true');
    let page = Math.floor(Math.max(0, selectedIndex) / Number(limit.value));
    function render() {
        const showAll = limit.value === 'all';
        const size = showAll ? Math.max(cards.length, 1) : Number(limit.value);
        const pages = Math.max(1, Math.ceil(cards.length / size));
        page = Math.max(0, Math.min(page, pages - 1));
        const start = page * size;
        cards.forEach((card, index) => {
            card.style.display = index >= start && index < start + size ? '' : 'none';
        });
        previous.disabled = showAll || page === 0;
        next.disabled = showAll || page >= pages - 1;
        limit.disabled = cards.length === 0;
        document.getElementById('apeBatchPickerPage').textContent = showAll ? 'All batches' : `Page ${page + 1} of ${pages}`;
        document.getElementById('apeBatchPickerRange').textContent = cards.length
            ? `${start + 1}–${Math.min(start + size, cards.length)} of ${cards.length} batches`
            : '0 batches';
        list.scrollTop = 0;
    }
    previous.addEventListener('click', () => { page--; render(); });
    next.addEventListener('click', () => { page++; render(); });
    limit.addEventListener('change', () => { page = 0; render(); });
    render();
})();
</script>

<?php render_footer(); ?>
