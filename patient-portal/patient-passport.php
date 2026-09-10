<?php
require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/AlertWorkflow.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../app/helpers/emergency_contact.php';
require_once __DIR__ . '/../app/services/AuditLog.php';

ensure_alert_workflow_schema();
ensure_ape_workflow_schema();
$profile = student_require_login();
$patientId = (int) $profile['patient_id'];

$latestBmiRecord = null;
if ($patientId > 0) {
    $latestBmiStmt = auth_db()->prepare("
        SELECT patient_height_cm, patient_weight_kg, patient_bmi, patient_vitals_confirmed_at
        FROM ape_records
        WHERE patient_id = ?
          AND patient_vitals_status = 'Confirmed'
          AND (patient_height_cm IS NOT NULL OR patient_weight_kg IS NOT NULL OR patient_bmi IS NOT NULL)
        ORDER BY COALESCE(patient_vitals_confirmed_at, exam_date, created_at) DESC, ape_id DESC
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
    'bmi_recorded_at' => $latestBmiRecord['patient_vitals_confirmed_at'] ?? null,
];
$passportUrl = '../public/emergency.php?token=' . urlencode($passport['token']);
$passportPreviewUrl = 'passport-demo.php?token=' . urlencode($passport['token']);

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($patientId <= 0) {
        $passportError = 'A clinical patient record is required before health-passport information can be saved.';
    } else {
    try {
    $passport['blood_type']       = trim($_POST['blood_type'] ?? $passport['blood_type']);
    $passport['allergies']        = trim($_POST['allergies'] ?? $passport['allergies']);
    $passport['conditions']       = trim($_POST['conditions'] ?? $passport['conditions']);
    $passport['medications']      = trim($_POST['medications'] ?? $passport['medications']);
    $passport['instructions']     = trim($_POST['instructions'] ?? $passport['instructions']);
    $passport['show_bmi']         = isset($_POST['show_bmi_on_passport']);
    $passport['guardian_name'] = trim((string) ($_POST['guardian_name'] ?? ''));
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
            SET blood_type = ?, allergies = ?, existing_conditions = ?, medications = ?, emergency_instructions = ?,
                guardian_or_contact_name = ?, guardian_or_contact_number = ?,
                guardian_relationship = ?, secondary_contact_number = ?, show_bmi_on_passport = ?
            WHERE person_id = ?
        ");
        $stmt->execute([
            $passport['blood_type'],
            $passport['allergies'],
            $passport['conditions'],
            $passport['medications'],
            $passport['instructions'],
            $passport['guardian_name'],
            $passport['primary_contact'],
            $passport['relationship'],
            $passport['secondary_contact'] !== '' ? $passport['secondary_contact'] : null,
            $passport['show_bmi'] ? 1 : 0,
            $patientId,
        ]);
        $saved = true;
        audit_log_event('passport', 'passport_profile_updated', $patientId, 'student', 'patient', $patientId, ['fields' => ['blood_type', 'allergies', 'conditions', 'medications', 'instructions', 'emergency_contacts', 'show_bmi_on_passport']]);
        $passport['last_updated'] = date('F j, Y');
    } catch (InvalidArgumentException $e) {
        $saved = false;
        $passportError = $e->getMessage();
    } catch (Throwable $e) {
        $saved = false;
        $passportError = 'The passport settings could not be saved. Please try again.';
        error_log('[CLINiQ Passport] Save failed: ' . $e->getMessage());
    }
    }
}

render_student_header('Emergency Health Passport', 'passport');
?>

<section class="student-page-header">
    <div>
        <p class="student-eyebrow">Emergency Health</p>
        <h1 class="student-title">Health Passport</h1>
        <p class="student-subtitle">Manage the information shown on your Emergency Health Passport accessed via QR or NFC.</p>
    </div>
    <span class="student-badge passport-badge-emergency">
        <span class="material-symbols-outlined passport-icon-sm">emergency</span>
        Emergency Access
    </span>
</section>

<?php if (!empty($passportError)): ?>
<div class="student-note student-note-warning mb-4">
    <span class="material-symbols-outlined">warning</span>
    <div><?= student_e($passportError) ?></div>
</div>
<?php endif; ?>

