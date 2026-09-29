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

<section class="clinic-card p-6">
    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-2">Assign doctors</h2>
    <p class="text-sm text-slate-600 mb-5">Select a doctor, then optionally choose purposes and weekdays. No purpose selected means both Medical Consult and Dental. No days selected means every clinic working day. A doctor covering both services will not be double-booked in the same hour.</p>
    <?php if (!$doctors): ?>
        <p class="text-sm text-slate-600">No active doctor accounts are available. Activate a doctor account in Settings first.</p>
    <?php else: ?>
        <div class="space-y-4">
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
            ?>
            <details class="rounded-2xl border border-slate-200 p-4">
                <summary class="cursor-pointer font-bold text-[#17261d] flex items-center justify-between">
                    <span><?= e($doctor['name']) ?></span><span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
                </summary>
                <form method="post" class="mt-5 space-y-5">
                    <input type="hidden" name="doctor_id" value="<?= $doctorId ?>">
                    <fieldset>
                        <legend class="font-bold text-sm mb-2">Consultation purpose</legend>
                        <div class="flex flex-wrap gap-4">
                            <?php foreach (appointment_consult_purposes() as $purpose): ?>
                                <label class="inline-flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="purposes[]" value="<?= e($purpose) ?>" <?= isset($schedule[$purpose]['doctors'][$doctorId]) ? 'checked' : '' ?>> <?= e($purpose) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs text-slate-500 mt-2 mb-0">Leave both unchecked to assign both purposes.</p>
                    </fieldset>
                    <fieldset>
                        <legend class="font-bold text-sm mb-2">Assigned days</legend>
                        <p class="text-xs text-slate-500 mb-2">Leave all days unchecked for every clinic working day. Grey days are closed in <?= e($currentMonthLabel) ?>, but can still be selected for a future clinic schedule.</p>
                        <?php if ($daysDiffer): ?><p class="text-xs text-amber-700 mb-2">This doctor currently has different days for each purpose. Saving will apply the selected days to both.</p><?php endif; ?>
                        <div class="flex flex-wrap gap-3">
                        <?php foreach ($weekdays as $day => $label): ?>
                            <?php $closedThisMonth = empty($currentMonthClinicSchedule[$day]['enabled']); ?>
                            <label class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm cursor-pointer <?= $closedThisMonth ? 'border-slate-200 bg-slate-100 text-slate-400' : 'border-slate-200' ?>" <?= $closedThisMonth ? 'title="Clinic closed in ' . e($currentMonthLabel) . '; selectable for future schedules"' : '' ?>>
                                <input type="checkbox" name="days[]" value="<?= $day ?>" <?= in_array($day, $selectedDays, true) ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <button class="btn btn-primary" data-confirm-submit data-confirm-title="Save doctor days?" data-confirm-message="This will update medical and dental coverage for the selected doctor." data-confirm-toast="Saving doctor days...">Save assigned days</button>
                </form>
                <?php if ($hasAssignment): ?>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="doctor_id" value="<?= $doctorId ?>">
                        <input type="hidden" name="remove_assignments" value="1">
                        <button class="btn btn-ghost" data-confirm-submit data-confirm-type="danger" data-confirm-title="Remove doctor assignments?" data-confirm-message="This doctor will no longer cover medical or dental consultations. Existing bookings must still have another doctor available." data-confirm-toast="Removing assignments...">Remove assignments</button>
                    </form>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
