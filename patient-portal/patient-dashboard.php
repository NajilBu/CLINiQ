<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';
require_once __DIR__ . '/includes/patient-layout.php';

ensure_appointment_schema();
ensure_ape_workflow_schema();
appointment_sync_overdue_confirmations();

$profile = student_require_login();
$firstRegistrationError = '';
if (!empty($profile['first_registration'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_first_registration') {
        try {
            complete_first_registration(
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? '')
            );
            header('Location: patient-dashboard.php?activated=1');
            exit;
        } catch (Throwable $e) {
            $firstRegistrationError = $e->getMessage();
        }
    }

    render_student_header('Complete Registration', 'dashboard');
    ?>
    <section class="student-page-header">
        <div>
            <p class="student-eyebrow">First Registration</p>
            <h1 class="student-title">Welcome, <?= student_e($profile['first_name']) ?></h1>
            <p class="student-subtitle">Create your permanent password to activate and unlock your CLINiQ account.</p>
        </div>
        <span class="student-badge student-badge-warning">
            <span class="material-symbols-outlined text-[14px]">lock</span>
            Activation Required
        </span>
    </section>

    <section class="student-card student-card-pad max-w-2xl mx-auto">
        <div class="flex items-start gap-4 mb-5">
            <span class="student-icon-box">
                <span class="material-symbols-outlined">password</span>
            </span>
            <div>
                <h2 class="student-card-title">Create your password</h2>
                <p class="student-card-copy">Your identity has been verified. Dashboard features will remain locked until this step is completed.</p>
            </div>
        </div>

        <?php if ($firstRegistrationError !== ''): ?>
            <div class="student-note student-note-danger mb-4">
                <span class="material-symbols-outlined">error</span>
                <div><?= student_e($firstRegistrationError) ?></div>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4" autocomplete="off">
            <input type="hidden" name="action" value="complete_first_registration">
            <div class="student-field">
                <label class="student-label" for="password">Create Password</label>
                <input id="password" name="password" class="student-input" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="student-field">
                <label class="student-label" for="confirm-password">Confirm Password</label>
                <input id="confirm-password" name="confirm_password" class="student-input" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <p class="student-card-copy text-[11px]">Use at least 8 characters with at least one number.</p>
            <button type="submit" class="student-button w-full">
                Activate Account
                <span class="material-symbols-outlined">verified_user</span>
            </button>
        </form>
    </section>
    <?php
    render_student_footer();
    return;
}

// Re-enrollment / re-employment confirmation (school-year reset flow)
if (re_enrollment_pending()) {
    $reCtx = re_enrollment_context();
    $reType = (string) ($reCtx['type'] ?? 'patient');
    $isStudent = $reType === 'student';
    $reEnrollError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_re_enrollment') {
        try {
            complete_re_enrollment();
            header('Location: patient-dashboard.php?re_enrolled=1');
            exit;
        } catch (Throwable $e) {
            $reEnrollError = $e->getMessage();
        }
    }
    render_student_header($isStudent ? 'Confirm Re-enrollment' : 'Confirm Re-employment', 'dashboard');
    ?>
    <section class="student-page-header">
        <div>
            <p class="student-eyebrow">New School Year</p>
            <h1 class="student-title"><?= $isStudent ? 'Are you still enrolled?' : 'Are you still employed?' ?></h1>
            <p class="student-subtitle"><?= $isStudent
                ? 'The clinic has started a new school year. Please confirm you are still enrolled to regain access to your health portal.'
                : 'The clinic has started a new school year. Please confirm you are still employed to regain access to your health portal.'
            ?></p>
        </div>
        <span class="student-badge student-badge-warning">
            <span class="material-symbols-outlined text-[14px]">how_to_reg</span>
            Confirmation Required
        </span>
    </section>

    <section class="student-card student-card-pad max-w-2xl mx-auto">
        <div class="flex items-start gap-4 mb-5">
            <span class="student-icon-box">
                <span class="material-symbols-outlined"><?= $isStudent ? 'school' : 'badge' ?></span>
            </span>
            <div>
                <h2 class="student-card-title"><?= $isStudent ? 'Confirm Enrollment' : 'Confirm Employment' ?></h2>
                <p class="student-card-copy"><?= $isStudent
                    ? 'By confirming, you declare that you are currently enrolled this school year and should have access to clinic health services.'
                    : 'By confirming, you declare that you are currently employed this school year and should have access to clinic health services.'
                ?></p>
            </div>
        </div>

        <?php if ($reEnrollError !== ''): ?>
            <div class="student-note student-note-danger mb-4">
                <span class="material-symbols-outlined">error</span>
                <div><?= student_e($reEnrollError) ?></div>
            </div>
        <?php endif; ?>

        <div class="student-note student-note-warning mb-5">
            <span class="material-symbols-outlined">info</span>
            <div>If you are no longer <?= $isStudent ? 'enrolled' : 'employed' ?>, do <strong>not</strong> confirm. Contact the clinic if you have questions about your account.</div>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="complete_re_enrollment">
            <button type="submit" class="student-button w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="<?= $isStudent ? 'Confirm you are still enrolled?' : 'Confirm you are still employed?' ?>" data-confirm-message="This will reactivate your account for the new school year." data-confirm-toast="Confirming...">
                <span class="material-symbols-outlined">how_to_reg</span>
                <?= $isStudent ? 'Yes, I am Still Enrolled' : 'Yes, I am Still Employed' ?>
            </button>
        </form>
    </section>
    <?php
    render_student_footer();
    return;
}

