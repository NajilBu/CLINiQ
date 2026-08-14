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

$personId = (int) $patient['person_id'];
$fullName = trim(implode(' ', array_filter([
    $patient['first_name'],
    $patient['middle_name'],
    $patient['last_name'],
])));
$classificationDetail = match ($patient['patient_type']) {
    'Student' => trim(($patient['program_code'] ?? '') . ' ' . ($patient['year_level'] ?? '') . strtoupper((string) ($patient['section'] ?? ''))),
    'Faculty', 'School Personnel' => trim(($patient['employee_department_code'] ?? '') . ' - ' . ($patient['employee_department_name'] ?? ''), ' -'),
    'Clinic Staff' => trim(($patient['staff_department_code'] ?? '') . ' - ' . ($patient['staff_department_name'] ?? ''), ' -'),
    default => '',
};

set_page_back_link('view.php?id=' . $personId, 'Profile');
render_header('Staff Emergency Profile');
render_clinic_command_header(
    'Staff Only',
    'Emergency Profile',
    'Emergency details stored in Cliniq_db for authorized clinic staff.',
    '<a class="px-5 py-3 bg-slate-100 text-primary rounded-2xl text-sm font-bold text-decoration-none" href="' . e(app_url('patients/view.php?id=' . $personId)) . '">Back to Profile</a>'
);
?>

<section class="clinic-card p-6 md:p-8">
    <div class="flex flex-col md:flex-row items-start justify-between gap-3 mb-6">
        <div>
            <h2 class="font-headline text-2xl font-extrabold text-[#1c2a59] mb-1"><?= e($fullName) ?></h2>
            <p class="text-secondary mb-0">
                <?= e($patient['id_number']) ?>
                <?php if ($classificationDetail !== ''): ?>
                    &bull; <?= e($classificationDetail) ?>
                <?php endif; ?>
            </p>
        </div>
        <span class="px-3 py-1 rounded-full bg-red-50 text-red-700 text-xs font-black">Private Health Data</span>
    </div>

    <dl class="grid grid-cols-1 md:grid-cols-[14rem_1fr] gap-4">
        <dt class="clinic-label">Patient Classification</dt>
        <dd class="text-sm font-bold text-slate-700"><?= e($patient['patient_type']) ?></dd>

        <dt class="clinic-label">Blood Type</dt>
        <dd class="text-sm font-bold text-slate-700"><?= $patient['blood_type'] ? e($patient['blood_type']) : 'Not specified' ?></dd>

        <dt class="clinic-label">Emergency Instructions</dt>
        <dd class="text-sm font-bold text-slate-700"><?= $patient['emergency_instructions'] ? nl2br(e($patient['emergency_instructions'])) : 'None recorded' ?></dd>

        <dt class="clinic-label">Guardian / Contact</dt>
        <dd class="text-sm font-bold text-slate-700">
            <?= $patient['guardian_name'] ? e($patient['guardian_name']) : 'Not specified' ?>
            <?php if ($patient['guardian_contact']): ?>
                &bull; <?= e($patient['guardian_contact']) ?>
            <?php endif; ?>
        </dd>

        <dt class="clinic-label">Account Status</dt>
        <dd class="text-sm font-bold text-slate-700"><?= $patient['account_status'] ? e(ucfirst($patient['account_status'])) : 'No login account' ?></dd>
    </dl>
</section>

<?php render_footer(); ?>
