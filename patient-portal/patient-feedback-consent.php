<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/ClinicFeedback.php';

$profile = student_require_login();
student_start_session();
$db = auth_db();
$personId = (int) ($profile['person_id'] ?? 0);
$csrf = csrf_token();
$error = '';
$pendingVisits = clinic_feedback_pending_completed_visits($db, $personId);
$requestedVisitId = filter_var($_GET['visit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$selectedTarget = is_string($_POST['feedback_target'] ?? null)
    ? (string) $_POST['feedback_target']
    : ($requestedVisitId > 0 ? 'visit:' . $requestedVisitId : ($pendingVisits ? '' : 'general'));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_request_is_valid()) {
            throw new InvalidArgumentException('This form has expired. Reload the page and try again.');
        }
        if (($_POST['mode'] ?? '') === 'start_feedback') {
            if (($_POST['participate'] ?? '') !== '1' || ($_POST['consent'] ?? '') !== '1') {
                throw new InvalidArgumentException('Please confirm your participation and privacy consent before continuing.');
            }
            $selectedId = 0;
            if ($selectedTarget !== 'general') {
                if (!preg_match('/^visit:([1-9][0-9]*)$/D', $selectedTarget, $matches)) {
                    throw new InvalidArgumentException('Choose general feedback or one of your completed visits.');
                }
                $selectedId = (int) $matches[1];
                if (!in_array($selectedId, array_map('intval', array_column($pendingVisits, 'visit_id')), true)) {
                    throw new InvalidArgumentException('That completed visit is no longer available for feedback.');
                }
            }
            $_SESSION['student_feedback_started'] = true;
            $_SESSION['student_feedback_consented'] = true;
            $_SESSION['student_feedback_selected_visit_id'] = $selectedId;
            $_SESSION['student_feedback_anonymous'] = $selectedId === 0;
            $_SESSION['student_feedback_start_token'] = bin2hex(random_bytes(32));
            header('Location: patient-feedback.php?start=' . rawurlencode($_SESSION['student_feedback_start_token']));
            exit;
        }
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Student feedback consent: ' . $exception->getMessage());
    $error = 'Feedback is temporarily unavailable. Please try again later.';
}

render_student_header('Give Feedback', 'dashboard');
?>
<div class="student-feedback-page">
    <section class="student-card student-card-pad student-feedback-hero">
        <p class="student-feedback-eyebrow">Clinic feedback</p>
        <h1>Give feedback</h1>
        <p>Tell us about your clinic experience.</p>
    </section>
    <nav class="student-feedback-flow-progress" aria-label="Feedback progress"><span class="is-current"><strong>1 · Before you begin</strong><small>Choose feedback and confirm consent</small></span><span><strong>2 · Give feedback</strong><small>Share your experience</small></span></nav>

    <?php if ($error): ?><div class="student-note student-note-danger student-feedback-notice" role="alert"><span class="material-symbols-outlined" aria-hidden="true">error</span><span><?= student_e($error) ?></span></div><?php endif; ?>
    <section class="student-card student-card-pad student-feedback-visit-summary">
        <div class="student-card-header"><div><p class="student-feedback-eyebrow">Before you begin</p><h2>Would you like to provide clinic feedback?</h2></div><span class="student-badge student-badge-info">Voluntary</span></div>
        <p class="student-feedback-muted">Your feedback helps improve clinic services. Participation is voluntary.</p>
        <form method="post" class="student-feedback-form">
            <input type="hidden" name="_csrf" value="<?= student_e($csrf) ?>">
            <input type="hidden" name="mode" value="start_feedback">
            <fieldset class="student-feedback-targets">
                <legend>What would you like to review?</legend>
                <label class="student-feedback-target-option"><input type="radio" name="feedback_target" value="general" <?= $selectedTarget === 'general' ? 'checked' : '' ?> required><span><strong>General clinic feedback</strong><small>Anonymous · No visit or ID number linked to your response</small></span></label>
                <?php foreach ($pendingVisits as $item): $targetValue = 'visit:' . (int) $item['visit_id']; ?>
                    <label class="student-feedback-target-option"><input type="radio" name="feedback_target" value="<?= student_e($targetValue) ?>" <?= $selectedTarget === $targetValue ? 'checked' : '' ?> required><span><strong><?= student_e(date('F j, Y · g:i A', strtotime((string) $item['visit_datetime']))) ?> · <?= student_e($item['visit_purpose'] ?: 'Clinic visit') ?></strong><small>Completed visit · Confidential and linked to this visit</small></span></label>
                <?php endforeach; ?>
            </fieldset>
            <?php if ($pendingVisits): ?><p class="student-feedback-muted">General feedback will not complete a feedback requirement for any of the listed visits.</p><?php endif; ?>
            <label class="student-feedback-consent"><input type="checkbox" name="participate" value="1" required><span>Yes, I want to provide clinic feedback.</span></label>
            <label class="student-feedback-consent"><input type="checkbox" name="consent" value="1" required><span>I have read the RA 10173 privacy notice and consent to the collection and use of my feedback.</span></label>
            <div class="student-feedback-privacy mt-4">
                <p><strong>Data privacy notice — RA 10173</strong></p>
                <p>Your feedback is collected under the Data Privacy Act of 2012 (Republic Act No. 10173) to evaluate and improve clinic services.</p>
                <details><summary>Read the privacy notice and your rights</summary><div><p>We collect your ratings, comments, and consent record for service quality improvement and clinic reporting. General feedback is anonymous and has no visit or ID number linked to the response. Visit-linked feedback is confidential and may be used for visit-specific follow-up.</p><p>Access is limited to authorized clinic personnel who need it for evaluation, support, or reporting.</p></div></details>
            </div>
            <div class="flex flex-wrap gap-3 mt-4">
                <button type="submit" class="student-button"><span class="material-symbols-outlined" aria-hidden="true">check</span>Submit</button>
                <a href="patient-dashboard.php" class="student-button student-button-secondary text-decoration-none">Not now</a>
            </div>
        </form>
    </section>
</div>
<script>sessionStorage.removeItem('cliniqPatientFeedbackResetPending');</script>
<?php render_student_footer(); ?>
