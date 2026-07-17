<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/RiskClassifier.php';
require_once __DIR__ . '/../../app/services/VisitWorkflow.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_visit_workflow_schema();
ensure_inventory_workflow_schema();

$patients = db()->query('SELECT id, student_number, first_name, last_name, course_section, sex FROM patients ORDER BY last_name, first_name')->fetchAll();
$medicineInventory = visit_medicine_inventory_options();
$equipmentInventory = visit_equipment_inventory_options();
$preselectedPatientId = (int) ($_GET['patient_id'] ?? 0);
$preselectedPatient = null;
foreach ($patients as $patient) {
    if ($preselectedPatientId === (int) $patient['id']) {
        $preselectedPatient = $patient;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = db();
    try {
        $postedStudentNumber = trim($_POST['student_number'] ?? '');
        $patientId = 0;
        if ($postedStudentNumber !== '') {
            if (!is_valid_student_id($postedStudentNumber)) {
                throw new InvalidArgumentException(student_id_format_message());
            }

            $patientCheck = $db->prepare('SELECT id FROM patients WHERE student_number = ? LIMIT 1');
            $patientCheck->execute([$postedStudentNumber]);
            $patientId = (int) ($patientCheck->fetchColumn() ?: 0);
        } else {
            $patientId = (int) ($_POST['patient_id'] ?? 0);
            $patientCheck = $db->prepare('SELECT id FROM patients WHERE id = ? LIMIT 1');
            $patientCheck->execute([$patientId]);
            $patientId = (int) ($patientCheck->fetchColumn() ?: 0);
        }

        if ($patientId <= 0) {
            throw new InvalidArgumentException('Please enter a valid student ID before saving the visit.');
        }

        $risk = classify_patient_risk($_POST);
        $riskReasons = risk_reasons_text($risk);
        $actionTaken = trim($_POST['action_taken'] ?? '');
        $status = normalize_visit_status($_POST['status'] ?? 'Active', 'Active');
        if ($status === 'Unaddressed' || $status === 'Cancelled') {
            $status = 'Active';
        }
        $purpose = normalize_visit_purpose($_POST['visit_purpose'] ?? null);
        $referralType = trim($_POST['referral_type'] ?? '');
        if ($referralType === 'None') {
            $referralType = '';
        }
        $dispensingRequest = visit_dispensing_request($_POST);

        $db->beginTransaction();
        $stmt = $db->prepare(
            'INSERT INTO clinic_visits (patient_id, visit_datetime, chief_complaint, symptoms, temperature, blood_pressure, pulse_rate, risk_level, risk_score, risk_reasons, status, visit_purpose, visit_source, action_taken, recorded_by, attended_by)
             VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $patientId,
            trim($_POST['chief_complaint'] ?? ''),
            trim($_POST['symptoms'] ?? ''),
            $_POST['temperature'] ?: null,
            trim($_POST['blood_pressure'] ?? ''),
            $_POST['pulse_rate'] ?: null,
            $risk['level'],
            $risk['score'],
            $riskReasons,
            $status,
            $purpose,
            'Staff Recorded',
            $actionTaken ?: null,
            current_user()['id'],
            current_user()['id'],
        ]);
        $visitId = (int) $db->lastInsertId();
        escalate_major_risk_visit($db, $visitId, $patientId, $risk['level'], (int) $risk['score'], $riskReasons);

        $borrower = visit_patient_borrower($db, $patientId);
        $dispensed = process_visit_inventory_request($db, $dispensingRequest, $borrower['name'], $borrower['identifier']);
        $remarks = trim($_POST['remarks'] ?? '');
        $dispensingNote = visit_dispensing_note($dispensed);
        if ($dispensingNote !== '') {
            $remarks = trim($remarks . "\n" . $dispensingNote);
        }
        $entry = [
            'symptoms_note' => trim($_POST['symptoms'] ?? ''),
            'diagnosis' => trim($_POST['diagnosis'] ?? ''),
            'management_treatment' => $actionTaken,
            'referral_type' => $referralType,
            'remarks' => $remarks,
        ];

        if (treatment_entry_has_content($entry)) {
            $treatmentStmt = $db->prepare(
                'INSERT INTO visit_treatment_entries (visit_id, symptoms_note, diagnosis, management_treatment, referral_type, remarks, dispensed_inventory_item_id, dispensed_quantity, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $treatmentStmt->execute([
                $visitId,
                $entry['symptoms_note'] ?: null,
                $entry['diagnosis'] ?: null,
                $entry['management_treatment'] ?: null,
                $entry['referral_type'] ?: null,
                $entry['remarks'] ?: null,
                $dispensed['item_id'] ?? null,
                $dispensed['quantity'] ?? null,
                current_user()['id'],
            ]);
        }

        $db->commit();
        flash_message('success', $dispensed ? 'Manual visit recorded and medicine stock deducted.' : 'Manual visit recorded.');
        header('Location: view.php?id=' . $visitId . '&from=logbook');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        header('Location: create.php' . ((int) ($_POST['patient_id'] ?? 0) > 0 ? '?patient_id=' . (int) $_POST['patient_id'] : ''));
    }
    exit;
}

