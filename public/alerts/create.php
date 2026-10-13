<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AlertWorkflow.php';
require_once __DIR__ . '/../../app/services/AccountValidation.php';
require_once __DIR__ . '/../../app/services/CliniqVisitWorkflow.php';

require_report_access();
ensure_alert_workflow_schema();

$user = current_user() ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $reporterName = cliniq_normalize_person_name($_POST['reporter_name'] ?? '');
        if (!account_valid_person_name($reporterName)) {
            throw new InvalidArgumentException('Reporter name may contain only letters, spaces, apostrophes, periods, or hyphens.');
        }
        $reporterRole = cliniq_assert_bounded_text($_POST['reporter_role'] ?? '', 'Reporter role', 80);
        $location = cliniq_assert_bounded_text($_POST['location'] ?? '', 'Location', 160, true);
        $concern = cliniq_assert_bounded_text($_POST['concern'] ?? '', 'Concern', 255, true);
        $patientIdNumber = cliniq_assert_bounded_text($_POST['patient_id_number'] ?? '', 'Patient ID number', 80);
        $patient = $patientIdNumber === '' ? null : cliniq_visit_patient_by_id_number($patientIdNumber, true);
        if ($patientIdNumber !== '' && !$patient) {
            throw new InvalidArgumentException('No registered patient matches that ID number. Clear the field to save an unlinked record.');
        }
        $resolutionReport = cliniq_assert_bounded_text($_POST['resolution_report'] ?? '', 'Accident / response report', 5000, true);
        $answers = collect_incident_report_answers([
            'incident_type' => $_POST['incident_type'] ?? '',
            'observed_condition' => $_POST['observed_condition'] ?? '',
            'breathing_status' => $_POST['breathing_status'] ?? '',
            'bleeding_status' => $_POST['bleeding_status'] ?? '',
            'pain_level' => $_POST['pain_level'] ?? '',
            'mobility_status' => $_POST['mobility_status'] ?? '',
            'notes' => $_POST['details'] ?? '',
            'concern' => $concern,
        ]);
        $photoPath = isset($_FILES['photo']) ? save_alert_photo_upload($_FILES['photo']) : null;
    } catch (InvalidArgumentException $e) {
        flash_message('warning', $e->getMessage());
        header('Location: create.php');
        exit;
    }

    $reportAnswers = incident_report_answers_text($answers);
    $classification = classify_reported_incident($answers);
    $details = trim($answers['notes'] . ($answers['notes'] !== '' ? "\n\n" : '') . $reportAnswers);
    $actorPersonId = (int) ($user['person_id'] ?? $user['id'] ?? 0) ?: null;
    $stmt = auth_db()->prepare(
        "INSERT INTO nurse_alerts (
            record_source, patient_id, reporter_name, reporter_role, location, concern, incident_type, details, report_answers,
            risk_level, risk_score, risk_reasons, response_guidance, photo_path, status, resolution_report, resolved_by, resolved_at
        ) VALUES ('Staff documentation', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Resolved', ?, ?, NOW())"
    );
    $stmt->execute([
        $patient['person_id'] ?? null,
        $reporterName,
        $reporterRole,
        $location,
        $concern,
        $answers['incident_type'] ?: null,
        $details,
        $reportAnswers,
        $classification['level'],
        $classification['score'],
        incident_risk_reasons_text($classification),
        $classification['guidance'],
        $photoPath,
        $resolutionReport,
        $actorPersonId,
    ]);
    $alertId = (int) auth_db()->lastInsertId();
    audit_log_event('incident', 'incident_documented_resolved', $actorPersonId, 'staff', 'nurse_alert', $alertId, [
        'record_source' => 'Staff documentation',
        'patient_id' => $patient['person_id'] ?? null,
    ]);

    flash_message('success', 'Incident documented and marked resolved.');
    header('Location: view.php?id=' . $alertId);
    exit;
}

set_page_back_link('index.php', 'Back');
render_header('Document Incident');
render_clinic_command_header(
    'Alerts',
    'Document Incident',
    'Record a completed incident that was not submitted through the Emergency Passport.'
);
?>

<form class="clinic-card p-6 md:p-8" method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div>
            <label class="clinic-label">Patient ID Number <span class="text-slate-400">(Optional)</span></label>
            <input class="clinic-input uppercase" name="patient_id_number" placeholder="Leave blank if no registered patient is involved" data-id-number-format>
            <p class="text-xs font-bold text-slate-400 mt-2 mb-0">A supplied ID must match a registered patient.</p>
        </div>
        <div>
            <label class="clinic-label">Reporter Name</label>
            <input class="clinic-input" name="reporter_name" required placeholder="Name of the person who reported the incident">
        </div>
        <div>
            <label class="clinic-label">Reporter Role</label>
            <input class="clinic-input" name="reporter_role" placeholder="Student, Teacher, Staff">
        </div>
        <div>
            <label class="clinic-label">Location</label>
            <input class="clinic-input" name="location" required placeholder="Building, room, or area">
        </div>
        <div>
            <label class="clinic-label">Concern</label>
            <input class="clinic-input" name="concern" required placeholder="Brief description of the incident">
        </div>
        <div>
            <label class="clinic-label">Incident Type</label>
            <select class="clinic-input" name="incident_type" required>
                <option value="">Select the closest type</option>
                <?php foreach (incident_type_options() as $option): ?>
                    <option value="<?= e($option) ?>"><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="clinic-label">Student Condition</label>
            <select class="clinic-input" name="observed_condition" required>
                <option value="">Select condition</option>
                <?php foreach (incident_condition_options() as $option): ?>
                    <option value="<?= e($option) ?>"><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="clinic-label">Breathing</label>
            <select class="clinic-input" name="breathing_status" required>
                <option value="">Select breathing status</option>
                <?php foreach (incident_breathing_options() as $option): ?>
                    <option value="<?= e($option) ?>"><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="clinic-label">Bleeding</label>
            <select class="clinic-input" name="bleeding_status" required>
                <option value="">Select bleeding status</option>
                <?php foreach (incident_bleeding_options() as $option): ?>
                    <option value="<?= e($option) ?>"><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="clinic-label">Pain Level</label>
            <select class="clinic-input" name="pain_level" required>
                <option value="">Select pain level</option>
                <?php foreach (incident_pain_level_options() as $option): ?>
                    <option value="<?= e($option) ?>"><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="clinic-label">Mobility</label>
            <select class="clinic-input" name="mobility_status" required>
                <option value="">Select mobility</option>
                <?php foreach (incident_mobility_options() as $option): ?>
                    <option value="<?= e($option) ?>"><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Details</label>
            <textarea class="clinic-textarea" name="details" rows="4" placeholder="Additional details about the incident..."></textarea>
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Accident / Response Report</label>
            <textarea class="clinic-textarea" name="resolution_report" rows="8" required placeholder="Write what happened, how staff responded, treatment or referral made, and final outcome."></textarea>
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Photo Evidence (Optional)</label>
            <input class="clinic-input" name="photo" type="file" accept="image/png,image/jpeg,image/webp">
            <p class="text-xs font-bold text-slate-400 mt-2 mb-0">JPG, PNG, or WebP only. Maximum 5MB.</p>
        </div>
    </div>
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Document this incident?" data-confirm-message="This will save the incident directly to resolved history. It will not trigger an emergency alert." data-confirm-toast="Documenting incident...">
            <span class="material-symbols-outlined text-[18px]">task_alt</span> Save Resolved Record
        </button>
        <span class="text-xs font-bold text-slate-400">This is for record keeping only, not emergency dispatch.</span>
    </div>
</form>
<?php render_footer(); ?>
