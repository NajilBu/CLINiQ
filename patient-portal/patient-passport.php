<?php
require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/AlertWorkflow.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../app/helpers/emergency_contact.php';
require_once __DIR__ . '/../app/services/AuditLog.php';

ensure_alert_workflow_schema();
ensure_ape_workflow_schema();
$profile = student_require_official_access('Health Passport');
$patientId = (int) $profile['patient_id'];

$latestBmiRecord = null;
if ($patientId > 0) {
    $latestBmiStmt = auth_db()->prepare("
        SELECT patient_height_cm, patient_weight_kg, patient_bmi, COALESCE(exam_date, created_at) AS bmi_recorded_at
        FROM ape_records
        WHERE patient_id = ?
          AND (patient_height_cm IS NOT NULL OR patient_weight_kg IS NOT NULL OR patient_bmi IS NOT NULL)
        ORDER BY COALESCE(exam_date, created_at) DESC, ape_id DESC
        LIMIT 1
    ");
    $latestBmiStmt->execute([$patientId]);
    $latestBmiRecord = $latestBmiStmt->fetch() ?: null;
}

$passport = [
    'name'            => $profile['name'],
    'student_id'      => $profile['student_id'],
    'dob'             => $profile['birthdate'] ? date('F j, Y', strtotime($profile['birthdate'])) : 'Not recorded',
    'sex'             => $profile['sex'] ?: 'Not specified',
    'blood_type'      => $profile['blood_type'] ?: 'Unknown',
    'allergies'       => $profile['allergies'] ?: 'None',
    'conditions'      => $profile['existing_conditions'] ?: 'None reported.',
    'medications'     => $profile['medications'] ?: 'No current medications recorded.',
    'instructions'    => $profile['emergency_instructions'] ?: "If unconscious, place in recovery position and notify the clinic immediately.",
    'guardian_name'   => $profile['guardian_name'] ?: '',
    'relationship'    => $profile['guardian_relationship'] ?: 'Guardian',
    'primary_contact' => $profile['guardian_contact'] ?: '',
    'secondary_contact' => $profile['secondary_contact'] ?: '',
    'last_updated'    => $profile['updated_at'] ? date('F j, Y', strtotime($profile['updated_at'])) : date('F j, Y'),
    'token'           => $profile['emergency_token'] ?: 'not-generated',
    'height_cm'       => $latestBmiRecord['patient_height_cm'] ?? null,
    'weight_kg'       => $latestBmiRecord['patient_weight_kg'] ?? null,
    'bmi'             => $latestBmiRecord['patient_bmi'] ?? null,
    'show_bmi'        => (int) ($profile['show_bmi_on_passport'] ?? 1) === 1,
    'bmi_recorded_at' => $latestBmiRecord['bmi_recorded_at'] ?? null,
];
$passportUrl = '../public/emergency.php?token=' . urlencode($passport['token']);
$passportPreviewUrl = $passportUrl;

$saved = false;
$passportErrorGroup = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($patientId <= 0) {
        $passportError = 'A clinical patient record is required before health-passport information can be saved.';
    } else {
    try {
    $passport['allergies']        = cliniq_normalize_free_text($_POST['allergies'] ?? $passport['allergies']);
    $passport['instructions']     = cliniq_normalize_free_text($_POST['instructions'] ?? $passport['instructions']);
    $passport['show_bmi']         = isset($_POST['show_bmi_on_passport']);
    $passport['guardian_name'] = cliniq_normalize_person_name($_POST['guardian_name'] ?? '');
    $passport['relationship'] = trim((string) ($_POST['relationship'] ?? ''));
    $passport['primary_contact'] = trim((string) ($_POST['primary_contact'] ?? ''));
    $passport['secondary_contact'] = trim((string) ($_POST['secondary_contact'] ?? ''));
    $contact = cliniq_validate_emergency_contact($passport, dropdown_options('guardian_relationship'));
    $passport['guardian_name'] = $contact['guardian_name'];
    $passport['relationship'] = $contact['relationship'];
    $passport['primary_contact'] = $contact['primary_contact'];
    $passport['secondary_contact'] = $contact['secondary_contact'] ?? '';

        $stmt = auth_db()->prepare("
            UPDATE patients
            SET allergies = ?, emergency_instructions = ?,
                guardian_or_contact_name = ?, guardian_or_contact_number = ?,
                guardian_relationship = ?, secondary_contact_number = ?, show_bmi_on_passport = ?
            WHERE person_id = ?
        ");
        $stmt->execute([
            $passport['allergies'],
            $passport['instructions'],
            $passport['guardian_name'],
            $passport['primary_contact'],
            $passport['relationship'],
            $passport['secondary_contact'] !== '' ? $passport['secondary_contact'] : null,
            $passport['show_bmi'] ? 1 : 0,
            $patientId,
        ]);
        $saved = true;
        audit_log_event('passport', 'passport_profile_updated', $patientId, 'student', 'patient', $patientId, ['fields' => ['allergies', 'instructions', 'emergency_contacts', 'show_bmi_on_passport']]);
        $passport['last_updated'] = date('F j, Y');
        student_start_session();
        $_SESSION['student_flash_success'] = 'Passport settings saved. Your Emergency Health Passport has been updated.';
        header('Location: patient-passport.php');
        exit;
    } catch (InvalidArgumentException $e) {
        $saved = false;
        $passportError = $e->getMessage();
        $passportErrorGroup = 'emergency';
    } catch (Throwable $e) {
        $saved = false;
        $passportError = 'The passport settings could not be saved. Please try again.';
        error_log('[CLINiQ Passport] Save failed: ' . $e->getMessage());
    }
    }
}

render_student_header('Emergency Health Passport', 'passport');
?>

<section class="student-page-header passport-page-header">
    <div>
        <h1 class="student-title">Health Passport</h1>
        <p class="student-subtitle">Review the information available for emergency access.</p>
    </div>
</section>

<?php if (!empty($passportError)): ?>
<div class="student-note student-note-warning passport-save-error mb-4" role="alert">
    <span class="material-symbols-outlined">warning</span>
    <div>
        <strong>Passport settings were not saved.</strong>
        <span><?= student_e($passportError) ?></span>
        <?php if ($passportErrorGroup === 'emergency'): ?>
            <a class="passport-error-action" href="#passport-emergency-contact-panel" data-passport-open-group="emergency">Complete emergency contact</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($saved): ?>
<div class="student-note student-note-success student-toast" data-student-toast role="status" aria-live="polite">
    <span class="material-symbols-outlined">check_circle</span>
    <div><strong>Passport settings saved.</strong> Your Emergency Health Passport has been updated.</div>
    <button type="button" class="student-toast-dismiss" aria-label="Dismiss saved confirmation">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
    </button>
</div>
<?php endif; ?>

<div class="passport-summary-row">
    <p class="passport-privacy-summary"><span class="material-symbols-outlined" aria-hidden="true">privacy_tip</span><span>Review the details you choose to share in an emergency. <a href="<?= student_e(student_legal_url('privacy')) ?>" target="_blank" rel="noopener" class="student-auth-link">Privacy Notice</a></span></p>
</div>

<form method="POST" action="" id="passport-form" data-emergency-contact-form data-passport-error-group="<?= student_e((string) $passportErrorGroup) ?>">
<nav class="passport-mobile-tabs" aria-label="Passport sections">
    <button type="button" class="is-active" data-passport-tab="profile">Profile</button>
    <button type="button" data-passport-tab="emergency">Emergency</button>
    <button type="button" data-passport-tab="access">Access</button>
</nav>
<p id="passport-mobile-save-hint" class="passport-mobile-save-hint" hidden role="alert">Complete the required Emergency Contact fields before saving your passport settings.</p>
<div class="student-grid passport-layout">

    <!-- ── Left column: Settings ── -->
    <div class="student-span-7 grid gap-4 passport-settings-stack">

        <!-- Personal Information (read-only + editable blood type) -->
        <details class="patient-mobile-panel" data-mobile-accordion data-passport-group="profile">
        <summary>Personal information</summary>
        <section class="student-card">
            <div class="student-card-header">
                <div>
                    <h2 class="student-card-title">Personal Information</h2>
                    <p class="student-card-copy">Basic identity fields pulled from your student record</p>
                </div>
                <span class="student-badge student-badge-info">
                        <span class="material-symbols-outlined passport-icon-xs">lock</span>
                    Mostly Read-only
                </span>
            </div>
            <div class="student-card-pad">
                <div class="student-grid passport-grid-tight passport-personal-grid">
                    <div class="student-span-6 student-field passport-field-compact">
                        <label class="student-label">Full Name</label>
                        <div class="passport-readonly-field"><?= student_e($passport['name']) ?></div>
                    </div>
                    <div class="student-span-6 student-field passport-field-compact">
                        <label class="student-label">ID Number</label>
                        <div class="passport-readonly-field"><?= student_e($passport['student_id']) ?></div>
                    </div>
                    <div class="student-span-6 student-field passport-field-compact">
                        <label class="student-label">Date of Birth</label>
                        <div class="passport-readonly-field"><?= student_e($passport['dob']) ?></div>
                    </div>
                    <div class="student-span-6 student-field passport-field-compact">
                        <label class="student-label">Sex</label>
                        <div class="passport-readonly-field"><?= student_e($passport['sex']) ?></div>
                    </div>
                    <div class="student-span-12 student-field passport-field-compact">
                        <label class="student-label">Blood Type <span class="passport-readonly-tag">Clinic-managed</span></label>
                        <div class="passport-readonly-field"><?= student_e($passport['blood_type'] ?: 'Not recorded') ?></div>
                        <p class="passport-hint">Updated only by authorized clinic staff during APE.</p>
                    </div>
                </div>
            </div>
        </section>
        </details>

        <!-- BMI -->
        <details class="patient-mobile-panel" data-mobile-accordion data-passport-group="profile">
        <summary>BMI</summary>
        <section class="student-card">
            <div class="student-card-header">
                <div>
                    <h2 class="student-card-title">BMI</h2>
                    <p class="student-card-copy">Latest height, weight, and BMI confirmed from your APE record</p>
                </div>
                <span class="student-badge student-badge-info">
                        <span class="material-symbols-outlined passport-icon-xs">monitor_heart</span>
                    BMI
                </span>
            </div>
            <div class="student-card-pad">
                <div class="student-grid passport-grid-tight passport-bmi-grid">
                    <div class="student-span-4 student-field passport-field-compact">
                        <label class="student-label">Height</label>
                        <div class="passport-readonly-field"><?= $passport['height_cm'] !== null ? student_e(number_format((float) $passport['height_cm'], 2)) . ' cm' : 'Not recorded from APE yet' ?></div>
                    </div>
                    <div class="student-span-4 student-field passport-field-compact">
                        <label class="student-label">Weight</label>
                        <div class="passport-readonly-field"><?= $passport['weight_kg'] !== null ? student_e(number_format((float) $passport['weight_kg'], 2)) . ' kg' : 'Not recorded from APE yet' ?></div>
                    </div>
                    <div class="student-span-4 student-field passport-field-compact">
                        <label class="student-label">BMI</label>
                        <div class="passport-readonly-field"><?= $passport['bmi'] !== null ? student_e(number_format((float) $passport['bmi'], 2)) : 'Not recorded from APE yet' ?></div>
                    </div>
                    <div class="student-span-12 student-field passport-field-compact">
                        <label class="passport-visibility-toggle" for="show_bmi_on_passport">
                            <input type="checkbox" id="show_bmi_on_passport" name="show_bmi_on_passport" value="1" role="switch" <?= $passport['show_bmi'] ? 'checked' : '' ?>>
                            <span class="passport-visibility-track" aria-hidden="true"><span></span></span>
                            <span class="passport-visibility-copy">
                                <strong>Show Body Measurements on Emergency Passport</strong>
                                <small>Turn this off to hide your height, weight, and BMI from the QR/NFC passport.</small>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </section>
        </details>

        <!-- Emergency Information -->
        <details id="passport-emergency-contact-panel" class="patient-mobile-panel" data-mobile-accordion data-passport-group="emergency">
        <summary>Emergency information</summary>
        <section class="student-card">
            <div class="student-card-header">
                <div>
                    <h2 class="student-card-title">Emergency Information</h2>
                    <p class="student-card-copy">Shown to responders when your QR or NFC is scanned</p>
                </div>
                <span class="student-badge passport-badge-emergency-soft">
                        <span class="material-symbols-outlined passport-icon-xs">edit</span>
                    Editable
                </span>
            </div>
            <div class="student-card-pad grid gap-0 passport-emergency-fields">

                <div class="student-field">
                    <label class="student-label" for="allergies">
                        <span class="passport-dot passport-dot-red"></span>
                        Allergies
                    </label>
                    <input
                        id="allergies"
                        name="allergies"
                        type="text"
                        class="student-input"
                        value="<?= student_e($passport['allergies']) ?>"
                        placeholder="e.g. Penicillin, Shellfish, Dust (comma-separated)"
                    >
                    <p class="passport-hint">Separate multiple allergies with commas.</p>
                </div>

                <div class="student-field">
                    <label class="student-label">
                        <span class="passport-dot passport-dot-amber"></span>
                        Existing Medical Conditions
                    </label>
                    <div class="passport-readonly-field whitespace-pre-wrap"><?= student_e($passport['conditions'] ?: 'None recorded') ?></div>
                    <p class="passport-hint">Doctor-confirmed and maintained by clinic staff during APE.</p>
                </div>

                <div class="student-field">
                    <label class="student-label">
                        <span class="passport-dot passport-dot-green"></span>
                        Current Medications
                    </label>
                    <div class="passport-readonly-field whitespace-pre-wrap"><?= student_e($passport['medications'] ?: 'None recorded') ?></div>
                    <p class="passport-hint">Current medications recorded by clinic staff during APE.</p>
                </div>

                <div class="student-field passport-field-compact">
                    <label class="student-label" for="instructions">
                        <span class="passport-dot passport-dot-blue"></span>
                        Emergency Instructions
                    </label>
                    <textarea
                        id="instructions"
                        name="instructions"
                        class="student-textarea passport-textarea-lg"
                        placeholder="e.g. Do NOT give penicillin. Inhaler is in the bag. Call guardian if unconscious."
                    ><?= student_e($passport['instructions']) ?></textarea>
                    <p class="passport-hint">Keep this concise. Responders need to read it fast.</p>
                </div>
            </div>
        </section>
        </details>

        <!-- Emergency Contacts -->
        <details class="patient-mobile-panel" data-mobile-accordion data-passport-group="emergency">
        <summary>Emergency contact</summary>
        <section class="student-card">
            <div class="student-card-header">
                <div>
                    <h2 class="student-card-title">Emergency Contact</h2>
                    <p class="student-card-copy">Guardian or next-of-kin shown on your passport</p>
                </div>
                <span class="student-badge student-badge-info">
                    <span class="material-symbols-outlined passport-icon-xs">contacts</span>
                    Guardian
                </span>
            </div>
            <div class="student-card-pad">
                <div class="student-grid passport-grid-tight">
                    <div class="student-span-6 student-field passport-field-compact">
                        <label class="student-label" for="guardian_name">Guardian Name</label>
                        <input
                            id="guardian_name"
                            name="guardian_name"
                            type="text"
                            class="student-input"
                            value="<?= student_e($passport['guardian_name']) ?>"
                            placeholder="Full name of guardian or next of kin"
                            minlength="2"
                            maxlength="100"
                            autocomplete="name"
                            required
                        >
                        <p class="passport-contact-error" data-contact-error="guardian_name" hidden></p>
                    </div>
                    <div class="student-span-6 student-field passport-field-compact">
                        <label class="student-label" for="relationship">Relationship</label>
                        <select id="relationship" name="relationship" class="student-select" required>
                            <?php
                            $rels = dropdown_options('guardian_relationship');
                            foreach ($rels as $r):
                            ?>
                                <option value="<?= student_e($r) ?>" <?= $passport['relationship'] === $r ? 'selected' : '' ?>><?= student_e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="passport-contact-error" data-contact-error="relationship" hidden></p>
                    </div>
                    <div class="student-span-6 student-field passport-field-compact">
                        <label class="student-label" for="primary_contact">Primary Contact Number</label>
                        <input
                            id="primary_contact"
                            name="primary_contact"
                            type="tel"
                            class="student-input"
                            value="<?= student_e($passport['primary_contact']) ?>"
                            placeholder="+63 9XX XXX XXXX"
                            inputmode="tel"
                            autocomplete="tel"
                            maxlength="17"
                            required
                        >
                        <p class="passport-contact-error" data-contact-error="primary_contact" hidden></p>
                    </div>
                    <div class="student-span-6 student-field passport-field-compact">
                        <label class="student-label" for="secondary_contact">Secondary Contact Number</label>
                        <input
                            id="secondary_contact"
                            name="secondary_contact"
                            type="tel"
                            class="student-input"
                            value="<?= student_e($passport['secondary_contact']) ?>"
                            placeholder="+63 9XX XXX XXXX (optional)"
                            inputmode="tel"
                            autocomplete="tel"
                            maxlength="17"
                        >
                        <p class="passport-contact-error" data-contact-error="secondary_contact" hidden></p>
                    </div>
                </div>
            </div>
        </section>
        </details>

        <!-- Save button -->
        <div class="passport-action-row">
            <button type="submit" class="student-button passport-save-button">
                <span class="material-symbols-outlined">save</span>
                <span class="passport-action-label-long">Save Passport Settings</span>
                <span class="passport-action-label-short">Save Settings</span>
            </button>
            <a href="<?= student_e($passportPreviewUrl) ?>" target="_blank" class="student-button-secondary passport-preview-button text-decoration-none">
                <span class="material-symbols-outlined">open_in_new</span>
                <span class="passport-action-label-long">View Live Passport</span>
                <span class="passport-action-label-short">View Passport</span>
            </a>
        </div>

    </div>

    <!-- ── Right column: QR/NFC Preview ── -->
    <div class="student-span-5 grid gap-4">

        <details class="passport-mobile-panel patient-mobile-panel" data-mobile-accordion data-passport-group="access" open>
        <summary>QR / NFC access</summary>
        <!-- QR Code card -->
        <section class="student-card">
            <div class="student-card-header">
                <div>
                    <h2 class="student-card-title">QR / NFC Access</h2>
                    <p class="student-card-copy">Share this code with your ID or phone</p>
                </div>
                <span class="student-badge student-badge-success">Active</span>
            </div>
            <div class="student-card-pad passport-qr-card-body">
                <div
                    class="passport-qr-wrap"
                    id="qr-container"
                    data-passport-url="<?= student_e($passportUrl) ?>"
                    data-download-name="<?= student_e($passport['student_id']) ?>-emergency-passport-qr.png"
                    aria-label="QR code for <?= student_e($passport['name']) ?>'s Emergency Health Passport"
                >
                    <span class="passport-qr-loading">Generating QR code&hellip;</span>
                </div>
                <p class="passport-qr-label">Scan to view Emergency Passport</p>
                <div class="flex gap-2 mt-3">
                    <button type="button" class="student-button-secondary passport-qr-action" id="download-passport-qr" disabled>
                        <span class="material-symbols-outlined">download</span>
                        Download QR
                    </button>
                    <button type="button" class="student-button-secondary passport-qr-action" id="write-passport-nfc" aria-describedby="passport-nfc-status">
                        <span class="material-symbols-outlined">nfc</span>
                        Write NFC
                    </button>
                </div>
                <p id="passport-nfc-status" class="student-card-copy mt-3" role="status" aria-live="polite" hidden></p>
                    <div class="passport-token-chip mt-3">
                        <span class="material-symbols-outlined passport-icon-key">key</span>
                        <span>Token:</span> <code><?= student_e($passport['token']) ?></code><button type="button" class="passport-token-copy" data-passport-token="<?= student_e($passport['token']) ?>" aria-label="Copy passport token"><span class="material-symbols-outlined">content_copy</span></button>
                </div>
            </div>
        </section>
        </details>

        <!-- Live Passport Preview -->
        <details class="passport-mobile-panel patient-mobile-panel" data-mobile-accordion data-passport-group="access">
        <summary>Passport preview</summary>
        <section class="student-card" id="passport-preview-card">
            <div class="student-card-header">
                <div>
                    <h2 class="student-card-title">Passport Preview</h2>
                    <p class="student-card-copy">What emergency responders will see</p>
                </div>
                <span class="student-badge passport-badge-emergency-soft">
                    <span class="material-symbols-outlined passport-icon-xs">visibility</span>
                    Live
                </span>
            </div>

            <div class="passport-preview passport-preview-modern">
                <div class="passport-modern-hero">
                    <div class="passport-modern-avatar">
                        <?php $passportPhotoPath = profile_photo_normalize_path($profile['profile_photo_path'] ?? null); ?>
                        <?php if ($passportPhotoPath !== null): ?>
                            <img src="<?= student_e('../public/' . $passportPhotoPath) ?>" alt="<?= student_e($passport['name']) ?> profile picture">
                        <?php else: ?>
                            <span class="material-symbols-outlined" aria-hidden="true">person</span>
                        <?php endif; ?>
                    </div>
                    <div class="passport-modern-identity">
                        <div class="passport-modern-kicker-row">
                            <span class="passport-modern-pill">Emergency Passport</span>
                        </div>
                        <div class="passport-modern-name" id="prev-name"><?= student_e($passport['name']) ?></div>
                        <div class="passport-modern-meta">
                            <span id="prev-sid"><?= student_e($passport['student_id']) ?></span>
                        </div>
                    </div>
                    <span class="passport-modern-status"><span class="material-symbols-outlined">check_circle</span> No Active Incident</span>
                    <div class="passport-modern-blood" role="img" aria-label="Blood type">
                        <span>Blood type</span>
                        <strong id="prev-blood"><?= student_e($passport['blood_type']) ?></strong>
                    </div>
                </div>

                <div class="passport-modern-content">
                    <section class="passport-modern-section">
                        <div class="passport-modern-section-title"><span class="material-symbols-outlined">person</span> Personal information</div>
                        <div class="passport-modern-personal-grid">
                            <div class="passport-modern-personal-item passport-modern-personal-item-wide"><span>Full name</span><strong><?= student_e($passport['name']) ?></strong></div>
                            <div class="passport-modern-personal-item"><span>ID number</span><strong><?= student_e($passport['student_id']) ?></strong></div>
                            <div class="passport-modern-personal-item"><span>Date of birth</span><strong><?= student_e($passport['dob']) ?></strong></div>
                            <div class="passport-modern-personal-item"><span>Sex</span><strong><?= student_e($passport['sex']) ?></strong></div>
                            <div class="passport-modern-personal-item"><span>Blood type</span><strong><?= student_e($passport['blood_type']) ?></strong></div>
                        </div>
                    </section>
                    <section class="passport-modern-section">
                        <div class="passport-modern-section-title"><span class="material-symbols-outlined">medical_information</span> Medical information</div>
                        <div class="passport-modern-medical-list">
                            <div class="passport-modern-medical-item"><span>Allergies</span><strong id="prev-allergies"><?= student_e($passport['allergies'] ?: 'None reported.') ?></strong></div>
                            <div class="passport-modern-medical-item"><span>Medical conditions</span><strong id="prev-conditions"><?= nl2br(student_e($passport['conditions'] ?: 'None reported.')) ?></strong></div>
                            <div class="passport-modern-medical-item"><span>Current medications</span><strong id="prev-medications"><?= nl2br(student_e($passport['medications'] ?: 'No current medications recorded.')) ?></strong></div>
                            <div class="passport-modern-medical-item"><span>Emergency instructions</span><strong class="passport-modern-instructions" id="prev-instructions"><?= nl2br(student_e($passport['instructions'])) ?></strong></div>
                        </div>
                    </section>
                    <section class="passport-modern-section" id="prev-body-measurements"<?= $passport['show_bmi'] ? '' : ' hidden' ?>>
                        <div class="passport-modern-section-title"><span class="material-symbols-outlined">monitor_weight</span> Body measurements</div>
                        <div class="passport-modern-personal-grid">
                            <div class="passport-modern-personal-item"><span>Height</span><strong><?= $passport['height_cm'] !== null ? student_e(number_format((float) $passport['height_cm'], 2)) . ' cm' : 'Not recorded' ?></strong></div>
                            <div class="passport-modern-personal-item"><span>Weight</span><strong><?= $passport['weight_kg'] !== null ? student_e(number_format((float) $passport['weight_kg'], 2)) . ' kg' : 'Not recorded' ?></strong></div>
                            <div class="passport-modern-personal-item"><span>BMI</span><strong><?= $passport['bmi'] !== null ? student_e(number_format((float) $passport['bmi'], 2)) : 'Not recorded' ?></strong></div>
                        </div>
                    </section>

                    <div class="passport-modern-contact">
                        <div class="passport-modern-contact-icon" aria-hidden="true">
                            <span class="material-symbols-outlined">phone_in_talk</span>
                        </div>
                        <div class="passport-modern-contact-details">
                            <span>Emergency Contact</span>
                            <strong id="prev-guardian"><?= student_e($passport['guardian_name'] ?: 'Not provided') ?></strong>
                            <small><span id="prev-rel"><?= student_e($passport['relationship']) ?></span> &bull; <span id="prev-phone"><?= student_e($passport['primary_contact'] ?: 'No phone number') ?></span></small>
                        </div>
                        <a class="passport-modern-call" href="tel:<?= student_e($passport['primary_contact']) ?>" id="prev-call-link">
                            <span class="material-symbols-outlined">call</span>
                            Call Now
                        </a>
                    </div>

                    <div class="passport-modern-updated">
                        <span class="material-symbols-outlined">schedule</span>
                        Last updated: <strong><?= student_e($passport['last_updated']) ?></strong>
                    </div>
                </div>
            </div>
        </section>
        </details>

    </div><!-- /right column -->

</div><!-- /student-grid -->
</form>

<script src="../public/assets/vendor/qrcode/qrcode.min.js?v=1.0.0"></script>
<script src="../public/assets/js/patient-passport-qr.js?v=2"></script>
<?php render_student_footer(); ?>

<script src="../public/assets/js/emergency-contact.js?v=1"></script>
<script>
(() => {
    const form = document.getElementById('passport-form');
    if (!form) return;
    const saveButton = form.querySelector('.passport-save-button');
    const initialValues = new URLSearchParams(new FormData(form)).toString();
    let isDirty = false;
    const syncDirtyState = () => {
        isDirty = new URLSearchParams(new FormData(form)).toString() !== initialValues;
        form.dataset.passportDirty = isDirty ? 'true' : 'false';
        if (saveButton) saveButton.disabled = !isDirty;
    };
    form.addEventListener('input', syncDirtyState);
    form.addEventListener('change', syncDirtyState);
    form.addEventListener('submit', (event) => {
        if (!isDirty) event.preventDefault();
    });
    syncDirtyState();

    const isPhone = window.matchMedia('(max-width: 640px)').matches;
    const isCompactViewport = window.matchMedia('(max-width: 1024px)').matches;
    if (!isCompactViewport) return;

    if (isPhone) {
        form.classList.add('passport-tab-profile');
        const profilePanel = form.querySelector('[data-passport-group="profile"]');
        if (profilePanel?.matches('details')) profilePanel.open = true;
    }
    const passportTabs = document.querySelectorAll('[data-passport-tab]');
    const openPassportGroup = (group, shouldFocus = false) => {
        const tab = form.querySelector(`[data-passport-tab="${group}"]`);
        if (tab) tab.click();
        const target = form.querySelector(`#passport-${group === 'emergency' ? 'emergency-contact-panel' : group}`) || form.querySelector(`[data-passport-group="${group}"]`);
        if (target?.matches('details')) target.open = true;
        if (shouldFocus && group === 'emergency') {
            window.setTimeout(() => {
                const field = form.querySelector('#guardian_name')?.value.trim() ? form.querySelector('#primary_contact') : form.querySelector('#guardian_name');
                field?.focus({ preventScroll: true });
            }, 120);
        }
    };
    const mobileSaveHint = document.getElementById('passport-mobile-save-hint');
    if (isPhone) {
        form.noValidate = true;
        form.addEventListener('submit', (event) => {
            const requiredContactFields = ['guardian_name', 'relationship', 'primary_contact']
                .map((id) => form.querySelector(`#${id}`))
                .filter(Boolean);
            const invalidField = requiredContactFields.find((field) => !field.checkValidity());
            if (!invalidField) return;
            event.preventDefault();
            if (mobileSaveHint) mobileSaveHint.hidden = false;
            openPassportGroup('emergency', true);
        });
    }
    passportTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const group = tab.dataset.passportTab;
            if (isPhone) {
                form.classList.remove('passport-tab-profile', 'passport-tab-emergency', 'passport-tab-access');
                form.classList.add(`passport-tab-${group}`);
            }
            document.querySelectorAll('[data-passport-tab]').forEach((item) => item.classList.toggle('is-active', item === tab));

            const target = form.querySelector(`[data-passport-group="${group}"]`);
            if (!target) return;
            if (target.matches('details')) target.open = true;
            const header = document.querySelector('.student-topbar');
            const headerHeight = header?.getBoundingClientRect().height ?? 72;
            const tabsHeight = document.querySelector('.passport-mobile-tabs')?.getBoundingClientRect().height ?? 0;
            const hintHeight = mobileSaveHint && !mobileSaveHint.hidden ? mobileSaveHint.getBoundingClientRect().height + 8 : 0;
            const top = target.getBoundingClientRect().top + window.scrollY - headerHeight - tabsHeight - hintHeight - 12;
            window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        });
    });
    document.querySelector('[data-passport-open-group]')?.addEventListener('click', (event) => {
        event.preventDefault();
        openPassportGroup(event.currentTarget.dataset.passportOpenGroup, true);
    });
    const passportErrorGroup = form.dataset.passportErrorGroup;
    if (passportErrorGroup && isPhone) openPassportGroup(passportErrorGroup, true);
    document.querySelectorAll('[data-passport-token]').forEach((button) => {
        button.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(button.dataset.passportToken); button.title = 'Copied'; } catch (_) { button.title = 'Copy unavailable'; }
        });
    });
})();

