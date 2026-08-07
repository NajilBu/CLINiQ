<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_login();
ensure_appointment_schema();

$filterStatus = $_GET['status'] ?? 'Pending';
$allowedFilters = ['all', 'Pending', 'Scheduled', 'Completed', 'Cancelled', 'No Show'];
if (!in_array($filterStatus, $allowedFilters, true)) {
    $filterStatus = 'Pending';
}

$where = '1=1';
$params = [];
if ($filterStatus !== 'all') {
    $where = 'a.status = ?';
    $params[] = $filterStatus;
}

$stmt = appointment_db()->prepare("
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
    WHERE {$where}
    ORDER BY
        CASE a.status WHEN 'Pending' THEN 0 WHEN 'Scheduled' THEN 1 ELSE 2 END,
        a.appointment_datetime ASC,
        a.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$appointments = $stmt->fetchAll();

$statusCounts = ['all' => 0];
$countQuery = appointment_db()->query("SELECT status, COUNT(*) AS cnt FROM appointments GROUP BY status");
foreach ($countQuery->fetchAll() as $sc) {
    $statusCounts[$sc['status']] = (int)$sc['cnt'];
    $statusCounts['all'] += (int)$sc['cnt'];
}

$columns = [
    ['headerName' => 'Requested Slot', 'field' => 'slotHtml', 'cellRenderer' => 'html', 'sortField' => 'slotSort', 'sortType' => 'date', 'minWidth' => 190],
    ['headerName' => 'Student', 'field' => 'studentHtml', 'cellRenderer' => 'html', 'sortField' => 'studentSort', 'minWidth' => 240],
    ['headerName' => 'Purpose', 'field' => 'purpose', 'minWidth' => 220],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'sortField' => 'statusSort', 'sortType' => 'number', 'width' => 150],
    ['headerName' => 'Notes', 'field' => 'notes', 'minWidth' => 220],
    ['headerName' => 'Cancellation Reason', 'field' => 'cancelReason', 'minWidth' => 240],
    ['headerName' => 'Requested', 'field' => 'created', 'sortField' => 'createdSort', 'sortType' => 'date', 'width' => 150],
    ['headerName' => 'Clinic Action', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 280],
];

$rows = [];
foreach ($appointments as $appointment) {
    $fullName = trim($appointment['first_name'] . ' ' . $appointment['last_name']);
    $status = $appointment['status'];
    $actions = '';

    if ($status === 'Pending') {
        $actions = '<div class="appointment-action-group">'
            . '<form method="post" action="update.php"><input type="hidden" name="id" value="' . (int)$appointment['appointment_id'] . '"><input type="hidden" name="status" value="Scheduled"><button class="btn btn-sm btn-primary" title="Approve appointment" data-confirm-submit data-confirm-type="primary" data-confirm-title="Approve this appointment?" data-confirm-message="This will schedule the appointment request." data-confirm-toast="Approving appointment..."><span class="material-symbols-outlined text-[14px]">event_available</span> Approve</button></form>'
            . '<button type="button" class="btn btn-sm btn-ghost btn-cancel-icon" title="Cancel request" aria-label="Cancel request" data-cancel-appointment data-cancel-id="' . (int)$appointment['appointment_id'] . '" data-cancel-title="Cancel appointment request"><span class="material-symbols-outlined text-[14px]">cancel</span></button>'
            . '</div>';
    } elseif ($status === 'Scheduled') {
        $actions = '<div class="appointment-action-group">'
            . '<form method="post" action="update.php"><input type="hidden" name="id" value="' . (int)$appointment['appointment_id'] . '"><input type="hidden" name="status" value="Completed"><button class="btn btn-sm btn-outline" title="Mark completed" data-confirm-submit data-confirm-type="primary" data-confirm-title="Mark appointment completed?" data-confirm-message="This will mark the scheduled appointment as Completed." data-confirm-toast="Completing appointment..."><span class="material-symbols-outlined text-[14px]">check</span> Complete</button></form>'
            . '<form method="post" action="update.php"><input type="hidden" name="id" value="' . (int)$appointment['appointment_id'] . '"><input type="hidden" name="status" value="No Show"><button class="btn btn-sm btn-ghost" title="Mark no-show" data-confirm-submit data-confirm-type="danger" data-confirm-title="Mark as no-show?" data-confirm-message="This will mark the scheduled appointment as No Show." data-confirm-toast="Marking no-show..."><span class="material-symbols-outlined text-[14px]">person_cancel</span> No Show</button></form>'
            . '<button type="button" class="btn btn-sm btn-ghost btn-cancel-icon" title="Cancel appointment" aria-label="Cancel appointment" data-cancel-appointment data-cancel-id="' . (int)$appointment['appointment_id'] . '" data-cancel-title="Cancel scheduled appointment"><span class="material-symbols-outlined text-[14px]">cancel</span></button>'
            . '</div>';
    }

    $rows[] = [
        'rowUrl' => app_url('patients/view.php?id=' . (int)$appointment['patient_id']),
        'slotSort' => $appointment['appointment_datetime'],
        'slotHtml' => '<p class="text-sm font-bold text-slate-800 mb-0">' . e(date('M d, Y', strtotime($appointment['appointment_datetime']))) . '</p><p class="text-xs font-bold text-slate-400 mb-0">' . e(date('g:i A', strtotime($appointment['appointment_datetime']))) . '</p>',
        'studentSort' => trim($appointment['last_name'] . ' ' . $appointment['first_name']),
        'studentHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-xs font-bold text-slate-400">' . e($appointment['id_number']) . ' · ' . e($appointment['course_section'] ?: 'No course') . '</div></div></div>',
        'purpose' => $appointment['purpose'],
        'statusHtml' => '<span class="badge ' . e(appointment_status_badge_class($status)) . '">' . e($status) . '</span>',
        'statusSort' => array_search($status, ['Pending', 'Scheduled', 'Completed', 'No Show', 'Cancelled'], true),
        'notes' => $appointment['notes'] ?: '-',
        'cancelReason' => $appointment['cancellation_reason'] ?: '-',
        'created' => date('M d, g:i A', strtotime($appointment['created_at'])),
        'createdSort' => $appointment['created_at'],
        'actionsHtml' => $actions,
    ];
}

