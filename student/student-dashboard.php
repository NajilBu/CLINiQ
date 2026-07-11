<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/includes/student-layout.php';

ensure_appointment_schema();

$profile = student_require_login();
$patientId = (int) $profile['patient_id'];
$db = db();

$appointmentStmt = $db->prepare("
    SELECT *
    FROM appointments
    WHERE patient_id = ?
    ORDER BY appointment_datetime DESC, created_at DESC
    LIMIT 1
");
$appointmentStmt->execute([$patientId]);
$latestAppointment = $appointmentStmt->fetch();

$apeStmt = $db->prepare("
    SELECT *
    FROM ape_records
    WHERE patient_id = ?
    ORDER BY updated_at DESC, created_at DESC
    LIMIT 1
");
$apeStmt->execute([$patientId]);
$latestApe = $apeStmt->fetch();
$apeStatus = $latestApe['workflow_status'] ?? 'Not Started';
$apePercent = match ($apeStatus) {
    'Registered' => 20,
    'Batch Assigned', 'Requirements Checked' => 40,
    'Submitted', 'Reviewed' => 60,
    'Scheduled', 'Exam Done', 'Follow-up Required' => 80,
    'Cleared' => 100,
    default => 0,
};
$apeBadgeClass = match ($latestApe['clearance_status'] ?? '') {
    'Cleared' => 'student-badge-success',
    'For Follow-up' => 'student-badge-warning',
    default => $latestApe ? 'student-badge-info' : 'student-badge-warning',
};
$apeNote = $latestApe['student_visible_note'] ?? 'Start your APE record with the clinic.';
$passportMissing = [];
if (empty($profile['blood_type']) || $profile['blood_type'] === 'Unknown') {
    $passportMissing[] = 'blood type';
}
if (empty($profile['allergies'])) {
    $passportMissing[] = 'allergy notes';
}
if (empty($profile['guardian_name']) || empty($profile['guardian_contact'])) {
    $passportMissing[] = 'guardian contact';
}
if (empty($profile['emergency_instructions'])) {
    $passportMissing[] = 'emergency instructions';
}
$passportComplete = empty($passportMissing);
$apeNeedsAction = ($latestApe['clearance_status'] ?? 'Pending') !== 'Cleared';
$requiredActionCount = ($passportComplete ? 0 : 1) + ($apeNeedsAction ? 1 : 0);

$appointmentStatus = $latestAppointment['status'] ?? 'No Request';
$appointmentBadgeClass = match ($appointmentStatus) {
    'Scheduled', 'Completed' => 'student-badge-success',
    'Cancelled', 'No Show' => 'student-badge-danger',
    'Pending' => 'student-badge-warning',
    default => 'student-badge-info',
};

render_student_header('Dashboard', 'dashboard');
?>

<section class="student-page-header">
    <div>
        <p class="student-eyebrow">Student Health Portal</p>
        <h1 class="student-title">Welcome back, <?= student_e($profile['first_name']) ?></h1>
        <p class="student-subtitle">Track your APE requirements, clinic notes, and appointment requests in one place.</p>
    </div>
    <span class="student-badge student-badge-success">
        <span class="material-symbols-outlined text-[14px]">verified</span>
        Enrolled
    </span>
</section>

<section class="student-required-actions mb-4" aria-label="Required student actions">
    <div class="student-required-actions-head">
        <div>
            <p class="student-eyebrow" style="margin-bottom:0.28rem;">Required Actions</p>
            <h2>Complete these to keep your clinic profile ready</h2>
        </div>
        <span class="student-badge <?= $requiredActionCount > 0 ? 'student-badge-warning' : 'student-badge-success' ?>">
            <?= (int) $requiredActionCount ?> Pending
        </span>
    </div>

    <div class="student-required-action-list">
        <?php if (!$passportComplete): ?>
            <article class="student-action-card student-action-card-danger">
                <div class="flex items-start gap-4">
                    <span class="student-action-step student-action-step-danger">1</span>
                    <span class="student-icon-box student-icon-box-danger">
                        <span class="material-symbols-outlined">emergency</span>
                    </span>
                    <div>
                        <p class="student-action-kicker">Urgent profile action</p>
                        <h2>Complete your Emergency Health Passport</h2>
                        <p>Add <?= student_e(implode(', ', $passportMissing)) ?> before an incident happens.</p>
                    </div>
                </div>
                <a href="student-passport.php" class="student-button-danger text-decoration-none">
                    Complete Passport
                    <span class="material-symbols-outlined">arrow_forward</span>
                </a>
            </article>
        <?php endif; ?>

        <?php if ($apeNeedsAction): ?>
            <article class="student-action-card">
                <div class="flex items-start gap-4">
                    <span class="student-action-step"><?= $passportComplete ? 1 : 2 ?></span>
                    <span class="student-icon-box">
                        <span class="material-symbols-outlined">upload_file</span>
                    </span>
                    <div>
                        <p class="student-action-kicker student-action-kicker-primary">APE requirement</p>
                        <h2>Continue your APE clearance</h2>
                        <p><?= student_e($apeNote) ?></p>
                    </div>
                </div>
                <a href="student-ape-status.php" class="student-button text-decoration-none">
                    Continue APE
                    <span class="material-symbols-outlined">arrow_forward</span>
                </a>
            </article>
        <?php endif; ?>

        <?php if ($requiredActionCount === 0): ?>
            <article class="student-action-card">
                <div class="flex items-start gap-4">
                    <span class="student-icon-box">
                        <span class="material-symbols-outlined">verified</span>
                    </span>
                    <div>
                        <p class="student-action-kicker student-action-kicker-primary">Ready</p>
                        <h2>Your clinic profile is complete</h2>
                        <p>Your passport and APE clearance records are up to date.</p>
                    </div>
                </div>
            </article>
        <?php endif; ?>
    </div>
</section>

<div class="student-grid">
    <section class="student-card student-card-pad student-span-4 student-clickable-card" data-href="student-passport.php" role="link" tabindex="0" aria-label="Open Health Passport profile">
        <div class="flex items-center gap-3 mb-5">
            <span class="student-icon-box">
                <span class="material-symbols-outlined">badge</span>
            </span>
            <div>
                <h2 class="student-card-title">Student Profile</h2>
                <p class="student-card-copy">Basic enrollment details</p>
            </div>
        </div>

        <div class="grid gap-4">
            <div>
                <span class="student-label">Full Name</span>
                <p class="text-sm font-black text-[#17261d] mb-0"><?= student_e($profile['name']) ?></p>
            </div>
            <div>
                <span class="student-label">Student ID</span>
                <p class="text-sm font-black text-[#17261d] mb-0"><?= student_e($profile['student_id']) ?></p>
            </div>
            <div>
                <span class="student-label">Course and Year</span>
                <p class="text-sm font-black text-[#17261d] mb-0"><?= student_e($profile['course']) ?></p>
            </div>
            <div>
                <span class="student-label">Email</span>
                <a href="mailto:<?= student_e($profile['email']) ?>" class="text-sm font-black text-[#3F7D52] text-decoration-none"><?= student_e($profile['email']) ?></a>
            </div>
        </div>
    </section>

    <section class="student-card student-span-4 student-clickable-card" data-href="student-ape-status.php" role="link" tabindex="0" aria-label="Open APE status">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">APE Progress</h2>
                <p class="student-card-copy">Your current clearance path</p>
            </div>
            <span class="student-badge <?= student_e($apeBadgeClass) ?>"><?= student_e($apeStatus) ?></span>
        </div>
        <div class="student-card-pad">
            <div class="flex items-end justify-between mb-3">
                <span class="text-xs font-black text-slate-500 uppercase tracking-wider">Completion</span>
                <strong class="font-headline text-3xl font-black text-[#17261d]"><?= (int) $apePercent ?>%</strong>
            </div>
            <div class="w-full h-3 rounded-full bg-[#edf8f0] overflow-hidden mb-4">
                <div class="h-full bg-[#3F7D52] rounded-full" style="width: <?= (int) $apePercent ?>%;"></div>
            </div>
            <div class="student-progress-list">
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">fact_check</span>
                    <div>
                        <strong><?= student_e($latestApe['document_type'] ?? 'APE Form') ?></strong>
                        <span><?= student_e($latestApe['clinical_remarks'] ?? 'No clinic remarks yet.') ?></span>
                    </div>
                    <span class="student-badge <?= student_e($apeBadgeClass) ?>"><?= student_e($latestApe['clearance_status'] ?? 'Pending') ?></span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">cloud_upload</span>
                    <div>
                        <strong>Required action</strong>
                        <span><?= student_e($latestApe['missing_items'] ?? 'No missing items recorded.') ?></span>
                    </div>
                    <span class="student-badge <?= student_e($apeBadgeClass) ?>"><?= student_e($latestApe['verification_status'] ?? 'Pending') ?></span>
                </div>
            </div>
        </div>
    </section>

    <section class="student-card student-span-4 student-clickable-card" data-href="student-appointment.php" role="link" tabindex="0" aria-label="Open appointment page">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Appointment</h2>
                <p class="student-card-copy">Latest clinic request status</p>
            </div>
            <span class="student-badge <?= student_e($appointmentBadgeClass) ?>"><?= student_e($appointmentStatus) ?></span>
        </div>
        <div class="student-card-pad">
            <?php if ($latestAppointment): ?>
                <div class="student-note <?= $appointmentStatus === 'Pending' ? 'student-note-warning' : 'student-note-success' ?> mb-4">
                    <span class="material-symbols-outlined"><?= $appointmentStatus === 'Pending' ? 'hourglass_top' : 'event_available' ?></span>
                    <div>
                        <strong><?= student_e($latestAppointment['purpose']) ?></strong><br>
                        <?= student_e(date('F j, Y \a\t g:i A', strtotime($latestAppointment['appointment_datetime']))) ?>
                    </div>
                </div>
                <p class="text-xs font-bold text-slate-500 mb-5">
                    <?= $appointmentStatus === 'Pending'
                        ? 'Your request was sent to the clinic. Please wait for approval before going to the clinic.'
                        : 'Please arrive 10 minutes before your scheduled time.' ?>
                </p>
            <?php else: ?>
                <div class="student-note student-note-warning mb-4">
                    <span class="material-symbols-outlined">event_busy</span>
                    <div>
                        <strong>No appointment request yet</strong><br>
                        Book a visit and wait for clinic approval.
                    </div>
                </div>
            <?php endif; ?>
            <a href="student-appointment.php" class="student-button-secondary w-full text-decoration-none">
                Manage Appointment
                <span class="material-symbols-outlined">schedule</span>
            </a>
        </div>
    </section>
</div>

<section class="student-card mt-4">
    <div class="student-card-header">
        <div>
            <h2 class="student-card-title">Clinic Notes</h2>
            <p class="student-card-copy">Messages from the clinic based on your APE review</p>
        </div>
        <span class="student-badge student-badge-info">3 Notes</span>
    </div>
    <div class="student-card-pad grid gap-3">
        <div class="student-note student-note-warning">
            <span class="material-symbols-outlined">info</span>
            <div><strong>UHS Medical Record needed.</strong> Upload a clear scanned copy after clinic verification.</div>
        </div>
        <div class="student-note student-note-warning">
            <span class="material-symbols-outlined">info</span>
            <div><strong>UHS Dental Record missing.</strong> Submit the checked document online for digital keeping.</div>
        </div>
        <div class="student-note student-note-success">
            <span class="material-symbols-outlined">check_circle</span>
            <div><strong>Lab Request Form accepted.</strong> This document is already stored in your clinic record.</div>
        </div>
    </div>
</section>

<script>
(function () {
    document.querySelectorAll('[data-href].student-clickable-card').forEach((card) => {
        const navigate = () => {
            window.location.href = card.dataset.href;
        };

        card.addEventListener('click', (event) => {
            if (event.target.closest('a, button, input, select, textarea, label')) {
                return;
            }
            navigate();
        });

        card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                navigate();
            }
        });
    });
})();
</script>

<?php render_student_footer(); ?>
