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
        pe.profile_photo_path,
        pe.birthdate,
        pe.sex,
        pt.blood_type,
        pt.allergies,
        pt.existing_conditions,
        pt.medications,
        pt.emergency_instructions,
        pt.guardian_or_contact_name AS guardian_name,
        pt.guardian_or_contact_number AS guardian_contact,
        pt.guardian_relationship,
        pt.secondary_contact_number,
        pt.show_bmi_on_passport,
        pt.updated_at,
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
$explicitPassportViewerId = (int) ($_SESSION['passport_viewer_person_id'] ?? 0);
$viewer = $explicitPassportViewerId > 0
    ? passport_viewer_from_person_id($explicitPassportViewerId)
    : passport_current_viewer();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'passport_auth' && $patient) {
    $viewer = passport_authenticate_viewer((string) ($_POST['student_number'] ?? ''), (string) ($_POST['password'] ?? ''));
    if (!$viewer) {
        $authError = 'The ID number or password is incorrect.';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $patient && ($_POST['action'] ?? '') === 'incident_report' && $viewerPersonId > 0) {
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

$passportHolderName = trim(implode(' ', array_filter([
    $patient['first_name'] ?? '',
    $patient['middle_name'] ?? '',
    $patient['last_name'] ?? '',
])));
$latestVitals = null;
$hasActiveIncident = false;
if ($patient) {
    $vitalsStmt = auth_db()->prepare("
        SELECT patient_height_cm, patient_weight_kg, patient_bmi
        FROM ape_records
        WHERE patient_id = ?
          AND patient_vitals_status = 'Confirmed'
          AND (patient_height_cm IS NOT NULL OR patient_weight_kg IS NOT NULL OR patient_bmi IS NOT NULL)
        ORDER BY COALESCE(patient_vitals_confirmed_at, exam_date, created_at) DESC, ape_id DESC
        LIMIT 1
    ");
    $vitalsStmt->execute([(int) $patient['id']]);
    $latestVitals = $vitalsStmt->fetch() ?: null;

    $activeIncidentStmt = auth_db()->prepare("SELECT COUNT(*) FROM nurse_alerts WHERE patient_id = ? AND status = 'Pending'");
    $activeIncidentStmt->execute([(int) $patient['id']]);
    $hasActiveIncident = (int) $activeIncidentStmt->fetchColumn() > 0;
}
$passportUpdatedAt = !empty($patient['updated_at'])
    ? date('F j, Y', strtotime((string) $patient['updated_at']))
    : 'Not recorded';
$reportFormOpen = $error !== null;

render_header('Emergency Health Passport');
?>
<link rel="stylesheet" href="<?= app_url('assets/css/emergency-passport.css?v=' . filemtime(__DIR__ . '/assets/css/emergency-passport.css')) ?>">
<div class="passport-page">
    <?php if (!$patient): ?>
        <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold">Emergency tag not found or
            disabled.</div>
    <?php else: ?>
        <div class="clinic-card w-full max-w-3xl overflow-hidden">
            <div class="bg-[#173f2a] text-white px-6 py-5">
                <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-200 mb-1">CLINiQ</p>
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-3xl" aria-hidden="true">medical_information</span>
                    <div>
                        <h1 class="font-headline text-2xl md:text-3xl font-extrabold leading-tight">Emergency Health Passport</h1>
                        <p class="text-sm font-bold text-emerald-100 mt-1 mb-0">Essential health information for emergency response</p>
                    </div>
                </div>
            </div>
            <div class="p-6 md:p-8">
                <?php if ($authError): ?>
                    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold mb-4"><?= e($authError) ?></div>
                <?php endif; ?>

                <?php if (!$viewer): ?>
                    <div class="rounded-2xl bg-amber-50 border border-amber-200 px-5 py-4 mb-5">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-amber-700" aria-hidden="true">shield_lock</span>
                            <div>
                                <p class="font-black text-amber-900 mb-1">Protected emergency passport</p>
                                <p class="text-sm font-bold text-amber-800 mb-0">Sign in with your active CLINiQ account to view this patient's approved emergency essentials.</p>
                            </div>
                        </div>
                    </div>
                    <form method="post" class="rounded-2xl bg-slate-50 border border-slate-200 p-5 mb-6">
                        <input type="hidden" name="action" value="passport_auth">
                        <p class="font-black text-slate-800 mb-1">View passport</p>
                        <p class="text-xs font-bold text-slate-500 mb-4">For privacy and accountability, enter your own account credentials. Access will be recorded in the audit log.</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <input class="clinic-input" name="student_number" required placeholder="Your ID number" autocomplete="username" data-id-number-format>
                            <input class="clinic-input" name="password" type="password" required placeholder="Password" autocomplete="current-password">
                        </div>
                        <button class="mt-4 px-5 py-3 bg-slate-800 text-white rounded-2xl text-sm font-black" type="submit">Unlock Emergency Passport</button>
                    </form>
                <?php else: ?>
                    <section aria-labelledby="passport-holder-heading" class="public-passport">
                        <div class="passport-preview-modern">
                            <div class="passport-modern-hero">
                                <div class="passport-modern-avatar">
                                    <?php $emergencyPhotoPath = profile_photo_normalize_path($patient['profile_photo_path'] ?? null); ?>
                                    <?php if ($emergencyPhotoPath !== null): ?>
                                        <img src="<?= e(app_url($emergencyPhotoPath)) ?>" alt="<?= e($passportHolderName ?: 'Patient') ?> profile picture">
                                    <?php else: ?>
                                        <span class="material-symbols-outlined" aria-hidden="true">person</span>
                                    <?php endif; ?>
                                </div>
                                <div class="passport-modern-identity">
                                    <div class="passport-modern-kicker-row">
                                        <span class="passport-modern-pill">Emergency Passport</span>
                                        <span class="passport-modern-status <?= $hasActiveIncident ? 'is-active' : '' ?>">
                                            <span class="material-symbols-outlined"><?= $hasActiveIncident ? 'warning' : 'check_circle' ?></span>
                                            <?= $hasActiveIncident ? 'Active Incident' : 'No Active Incident' ?>
                                        </span>
                                    </div>
                                    <h2 id="passport-holder-heading" class="passport-modern-name"><?= e($passportHolderName ?: 'Patient') ?></h2>
                                    <div class="passport-modern-meta">
                                        <span><?= e($patient['id_number'] ?: 'ID not recorded') ?></span>
                                        <span aria-hidden="true">&bull;</span>
                                        <span><?= e($patient['course_section'] ?: 'Program not recorded') ?></span>
                                    </div>
                                </div>
                                <div class="passport-modern-blood" role="img" aria-label="Blood type <?= e($patient['blood_type'] ?: 'unknown') ?>">
                                    <span>Blood type</span>
                                    <strong><?= e($patient['blood_type'] ?: '—') ?></strong>
                                </div>
                            </div>

                            <div class="passport-modern-content">
                                <div class="passport-modern-info-grid">
                                    <article class="passport-modern-info passport-modern-info-allergy">
                                        <div class="passport-modern-info-heading"><span class="material-symbols-outlined">allergy</span><span>Allergies</span></div>
                                        <div class="passport-modern-tags">
                                            <?php foreach (array_filter(array_map('trim', preg_split('/[,;\r\n]+/', (string) ($patient['allergies'] ?: 'None')) ?: [])) as $allergy): ?>
                                                <span class="passport-modern-tag"><?= e($allergy) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </article>

                                    <article class="passport-modern-info passport-modern-info-condition">
                                        <div class="passport-modern-info-heading"><span class="material-symbols-outlined">cardiology</span><span>Medical Conditions</span></div>
                                        <div class="passport-modern-value"><?= nl2br(e($patient['existing_conditions'] ?: 'None reported.')) ?></div>
                                    </article>

                                    <article class="passport-modern-info passport-modern-info-medication">
                                        <div class="passport-modern-info-heading"><span class="material-symbols-outlined">medication</span><span>Current Medications</span></div>
                                        <div class="passport-modern-value"><?= nl2br(e($patient['medications'] ?: 'No current medications recorded.')) ?></div>
                                    </article>

                                    <article class="passport-modern-info passport-modern-info-instructions">
                                        <div class="passport-modern-info-heading"><span class="material-symbols-outlined">emergency_home</span><span>Emergency Instructions</span></div>
                                        <div class="passport-modern-instructions"><?= nl2br(e($patient['emergency_instructions'] ?: 'Notify the clinic immediately.')) ?></div>
                                    </article>

                                    <?php if ($latestVitals): ?>
                                        <article class="passport-modern-info passport-modern-info-bmi">
                                            <div class="passport-modern-info-heading"><span class="material-symbols-outlined">monitor_weight</span><span>Body Measurements</span></div>
                                            <div class="passport-modern-metrics">
                                                <span><strong><?= e((string) ($latestVitals['patient_height_cm'] ?: '—')) ?></strong><small>Height (cm)</small></span>
                                                <span><strong><?= e((string) ($latestVitals['patient_weight_kg'] ?: '—')) ?></strong><small>Weight (kg)</small></span>
                                                <?php if ((int) ($patient['show_bmi_on_passport'] ?? 1) === 1): ?>
                                                    <span><strong><?= e((string) ($latestVitals['patient_bmi'] ?: '—')) ?></strong><small>BMI</small></span>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endif; ?>
                                </div>

                                <div class="passport-modern-contact">
                                    <div class="passport-modern-contact-icon" aria-hidden="true"><span class="material-symbols-outlined">phone_in_talk</span></div>
                                    <div class="passport-modern-contact-details">
                                        <span>Emergency Contact</span>
                                        <strong><?= e($patient['guardian_name'] ?: 'Not provided') ?></strong>
                                        <small><?= e($patient['guardian_relationship'] ?: 'Guardian') ?> &bull; <?= e($patient['guardian_contact'] ?: 'No phone number') ?></small>
                                    </div>
                                    <?php if (!empty($patient['guardian_contact'])): ?>
                                        <a class="passport-modern-call" href="tel:<?= e($patient['guardian_contact']) ?>"><span class="material-symbols-outlined">call</span>Call Now</a>
                                    <?php endif; ?>
                                </div>

                <?php if ($message): ?>
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-700 px-5 py-4 font-bold mb-4">
                        <?= e($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold mb-4">
                        <?= e($error) ?></div>
                <?php endif; ?>

                <button type="button" id="toggle-emergency-report" class="w-full px-5 py-4 bg-red-600 text-white rounded-2xl text-sm font-black shadow-lg hover:bg-red-700 flex items-center justify-center gap-2" aria-controls="emergency-report-panel" aria-expanded="<?= $reportFormOpen ? 'true' : 'false' ?>">
                    <span class="material-symbols-outlined" aria-hidden="true">emergency</span>
                    Report an Emergency
                </button>
                <p class="text-xs font-bold text-slate-500 text-center mt-3 mb-0">This sends an alert to the clinic response queue.</p>

                <section id="emergency-report-panel" class="mt-6 border-t border-slate-200 pt-6" <?= $reportFormOpen ? '' : 'hidden' ?>>
                    <div class="rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 px-5 py-4 mb-5">
                        <p class="font-black mb-1">Emergency reporting</p>
                        <p class="text-sm font-bold mb-0">Stay with the patient, notify the clinic, and provide the current location. Do not rely on this page alone for urgent care.</p>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <input type="hidden" name="action" value="incident_report">
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
                </section>
                                <div class="passport-modern-updated">
                                    <span class="material-symbols-outlined">schedule</span>
                                    Last updated: <strong><?= e($passportUpdatedAt) ?></strong>
                                </div>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var button = document.getElementById('toggle-emergency-report');
    var panel = document.getElementById('emergency-report-panel');
    if (!button || !panel) return;

    button.addEventListener('click', function () {
        var willOpen = panel.hidden;
        panel.hidden = !willOpen;
        button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
})();
</script>
<?php render_footer(); ?>
