<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/VisitWorkflow.php';
require_login();
ensure_visit_workflow_schema();

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

function split_emergency_patient_name(string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName));
    if (!$parts || count($parts) === 1) {
        return [$fullName, 'Emergency Patient'];
    }

    $lastName = array_pop($parts);
    return [implode(' ', $parts), $lastName];
}

$search = trim($_GET['q'] ?? '');
$patientOptions = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = db()->prepare("
        SELECT id, student_number, first_name, last_name, course_section
        FROM patients
        WHERE first_name LIKE ? OR last_name LIKE ? OR student_number LIKE ?
        ORDER BY last_name, first_name
        LIMIT 30
    ");
    $stmt->execute([$like, $like, $like]);
    $patientOptions = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = (int) ($_POST['patient_id'] ?? 0);

    if (!$patientId) {
        $identifier = trim($_POST['identifier'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $category = trim($_POST['category'] ?? 'Student');
        $department = trim($_POST['department'] ?? '');

        if ($identifier === '' || $fullName === '') {
            flash_message('error', 'Provide an existing patient or enter a patient name and ID.');
            header('Location: emergency_create.php');
            exit;
        }

        if ($category === 'Student' && !is_valid_student_id($identifier)) {
            flash_message('error', student_id_format_message());
            header('Location: emergency_create.php');
            exit;
        }

        $existing = db()->prepare('SELECT id FROM patients WHERE student_number = ? LIMIT 1');
        $existing->execute([$identifier]);
        $patientId = (int) $existing->fetchColumn();

        if (!$patientId) {
            [$firstName, $lastName] = split_emergency_patient_name($fullName);
            $courseSection = trim(implode(' - ', array_filter([$category, $department])));
            $token = hash('sha256', 'emergency-' . $identifier . '-' . microtime(true));

            $insertPatient = db()->prepare(
                'INSERT INTO patients (student_number, first_name, last_name, course_section, emergency_token)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertPatient->execute([$identifier, $firstName, $lastName, $courseSection ?: null, $token]);
            $patientId = (int) db()->lastInsertId();
        }
    }

    $management = trim($_POST['management_treatment'] ?? '');
    $referralType = trim($_POST['referral_type'] ?? '');
    if ($referralType === 'None') {
        $referralType = '';
    }

    $visitStmt = db()->prepare(
        'INSERT INTO clinic_visits (patient_id, visit_datetime, chief_complaint, symptoms, temperature, blood_pressure, pulse_rate, status, visit_purpose, visit_source, action_taken, recorded_by, attended_by)
         VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $visitStmt->execute([
        $patientId,
        trim($_POST['chief_complaint'] ?? ''),
        trim($_POST['symptoms'] ?? ''),
        $_POST['temperature'] ?: null,
        trim($_POST['blood_pressure'] ?? ''),
        $_POST['pulse_rate'] ?: null,
        normalize_visit_status($_POST['status'] ?? 'Active', 'Active'),
        'Emergency',
        'Nurse Emergency',
        $management ?: 'Emergency visit recorded by nurse.',
        $user['id'],
        $user['id'],
    ]);
    $visitId = (int) db()->lastInsertId();

    $entry = [
        'symptoms_note' => trim($_POST['symptoms'] ?? ''),
        'diagnosis' => trim($_POST['diagnosis'] ?? ''),
        'management_treatment' => $management,
        'referral_type' => $referralType,
        'remarks' => trim($_POST['remarks'] ?? ''),
    ];

    if (treatment_entry_has_content($entry)) {
        $treatmentStmt = db()->prepare(
            'INSERT INTO visit_treatment_entries (visit_id, symptoms_note, diagnosis, management_treatment, referral_type, remarks, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $treatmentStmt->execute([
            $visitId,
            $entry['symptoms_note'] ?: null,
            $entry['diagnosis'] ?: null,
            $entry['management_treatment'] ?: null,
            $entry['referral_type'] ?: null,
            $entry['remarks'] ?: null,
            $user['id'],
        ]);
    }

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
                        <option value="">Create minimal emergency patient instead</option>
                        <?php foreach ($patientOptions as $patient): ?>
                            <?php $name = trim($patient['last_name'] . ', ' . $patient['first_name']); ?>
                            <option value="<?= (int) $patient['id'] ?>"><?= e($name . ' - ' . $patient['student_number'] . ' - ' . ($patient['course_section'] ?: 'No section')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($search !== '' && !$patientOptions): ?>
                        <p class="text-xs font-bold text-amber-600 mt-2 mb-0">No matching patient found. Complete the minimal patient fields below.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="clinic-label">Full Name for New Emergency Patient</label>
                    <input class="clinic-input" name="full_name" placeholder="Required only if no existing patient is selected">
                </div>
                <div>
                    <label class="clinic-label">Student / Staff ID</label>
                    <input class="clinic-input" name="identifier" value="<?= e($search) ?>" placeholder="00-00000" data-student-id-format data-student-category-source="emergencyPatientCategory">
                </div>
                <div>
                    <label class="clinic-label">Category</label>
                    <select class="clinic-select" name="category" id="emergencyPatientCategory">
                        <?php foreach (dropdown_options('person_category') as $category): ?>
                            <option value="<?= e($category) ?>"><?= e($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="clinic-label">Course / Department</label>
                    <input class="clinic-input" name="department" placeholder="Example: College of Nursing">
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