set_page_back_link('index.php', 'Visits');
render_header('Record Visit');
?>
<style>
    .record-sheet-field {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 0.625rem;
        background: #f8fafc;
        color: #0f172a;
        font-size: 0.8125rem;
        font-weight: 700;
        line-height: 1.35;
        height: 3.625rem;
        min-height: 3.625rem;
        padding: 0.75rem 1rem;
        box-sizing: border-box;
    }
    textarea.record-sheet-field {
        min-height: 7rem;
        height: auto;
    }
    .vitals-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
    }
    .vital-tile {
        min-height: 6.25rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 0.875rem;
        background: #f8fafc;
        padding: 1rem;
    }
    .vital-label {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        color: #94a3b8;
        font-size: 0.68rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        margin-bottom: 0.75rem;
    }
    .vital-label .material-symbols-outlined {
        font-size: 1rem;
    }
    .vital-value-wrap {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.5rem;
    }
    .vital-input {
        width: 100%;
        height: 2.375rem;
        border: 1px solid rgba(148, 163, 184, 0.35);
        border-radius: 0.5rem;
        background: #fff;
        padding: 0 0.75rem;
        font-size: 0.8125rem;
        font-weight: 800;
        color: #0f172a;
    }
    .vital-unit {
        color: #94a3b8;
        font-size: 0.68rem;
        font-weight: 800;
    }
    @media (max-width: 900px) {
        .vitals-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 640px) {
        .vitals-grid { grid-template-columns: 1fr; }
    }
    .patient-lookup-status {
        min-height: 1.1rem;
        color: #64748b;
        font-size: 0.72rem;
        font-weight: 800;
    }
    .patient-lookup-status.is-found {
        color: #15803d;
    }
    .patient-lookup-status.is-missing {
        color: #b91c1c;
    }
</style>

<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 mb-6">
    <div>
        <p class="clinic-label mb-1">Manual Nurse Station Flow</p>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Manual Record Visit</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Record a walk-in visit directly with vitals, treatment, dispensing, and clinical notes.</p>
    </div>
</div>