$patientId = (int) $profile['patient_id'];
$appointmentPatientId = (int) $profile['person_id'];

$appointmentStmt = appointment_db()->prepare("
    SELECT *
    FROM appointments
    WHERE patient_id = ?
    ORDER BY appointment_datetime DESC, created_at DESC
    LIMIT 1
");
$appointmentStmt->execute([$appointmentPatientId]);
$latestAppointment = $appointmentStmt->fetch();

$latestApe = ape_fetch_patient_record($appointmentPatientId);
$apeStatus = $latestApe['workflow_status'] ?? 'Not Started';
$apePercent = match ($apeStatus) {
    'Registered' => 20,
    'Batch Assigned' => 20,
    'Exam Done' => 40,
    'Requirements Checked', 'Scheduled' => 60,
    'Submitted' => 80,
    'Reviewed', 'Follow-up Required' => 80,
    'Cleared' => 100,
    default => 0,
};
$apeBadgeClass = match ($latestApe['clearance_status'] ?? '') {
    'Cleared' => 'student-badge-success',
    'For Follow-up' => 'student-badge-warning',
    default => $latestApe ? 'student-badge-info' : 'student-badge-warning',
};
$apeNote = trim((string) ($latestApe['patient_visible_note'] ?? ''));
$apeRequirementStatus = $latestApe['requirement_status'] ?? 'Not Checked';
$apePatientVitalsConfirmed = ($latestApe['patient_vitals_status'] ?? 'Not Started') === 'Confirmed';
$apeRequirementsVerified = $apePatientVitalsConfirmed && ($apeRequirementStatus === 'Checked' || in_array($apeStatus, [
    'Requirements Checked',
    'Submitted',
    'Reviewed',
    'Scheduled',
    'Follow-up Required',
    'Cleared',
], true));
$apeRequirementsNeedCorrection = $apeRequirementStatus === 'Needs Correction';
$apeExamCompleted = !empty($latestApe['exam_date']);
$apeAllDocumentsUploaded = (int) ($latestApe['required_document_count'] ?? 0) >= count(ape_default_requirements());
$apeDocumentsAwaitingReview = $apeAllDocumentsUploaded && (int) ($latestApe['required_unverified_count'] ?? 0) > 0;
$clinicNotes = [];
if (!$latestApe) {
    $clinicNotes[] = ['type' => 'info', 'icon' => 'info', 'text' => 'No APE record has been opened by the clinic yet.'];
} elseif (($latestApe['clearance_status'] ?? '') === 'Cleared') {
    $clinicNotes[] = ['type' => 'success', 'icon' => 'check_circle', 'text' => 'APE completed and cleared. No outstanding clinic requirements.'];
    if ($apeNote !== '') {
        $clinicNotes[] = ['type' => 'success', 'icon' => 'campaign', 'text' => $apeNote];
    }
} else {
    if ($apeRequirementsNeedCorrection) {
        $clinicNotes[] = ['type' => 'warning', 'icon' => 'assignment_late', 'text' => $apeNote ?: 'Corrected hard-copy requirements are needed.'];
    }
    if ($apeStatus === 'Follow-up Required' && $apeNote !== '') {
        $clinicNotes[] = ['type' => 'warning', 'icon' => 'medical_information', 'text' => $apeNote];
    }
    $latestDocuments = [];
    foreach (ape_documents_for_record((int) $latestApe['ape_id']) as $document) {
        $latestDocuments[$document['document_type']] ??= $document;
    }
    if ($apeRequirementsVerified) {
        foreach (ape_default_requirements() as $documentType) {
            $document = $latestDocuments[$documentType] ?? null;
            if (!$document) {
                $clinicNotes[] = ['type' => 'warning', 'icon' => 'info', 'text' => $documentType . ' is still missing.'];
                continue;
            }
            $verification = $document['verification_status'] ?? 'Pending';
            $clinicNotes[] = match ($verification) {
                'Verified' => ['type' => 'success', 'icon' => 'check_circle', 'text' => $documentType . ' accepted and stored in your clinic record.'],
                'Needs Correction' => ['type' => 'danger', 'icon' => 'error', 'text' => $documentType . ' needs a corrected upload.'],
                default => ['type' => 'warning', 'icon' => 'info', 'text' => $documentType . ' was submitted and is waiting for clinic review.'],
            };
        }
    }
    if (!$clinicNotes) {
        $clinicNotes[] = ['type' => 'info', 'icon' => 'info', 'text' => 'Complete the current APE step shown above.'];
    }
}
$clinicNoteClass = static fn(string $type): string => match ($type) {
    'success' => 'student-note-success',
    'danger' => 'student-note-danger',
    'warning' => 'student-note-warning',
    default => 'student-note-info',
};
$apeActionTitle = match (true) {
    ($latestApe['clearance_status'] ?? 'Pending') === 'Cleared' => 'APE completed',
    $apeRequirementsNeedCorrection => 'Return corrected hard-copy requirements',
    $apeStatus === 'Follow-up Required' => 'Complete the required follow-up',
    !$apePatientVitalsConfirmed => 'Complete your vitals and BMI',
    !$apeExamCompleted || !$apeRequirementsVerified => 'Attend examination',
    $apeDocumentsAwaitingReview => 'Wait for clinic document review',
    $apeStatus === 'Reviewed' => 'Wait for the final clinical decision',
    default => 'Upload verified APE documents',
};
$apeActionCopy = match (true) {
    ($latestApe['clearance_status'] ?? 'Pending') === 'Cleared' => 'Your APE record is already cleared by the clinic.',
    $apeRequirementsNeedCorrection => $apeNote ?: 'Return the corrected hard-copy requirements requested by the clinic.',
    $apeStatus === 'Follow-up Required' => $apeNote ?: 'Complete the referral or other follow-up requested by the clinic.',
    !$apePatientVitalsConfirmed => 'Enter and confirm your vitals and BMI before visiting the clinic for examination.',
    !$apeExamCompleted || !$apeRequirementsVerified => $apeNote ?: 'Your vitals are confirmed. Visit the clinic for examination and hard-copy document review.',
    $apeDocumentsAwaitingReview => 'Your documents are waiting for clinic archive review.',
    $apeStatus === 'Reviewed' => $apeNote ?: 'Your examination and documents are complete and awaiting the clinic\'s final decision.',
    default => $apeNote ?: ($latestApe ? 'Complete the current APE step in your APE status page.' : 'Start your APE record with the clinic.'),
};
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
$profileDetailLabel = match ($profile['account_type'] ?? 'patient') {
    'student' => 'Program',
    'faculty', 'school_personnel' => 'Department',
    default => 'Affiliation',
};
$accountBadgeLabel = ($profile['account_type'] ?? '') === 'student' ? 'Enrolled' : 'Active';

$appointmentStatus = $latestAppointment['status'] ?? 'No Request';
$appointmentBadgeClass = match ($appointmentStatus) {
    'Scheduled', 'Completed' => 'student-badge-success',
    'Cancelled', 'No Show' => 'student-badge-danger',
    'Pending', 'For Confirmation' => 'student-badge-warning',
    default => 'student-badge-info',
};
$appointmentNoteClass = match ($appointmentStatus) {
    'Pending', 'For Confirmation' => 'student-note-warning',
    'Cancelled', 'No Show' => 'student-note-danger',
    default => 'student-note-success',
};
$appointmentIcon = match ($appointmentStatus) {
    'Pending', 'For Confirmation' => 'hourglass_top',
    'Cancelled', 'No Show' => 'event_busy',
    default => 'event_available',
};
$appointmentSummary = match ($appointmentStatus) {
    'Pending' => 'Your request was sent to the clinic. Please wait for approval before going to the clinic.',
    'Scheduled' => 'Please arrive 10 minutes before your scheduled time.',
    'For Confirmation' => 'Your appointment time has passed. Please wait for clinic staff to confirm if it was completed.',
    'Completed' => 'This appointment has been completed.',
    'Cancelled' => 'This appointment was cancelled.',
    'No Show' => 'This appointment was marked as no-show by the clinic.',
    default => 'Manage your appointment request from the appointment page.',
};
$appointmentDisplayStatus = $appointmentStatus === 'For Confirmation' ? 'Awaiting clinic confirmation' : $appointmentStatus;

render_student_header('Dashboard', 'dashboard');
?>

<?php if (isset($_GET['activated'])): ?>
    <div class="student-note student-note-success mb-4">
        <span class="material-symbols-outlined">check_circle</span>
        <div>Your account is now active. Your dashboard is fully unlocked.</div>
    </div>
<?php endif; ?>

<section class="student-page-header">
    <div>
        <p class="student-eyebrow">Patient Health Portal</p>
        <h1 class="student-title">Welcome back, <?= student_e($profile['first_name']) ?></h1>
        <p class="student-subtitle">Track your APE requirements, clinic notes, and appointment requests in one place.</p>
    </div>
    <span class="student-badge student-badge-success">
        <span class="material-symbols-outlined text-[14px]">verified</span>
        <?= student_e($accountBadgeLabel) ?>
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
                <a href="patient-passport.php" class="student-button-danger text-decoration-none">
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
                        <h2><?= student_e($apeActionTitle) ?></h2>
                        <p><?= student_e($apeActionCopy) ?></p>
                    </div>
                </div>
                <a href="patient-ape-status.php" class="student-button text-decoration-none">
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
    <section class="student-card student-card-pad student-span-4 student-clickable-card" data-href="patient-passport.php" role="link" tabindex="0" aria-label="Open Health Passport profile">
        <div class="flex items-center gap-3 mb-5">
            <span class="student-icon-box">
                <span class="material-symbols-outlined">badge</span>
            </span>
            <div>
                <h2 class="student-card-title">Patient Profile</h2>
                <p class="student-card-copy">Basic enrollment details</p>
            </div>
        </div>

        <div class="grid gap-4">
            <div>
                <span class="student-label">Full Name</span>
                <p class="text-sm font-black text-[#17261d] mb-0"><?= student_e($profile['name']) ?></p>
            </div>
            <div>
                <span class="student-label">ID Number</span>
                <p class="text-sm font-black text-[#17261d] mb-0"><?= student_e($profile['student_id']) ?></p>
            </div>
            <div>
                <span class="student-label"><?= student_e($profileDetailLabel) ?></span>
                <p class="text-sm font-black text-[#17261d] mb-0"><?= student_e($profile['course']) ?></p>
            </div>
            <div>
                <span class="student-label">Email</span>
                <a href="mailto:<?= student_e($profile['email']) ?>" class="text-sm font-black text-primary text-decoration-none"><?= student_e($profile['email']) ?></a>
            </div>
        </div>
    </section>

    <section class="student-card student-span-4 student-clickable-card" data-href="patient-ape-status.php" role="link" tabindex="0" aria-label="Open APE status">
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
            <div class="w-full h-3 rounded-full bg-primary-fixed overflow-hidden mb-4">
                <div class="h-full bg-primary rounded-full" style="width: <?= (int) $apePercent ?>%;"></div>
            </div>
            <div class="student-progress-list">
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">task_alt</span>
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

    <section class="student-card student-span-4 student-clickable-card" data-href="patient-appointment.php" role="link" tabindex="0" aria-label="Open appointment page">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Appointment</h2>
                <p class="student-card-copy">Latest clinic request status</p>
            </div>
            <span class="student-badge <?= student_e($appointmentBadgeClass) ?>"><?= student_e($appointmentDisplayStatus) ?></span>
        </div>
        <div class="student-card-pad">
            <?php if ($latestAppointment): ?>
                <div class="student-note <?= student_e($appointmentNoteClass) ?> mb-4">
                    <span class="material-symbols-outlined"><?= student_e($appointmentIcon) ?></span>
                    <div>
                        <strong><?= student_e($latestAppointment['purpose']) ?></strong><br>
                        <?= student_e(date('F j, Y \a\t g:i A', strtotime($latestAppointment['appointment_datetime']))) ?>
                        <?php if ($appointmentStatus === 'Cancelled' && trim((string) ($latestAppointment['cancellation_reason'] ?? '')) !== ''): ?>
                            <br><strong>Reason:</strong> <?= student_e($latestAppointment['cancellation_reason']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="text-xs font-bold text-slate-500 mb-5">
                    <?= student_e($appointmentSummary) ?>
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
            <a href="patient-appointment.php" class="student-button-secondary w-full text-decoration-none">
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
        <span class="student-badge student-badge-info"><?= count($clinicNotes) ?> Note<?= count($clinicNotes) === 1 ? '' : 's' ?></span>
    </div>
    <div class="student-card-pad grid gap-3">
        <?php foreach ($clinicNotes as $clinicNote): ?>
            <div class="student-note <?= student_e($clinicNoteClass($clinicNote['type'])) ?>">
                <span class="material-symbols-outlined"><?= student_e($clinicNote['icon']) ?></span>
                <div><?= student_e($clinicNote['text']) ?></div>
            </div>
        <?php endforeach; ?>
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
