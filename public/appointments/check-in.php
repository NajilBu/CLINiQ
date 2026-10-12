<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/../../app/services/CliniqVisitWorkflow.php';
require_login();
ensure_appointment_schema();

$appointmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    ?: (int) ($_POST['id'] ?? 0);
if ($appointmentId < 1) {
    http_response_code(404);
    exit('Appointment record not found.');
}

$db = appointment_db();
$appointmentStmt = $db->prepare('
    SELECT a.appointment_id, a.patient_id, a.appointment_datetime, a.purpose, a.status,
           p.first_name, p.middle_name, p.last_name, p.id_number,
           v.visit_id AS linked_visit_id, v.status AS linked_visit_status
    FROM appointments a
    JOIN people p ON p.id = a.patient_id
    LEFT JOIN visits v ON v.appointment_id = a.appointment_id
    WHERE a.appointment_id = ?
    LIMIT 1
');
$appointmentStmt->execute([$appointmentId]);
$appointment = $appointmentStmt->fetch();
if (!$appointment) {
    http_response_code(404);
    exit('Appointment record not found.');
}

if (!empty($appointment['linked_visit_id'])) {
    header('Location: ' . app_url('visits/view.php?id=' . (int) $appointment['linked_visit_id'] . '&from=logbook'));
    exit;
}

if (!appointment_can_check_in($appointment)) {
    flash_message('warning', 'Only same-day appointments awaiting clinical check-in can be checked in.');
    header('Location: index.php?status=Scheduled');
    exit;
}

$chiefComplaint = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chiefComplaint = trim((string) ($_POST['chief_complaint'] ?? ''));
    try {
        $visitId = cliniq_visit_create_from_appointment(
            $appointmentId,
            $chiefComplaint,
            cliniq_visit_staff_person_id()
        );
        flash_message('success', 'Appointment checked in. Complete the clinical visit record.');
        header('Location: ' . app_url('visits/view.php?id=' . $visitId . '&from=logbook'));
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to check in this appointment. Please try again.';
    }
}

$patientName = trim(implode(' ', array_filter([
    $appointment['first_name'] ?? '',
    $appointment['middle_name'] ?? '',
    $appointment['last_name'] ?? '',
], static fn ($part): bool => trim((string) $part) !== '')));

set_page_back_link('index.php?status=Scheduled', 'Appointments');
render_header('Check In Appointment');
render_clinic_command_header(
    'Scheduling',
    'Check In Appointment',
    'Open the linked clinical visit when the patient arrives at the clinic.'
);
?>

<section class="clinic-card p-6 sm:p-8 max-w-3xl">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">
        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4"><p class="clinic-label mb-1">Patient</p><p class="font-bold text-[#17261d] mb-0"><?= e($patientName) ?><br><span class="text-sm text-slate-500"><?= e((string) $appointment['id_number']) ?></span></p></div>
        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4"><p class="clinic-label mb-1">Appointment</p><p class="font-bold text-[#17261d] mb-0"><?= e(date('F j, Y · g:i A', strtotime((string) $appointment['appointment_datetime']))) ?><br><span class="text-sm text-slate-500"><?= e((string) $appointment['purpose']) ?></span></p></div>
    </div>
    <?php if ($error !== ''): ?><div class="notice notice-warning mb-5" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="space-y-5">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $appointmentId ?>">
        <div>
            <label class="clinic-label" for="chief_complaint">Chief Complaint</label>
            <textarea id="chief_complaint" name="chief_complaint" class="clinic-textarea" rows="4" maxlength="255" required autofocus placeholder="Record the patient's reason for visiting today."><?= e($chiefComplaint) ?></textarea>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="submit" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">how_to_reg</span>Check In and Open Visit</button>
            <a href="index.php?status=Scheduled" class="btn btn-outline text-decoration-none">Back to Appointments</a>
        </div>
    </form>
</section>

<?php render_footer(); ?>