<form method="post" id="visitForm" class="space-y-6">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <section class="clinic-card p-6 space-y-5">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-1">
                <span class="material-symbols-outlined text-primary text-[19px]">person</span>
                Patient Information
            </h2>
            <div>
                <label class="clinic-label" for="studentIdLookup">Student ID</label>
                <input class="record-sheet-field px-4" id="studentIdLookup" name="student_number" list="studentIdOptions" placeholder="00-00000" value="<?= e($preselectedPatient['student_number'] ?? '') ?>" autocomplete="off" data-student-id-format required>
                <input type="hidden" name="patient_id" id="patientIdInput" value="<?= $preselectedPatient ? (int) $preselectedPatient['id'] : '' ?>">
                <datalist id="studentIdOptions">
                    <?php foreach ($patients as $patient): ?>
                        <?php $patientName = trim($patient['last_name'] . ', ' . $patient['first_name']); ?>
                        <option value="<?= e($patient['student_number']) ?>"><?= e($patientName) ?></option>
                    <?php endforeach; ?>
                </datalist>
                <div id="patientLookupStatus" class="patient-lookup-status mt-2">Enter a student ID to load patient details.</div>
            </div>
            <div>
                <label class="clinic-label">Student Name</label>
                <input class="record-sheet-field px-4" id="patientNameDisplay" value="<?= $preselectedPatient ? e(trim($preselectedPatient['first_name'] . ' ' . $preselectedPatient['last_name'])) : 'Enter student ID' ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Course / Department</label>
                <input class="record-sheet-field px-4" id="patientCourseDisplay" value="<?= $preselectedPatient ? e($preselectedPatient['course_section'] ?: 'Not specified') : 'Enter student ID' ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Sex</label>
                <input class="record-sheet-field px-4" id="patientSexDisplay" value="<?= $preselectedPatient ? e($preselectedPatient['sex'] ?: 'Not specified') : 'Enter student ID' ?>" readonly>
            </div>
        </section>

        <section class="clinic-card p-6 space-y-5">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-1">
                <span class="material-symbols-outlined text-primary text-[19px]">clinical_notes</span>
                Visit Information
            </h2>
            <div>
                <label class="clinic-label">Purpose of Visit</label>
                <select class="record-sheet-field px-4" name="visit_purpose" required>
                    <option value="">Select purpose</option>
                    <?php foreach (visit_purposes() as $purpose): ?>
                        <option value="<?= e($purpose) ?>"><?= e($purpose) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="clinic-label">Chief Complaint</label>
                <textarea class="record-sheet-field p-4" name="chief_complaint" rows="3" placeholder="Patient's main concern..." required></textarea>
            </div>
            <div>
                <label class="clinic-label">Visit Status</label>
                <select class="record-sheet-field px-4" name="status">
                    <option value="Active">Active</option>
                    <option value="Completed">Completed</option>
                </select>
            </div>
            <div>
                <label class="clinic-label">Time of Arrival</label>
                <input class="record-sheet-field px-4" value="<?= e(date('g:i A')) ?>" readonly>
            </div>
        </section>
    </div>

    <section class="clinic-card p-6">
        <div class="flex items-center justify-between gap-3 mb-6">
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] flex items-center gap-3 m-0">
                <span class="material-symbols-outlined">monitor_heart</span>
                Vitals & Measurements
            </h2>
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Optional</span>
        </div>
        <div class="vitals-grid">
            <div class="vital-tile">
                <div class="vital-label"><span class="material-symbols-outlined">favorite</span>BP</div>
                <div class="vital-value-wrap">
                    <input class="vital-input" name="blood_pressure" placeholder="0">
                    <span class="vital-unit">mmHg</span>
                </div>
            </div>
            <div class="vital-tile">
                <div class="vital-label"><span class="material-symbols-outlined">thermostat</span>Temp</div>
                <div class="vital-value-wrap">
                    <input class="vital-input" name="temperature" id="tempInput" type="number" step="0.1" placeholder="0">
                    <span class="vital-unit">C</span>
                </div>
            </div>
            <div class="vital-tile">
                <div class="vital-label"><span class="material-symbols-outlined">ecg_heart</span>Heart</div>
                <div class="vital-value-wrap">
                    <input class="vital-input" name="pulse_rate" id="pulseInput" type="number" placeholder="0">
                    <span class="vital-unit">BPM</span>
                </div>
            </div>
            <?php foreach ([['air', 'SpO2', '%'], ['scale', 'Weight', 'kg'], ['height', 'Height', 'cm']] as [$icon, $label, $unit]): ?>
                <div class="vital-tile">
                    <div class="vital-label"><span class="material-symbols-outlined"><?= e($icon) ?></span><?= e($label) ?></div>
                    <div class="vital-value-wrap">
                        <input class="vital-input" placeholder="0">
                        <span class="vital-unit"><?= e($unit) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="clinic-card p-6">
        <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-5">
            <span class="material-symbols-outlined text-primary text-[19px]">inventory_2</span>
            Inventory & Dispensing
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-[0.7fr_1.6fr_0.6fr] gap-4">
            <div>
                <label class="clinic-label">Type</label>
                <select class="record-sheet-field px-4" name="dispensing_type">
                    <option value="Medicine">Medicine</option>
                    <option value="Equipment">Equipment</option>
                </select>
            </div>
            <div>
                <label class="clinic-label">Medicine / Equipment</label>
                <select class="record-sheet-field px-4 js-visit-inventory-item" name="dispensed_inventory_item_id">
                    <option value="" data-type="Medicine">No item selected</option>
                    <?php foreach ($medicineInventory as $medicine): ?>
                        <option value="<?= (int) $medicine['id'] ?>" data-type="Medicine" <?= (int) $medicine['quantity'] <= 0 ? 'disabled' : '' ?>>
                            <?= e($medicine['item_name']) ?> (<?= (int) $medicine['quantity'] ?> <?= e($medicine['unit']) ?>)
                        </option>
                    <?php endforeach; ?>
                    <?php foreach ($equipmentInventory as $equipment): ?>
                        <option value="<?= (int) $equipment['id'] ?>" data-type="Equipment" <?= (int) $equipment['quantity'] <= 0 ? 'disabled' : '' ?>>
                            <?= e($equipment['item_name']) ?> (<?= (int) $equipment['quantity'] ?> <?= e($equipment['unit']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="clinic-label">Quantity</label>
                <input class="record-sheet-field px-4" name="dispensed_quantity" type="number" min="1" placeholder="0">
            </div>
        </div>
    </section>

    <section class="clinic-card p-6">
        <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-5">
            <span class="material-symbols-outlined text-primary text-[19px]">medical_information</span>
            Medical Record
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="clinic-label">Nurse Symptoms / Assessment</label>
                <textarea class="record-sheet-field p-4" name="symptoms" id="symptomsInput" placeholder="Confirm symptoms and add nurse observations..."></textarea>
            </div>
            <div>
                <label class="clinic-label">Diagnosis</label>
                <textarea class="record-sheet-field p-4" name="diagnosis" placeholder="Clinical impression or diagnosis..."></textarea>
            </div>
            <div>
                <label class="clinic-label">Management / Treatment</label>
                <textarea class="record-sheet-field p-4" name="action_taken" placeholder="Treatment given, medication, monitoring, advice..."></textarea>
            </div>
            <div>
                <label class="clinic-label">Referral</label>
                <select class="record-sheet-field px-4" name="referral_type">
                    <?php foreach (visit_referral_options() as $option): ?>
                        <option value="<?= e($option) ?>"><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="clinic-label">Remarks</label>
                <textarea class="record-sheet-field p-4" name="remarks" rows="4" placeholder="General remarks or follow-up instruction..."></textarea>
            </div>
        </div>
        <div class="mt-6 pt-5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3">
            <div class="flex flex-wrap gap-3 justify-end">
                <a class="btn btn-ghost text-decoration-none justify-center" href="index.php">
                    <span class="material-symbols-outlined">cancel</span>
                    Cancel
                </a>
                <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save this manual visit?" data-confirm-message="This will create a staff-recorded visit and deduct medicine stock if medicine was dispensed." data-confirm-toast="Saving visit...">
                    <span class="material-symbols-outlined text-[18px]">save</span>
                    Save Visit
                </button>
            </div>
        </div>
    </section>
</form>

<script>
const visitPatients = <?= json_encode(array_map(static function (array $patient): array {
    return [
        'id' => (int) $patient['id'],
        'studentNumber' => $patient['student_number'],
        'name' => trim($patient['first_name'] . ' ' . $patient['last_name']),
        'course' => $patient['course_section'] ?: 'Not specified',
        'sex' => $patient['sex'] ?: 'Not specified',
    ];
}, $patients), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const visitPatientsByStudentNumber = new Map(visitPatients.map((patient) => [patient.studentNumber, patient]));
const studentIdLookup = document.getElementById('studentIdLookup');
const patientIdInput = document.getElementById('patientIdInput');
const patientNameDisplay = document.getElementById('patientNameDisplay');
const patientCourseDisplay = document.getElementById('patientCourseDisplay');
const patientSexDisplay = document.getElementById('patientSexDisplay');
const patientLookupStatus = document.getElementById('patientLookupStatus');

function normalizeStudentId(value) {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 7);
    if (digits.length <= 2) {
        return digits;
    }

    return `${digits.slice(0, 2)}-${digits.slice(2)}`;
}

function setLookupStatus(message, state = '') {
    patientLookupStatus.textContent = message;
    patientLookupStatus.classList.toggle('is-found', state === 'found');
    patientLookupStatus.classList.toggle('is-missing', state === 'missing');
}

function clearPatientLookup(message = 'Enter a student ID to load patient details.', state = '') {
    patientIdInput.value = '';
    patientNameDisplay.value = 'Enter student ID';
    patientCourseDisplay.value = 'Enter student ID';
    patientSexDisplay.value = 'Enter student ID';
    setLookupStatus(message, state);
}

function updatePatientLookup() {
    const formatted = normalizeStudentId(studentIdLookup.value);
    if (studentIdLookup.value !== formatted) {
        studentIdLookup.value = formatted;
    }

    if (formatted.length === 0) {
        clearPatientLookup();
        return;
    }

    const patient = visitPatientsByStudentNumber.get(formatted);
    if (!patient) {
        clearPatientLookup(formatted.length >= 8 ? 'No patient found for this student ID.' : 'Continue typing the student ID.', formatted.length >= 8 ? 'missing' : '');
        return;
    }

    patientIdInput.value = patient.id;
    patientNameDisplay.value = patient.name;
    patientCourseDisplay.value = patient.course;
    patientSexDisplay.value = patient.sex;
    setLookupStatus('Patient details loaded.', 'found');
}

studentIdLookup?.addEventListener('input', updatePatientLookup);
studentIdLookup?.addEventListener('change', updatePatientLookup);
updatePatientLookup();

document.getElementById('visitForm')?.addEventListener('submit', (event) => {
    updatePatientLookup();
    if (!patientIdInput.value) {
        event.preventDefault();
        studentIdLookup.focus();
        setLookupStatus('Enter a valid student ID before saving the visit.', 'missing');
    }
});

document.querySelectorAll('select[name="dispensing_type"]').forEach((typeSelect) => {
    const container = typeSelect.closest('section');
    const itemSelect = container?.querySelector('.js-visit-inventory-item');
    if (!itemSelect) return;

    const syncItems = () => {
        const activeType = typeSelect.value || 'Medicine';
        let currentVisible = false;
        Array.from(itemSelect.options).forEach((option) => {
            const optionType = option.dataset.type || 'Medicine';
            const visible = option.value === '' || optionType === activeType;
            option.hidden = !visible;
            if (option.selected && visible) currentVisible = true;
        });
        if (!currentVisible) itemSelect.value = '';
    };

    typeSelect.addEventListener('change', syncItems);
    syncItems();
});
</script>

<?php render_footer(); ?>
