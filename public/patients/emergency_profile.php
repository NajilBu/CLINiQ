<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$patient = $stmt->fetch();

if ($patient) {
    $log = db()->prepare('INSERT INTO passport_access_logs (patient_id, ip_address, user_agent) VALUES (?, ?, ?)');
    $log->execute([
        $patient['id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr('Staff emergency profile view: ' . ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

render_header('Staff Emergency Profile');
?>
<?php render_clinic_command_header(
    'Staff Only',
    'Emergency Profile',
    'Sensitive emergency health details for authorized clinic staff.',
    '<a class="px-5 py-3 bg-slate-100 text-primary rounded-2xl text-sm font-bold text-decoration-none" href="' . e(app_url('patients/index.php')) . '">Back to Patients</a>'
); ?>

<?php if (!$patient): ?>
    <div class="rounded-2xl bg-red-50 border border-red-100 text-red-700 px-5 py-4 font-bold">Patient not found.</div>
<?php else: ?>
    <section class="clinic-card p-6 md:p-8">
        <div class="flex flex-col md:flex-row items-start justify-between gap-3 mb-6">
            <div>
                <h2 class="font-headline text-2xl font-extrabold text-[#1c2a59] mb-1"><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></h2>
                <p class="text-secondary mb-0"><?= e($patient['student_number']) ?> · <?= e($patient['course_section']) ?></p>
            </div>
            <span class="px-3 py-1 rounded-full bg-red-50 text-red-700 text-xs font-black">Private Health Data</span>
        </div>

        <dl class="grid grid-cols-1 md:grid-cols-[14rem_1fr] gap-4">
            <dt class="clinic-label">Blood Type</dt>
            <dd class="text-sm font-bold text-slate-700"><?= e($patient['blood_type']) ?: 'Not specified' ?></dd>

            <dt class="clinic-label">Critical Allergies</dt>
            <dd class="text-sm font-bold text-slate-700"><?= nl2br(e($patient['allergies'])) ?: 'None recorded' ?></dd>

            <dt class="clinic-label">Existing Conditions</dt>
            <dd class="text-sm font-bold text-slate-700"><?= nl2br(e($patient['existing_conditions'])) ?: 'None recorded' ?></dd>

            <dt class="clinic-label">Emergency Instructions</dt>
            <dd class="text-sm font-bold text-slate-700"><?= nl2br(e($patient['emergency_instructions'])) ?: 'None recorded' ?></dd>

            <dt class="clinic-label">Guardian</dt>
            <dd class="text-sm font-bold text-slate-700">
                <?= e($patient['guardian_name']) ?: 'Not specified' ?>
                <?php if ($patient['guardian_contact']): ?>
                    · <?= e($patient['guardian_contact']) ?>
                <?php endif; ?>
            </dd>
        </dl>
    </section>
<?php endif; ?>
<?php render_footer(); ?>