// ── Live preview update ───────────────────────────────────────────
(function () {
    const $ = id => document.getElementById(id);

    function syncField(inputId, previewId, transform) {
        const inp = $(inputId);
        const out = $(previewId);
        if (!inp || !out) return;
        inp.addEventListener('input', () => {
            out.innerHTML = transform ? transform(inp.value) : escHtml(inp.value);
        });
    }

    function escHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function nl2br(s) {
        return escHtml(s).replace(/\n/g, '<br>');
    }

    // Allergies → tags
    const allergyInp = $('allergies');
    if (allergyInp) {
        allergyInp.addEventListener('input', () => {
            const tags = allergyInp.value.split(/[,;\n]+/).map(t => t.trim()).filter(Boolean);
            $('prev-allergies').innerHTML = tags.map(t =>
                `<span class="passport-modern-tag">${escHtml(t)}</span>`
            ).join('');
        });
    }

    syncField('conditions',   'prev-conditions',   nl2br);
    syncField('medications',  'prev-medications',  nl2br);
    syncField('instructions', 'prev-instructions', nl2br);
    syncField('guardian_name','prev-guardian',      null);
    syncField('primary_contact', 'prev-phone',      null);

    const relSel = $('relationship');
    if (relSel) {
        relSel.addEventListener('change', () => {
            $('prev-rel').textContent = relSel.value;
        });
    }

    const pcInp = $('primary_contact');
    if (pcInp) {
        pcInp.addEventListener('input', () => {
            const link = $('prev-call-link');
            if (link) link.href = 'tel:' + pcInp.value;
        });
    }

    const bodyMeasurementsVisibility = $('show_bmi_on_passport');
    const bodyMeasurements = $('prev-body-measurements');
    if (bodyMeasurementsVisibility && bodyMeasurements) {
        const syncBodyMeasurementsVisibility = () => {
            bodyMeasurements.hidden = !bodyMeasurementsVisibility.checked;
        };
        bodyMeasurementsVisibility.addEventListener('change', syncBodyMeasurementsVisibility);
        syncBodyMeasurementsVisibility();
    }

})();
</script>
