<?php

require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../app/services/AlertWorkflow.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/../app/services/CliniqVisitWorkflow.php';
require_login();

$firstRegistrationError = '';
if (first_registration_pending('staff')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_first_registration') {
        try {
            complete_first_registration(
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? '')
            );
            header('Location: dashboard.php?activated=1');
            exit;
        } catch (Throwable $e) {
            $firstRegistrationError = $e->getMessage();
        }
    }

    $registrationUser = current_user() ?? [];
    render_header('Complete Registration');
    ?>
    <div class="dashboard-page">
        <?php render_clinic_command_header(
            'First Registration',
            'Welcome, ' . (string) ($registrationUser['name'] ?? 'Clinic Staff'),
            'Create your permanent password to activate and unlock your clinic staff account.',
            '<span class="badge badge-pending">Activation Required</span>'
        ); ?>

        <section class="clinic-card p-6 max-w-2xl mx-auto">
            <div class="flex items-start gap-4 mb-5">
                <span class="w-12 h-12 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined">password</span>
                </span>
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Create your password</h2>
                    <p class="text-sm font-bold text-slate-500 mb-0">Your identity has been verified. Clinic functions remain locked until activation is completed.</p>
                </div>
            </div>

            <?php if ($firstRegistrationError !== ''): ?>
                <div class="rounded-xl bg-red-50 border border-red-100 text-red-700 px-4 py-3 text-sm font-bold mb-5">
                    <?= e($firstRegistrationError) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4" autocomplete="off">
                <input type="hidden" name="action" value="complete_first_registration">
                <div>
                    <label class="clinic-label" for="password">Create Password</label>
                    <input class="clinic-input" id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div>
                    <label class="clinic-label" for="confirm-password">Confirm Password</label>
                    <input class="clinic-input" id="confirm-password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <p class="text-xs font-bold text-slate-500">Use at least 8 characters with at least one number.</p>
                <button type="submit" class="btn btn-primary w-full justify-center">
                    <span class="material-symbols-outlined">verified_user</span>
                    Activate Account
                </button>
            </form>
        </section>
    </div>
    <?php
    render_footer();
    return;
}

ensure_ape_workflow_schema();
ensure_alert_workflow_schema();
ensure_appointment_schema();
appointment_sync_overdue_confirmations();

