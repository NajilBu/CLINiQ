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
        appointment_save_doctor_roles(
            (int) ($_POST['doctor_id'] ?? 0),
            (array) ($_POST['coverage'] ?? []),
            (int) (current_user()['person_id'] ?? 0) ?: null,
            isset($_POST['remove_assignments'])
        );
        flash_message('success', isset($_POST['remove_assignments']) ? 'Doctor roles were removed.' : 'Doctor consultation roles were saved.');
    } catch (InvalidArgumentException $exception) {
        flash_message('error', $exception->getMessage());
    }
    header('Location: doctors.php');
    exit;
}

$doctors = appointment_active_doctors();
$schedule = appointment_doctor_schedule();
set_page_back_link(app_url('appointments/index.php'), 'Back to Appointments');
render_header('Consultation Roles');
render_clinic_command_header(
    'Clinic Team',
    'Consultation Roles',
    'Assign active doctors to Medical Consult, Dental, or both. Service hours and open days are managed in Clinic Availability.'
);
?>

<section class="clinic-card consultation-doctors-shell">
    <header class="consultation-doctors-intro">
        <div class="consultation-doctors-intro-icon" aria-hidden="true"><span class="material-symbols-outlined">stethoscope</span></div>
        <div>
            <p class="appointment-availability-eyebrow">Care coverage</p>
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Assign consultation roles</h2>
            <p class="text-sm text-slate-600 mb-0">Choose the services each doctor covers. Clinic Availability controls service hours, open days, and future schedules.</p>
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
                $hasAssignment = isset($schedule['Medical Consult']['doctors'][$doctorId]) || isset($schedule['Dental']['doctors'][$doctorId]);
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
                    <?php foreach (appointment_consult_purposes() as $purpose): ?>
                        <fieldset class="consultation-doctor-fieldset">
                            <legend><?= e($purpose) ?></legend>
                            <label class="consultation-choice"><input type="checkbox" name="coverage[<?= e($purpose) ?>][enabled]" value="1" <?= isset($schedule[$purpose]['doctors'][$doctorId]) ? 'checked' : '' ?>><span>This doctor covers <?= e($purpose) ?></span></label>
                            <p>Assigned doctors cover every open day for this service. Change hours and days in Clinic Availability.</p>
                        </fieldset>
                    <?php endforeach; ?>
                    </div>
                    <div class="consultation-doctor-actions"><button class="btn btn-primary"><span class="material-symbols-outlined" aria-hidden="true">save</span>Save roles</button></div>
                </form>
                <?php if ($hasAssignment): ?>
                    <form method="post" class="consultation-doctor-remove-form">
                        <input type="hidden" name="doctor_id" value="<?= $doctorId ?>">
                        <input type="hidden" name="remove_assignments" value="1">
                        <button class="btn btn-ghost" data-confirm-submit data-confirm-type="danger" data-confirm-title="Remove doctor roles?" data-confirm-message="This doctor will no longer cover Medical Consult or Dental. Existing bookings must still have another doctor assigned." data-confirm-toast="Removing roles..."><span class="material-symbols-outlined" aria-hidden="true">person_remove</span>Remove roles</button>
                    </form>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
