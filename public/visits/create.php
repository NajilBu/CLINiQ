<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/VisitWorkflow.php';
require_once __DIR__ . '/../../app/services/CliniqVisitWorkflow.php';
require_login();

$patients = cliniq_visit_patients();
$medicineInventory = cliniq_inventory_available_medicines();
$equipmentInventory = [];
$preselectedPatientId = (int) ($_GET['patient_id'] ?? 0);
$preselectedPatient = null;
foreach ($patients as $patient) {
    if ($preselectedPatientId === (int) $patient['id']) {
        $preselectedPatient = $patient;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedStudentNumber = trim($_POST['id_number'] ?? '');
        $patientId = 0;
        if ($postedStudentNumber !== '') {
            if (!is_valid_id_number($postedStudentNumber)) {
                throw new InvalidArgumentException(id_number_validation_message());
            }

            $patient = cliniq_visit_patient_by_id_number($postedStudentNumber);
            $patientId = (int) ($patient['person_id'] ?? 0);
        } else {
            $patientId = (int) ($_POST['patient_id'] ?? 0);
            if (!cliniq_visit_patient_exists($patientId)) {
                $patientId = 0;
            }
        }

        if ($patientId <= 0) {
            throw new InvalidArgumentException('Please enter a valid ID number before saving the visit.');
        }

        $actionTaken = trim($_POST['action_taken'] ?? '');
        $purpose = normalize_visit_purpose($_POST['visit_purpose'] ?? null);
        $referralType = trim($_POST['referral_type'] ?? '');
        if ($referralType === 'None') {
            $referralType = '';
        }
        $staffPersonId = cliniq_visit_staff_person_id();
        $dispensings = cliniq_inventory_dispensing_rows($_POST);
        $visitId = cliniq_visit_create([
            'patient_person_id' => $patientId,
            'chief_complaint' => trim($_POST['chief_complaint'] ?? ''),
            'status' => 'Active',
            'visit_purpose' => $purpose,
            'visit_source' => 'Staff Recorded',
            'action_taken' => $actionTaken,
            'recorded_by_person_id' => $staffPersonId,
            'attended_by_person_id' => $staffPersonId,
        ], [
            'symptoms' => trim($_POST['symptoms'] ?? ''),
            'diagnosis' => trim($_POST['diagnosis'] ?? ''),
            'treatment' => $actionTaken,
            'referral' => $referralType,
            'remarks' => trim($_POST['remarks'] ?? ''),
        ], [
            'temperature' => $_POST['temperature'] ?? '',
            'blood_pressure' => trim($_POST['blood_pressure'] ?? ''),
            'pulse_rate' => $_POST['pulse_rate'] ?? '',
        ], $dispensings);

        flash_message('success', 'Manual visit recorded in Cliniq_db.');
        header('Location: view.php?id=' . $visitId . '&from=logbook');
    } catch (Throwable $e) {
        flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        header('Location: create.php' . ((int) ($_POST['patient_id'] ?? 0) > 0 ? '?patient_id=' . (int) $_POST['patient_id'] : ''));
    }
    exit;
}

set_page_back_link('index.php', 'Visits');
render_header('Record Visit');

render_clinic_command_header(
    'Manual Nurse Station Flow',
    'Manual Record Visit',
    'Record a walk-in visit directly with vitals, treatment, and clinical notes.'
);
?>