// --- 1. Metrics & Analytics ---
$metrics = [
    'visits_today' => (int) (cliniq_visit_db()->query('SELECT COUNT(*) AS total FROM visits WHERE DATE(visit_datetime) = CURDATE()')->fetch()['total'] ?? 0),
    'pending_alerts' => (int) (auth_db()->query("SELECT COUNT(*) AS total FROM nurse_alerts WHERE status = 'Pending'")->fetch()['total'] ?? 0),
    'low_stock' => (int) (cliniq_inventory_db()->query('SELECT COUNT(*) AS total FROM inventory_items WHERE is_active = 1 AND quantity <= reorder_level')->fetch()['total'] ?? 0),
    'appointment_requests' => (int) (appointment_db()->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'Pending'")->fetch()['total'] ?? 0),
];

// APE Metrics (condensed)
$apeTotal = (int) (auth_db()->query('SELECT COUNT(*) AS total FROM ape_records')->fetch()['total'] ?? 0);
$apeCleared = (int) (auth_db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE workflow_status = 'Cleared' OR clearance_status = 'Cleared'")->fetch()['total'] ?? 0);
$metrics['ape_clearance_rate'] = $apeTotal > 0 ? round(($apeCleared / $apeTotal) * 100) : 0;
$metrics['ape_pending_review'] = (int) (auth_db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE COALESCE(requirement_status, '') <> 'Pre-Verified'")->fetch()['total'] ?? 0);


// --- 2. Live Alerts (Most Urgent First) ---
$activeAlerts = auth_db()->query("
    SELECT a.*, p.first_name, p.last_name
    FROM nurse_alerts a
    LEFT JOIN patients pt ON pt.person_id = a.patient_id
    LEFT JOIN people p ON p.id = pt.person_id
    WHERE a.status = 'Pending'
    ORDER BY
        CASE COALESCE(a.risk_level, 'Low')
            WHEN 'Critical' THEN 4
            WHEN 'High' THEN 3
            WHEN 'Moderate' THEN 2
            ELSE 1
        END DESC,
        COALESCE(a.risk_score, 0) DESC,
        a.created_at DESC,
        a.id DESC
    LIMIT 2
")->fetchAll();

// --- 3. Today's Appointments (Scheduled) ---
$appointmentsStmt = appointment_db()->query("
    SELECT a.*, p.first_name, p.last_name, p.id_number
    FROM appointments a
    JOIN patients pt ON pt.person_id = a.patient_id
    JOIN people p ON p.id = pt.person_id
    WHERE DATE(a.appointment_datetime) = CURDATE()
      AND a.status IN ('Scheduled', 'For Confirmation')
    ORDER BY a.appointment_datetime ASC
");
$appointments = $appointmentsStmt ? $appointmentsStmt->fetchAll() : [];

// --- 3b. Pending Appointment Requests (awaiting approval) ---
$pendingAppointmentsStmt = appointment_db()->query("
    SELECT a.*, p.first_name, p.last_name, p.id_number,
           COALESCE(
               NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''),
               ed.department_code,
               'Patient'
           ) AS course_section
    FROM appointments a
    JOIN patients pt ON pt.person_id = a.patient_id
    JOIN people p ON p.id = pt.person_id
    LEFT JOIN students s ON s.person_id = p.id
    LEFT JOIN programs pr ON pr.id = s.program_id
    LEFT JOIN school_employees se ON se.person_id = p.id
    LEFT JOIN departments ed ON ed.id = se.department_id
    WHERE a.status = 'Pending'
    ORDER BY a.appointment_datetime ASC
    LIMIT 10
");
$pendingAppointments = $pendingAppointmentsStmt ? $pendingAppointmentsStmt->fetchAll() : [];

// Build the dashboard day view without changing the appointment schema. Appointments
// currently store a start time only, so the dashboard reserves one hour per record.
$scheduleStartMinutes = 8 * 60;
$scheduleEndMinutes = 17 * 60;
$appointmentDurationMinutes = 60;
$scheduleItems = [];
$usingAppointmentPlaceholders = count($appointments) === 0;

$appointmentRows = $appointments;
if ($usingAppointmentPlaceholders) {
    // UI-only examples make the empty timeline understandable. They are never saved.
    $today = date('Y-m-d');
    $appointmentRows = [
        ['id' => 0, 'patient_id' => 0, 'appointment_datetime' => $today . ' 08:30:00', 'first_name' => 'Sofia', 'last_name' => 'Bautista', 'id_number' => '26-01024', 'purpose' => 'General consultation', 'status' => 'Scheduled', '_placeholder' => true],
        ['id' => 0, 'patient_id' => 0, 'appointment_datetime' => $today . ' 10:00:00', 'first_name' => 'Najil', 'last_name' => 'Bumacod', 'id_number' => '23-00262', 'purpose' => 'Follow-up checkup', 'status' => 'Scheduled', '_placeholder' => true],
        ['id' => 0, 'patient_id' => 0, 'appointment_datetime' => $today . ' 13:30:00', 'first_name' => 'Maria', 'last_name' => 'Santos', 'id_number' => 'FAC-0001', 'purpose' => 'Medical consultation', 'status' => 'Scheduled', '_placeholder' => true],
    ];
}

foreach ($appointmentRows as $row) {
    $timestamp = strtotime((string) $row['appointment_datetime']);
    $startMinute = ((int) date('G', $timestamp) * 60) + (int) date('i', $timestamp);
    $endMinute = $startMinute + $appointmentDurationMinutes;

    // The day view covers clinic hours only. Keep partially overlapping appointments
    // visible by clipping their cards to the calendar boundary.
    if ($endMinute <= $scheduleStartMinutes || $startMinute >= $scheduleEndMinutes) {
        continue;
    }

    $row['_start_minute'] = max($startMinute, $scheduleStartMinutes);
    $row['_end_minute'] = min($endMinute, $scheduleEndMinutes);
    $row['_actual_start_minute'] = $startMinute;
    $row['_lane'] = 0;
    $row['_lane_count'] = 1;
    $scheduleItems[] = $row;
}

usort($scheduleItems, static fn(array $a, array $b): int => $a['_start_minute'] <=> $b['_start_minute']);

// Assign overlapping appointments to adjacent lanes so neither card hides the other.
$overlapGroups = [];
$currentGroup = [];
$currentGroupEnd = -1;
foreach ($scheduleItems as $index => $item) {
    if ($currentGroup !== [] && $item['_start_minute'] >= $currentGroupEnd) {
        $overlapGroups[] = $currentGroup;
        $currentGroup = [];
        $currentGroupEnd = -1;
    }
    $currentGroup[] = $index;
    $currentGroupEnd = max($currentGroupEnd, $item['_end_minute']);
}
if ($currentGroup !== []) {
    $overlapGroups[] = $currentGroup;
}

foreach ($overlapGroups as $group) {
    $laneEnds = [];
    foreach ($group as $index) {
        $lane = 0;
        while (isset($laneEnds[$lane]) && $scheduleItems[$index]['_start_minute'] < $laneEnds[$lane]) {
            $lane++;
        }
        $scheduleItems[$index]['_lane'] = $lane;
        $laneEnds[$lane] = $scheduleItems[$index]['_end_minute'];
    }
    $laneCount = max(1, count($laneEnds));
    foreach ($group as $index) {
        $scheduleItems[$index]['_lane_count'] = $laneCount;
    }
}

// --- 4. Real-time Visitor Log (Clinic Visits Today) ---
$visitorLogs = cliniq_visit_db()->query("
    SELECT v.*, v.visit_id AS id, p.first_name, p.last_name, p.id_number,
           COALESCE(NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''), ed.department_code, 'Patient') AS course_section
    FROM visits v
    JOIN patients pt ON pt.person_id = v.patient_person_id
    JOIN people p ON p.id = pt.person_id
    LEFT JOIN students s ON s.person_id = p.id
    LEFT JOIN programs pr ON pr.id = s.program_id
    LEFT JOIN school_employees se ON se.person_id = p.id
    LEFT JOIN departments ed ON ed.id = se.department_id
    WHERE DATE(v.visit_datetime) = CURDATE()
    ORDER BY v.visit_datetime DESC
")->fetchAll();

// --- 5. APE Action Queue (Condensed) ---
$allApeRecords = array_values(array_filter(
    ape_fetch_records('', 500),
    static fn(array $record): bool => ($record['workflow_status'] ?? '') !== 'Cleared'
));

$dashboardQueues = ape_work_queues();
$recordsByQueue = array_fill_keys(array_keys($dashboardQueues), []);
foreach ($allApeRecords as $record) {
    $queue = ape_record_queue($record);
    if (isset($recordsByQueue[$queue])) {
        $recordsByQueue[$queue][] = $record;
    }
}
$apeQueue = [];
foreach (['examination', 'digital_submission', 'final_decision', 'follow_up'] as $queueKey) {
    foreach ($recordsByQueue[$queueKey] as $record) {
        $record['_queue_key'] = $queueKey;
        $apeQueue[] = $record;
    }
}
$apeQueue = array_slice($apeQueue, 0, 5); // Limit to 5 for condensed view

$visitorColumns = [
    ['headerName' => 'Arrived', 'field' => 'arrivedTime', 'sortField' => 'arrivedSort', 'sortType' => 'date', 'width' => 105, 'minWidth' => 100, 'maxWidth' => 115, 'flex' => 0],
    ['headerName' => 'Addressed', 'field' => 'addressedTime', 'sortField' => 'addressedSort', 'sortType' => 'date', 'width' => 110, 'minWidth' => 105, 'maxWidth' => 120, 'flex' => 0],
    ['headerName' => 'Completed', 'field' => 'completedTime', 'sortField' => 'completedSort', 'sortType' => 'date', 'width' => 110, 'minWidth' => 105, 'maxWidth' => 120, 'flex' => 0],
    ['headerName' => 'Patient', 'field' => 'patientHtml', 'cellRenderer' => 'html', 'sortField' => 'patientSort', 'minWidth' => 150, 'flex' => 1],
    ['headerName' => 'Complaint', 'field' => 'complaint', 'minWidth' => 170, 'flex' => 1.2],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'sortField' => 'statusSort', 'sortType' => 'number', 'width' => 128, 'minWidth' => 120, 'maxWidth' => 140, 'flex' => 0],
];
$visitorRows = [];
foreach ($visitorLogs as $visit) {
    $fullName = trim($visit['first_name'] . ' ' . $visit['last_name']);
    $visitorRows[] = [
        'rowUrl' => app_url('visits/view.php?id=' . (int) $visit['id'] . '&from=dashboard'),
        'arrivedSort' => $visit['visit_datetime'],
        'arrivedTime' => date('h:i A', strtotime($visit['visit_datetime'])),
        'addressedSort' => $visit['addressed_at'],
        'addressedTime' => $visit['addressed_at'] ? date('h:i A', strtotime($visit['addressed_at'])) : '-',
        'completedSort' => $visit['completed_at'],
        'completedTime' => $visit['completed_at'] ? date('h:i A', strtotime($visit['completed_at'])) : '-',
        'patientSort' => trim($visit['last_name'] . ' ' . $visit['first_name']),
        'patientHtml' => '<div class="font-bold text-slate-800 text-sm">' . e($fullName) . '</div><div class="text-[10px] text-slate-400">' . e($visit['id_number']) . '</div>',
        'complaint' => $visit['chief_complaint'],
        'statusHtml' => '<span class="badge ' . e(status_badge_class($visit['status'])) . ' text-[9px]">' . e($visit['status']) . '</span>',
        'statusSort' => array_search($visit['status'], ['Unaddressed', 'Active', 'Completed', 'Cancelled'], true),
    ];
}

$dashboardApeColumns = [
    ['headerName' => 'Patient', 'field' => 'studentHtml', 'cellRenderer' => 'html', 'sortField' => 'studentSort', 'minWidth' => 230],
    ['headerName' => 'Work Queue', 'field' => 'queueHtml', 'cellRenderer' => 'html', 'sortField' => 'queueSort', 'minWidth' => 170],
    ['headerName' => 'Missing / Waiting For', 'field' => 'missing', 'minWidth' => 240],
    ['headerName' => 'Actions', 'field' => 'actionHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 100, 'minWidth' => 90],
];
$dashboardApeRows = [];
foreach ($apeQueue as $rec) {
    $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
    $queueKey = $rec['_queue_key'];
    $queue = $dashboardQueues[$queueKey];
    $next = ape_next_action($rec);
    $dashboardApeRows[] = [
        'rowUrl' => app_url('ape/view.php?id=' . (int) $rec['id']),
        'studentSort' => trim($rec['last_name'] . ' ' . $rec['first_name']),
        'studentHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-[10px] font-bold text-slate-400">' . e($rec['id_number']) . '</div></div></div>',
        'queueHtml' => '<span class="badge ' . ($queueKey === 'follow_up' ? 'badge-high' : 'badge-in-progress') . '">' . e($queue['short_title'] ?? $queue['title']) . '</span>',
        'queueSort' => $queue['short_title'] ?? $queue['title'],
        'missing' => ape_missing_item($rec),
        'actionHtml' => row_actions_button('APE actions', '<a href="' . e(app_url('ape/view.php?id=' . (int) $rec['id'])) . '" class="btn btn-sm btn-ghost border border-slate-200 hover:border-primary hover:text-primary text-decoration-none"><span class="material-symbols-outlined text-[14px]">' . e($next['icon']) . '</span>' . e($next['label']) . '</a>'),
    ];
}

$dashboardUser = current_user() ?? [];
$dashboardUserName = trim((string) ($dashboardUser['name'] ?? ''));
$dashboardUserRole = strtolower((string) ($dashboardUser['role'] ?? ''));
$dashboardRoleTitle = match ($dashboardUserRole) {
    'nurse' => 'Nurse',
    'doctor', 'physician' => 'Doctor',
    default => '',
};
$dashboardDisplayName = $dashboardUserName !== '' ? $dashboardUserName : 'Clinic Staff';
if ($dashboardRoleTitle !== '' && !preg_match('/^(nurse|dr\.?|doctor)\b/i', $dashboardDisplayName)) {
    $dashboardDisplayName = $dashboardRoleTitle . ' ' . $dashboardDisplayName;
}

render_header('Main Dashboard');
?>

<div class="dashboard-page">

    <?php if (isset($_GET['activated'])): ?>
        <div class="rounded-xl bg-emerald-50 border border-emerald-100 text-emerald-800 px-4 py-3 text-sm font-bold mb-5">
            Your account is now active. The clinic dashboard is fully unlocked.
        </div>
    <?php endif; ?>

    <?php render_clinic_command_header(
        'Clinic Command Center',
        'Good day, ' . $dashboardDisplayName,
        'Real-time alerts, daily schedule, and active patient logs.'
    ); ?>

    <!-- 2. Analytics & Metrics Ribbon -->
    <div class="dashboard-metrics grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
        <a href="<?= app_url('visits/index.php') ?>"
            class="dashboard-metric-card flex items-center gap-4 text-decoration-none hover:shadow-md hover:border-emerald-200 transition-all cursor-pointer">
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">group</span>
            </div>
            <div class="min-w-0">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Visits Today</p>
                <p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0">
                    <?= $metrics['visits_today'] ?>
                </p>
            </div>
        </a>
        <a href="<?= app_url('inventory/index.php') ?>"
            class="dashboard-metric-card flex items-center gap-4 text-decoration-none hover:shadow-md hover:border-amber-200 transition-all cursor-pointer">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">inventory_2</span>
            </div>
            <div class="min-w-0">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Low Stock</p>
                <p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0">
                    <?= $metrics['low_stock'] ?>
                </p>
            </div>
        </a>
        <a href="<?= app_url('appointments/index.php') ?>"
            class="dashboard-metric-card flex items-center gap-4 text-decoration-none hover:shadow-md hover:border-primary transition-all cursor-pointer">
            <div class="w-12 h-12 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">event_available</span>
            </div>
            <div class="min-w-0">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Appt Requests</p>
                <p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0">
                    <?= $metrics['appointment_requests'] ?>
                </p>
            </div>
        </a>
        <a href="<?= app_url('ape/index.php') ?>"
            class="dashboard-metric-card flex items-center gap-4 text-decoration-none hover:shadow-md hover:border-primary transition-all cursor-pointer">
            <div class="w-12 h-12 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">fact_check</span>
            </div>
            <div class="min-w-0">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">APE Clearance</p>
                <p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0">
                    <?= $metrics['ape_clearance_rate'] ?>%
                </p>
            </div>
        </a>
        <a href="<?= app_url('ape/index.php?queue=examination') ?>"
            class="dashboard-metric-card flex items-center gap-4 text-decoration-none hover:shadow-md hover:border-blue-200 transition-all cursor-pointer col-span-2 sm:col-span-1">
            <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">pending_actions</span>
            </div>
            <div class="min-w-0">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">APE Action</p>
                <p class="font-headline text-3xl font-extrabold text-slate-800 leading-none m-0">
                    <?= $metrics['ape_pending_review'] ?>
                </p>
            </div>
        </a>
    </div>

    <!-- 1. Emergency & Alerts Banner -->
    <?php if (count($activeAlerts) > 0): ?>
        <section class="clinic-card overflow-hidden mb-8">
            <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[20px]">warning</span>
                    </div>
                    <div>
                        <h2 class="font-headline text-base font-extrabold text-[#17261d] m-0 flex items-center gap-2">
                            Active Emergency Alerts
                            <span class="badge badge-high text-[10px]"><?= $metrics['pending_alerts'] > 99 ? '99+' : $metrics['pending_alerts'] ?></span>
                        </h2>
                        <p class="text-xs font-bold text-slate-500 m-0">
                            Showing the most urgent <?= count($activeAlerts) ?> of <?= $metrics['pending_alerts'] ?> pending alert(s).
                        </p>
                    </div>
                </div>
                <a href="<?= app_url('alerts/index.php?status=pending') ?>"
                    class="btn btn-sm btn-ghost text-red-600 hover:text-red-700 text-decoration-none shrink-0">
                    View All
                    <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                </a>
            </div>

            <div class="p-4 sm:p-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($activeAlerts as $alert): ?>
                        <?php
                        $alertRisk = $alert['risk_level'] ?? 'Low';
                        $alertRiskClass = risk_badge_class($alertRisk);
                        $alertPhotoUrl = !empty($alert['photo_path']) ? app_url($alert['photo_path']) : '';
                        ?>
                        <div
                            class="flex flex-col justify-between rounded-xl border border-slate-100 border-l-4 border-l-red-500 bg-white p-4 hover:shadow-sm transition-shadow">
                            <div>
                                <div class="flex justify-between items-start mb-2">
                                    <div class="flex flex-wrap gap-2">
                                        <span class="badge badge-pending text-[9px]">Pending</span>
                                        <span class="badge <?= e($alertRiskClass) ?> text-[9px]"><?= e($alertRisk) ?> risk</span>
                                    </div>
                                    <span class="text-[11px] font-bold text-slate-400 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[13px]">schedule</span>
                                        <?= date('h:i A', strtotime($alert['created_at'])) ?>
                                    </span>
                                </div>
                                <h3 class="font-bold text-slate-800 text-sm mb-1"><?= e($alert['concern']) ?></h3>
                                <?php if (!empty($alert['incident_type'])): ?>
                                    <p class="text-[11px] font-black text-red-500 uppercase tracking-widest mb-1"><?= e($alert['incident_type']) ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-slate-500 line-clamp-2 mb-2"><?= e($alert['report_answers'] ?: $alert['details']) ?></p>
                                <?php if (!empty($alert['response_guidance'])): ?>
                                    <div class="rounded-xl bg-red-50 border border-red-100 px-3 py-2 text-[11px] font-bold text-red-700 mb-3 line-clamp-2">
                                        <?= e($alert['response_guidance']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($alertPhotoUrl): ?>
                                    <a href="<?= e($alertPhotoUrl) ?>" class="block rounded-xl overflow-hidden border border-slate-100 bg-slate-50 mb-3" data-file-preview data-preview-type="image" data-preview-title="Alert evidence - <?= e($alert['concern']) ?>">
                                        <img src="<?= e($alertPhotoUrl) ?>" alt="Alert evidence" class="w-full h-28 object-cover">
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-1 text-xs font-bold text-slate-500 mb-3">
                                <span class="material-symbols-outlined text-[14px] text-red-400">location_on</span>
                                <?= e($alert['location']) ?>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <form method="post" action="<?= app_url('alerts/update.php') ?>" class="flex-1">
                                    <input type="hidden" name="id" value="<?= (int) $alert['id'] ?>">
                                    <input type="hidden" name="status" value="In Progress">
                                    <input type="hidden" name="redirect" value="../dashboard.php">
                                    <button type="submit" class="btn btn-sm btn-danger w-full" data-confirm-submit
                                        data-confirm-type="danger" data-confirm-title="Acknowledge this alert?"
                                        data-confirm-message="This will move the alert to In Progress so staff can handle it."
                                        data-confirm-toast="Acknowledging alert...">
                                        <span class="material-symbols-outlined text-[14px]">priority_high</span>
                                        Acknowledge
                                    </button>
                                </form>
                                <a href="<?= app_url('alerts/view.php?id=' . (int) $alert['id']) ?>" class="btn btn-sm btn-outline w-full flex-1 text-decoration-none">
                                    <span class="material-symbols-outlined text-[14px]">assignment</span>
                                    Open Report
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>



    <div class="dashboard-activity-grid grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8 items-stretch">

        <!-- NEW: Pending Appointment Requests (left panel) -->
        <section class="clinic-card overflow-hidden flex flex-col h-[520px]" style="height: 520px;">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined">pending_actions</span>
                    </div>
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Appointment Requests</h2>
                        <p class="text-xs font-bold text-slate-500 m-0">Approve or cancel pending patient booking requests.</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <?php if (count($pendingAppointments) > 0): ?>
                        <span class="badge badge-pending text-[10px]"><?= count($pendingAppointments) ?> pending</span>
                    <?php endif; ?>
                    <a href="<?= app_url('appointments/index.php') ?>"
                        class="btn btn-sm btn-ghost text-slate-400 hover:text-primary text-decoration-none">
                        <span class="material-symbols-outlined text-[18px]">open_in_new</span>
                    </a>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto divide-y divide-slate-100">
                <?php if (empty($pendingAppointments)): ?>
                    <div class="flex flex-col items-center justify-center py-12 px-6 text-center">
                        <div class="w-12 h-12 rounded-2xl bg-slate-50 text-slate-300 flex items-center justify-center mb-3">
                            <span class="material-symbols-outlined text-[26px]">event_available</span>
                        </div>
                        <p class="text-sm font-bold text-slate-500 mb-0">No pending requests</p>
                        <p class="text-xs text-slate-400 mt-1">New appointment requests will appear here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pendingAppointments as $pa):
                        $paName = trim($pa['first_name'] . ' ' . $pa['last_name']);
                        $paDate = date('M d, Y', strtotime($pa['appointment_datetime']));
                        $paTime = date('g:i A', strtotime($pa['appointment_datetime']));
                        $paId = (int) $pa['appointment_id'];
                    ?>
                        <div class="p-4 hover:bg-slate-50 transition-colors">
                            <div class="flex items-start gap-3">
                                <div class="avatar <?= e(avatar_color($paName)) ?> shrink-0 mt-0.5" style="width:36px;height:36px;font-size:12px;">
                                    <?= e(initials($paName)) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold text-slate-800 mb-0 truncate"><?= e($paName) ?></p>
                                            <p class="text-[11px] font-bold text-slate-400 mb-1"><?= e($pa['id_number']) ?> · <?= e($pa['course_section'] ?? 'Patient') ?></p>
                                        </div>
                                        <span class="text-[10px] font-bold text-amber-600 bg-amber-50 rounded-full px-2 py-0.5 whitespace-nowrap shrink-0">For Approval</span>
                                    </div>
                                    <div class="flex items-center gap-1 text-xs font-bold text-slate-500 mb-2">
                                        <span class="material-symbols-outlined text-[13px] text-indigo-400">event</span>
                                        <?= e($paDate) ?> &bull;
                                        <span class="material-symbols-outlined text-[13px] text-indigo-400">schedule</span>
                                        <?= e($paTime) ?>
                                    </div>
                                    <?php if (!empty($pa['purpose'])): ?>
                                        <p class="text-xs text-slate-500 mb-3 line-clamp-1"><?= e($pa['purpose']) ?></p>
                                    <?php endif; ?>
                                    <div class="flex items-center gap-2">
                                        <form method="post" action="<?= app_url('appointments/update.php') ?>" class="flex-1">
                                            <input type="hidden" name="id" value="<?= $paId ?>">
                                            <input type="hidden" name="status" value="Scheduled">
                                            <input type="hidden" name="redirect" value="../dashboard.php">
                                            <button type="submit" class="btn btn-sm btn-primary w-full justify-center"
                                                data-confirm-submit
                                                data-confirm-type="primary"
                                                data-confirm-title="Approve this appointment?"
                                                data-confirm-message="This will schedule the appointment for <?= e($paName) ?> on <?= e($paDate . ' at ' . $paTime) ?>."
                                                data-confirm-toast="Approving appointment...">
                                                <span class="material-symbols-outlined text-[14px]">event_available</span>
                                                Approve
                                            </button>
                                        </form>
                                        <form method="post" action="<?= app_url('appointments/update.php') ?>">
                                            <input type="hidden" name="id" value="<?= $paId ?>">
                                            <input type="hidden" name="status" value="Cancelled">
                                            <input type="hidden" name="cancellation_reason" value="Declined by clinic staff.">
                                            <input type="hidden" name="redirect" value="../dashboard.php">
                                            <button type="submit" class="btn btn-sm btn-ghost btn-cancel-icon"
                                                title="Decline request"
                                                aria-label="Decline appointment request"
                                                data-confirm-submit
                                                data-confirm-type="danger"
                                                data-confirm-title="Decline this request?"
                                                data-confirm-message="This will cancel the appointment request for <?= e($paName) ?>."
                                                data-confirm-toast="Declining request...">
                                                <span class="material-symbols-outlined text-[16px]">cancel</span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Today's Appointment Schedule -->
        <section class="clinic-card overflow-hidden flex flex-col h-[520px]" style="height: 520px;">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined">event</span>
                    </div>
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Today's Schedule</h2>
                        <p class="text-xs font-bold text-slate-500 m-0"><?= e(date('l, F j, Y')) ?> &bull; 8:00 AM–5:00 PM</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="badge badge-in-progress text-[9px]"><?= count($appointments) ?> scheduled / confirmation</span>
                    <a href="<?= app_url('appointments/index.php') ?>"
                        class="btn btn-sm btn-ghost text-slate-400 hover:text-primary text-decoration-none">
                        <span class="material-symbols-outlined text-[18px]">calendar_month</span>
                    </a>
                </div>
            </div>
            <div class="flex-1 p-4 flex flex-col min-h-0 overflow-hidden">
                <div class="dashboard-day-schedule-section flex-1 flex flex-col min-h-0">
                    <div class="dashboard-day-calendar flex-1 h-full overflow-x-hidden" style="overflow-x: hidden !important;" aria-label="Today's appointment calendar from 8 AM to 5 PM">
                        <div class="dashboard-day-calendar-canvas">
                            <?php for ($hour = 8; $hour <= 17; $hour++): ?>
                                <?php $hourOffset = (($hour * 60) - $scheduleStartMinutes) / ($scheduleEndMinutes - $scheduleStartMinutes) * 100; ?>
                                <div class="dashboard-calendar-hour" style="top: <?= number_format($hourOffset, 4, '.', '') ?>%;">
                                    <time><?= e(date('g A', mktime($hour, 0))) ?></time>
                                    <span aria-hidden="true"></span>
                                </div>
                            <?php endfor; ?>

                            <div class="dashboard-calendar-events">
                                <?php foreach ($scheduleItems as $apt):
                                    $calendarDuration = $scheduleEndMinutes - $scheduleStartMinutes;
                                    $top = (($apt['_start_minute'] - $scheduleStartMinutes) / $calendarDuration) * 100;
                                    $height = (($apt['_end_minute'] - $apt['_start_minute']) / $calendarDuration) * 100;
                                    $laneCount = max(1, (int) $apt['_lane_count']);
                                    $lane = (int) $apt['_lane'];
                                    $width = 100 / $laneCount;
                                    $left = $lane * $width;
                                    $time = date('g:i A', strtotime((string) $apt['appointment_datetime']));
                                    $endTime = date('g:i A', strtotime((string) $apt['appointment_datetime']) + ($appointmentDurationMinutes * 60));
                                    $fullName = trim($apt['first_name'] . ' ' . $apt['last_name']);
                                    $isPlaceholder = !empty($apt['_placeholder']);
                                    ?>
                                    <article class="dashboard-calendar-event <?= $isPlaceholder ? 'is-placeholder' : '' ?>"
                                        style="top: calc(<?= number_format($top, 4, '.', '') ?>% + 2px); height: calc(<?= number_format($height, 4, '.', '') ?>% - 4px); left: calc(<?= number_format($left, 4, '.', '') ?>% + 3px); width: calc(<?= number_format($width, 4, '.', '') ?>% - 6px);"
                                        title="<?= e($fullName . ' — ' . $apt['purpose'] . ' — ' . $time . ' to ' . $endTime) ?>">
                                        <div class="dashboard-calendar-event-time">
                                            <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
                                            <?= e($time) ?>–<?= e($endTime) ?>
                                            <?php if ($isPlaceholder): ?><span class="dashboard-calendar-sample-badge">Sample</span><?php endif; ?>
                                        </div>
                                        <strong><?= e($fullName) ?></strong>
                                        <span><?= e($apt['id_number']) ?> &bull; <?= e($apt['purpose']) ?></span>
                                        <?php if (($apt['status'] ?? '') === 'For Confirmation'): ?>
                                            <span class="badge badge-pending text-[9px] mt-1">For Confirmation</span>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 4. Real-time Visitor Log -->
        <section class="clinic-card overflow-hidden flex flex-col mb-8 lg:col-span-2">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined">directions_walk</span>
                    </div>
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Live Visitor Log</h2>
                        <p class="text-xs font-bold text-slate-500 m-0">Patients who visited the clinic today.</p>
                    </div>
                </div>
                <a href="<?= app_url('visits/index.php') ?>"
                    class="btn btn-sm btn-ghost text-slate-400 hover:text-primary shrink-0">
                    <span class="material-symbols-outlined text-[18px]">list</span>
                </a>
            </div>
            <div class="dashboard-visitor-grid-wrap p-0" style="height: 380px;">
                <?php render_ag_grid('dashboardVisitorGrid', $visitorColumns, $visitorRows, [
                    'pageSize' => 10,
                    'height' => 'compact',
                    'fitColumns' => true,
                    'emptyTitle' => 'No visitors logged yet today.',
                    'emptyText' => 'Recorded clinic visits will appear here.',
                ]); ?>
            </div>
        </section>
    </div>

    <!-- 5. Condensed APE Action Queue -->
    <section class="clinic-card overflow-hidden mb-6">
        <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">APE Action Queue</h2>
                <p class="text-xs font-bold text-slate-500 m-0">Priority patients needing hard-copy review, clinical
                    examination, document keeping, final decision, or follow-up clearance.</p>
            </div>
            <a href="<?= app_url('ape/index.php') ?>" class="btn btn-sm btn-primary shrink-0">
                <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                Open APE Center
            </a>
        </div>

        <?php render_ag_grid('dashboardApeGrid', $dashboardApeColumns, $dashboardApeRows, [
            'pageSize' => 10,
            'height' => 'compact',
            'emptyTitle' => 'No pending APE tasks in the queue.',
            'emptyText' => 'Patients with clinic actions will appear here.',
        ]); ?>
    </section>

</div>

<?php render_footer(); ?>
