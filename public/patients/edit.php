<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/CliniqPatientProfile.php';
require_once __DIR__ . '/../../app/services/PatientAccountService.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$patient = cliniq_patient_profile_find($id);
if (!$patient) {
    flash_message('error', 'Patient not found.');
    header('Location: index.php');
    exit;
}

$programOptions = cliniq_patient_profile_programs();
$departmentOptions = cliniq_patient_profile_departments();
$relationshipOptions = dropdown_options('guardian_relationship');
$user = current_user() ?? [];
$canManageAccount = can_manage_patient_accounts($user);
$canUpdatePortalEmail = $canManageAccount && (int) ($patient['account_id'] ?? 0) > 0;
$hasEmergencyContact = trim((string) ($patient['guardian_name'] ?? '')) !== ''
    || trim((string) ($patient['guardian_contact'] ?? '')) !== ''
    || trim((string) ($patient['secondary_contact'] ?? '')) !== '';
$defaultGuardianRelationship = $hasEmergencyContact ? ((string) ($patient['guardian_relationship'] ?: 'Guardian')) : '';
$editableFields = ['id_number', 'first_name', 'middle_name', 'last_name', 'birthdate', 'sex', 'program_id', 'year_level', 'section', 'academic_year', 'department_id', 'employment_type', 'position_title', 'blood_type', 'guardian_name', 'guardian_relationship', 'guardian_contact', 'secondary_contact', 'emergency_instructions', 'email'];
$editValues = is_array($_SESSION['patient_profile_edit_values'] ?? null) ? $_SESSION['patient_profile_edit_values'] : [];
$editErrors = is_array($_SESSION['patient_profile_edit_errors'] ?? null) ? $_SESSION['patient_profile_edit_errors'] : [];
unset($_SESSION['patient_profile_edit_values'], $_SESSION['patient_profile_edit_errors']);
$formValue = static fn(string $field, mixed $fallback = ''): string => (string) ($editValues[$field] ?? $fallback);
$fieldError = static fn(string $field): string => (string) ($editErrors[$field] ?? '');
$fieldInvalid = static fn(string $field): string => $fieldError($field) !== '' ? ' aria-invalid="true" aria-describedby="' . e($field) . '-error"' : '';
$renderFieldError = static function (string $field) use ($fieldError): string {
    $message = $fieldError($field);
    return $message === '' ? '' : '<p id="' . e($field) . '-error" class="mt-1 text-xs font-bold text-red-700" role="alert">' . e($message) . '</p>';
};
$errorFields = static function (Throwable $e): array {
    $message = strtolower($e->getMessage());
    return match (true) {
        str_contains($message, 'first name') && str_contains($message, 'last name') => ['id_number' => $e->getMessage(), 'first_name' => $e->getMessage(), 'last_name' => $e->getMessage()],
        str_contains($message, 'email') => ['email' => $e->getMessage()],
        str_contains($message, 'school year') => ['academic_year' => $e->getMessage()],
        str_contains($message, 'guardian relationship') => ['guardian_relationship' => $e->getMessage()],
        str_contains($message, 'secondary contact') => ['secondary_contact' => $e->getMessage()],
        str_contains($message, 'mobile number') => ['guardian_contact' => $e->getMessage()],
        str_contains($message, 'guardian') || str_contains($message, 'contact') => ['guardian_name' => $e->getMessage(), 'guardian_contact' => $e->getMessage()],
        str_contains($message, 'birthdate') => ['birthdate' => $e->getMessage()],
        str_contains($message, 'sex') => ['sex' => $e->getMessage()],
        str_contains($message, 'blood type') => ['blood_type' => $e->getMessage()],
        str_contains($message, 'program') => ['program_id' => $e->getMessage()],
        str_contains($message, 'year level') => ['year_level' => $e->getMessage()],
        str_contains($message, 'section') => ['section' => $e->getMessage()],
        str_contains($message, 'department') => ['department_id' => $e->getMessage()],
        str_contains($message, 'employment type') => ['employment_type' => $e->getMessage()],
        str_contains($message, 'id number') => ['id_number' => $e->getMessage()],
        default => [],
    };
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedIdNumber = normalize_id_number(strtoupper(trim((string) ($_POST['id_number'] ?? ''))));
        if (!is_valid_id_number($postedIdNumber)) {
            throw new InvalidArgumentException(id_number_validation_message('ID number'));
        }
        $patient = cliniq_patient_profile_update($id, array_merge($_POST, [
            'id_number' => $postedIdNumber,
        ]), (int) ($user['person_id'] ?? $user['id'] ?? 0) ?: null, $canUpdatePortalEmail);
        flash_message('success', 'Patient profile updated.');
        header('Location: view.php?id=' . $id);
        exit;
    } catch (Throwable $e) {
        $_SESSION['patient_profile_edit_values'] = array_intersect_key($_POST, array_flip($editableFields));
        $_SESSION['patient_profile_edit_errors'] = $errorFields($e);
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
    'Update the profile for ' . $patient['first_name'] . ' ' . $patient['last_name'] . '.'
);
?>

