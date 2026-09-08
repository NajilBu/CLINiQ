<?php

require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/services/AlertWorkflow.php';
require_once __DIR__ . '/../app/services/PassportAccess.php';
ensure_alert_workflow_schema();

$token = $_GET['token'] ?? '';
$stmt = auth_db()->prepare("
    SELECT
        pt.person_id AS id,
        pt.person_id,
        pe.id_number,
        pe.first_name,
        pe.middle_name,
        pe.last_name,
        pe.birthdate,
        pe.sex,
        pt.blood_type,
        pt.allergies,
        pt.existing_conditions,
        pt.medications,
        pt.emergency_instructions,
        pt.guardian_or_contact_name AS guardian_name,
        pt.guardian_or_contact_number AS guardian_contact,
        pt.emergency_token,
        pt.token_enabled,
        COALESCE(
            NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''),
            ed.department_code,
            cd.department_code,
            'Not recorded'
        ) AS course_section
    FROM patients pt
    JOIN people pe ON pe.id = pt.person_id
    LEFT JOIN students s ON s.person_id = pe.id
    LEFT JOIN programs pr ON pr.id = s.program_id
    LEFT JOIN school_employees se ON se.person_id = pe.id
    LEFT JOIN departments ed ON ed.id = se.department_id
    LEFT JOIN clinic_staff cs ON cs.person_id = pe.id
    LEFT JOIN departments cd ON cd.id = cs.department_id
    WHERE pt.emergency_token = ? AND pt.token_enabled = 1
    LIMIT 1
");
$stmt->execute([$token]);
$patient = $stmt->fetch();
$message = null;
$error = null;
$authError = null;
$viewer = passport_current_viewer();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'passport_auth' && $patient) {
    $viewer = passport_authenticate_viewer((string) ($_POST['student_number'] ?? ''), (string) ($_POST['password'] ?? ''));
    if (!$viewer) {
        $authError = 'The student number or password is incorrect.';
        audit_log_event('passport', 'viewer_authentication_failed', null, 'guest', 'patient', (int) $patient['id'], [], 'failure');
    }
}

$viewerPersonId = (int) ($viewer['person_id'] ?? 0);

