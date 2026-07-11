<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/includes/student-layout.php';

ensure_appointment_schema();

$db = db();
$db->exec("INSERT IGNORE INTO patients (id, student_number, first_name, last_name, emergency_token) VALUES (1, '23-00456', 'Juan', 'dela Cruz', 'dummy-token-123')");

$appointmentStmt = $db->prepare("
    SELECT *
    FROM appointments
    WHERE patient_id = 1
    ORDER BY appointment_datetime DESC, created_at DESC
    LIMIT 1
");
$appointmentStmt->execute();
$latestAppointment = $appointmentStmt->fetch();

$appointmentStatus = $latestAppointment['status'] ?? 'No Request';
$appointmentBadgeClass = match ($appointmentStatus) {
    'Scheduled', 'Completed' => 'student-badge-success',
    'Cancelled', 'No Show' => 'student-badge-danger',
    'Pending' => 'student-badge-warning',
    default => 'student-badge-info',
};

$profile = student_demo_profile();

render_student_header('Dashboard', 'dashboard');
?>

<section class="student-page-header">
    <div>
        <p class="student-eyebrow">Student Health Portal</p>
        <h1 class="student-title">Welcome back, Juan</h1>
        <p class="student-subtitle">Track your APE requirements, clinic notes, and appointment requests in one place.</p>
    </div>
    <span class="student-badge student-badge-success">
        <span class="material-symbols-outlined text-[14px]">verified</span>
        Enrolled
    </span>
</section>

<section class="student-action-card mb-4">
    <div class="flex items-start gap-4">
        <span class="student-icon-box">
            <span class="material-symbols-outlined">upload_file</span>
        </span>
        <div>
            <h2>Next action: Upload missing APE documents</h2>
            <p>Submit your UHS Medical Record, UHS Dental Record, and Referral Form so the clinic can continue review.</p>
        </div>
    </div>
    <a href="student-ape-status.php" class="student-button text-decoration-none">
        Continue APE
        <span class="material-symbols-outlined">arrow_forward</span>
    </a>
</section>

<div class="student-grid">
    <section class="student-card student-card-pad student-span-4">
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

    <section class="student-card student-span-4">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">APE Progress</h2>
                <p class="student-card-copy">Your current clearance path</p>
            </div>
            <span class="student-badge student-badge-warning">In Progress</span>
        </div>
        <div class="student-card-pad">
            <div class="flex items-end justify-between mb-3">
                <span class="text-xs font-black text-slate-500 uppercase tracking-wider">Completion</span>
                <strong class="font-headline text-3xl font-black text-[#17261d]">40%</strong>
            </div>
            <div class="w-full h-3 rounded-full bg-[#edf8f0] overflow-hidden mb-4">
                <div class="h-full bg-[#3F7D52] rounded-full" style="width: 40%;"></div>
            </div>
            <div class="student-progress-list">
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">fact_check</span>
                    <div>
                        <strong>Hard-copy check</strong>
                        <span>Clinic reviewed initial papers</span>
                    </div>
                    <span class="student-badge student-badge-success">Done</span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">cloud_upload</span>
                    <div>
                        <strong>Digital submission</strong>
                        <span>Three documents still missing</span>
                    </div>
                    <span class="student-badge student-badge-warning">Action</span>
                </div>
            </div>
        </div>
    </section>

    <section class="student-card student-span-4">
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

<?php render_student_footer(); ?>
