<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../../app/services/ApeCycleService.php';
require_once __DIR__ . '/../../app/services/ClinicWorkCenter.php';
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
    $_GET = array_merge($_GET, array_intersect_key(
        $_SESSION['ape_work_queue_state'],
        array_flip(['queue', 'q', 'population'])
    ));
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
$apeCycles = auth_db()->query('SELECT ape_cycle_id, academic_year, status FROM ape_cycles ORDER BY started_at DESC, ape_cycle_id DESC')->fetchAll();
$requestedCycleId = (int) ($_GET['cycle'] ?? 0);
$selectedCycle = null;
foreach ($apeCycles as $cycle) {
    if ((int) $cycle['ape_cycle_id'] === $requestedCycleId) {
        $selectedCycle = $cycle;
        break;
    }
}
$selectedCycle ??= $activeApeCycle;
$selectedCycleId = (int) ($selectedCycle['ape_cycle_id'] ?? 0);
$historicalCycleView = $selectedCycleId > 0 && (string) ($selectedCycle['status'] ?? '') !== 'Active';
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
$requestedBatchId = filter_var($_GET['batch'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$selectedBatchId = null;
if ($populationScope === 'students') {
    $resolvedBatchSelection = ape_resolve_batch_selection(
        $scheduledBatchesById,
        $requestedBatchId ? (int) $requestedBatchId : null
    );
    $selectedBatchId = $resolvedBatchSelection['batch_id'];
}
unset($_SESSION['ape_batch_selection_v2']);
$selectedBatch = $selectedBatchId !== null ? $scheduledBatchesById[$selectedBatchId] : null;
$isOverallView = $selectedBatch === null;

$persistedApeState = [
    'queue' => $activeQueue,
    'population' => $populationScope,
];
if ($search !== '') {
    $persistedApeState['q'] = $search;
}
$_SESSION['ape_work_queue_state'] = $persistedApeState;

$apeQueueReturnParams = ['queue' => $activeQueue, 'population' => $populationScope];
if ($search !== '') $apeQueueReturnParams['q'] = $search;
if ($selectedCycleId > 0) $apeQueueReturnParams['cycle'] = $selectedCycleId;
if ($selectedBatchId !== null) $apeQueueReturnParams['batch'] = $selectedBatchId;
elseif ($populationScope === 'students') $apeQueueReturnParams['scope'] = 'overall';
$apeQueueReturnUrl = 'index.php?' . http_build_query($apeQueueReturnParams);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_ape_follow_up') {
    try {
        if ($populationScope !== 'students' || !in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor', 'nurse'], true)) {
            throw new RuntimeException('Only authorized clinic staff can send an APE follow-up.');
        }
        $sourceId = filter_var($_POST['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $patientPersonId = filter_var($_POST['patient_person_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $eventType = trim((string) ($_POST['event_type'] ?? ''));
        $candidate = null;
        foreach (clinic_work_center_reminder_candidates($selectedBatchId) as $item) {
            if (empty($item['can_send_reminder'])) continue;
            if ((int) ($item['patient_person_id'] ?? 0) !== $patientPersonId
                || (int) ($item['email_source_id'] ?? $item['source_id'] ?? 0) !== $sourceId
                || (string) ($item['email_event_type'] ?? '') !== $eventType) continue;
            $candidate = $item;
            break;
        }
        if ($candidate === null) {
            throw new RuntimeException('This follow-up is no longer ready to send. Refresh the queue to review its current status.');
        }
        $result = clinic_work_center_send_reminders((int) ($apeUser['person_id'] ?? 0), [[
            'patient_person_id' => $patientPersonId,
            'source_type' => 'ape',
            'source_id' => $sourceId,
            'event_type' => $eventType,
            'follow_up_number' => (int) ($candidate['follow_up_number'] ?? 0),
            'subject' => (string) ($_POST['subject'] ?? ''),
            'message' => (string) ($_POST['message'] ?? ''),
        ]], $selectedBatchId);
        if ((int) ($result['sent'] ?? 0) > 0) flash_message('success', 'APE follow-up email sent.');
        elseif ((int) ($result['queued'] ?? 0) > 0) flash_message('success', 'APE follow-up email queued for delivery.');
        else throw new RuntimeException('The follow-up was not sent. Refresh the queue and review the current delivery status.');
    } catch (Throwable $e) {
        flash_message($e instanceof InvalidArgumentException || $e instanceof RuntimeException ? 'warning' : 'error', $e->getMessage());
    }
    header('Location: ' . $apeQueueReturnUrl);
    exit;
}

$overallRecords = ape_fetch_records('', null, null, null, $selectedCycleId);
$scopeRecords = ape_fetch_records($search, null, null, $historicalCycleView ? null : $selectedBatchId, $selectedCycleId);
$scopeRecords = array_values(array_filter($scopeRecords, static function (array $record) use ($populationScope): bool {
    $isStaffApe = ape_is_staff_managed_record($record);
    return $populationScope === 'faculty_ntp'
        ? $isStaffApe
        : (($record['entry_mode'] ?? '') !== 'Clinic Manual');
}));
$batchProgress = ape_batch_progress($schoolYearBatches === [] ? [] : $overallRecords);
$allRecords = $scopeRecords;
$clearanceComplianceCompleted = count(array_filter(
    $allRecords,
    static fn(array $record): bool => in_array(ape_staff_queue_stage($record), ['completed', 'staff_submitted'], true)
));
$clearanceComplianceTotal = count($allRecords);
$clearanceComplianceRate = $clearanceComplianceTotal > 0
    ? (int) round(($clearanceComplianceCompleted / $clearanceComplianceTotal) * 100)
    : 0;
$batchIndicator = $selectedBatch !== null
    ? (string) $selectedBatch['batch_name']
    : 'Overall';
$scopeDescription = $selectedBatch !== null
    ? 'All queues and totals show only patients assigned to ' . (string) $selectedBatch['batch_name'] . '. Choose Overall to show every batch.'
    : ($historicalCycleView
        ? 'Viewing the ' . (string) ($selectedCycle['academic_year'] ?? '') . ' cycle. Historical records are read-only.'
    : ($populationScope === 'faculty_ntp'
        ? 'All queues and totals show clinic-manual Faculty and NTP records only.'
        : 'All queues and totals show student records from every batch.'));
$scopeCountLabel = 'Records in scope';
$batchQuerySuffix = $populationScope === 'faculty_ntp'
    ? '&scope=overall&population=faculty_ntp'
    : ($selectedBatchId !== null ? '&batch=' . $selectedBatchId : '&scope=overall&selection=overall');
$batchQuerySuffix .= $selectedCycleId > 0 ? '&cycle=' . $selectedCycleId : '';

$recordsByQueue = array_fill_keys(array_keys($queues), []);
foreach ($allRecords as $record) {
    $recordsByQueue[ape_staff_queue_stage($record)][] = $record;
}

$flowQueues = $populationScope === 'faculty_ntp'
    ? [
        'staff_awaiting_certificate' => $queues['staff_awaiting_certificate'],
        'staff_submitted' => $queues['staff_submitted'],
    ]
    : [
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
foreach ($populationScope === 'faculty_ntp' ? ['staff_awaiting_certificate'] : ['examination', 'digital_submission', 'final_decision', 'follow_up'] as $key) {
    $needsAction += count($recordsByQueue[$key]);
}

$metrics = [
    'total' => count($allRecords),
    'clinic_action' => $needsAction,
    'digitized' => count(array_filter($allRecords, static fn(array $record): bool => !empty($record['document_path']))),
    'follow_up' => count($recordsByQueue['follow_up']),
    'completed' => $populationScope === 'faculty_ntp' ? count($recordsByQueue['staff_submitted']) : count($recordsByQueue['completed']),
];
$clearanceRate = $metrics['total'] > 0 ? round(($metrics['completed'] / $metrics['total']) * 100) : 0;

// Top bar stats
$activePatients = $metrics['total'] - $metrics['completed'];
$appointmentsStmt = appointment_db()->query("SELECT COUNT(*) AS total FROM appointments WHERE DATE(appointment_datetime) = CURDATE()");
$appointmentsToday = (int)($appointmentsStmt->fetch()['total'] ?? 0);

$apeReminderCandidatesByRecordId = [];
$apeMissedExamEmailSentByRecordId = [];
foreach (clinic_work_center_reminder_candidates($selectedBatchId) as $reminderItem) {
    if ((string) ($reminderItem['email_source_type'] ?? $reminderItem['source_type'] ?? '') !== 'ape') continue;
    $apeId = (int) ($reminderItem['email_source_id'] ?? $reminderItem['source_id'] ?? 0);
    if ($apeId < 1) continue;
    $apeReminderCandidatesByRecordId[$apeId][] = $reminderItem;
    if ((string) ($reminderItem['email_event_type'] ?? '') === 'ape_exam_missed'
        && (int) ($reminderItem['automatic_sent_count'] ?? 0) > 0) {
        $apeMissedExamEmailSentByRecordId[$apeId] = true;
    }
}
$apeReminderByRecordId = [];
foreach ($apeReminderCandidatesByRecordId as $apeId => $reminderItems) {
    foreach ($reminderItems as $reminderItem) {
        if (empty($reminderItem['can_send_reminder'])) continue;
        $apeReminderByRecordId[$apeId] = $reminderItem;
        break;
    }
    $apeReminderByRecordId[$apeId] ??= $reminderItems[0];
}
$recordsByApeId = [];
$overdueRecords = [];
foreach ($allRecords as $rec) {
    $apeId = (int) ($rec['ape_id'] ?? $rec['id'] ?? 0);
    if ($apeId < 1) continue;
    $recordsByApeId[$apeId] = $rec;
    $missedExamEmailSent = !empty($apeMissedExamEmailSentByRecordId[$apeId]);
    if (!ape_show_immediate_attention_warning($rec, $missedExamEmailSent)) continue;

    $priority = ape_priority_badge($rec);
    if ($priority['label'] === 'Missed' && ape_record_queue($rec) === 'examination') {
        $overdueRecords[$apeId] = ['record' => $rec, 'attention' => ['kind' => 'missed']];
        continue;
    }
    foreach (ape_patient_document_action_summaries($rec) as $action) {
        if (($action['priority'] ?? '') !== 'overdue') continue;
        $overdueRecords[$apeId] = ['record' => $rec, 'attention' => ['kind' => 'overdue', 'action' => $action]];
        break;
    }
}
$apeReadyFollowUpCount = count(array_filter($apeReminderByRecordId, static fn(array $item): bool => !empty($item['can_send_reminder'])));
$canReviewApeFollowUps = $populationScope === 'students'
    && $apeReadyFollowUpCount > 0
    && in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor', 'nurse'], true);
if ($canReviewApeFollowUps) {
    foreach ($apeReminderByRecordId as $apeId => $reminderItem) {
        if (empty($reminderItem['can_send_reminder']) || !isset($recordsByApeId[$apeId])) continue;
        if (!isset($overdueRecords[$apeId])) {
            $overdueRecords[$apeId] = ['record' => $recordsByApeId[$apeId], 'attention' => ['kind' => 'follow_up_ready']];
        }
        $overdueRecords[$apeId]['attention']['follow_up_ready'] = true;
        $overdueRecords[$apeId]['attention']['reminder'] = $reminderItem;
    }
}
$apeFollowUpReviewItems = [];
if ($canReviewApeFollowUps) {
    foreach ($overdueRecords as $apeId => $urgentItem) {
        $attention = $urgentItem['attention'] ?? [];
        if (empty($attention['follow_up_ready'])) continue;
        $record = $urgentItem['record'];
        $reminder = $attention['reminder'] ?? ($apeReminderByRecordId[$apeId] ?? null);
        if (!$reminder) continue;
        $apeFollowUpReviewItems[(int) $apeId] = [
            'record' => $record,
            'reminder' => $reminder,
            'history' => [],
        ];
    }
}
if ($apeFollowUpReviewItems !== []) {
    $reviewApeIds = array_keys($apeFollowUpReviewItems);
    $placeholders = implode(',', array_fill(0, count($reviewApeIds), '?'));
    $emailHistory = auth_db()->prepare("SELECT source_id, origin, status, subject, COALESCE(sent_at, created_at) AS occurred_at
        FROM email_queue WHERE source_type = 'ape' AND source_id IN ({$placeholders}) AND status <> 'cancelled'
        ORDER BY COALESCE(sent_at, created_at) DESC, id DESC");
    $emailHistory->execute($reviewApeIds);
    foreach ($emailHistory->fetchAll() as $entry) {
        $apeId = (int) ($entry['source_id'] ?? 0);
        if (!isset($apeFollowUpReviewItems[$apeId])) continue;
        $apeFollowUpReviewItems[$apeId]['history'][] = ['kind' => 'email', 'title' => (string) ($entry['subject'] ?? 'APE email'), 'detail' => ucfirst((string) ($entry['origin'] ?? 'system')) . ' email ' . (string) ($entry['status'] ?? 'recorded'), 'occurred_at' => (string) ($entry['occurred_at'] ?? '')];
    }
    $notificationHistory = auth_db()->prepare("SELECT source_id, title, message, created_at
        FROM patient_notifications WHERE category = 'ape' AND source_type = 'ape' AND source_id IN ({$placeholders})
        ORDER BY created_at DESC, notification_id DESC");
    $notificationHistory->execute($reviewApeIds);
    foreach ($notificationHistory->fetchAll() as $entry) {
        $apeId = (int) ($entry['source_id'] ?? 0);
        if (!isset($apeFollowUpReviewItems[$apeId])) continue;
        $apeFollowUpReviewItems[$apeId]['history'][] = ['kind' => 'notification', 'title' => (string) ($entry['title'] ?? 'APE update'), 'detail' => (string) ($entry['message'] ?? ''), 'occurred_at' => (string) ($entry['created_at'] ?? '')];
    }
    foreach ($apeFollowUpReviewItems as &$reviewItem) {
        usort($reviewItem['history'], static fn(array $left, array $right): int => strcmp((string) ($right['occurred_at'] ?? ''), (string) ($left['occurred_at'] ?? '')));
    }
    unset($reviewItem);
}

$apeQueueColumns = [
    ['headerName' => 'Priority', 'field' => 'priorityHtml', 'cellRenderer' => 'html', 'sortField' => 'prioritySort', 'sortType' => 'number', 'width' => 145],
    ['headerName' => 'Patient', 'field' => 'studentHtml', 'cellRenderer' => 'html', 'sortField' => 'studentSort', 'width' => 250],
    ['headerName' => 'Program', 'field' => 'programHtml', 'cellRenderer' => 'html', 'sortField' => 'programSort', 'width' => 190],
    ['headerName' => 'Waiting', 'field' => 'waiting', 'sortField' => 'waitingSort', 'sortType' => 'number', 'width' => 145],
    ['headerName' => 'Next Action', 'field' => 'nextActionHtml', 'cellRenderer' => 'html', 'sortField' => 'nextActionSort', 'width' => 320],
];
if ($populationScope === 'students') {
    array_splice($apeQueueColumns, 3, 0, [[
        'headerName' => 'APE Schedule', 'field' => 'scheduleHtml', 'cellRenderer' => 'html',
        'sortField' => 'scheduleSort', 'width' => 210,
    ]]);
}

render_header('APE Work Queues');

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
    'APE Work Queues',
    $populationScope === 'faculty_ntp'
        ? 'Clinic-managed Faculty and NTP records. No scheduling is required.'
        : ($selectedBatch !== null ? 'Reviewing the selected scheduled APE batch.' : 'Reviewing student APE records for the active school year.'),
    $apeHeaderActions
);
?>

<?php if (count($overdueRecords) > 0): ?>
<details class="bg-red-50 border border-red-200 rounded-2xl mb-8 group" data-ape-persistent-details="attention">
    <summary class="px-5 py-4 text-red-700 flex items-center justify-between gap-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
        <span class="flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">error</span><h2 class="font-headline font-extrabold text-sm m-0"><?= count($overdueRecords) ?> patient(s) need immediate attention</h2></span>
        <span class="material-symbols-outlined text-[20px] transition-transform group-open:rotate-180">expand_more</span>
    </summary>
    <div class="divide-y divide-red-100/50">
        <?php foreach ($overdueRecords as $urgentItem): $rec = $urgentItem['record']; $attention = $urgentItem['attention'];
            $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
            $next = ape_next_action($rec);
            $isMissed = ($attention['kind'] ?? '') === 'missed';
            $isFollowUpReady = !empty($attention['follow_up_ready']);
            $dueDate = (string) ($attention['action']['due_at'] ?? '');
            $days = $dueDate === '' ? 0 : max(0, (int) ((new DateTimeImmutable('today'))->diff(new DateTimeImmutable($dueDate))->format('%r%a') * -1));
            $warningClass = 'text-red-600';
            $badgeIcon = $isFollowUpReady ? 'mail' : ($isMissed ? 'event_busy' : 'error');
            $deadlineText = $isFollowUpReady ? 'Follow-up email ready' : ($isMissed ? 'Assigned batch was missed' : 'Overdue - ' . $days . 'd');
            $canExamineNow = empty($rec['exam_date']) && ape_examination_is_available($rec);
            $apeId = (int) ($rec['ape_id'] ?? $rec['id'] ?? 0);
            $reminderItem = $attention['reminder'] ?? ($apeReminderByRecordId[$apeId] ?? null);
            $emailStatus = '';
            if ($reminderItem) {
                $sentCount = (int) ($reminderItem['sent_count'] ?? 0);
                $staffFollowUpCount = (int) ($reminderItem['manual_follow_up_count'] ?? 0);
                $emailStatus = $sentCount . ' email' . ($sentCount === 1 ? '' : 's') . ' sent to email service · '
                    . $staffFollowUpCount . ' of 2 staff follow-ups';
                if ($isFollowUpReady) $emailStatus .= ' · Follow-up email ready for staff review.';
                else $emailStatus .= ' · ' . (string) ($reminderItem['reminder_reason'] ?? '');
                if (($reminderItem['reminder_state'] ?? '') === 'cooldown' && !empty($reminderItem['next_eligible_at'])) $emailStatus .= ' Available ' . date('M j, g:i A', strtotime((string) $reminderItem['next_eligible_at'])) . '.';
            }
        ?>
            <div class="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-red-100/30 transition-colors">
                <div>
                    <h3 class="font-bold text-slate-800 text-base mb-1"><?= e($fullName) ?></h3>
                    <p class="text-xs font-bold text-slate-500 m-0">
                        <?= e($rec['id_number']) ?> &bull; <?= e($rec['course_section'] ?: 'No course') ?> &bull; <?= e($next['label']) ?> &mdash; <?= $isMissed ? 'missed' : 'overdue' ?>
                    </p>
                    <?php if ($emailStatus !== ''): ?><p class="text-xs text-slate-500 mt-2 mb-0"><span class="material-symbols-outlined text-[14px] align-bottom">mail</span> <?= e($emailStatus) ?></p><?php endif; ?>
                </div>
                <div class="flex flex-wrap items-center gap-3 shrink-0">
                    <span class="text-xs font-bold <?= $warningClass ?> flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]"><?= $badgeIcon ?></span>
                        <?= e($deadlineText) ?>
                    </span>
                    <?php if ($isFollowUpReady): ?><button type="button" class="btn btn-sm btn-primary" onclick="showModal('apeFollowUpDecisionModal<?= $apeId ?>')"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">mail</span>Review email follow-up</button><?php endif; ?>
                    <?php if (($attention['kind'] ?? '') !== 'follow_up_ready'): ?><a href="view.php?id=<?= $apeId ?>" class="text-xs font-bold text-red-700 hover:text-red-800 text-decoration-none flex items-center gap-1"><?= $canExamineNow ? 'Examine Patient' : 'Resolve' ?> <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</details>

<?php foreach ($apeFollowUpReviewItems as $reviewApeId => $reviewItem):
    $reviewRecord = $reviewItem['record'];
    $reviewReminder = $reviewItem['reminder'];
    $reviewSubject = (string) ($reviewReminder['email_subject'] ?? 'APE reminder — action needed');
    $reviewMessage = (string) ($reviewReminder['email_message'] ?? 'Please log in to your student portal and complete the outstanding APE action, or contact the clinic if you need assistance.');
    if (!str_contains($reviewMessage, 'https://plpuhs.dpdns.org')) $reviewMessage .= ' Student portal: https://plpuhs.dpdns.org';
    $canMakeReviewRecordInactive = ape_record_mutation_allowed($reviewRecord)
        && in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor'], true);
?>
    <div id="apeFollowUpDecisionModal<?= $reviewApeId ?>" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="apeFollowUpDecisionTitle<?= $reviewApeId ?>">
        <div class="modal-content bg-white rounded-[2rem] p-6 md:p-8 w-full max-w-3xl shadow-2xl max-h-[92vh] overflow-y-auto">
            <div class="flex items-start justify-between gap-4 mb-6"><div><p class="text-[11px] font-black uppercase tracking-widest text-red-700 mb-2">Needs attention</p><h2 id="apeFollowUpDecisionTitle<?= $reviewApeId ?>" class="font-headline text-2xl font-extrabold text-[#17261d] mb-1">APE follow-up decision</h2><p class="text-sm text-slate-500 mb-0"><?= e(trim((string) $reviewRecord['first_name'] . ' ' . (string) $reviewRecord['last_name'])) ?> · <?= e((string) $reviewRecord['id_number']) ?> · Follow-up <?= (int) ($reviewReminder['follow_up_number'] ?? 1) ?> of 2</p></div><button type="button" class="btn btn-sm btn-ghost" onclick="closeModal('apeFollowUpDecisionModal<?= $reviewApeId ?>')">Close</button></div>
            <section class="rounded-2xl border border-slate-200 bg-slate-50 p-4 mb-5" aria-label="Student contact history"><h3 class="font-headline text-base font-extrabold text-[#17261d] mb-3">Updates sent to this student</h3><?php if (!$reviewItem['history']): ?><p class="text-sm text-slate-500 mb-0">No student-contact updates have been recorded for this APE record.</p><?php else: ?><ol class="space-y-3 list-none p-0 m-0"><?php foreach (array_slice($reviewItem['history'], 0, 12) as $history): ?><li class="border-l-2 <?= ($history['kind'] ?? '') === 'email' ? 'border-primary' : 'border-slate-300' ?> pl-3"><p class="text-sm font-bold text-slate-800 mb-0"><?= e((string) $history['title']) ?></p><p class="text-xs text-slate-600 mt-1 mb-1"><?= e((string) $history['detail']) ?></p><time class="text-[11px] font-bold text-slate-400"><?= !empty($history['occurred_at']) ? e(date('M j, Y g:i A', strtotime((string) $history['occurred_at']))) : 'Date unavailable' ?></time></li><?php endforeach; ?></ol><?php endif; ?></section>
            <form method="post" class="space-y-3"><input type="hidden" name="action" value="send_ape_follow_up"><input type="hidden" name="patient_person_id" value="<?= (int) ($reviewReminder['patient_person_id'] ?? 0) ?>"><input type="hidden" name="source_id" value="<?= $reviewApeId ?>"><input type="hidden" name="event_type" value="<?= e((string) ($reviewReminder['email_event_type'] ?? '')) ?>"><label class="clinic-label">Email subject<input class="clinic-input mt-2" name="subject" maxlength="180" required value="<?= e($reviewSubject) ?>"></label><label class="clinic-label">Email message<textarea class="clinic-textarea mt-2" name="message" maxlength="10000" rows="5" required><?= e($reviewMessage) ?></textarea></label><div class="flex justify-end"><button class="btn btn-primary" data-confirm-submit data-confirm-title="Send this APE follow-up?" data-confirm-message="The reviewed email will be sent to this student and count toward the two-follow-up limit."><span class="material-symbols-outlined text-[18px]" aria-hidden="true">send</span>Send follow-up email</button></div></form>
            <?php if ($canMakeReviewRecordInactive): ?><details class="mt-6 rounded-2xl border border-rose-200 bg-rose-50/50 p-4"><summary class="cursor-pointer font-extrabold text-rose-900">Make this APE record inactive</summary><p class="text-sm text-rose-800 mt-3">Use only when the student withdrew, transferred, declined APE, is no longer enrolled, or has a duplicate record. Documents and history are retained.</p><form method="post" action="view.php?id=<?= $reviewApeId ?>" class="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3 items-end"><input type="hidden" name="action" value="mark_ape_inactive"><input type="hidden" name="return_to" value="ape_queue"><label class="clinic-label">Reason<select class="clinic-select mt-2" name="inactive_reason" required><option value="">Select a reason</option><?php foreach (dropdown_options('ape_inactive_reason') as $reason): ?><option value="<?= e($reason) ?>"><?= e($reason) ?></option><?php endforeach; ?></select></label><label class="clinic-label">Details for Other<input class="clinic-input mt-2" name="inactive_details" maxlength="500" placeholder="Required only for Other"></label><button class="btn btn-outline" data-confirm-submit data-confirm-type="danger" data-confirm-title="Make this APE record inactive?" data-confirm-message="This stops APE work and reminders. Documents and history remain retained."><span class="material-symbols-outlined text-[18px]" aria-hidden="true">block</span>Make inactive</button></form></details><?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php endif; ?>

<section class="clinic-card p-6" data-filter-region="ape-work-queue" aria-live="polite">
    <form method="get" data-table-filter="ape-work-queue" data-filter-region="ape-work-queue">
    <div class="ape-work-queue-toolbar-shell mb-5">
        <div class="ape-work-queue-toolbar">
            <div class="ape-work-queue-titlebar">
                <div class="ape-work-queue-heading min-w-0">
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Work Queue Map</h2>
                    <p class="text-xs font-bold text-slate-500 mb-0">Track every APE queue for the selected school year and batch.</p>
                </div>
                <div class="ape-work-queue-search search-input-wrap">
                    <span class="search-icon material-symbols-outlined">search</span>
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search APE records..." class="search-input">
                </div>
            </div>
            <div class="ape-work-queue-filters">
                <div class="ape-work-queue-filter ape-work-queue-cycle">
                    <label for="ape-cycle-history">School year</label>
                    <select id="ape-cycle-history" name="cycle" class="clinic-select" onchange="this.form.submit()">
                        <?php foreach ($apeCycles as $cycle): ?>
                            <option value="<?= (int) $cycle['ape_cycle_id'] ?>" <?= (int) $cycle['ape_cycle_id'] === $selectedCycleId ? 'selected' : '' ?>><?= e((string) $cycle['academic_year']) ?> · <?= e((string) $cycle['status']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ape-work-queue-filter ape-work-queue-group">
                    <span>Patient group</span>
                    <div class="ape-work-queue-population flex rounded-xl border border-outline-variant overflow-hidden" role="group" aria-label="APE population">
                        <?php
                        $studentScopeQuery = ['queue' => $activeQueue, 'population' => 'students'];
                        if ($search !== '') $studentScopeQuery['q'] = $search;
                        if ($selectedBatchId !== null) $studentScopeQuery['batch'] = $selectedBatchId;
                        else {
                            $studentScopeQuery['scope'] = 'overall';
                            $studentScopeQuery['selection'] = 'overall';
                        }
                        $employeeScopeQuery = ['queue' => 'staff_awaiting_certificate', 'scope' => 'overall', 'population' => 'faculty_ntp'];
                        if ($search !== '') $employeeScopeQuery['q'] = $search;
                        ?>
                        <a href="?<?= e(http_build_query($studentScopeQuery)) ?>" class="px-4 py-3 text-sm font-extrabold text-decoration-none whitespace-nowrap <?= $populationScope === 'students' ? 'bg-primary text-white' : 'bg-white text-slate-700 hover:bg-slate-50' ?>">Students</a>
                        <a href="?<?= e(http_build_query($employeeScopeQuery)) ?>" class="px-4 py-3 text-sm font-extrabold text-decoration-none whitespace-nowrap border-l border-outline-variant <?= $populationScope === 'faculty_ntp' ? 'bg-primary text-white' : 'bg-white text-slate-700 hover:bg-slate-50' ?>">Faculty &amp; NTP</a>
                    </div>
                </div>
                <?php if ($populationScope === 'students'): ?>
                <div class="ape-work-queue-filter ape-work-queue-batch-filter">
                    <span>Scheduled batch</span>
                    <button type="button" onclick="showModal('apeBatchPickerModal')" class="ape-work-queue-batch min-h-12 px-4 py-2 rounded-xl border border-outline-variant bg-primary-fixed flex items-center gap-3 text-left hover:border-primary transition-colors" title="<?= e($selectedBatch !== null ? 'All queues are filtered to this batch. Choose another batch or Overall.' : 'Overall view includes records from every batch.') ?>">
                        <span class="material-symbols-outlined text-primary text-[20px]">event_available</span>
                        <span class="text-xs font-extrabold text-slate-700 whitespace-nowrap"><?= e($batchIndicator) ?></span>
                        <span class="material-symbols-outlined text-primary text-[18px] ml-auto">expand_more</span>
                    </button>
                </div>
                <?php endif; ?>
                <?php if ($populationScope === 'students' && in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor'], true)): ?>
                    <a href="scheduling.php" class="btn btn-outline ape-work-queue-scheduling ape-work-queue-primary-action text-decoration-none">
                        <span class="material-symbols-outlined text-[18px]">settings</span>
                        Manage Scheduling
                    </a>
                <?php endif; ?>
                <?php if ($populationScope === 'faculty_ntp' && in_array((string) ($apeUser['role'] ?? ''), ['admin', 'doctor', 'nurse'], true)): ?>
                    <a href="create.php" class="btn btn-outline ape-work-queue-primary-action ape-work-queue-primary-action--staff text-decoration-none"><span class="material-symbols-outlined text-[18px]">settings</span> Open Staff APE</a>
                <?php endif; ?>
            </div>
            <p class="ape-work-queue-scope mb-0">
                <span class="material-symbols-outlined" aria-hidden="true">info</span>
                <?= e($scopeDescription) ?>
            </p>
            <?php if ($activeQueue !== 'all'): ?>
                <input type="hidden" name="queue" value="<?= e($activeQueue) ?>">
            <?php endif; ?>
            <input type="hidden" name="population" value="<?= e($populationScope) ?>">
            <?php if ($selectedBatchId !== null): ?>
                <input type="hidden" name="batch" value="<?= (int) $selectedBatchId ?>">
            <?php else: ?>
                <input type="hidden" name="scope" value="overall">
                <?php if ($populationScope === 'students'): ?>
                    <input type="hidden" name="selection" value="overall">
                <?php endif; ?>
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
            <span><?= $populationScope === 'faculty_ntp' ? 'Certificate submission' : 'Clearance compliance' ?></span>
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
                'fitColumns' => false,
                'emptyTitle' => 'No patients here',
                'emptyText' => $scopeEmptyText,
                'stateKey' => 'ape-work-queue-v2-' . $populationScope . '-' . $queueKey,
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
                        <?= e((string) ($activeApeCycle['academic_year'] ?? 'Current school year')) ?> &bull; The next upcoming batch is selected automatically, or the most recent batch when none are upcoming. Choose Overall to show every batch.
                    </p>
                </div>
            </div>
            <button type="button" onclick="closeModal('apeBatchPickerModal')" class="btn-icon btn-icon-slate" aria-label="Close scheduled batch list">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <?php
        $defaultQuery = ['queue' => $activeQueue, 'scope' => 'overall', 'selection' => 'overall', 'population' => $populationScope];
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