<form method="post" id="visitForm" class="space-y-6">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <section class="clinic-card p-6 space-y-5">
            <h2 class="font-headline text-lg font-extrabold text-[#1c2a59] flex items-center gap-2 mb-1">
                <span class="material-symbols-outlined text-primary text-[19px]">person</span>
                Patient Information
            </h2>
            <div>
                <label class="clinic-label" for="studentIdLookup">ID Number</label>
                <input class="record-sheet-field px-4" id="studentIdLookup" name="id_number" list="studentIdOptions" placeholder="Enter ID number" value="<?= e($preselectedPatient['id_number'] ?? '') ?>" autocomplete="off" data-id-number-format required>
                <input type="hidden" name="patient_id" id="patientIdInput" value="<?= $preselectedPatient ? (int) $preselectedPatient['id'] : '' ?>">
                <datalist id="studentIdOptions">
                    <?php foreach ($patients as $patient): ?>
                        <?php $patientName = trim($patient['last_name'] . ', ' . $patient['first_name']); ?>
                        <option value="<?= e($patient['id_number']) ?>"><?= e($patientName) ?></option>
                    <?php endforeach; ?>
                </datalist>
                <div id="patientLookupStatus" class="patient-lookup-status mt-2">Enter a ID number to load patient details.</div>
            </div>
            <div>
                <label class="clinic-label">Student Name</label>
                <input class="record-sheet-field px-4" id="patientNameDisplay" value="<?= $preselectedPatient ? e(trim($preselectedPatient['first_name'] . ' ' . $preselectedPatient['last_name'])) : 'Enter ID number' ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Course / Department</label>
                <input class="record-sheet-field px-4" id="patientCourseDisplay" value="<?= $preselectedPatient ? e($preselectedPatient['course_section'] ?: 'Not specified') : 'Enter ID number' ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Sex</label>
                <input class="record-sheet-field px-4" id="patientSexDisplay" value="<?= $preselectedPatient ? e($preselectedPatient['sex'] ?: 'Not specified') : 'Enter ID number' ?>" readonly>
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
        <p class="settings-help mb-4">Optional medicines are saved with the treatment entry and deducted from Cliniq_db stock.</p>
        <div class="space-y-3" data-dispensing-list>
            <div class="grid grid-cols-1 md:grid-cols-[0.7fr_1.6fr_0.55fr_auto] gap-4 items-end" data-dispensing-row>
                <div>
                    <label class="clinic-label">Type</label>
                    <select class="record-sheet-field px-4 js-dispensing-type" name="dispensing_type[]" disabled>
                        <option value="Medicine">Medicine</option>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Medicine</label>
                    <select class="record-sheet-field px-4 js-visit-inventory-item" name="dispensed_inventory_item_id[]">
                        <option value="" data-type="Medicine">No medicine selected</option>
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
                    <input class="record-sheet-field px-4" name="dispensed_quantity[]" type="number" min="1" placeholder="0">
                </div>
                <button type="button" class="btn btn-ghost js-remove-dispensing-row" title="Remove medicine" aria-label="Remove medicine">
                    <span class="material-symbols-outlined text-[18px]">delete</span>
                </button>
            </div>
        </div>
        <button type="button" class="btn btn-outline mt-4 js-add-dispensing-row">
            <span class="material-symbols-outlined text-[18px]">add</span>
            Add Item
        </button>
        <p class="settings-help mt-3 mb-0">Equipment borrowing is recorded separately in Inventory &amp; Tracking.</p>
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
                <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save this manual visit?" data-confirm-message="This will create the visit and deduct any selected medicine from Cliniq_db inventory." data-confirm-toast="Saving visit...">
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
        'studentNumber' => $patient['id_number'],
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

function clearPatientLookup(message = 'Enter a ID number to load patient details.', state = '') {
    patientIdInput.value = '';
    patientNameDisplay.value = 'Enter ID number';
    patientCourseDisplay.value = 'Enter ID number';
    patientSexDisplay.value = 'Enter ID number';
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
        clearPatientLookup(formatted.length >= 8 ? 'No patient found for this ID number.' : 'Continue typing the ID number.', formatted.length >= 8 ? 'missing' : '');
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
        setLookupStatus('Enter a valid ID number before saving the visit.', 'missing');
    }
});

function syncDispensingRow(row) {
    const typeSelect = row.querySelector('.js-dispensing-type');
    const itemSelect = row.querySelector('.js-visit-inventory-item');
    if (!typeSelect || !itemSelect) return;

    const activeType = typeSelect.value || 'Medicine';
    let currentVisible = false;
    Array.from(itemSelect.options).forEach((option) => {
        const optionType = option.dataset.type || 'Medicine';
        const visible = option.value === '' || optionType === activeType;
        option.hidden = !visible;
        if (option.selected && visible) currentVisible = true;
    });
    if (!currentVisible) itemSelect.value = '';
}

function updateDispensingRemoveButtons(list) {
    const rows = list.querySelectorAll('[data-dispensing-row]');
    rows.forEach((row) => {
        const removeButton = row.querySelector('.js-remove-dispensing-row');
        if (removeButton) removeButton.disabled = rows.length === 1;
    });
}

document.querySelectorAll('[data-dispensing-list]').forEach((list) => {
    list.querySelectorAll('[data-dispensing-row]').forEach(syncDispensingRow);
    updateDispensingRemoveButtons(list);

    list.addEventListener('change', (event) => {
        const typeSelect = event.target.closest('.js-dispensing-type');
        if (typeSelect) syncDispensingRow(typeSelect.closest('[data-dispensing-row]'));
    });
});

document.addEventListener('click', (event) => {
    const addButton = event.target.closest('.js-add-dispensing-row');
    if (addButton) {
        const section = addButton.closest('section');
        const list = section?.querySelector('[data-dispensing-list]');
        const firstRow = list?.querySelector('[data-dispensing-row]');
        if (!list || !firstRow) return;

        const clone = firstRow.cloneNode(true);
        clone.querySelectorAll('select, input').forEach((field) => {
            field.value = '';
            if (field.classList.contains('js-dispensing-type')) field.value = 'Medicine';
        });
        list.appendChild(clone);
        syncDispensingRow(clone);
        updateDispensingRemoveButtons(list);
    }

    const removeButton = event.target.closest('.js-remove-dispensing-row');
    if (removeButton) {
        const row = removeButton.closest('[data-dispensing-row]');
        const list = row?.closest('[data-dispensing-list]');
        if (!row || !list || list.querySelectorAll('[data-dispensing-row]').length <= 1) return;
        row.remove();
        updateDispensingRemoveButtons(list);
    }
});
</script>

<?php render_footer(); ?>
