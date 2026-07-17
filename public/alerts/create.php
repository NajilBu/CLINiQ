<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AlertWorkflow.php';
ensure_alert_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $photoPath = isset($_FILES['photo']) ? save_alert_photo_upload($_FILES['photo']) : null;
    $answers = collect_incident_report_answers([
        'incident_type' => $_POST['incident_type'] ?? '',
        'observed_condition' => $_POST['observed_condition'] ?? '',
        'breathing_status' => $_POST['breathing_status'] ?? '',
        'bleeding_status' => $_POST['bleeding_status'] ?? '',
        'pain_level' => $_POST['pain_level'] ?? '',
        'mobility_status' => $_POST['mobility_status'] ?? '',
        'notes' => $_POST['details'] ?? '',
        'concern' => $_POST['concern'] ?? '',
    ]);
    $reportAnswers = incident_report_answers_text($answers);
    $classification = classify_reported_incident($answers);
    $riskReasons = incident_risk_reasons_text($classification);
    $details = trim((string) ($_POST['details'] ?? ''));
    $details = trim($details . ($details !== '' ? "\n\n" : '') . $reportAnswers);
    $stmt = db()->prepare(
        'INSERT INTO nurse_alerts (
            reporter_name, reporter_role, location, concern, incident_type, details, report_answers,
            risk_level, risk_score, risk_reasons, response_guidance, photo_path
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        trim($_POST['reporter_name'] ?? ''),
        trim($_POST['reporter_role'] ?? ''),
        trim($_POST['location'] ?? ''),
        trim($_POST['concern'] ?? ''),
        $answers['incident_type'] ?: null,
        $details,
        $reportAnswers,
        $classification['level'],
        $classification['score'],
        $riskReasons,
        $classification['guidance'],
        $photoPath,
    ]);

    flash_message('success', 'Alert submitted! The clinic has been notified.');
    header('Location: index.php');
    exit;
}

set_page_back_link('index.php', 'Back');
render_header('Submit Alert');
?>
<?php render_clinic_command_header(
    'Emergency',
    'Submit Emergency Alert',
    'Send an urgent concern to the clinic dashboard.'
); ?>

<form class="clinic-card p-6 md:p-8" method="post" enctype="multipart/form-data">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div>
            <label class="clinic-label">Reporter Name</label>
            <input class="clinic-input" name="reporter_name" required placeholder="Your full name">
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
            <input class="clinic-input" name="concern" required placeholder="Brief description of the emergency">
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
            <textarea class="clinic-textarea" name="details" rows="4" placeholder="Additional details about the situation..."></textarea>
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Photo Evidence (Optional)</label>
            <input class="clinic-input" name="photo" type="file" accept="image/png,image/jpeg,image/webp">
            <p class="text-xs font-bold text-slate-400 mt-2 mb-0">JPG, PNG, or WebP only. Maximum 5MB.</p>
        </div>
    </div>
    <div class="mt-6">
        <button class="btn btn-danger" data-confirm-submit data-confirm-type="danger" data-confirm-title="Submit this alert?" data-confirm-message="This will send the emergency alert to the nurse alert queue." data-confirm-toast="Submitting alert...">
            <span class="material-symbols-outlined text-[18px]">emergency_home</span> Send Alert
        </button>
    </div>
</form>
<?php render_footer(); ?>