<form class="clinic-card p-6 md:p-8" method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($editErrors !== []): ?>
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" tabindex="-1">
            <strong class="block font-extrabold">Review the highlighted fields before saving.</strong>
        </div>
    <?php endif; ?>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div>
            <label class="clinic-label">ID Number</label>
            <input class="clinic-input uppercase" name="id_number" value="<?= e($formValue('id_number', $patient['id_number'])) ?>" placeholder="<?= e(ID_NUMBER_FORMAT_LABEL) ?>" data-id-number-format required<?= $fieldInvalid('id_number') ?>>
            <?= $renderFieldError('id_number') ?>
        </div>
        <div>
            <label class="clinic-label">First Name</label>
            <input class="clinic-input" name="first_name" value="<?= e($formValue('first_name', $patient['first_name'])) ?>" required<?= $fieldInvalid('first_name') ?>>
            <?= $renderFieldError('first_name') ?>
        </div>
        <div>
            <label class="clinic-label">Middle Name</label>
            <input class="clinic-input" name="middle_name" value="<?= e($formValue('middle_name', $patient['middle_name'])) ?>">
        </div>
        <div>
            <label class="clinic-label">Last Name</label>
            <input class="clinic-input" name="last_name" value="<?= e($formValue('last_name', $patient['last_name'])) ?>" required<?= $fieldInvalid('last_name') ?>>
            <?= $renderFieldError('last_name') ?>
        </div>
        <div>
            <label class="clinic-label">Birthdate</label>
            <input class="clinic-input" name="birthdate" type="date" value="<?= e($formValue('birthdate', $patient['birthdate'])) ?>"<?= $fieldInvalid('birthdate') ?>>
            <?= $renderFieldError('birthdate') ?>
        </div>
        <div>
            <label class="clinic-label">Sex</label>
            <select class="clinic-select" name="sex"<?= $fieldInvalid('sex') ?>>
                <option value="">Select</option>
                <?php foreach (['Male', 'Female', 'Other'] as $sex): ?>
                    <option value="<?= e($sex) ?>" <?= $formValue('sex', $patient['sex']) === $sex ? 'selected' : '' ?>><?= e($sex) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $renderFieldError('sex') ?>
        </div>

        <?php if ($patient['patient_type'] === 'Student'): ?>
            <div>
                <label class="clinic-label">Program</label>
                <select class="clinic-select" name="program_id" required<?= $fieldInvalid('program_id') ?>>
                    <option value="">Select program</option>
                    <?php foreach ($programOptions as $program): ?>
                        <option value="<?= (int) $program['id'] ?>" <?= (int) $formValue('program_id', $patient['program_id']) === (int) $program['id'] ? 'selected' : '' ?>>
                            <?= e($program['code'] . ' — ' . $program['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $renderFieldError('program_id') ?>
            </div>
            <div>
                <label class="clinic-label">Year Level</label>
                <select class="clinic-select" name="year_level" required<?= $fieldInvalid('year_level') ?>>
                    <?php foreach (['1', '2', '3', '4'] as $yearLevel): ?>
                        <option value="<?= $yearLevel ?>" <?= $formValue('year_level', $patient['year_level']) === $yearLevel ? 'selected' : '' ?>>Year <?= $yearLevel ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $renderFieldError('year_level') ?>
            </div>
            <div>
                <label class="clinic-label">Section Code</label>
                <select class="clinic-select" name="section" required<?= $fieldInvalid('section') ?>>
                    <?php foreach (['A', 'B', 'C', 'D', 'E'] as $section): ?>
                        <option value="<?= $section ?>" <?= strtoupper($formValue('section', $patient['section'])) === $section ? 'selected' : '' ?>><?= $section ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $renderFieldError('section') ?>
            </div>
            <div>
                <label class="clinic-label">Academic Year</label>
                <input class="clinic-input" name="academic_year" value="<?= e($formValue('academic_year', $patient['academic_year'])) ?>" placeholder="e.g. 2026-2027" inputmode="numeric" pattern="\d{4}-\d{4}"<?= $fieldInvalid('academic_year') ?>>
                <?= $renderFieldError('academic_year') ?>
            </div>
        <?php elseif (in_array($patient['patient_type'], ['Faculty', 'Non-Teaching Personnel'], true)): ?>
            <div>
                <label class="clinic-label">Department</label>
                <select class="clinic-select" name="department_id" required<?= $fieldInvalid('department_id') ?>>
                    <option value="">Select department</option>
                    <?php foreach ($departmentOptions as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= (int) $formValue('department_id', $patient['employee_department_id']) === (int) $department['id'] ? 'selected' : '' ?>>
                            <?= e($department['code'] . ' — ' . $department['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $renderFieldError('department_id') ?>
            </div>
            <div>
                <label class="clinic-label">Employment Type</label>
                <select class="clinic-select" name="employment_type" required<?= $fieldInvalid('employment_type') ?>>
                    <?php foreach (['Full-time', 'Part-time'] as $employmentType): ?>
                        <option value="<?= e($employmentType) ?>" <?= $formValue('employment_type', $patient['employment_type']) === $employmentType ? 'selected' : '' ?>><?= e($employmentType) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $renderFieldError('employment_type') ?>
            </div>
            <div>
                <label class="clinic-label">Position Title</label>
                <input class="clinic-input" name="position_title" value="<?= e($formValue('position_title', $patient['employee_position_title'])) ?>" placeholder="e.g. DIT or MIT">
            </div>
        <?php elseif ($patient['patient_type'] === 'Clinic Staff'): ?>
            <div>
                <label class="clinic-label">Department</label>
                <select class="clinic-select" name="department_id"<?= $fieldInvalid('department_id') ?>>
                    <option value="">Select department</option>
                    <?php foreach ($departmentOptions as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= (int) $formValue('department_id', $patient['staff_department_id']) === (int) $department['id'] ? 'selected' : '' ?>>
                            <?= e($department['code'] . ' — ' . $department['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $renderFieldError('department_id') ?>
            </div>
            <div>
                <label class="clinic-label">Staff Role</label>
                <input class="clinic-input" value="<?= e(ucwords(str_replace('_', ' ', (string) $patient['staff_role']))) ?>" readonly>
            </div>
            <div>
                <label class="clinic-label">Position Title</label>
                <input class="clinic-input" name="position_title" value="<?= e($formValue('position_title', $patient['staff_position_title'])) ?>">
            </div>
        <?php endif; ?>

        <div>
            <label class="clinic-label">Blood Type</label>
            <input class="clinic-input uppercase" name="blood_type" maxlength="10" value="<?= e($formValue('blood_type', $patient['blood_type'])) ?>"<?= $fieldInvalid('blood_type') ?>>
            <?= $renderFieldError('blood_type') ?>
        </div>
        <div>
            <label class="clinic-label">Guardian / Next-of-Kin Name</label>
            <input class="clinic-input" name="guardian_name" value="<?= e($formValue('guardian_name', $patient['guardian_name'])) ?>" autocomplete="name"<?= $fieldInvalid('guardian_name') ?>>
            <?= $renderFieldError('guardian_name') ?>
        </div>
        <div>
            <label class="clinic-label">Relationship</label>
            <select class="clinic-select" name="guardian_relationship"<?= $fieldInvalid('guardian_relationship') ?>>
                <option value="">Select relationship</option>
                <?php foreach ($relationshipOptions as $relationship): ?>
                    <option value="<?= e($relationship) ?>" <?= $formValue('guardian_relationship', $defaultGuardianRelationship) === $relationship ? 'selected' : '' ?>><?= e($relationship) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $renderFieldError('guardian_relationship') ?>
        </div>
        <div>
            <label class="clinic-label">Primary Contact Number</label>
            <input class="clinic-input" name="guardian_contact" type="tel" value="<?= e($formValue('guardian_contact', $patient['guardian_contact'])) ?>" placeholder="+63 9XX XXX XXXX" inputmode="tel" autocomplete="tel"<?= $fieldInvalid('guardian_contact') ?>>
            <?= $renderFieldError('guardian_contact') ?>
        </div>
        <div>
            <label class="clinic-label">Secondary Contact Number</label>
            <input class="clinic-input" name="secondary_contact" type="tel" value="<?= e($formValue('secondary_contact', $patient['secondary_contact'])) ?>" placeholder="Optional" inputmode="tel" autocomplete="tel"<?= $fieldInvalid('secondary_contact') ?>>
            <?= $renderFieldError('secondary_contact') ?>
        </div>
        <?php if ($canUpdatePortalEmail): ?>
            <div>
                <label class="clinic-label">Portal Email</label>
                <input class="clinic-input" name="email" type="email" maxlength="254" value="<?= e($formValue('email', $patient['email'])) ?>" autocomplete="email" required<?= $fieldInvalid('email') ?>>
                <p class="mt-1 text-xs font-semibold text-slate-500">Used for clinic messages and account recovery.</p>
                <?= $renderFieldError('email') ?>
            </div>
        <?php endif; ?>
        <div class="md:col-span-3">
            <label class="clinic-label">Emergency Instructions</label>
            <textarea class="clinic-textarea" name="emergency_instructions" rows="3"><?= e($formValue('emergency_instructions', $patient['emergency_instructions'])) ?></textarea>
        </div>
    </div>
    <div class="mt-6 flex flex-wrap gap-3">
        <button class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save patient changes?" data-confirm-message="This will update the patient profile." data-confirm-toast="Saving patient changes...">
            <span class="material-symbols-outlined text-[18px]">save</span> Save Changes
        </button>
        <a class="btn btn-ghost btn-cancel-icon text-decoration-none" href="view.php?id=<?= $id ?>" title="Cancel" aria-label="Cancel">
            <span class="material-symbols-outlined">cancel</span>
        </a>
    </div>
</form>

<?php render_footer(); ?>
