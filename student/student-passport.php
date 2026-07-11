<?php
require_once __DIR__ . '/includes/student-layout.php';

$profile = student_demo_profile();

// Demo passport data (in production, this would come from the database)
$passport = [
    'name'            => 'Juan dela Cruz',
    'student_id'      => '23-00456',
    'dob'             => 'March 15, 2002',
    'sex'             => 'Male',
    'blood_type'      => 'O+',
    'allergies'       => 'Penicillin, Sulfonamides, Shellfish',
    'conditions'      => 'Asthma (mild, intermittent)' . "\n" . 'Iron-deficiency anaemia',
    'medications'     => 'Salbutamol inhaler (as needed)',
    'instructions'    => "Do NOT administer penicillin or any sulfa-based antibiotics.\nPatient uses a Salbutamol inhaler &mdash; check bag first.\nIf unconscious, place in recovery position and call guardian immediately.",
    'guardian_name'   => 'Elena R. dela Cruz',
    'relationship'    => 'Mother',
    'primary_contact' => '+63 917 555 0192',
    'secondary_contact' => '+63 998 123 4567',
    'last_updated'    => 'July 10, 2026',
    'token'           => 'demo-token-abc123',
];

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // In production: validate and save to DB
    $saved = true;
    // Update demo values from POST for preview
    $passport['blood_type']       = trim($_POST['blood_type'] ?? $passport['blood_type']);
    $passport['allergies']        = trim($_POST['allergies'] ?? $passport['allergies']);
    $passport['conditions']       = trim($_POST['conditions'] ?? $passport['conditions']);
    $passport['medications']      = trim($_POST['medications'] ?? $passport['medications']);
    $passport['instructions']     = trim($_POST['instructions'] ?? $passport['instructions']);
    $passport['guardian_name']    = trim($_POST['guardian_name'] ?? $passport['guardian_name']);
    $passport['relationship']     = trim($_POST['relationship'] ?? $passport['relationship']);
    $passport['primary_contact']  = trim($_POST['primary_contact'] ?? $passport['primary_contact']);
    $passport['secondary_contact']= trim($_POST['secondary_contact'] ?? $passport['secondary_contact']);
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
        <span class="material-symbols-outlined" style="font-size:14px;">emergency</span>
        Emergency Access
    </span>
</section>

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

