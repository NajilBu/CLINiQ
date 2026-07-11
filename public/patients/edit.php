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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = db()->prepare(
        'UPDATE patients SET student_number=?, first_name=?, middle_name=?, last_name=?, birthdate=?, sex=?, course_section=?, blood_type=?, allergies=?, existing_conditions=?, emergency_instructions=?, guardian_name=?, guardian_contact=? WHERE id=?'
    );
    $stmt->execute([
        trim($_POST['student_number'] ?? ''),
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
        $id,
    ]);

    flash_message('success', 'Patient record updated successfully.');
    header('Location: view.php?id=' . $id);
    exit;
}

set_page_back_link('view.php?id=' . $id, 'Profile');
render_header('Edit Patient');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Edit Patient</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Update the profile for <?= e($patient['first_name'] . ' ' . $patient['last_name']) ?>.</p>
    </div>
</div>

<form class="clinic-card p-6 md:p-8" method="post">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div>
            <label class="clinic-label">Student Number</label>
            <input class="clinic-input" name="student_number" value="<?= e($patient['student_number']) ?>" required>
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
            <label class="clinic-label">Course/Section</label>
            <input class="clinic-input" name="course_section" value="<?= e($patient['course_section']) ?>">
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
