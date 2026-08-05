<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqPatientProfile.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$patient = cliniq_patient_profile_find($id);
if (!$patient) {
    flash_message('error', 'Patient not found in Cliniq_db.');
    header('Location: index.php');
    exit;
}

$programOptions = cliniq_patient_profile_programs();
$departmentOptions = cliniq_patient_profile_departments();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedIdNumber = strtoupper(trim((string) ($_POST['id_number'] ?? '')));
        if (!is_valid_id_number($postedIdNumber)) {
            throw new InvalidArgumentException(id_number_validation_message('ID number'));
        }
        $patient = cliniq_patient_profile_update($id, array_merge($_POST, [
            'id_number' => $postedIdNumber,
        ]));
        flash_message('success', 'Patient profile updated in Cliniq_db.');
        header('Location: view.php?id=' . $id);
        exit;
    } catch (Throwable $e) {
        flash_message($e instanceof InvalidArgumentException ? 'warning' : 'error', $e->getMessage());
        header('Location: edit.php?id=' . $id);
        exit;
    }
}

set_page_back_link('view.php?id=' . $id, 'Profile');
render_header('Edit Patient');
render_clinic_command_header(
    'Patient Registry',
    'Edit Patient',
    'Update the Cliniq_db profile for ' . $patient['first_name'] . ' ' . $patient['last_name'] . '.'
);
?>

<form class="clinic-card p-6 md:p-8" method="post">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div>
            <label class="clinic-label">ID Number</label>
            <input class="clinic-input uppercase" name="id_number" value="<?= e($patient['id_number']) ?>" placeholder="<?= e(ID_NUMBER_FORMAT_LABEL) ?>" data-id-number-format required>
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
                <?php foreach (['Male', 'Female', 'Other'] as $sex): ?>
                    <option value="<?= e($sex) ?>" <?= $patient['sex'] === $sex ? 'selected' : '' ?>><?= e($sex) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($patient['patient_type'] === 'Student'): ?>
            <div>
                <label class="clinic-label">Program</label>
                <select class="clinic-select" name="program_id" required>
                    <option value="">Select program</option>
                    <?php foreach ($programOptions as $program): ?>
                        <option value="<?= (int) $program['id'] ?>" <?= (int) $patient['program_id'] === (int) $program['id'] ? 'selected' : '' ?>>
                            <?= e($program['code'] . ' — ' . $program['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="clinic-label">Year Level</label>
                <select class="clinic-select" name="year_level" required>
                    <?php foreach (['1', '2', '3', '4'] as $yearLevel): ?>
                        <option value="<?= $yearLevel ?>" <?= (string) $patient['year_level'] === $yearLevel ? 'selected' : '' ?>>Year <?= $yearLevel ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="clinic-label">Section Code</label>
                <select class="clinic-select" name="section" required>
                    <?php foreach (['A', 'B', 'C', 'D', 'E'] as $section): ?>
                        <option value="<?= $section ?>" <?= strtoupper((string) $patient['section']) === $section ? 'selected' : '' ?>><?= $section ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="clinic-label">Academic Year</label>
                <input class="clinic-input" name="academic_year" value="<?= e($patient['academic_year']) ?>" placeholder="e.g. 2026-2027">
            </div>
        <?php elseif (in_array($patient['patient_type'], ['Faculty', 'School Personnel'], true)): ?>
            <div>
                <label class="clinic-label">Department</label>
                <select class="clinic-select" name="department_id" required>
                    <option value="">Select department</option>
                    <?php foreach ($departmentOptions as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= (int) $patient['employee_department_id'] === (int) $department['id'] ? 'selected' : '' ?>>
                            <?= e($department['code'] . ' — ' . $department['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="clinic-label">Employment Type</label>
                <select class="clinic-select" name="employment_type" required>
                    <?php foreach (['Full-time', 'Part-time'] as $employmentType): ?>
                        <option value="<?= e($employmentType) ?>" <?= $patient['employment_type'] === $employmentType ? 'selected' : '' ?>><?= e($employmentType) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="clinic-label">Position Title</label>
                <input class="clinic-input" name="position_title" value="<?= e($patient['employee_position_title']) ?>" placeholder="e.g. DIT or MIT">
            </div>
        <?php elseif ($patient['patient_type'] === 'Clinic Staff'): ?>
            <div>
                <label class="clinic-label">Department</label>
                <select class="clinic-select" name="department_id">
                    <option value="">Select department</option>
                    <?php foreach ($departmentOptions as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= (int) $patient['staff_department_id'] === (int) $department['id'] ? 'selected' : '' ?>>
                            <?= e($department['code'] . ' — ' . $department['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="clinic-label">Staff Role</label>
                <input class="clinic-input" value="<?= e(ucwords(str_replace('_', ' ', (string) $patient['staff_role']))) ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Position Title</label>
                <input class="clinic-input" name="position_title" value="<?= e($patient['staff_position_title']) ?>">
            </div>
        <?php endif; ?>

        <div>
            <label class="clinic-label">Blood Type</label>
            <input class="clinic-input uppercase" name="blood_type" maxlength="10" value="<?= e($patient['blood_type']) ?>">
        </div>
        <div>
            <label class="clinic-label">Guardian / Contact Name</label>
            <input class="clinic-input" name="guardian_name" value="<?= e($patient['guardian_name']) ?>">
        </div>
        <div>
            <label class="clinic-label">Guardian / Contact Number</label>
            <input class="clinic-input" name="guardian_contact" value="<?= e($patient['guardian_contact']) ?>">
        </div>
        <div class="md:col-span-3">
            <label class="clinic-label">Emergency Instructions</label>
            <textarea class="clinic-textarea" name="emergency_instructions" rows="3"><?= e($patient['emergency_instructions']) ?></textarea>
        </div>
    </div>
    <div class="mt-6 flex flex-wrap gap-3">
        <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save patient changes?" data-confirm-message="This will update the patient profile in Cliniq_db." data-confirm-toast="Saving patient changes...">
            <span class="material-symbols-outlined text-[18px]">save</span> Save Changes
        </button>
        <a class="btn btn-ghost btn-cancel-icon text-decoration-none" href="view.php?id=<?= $id ?>" title="Cancel" aria-label="Cancel">
            <span class="material-symbols-outlined">cancel</span>
        </a>
    </div>
</form>

<?php render_footer(); ?>
