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
    WHERE pt.emergency_token = ? AND pt.token_enabled = 1 AND pt.access_status = 'Official'
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

            $message = $classification['level'] === 'Not assessed'
                ? ($reporterRiskRating
                    ? 'The clinic has been notified. The report was forwarded without risk-metric answers and marked ' . $reporterRiskRating . ' by the reporter for clinic triage.'
                    : 'The clinic has been notified. This report was forwarded without risk-metric answers, so clinic staff will assess urgency promptly.')
                : 'The clinic has been notified. Please stay with the student and call the clinic directly if the situation is urgent.';
            audit_log_event('incident', 'incident_report_submitted', $viewerPersonId ?: null, $viewerPersonId ? 'student' : 'guest', 'patient', (int) $patient['id'], ['location' => $location, 'risk_level' => $classification['level'], 'reporter_risk_rating' => $reporterRiskRating]);
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
          AND (patient_height_cm IS NOT NULL OR patient_weight_kg IS NOT NULL OR patient_bmi IS NOT NULL)
        ORDER BY COALESCE(exam_date, created_at) DESC, ape_id DESC
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
$GLOBALS['cliniq_page_camera_allowed'] = true;

render_header('Emergency Health Passport');
?>
<link rel="stylesheet" href="<?= app_url('assets/css/emergency-passport.css?v=' . filemtime(__DIR__ . '/assets/css/emergency-passport.css') . '&layout=2') ?>">
<div class="passport-page">
    <?php if (!$patient): ?>
        <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold">Emergency tag not found or
            disabled.</div>
    <?php else: ?>
        <?php if (!$viewer): ?>
        <div class="w-full max-w-3xl overflow-hidden">
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
        <?php endif; ?>
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
                                    <div class="passport-modern-kicker-row"><span class="passport-modern-pill">Emergency Passport</span></div>
                                    <h2 id="passport-holder-heading" class="passport-modern-name"><?= e($passportHolderName ?: 'Patient') ?></h2>
                                    <div class="passport-modern-meta">
                                        <span><?= e($patient['id_number'] ?: 'ID not recorded') ?></span>
                                    </div>
                                </div>
                                <span class="passport-modern-status <?= $hasActiveIncident ? 'is-active' : '' ?>"><span class="material-symbols-outlined"><?= $hasActiveIncident ? 'warning' : 'check_circle' ?></span><?= $hasActiveIncident ? 'Active Incident' : 'No Active Incident' ?></span>
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

                                    <?php if ($latestVitals && (int) ($patient['show_bmi_on_passport'] ?? 1) === 1): ?>
                                        <article class="passport-modern-info passport-modern-info-bmi">
                                            <div class="passport-modern-info-heading"><span class="material-symbols-outlined">monitor_weight</span><span>Body Measurements</span></div>
                                            <div class="passport-modern-metrics">
                                                <span><strong><?= e((string) ($latestVitals['patient_height_cm'] ?: '—')) ?></strong><small>Height (cm)</small></span>
                                                <span><strong><?= e((string) ($latestVitals['patient_weight_kg'] ?: '—')) ?></strong><small>Weight (kg)</small></span>
                                                <span><strong><?= e((string) ($latestVitals['patient_bmi'] ?: '—')) ?></strong><small>BMI</small></span>
                                            </div>
                                        </article>
                                    <?php endif; ?>
                                </div>

                                <div class="rounded-2xl bg-slate-50 border border-slate-200 text-slate-600 px-5 py-4 text-sm mb-5">
                                    <strong class="text-slate-800">Emergency privacy notice.</strong>
                                    This page displays only the emergency information selected for this passport. Access is logged. Use the information only to help the identified person; do not copy, publish, or use it for another purpose. See the <a href="legal/privacy-notice.php" target="_blank" rel="noopener" class="underline font-bold">CLINiQ Privacy Notice</a>.
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
                                <div class="passport-modern-updated"><span class="material-symbols-outlined">schedule</span> Last updated: <strong><?= e($passportUpdatedAt) ?></strong></div>
                            </div>
                        </div>
                    <div class="passport-emergency-action">
                <?php if ($message): ?>
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-700 px-5 py-4 font-bold mb-4">
                        <?= e($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold mb-4">
                        <?= e($error) ?></div>
                <?php endif; ?>

                <section id="emergency-report-panel" class="mt-6 border-t border-slate-200 pt-6" <?= $reportFormOpen ? '' : 'hidden' ?>>
                    <div class="rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 px-5 py-4 mb-5">
                        <p class="font-black mb-1">Emergency reporting</p>
                        <p class="text-sm font-bold mb-0">Stay with the patient, notify the clinic, and provide the current location. Do not rely on this page alone for urgent care.</p>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="emergency-report-form" id="emergency-report-form">
                    <input type="hidden" name="action" value="incident_report">
                    <section class="emergency-report-section">
                        <div class="emergency-report-section-heading">
                            <span class="material-symbols-outlined" aria-hidden="true">person_pin_circle</span>
                            <div><h2>Reporter details</h2><p>Tell the clinic where help is needed and how to contact you.</p></div>
                        </div>
                        <div class="emergency-report-grid">
                    <div class="emergency-report-field emergency-report-field-wide">
                        <label class="clinic-label">Reported Location</label>
                        <input class="clinic-input" name="location" required
                            placeholder="Example: Gymnasium, Room 204, gate area">
                    </div>
                    <div class="emergency-report-field">
                        <label class="clinic-label">Reporter Name</label>
                        <input class="clinic-input" name="reporter_name" placeholder="Optional for responders" <?= $viewer ? 'value="' . e($viewer['name']) . '" readonly' : '' ?>>
                    </div>
                    <div class="emergency-report-field">
                        <label class="clinic-label">Reporter Contact</label>
                        <input class="clinic-input" name="reporter_contact" placeholder="Optional phone number">
                    </div>
                        </div>
                    </section>

                    <fieldset class="emergency-report-section emergency-risk-check">
                        <legend><span class="material-symbols-outlined" aria-hidden="true">monitor_heart</span> Risk check <small>Optional</small></legend>
                        <p class="emergency-report-section-copy">Answer only what you can observe. You can still forward the report without these answers.</p>
                        <div class="emergency-report-grid">
                    <div class="emergency-report-field">
                        <label class="clinic-label">Incident Type</label>
                        <select class="clinic-input" name="incident_type">
                            <option value="">Select the closest type</option>
                            <?php foreach (incident_type_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="emergency-report-field">
                        <label class="clinic-label">Student Condition</label>
                        <select class="clinic-input" name="observed_condition">
                            <option value="">Select condition</option>
                            <?php foreach (incident_condition_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="emergency-report-field">
                        <label class="clinic-label">Breathing</label>
                        <select class="clinic-input" name="breathing_status">
                            <option value="">Select breathing status</option>
                            <?php foreach (incident_breathing_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="emergency-report-field">
                        <label class="clinic-label">Bleeding</label>
                        <select class="clinic-input" name="bleeding_status">
                            <option value="">Select bleeding status</option>
                            <?php foreach (incident_bleeding_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="emergency-report-field">
                        <label class="clinic-label">Pain Level</label>
                        <select class="clinic-input" name="pain_level">
                            <option value="">Select pain level</option>
                            <?php foreach (incident_pain_level_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="emergency-report-field">
                        <label class="clinic-label">Mobility</label>
                        <select class="clinic-input" name="mobility_status">
                            <option value="">Select mobility</option>
                            <?php foreach (incident_mobility_options() as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="emergency-report-field">
                        <label class="clinic-label">Reported Urgency <small>Optional</small></label>
                        <select class="clinic-input" name="reporter_risk_rating">
                            <option value="">Not assessed</option>
                            <?php foreach (['Low', 'Moderate', 'High', 'Critical'] as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                        </div>
                    </fieldset>

                    <section class="emergency-report-section">
                        <div class="emergency-report-section-heading">
                            <span class="material-symbols-outlined" aria-hidden="true">attachment</span>
                            <div><h2>Supporting details</h2><p>Add context or a photo if it will help the clinic respond.</p></div>
                        </div>
                    <div class="emergency-report-field">
                        <label class="clinic-label">Notes</label>
                        <textarea class="clinic-textarea" name="notes" rows="4"
                            placeholder="What happened? What does the student need?"></textarea>
                    </div>
                    <div class="emergency-report-field emergency-photo-evidence">
                        <h3 class="emergency-photo-evidence-title">Photo evidence</h3>
                        <input id="alert-photo" class="clinic-input emergency-photo-input" name="photo" type="file" accept="image/png,image/jpeg,image/webp" aria-hidden="true" tabindex="-1">
                        <div class="emergency-photo-stage">
                            <div id="alert-photo-empty" class="emergency-photo-empty" aria-hidden="true">
                                <span class="material-symbols-outlined">image</span>
                            </div>
                            <div id="alert-camera-panel" class="emergency-camera-panel" hidden>
                                <video id="alert-camera-preview" playsinline autoplay muted aria-label="Live camera preview"></video>
                                <canvas id="alert-camera-canvas" hidden></canvas>
                            </div>
                            <div id="alert-photo-result" class="emergency-photo-result" hidden>
                                <img id="alert-photo-thumbnail" alt="Captured photo preview">
                                <div class="emergency-photo-meta"><strong id="alert-photo-name">alert-photo.jpg</strong><span id="alert-photo-time"></span></div>
                            </div>
                        </div>
                        <div class="emergency-photo-evidence-actions">
                            <span id="alert-photo-status" class="emergency-photo-status">Ready to capture</span>
                            <div class="emergency-photo-action-buttons">
                            <button type="button" id="open-alert-camera" class="emergency-camera-launch">
                                <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                                <span id="alert-camera-action-label">Take live photo</span>
                            </button>
                            <button type="button" id="close-alert-camera" class="emergency-camera-button emergency-camera-button-secondary" hidden>Cancel</button>
                            </div>
                        </div>
                        <p class="text-xs font-bold text-slate-400 mt-2 mb-0">Optional. Take one live photo; it will appear in the nurse alert report.</p>
                    </div>
                    </section>
                    </form>

                    <p class="text-xs font-bold text-slate-500 mt-6 mb-0">
                        If this is urgent, call the clinic or school emergency contact immediately after submitting the report.
                    </p>
                </section>
                    </div>
                    </section>
                <div class="emergency-action-dock" aria-label="Emergency report actions" style="position:fixed;z-index:9999;right:1rem;bottom:1rem;left:1rem;display:grid;grid-template-columns:0.8fr 1.2fr;gap:.65rem;width:min(42rem,calc(100vw - 2rem));margin:0 auto;padding:.65rem;border:1px solid #d8e9dd;border-radius:1rem;background:rgba(255,255,255,.96);box-shadow:0 12px 32px rgba(23,38,29,.22);backdrop-filter:blur(10px);">
                    <button type="button" id="toggle-emergency-report" class="emergency-dock-button emergency-dock-button-secondary" style="min-height:3rem;padding:.7rem;border:0;border-radius:.7rem;color:#7f1d1d;background:#fff1f2;font:800 .78rem inherit;cursor:pointer;" aria-controls="emergency-report-panel" aria-expanded="<?= $reportFormOpen ? 'true' : 'false' ?>">
                        Emergency
                    </button>
                    <button type="submit" form="emergency-report-form" id="submit-emergency-report" class="emergency-dock-button" style="min-height:3rem;padding:.7rem;border:0;border-radius:.7rem;color:#fff;background:#dc2626;font:800 .78rem inherit;cursor:pointer;" data-confirm-submit data-confirm-type="danger" data-confirm-title="Submit this emergency report?" data-confirm-message="This will send the possible accident report to the clinic response queue." data-confirm-toast="Submitting emergency report..." <?= $reportFormOpen ? '' : 'disabled' ?>>
                        Report to Clinic
                    </button>
                </div>
                <?php endif; ?>
        <?php if (!$viewer): ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script>
(function () {
    var button = document.getElementById('toggle-emergency-report');
    var panel = document.getElementById('emergency-report-panel');
    var submitButton = document.getElementById('submit-emergency-report');
    if (!button || !panel) return;

    button.addEventListener('click', function () {
        var willOpen = panel.hidden;
        panel.hidden = !willOpen;
        button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (submitButton) submitButton.disabled = !willOpen;
        if (willOpen) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
})();

(function () {
    var input = document.getElementById('alert-photo');
    var openButton = document.getElementById('open-alert-camera');
    var panel = document.getElementById('alert-camera-panel');
    var video = document.getElementById('alert-camera-preview');
    var canvas = document.getElementById('alert-camera-canvas');
    var closeButton = document.getElementById('close-alert-camera');
    var emptyState = document.getElementById('alert-photo-empty');
    var result = document.getElementById('alert-photo-result');
    var thumbnail = document.getElementById('alert-photo-thumbnail');
    var photoName = document.getElementById('alert-photo-name');
    var photoTime = document.getElementById('alert-photo-time');
    var photoStatus = document.getElementById('alert-photo-status');
    var actionLabel = document.getElementById('alert-camera-action-label');
    var stream = null;
    var hasCapturedPhoto = false;

    if (!input || !openButton || !panel || !video || !canvas || !closeButton || !emptyState || !result || !thumbnail || !photoName || !photoTime || !photoStatus || !actionLabel) return;

    function setCameraAction(action) {
        var labels = {
            capture: 'Take Photo',
            retake: 'Retake Photo',
            launch: 'Take live photo'
        };
        actionLabel.textContent = labels[action] || labels.launch;
        openButton.setAttribute('aria-label', actionLabel.textContent);
    }

    function setPhotoStatus(message) {
        photoStatus.textContent = message;
    }

    function stopCamera() {
        if (stream) stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
        panel.hidden = true;
        closeButton.hidden = true;
        emptyState.hidden = hasCapturedPhoto;
        setPhotoStatus(hasCapturedPhoto ? 'Photo ready' : 'Ready to capture');
        setCameraAction(hasCapturedPhoto ? 'retake' : 'launch');
    }

    function showSelectedPhoto(file) {
        if (!file) return;
        thumbnail.src = URL.createObjectURL(file);
        photoName.textContent = file.name || 'alert-photo.jpg';
        photoTime.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        result.hidden = false;
        emptyState.hidden = true;
        hasCapturedPhoto = true;
        openButton.hidden = false;
    }

    async function openCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            video.srcObject = stream;
            emptyState.hidden = true;
            panel.hidden = false;
            openButton.hidden = false;
            closeButton.hidden = false;
            setPhotoStatus('Camera active');
            setCameraAction('capture');
        } catch (error) { stopCamera(); }
    }

    function capturePhoto() {
        var maxDimension = 1600;
        var scale = Math.min(1, maxDimension / Math.max(video.videoWidth, video.videoHeight));
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) {
            if (!blob) return;
            var photo = new File([blob], 'alert-photo.jpg', { type: 'image/jpeg' });
            var files = new DataTransfer();
            files.items.add(photo);
            input.files = files.files;
            showSelectedPhoto(photo);
            stopCamera();
        }, 'image/jpeg', 0.85);
    }

    openButton.addEventListener('click', function () {
        if (stream) capturePhoto();
        else if (hasCapturedPhoto) {
            input.value = '';
            thumbnail.removeAttribute('src');
            result.hidden = true;
            hasCapturedPhoto = false;
            setPhotoStatus('Ready to capture');
            openCamera();
        } else openCamera();
    });
    closeButton.addEventListener('click', stopCamera);
    window.addEventListener('pagehide', stopCamera);
})();

</script>
<?php render_footer(); ?>
