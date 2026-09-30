<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_login();
ensure_appointment_schema();

if (!in_array((string) (current_user()['role'] ?? ''), ['admin', 'doctor', 'nurse'], true)) {
    http_response_code(403);
    exit('You do not have permission to manage appointment doctors.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        appointment_save_doctor_days(
            (int) ($_POST['doctor_id'] ?? 0),
            (array) ($_POST['purposes'] ?? []),
            (array) ($_POST['days'] ?? []),
            (int) (current_user()['person_id'] ?? 0) ?: null,
            isset($_POST['remove_assignments'])
        );
        flash_message('success', isset($_POST['remove_assignments']) ? 'Doctor assignments were removed.' : 'Doctor consultation days were saved.');
    } catch (InvalidArgumentException $exception) {
        flash_message('error', $exception->getMessage());
    }
    header('Location: doctors.php');
    exit;
}

$doctors = appointment_active_doctors();
$schedule = appointment_doctor_schedule();
$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$currentMonth = date('Y-m');
$currentMonthLabel = date('F Y');
$currentMonthClinicSchedule = appointment_schedule_for_month($currentMonth);

set_page_back_link(app_url('appointments/index.php'), 'Back to Appointments');
render_header('Consultation Doctors');
render_clinic_command_header(
    'Scheduling',
    'Consultation Doctors',
    'Assign active doctors to medical and dental consultations and set their working days.'
);
?>

<section class="clinic-card consultation-doctors-shell">
    <header class="consultation-doctors-intro">
        <div class="consultation-doctors-intro-icon" aria-hidden="true"><span class="material-symbols-outlined">stethoscope</span></div>
        <div>
            <p class="appointment-availability-eyebrow">Care coverage</p>
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Assign consultation doctors</h2>
            <p class="text-sm text-slate-600 mb-0">Choose the services and clinic days each doctor covers. Leaving a group blank uses the all-inclusive default.</p>
        </div>
        <span class="consultation-doctors-count"><span class="material-symbols-outlined" aria-hidden="true">group</span><?= count($doctors) ?> active</span>
    </header>
    <?php if (!$doctors): ?>
        <div class="appointment-timeline-empty"><span class="material-symbols-outlined" aria-hidden="true">group_off</span><div><strong>No active doctors yet</strong><span>Activate a doctor account in Settings before assigning consultation coverage.</span></div></div>
    <?php else: ?>
        <div class="consultation-doctor-list">
        <?php foreach ($doctors as $doctor): ?>
            <?php $doctorId = (int) $doctor['id']; ?>
            <?php
                $medicalDays = $schedule['Medical Consult']['doctors'][$doctorId]['days'] ?? null;
                $dentalDays = $schedule['Dental']['doctors'][$doctorId]['days'] ?? null;
                $hasAssignment = $medicalDays !== null || $dentalDays !== null;
                $selectedDays = $medicalDays ?? $dentalDays ?? [];
                if ($medicalDays !== null && $dentalDays !== null) {
                    $selectedDays = !$medicalDays || !$dentalDays ? [] : array_values(array_unique(array_merge($medicalDays, $dentalDays)));
                }
                $daysDiffer = $medicalDays !== null && $dentalDays !== null && $medicalDays !== $dentalDays;
                $assignedPurposes = array_values(array_filter(appointment_consult_purposes(), static fn(string $purpose): bool => isset($schedule[$purpose]['doctors'][$doctorId])));
                $coverageLabel = !$hasAssignment ? 'Not assigned yet' : (!$assignedPurposes ? 'Medical & Dental' : implode(' & ', array_map(static fn(string $purpose): string => str_replace(' Consult', '', $purpose), $assignedPurposes)));
            ?>
            <details class="consultation-doctor-card" <?= !$hasAssignment ? 'open' : '' ?>>
                <summary class="consultation-doctor-summary">
                    <span class="consultation-doctor-identity"><span class="consultation-doctor-avatar material-symbols-outlined" aria-hidden="true">medical_services</span><span><strong><?= e($doctor['name']) ?></strong><small><?= e($coverageLabel) ?></small></span></span>
                    <span class="consultation-doctor-summary-end"><span class="consultation-doctor-status <?= $hasAssignment ? 'is-assigned' : '' ?>"><?= $hasAssignment ? 'Assigned' : 'Needs setup' ?></span><span class="material-symbols-outlined consultation-doctor-chevron" aria-hidden="true">expand_more</span></span>
                </summary>
                <form method="post" class="consultation-doctor-form">
                    <input type="hidden" name="doctor_id" value="<?= $doctorId ?>">
                    <div class="consultation-doctor-fields">
                    <fieldset class="consultation-doctor-fieldset">
                        <legend>Consultation purpose</legend>
                        <p>Leave both clear for Medical Consult and Dental.</p>
                        <div class="consultation-choice-list">
                            <?php foreach (appointment_consult_purposes() as $purpose): ?>
                                <label class="consultation-choice"><input type="checkbox" name="purposes[]" value="<?= e($purpose) ?>" <?= isset($schedule[$purpose]['doctors'][$doctorId]) ? 'checked' : '' ?>><span><?= e($purpose) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <fieldset class="consultation-doctor-fieldset">
                        <legend>Assigned days</legend>
                        <p>Leave all clear for every clinic working day. Muted days are closed in <?= e($currentMonthLabel) ?>.</p>
                        <?php if ($daysDiffer): ?><p class="consultation-inline-warning"><span class="material-symbols-outlined" aria-hidden="true">info</span>Saving will apply these days to both services.</p><?php endif; ?>
                        <div class="consultation-choice-list consultation-weekday-list">
                        <?php foreach ($weekdays as $day => $label): ?>
                            <?php $closedThisMonth = empty($currentMonthClinicSchedule[$day]['enabled']); ?>
                            <label class="consultation-choice <?= $closedThisMonth ? 'is-closed' : '' ?>" <?= $closedThisMonth ? 'title="Clinic closed in ' . e($currentMonthLabel) . '; selectable for future schedules"' : '' ?>>
                                <input type="checkbox" name="days[]" value="<?= $day ?>" <?= in_array($day, $selectedDays, true) ? 'checked' : '' ?>><span><?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </fieldset>
                    </div>
                    <div class="consultation-doctor-actions"><button class="btn btn-primary"><span class="material-symbols-outlined" aria-hidden="true">save</span>Save coverage</button></div>
                </form>
                <?php if ($hasAssignment): ?>
                    <form method="post" class="consultation-doctor-remove-form">
                        <input type="hidden" name="doctor_id" value="<?= $doctorId ?>">
                        <input type="hidden" name="remove_assignments" value="1">
                        <button class="btn btn-ghost" data-confirm-submit data-confirm-type="danger" data-confirm-title="Remove doctor assignments?" data-confirm-message="This doctor will no longer cover medical or dental consultations. Existing bookings must still have another doctor available." data-confirm-toast="Removing assignments..."><span class="material-symbols-outlined" aria-hidden="true">person_remove</span>Remove assignments</button>
                    </form>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