if ($patient) {
    $auditId = audit_log_event('passport', 'passport_viewed', $viewerPersonId ?: null, $viewerPersonId ? 'student' : 'guest', 'patient', (int) $patient['id'], [
        'route' => 'public/emergency.php',
        'authenticated' => $viewerPersonId > 0,
    ]);
    $log = auth_db()->prepare('INSERT INTO passport_access_logs (patient_id, viewer_person_id, audit_log_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)');
    $log->execute([
        $patient['id'],
        $viewerPersonId ?: null,
        $auditId ?: null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $patient && ($_POST['action'] ?? '') !== 'passport_auth') {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $location = trim($_POST['location'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $reporterName = trim($_POST['reporter_name'] ?? '');
    $reporterContact = trim($_POST['reporter_contact'] ?? '');
    if ($viewer) {
        $reporterName = (string) ($viewer['name'] ?? 'Authenticated student viewer');
    }
    $reporterRiskRating = trim((string) ($_POST['reporter_risk_rating'] ?? ''));
    if (!in_array($reporterRiskRating, ['Low', 'Moderate', 'High', 'Critical'], true)) {
        $reporterRiskRating = null;
    }
    $answers = collect_incident_report_answers([
        'incident_type' => $_POST['incident_type'] ?? '',
        'observed_condition' => $_POST['observed_condition'] ?? '',
        'breathing_status' => $_POST['breathing_status'] ?? '',
        'bleeding_status' => $_POST['bleeding_status'] ?? '',
        'pain_level' => $_POST['pain_level'] ?? '',
        'mobility_status' => $_POST['mobility_status'] ?? '',
        'notes' => $notes,
        'concern' => 'Possible accident reported from ID number',
    ]);
    $reportAnswers = incident_report_answers_text($answers);
    $classification = classify_reported_incident($answers);
    $riskReasons = incident_risk_reasons_text($classification);
    $photoPath = isset($_FILES['photo']) ? save_alert_photo_upload($_FILES['photo']) : null;

    if ($location === '') {
        $error = 'Please enter the reported location so the clinic knows where to respond.';
    } else {
        $rate = auth_db()->prepare("
            SELECT COUNT(*) AS total
            FROM incident_reports
            WHERE patient_id = ?
              AND ip_address <=> ?
              AND reported_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $rate->execute([$patient['id'], $ipAddress]);
        $recentReports = (int) ($rate->fetch()['total'] ?? 0);

        if ($recentReports >= 3) {
            $error = 'Too many reports were sent recently for this tag. Please call the clinic directly if this is urgent.';
        } else {
            $incident = auth_db()->prepare("
                INSERT INTO incident_reports
                    (patient_id, emergency_token, reporter_name, reporter_contact, location, notes, reporter_risk_rating, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $incident->execute([
                $patient['id'],
                $token,
                $reporterName ?: null,
                $reporterContact ?: null,
                $location,
                $notes ?: null,
                $reporterRiskRating,
                $ipAddress,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $details = trim(implode("\n", array_filter([
                'Reported from QR/NFC emergency tag.',
                $reporterContact ? 'Reporter contact: ' . $reporterContact : null,
                $notes ? 'Notes: ' . $notes : null,
                '',
                $reportAnswers,
            ])));

            $alert = auth_db()->prepare("
                INSERT INTO nurse_alerts
                    (patient_id, reporter_name, reporter_role, location, concern, incident_type, details, report_answers, reporter_risk_rating,
                     risk_level, risk_score, risk_reasons, response_guidance, photo_path, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $alert->execute([
                $patient['id'],
                $reporterName ?: 'QR/NFC scanner',
                'Emergency passport scanner',
                $location,
                'Possible accident reported from ID number',
                $answers['incident_type'] ?: null,
                $details ?: null,
                $reportAnswers,
                $reporterRiskRating,
                $classification['level'],
                $classification['score'],
                $riskReasons,
                $classification['guidance'],
                $photoPath,
            ]);

            $message = 'The clinic has been notified. Please stay with the student and call the clinic directly if the situation is urgent.';
            audit_log_event('incident', 'incident_report_submitted', $viewerPersonId ?: null, $viewerPersonId ? 'student' : 'guest', 'patient', (int) $patient['id'], ['location' => $location, 'risk_rating' => $reporterRiskRating]);
        }
    }
}

render_header('Emergency QR/NFC Response');
?>
<div class="passport-page">
    <?php if (!$patient): ?>
        <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold">Emergency tag not found or
            disabled.</div>
    <?php else: ?>
        <div class="clinic-card w-full max-w-2xl overflow-hidden">
            <div class="bg-red-700 text-white px-6 py-4 font-black">Emergency QR/NFC Response</div>
            <div class="p-6 md:p-8">
                <p class="clinic-label">Student Emergency Tag</p>
                <h1 class="font-headline text-3xl font-extrabold text-[#1c2a59] mb-2">Possible emergency?</h1>
                <p class="text-sm font-bold text-slate-500 mb-6">
                    This page notifies the clinic. Authenticate with your student account to view emergency essentials for this student.
                </p>

                <?php if ($authError): ?>
                    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold mb-4"><?= e($authError) ?></div>
                <?php endif; ?>

                <?php if (!$viewer): ?>
                    <form method="post" class="rounded-2xl bg-slate-50 border border-slate-200 p-5 mb-6">
                        <input type="hidden" name="action" value="passport_auth">
                        <p class="font-black text-slate-800 mb-1">Student access</p>
                        <p class="text-xs font-bold text-slate-500 mb-4">Students must sign in to view emergency passport essentials. Responders without an account may continue with the limited report form below.</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <input class="clinic-input" name="student_number" required placeholder="Student number">
                            <input class="clinic-input" name="password" type="password" required placeholder="Password">
                        </div>
                        <button class="mt-4 px-5 py-3 bg-slate-800 text-white rounded-2xl text-sm font-black" type="submit">View Emergency Essentials</button>
                    </form>
                    <div class="rounded-2xl bg-amber-50 border border-amber-100 text-amber-800 px-5 py-4 mb-6">
                        <p class="font-black mb-1">Limited responder view</p>
                        <p class="text-sm font-bold mb-0">Stay with the student, notify the clinic, and provide the current location. Do not rely on this page alone for urgent care.</p>
                    </div>
                <?php else: ?>
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-100 p-5 mb-6">
                        <p class="font-black text-emerald-900 mb-3">Emergency essentials</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm font-bold text-emerald-950">
                            <div><span class="text-emerald-700">Blood type:</span> <?= e($patient['blood_type'] ?: 'Unknown') ?></div>
                            <div><span class="text-emerald-700">Allergies:</span> <?= e($patient['allergies'] ?: 'None recorded') ?></div>
                            <div><span class="text-emerald-700">Conditions:</span> <?= e($patient['existing_conditions'] ?: 'None recorded') ?></div>
                            <div><span class="text-emerald-700">Medications:</span> <?= e($patient['medications'] ?: 'None recorded') ?></div>
                            <div class="md:col-span-2"><span class="text-emerald-700">Emergency instructions:</span> <?= e($patient['emergency_instructions'] ?: 'Notify the clinic immediately.') ?></div>
                            <div class="md:col-span-2"><span class="text-emerald-700">Guardian:</span> <?= e(trim(($patient['guardian_name'] ?: 'Not recorded') . ' ' . ($patient['guardian_contact'] ?: ''))) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($message): ?>
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-700 px-5 py-4 font-bold mb-4">
                        <?= e($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold mb-4">
                        <?= e($error) ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                        <label class="clinic-label">Reported Location</label>
                        <input class="clinic-input" name="location" required
                            placeholder="Example: Gymnasium, Room 204, gate area">
                    </div>
                    <div>
                        <label class="clinic-label">Reporter Name</label>
                        <input class="clinic-input" name="reporter_name" placeholder="Optional for responders" <?= $viewer ? 'value="' . e($viewer['name']) . '" readonly' : '' ?>>
                    </div>
                    <div>
                        <label class="clinic-label">Reporter Contact</label>
                        <input class="clinic-input" name="reporter_contact" placeholder="Optional phone number">
                    </div>
                    <div>
                        <label class="clinic-label">Incident Type</label>
                        <select class="clinic-input" name="incident_type">
                            <option value="">Select the closest type</option>
                            <?php foreach (incident_type_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Student Condition</label>
                        <select class="clinic-input" name="observed_condition">
                            <option value="">Select condition</option>
                            <?php foreach (incident_condition_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Breathing</label>
                        <select class="clinic-input" name="breathing_status">
                            <option value="">Select breathing status</option>
                            <?php foreach (incident_breathing_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Bleeding</label>
                        <select class="clinic-input" name="bleeding_status">
                            <option value="">Select bleeding status</option>
                            <?php foreach (incident_bleeding_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Pain Level</label>
                        <select class="clinic-input" name="pain_level">
                            <option value="">Select pain level</option>
                            <?php foreach (incident_pain_level_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="clinic-label">Mobility</label>
                        <select class="clinic-input" name="mobility_status">
                            <option value="">Select mobility</option>
                            <?php foreach (incident_mobility_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="clinic-label">How urgent does this seem? <span class="font-normal">(Optional)</span></label>
                        <select class="clinic-input" name="reporter_risk_rating">
                            <option value="">Not sure / skip</option>
                            <?php foreach (['Low', 'Moderate', 'High', 'Critical'] as $rating): ?>
                                <option value="<?= e($rating) ?>"><?= e($rating) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="clinic-label">Notes</label>
                        <textarea class="clinic-textarea" name="notes" rows="4"
                            placeholder="What happened? What does the student need?"></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <label class="clinic-label">Photo Evidence</label>
                        <input class="clinic-input" name="photo" type="file" accept="image/png,image/jpeg,image/webp">
                        <p class="text-xs font-bold text-slate-400 mt-2 mb-0">Optional image that will appear in the nurse alert report.</p>
                    </div>
                    <div class="md:col-span-2">
                        <button
                            class="w-full px-5 py-3 bg-red-600 text-white rounded-2xl text-sm font-black shadow-lg hover:bg-red-700" data-confirm-submit data-confirm-type="danger" data-confirm-title="Submit this emergency report?" data-confirm-message="This will send the possible accident report to the clinic response queue." data-confirm-toast="Submitting emergency report...">Report
                            Possible Accident to Clinic</button>
                    </div>
                </form>

                <p class="text-xs font-bold text-slate-500 mt-6 mb-0">
                    If this is urgent, call the clinic or school emergency contact immediately after submitting the report.
                </p>
            </div>
        </div>
    <?php endif; ?>
</div>
