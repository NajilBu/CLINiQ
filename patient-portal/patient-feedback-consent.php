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
$selectedId = (int) ($_GET['visit_id'] ?? ($_POST['visit_id'] ?? 0));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_request_is_valid()) {
            throw new InvalidArgumentException('This form has expired. Reload the page and try again.');
        }
        if (($_POST['mode'] ?? '') === 'start_feedback') {
            if (($_POST['participate'] ?? '') !== '1' || ($_POST['consent'] ?? '') !== '1') {
                throw new InvalidArgumentException('Please confirm your participation and privacy consent before continuing.');
            }
            $_SESSION['student_feedback_started'] = true;
            $_SESSION['student_feedback_anonymous'] = !empty($_POST['anonymous']);
            $_SESSION['student_feedback_start_token'] = bin2hex(random_bytes(32));
            $query = ['start' => $_SESSION['student_feedback_start_token']];
            if ($selectedId > 0) $query['visit_id'] = $selectedId;
            header('Location: patient-feedback.php?' . http_build_query($query));
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
        <p>Tell us what your recent clinic visit was like.</p>
    </section>

    <?php if ($error): ?><div class="student-note student-note-danger student-feedback-notice" role="alert"><span class="material-symbols-outlined" aria-hidden="true">error</span><span><?= student_e($error) ?></span></div><?php endif; ?>
    <section class="student-card student-card-pad student-feedback-visit-summary">
        <div class="student-card-header"><div><p class="student-feedback-eyebrow">Before you begin</p><h2>Would you like to provide clinic feedback?</h2></div><span class="student-badge student-badge-info">Voluntary</span></div>
        <p class="student-feedback-muted">Your feedback helps improve clinic services. Participation is voluntary, and you may submit anonymously.</p>
        <form method="post" class="student-feedback-form">
            <input type="hidden" name="_csrf" value="<?= student_e($csrf) ?>">
            <input type="hidden" name="mode" value="start_feedback">
            <input type="hidden" name="visit_id" value="<?= $selectedId ?>">
            <label class="student-feedback-consent"><input type="checkbox" name="participate" value="1" required><span>Yes, I want to provide clinic feedback.</span></label>
            <label class="student-feedback-consent"><input type="checkbox" name="anonymous" value="1" checked><span>Submit anonymously (do not identify me in the feedback record).</span></label>
            <label class="student-feedback-consent"><input type="checkbox" name="consent" value="1" required><span>I have read the RA 10173 privacy notice and consent to the collection and use of my feedback.</span></label>
            <div class="student-feedback-privacy mt-4">
                <p><strong>Data privacy notice — RA 10173</strong></p>
                <p>Your feedback is collected under the Data Privacy Act of 2012 (Republic Act No. 10173) to evaluate and improve clinic services.</p>
                <details><summary>Read the privacy notice and your rights</summary><div><p>We collect your ratings, comments, and consent record for service quality improvement and clinic reporting. You may submit anonymously; when you choose a visit, it is used only to improve visit-specific follow-up.</p><p>Access is limited to authorized clinic personnel who need it for evaluation, support, or reporting.</p></div></details>
            </div>
            <div class="flex flex-wrap gap-3 mt-4">
                <button type="submit" class="student-button"><span class="material-symbols-outlined" aria-hidden="true">check</span>Submit</button>
                <a href="patient-dashboard.php" class="student-button student-button-secondary text-decoration-none">Not now</a>
            </div>
        </form>
    </section>
</div>
<?php render_student_footer(); ?>