<?php if ($saved): ?>
<div class="student-note student-note-success mb-4">
    <span class="material-symbols-outlined">check_circle</span>
    <div><strong>Passport settings saved.</strong> Your Emergency Health Passport has been updated.</div>
</div>
<?php endif; ?>

<!-- ── Emergency Notice ── -->
<div class="student-note student-note-danger mb-4">
    <span class="material-symbols-outlined">info</span>
    <div>
        <strong>This information is shown to emergency responders.</strong>
        Make sure all fields are accurate. Only emergency-relevant data is displayed on the public passport page &mdash; full medical records are never exposed.
    </div>
</div>

<form method="POST" action="" id="passport-form" data-emergency-contact-form>
<div class="student-grid">

    <!-- ── Left column: Settings ── -->
    <div class="student-span-7 grid gap-4">

        <!-- Personal Information (read-only + editable blood type) -->
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
                <div class="student-grid passport-grid-tight">
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
                        <label class="student-label" for="blood_type">Blood Type <span class="passport-editable-tag">Editable</span></label>
                        <select id="blood_type" name="blood_type" class="student-select">
                            <?php
                            $types = dropdown_options('blood_type');
                            foreach ($types as $t):
                            ?>
                                <option value="<?= student_e($t) ?>" <?= $passport['blood_type'] === $t ? 'selected' : '' ?>><?= student_e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        <!-- BMI -->
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
                <div class="student-grid passport-grid-tight">
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
                                <strong>Show BMI on Emergency Passport</strong>
                                <small>Turn this off to hide only your BMI value from the QR/NFC passport.</small>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <!-- Emergency Information -->
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
            <div class="student-card-pad grid gap-0">

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
                    <label class="student-label" for="conditions">
                        <span class="passport-dot passport-dot-amber"></span>
                        Existing Medical Conditions
                    </label>
                    <textarea
                        id="conditions"
                        name="conditions"
                        class="student-textarea passport-textarea-md"
                        placeholder="e.g. Asthma (mild), Iron-deficiency anaemia"
                    ><?= student_e($passport['conditions']) ?></textarea>
                </div>

                <div class="student-field">
                    <label class="student-label" for="medications">
                        <span class="passport-dot passport-dot-green"></span>
                        Current Medications
                    </label>
                    <textarea
                        id="medications"
                        name="medications"
                        class="student-textarea passport-textarea-sm"
                        placeholder="e.g. Salbutamol inhaler (as needed), Ferrous sulfate 325 mg daily"
                    ><?= student_e($passport['medications']) ?></textarea>
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

        <!-- Emergency Contacts -->
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

        <!-- Save button -->
        <div class="passport-action-row">
            <button type="submit" class="student-button passport-save-button">
                <span class="material-symbols-outlined">save</span>
                Save Passport Settings
            </button>
            <a href="<?= student_e($passportPreviewUrl) ?>" target="_blank" class="student-button-secondary passport-preview-button text-decoration-none">
                <span class="material-symbols-outlined">open_in_new</span>
                View Live Passport
            </a>
        </div>

    </div>

    <!-- ── Right column: QR/NFC Preview ── -->
    <div class="student-span-5 grid gap-4">

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
                    Token: <code><?= student_e($passport['token']) ?></code>
                </div>
            </div>
        </section>

        <!-- Live Passport Preview -->
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
                            <span class="passport-modern-status">
                                <span class="material-symbols-outlined">check_circle</span>
                                No Active Incident
                            </span>
                        </div>
                        <div class="passport-modern-name" id="prev-name"><?= student_e($passport['name']) ?></div>
                        <div class="passport-modern-meta">
                            <span id="prev-sid"><?= student_e($passport['student_id']) ?></span>
                            <span aria-hidden="true">&bull;</span>
                            <span><?= student_e($profile['course'] ?? 'Not recorded') ?></span>
                        </div>
                    </div>
                    <div class="passport-modern-blood" role="img" aria-label="Blood type">
                        <span>Blood type</span>
                        <strong id="prev-blood"><?= student_e($passport['blood_type']) ?></strong>
                    </div>
                </div>

                <div class="passport-modern-content">
                    <div class="passport-modern-info-grid">
                        <article class="passport-modern-info passport-modern-info-allergy">
                            <div class="passport-modern-info-heading">
                                <span class="material-symbols-outlined">allergy</span>
                                <span>Allergies</span>
                            </div>
                            <div class="passport-modern-tags" id="prev-allergies">
                                <?php foreach (array_filter(array_map('trim', preg_split('/[,;\r\n]+/', $passport['allergies']) ?: [])) as $tag): ?>
                                    <span class="passport-modern-tag"><?= student_e($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </article>

                        <article class="passport-modern-info passport-modern-info-condition">
                            <div class="passport-modern-info-heading">
                                <span class="material-symbols-outlined">cardiology</span>
                                <span>Medical Conditions</span>
                            </div>
                            <div class="passport-modern-value" id="prev-conditions"><?= nl2br(student_e($passport['conditions'])) ?></div>
                        </article>

                        <article class="passport-modern-info passport-modern-info-medication">
                            <div class="passport-modern-info-heading">
                                <span class="material-symbols-outlined">medication</span>
                                <span>Current Medications</span>
                            </div>
                            <div class="passport-modern-value" id="prev-medications"><?= nl2br(student_e($passport['medications'])) ?></div>
                        </article>

                        <article class="passport-modern-info passport-modern-info-instructions">
                            <div class="passport-modern-info-heading">
                                <span class="material-symbols-outlined">emergency_home</span>
                                <span>Emergency Instructions</span>
                            </div>
                            <div class="passport-modern-instructions" id="prev-instructions"><?= nl2br(student_e($passport['instructions'])) ?></div>
                        </article>

                        <?php if ($passport['height_cm'] || $passport['weight_kg'] || $passport['bmi']): ?>
                            <article class="passport-modern-info passport-modern-info-bmi">
                                <div class="passport-modern-info-heading">
                                    <span class="material-symbols-outlined">monitor_weight</span>
                                    <span>Body Measurements</span>
                                </div>
                                <div class="passport-modern-metrics">
                                    <span><strong><?= student_e((string) ($passport['height_cm'] ?: '—')) ?></strong><small>Height (cm)</small></span>
                                    <span><strong><?= student_e((string) ($passport['weight_kg'] ?: '—')) ?></strong><small>Weight (kg)</small></span>
                                                <span id="prev-bmi-metric" <?= $passport['show_bmi'] ? '' : 'hidden' ?>><strong><?= student_e((string) ($passport['bmi'] ?: '—')) ?></strong><small>BMI</small></span>
                                </div>
                            </article>
                        <?php endif; ?>
                    </div>

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

                    <details class="passport-modern-guidance">
                        <summary>
                            <span><span class="material-symbols-outlined">emergency</span> Emergency response guidance</span>
                            <span class="material-symbols-outlined passport-modern-guidance-caret">expand_more</span>
                        </summary>
                        <div class="passport-modern-guidance-grid">
                            <div><strong>Breathing difficulty</strong><span>Sit the patient upright, assist with prescribed medication, and monitor breathing.</span></div>
                            <div><strong>Allergic reaction</strong><span>Avoid further exposure, monitor the airway, and seek medical assistance.</span></div>
                            <div><strong>Unconscious patient</strong><span>Place in the recovery position, monitor breathing, and contact the guardian.</span></div>
                        </div>
                    </details>

                    <div class="passport-modern-updated">
                        <span class="material-symbols-outlined">schedule</span>
                        Last updated: <strong><?= student_e($passport['last_updated']) ?></strong>
                    </div>
                </div>
            </div>
        </section>

    </div><!-- /right column -->

</div><!-- /student-grid -->
</form>

<script src="../public/assets/vendor/qrcode/qrcode.min.js?v=1.0.0"></script>
<script src="../public/assets/js/patient-passport-qr.js?v=2"></script>
<?php render_student_footer(); ?>

<script src="../public/assets/js/emergency-contact.js?v=1"></script>
<script>
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

    // Blood type
    const bloodSel = $('blood_type');
    if (bloodSel) {
        bloodSel.addEventListener('change', () => {
            $('prev-blood').textContent = bloodSel.value;
        });
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

    const bmiVisibility = $('show_bmi_on_passport');
    const bmiMetric = $('prev-bmi-metric');
    if (bmiVisibility && bmiMetric) {
        const syncBmiVisibility = () => {
            bmiMetric.hidden = !bmiVisibility.checked;
        };
        bmiVisibility.addEventListener('change', syncBmiVisibility);
        syncBmiVisibility();
    }

})();
</script>