<form method="POST" action="" id="passport-form">
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
                    <span class="material-symbols-outlined" style="font-size:12px;">lock</span>
                    Mostly Read-only
                </span>
            </div>
            <div class="student-card-pad">
                <div class="student-grid" style="gap:0.75rem;">
                    <div class="student-span-6 student-field" style="margin-bottom:0;">
                        <label class="student-label">Full Name</label>
                        <div class="passport-readonly-field"><?= student_e($passport['name']) ?></div>
                    </div>
                    <div class="student-span-6 student-field" style="margin-bottom:0;">
                        <label class="student-label">Student ID</label>
                        <div class="passport-readonly-field"><?= student_e($passport['student_id']) ?></div>
                    </div>
                    <div class="student-span-6 student-field" style="margin-bottom:0;">
                        <label class="student-label">Date of Birth</label>
                        <div class="passport-readonly-field"><?= student_e($passport['dob']) ?></div>
                    </div>
                    <div class="student-span-6 student-field" style="margin-bottom:0;">
                        <label class="student-label">Sex</label>
                        <div class="passport-readonly-field"><?= student_e($passport['sex']) ?></div>
                    </div>
                    <div class="student-span-12 student-field" style="margin-bottom:0;">
                        <label class="student-label" for="blood_type">Blood Type <span class="passport-editable-tag">Editable</span></label>
                        <select id="blood_type" name="blood_type" class="student-select">
                            <?php
                            $types = ['A+','A−','B+','B−','AB+','AB−','O+','O−','Unknown'];
                            foreach ($types as $t):
                            ?>
                                <option value="<?= student_e($t) ?>" <?= $passport['blood_type'] === $t ? 'selected' : '' ?>><?= student_e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
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
                    <span class="material-symbols-outlined" style="font-size:12px;">edit</span>
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
                        class="student-textarea"
                        placeholder="e.g. Asthma (mild), Iron-deficiency anaemia"
                        style="min-height:5rem;"
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
                        class="student-textarea"
                        placeholder="e.g. Salbutamol inhaler (as needed), Ferrous sulfate 325 mg daily"
                        style="min-height:4.5rem;"
                    ><?= student_e($passport['medications']) ?></textarea>
                </div>

                <div class="student-field" style="margin-bottom:0;">
                    <label class="student-label" for="instructions">
                        <span class="passport-dot passport-dot-blue"></span>
                        Emergency Instructions
                    </label>
                    <textarea
                        id="instructions"
                        name="instructions"
                        class="student-textarea"
                        placeholder="e.g. Do NOT give penicillin. Inhaler is in the bag. Call guardian if unconscious."
                        style="min-height:6rem;"
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
                    <span class="material-symbols-outlined" style="font-size:12px;">contacts</span>
                    Guardian
                </span>
            </div>
            <div class="student-card-pad">
                <div class="student-grid" style="gap:0.75rem;">
                    <div class="student-span-6 student-field" style="margin-bottom:0;">
                        <label class="student-label" for="guardian_name">Guardian Name</label>
                        <input
                            id="guardian_name"
                            name="guardian_name"
                            type="text"
                            class="student-input"
                            value="<?= student_e($passport['guardian_name']) ?>"
                            placeholder="Full name of guardian or next of kin"
                        >
                    </div>
                    <div class="student-span-6 student-field" style="margin-bottom:0;">
                        <label class="student-label" for="relationship">Relationship</label>
                        <select id="relationship" name="relationship" class="student-select">
                            <?php
                            $rels = ['Mother','Father','Parent','Sibling','Spouse','Relative','Guardian','Other'];
                            foreach ($rels as $r):
                            ?>
                                <option value="<?= student_e($r) ?>" <?= $passport['relationship'] === $r ? 'selected' : '' ?>><?= student_e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="student-span-6 student-field" style="margin-bottom:0;">
                        <label class="student-label" for="primary_contact">Primary Contact Number</label>
                        <input
                            id="primary_contact"
                            name="primary_contact"
                            type="tel"
                            class="student-input"
                            value="<?= student_e($passport['primary_contact']) ?>"
                            placeholder="+63 9XX XXX XXXX"
                        >
                    </div>
                    <div class="student-span-6 student-field" style="margin-bottom:0;">
                        <label class="student-label" for="secondary_contact">Secondary Contact Number</label>
                        <input
                            id="secondary_contact"
                            name="secondary_contact"
                            type="tel"
                            class="student-input"
                            value="<?= student_e($passport['secondary_contact']) ?>"
                            placeholder="+63 9XX XXX XXXX (optional)"
                        >
                    </div>
                </div>
            </div>
        </section>

        <!-- Save button -->
        <div class="flex gap-3">
            <button type="submit" class="student-button" style="flex:1;">
                <span class="material-symbols-outlined">save</span>
                Save Passport Settings
            </button>
            <a href="passport-demo.html" target="_blank" class="student-button-secondary text-decoration-none" style="white-space:nowrap;">
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
            <div class="student-card-pad" style="text-align:center;">
                <div class="passport-qr-wrap" id="qr-container" aria-label="QR code for Emergency Health Passport">
                    <!-- Inline SVG QR placeholder &mdash; in production use a real QR library -->
                    <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" class="passport-qr-svg" aria-hidden="true">
                        <!-- Corner squares -->
                        <rect x="10" y="10" width="55" height="55" rx="6" fill="none" stroke="#17261d" stroke-width="6"/>
                        <rect x="22" y="22" width="31" height="31" rx="3" fill="#17261d"/>
                        <rect x="135" y="10" width="55" height="55" rx="6" fill="none" stroke="#17261d" stroke-width="6"/>
                        <rect x="147" y="22" width="31" height="31" rx="3" fill="#17261d"/>
                        <rect x="10" y="135" width="55" height="55" rx="6" fill="none" stroke="#17261d" stroke-width="6"/>
                        <rect x="22" y="147" width="31" height="31" rx="3" fill="#17261d"/>
                        <!-- Data dots (simulated pattern) -->
                        <?php
                        // Pseudo-random dot grid for visual authenticity
                        $seed = crc32($passport['token']);
                        $positions = [];
                        for ($row = 0; $row < 9; $row++) {
                            for ($col = 0; $col < 9; $col++) {
                                $x = 80 + $col * 13;
                                $y = 10 + $row * 13;
                                $on = ($seed >> (($row * 9 + $col) % 30)) & 1;
                                if ($on) {
                                    echo "<rect x=\"{$x}\" y=\"{$y}\" width=\"9\" height=\"9\" rx=\"2\" fill=\"#17261d\"/>";
                                }
                            }
                        }
                        for ($row = 0; $row < 9; $row++) {
                            for ($col = 0; $col < 9; $col++) {
                                $x = 10 + $col * 13;
                                $y = 80 + $row * 13;
                                $on = ($seed >> (($row * 9 + $col + 7) % 30)) & 1;
                                if ($on) {
                                    echo "<rect x=\"{$x}\" y=\"{$y}\" width=\"9\" height=\"9\" rx=\"2\" fill=\"#17261d\"/>";
                                }
                            }
                        }
                        ?>
                    </svg>
                </div>
                <p class="passport-qr-label">Scan to view Emergency Passport</p>
                <div class="flex gap-2 mt-3">
                    <button type="button" class="student-button-secondary" style="flex:1;font-size:0.72rem;" onclick="alert('QR download will be connected to the backend.')">
                        <span class="material-symbols-outlined" style="font-size:1rem;">download</span>
                        Download QR
                    </button>
                    <button type="button" class="student-button-secondary" style="flex:1;font-size:0.72rem;" onclick="alert('NFC write feature requires a physical NFC device.')">
                        <span class="material-symbols-outlined" style="font-size:1rem;">nfc</span>
                        Write NFC
                    </button>
                </div>
                <div class="passport-token-chip mt-3">
                    <span class="material-symbols-outlined" style="font-size:0.85rem;">key</span>
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
                    <span class="material-symbols-outlined" style="font-size:12px;">visibility</span>
                    Live
                </span>
            </div>

            <!-- Preview shell mimicking passport-demo.html style -->
            <div class="passport-preview">

                <!-- Preview header -->
                <div class="pv-head">
                    <div class="pv-avatar" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </div>
                    <div class="pv-head-info">
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 6px;">
                            <div class="pv-pill" style="margin: 0;">
                                <svg width="6" height="6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="10"/></svg>
                                Emergency Passport
                            </div>
                            <div class="pv-status-badge">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                No Active Incident
                            </div>
                        </div>
                        <div class="pv-name" id="prev-name"><?= student_e($passport['name']) ?></div>
                        <div class="pv-meta" id="prev-sid"><?= student_e($passport['student_id']) ?></div>
                    </div>
                    <div class="pv-blood" role="img" aria-label="Blood type">
                        <div class="pv-blood-lbl">Blood</div>
                        <div class="pv-blood-val" id="prev-blood"><?= student_e($passport['blood_type']) ?></div>
                    </div>
                </div>

                <!-- Preview rows -->
                <div class="pv-body">
                    <div class="pv-row">
                        <div class="pv-row-lbl">
                            <span class="passport-dot passport-dot-red"></span>
                            Allergies
                        </div>
                        <div class="pv-tags" id="prev-allergies">
                            <?php
                            $tags = array_filter(array_map('trim', explode(',', $passport['allergies'])));
                            foreach ($tags as $tag):
                            ?>
                                <span class="pv-tag"><?= student_e($tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="pv-row">
                        <div class="pv-row-lbl">
                            <span class="passport-dot passport-dot-amber"></span>
                            Medical Conditions
                        </div>
                        <div class="pv-val" id="prev-conditions"><?= nl2br(student_e($passport['conditions'])) ?></div>
                    </div>

                    <div class="pv-row">
                        <div class="pv-row-lbl">
                            <span class="passport-dot passport-dot-blue"></span>
                            Emergency Instructions
                        </div>
                        <div class="pv-instr" id="prev-instructions"><?= nl2br(student_e($passport['instructions'])) ?></div>
                    </div>
                </div>

                <!-- Preview contact -->
                <div class="pv-contact">
                    <div class="pv-contact-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.8 19.8 0 0 1 3.09 4.18 2 2 0 0 1 5.09 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L9.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 23 17Z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="pv-contact-name" id="prev-guardian"><?= student_e($passport['guardian_name']) ?></div>
                        <div class="pv-contact-tel">
                            <span id="prev-phone"><?= student_e($passport['primary_contact']) ?></span>
                            &middot;
                            <span id="prev-rel"><?= student_e($passport['relationship']) ?></span>
                        </div>
                    </div>
                    <a class="pv-call" href="tel:<?= student_e($passport['primary_contact']) ?>" id="prev-call-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="width:13px;height:13px;">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.8 19.8 0 0 1 3.09 4.18 2 2 0 0 1 5.09 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L9.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 23 17Z"/>
                        </svg>
                        Call Now
                    </a>
                </div>

                <!-- ── Emergency Guidance ── -->
                <div class="pv-section-title">What To Do In Case of Emergency</div>

                <div class="pv-card pv-guidance-card pv-guidance-asthma">
                    <div class="pv-guidance-head">
                        <span class="pv-guidance-icon">&#129505;</span>
                        <span class="pv-guidance-title">Asthma Attack</span>
                    </div>
                    <ul class="pv-guidance-list">
                        <li>Help patient sit upright</li>
                        <li>Assist with inhaler</li>
                        <li>Monitor breathing</li>
                    </ul>
                </div>

                <div class="pv-card pv-guidance-card pv-guidance-allergy">
                    <div class="pv-guidance-head">
                        <span class="pv-guidance-icon">&#9888;&#65039;</span>
                        <span class="pv-guidance-title">Allergic Reaction</span>
                    </div>
                    <ul class="pv-guidance-list">
                        <li>Avoid allergen exposure</li>
                        <li>Monitor airway</li>
                        <li>Seek medical assistance</li>
                    </ul>
                </div>

                <div class="pv-card pv-guidance-card pv-guidance-unconscious">
                    <div class="pv-guidance-head">
                        <span class="pv-guidance-icon">&#128716;</span>
                        <span class="pv-guidance-title">Unconscious Patient</span>
                    </div>
                    <ul class="pv-guidance-list">
                        <li>Place in recovery position</li>
                        <li>Monitor breathing</li>
                        <li>Contact guardian</li>
                    </ul>
                </div>

                <!-- ── Incident Response Actions ── -->
                <div class="pv-section-title">&#128680; Incident Response</div>
                
                <div class="pv-card" style="padding: 16px;">
                    <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 14px; margin-top: -4px;">
                        Document the incident and notify the clinic.
                    </p>

                    <div class="pv-form-group">
                        <label class="pv-label">&#128247; Upload Incident Photos</label>
                        <div class="pv-file-dropzone">
                            <div class="pv-dropzone-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="17 8 12 3 7 8"></polyline>
                                    <line x1="12" y1="3" x2="12" y2="15"></line>
                                </svg>
                            </div>
                            <div class="pv-dropzone-text">Drag photos here or click to upload</div>
                            <div class="pv-dropzone-sub">Supported: JPG, PNG, WEBP</div>
                        </div>
                    </div>

                    <div class="pv-form-group">
                        <label class="pv-label">&#128221; Incident Description</label>
                        <textarea class="pv-textarea" style="min-height: 100px;" placeholder="Describe:
&bull; What happened
&bull; Student condition
&bull; Actions already taken
&bull; Other observations"></textarea>
                    </div>

                    <button class="pv-btn-primary">
                        <div style="font-size: 1.05rem; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 8px;">
                            &#128680; Notify Clinic
                        </div>
                        <div style="font-size: 0.7rem; font-weight: 500; opacity: 0.9; margin-top: 4px; text-transform: none; letter-spacing: normal;">
                            Send incident report, photos, and notes to clinic personnel.
                        </div>
                    </button>
                </div>

                <div class="pv-updated">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;flex-shrink:0;" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Last updated: <strong><?= student_e($passport['last_updated']) ?></strong>
                </div>
            </div><!-- /passport-preview -->
        </section>

    </div><!-- /right column -->

</div><!-- /student-grid -->
</form>

<?php render_student_footer(); ?>

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
            const tags = allergyInp.value.split(',').map(t => t.trim()).filter(Boolean);
            $('prev-allergies').innerHTML = tags.map(t =>
                `<span class="pv-tag">${escHtml(t)}</span>`
            ).join('');
        });
    }

    syncField('conditions',   'prev-conditions',   nl2br);
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
})();
</script>
