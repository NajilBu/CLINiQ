<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentNumber = trim($_POST['student_number'] ?? '');
    if (!preg_match('/^\d{2}-\d{5}$/', $studentNumber)) {
        flash_message('error', 'Student number must use the format 00-00000.');
        header('Location: create.php');
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO patients (student_number, first_name, middle_name, last_name, birthdate, sex, course_section, blood_type, allergies, existing_conditions, emergency_instructions, guardian_name, guardian_contact, emergency_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $studentNumber,
        trim($_POST['first_name'] ?? ''),
        trim($_POST['middle_name'] ?? ''),
        trim($_POST['last_name'] ?? ''),
        $_POST['birthdate'] ?: null,
        $_POST['sex'] ?: null,
        trim($_POST['course_section'] ?? ''),
        trim($_POST['blood_type'] ?? ''),
        trim($_POST['allergies'] ?? ''),
        trim($_POST['existing_conditions'] ?? ''),
        trim($_POST['emergency_instructions'] ?? ''),
        trim($_POST['guardian_name'] ?? ''),
        trim($_POST['guardian_contact'] ?? ''),
        $token,
    ]);

    flash_message('success', 'Patient "' . trim($_POST['first_name'] . ' ' . $_POST['last_name']) . '" registered successfully.');
    header('Location: index.php');
    exit;
}

set_page_back_link('index.php', 'Patients');
render_header('Add Patient');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Add Patient</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Create a patient profile and QR/NFC emergency tag reference.</p>
    </div>
</div>

<form class="clinic-card p-6 md:p-8" method="post">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div>
            <label class="clinic-label">Student Number</label>
            <input class="clinic-input" name="student_number" placeholder="00-00000" data-student-id-format required>
        </div>
        <div>
            <label class="clinic-label">First Name</label>
            <input class="clinic-input" name="first_name" required>
        </div>
        <div>
            <label class="clinic-label">Middle Name</label>
            <input class="clinic-input" name="middle_name">
        </div>
        <div>
            <label class="clinic-label">Last Name</label>
            <input class="clinic-input" name="last_name" required>
        </div>
        <div>
            <label class="clinic-label">Birthdate</label>
            <input class="clinic-input" name="birthdate" type="date">
        </div>
        <div>
            <label class="clinic-label">Sex</label>
            <select class="clinic-select" name="sex">
                <option value="">Select</option>
                <option>Male</option>
                <option>Female</option>
                <option>Other</option>
            </select>
        </div>
        <div>
            <label class="clinic-label">Course/Section</label>
            <input class="clinic-input" name="course_section">
        </div>
        <div>
            <label class="clinic-label">Blood Type</label>
            <input class="clinic-input" name="blood_type">
        </div>
        <div>
            <label class="clinic-label">Guardian Name</label>
            <input class="clinic-input" name="guardian_name">
        </div>
        <div>
            <label class="clinic-label">Guardian Contact</label>
            <input class="clinic-input" name="guardian_contact">
        </div>
        <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="clinic-label">Allergies</label>
                <textarea class="clinic-textarea" name="allergies" rows="3"></textarea>
            </div>
            <div>
                <label class="clinic-label">Existing Conditions</label>
                <textarea class="clinic-textarea" name="existing_conditions" rows="3"></textarea>
            </div>
        </div>
        <div class="md:col-span-3">
            <label class="clinic-label">Emergency Instructions</label>
            <textarea class="clinic-textarea" name="emergency_instructions" rows="3"></textarea>
        </div>
    </div>
    <div class="mt-6 flex flex-wrap gap-3">
        <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save this patient?" data-confirm-message="This will create a new patient record." data-confirm-toast="Saving patient...">
            <span class="material-symbols-outlined text-[18px]">person_add</span> Save Patient
        </button>
        <a class="btn btn-ghost btn-cancel-icon text-decoration-none" href="index.php" title="Cancel" aria-label="Cancel">
            <span class="material-symbols-outlined">cancel</span>
        </a>
    </div>
</form>
<?php render_footer(); ?>
