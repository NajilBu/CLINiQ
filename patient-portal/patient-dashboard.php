<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AppointmentWorkflow.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';
require_once __DIR__ . '/../app/services/ClinicFeedback.php';
require_once __DIR__ . '/includes/patient-layout.php';

ensure_appointment_schema();
ensure_ape_workflow_schema();
appointment_sync_overdue_confirmations();

$profile = student_require_login();
$isOfficialAccess = patient_has_official_access($profile);
$firstRegistrationError = '';
if (!empty($profile['first_registration'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_first_registration') {
        try {
            complete_first_registration(
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? ''),
                ($_POST['legal_acknowledgement'] ?? '') === '1'
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
            <p class="student-subtitle">Set your password to activate secure access to your clinic portal.</p>
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
                <p class="student-card-copy">Your identity has been verified. Create your password to enter the patient dashboard.</p>
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
            <label class="flex items-start gap-3 text-xs font-bold text-slate-600"><input class="mt-0.5" type="checkbox" name="legal_acknowledgement" value="1" required><span>I have read and acknowledge the <a href="<?= student_e(student_legal_url('terms')) ?>" target="_blank" rel="noopener" class="student-auth-link">Terms of Use</a> and <a href="<?= student_e(student_legal_url('privacy')) ?>" target="_blank" rel="noopener" class="student-auth-link">Privacy Notice</a>, including how CLINiQ processes health information.</span></label>
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

// Student re-enrollment confirmation (school-year reset flow)
if (re_enrollment_pending()) {
    $reCtx = re_enrollment_context();
    if ((string) ($reCtx['type'] ?? '') !== 'student') {
        unset($_SESSION['re_enrollment']);
        header('Location: patient-login.php');
        exit;
    }
    $reEnrollError = '';
    $selectedEnrollmentStatus = trim((string) ($_POST['enrollment_status'] ?? ''));
    $selectedNonEnrollmentReason = trim((string) ($_POST['non_enrollment_reason'] ?? ''));
    $contactNumber = trim((string) ($_POST['contact_number'] ?? ($reCtx['contact_number'] ?? '')));
    $contactConfirmed = (string) ($_POST['contact_confirmed'] ?? '');
    $yearLevel = trim((string) ($_POST['year_level'] ?? ($reCtx['year_level'] ?? '')));
    $section = strtoupper(trim((string) ($_POST['section'] ?? ($reCtx['section'] ?? ''))));
    $nonEnrollmentReasons = student_non_enrollment_reasons();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_re_enrollment') {
        try {
            complete_re_enrollment($selectedEnrollmentStatus, $selectedNonEnrollmentReason, $contactNumber, $contactConfirmed, $yearLevel, $section);
            header('Location: patient-dashboard.php?re_enrolled=1');
            exit;
        } catch (Throwable $e) {
            $reEnrollError = $e->getMessage();
        }
    }
    render_student_header('Confirm Re-enrollment', 'dashboard');
    ?>
    <section class="student-page-header">
        <div>
            <p class="student-eyebrow">New School Year</p>
            <h1 class="student-title">Update your enrollment status</h1>
            <p class="student-subtitle">Confirm your enrollment for the new school year.</p>
        </div>
        <span class="student-badge student-badge-warning">
            <span class="material-symbols-outlined text-[14px]">how_to_reg</span>
            Confirmation Required
        </span>
    </section>

    <section class="student-card student-card-pad max-w-2xl mx-auto">
        <div class="flex items-start gap-4 mb-5">
            <span class="student-icon-box">
                <span class="material-symbols-outlined">school</span>
            </span>
            <div>
                <h2 class="student-card-title">Enrollment Declaration</h2>
                <p class="student-card-copy">Review your updated student information and confirm your enrollment for <?= student_e((string) ($reCtx['academic_year'] ?? student_current_academic_year())) ?>.</p>
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
            <div>Your response will be recorded and your portal access will resume immediately after submission.</div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 mb-5">
            <div class="student-field"><label class="student-label" for="year-level">Year Level</label><select id="year-level" name="year_level" class="student-select" required><?php foreach (['1','2','3','4'] as $option): ?><option value="<?= $option ?>" <?= $yearLevel === $option ? 'selected' : '' ?>>Year <?= $option ?></option><?php endforeach; ?></select></div>
            <div class="student-field"><label class="student-label" for="section">Section</label><input id="section" name="section" class="student-input" value="<?= student_e($section) ?>" maxlength="80" required><p class="text-xs font-bold text-slate-500 mt-1 mb-0">Review or correct the section assigned for this school year.</p></div>
            <div class="student-field"><label class="student-label" for="contact-number">Contact Number</label><input id="contact-number" name="contact_number" class="student-input" value="<?= student_e($contactNumber) ?>" placeholder="09XXXXXXXXX" required></div>
        </div>

        <form method="post" id="re-enrollment-form" class="space-y-5">
            <input type="hidden" name="action" value="complete_re_enrollment">
            <div class="student-field">
                <label class="student-label" for="enrollment-status">Current Enrollment Status</label>
                <select id="enrollment-status" name="enrollment_status" class="student-select" required>
                    <option value="">Select your status</option>
                    <option value="Still Enrolled" <?= $selectedEnrollmentStatus === 'Still Enrolled' ? 'selected' : '' ?>>Still Enrolled</option>
                    <option value="Not Currently Enrolled" <?= $selectedEnrollmentStatus === 'Not Currently Enrolled' ? 'selected' : '' ?>>Not Currently Enrolled</option>
                </select>
            </div>
            <div class="student-field" id="non-enrollment-reason-field" <?= $selectedEnrollmentStatus === 'Not Currently Enrolled' ? '' : 'hidden' ?>>
                <label class="student-label" for="non-enrollment-reason">Reason</label>
                <select id="non-enrollment-reason" name="non_enrollment_reason" class="student-select" <?= $selectedEnrollmentStatus === 'Not Currently Enrolled' ? 'required' : 'disabled' ?>>
                    <option value="">Select a reason</option>
                    <?php foreach ($nonEnrollmentReasons as $reason): ?>
                        <option value="<?= student_e($reason) ?>" <?= $selectedNonEnrollmentReason === $reason ? 'selected' : '' ?>><?= student_e($reason) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="flex items-start gap-3 text-xs font-bold text-slate-600"><input type="checkbox" name="contact_confirmed" value="1" <?= $contactConfirmed === '1' ? 'checked' : '' ?> required><span>I confirm that my year/section and contact information above are correct.</span></label>
            <button type="submit" class="student-button w-full" data-confirm-submit data-confirm-type="primary" data-confirm-title="Submit enrollment declaration?" data-confirm-message="Your response will be recorded and your account will be reactivated immediately." data-confirm-toast="Submitting...">
                <span class="material-symbols-outlined">how_to_reg</span>
                Submit and Continue
            </button>
        </form>
    </section>
    <script>
        (() => {
            const status = document.getElementById('enrollment-status');
            const reasonField = document.getElementById('non-enrollment-reason-field');
            const reason = document.getElementById('non-enrollment-reason');
            const syncReasonField = () => {
                const needsReason = status.value === 'Not Currently Enrolled';
                reasonField.hidden = !needsReason;
                reason.disabled = !needsReason;
                reason.required = needsReason;
                if (!needsReason) {
                    reason.value = '';
                }
            };
            status.addEventListener('change', syncReasonField);
            syncReasonField();
        })();
    </script>
    <?php
    render_student_footer();
    return;
}

$patientId = (int) $profile['patient_id'];
$appointmentPatientId = (int) $profile['person_id'];
$pendingFeedbackVisits = clinic_feedback_pending_completed_visits(auth_db(), $appointmentPatientId);
$feedbackRequired = count($pendingFeedbackVisits) > 0;
$feedbackPortalUrl = 'patient-feedback.php';

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
$hasScheduledApeBatch = $latestApe
    && !empty($latestApe['schedule_batch_id'])
    && ($latestApe['batch_status'] ?? '') === 'Scheduled';
$scheduledApeBatchLabel = $hasScheduledApeBatch
    ? date('F j, Y', strtotime((string) $latestApe['batch_schedule_date'])) . ' · '
        . date('g:i A', strtotime((string) $latestApe['batch_start_time'])) . '–'
        . date('g:i A', strtotime((string) $latestApe['batch_end_time']))
    : '';
$apeStatus = $latestApe['workflow_status'] ?? 'Not Started';
$apeQueue = $latestApe ? ape_record_queue($latestApe) : 'digital_submission';
$apeProgress = ape_patient_progress($latestApe ?? []);
$apeStep = $apeProgress['active_step'] - 1;
$apeDigitalSubmissionComplete = ape_digital_submission_complete($latestApe ?? []);
$apeExamCompleted = !empty($latestApe['exam_date']);
$apePercent = $apeProgress['percent'];
$apeCompleted = $apePercent >= 100 || ($latestApe['clearance_status'] ?? '') === 'Cleared';
$apeBadgeClass = match ($latestApe['clearance_status'] ?? '') {
    'Cleared' => 'student-badge-success',
    'For Follow-up' => 'student-badge-warning',
    default => $latestApe ? 'student-badge-info' : 'student-badge-warning',
};
$apeNote = trim((string) ($latestApe['patient_visible_note'] ?? ''));
$apeRequirementStatus = $latestApe['requirement_status'] ?? 'Not Checked';
$apeRequirementsVerified = $apeRequirementStatus === 'Checked' || in_array($apeStatus, [
    'Requirements Checked',
    'Submitted',
    'Reviewed',
    'Scheduled',
    'Follow-up Required',
    'Cleared',
], true);
$apeRequirementsNeedCorrection = $apeRequirementStatus === 'Needs Correction';
$apeAllDocumentsUploaded = ape_initial_uploads_present($latestApe ?? []);
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
        foreach (ape_upload_requirement_names(ape_requirements_for_record((int) $latestApe['ape_id'])) as $documentType) {
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
    if (!empty($latestApe['initial_upload_due_date'])) {
        $clinicNotes[] = ['type' => 'info', 'icon' => 'calendar_month', 'text' => 'Initial clinic-verified uploads due ' . date('M j, Y', strtotime($latestApe['initial_upload_due_date'])) . '.'];
    }
    if (!empty($latestApe['deferred_upload_due_date']) && !ape_deferred_submission_complete($latestApe)) {
        $clinicNotes[] = ['type' => 'warning', 'icon' => 'event', 'text' => 'Follow-up / correction documents due ' . date('M j, Y', strtotime($latestApe['deferred_upload_due_date'])) . '. These do not block the initial upload step.'];
    }
    if (!$clinicNotes) {
        $clinicNotes[] = ['type' => 'info', 'icon' => 'info', 'text' => 'Complete the current APE step shown above.'];
    }
}
$apeDocumentActionCount = count(array_filter($clinicNotes, static fn(array $clinicNote): bool =>
    str_ends_with($clinicNote['text'], ' is still missing.')
    || str_ends_with($clinicNote['text'], ' needs a corrected upload.')
));
$clinicNoteClass = static fn(string $type): string => match ($type) {
    'success' => 'student-note-success',
    'danger' => 'student-note-danger',
    'warning' => 'student-note-warning',
    default => 'student-note-info',
};
$apeActionTitle = match (true) {
    ($latestApe['clearance_status'] ?? 'Pending') === 'Cleared' => 'APE completed',
    $latestApe && $apeProgress['active_step'] === 1 => 'Upload APE documents',
    $apeQueue === 'digital_submission' && !$apeAllDocumentsUploaded => 'Upload APE documents',
    $apeQueue === 'digital_submission' && !$apeExamCompleted => 'Attend your scheduled examination',
    $apeQueue === 'digital_submission' => 'Wait for clinic document review',
    $apeQueue === 'follow_up' && !ape_document_follow_up($latestApe) && !ape_deferred_submission_complete($latestApe) => 'Submit follow-up documents for archive review',
    $apeRequirementsNeedCorrection => 'Return corrected hard-copy requirements',
    $apeStatus === 'Follow-up Required' => 'Complete the required follow-up',
    $apeQueue === 'examination' => ape_examination_is_available($latestApe ?? []) ? 'Attend examination' : 'Wait for your APE schedule',
    $apeDocumentsAwaitingReview => 'Wait for clinic document review',
    $apeStatus === 'Reviewed' => 'Wait for the final clinical decision',
    default => 'Upload verified APE documents',
};
$apeActionCopy = match (true) {
    ($latestApe['clearance_status'] ?? 'Pending') === 'Cleared' => 'Your APE record is already cleared by the clinic.',
    $latestApe && $apeProgress['active_step'] === 1 => 'Complete regular uploads within seven days of examination. Follow-up documents use their separately assigned return date.',
    $latestApe && $apeProgress['active_step'] === 3 => 'Your documents and examination are complete. The clinic will record the final clinical decision.',
    $latestApe && $apeProgress['active_step'] === 4 => $apeNote ?: 'Complete the follow-up requirements requested by the clinic before final clearance.',
    $apeQueue === 'digital_submission' && !$apeAllDocumentsUploaded => $apeExamCompleted
        ? 'Complete regular uploads within seven days of examination. Follow-up documents use their separately assigned return date.'
        : 'Upload any available documents now. Incomplete files will not prevent attendance during your assigned examination schedule.',
    $apeQueue === 'digital_submission' && !$apeExamCompleted => 'Your files are ready for clinic comparison. Attend your examination even if clinic review is still pending.',
    $apeQueue === 'digital_submission' => 'Your regular documents are waiting for clinic archive review.',
    $apeQueue === 'follow_up' && !ape_document_follow_up($latestApe) && !ape_deferred_submission_complete($latestApe) => 'The initial group is archived. Upload the returned documents by their assigned due date and wait for clinic archive review.',
    $apeRequirementsNeedCorrection => $apeNote ?: 'Return the corrected hard-copy requirements requested by the clinic.',
    $apeStatus === 'Follow-up Required' => $apeNote ?: 'Complete the referral or other follow-up requested by the clinic.',
    $apeQueue === 'examination' => ape_examination_is_available($latestApe ?? [])
        ? "Attend {$latestApe['batch_name']} now and bring any available hard-copy requirements."
        : 'Continue early digital uploads while waiting for the clinic to assign or open your examination schedule.',
    $apeDocumentsAwaitingReview => 'Your documents are waiting for clinic archive review.',
    $apeStatus === 'Reviewed' => $apeNote ?: 'Your examination and documents are complete and awaiting the clinic\'s final decision.',
    default => $apeNote ?: ($latestApe ? 'Complete the current APE step in your APE status page.' : 'Start your APE record with the clinic.'),
};
$apePhaseLabel = match (true) {
    !$latestApe => 'Not Started',
    default => $apeProgress['stage_label'],
};
$apePhaseStatus = match (true) {
    !$latestApe => 'Not Started',
    ($latestApe['clearance_status'] ?? '') === 'Cleared' => 'Completed',
    $apeProgress['active_step'] === 1 => 'Upload Required',
    $apeProgress['active_step'] === 3 => 'Awaiting Decision',
    $apeProgress['active_step'] === 4 => 'Follow-up Required',
    $apeRequirementsNeedCorrection => 'Correction Needed',
    $apeStatus === 'Follow-up Required' => 'Follow-up Required',
    $apeQueue === 'digital_submission' && $apeDocumentsAwaitingReview => 'Under Clinic Review',
    $apeQueue === 'examination' && $hasScheduledApeBatch => 'Scheduled',
    $apeQueue === 'examination' => 'Waiting for Schedule',
    default => $apeStatus,
};
$apeActionStatus = match (true) {
    !$latestApe => 'Not Started',
    ($latestApe['clearance_status'] ?? '') === 'Cleared' => 'Complete',
    $apeProgress['active_step'] === 1 => 'Upload Required',
    $apeProgress['active_step'] === 3 => 'Awaiting Decision',
    $apeProgress['active_step'] === 4 => 'Follow-up Required',
    $apeRequirementsNeedCorrection => 'Needs Correction',
    $apeQueue === 'digital_submission' && !$apeAllDocumentsUploaded => 'Upload Required',
    $apeQueue === 'digital_submission' && $apeDocumentsAwaitingReview => 'Under Review',
    $apeQueue === 'digital_submission' => 'Documents Complete',
    $apeQueue === 'examination' && $hasScheduledApeBatch => 'Scheduled',
    $apeQueue === 'examination' => 'Waiting for Schedule',
    $apeQueue === 'follow_up' => 'Follow-up Required',
    default => 'In Progress',
};
$apePhaseBadgeClass = match ($apePhaseStatus) {
    'Completed' => 'student-badge-success',
    'Correction Needed', 'Follow-up Required' => 'student-badge-danger',
    'Scheduled', 'Under Clinic Review' => 'student-badge-info',
    default => 'student-badge-warning',
};
$apeActionBadgeClass = match ($apeActionStatus) {
    'Complete', 'Documents Complete' => 'student-badge-success',
    'Needs Correction', 'Follow-up Required' => 'student-badge-danger',
    'Under Review', 'Scheduled' => 'student-badge-info',
    default => 'student-badge-warning',
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
$passportRequired = $isOfficialAccess && !$passportComplete;
$requiredActionCount = ($passportRequired ? 1 : 0) + ($apeNeedsAction ? 1 : 0) + ($feedbackRequired ? 1 : 0);
$dashboardTasks = [];
if ($passportRequired) {
    $dashboardTasks[] = [
        'key' => 'passport', 'icon' => 'emergency', 'tone' => 'danger',
        'kicker' => 'Urgent profile action', 'title' => 'Complete your Emergency Health Passport',
        'short_copy' => 'Complete your missing emergency details.', 'href' => 'patient-passport.php', 'button' => 'Complete Passport',
    ];
}
if ($apeNeedsAction) {
    $dashboardTasks[] = [
        'key' => 'ape', 'icon' => 'upload_file', 'tone' => 'primary',
        'kicker' => 'APE requirement', 'title' => $apeActionTitle,
        'short_copy' => match (true) {
            str_contains($apeActionTitle, 'Upload') => 'Upload your required APE documents.',
            str_contains($apeActionTitle, 'Attend') => 'Attend your scheduled examination.',
            str_contains($apeActionTitle, 'follow-up') => 'Complete the clinic follow-up.',
            default => 'Continue your APE requirements.',
        },
        'href' => 'patient-ape-status.php', 'button' => 'Continue APE',
    ];
}
if ($feedbackRequired) {
    $dashboardTasks[] = [
        'key' => 'feedback', 'icon' => 'rate_review', 'tone' => 'danger',
        'kicker' => 'Required clinic feedback', 'title' => 'Share feedback for your completed visit',
        'short_copy' => 'Complete feedback before requesting another appointment.', 'href' => $feedbackPortalUrl, 'button' => 'Complete Required Feedback',
    ];
}
if (!$dashboardTasks) {
    $dashboardTasks[] = [
        'key' => 'ready', 'icon' => 'verified', 'tone' => 'primary',
        'kicker' => 'Ready', 'title' => 'Your clinic profile is complete',
        'short_copy' => 'Your clinic profile is up to date.', 'href' => null, 'button' => null,
    ];
}
$profileDetailLabel = match ($profile['account_type'] ?? 'patient') {
    'student' => 'Program',
    'faculty', 'school_personnel' => 'Department',
    default => 'Affiliation',
};
$accountBadgeLabel = $isOfficialAccess ? 'Official' : 'Applicant';

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
$appointmentCtaLabel = in_array($appointmentStatus, ['Cancelled', 'No Show'], true) ? 'Book New Appointment' : 'Manage Appointment';
$appointmentCtaIcon = in_array($appointmentStatus, ['Cancelled', 'No Show'], true) ? 'calendar_add_on' : 'schedule';

render_student_header('Dashboard', 'dashboard');
?>

<?php if (isset($_GET['activated'])): ?>
    <div class="student-note student-note-success student-toast" data-student-toast role="status" aria-live="polite">
        <span class="material-symbols-outlined">check_circle</span>
        <div>Your sign-in is now active. Your available portal features are shown on the dashboard.</div>
        <button type="button" class="student-toast-dismiss" aria-label="Dismiss confirmation"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['device_forgotten'])): ?>
    <div class="student-note student-note-success student-toast" data-student-toast role="status" aria-live="polite">
        <span class="material-symbols-outlined">verified_user</span>
        <div>This device has been forgotten. You will stay signed in here, but future visits will require your password.</div>
        <button type="button" class="student-toast-dismiss" aria-label="Dismiss confirmation"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
    </div>
<?php endif; ?>

<section class="student-card student-card-pad mb-4 student-dashboard-welcome" aria-label="Patient dashboard overview">
<div class="student-page-header">
    <div>
        <p class="student-eyebrow">Patient Health Portal</p>
        <h1 class="student-title">Welcome back, <?= student_e($profile['first_name']) ?></h1>
        <p class="student-subtitle">View your clinic tasks, updates, and records in one place.</p>
    </div>
    <span class="student-badge <?= $isOfficialAccess ? 'student-badge-success' : 'student-badge-warning' ?>">
        <span class="material-symbols-outlined text-[14px]"><?= $isOfficialAccess ? 'verified' : 'hourglass_top' ?></span>
        <?= student_e($accountBadgeLabel) ?>
    </span>
</div>
<?php if (!$isOfficialAccess): ?>
    <p class="student-dashboard-applicant-hint">APE clearance unlocks Passport and appointments.</p>
<?php endif; ?>
<?php if ($hasScheduledApeBatch): ?>
    <a href="patient-ape-status.php" class="student-dashboard-batch-summary text-decoration-none">
        <span class="student-icon-box"><span class="material-symbols-outlined" aria-hidden="true">event_available</span></span>
        <strong>Current APE batch</strong>
        <span class="student-dashboard-batch-time"><?= student_e($scheduledApeBatchLabel) ?></span>
    </a>
<?php endif; ?>
</section>

<?php if (!$isOfficialAccess): ?>
    <div class="student-note student-note-warning student-dashboard-applicant-warning mb-4" role="status">
        <span class="material-symbols-outlined">lock_clock</span>
        <div><strong>Applicant access</strong><br>Complete your APE and receive final clinic clearance to unlock your Health Passport and appointment booking.</div>
    </div>
<?php endif; ?>

<?php if ($requiredActionCount > 0): ?>
<section class="student-required-actions student-dashboard-next-steps mb-4" aria-label="Required student actions">
    <div class="student-required-actions-head">
        <div>
            <p class="student-eyebrow student-eyebrow-compact"><span class="student-dashboard-desktop-copy">Your next steps</span><span class="student-dashboard-mobile-copy">Your next step</span></p>
            <h2><span class="student-dashboard-desktop-copy">Start here to keep your clinic profile ready</span><span class="student-dashboard-mobile-copy">Keep your clinic profile ready</span></h2>
            <p class="student-required-actions-copy"><span class="student-dashboard-desktop-copy">Complete the items below in order. The portal will unlock the next action when it is ready.</span><span class="student-dashboard-mobile-copy">Complete the highlighted task first.</span></p>
        </div>
        <span class="student-badge <?= $requiredActionCount > 0 ? 'student-badge-warning' : 'student-badge-success' ?>">
            <span class="student-dashboard-desktop-copy"><?= (int) $requiredActionCount ?> Pending</span>
            <span class="student-dashboard-mobile-copy"><?= $requiredActionCount > 0 ? (int) $requiredActionCount . ' pending' : 'Ready' ?></span>
        </span>
    </div>

    <div class="student-required-action-list student-dashboard-desktop-task-list">
        <?php if ($passportRequired): ?>
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
            <article class="student-action-card student-dashboard-ape-action">
                <div class="flex items-start gap-4">
                    <span class="student-action-step"><?= $passportRequired ? 2 : 1 ?></span>
                    <span class="student-icon-box">
                        <span class="material-symbols-outlined">upload_file</span>
                    </span>
                    <div>
                        <p class="student-action-kicker student-action-kicker-primary">APE requirement</p>
                        <h2><?= student_e($apeActionTitle) ?></h2>
                        <p><?= student_e($apeActionCopy) ?></p>
                        <?php if ($apeDocumentActionCount > 0): ?>
                            <p class="student-dashboard-action-scope"><strong><?= (int) $apeDocumentActionCount ?> document<?= $apeDocumentActionCount === 1 ? '' : 's' ?> need<?= $apeDocumentActionCount === 1 ? 's' : '' ?> your upload.</strong> Review each requirement below or on APE Status.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="patient-ape-status.php" class="student-button text-decoration-none">
                    Continue APE
                    <span class="material-symbols-outlined">arrow_forward</span>
                </a>
            </article>
        <?php endif; ?>

        <?php if ($feedbackRequired): ?>
            <article class="student-action-card student-action-card-danger">
                <div class="flex items-start gap-4">
                    <span class="student-action-step student-action-step-danger"><?= (int) (($passportRequired ? 1 : 0) + ($apeNeedsAction ? 1 : 0) + 1) ?></span>
                    <span class="student-icon-box student-icon-box-danger">
                        <span class="material-symbols-outlined">rate_review</span>
                    </span>
                    <div>
                        <p class="student-action-kicker">Required clinic feedback</p>
                        <h2>Share feedback for your completed visit</h2>
                        <p><?= count($pendingFeedbackVisits) === 1 ? 'One completed visit needs feedback.' : count($pendingFeedbackVisits) . ' completed visits need feedback.' ?> You cannot request another clinic appointment until all required feedback is completed.</p>
                    </div>
                </div>
                <a href="<?= student_e($feedbackPortalUrl) ?>" class="student-button-danger text-decoration-none">
                    Complete Required Feedback
                    <span class="material-symbols-outlined">arrow_forward</span>
                </a>
            </article>
        <?php endif; ?>

    </div>

    <?php $primaryTask = $dashboardTasks[0]; $secondaryTasks = array_slice($dashboardTasks, 1); ?>
    <div class="student-dashboard-mobile-task-list">
        <article class="student-action-card student-dashboard-mobile-primary-task<?= $primaryTask['tone'] === 'danger' ? ' student-action-card-danger' : '' ?>">
            <div class="student-dashboard-mobile-task-main">
                <span class="student-icon-box<?= $primaryTask['tone'] === 'danger' ? ' student-icon-box-danger' : '' ?>">
                    <span class="material-symbols-outlined"><?= student_e($primaryTask['icon']) ?></span>
                </span>
                <div>
                    <p class="student-action-kicker<?= $primaryTask['tone'] === 'primary' ? ' student-action-kicker-primary' : '' ?>"><?= student_e($primaryTask['kicker']) ?></p>
                    <h2><?= student_e($primaryTask['title']) ?></h2>
                    <p><?= student_e($primaryTask['short_copy']) ?></p>
                </div>
            </div>
            <?php if ($primaryTask['href']): ?>
                <a href="<?= student_e($primaryTask['href']) ?>" class="<?= $primaryTask['tone'] === 'danger' ? 'student-button-danger' : 'student-button' ?> text-decoration-none">
                    <?= student_e($primaryTask['button']) ?>
                    <span class="material-symbols-outlined">arrow_forward</span>
                </a>
            <?php endif; ?>
        </article>

        <?php if ($secondaryTasks): ?>
            <details class="student-dashboard-more-tasks">
                <summary>
                    <span>More tasks</span>
                    <span class="student-badge student-badge-info"><?= count($secondaryTasks) ?></span>
                    <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
                </summary>
                <div class="student-dashboard-more-task-list">
                    <?php foreach ($secondaryTasks as $task): ?>
                        <a href="<?= student_e((string) $task['href']) ?>" class="student-dashboard-more-task text-decoration-none">
                            <span class="student-icon-box<?= $task['tone'] === 'danger' ? ' student-icon-box-danger' : '' ?>"><span class="material-symbols-outlined"><?= student_e($task['icon']) ?></span></span>
                            <span><strong><?= student_e($task['title']) ?></strong><small><?= student_e($task['short_copy']) ?></small></span>
                            <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
</section>
<?php else: ?>
<div class="student-required-action-list student-dashboard-ready-action-list mb-4" aria-label="Student profile status">
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
</div>
<?php endif; ?>

<section class="student-dashboard-mobile-overview" aria-label="Dashboard summaries">
    <a href="patient-ape-status.php" class="student-dashboard-summary-row text-decoration-none" aria-label="View APE Status">
        <span class="student-icon-box"><span class="material-symbols-outlined">task_alt</span></span>
        <span class="student-dashboard-summary-copy">
            <strong>APE Status</strong>
            <span><?= student_e($latestApe ? ape_record_stage_label($latestApe) : $apeStatus) ?> · <?= (int) $apePercent ?>% complete</span>
        </span>
        <span class="student-badge <?= student_e($apeBadgeClass) ?>"><?= student_e($latestApe['clearance_status'] ?? 'Pending') ?></span>
        <span class="material-symbols-outlined student-dashboard-summary-arrow" aria-hidden="true">chevron_right</span>
    </a>

    <?php if ($isOfficialAccess): ?>
    <a href="patient-appointment.php" class="student-dashboard-summary-row text-decoration-none" aria-label="View Appointments">
        <span class="student-icon-box"><span class="material-symbols-outlined">calendar_month</span></span>
        <span class="student-dashboard-summary-copy">
            <strong>Appointments</strong>
            <span><?php if ($latestAppointment): ?><?= student_e(date('M j, Y · g:i A', strtotime($latestAppointment['appointment_datetime']))) ?><?php else: ?>No appointment scheduled<?php endif; ?></span>
        </span>
        <span class="student-badge <?= student_e($appointmentBadgeClass) ?>"><?= student_e($appointmentDisplayStatus) ?></span>
        <span class="material-symbols-outlined student-dashboard-summary-arrow" aria-hidden="true">chevron_right</span>
    </a>
    <?php else: ?>
    <div class="student-dashboard-summary-row" aria-label="Appointments locked for Applicant access">
        <span class="student-icon-box"><span class="material-symbols-outlined">lock</span></span>
        <span class="student-dashboard-summary-copy"><strong>Appointments</strong><span>Available after final APE clearance</span></span>
        <span class="student-badge student-badge-warning">Locked</span>
    </div>
    <?php endif; ?>
</section>

<div class="student-grid student-dashboard-detail-stack">
    <div class="student-dashboard-profile-slot">
    <details class="student-mobile-more dashboard-profile-more" open>
        <summary>Profile details</summary>
    <section class="student-card student-card-pad student-span-4<?= $isOfficialAccess ? ' student-clickable-card' : '' ?>"<?= $isOfficialAccess ? ' data-href="patient-passport.php" role="link" tabindex="0" aria-label="Open Health Passport profile"' : ' aria-label="Patient profile"' ?>>
        <div class="flex items-center gap-3 mb-5">
            <?php $dashboardPhotoPath = profile_photo_normalize_path($profile['profile_photo_path'] ?? null); ?>
            <span class="student-dashboard-profile-photo">
                <?php if ($dashboardPhotoPath !== null): ?>
                    <img src="<?= student_e('../public/' . $dashboardPhotoPath) ?>" alt="<?= student_e($profile['name']) ?> profile picture">
                <?php else: ?>
                    <span><?= student_e(student_initials($profile['name'])) ?></span>
                <?php endif; ?>
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
                <p class="text-sm font-black text-[#17261d] mb-0"><?= student_e($profile['course']) ?><?php if (($profile['account_type'] ?? '') === 'student'): ?> · Year <?= student_e($profile['year_level'] ?: '—') ?> · Section <?= student_e($profile['section'] ?: '—') ?><?php endif; ?></p>
            </div>
            <div>
                <span class="student-label">Email</span>
                <p class="text-sm font-black text-primary mb-0"><?= student_e($profile['email']) ?></p>
            </div>
        </div>

    </section>
    </details>
    </div>

    <section class="student-card student-span-4 student-clickable-card student-dashboard-duplicate student-dashboard-actionable" data-href="patient-ape-status.php" role="link" tabindex="0" aria-label="Open APE status">
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">APE Progress</h2>
                <p class="student-card-copy">Your current clearance path</p>
            </div>
            <span class="student-badge <?= student_e($apeBadgeClass) ?>"><?= student_e($apePhaseLabel) ?></span>
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
                        <strong><?= student_e($apePhaseLabel) ?></strong>
                    </div>
                    <span class="student-badge <?= student_e($apePhaseBadgeClass) ?>"><?= student_e($apePhaseStatus) ?></span>
                </div>
                <div class="student-progress-step">
                    <span class="student-progress-step-icon material-symbols-outlined">cloud_upload</span>
                    <div>
                        <strong><?= student_e($apeActionTitle) ?></strong>
                    </div>
                    <span class="student-badge <?= student_e($apeActionBadgeClass) ?>"><?= student_e($apeActionStatus) ?></span>
                </div>
            </div>
        </div>
    </section>

    <section class="student-card student-span-4<?= $isOfficialAccess ? ' student-clickable-card' : '' ?> student-dashboard-duplicate student-dashboard-appointment-card"<?= $isOfficialAccess ? ' data-href="patient-appointment.php" role="link" tabindex="0" aria-label="Open appointment page"' : ' aria-label="Appointments locked for Applicant access"' ?>>
        <div class="student-card-header">
            <div>
                <h2 class="student-card-title">Appointment</h2>
                <p class="student-card-copy">Latest clinic request status</p>
            </div>
            <span class="student-badge <?= $isOfficialAccess ? student_e($appointmentBadgeClass) : 'student-badge-warning' ?>"><?= $isOfficialAccess ? student_e($appointmentDisplayStatus) : 'Locked' ?></span>
        </div>
        <div class="student-card-pad">
            <?php if (!$isOfficialAccess): ?>
                <div class="student-note student-note-warning mb-4">
                    <span class="material-symbols-outlined">lock</span>
                    <div><strong>Applicant access</strong><br>Appointment booking unlocks after final APE clearance.</div>
                </div>
            <?php elseif ($latestAppointment): ?>
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
            <?php if ($isOfficialAccess): ?>
                <a href="patient-appointment.php" class="student-button-secondary w-full text-decoration-none">
                    Manage Appointment
                    <span class="material-symbols-outlined">schedule</span>
                </a>
            <?php endif; ?>
        </div>
    </section>

</div>

<details class="student-mobile-more dashboard-clinic-notes" open>
    <summary>Clinic notes</summary>
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
</details>

<section class="student-card student-dashboard-help mt-4" aria-labelledby="student-dashboard-help-title">
    <div class="student-card-pad student-dashboard-help-content">
        <span class="student-dashboard-help-icon material-symbols-outlined" aria-hidden="true">help</span>
        <div>
            <h2 id="student-dashboard-help-title" class="student-card-title">Need help?</h2>
            <p class="student-card-copy">Find answers about access, APE requirements, appointments, your Health Passport, and clinic support.</p>
        </div>
        <a href="patient-help.php" class="student-button-secondary text-decoration-none">
            Open Help &amp; FAQs
            <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
        </a>
    </div>
</section>

<script>
(function () {
    if (window.matchMedia('(max-width: 640px)').matches) {
        document.querySelectorAll('.dashboard-profile-more, .dashboard-clinic-notes').forEach((panel) => panel.removeAttribute('open'));
    }
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