render_header('Appointments');

render_clinic_command_header(
    'Scheduling',
    'Appointment Requests',
    'Approve student booking requests before they become clinic schedules.',
    '<a href="#clinic-availability" class="btn btn-primary text-decoration-none"><span class="material-symbols-outlined">calendar_month</span>Clinic Availability</a>'
);
?>

<section class="clinic-card overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Clinic Appointment Queue</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= (int)$statusCounts['all'] ?> appointment record(s)</p>
            </div>
            <div class="search-input-wrap w-full md:w-auto shrink-0" style="min-width: 280px;">
                <span class="search-icon material-symbols-outlined">search</span>
                <input id="appointmentsGridSearch" type="text" placeholder="Search appointments..." class="search-input">
            </div>
        </div>

        <div class="flex items-center gap-2 mt-4 border-t border-slate-100 pt-4 overflow-x-auto scrollbar-hide">
            <?php
            $tabs = [
                'Pending' => 'For Approval',
                'Scheduled' => 'Approved',
                'Completed' => 'Completed',
                'Cancelled' => 'Cancelled',
                'No Show' => 'No Show',
                'all' => 'All',
            ];
            foreach ($tabs as $key => $label):
                $isActive = $filterStatus === $key;
                $count = $statusCounts[$key] ?? 0;
                $href = $key === 'Pending' ? '?' : '?status=' . urlencode($key);
            ?>
                <a href="<?= e($href) ?>" class="status-tab <?= $isActive ? 'active' : '' ?> text-decoration-none whitespace-nowrap">
                    <?= e($label) ?>
                    <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $isActive ? 'bg-blue-100 text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= (int)$count ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php render_ag_grid('appointmentsGrid', $columns, $rows, [
        'searchInput' => 'appointmentsGridSearch',
        'emptyTitle' => $filterStatus === 'Pending' ? 'No appointment requests' : 'No appointments found',
        'emptyText' => $filterStatus === 'Pending' ? 'Student appointment requests will appear here for clinic approval.' : 'Try another status filter.',
    ]); ?>
</section>

<?php require __DIR__ . '/_availability_section.php'; ?>

<div id="appointmentCancelModal" class="modal-backdrop" data-no-row-click>
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-md shadow-2xl">
        <div class="flex items-start gap-4 mb-6">
            <div class="w-12 h-12 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined">event_busy</span>
            </div>
            <div>
                <h3 id="appointmentCancelTitle" class="font-headline text-lg font-extrabold text-[#1c2a59] mb-1">Cancel appointment</h3>
                <p class="text-sm font-bold text-slate-500">Please provide a reason. This will be visible in the appointment record.</p>
            </div>
        </div>
        <form method="post" action="update.php">
            <input type="hidden" name="id" id="appointmentCancelId" value="">
            <input type="hidden" name="status" value="Cancelled">
            <div class="mb-5">
                <label class="clinic-label" for="appointmentCancellationReason">Cancellation Reason</label>
                <textarea id="appointmentCancellationReason" name="cancellation_reason" class="clinic-textarea" required maxlength="500" placeholder="Example: Student requested reschedule, clinic schedule conflict, or another reason."></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeModal('appointmentCancelModal')" class="btn btn-ghost">Back</button>
                <button type="submit" class="btn btn-danger">
                    <span class="material-symbols-outlined text-[16px]">cancel</span>
                    Cancel Appointment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-cancel-appointment]');
        if (!button) {
            return;
        }

        event.preventDefault();
        document.getElementById('appointmentCancelId').value = button.dataset.cancelId || '';
        document.getElementById('appointmentCancelTitle').textContent = button.dataset.cancelTitle || 'Cancel appointment';
        document.getElementById('appointmentCancellationReason').value = '';
        showModal('appointmentCancelModal');
        setTimeout(() => document.getElementById('appointmentCancellationReason').focus(), 60);
    });
</script>

<?php render_footer(); ?>
