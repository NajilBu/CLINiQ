<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_login();
ensure_appointment_schema();

$appointmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$appointmentId) {
    http_response_code(404);
    exit('Appointment record not found.');
}

$stmt = appointment_db()->prepare("
    SELECT a.*, p.first_name, p.middle_name, p.last_name, p.id_number,
           COALESCE(
               NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''),
               ed.department_code,
               'Patient'
           ) AS course_section,
           TRIM(CONCAT_WS(' ', reviewer.first_name, reviewer.middle_name, reviewer.last_name)) AS reviewer_name
    FROM appointments a
    JOIN patients pt ON pt.person_id = a.patient_id
    JOIN people p ON p.id = pt.person_id
    LEFT JOIN students s ON s.person_id = p.id
    LEFT JOIN programs pr ON pr.id = s.program_id
    LEFT JOIN school_employees se ON se.person_id = p.id
    LEFT JOIN departments ed ON ed.id = se.department_id
    LEFT JOIN people reviewer ON reviewer.id = a.reviewed_by_person_id
    WHERE a.appointment_id = ?
    LIMIT 1
");
$stmt->execute([$appointmentId]);
$appointment = $stmt->fetch();
if (!$appointment) {
    http_response_code(404);
    exit('Appointment record not found.');
}

$allowedReturnStatuses = ['all', 'Pending', 'Scheduled', 'For Confirmation', 'Completed', 'Cancelled', 'No Show'];
$returnStatus = (string) ($_GET['status'] ?? 'all');
if (!in_array($returnStatus, $allowedReturnStatuses, true)) {
    $returnStatus = 'all';
}
$patientName = trim(implode(' ', array_filter([
    $appointment['first_name'],
    $appointment['middle_name'],
    $appointment['last_name'],
], static fn ($part): bool => trim((string) $part) !== '')));
$notes = trim((string) ($appointment['notes'] ?? ''));
$cancellationReason = trim((string) ($appointment['cancellation_reason'] ?? ''));
$reviewerName = trim((string) ($appointment['reviewer_name'] ?? ''));

set_page_back_link(app_url('appointments/index.php?status=' . rawurlencode($returnStatus)), 'Back to Appointments');
render_header('Appointment Record');
render_clinic_command_header(
    'Scheduling',
    'Appointment Record',
    'Read-only details for this clinic appointment.'
);
?>

<section class="clinic-card p-6 sm:p-8">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 border-b border-slate-100 pb-6 mb-6">
        <div>
            <p class="clinic-label mb-1">Patient</p>
            <h2 class="font-headline text-2xl font-extrabold text-[#17261d] mb-1"><?= e($patientName) ?></h2>
            <p class="text-sm font-semibold text-slate-500 mb-0"><?= e((string) $appointment['id_number']) ?> · <?= e((string) $appointment['course_section']) ?></p>
        </div>
        <div class="sm:text-right">
            <span class="badge <?= e(appointment_status_badge_class((string) $appointment['status'])) ?>"><?= e(appointment_status_display_label((string) $appointment['status'])) ?></span>
            <p class="text-xs font-semibold text-slate-500 mt-2 mb-0">Appointment #<?= (int) $appointment['appointment_id'] ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
            <p class="clinic-label mb-1">Appointment date</p>
            <p class="font-bold text-[#17261d] mb-0"><?= e(date('F j, Y', strtotime((string) $appointment['appointment_datetime']))) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
            <p class="clinic-label mb-1">Time</p>
            <p class="font-bold text-[#17261d] mb-0"><?= e(date('g:i A', strtotime((string) $appointment['appointment_datetime']))) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
            <p class="clinic-label mb-1">Purpose</p>
            <p class="font-bold text-[#17261d] mb-0"><?= e((string) $appointment['purpose']) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
            <p class="clinic-label mb-1">Requested through</p>
            <p class="font-bold text-[#17261d] mb-0"><?= e((string) $appointment['request_source']) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
            <p class="clinic-label mb-1">Requested on</p>
            <p class="font-bold text-[#17261d] mb-0"><?= e(date('F j, Y · g:i A', strtotime((string) $appointment['created_at']))) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
            <p class="clinic-label mb-1">Last updated</p>
            <p class="font-bold text-[#17261d] mb-0"><?= e(date('F j, Y · g:i A', strtotime((string) $appointment['updated_at']))) ?></p>
        </div>
        <?php if ($reviewerName !== ''): ?>
            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
                <p class="clinic-label mb-1">Reviewed by</p>
                <p class="font-bold text-[#17261d] mb-0"><?= e($reviewerName) ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($appointment['cancelled_by'])): ?>
            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
                <p class="clinic-label mb-1">Cancelled by</p>
                <p class="font-bold text-[#17261d] mb-0"><?= e((string) $appointment['cancelled_by']) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <div class="mt-6 border-t border-slate-100 pt-6">
        <p class="clinic-label mb-2">Appointment notes</p>
        <p class="text-sm text-slate-700 leading-6 mb-0"><?= $notes !== '' ? nl2br(e($notes)) : 'No notes recorded.' ?></p>
    </div>
    <?php if ($cancellationReason !== ''): ?>
        <div class="mt-5 rounded-2xl border border-red-100 bg-red-50 p-5">
            <p class="clinic-label mb-2">Cancellation reason</p>
            <p class="text-sm text-slate-700 leading-6 mb-0"><?= nl2br(e($cancellationReason)) ?></p>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
