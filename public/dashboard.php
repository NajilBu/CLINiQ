<?php

require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../app/services/AlertWorkflow.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_login();
ensure_ape_workflow_schema();
ensure_alert_workflow_schema();
ensure_appointment_schema();

// --- 1. Metrics & Analytics ---
$metrics = [
    'visits_today' => (int) (db()->query('SELECT COUNT(*) AS total FROM clinic_visits WHERE DATE(visit_datetime) = CURDATE()')->fetch()['total'] ?? 0),
    'pending_alerts' => (int) (db()->query("SELECT COUNT(*) AS total FROM nurse_alerts WHERE status = 'Pending'")->fetch()['total'] ?? 0),
    'low_stock' => (int) (db()->query('SELECT COUNT(*) AS total FROM inventory_items WHERE quantity <= reorder_level')->fetch()['total'] ?? 0),
    'appointment_requests' => (int) (db()->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'Pending'")->fetch()['total'] ?? 0),
];

// APE Metrics (condensed)
$apeTotal = (int) (db()->query('SELECT COUNT(*) AS total FROM ape_records')->fetch()['total'] ?? 0);
$apeCleared = (int) (db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE workflow_status = 'Cleared' OR clearance_status = 'Cleared'")->fetch()['total'] ?? 0);
$metrics['ape_clearance_rate'] = $apeTotal > 0 ? round(($apeCleared / $apeTotal) * 100) : 0;
$metrics['ape_pending_review'] = (int) (db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE COALESCE(requirement_status, '') <> 'Pre-Verified'")->fetch()['total'] ?? 0);


// --- 2. Live Alerts (Most Urgent First) ---
$activeAlerts = db()->query("
    SELECT a.*, p.first_name, p.last_name
    FROM nurse_alerts a
    LEFT JOIN patients p ON p.id = a.patient_id
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

// --- 3. Today's Appointments ---
// We check the new appointments table
$appointmentsStmt = db()->query("
    SELECT a.*, p.first_name, p.last_name, p.student_number, p.course_section
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    WHERE DATE(a.appointment_datetime) = CURDATE()
      AND a.status = 'Scheduled'
    ORDER BY a.appointment_datetime ASC
");
$appointments = $appointmentsStmt ? $appointmentsStmt->fetchAll() : [];

$pendingAppointments = db()->query("
    SELECT a.*, p.first_name, p.last_name, p.student_number, p.course_section
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    WHERE a.status = 'Pending'
    ORDER BY a.created_at DESC, a.appointment_datetime ASC
    LIMIT 4
")->fetchAll();

// --- 4. Real-time Visitor Log (Clinic Visits Today) ---
$visitorLogs = db()->query("
    SELECT v.*, p.first_name, p.last_name, p.student_number, p.course_section
    FROM clinic_visits v
    JOIN patients p ON p.id = v.patient_id
    WHERE DATE(v.visit_datetime) = CURDATE()
    ORDER BY v.visit_datetime DESC
")->fetchAll();

// --- 5. APE Action Queue (Condensed) ---
$allApeRecords = db()->query("
    SELECT a.*, p.first_name, p.last_name, p.student_number, p.course_section
    FROM ape_records a
    JOIN patients p ON p.id = a.patient_id
    WHERE a.workflow_status NOT IN ('Cleared')
    ORDER BY a.updated_at DESC, a.created_at DESC
")->fetchAll();

$dashboardQueues = ape_work_queues();
$recordsByQueue = array_fill_keys(array_keys($dashboardQueues), []);
foreach ($allApeRecords as $record) {
    $queue = ape_record_queue($record);
    if (isset($recordsByQueue[$queue])) {
        $recordsByQueue[$queue][] = $record;
    }
}
$apeQueue = [];
foreach (['document_review', 'digital_submission', 'follow_up'] as $queueKey) {
    foreach ($recordsByQueue[$queueKey] as $record) {
        $record['_queue_key'] = $queueKey;
        $apeQueue[] = $record;
    }
}
$apeQueue = array_slice($apeQueue, 0, 5); // Limit to 5 for condensed view

$visitorColumns = [
    ['headerName' => 'Time', 'field' => 'time', 'width' => 100, 'minWidth' => 96, 'maxWidth' => 112, 'flex' => 0],
    ['headerName' => 'Patient', 'field' => 'patientHtml', 'cellRenderer' => 'html', 'minWidth' => 150, 'flex' => 1],
    ['headerName' => 'Complaint', 'field' => 'complaint', 'minWidth' => 170, 'flex' => 1.2],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'width' => 128, 'minWidth' => 120, 'maxWidth' => 140, 'flex' => 0],
];
$visitorRows = [];
foreach ($visitorLogs as $visit) {
    $fullName = trim($visit['first_name'] . ' ' . $visit['last_name']);
    $visitorRows[] = [
        'rowUrl' => app_url('visits/view.php?id=' . (int) $visit['id'] . '&from=dashboard'),
        'time' => date('h:i A', strtotime($visit['visit_datetime'])),
        'patientHtml' => '<div class="font-bold text-slate-800 text-sm">' . e($fullName) . '</div><div class="text-[10px] text-slate-400">' . e($visit['student_number']) . '</div>',
        'complaint' => $visit['chief_complaint'],
        'statusHtml' => '<span class="badge ' . e(status_badge_class($visit['status'])) . ' text-[9px]">' . e($visit['status']) . '</span>',
    ];
}

$dashboardApeColumns = [
    ['headerName' => 'Student', 'field' => 'studentHtml', 'cellRenderer' => 'html', 'minWidth' => 230],
    ['headerName' => 'Work Queue', 'field' => 'queueHtml', 'cellRenderer' => 'html', 'width' => 170],
    ['headerName' => 'Missing / Waiting For', 'field' => 'missing', 'minWidth' => 240],
    ['headerName' => 'Action', 'field' => 'actionHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 210],
];
$dashboardApeRows = [];
foreach ($apeQueue as $rec) {
    $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
    $queueKey = $rec['_queue_key'];
    $queue = $dashboardQueues[$queueKey];
    $next = ape_next_action($rec);
    $dashboardApeRows[] = [
        'rowUrl' => app_url('ape/view.php?id=' . (int) $rec['id']),
        'studentHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-[10px] font-bold text-slate-400">' . e($rec['student_number']) . '</div></div></div>',
        'queueHtml' => '<span class="badge ' . ($queueKey === 'follow_up' ? 'badge-high' : 'badge-in-progress') . '">' . e($queue['short_title'] ?? $queue['title']) . '</span>',
        'missing' => ape_missing_item($rec),
        'actionHtml' => '<a href="' . e(app_url('ape/view.php?id=' . (int) $rec['id'])) . '" class="btn btn-sm btn-ghost border border-slate-200 hover:border-primary hover:text-primary text-decoration-none"><span class="material-symbols-outlined text-[14px]">' . e($next['icon']) . '</span>' . e($next['label']) . '</a>',
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
        <a href="<?= app_url('ape/index.php?queue=document_review') ?>"
            class="dashboard-metric-card flex items-center gap-4 text-decoration-none hover:shadow-md hover:border-blue-200 transition-all cursor-pointer col-span-2 sm:col-span-1">
            <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">pending_actions</span>
            </div>
            <div class="min-w-0">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">APE To Review</p>
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
                                    <a href="<?= e($alertPhotoUrl) ?>" target="_blank" class="block rounded-xl overflow-hidden border border-slate-100 bg-slate-50 mb-3">
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



    <div class="dashboard-activity-grid grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8 items-stretch">
        <!-- 3. Appointment Requests and Today's Schedule -->
        <section class="dashboard-activity-card clinic-card overflow-hidden flex flex-col max-h-[600px]">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined">event</span>
                    </div>
                    <div>
                        <h2 class="font-headline text-lg font-extrabold text-[#17261d] m-0">Appointments</h2>
                        <p class="text-xs font-bold text-slate-500 m-0">Approve student requests before they become
                            scheduled visits.</p>
                    </div>
                </div>
                <a href="<?= app_url('appointments/index.php') ?>"
                    class="btn btn-sm btn-ghost text-slate-400 hover:text-primary text-decoration-none shrink-0">
                    <span class="material-symbols-outlined text-[18px]">calendar_month</span>
                </a>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-5">
                <div>
                    <div class="flex items-center justify-between mb-2 px-1">
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest m-0">For Clinic
                            Approval</h3>
                        <span class="badge badge-pending text-[9px]"><?= count($pendingAppointments) ?>
                            request(s)</span>
                    </div>
                    <?php if (count($pendingAppointments) > 0): ?>
                        <div class="space-y-2">
                            <?php foreach ($pendingAppointments as $request):
                                $time = date('M d, g:i A', strtotime($request['appointment_datetime']));
                                $fullName = trim($request['first_name'] . ' ' . $request['last_name']);
                                ?>
                                <div class="p-3 rounded-xl border border-amber-100 bg-amber-50/50">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <h4 class="font-bold text-slate-800 text-sm mb-0 truncate"><?= e($fullName) ?></h4>
                                            <p class="text-xs text-slate-500 mb-1 truncate"><?= e($request['student_number']) ?>
                                                &bull; <?= e($request['purpose']) ?></p>
                                            <p class="text-[11px] font-bold text-amber-700 mb-0 flex items-center gap-1">
                                                <span class="material-symbols-outlined text-[13px]">schedule</span>
                                                Requested <?= e($time) ?>
                                            </p>
                                        </div>
                                        <span class="badge badge-pending text-[9px] shrink-0">Pending</span>
                                    </div>
                                    <div class="flex flex-col sm:flex-row gap-2 mt-3">
                                        <form method="post" action="<?= app_url('appointments/update.php') ?>" class="flex-1">
                                            <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
                                            <input type="hidden" name="status" value="Scheduled">
                                            <input type="hidden" name="redirect" value="../dashboard.php">
                                            <button class="btn btn-sm btn-primary w-full" data-confirm-submit
                                                data-confirm-type="primary" data-confirm-title="Approve this appointment?"
                                                data-confirm-message="This will schedule the appointment request."
                                                data-confirm-toast="Approving appointment...">
                                                <span class="material-symbols-outlined text-[14px]">event_available</span>
                                                Approve
                                            </button>
                                        </form>
                                        <form method="post" action="<?= app_url('appointments/update.php') ?>" class="flex-1">
                                            <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
                                            <input type="hidden" name="status" value="Cancelled">
                                            <input type="hidden" name="redirect" value="../dashboard.php">
                                            <input class="clinic-input mb-2" type="text" name="cancellation_reason" placeholder="Cancellation reason" required maxlength="500">
                                            <button class="btn btn-sm btn-ghost w-full" data-confirm-submit
                                                data-confirm-type="danger" data-confirm-title="Cancel this appointment request?"
                                                data-confirm-message="This will mark the appointment request as Cancelled."
                                                data-confirm-toast="Cancelling appointment...">
                                                <span class="material-symbols-outlined text-[14px]">cancel</span>
                                                Cancel
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 text-center text-slate-400">
                            <p class="text-xs font-bold m-0">No pending appointment requests.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2 px-1">
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest m-0">Approved Today
                        </h3>
                        <span class="badge badge-in-progress text-[9px]"><?= count($appointments) ?> scheduled</span>
                    </div>
                    <?php if (count($appointments) > 0): ?>
                        <div class="space-y-2">
                            <?php foreach ($appointments as $apt):
                                $time = date('h:i A', strtotime($apt['appointment_datetime']));
                                $fullName = trim($apt['first_name'] . ' ' . $apt['last_name']);
                                $statusClass = $apt['status'] === 'Completed' ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-white border-slate-200';
                                ?>
                                <div
                                    class="flex items-center gap-4 p-4 rounded-xl border <?= $statusClass ?> hover:shadow-sm transition-shadow">
                                    <div class="text-center w-16 shrink-0">
                                        <span class="block text-lg font-headline font-black text-slate-800"><?= $time ?></span>
                                    </div>
                                    <div class="w-px h-10 bg-slate-200 shrink-0"></div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="font-bold text-slate-800 text-sm mb-0 truncate"><?= e($fullName) ?></h4>
                                        <p class="text-xs text-slate-500 mb-1 truncate"><?= e($apt['student_number']) ?> &bull;
                                            <?= e($apt['purpose']) ?>
                                        </p>
                                        <span class="badge badge-in-progress text-[9px]"><?= e($apt['status']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div
                            class="p-5 flex flex-col items-center justify-center text-slate-400 bg-slate-50 rounded-xl border border-slate-100">
                            <span class="material-symbols-outlined text-[48px] mb-3 text-slate-200">event_busy</span>
                            <p class="text-sm font-bold m-0">No approved appointments scheduled for today.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 4. Real-time Visitor Log -->
        <section class="dashboard-activity-card clinic-card overflow-hidden flex flex-col max-h-[600px]">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
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
            <div class="dashboard-visitor-grid-wrap flex-1 min-h-0 p-0">
                <?php render_ag_grid('dashboardVisitorGrid', $visitorColumns, $visitorRows, [
                    'pageSize' => 10,
                    'height' => 'fill',
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
                <p class="text-xs font-bold text-slate-500 m-0">Priority students needing hard-copy review, online
                    document keeping, or follow-up clearance.</p>
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
            'emptyText' => 'Students with clinic actions will appear here.',
        ]); ?>
    </section>

</div>

<?php render_footer(); ?>
