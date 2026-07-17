<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$patient = $stmt->fetch();

if (!$patient) {
    flash_message('error', 'Patient not found.');
    header('Location: index.php');
    exit;
}

$programOptions = dropdown_options('student_program');
$sectionOptions = dropdown_options('student_section');
$currentProgram = trim((string) ($patient['course_section'] ?? ''));
$currentSection = '';
if (preg_match('/^(.+)\s+([A-E])$/i', $currentProgram, $matches)) {
    $currentProgram = trim($matches[1]);
    $currentSection = strtoupper($matches[2]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentNumber = trim($_POST['student_number'] ?? '');
    if (!is_valid_student_id($studentNumber)) {
        flash_message('error', student_id_format_message('Student number'));
        header('Location: edit.php?id=' . $id);
        exit;
    }

    $program = trim((string) ($_POST['student_program'] ?? ''));
    $section = strtoupper(trim((string) ($_POST['student_section'] ?? '')));
    $courseSection = trim(implode(' ', array_filter([$program, $section])));

    $stmt = db()->prepare(
        'UPDATE patients SET student_number=?, first_name=?, middle_name=?, last_name=?, birthdate=?, sex=?, course_section=?, blood_type=?, allergies=?, existing_conditions=?, emergency_instructions=?, guardian_name=?, guardian_contact=? WHERE id=?'
    );
    $stmt->execute([
        $studentNumber,
        trim($_POST['first_name'] ?? ''),
        trim($_POST['middle_name'] ?? ''),
        trim($_POST['last_name'] ?? ''),
        $_POST['birthdate'] ?: null,
        $_POST['sex'] ?: null,
        $courseSection,
        trim($_POST['blood_type'] ?? ''),
        trim($_POST['allergies'] ?? ''),
        trim($_POST['existing_conditions'] ?? ''),
        trim($_POST['emergency_instructions'] ?? ''),
        trim($_POST['guardian_name'] ?? ''),
        trim($_POST['guardian_contact'] ?? ''),
        $id,
    ]);

    flash_message('success', 'Patient record updated successfully.');
    header('Location: view.php?id=' . $id);
    exit;
}

set_page_back_link('view.php?id=' . $id, 'Profile');
render_header('Edit Patient');
?>
<?php render_clinic_command_header(
    'Patient Registry',
    'Edit Patient',
    'Update the profile for ' . $patient['first_name'] . ' ' . $patient['last_name'] . '.'
); ?>

<form class="clinic-card p-6 md:p-8" method="post">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div>
            <label class="clinic-label">Student Number</label>
            <input class="clinic-input" name="student_number" value="<?= e($patient['student_number']) ?>" placeholder="<?= e(STUDENT_ID_FORMAT_LABEL) ?>" data-student-id-format required>
        </div>
        <div>
            <label class="clinic-label">First Name</label>
            <input class="clinic-input" name="first_name" value="<?= e($patient['first_name']) ?>" required>
        </div>
        <div>
            <label class="clinic-label">Middle Name</label>
            <input class="clinic-input" name="middle_name" value="<?= e($patient['middle_name']) ?>">
        </div>
        <div>
            <label class="clinic-label">Last Name</label>
            <input class="clinic-input" name="last_name" value="<?= e($patient['last_name']) ?>" required>
        </div>
        <div>
            <label class="clinic-label">Birthdate</label>
            <input class="clinic-input" name="birthdate" type="date" value="<?= e($patient['birthdate']) ?>">
        </div>
        <div>
            <label class="clinic-label">Sex</label>
            <select class="clinic-select" name="sex">
                <option value="">Select</option>
                <option <?= $patient['sex'] === 'Male' ? 'selected' : '' ?>>Male</option>
                <option <?= $patient['sex'] === 'Female' ? 'selected' : '' ?>>Female</option>
                <option <?= $patient['sex'] === 'Other' ? 'selected' : '' ?>>Other</option>
            </select>
        </div>
        <div>
            <label class="clinic-label">Program</label>
            <select class="clinic-select" name="student_program">
                <option value="">Select program</option>
                <?php foreach ($programOptions as $program): ?>
                    <option value="<?= e($program) ?>" <?= $currentProgram === $program ? 'selected' : '' ?>><?= e($program) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="clinic-label">Section</label>
            <select class="clinic-select" name="student_section">
                <option value="">Select section</option>
                <?php foreach ($sectionOptions as $section): ?>
                    <option value="<?= e($section) ?>" <?= $currentSection === strtoupper($section) ? 'selected' : '' ?>><?= e($section) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="clinic-label">Blood Type</label>
            <input class="clinic-input" name="blood_type" value="<?= e($patient['blood_type']) ?>">
        </div>
        <div>
            <label class="clinic-label">Guardian Name</label>
            <input class="clinic-input" name="guardian_name" value="<?= e($patient['guardian_name']) ?>">
        </div>
        <div>
            <label class="clinic-label">Guardian Contact</label>
            <input class="clinic-input" name="guardian_contact" value="<?= e($patient['guardian_contact']) ?>">
        </div>
        <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="clinic-label">Allergies</label>
                <textarea class="clinic-textarea" name="allergies" rows="3"><?= e($patient['allergies']) ?></textarea>
            </div>
            <div>
                <label class="clinic-label">Existing Conditions</label>
                <textarea class="clinic-textarea" name="existing_conditions" rows="3"><?= e($patient['existing_conditions']) ?></textarea>
            </div>
        </div>
        <div class="md:col-span-3">
            <label class="clinic-label">Emergency Instructions</label>
            <textarea class="clinic-textarea" name="emergency_instructions" rows="3"><?= e($patient['emergency_instructions']) ?></textarea>
        </div>
    </div>
    <div class="mt-6 flex flex-wrap gap-3">
        <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save patient changes?" data-confirm-message="This will update the patient profile information." data-confirm-toast="Saving patient changes...">
            <span class="material-symbols-outlined text-[18px]">save</span> Save Changes
        </button>
        <a class="btn btn-ghost btn-cancel-icon text-decoration-none" href="view.php?id=<?= $id ?>" title="Cancel" aria-label="Cancel">
            <span class="material-symbols-outlined">cancel</span>
        </a>
    </div>
</form>
<?php render_footer(); ?>
