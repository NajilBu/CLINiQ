<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqVisitWorkflow.php';
require_login();

$user = current_user();
if (!in_array($user['role'] ?? '', ['admin', 'doctor', 'nurse'], true)) {
    render_header('Emergency Visit');
    ?>
    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold">
        Emergency visit logging is available to doctors, nurses, and administrators only.
    </div>
    <?php
    render_footer();
    exit;
}

$search = trim($_GET['q'] ?? '');
$patientOptions = $search !== '' ? cliniq_visit_patients($search, 30) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = (int) ($_POST['patient_id'] ?? 0);

    if (!$patientId) {
        $identifier = trim($_POST['identifier'] ?? '');
        if ($identifier === '') {
            flash_message('error', 'Select an existing patient or enter an ID listed in Cliniq_db.');
            header('Location: emergency_create.php');
            exit;
        }

        if (!is_valid_id_number($identifier)) {
            flash_message('error', id_number_validation_message());
            header('Location: emergency_create.php');
            exit;
        }

        $existing = cliniq_visit_patient_by_id_number($identifier);
        $patientId = (int) ($existing['person_id'] ?? 0);

        if (!$patientId) {
            flash_message('error', 'This ID is not listed as a patient in Cliniq_db. Create the inactive patient account first.');
            header('Location: emergency_create.php');
            exit;
        }
    }

    $management = trim($_POST['management_treatment'] ?? '');
    $referralType = trim($_POST['referral_type'] ?? '');
    if ($referralType === 'None') {
        $referralType = '';
    }

    $staffPersonId = cliniq_visit_staff_person_id($user);
    $visitId = cliniq_visit_create([
        'patient_person_id' => $patientId,
        'chief_complaint' => trim($_POST['chief_complaint'] ?? ''),
        'status' => cliniq_visit_status($_POST['status'] ?? 'Active', 'Active'),
        'visit_purpose' => 'Emergency',
        'visit_source' => 'Nurse Emergency',
        'action_taken' => $management ?: 'Emergency visit recorded by nurse.',
        'recorded_by_person_id' => $staffPersonId,
        'attended_by_person_id' => $staffPersonId,
    ], [
        'symptoms' => trim($_POST['symptoms'] ?? ''),
        'diagnosis' => trim($_POST['diagnosis'] ?? ''),
        'treatment' => $management,
        'referral' => $referralType,
        'remarks' => trim($_POST['remarks'] ?? ''),
    ], [
        'temperature' => $_POST['temperature'] ?? '',
        'blood_pressure' => trim($_POST['blood_pressure'] ?? ''),
        'pulse_rate' => $_POST['pulse_rate'] ?? '',
    ]);

    flash_message('success', 'Emergency visit recorded and opened for treatment.');
    header('Location: view.php?id=' . $visitId);
    exit;
}

set_page_back_link('index.php', 'Logbook');
render_header('Emergency Visit');
?>

<?php render_clinic_command_header(
    'Nurse Emergency Flow',
    'Record Emergency Visit',
    'Use this when the patient cannot complete the public logbook process.'
); ?>

<section class="clinic-card p-6 md:p-8">
    <form method="get" class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_auto] gap-3 mb-6 pb-6 border-b border-slate-100">
        <div class="search-input-wrap">
            <span class="search-icon material-symbols-outlined">search</span>
            <input class="search-input" name="q" value="<?= e($search) ?>" placeholder="Search existing patient by name or ID...">
        </div>
        <button class="btn btn-outline">
            <span class="material-symbols-outlined text-[18px]">person_search</span>
            Search Patient
        </button>
    </form>

    <form method="post" class="space-y-6">
        <div>
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-4">Patient</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2">
                    <label class="clinic-label">Existing Patient</label>
                    <select class="clinic-select" name="patient_id">
                        <option value="">Use an existing patient ID below</option>
                        <?php foreach ($patientOptions as $patient): ?>
                            <?php $name = trim($patient['last_name'] . ', ' . $patient['first_name']); ?>
                            <option value="<?= (int) $patient['id'] ?>"><?= e($name . ' - ' . $patient['id_number'] . ' - ' . ($patient['course_section'] ?: 'No section')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($search !== '' && !$patientOptions): ?>
                        <p class="text-xs font-bold text-amber-600 mt-2 mb-0">No matching patient was found in Cliniq_db. Create the inactive patient account first.</p>
                    <?php endif; ?>
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Existing Student / Faculty / Personnel ID</label>
                    <input class="clinic-input" name="identifier" value="<?= e($search) ?>" placeholder="Enter ID number" data-id-number-format>
                </div>
            </div>
        </div>

        <div>
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-4">Emergency Visit And Treatment</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="clinic-label">Chief Complaint</label>
                    <input class="clinic-input" name="chief_complaint" required placeholder="Example: Fainting, injury, asthma attack">
                </div>
                <div>
                    <label class="clinic-label">Status</label>
                    <select class="clinic-select" name="status">
                        <?php foreach (array_filter(visit_statuses(), fn($status) => $status !== 'Unaddressed') as $status): ?>
                            <option value="<?= e($status) ?>"><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Symptoms / Situation</label>
                    <textarea class="clinic-textarea" name="symptoms" rows="3" placeholder="What happened? What symptoms are present?"></textarea>
                </div>
                <div>
                    <label class="clinic-label">Temperature (C)</label>
                    <input class="clinic-input" name="temperature" type="number" step="0.1" placeholder="0">
                </div>
                <div>
                    <label class="clinic-label">Blood Pressure</label>
                    <input class="clinic-input" name="blood_pressure" placeholder="0">
                </div>
                <div>
                    <label class="clinic-label">Pulse Rate (bpm)</label>
                    <input class="clinic-input" name="pulse_rate" type="number" placeholder="0">
                </div>
                <div>
                    <label class="clinic-label">Referral</label>
                    <select class="clinic-select" name="referral_type">
                        <?php foreach (visit_referral_options() as $option): ?>
                            <option value="<?= e($option) ?>"><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Diagnosis</label>
                    <textarea class="clinic-textarea" name="diagnosis" rows="4" placeholder="Clinical impression or diagnosis..."></textarea>
                </div>
                <div>
                    <label class="clinic-label">Management / Treatment</label>
                    <textarea class="clinic-textarea" name="management_treatment" rows="4" placeholder="Immediate treatment, medication, monitoring, advice..."></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Remarks</label>
                    <textarea class="clinic-textarea" name="remarks" rows="3" placeholder="Guardian contacted, transfer notes, follow-up instruction..."></textarea>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-3 pt-2">
            <button class="btn btn-danger" data-confirm-submit data-confirm-type="danger" data-confirm-title="Save this emergency visit?" data-confirm-message="This will create or match the patient record and save an active emergency visit." data-confirm-toast="Saving emergency visit...">
                <span class="material-symbols-outlined text-[18px]">emergency</span>
                Save Emergency Visit
            </button>
            <a class="btn btn-ghost btn-cancel-icon text-decoration-none" href="index.php" title="Cancel" aria-label="Cancel">
                <span class="material-symbols-outlined">cancel</span>
            </a>
        </div>
    </form>
</section>

<?php render_footer(); ?>
