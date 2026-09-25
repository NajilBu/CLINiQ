<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/ClinicFeedback.php';
require_once __DIR__ . '/../app/services/PatientNotification.php';

$profile = student_require_login();
student_start_session();
$db = auth_db();
$personId = (int) ($profile['person_id'] ?? 0);
$csrf = csrf_token();
$error = '';
$values = [];
$success = !empty($_SESSION['student_feedback_success']);
unset($_SESSION['student_feedback_success']);
$startToken = is_string($_GET['start'] ?? null) ? (string) $_GET['start'] : '';
$storedStartToken = is_string($_SESSION['student_feedback_start_token'] ?? null) ? (string) $_SESSION['student_feedback_start_token'] : '';
$feedbackStarted = $startToken !== '' && $storedStartToken !== '' && hash_equals($storedStartToken, $startToken) && !empty($_SESSION['student_feedback_started']);
$requestedVisitId = filter_var($_GET['visit_id'] ?? $_POST['selected_visit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

try {
    $pendingVisits = clinic_feedback_pending_completed_visits($db, $personId);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$feedbackStarted && !$success) {
        $consentQuery = $requestedVisitId > 0 ? '?visit_id=' . $requestedVisitId : '';
        header('Location: patient-feedback-consent.php' . $consentQuery);
        exit;
    }
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
            $startQuery = ['start' => $_SESSION['student_feedback_start_token']];
            if (!empty($_POST['visit_id'])) {
                $startQuery['visit_id'] = (int) $_POST['visit_id'];
            }
            header('Location: patient-feedback.php?' . http_build_query($startQuery));
            exit;
        }
        if (($_POST['mode'] ?? '') === 'reset_feedback') {
            unset($_SESSION['student_feedback_started'], $_SESSION['student_feedback_anonymous'], $_SESSION['student_feedback_start_token']);
            http_response_code(204);
            exit;
        }
        $selectedId = filter_var($_POST['selected_visit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if (!$selectedId && $pendingVisits) {
            throw new InvalidArgumentException('Select one of your completed visits before submitting feedback.');
        }
        $selected = $selectedId ? clinic_feedback_visit_for_person($db, $personId, $selectedId) : null;
        if ($selectedId && (!$selected || $selected['status'] !== 'Completed' || clinic_feedback_already_sent($db, (int) $selected['visit_id']))) {
            throw new InvalidArgumentException('That completed visit is no longer available for feedback.');
        }
        $values = $_POST;
        clinic_feedback_submit($db, ['person_id' => $personId, 'visit_id' => $selectedId], $_POST);
        if ($selectedId) {
            patient_notification_mark_source_read($db, $personId, 'clinic_feedback', $selectedId);
        }
        $_SESSION['student_feedback_success'] = true;
        unset($_SESSION['student_feedback_started']);
        unset($_SESSION['student_feedback_anonymous']);
        unset($_SESSION['student_feedback_start_token']);
        header('Location: patient-feedback.php');
        exit;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Student feedback: ' . $exception->getMessage());
    $error = 'Feedback is temporarily unavailable. Please try again later.';
}

$pendingVisits ??= [];
$selectedId = (int) ($_GET['visit_id'] ?? ($_POST['selected_visit_id'] ?? ($pendingVisits[0]['visit_id'] ?? 0)));
$selectedVisit = null;
foreach ($pendingVisits as $pendingVisit) {
    if ((int) $pendingVisit['visit_id'] === $selectedId) {
        $selectedVisit = clinic_feedback_visit_for_person($db, $personId, $selectedId);
        break;
    }
}
if (!$selectedVisit && $selectedId > 0 && !$success && $error === '') {
    $error = 'Choose one of your pending completed visits to continue.';
}
$isGeneralFeedback = $selectedVisit === null;

function student_feedback_value(array $values, string $key): string
{
    return is_string($values[$key] ?? null) ? $values[$key] : '';
}

render_student_header('Give Feedback', 'dashboard');
?>
<div class="student-feedback-page">
    <section class="student-card student-card-pad student-feedback-hero">
        <p class="student-feedback-eyebrow">Clinic feedback</p>
        <h1>Give feedback</h1>
        <p>Tell us what your recent clinic visit was like.</p>
    </section>

    <?php if (!$feedbackStarted && !$success): ?>
        <section class="student-card student-card-pad student-feedback-visit-summary">
            <div class="student-card-header"><div><p class="student-feedback-eyebrow">Before you begin</p><h2>Would you like to provide clinic feedback?</h2></div><span class="student-badge student-badge-info">Voluntary</span></div>
            <p class="student-feedback-muted">Your feedback helps improve clinic services. Participation is voluntary, and you may submit anonymously.</p>
            <form method="post" class="student-feedback-form">
                <input type="hidden" name="_csrf" value="<?= student_e($csrf) ?>">
                <input type="hidden" name="mode" value="start_feedback">
                <input type="hidden" name="visit_id" value="<?= (int) $selectedId ?>">
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
    <?php elseif ($error): ?>
        <div class="student-note student-note-danger student-feedback-notice" role="alert"><span class="material-symbols-outlined" aria-hidden="true">error</span><span><?= student_e($error) ?></span></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <section class="student-card student-card-pad student-feedback-success" role="status">
            <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
            <h2>Feedback submitted</h2>
            <p>Thank you. Your response has been recorded and your next clinic actions are updated.</p>
            <a class="student-button" href="patient-dashboard.php">Back to dashboard</a>
        </section>
    <?php else: ?>
        <?php if (count($pendingVisits) > 1): ?>
            <section class="student-card student-card-pad student-feedback-visits">
                <div class="student-card-header"><div><p class="student-feedback-eyebrow">Pending visits</p><h2>Choose a visit</h2></div><span class="student-feedback-count"><?= count($pendingVisits) ?></span></div>
                <div class="student-feedback-visit-list">
                    <?php foreach ($pendingVisits as $item): ?>
                        <a class="student-feedback-visit-option" data-feedback-internal="1" href="patient-feedback.php?start=<?= student_e($startToken) ?>&visit_id=<?= (int) $item['visit_id'] ?>">
                            <span><strong><?= student_e(date('M j, Y · g:i A', strtotime((string) $item['visit_datetime']))) ?></strong><small><?= student_e($item['visit_purpose'] ?: 'Clinic visit') ?></small></span><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($selectedVisit || $isGeneralFeedback): ?>
            <?php if (!$isGeneralFeedback): ?>
            <section class="student-card student-card-pad student-feedback-visit-summary">
                <div class="student-card-header"><div><p class="student-feedback-eyebrow">Selected visit</p><h2><?= student_e(date('F j, Y · g:i A', strtotime((string) $selectedVisit['visit_datetime']))) ?></h2></div><span class="student-badge student-badge-success">Completed</span></div>
                <dl class="student-feedback-visit-details">
                    <div><dt>Reason for visit</dt><dd><?= student_e($selectedVisit['visit_purpose'] ?: 'Not recorded') ?></dd></div>
                    <div><dt>Patient concern</dt><dd><?= student_e($selectedVisit['chief_complaint'] ?: 'Not recorded') ?></dd></div>
                </dl>
            </section>
            <?php else: ?>
            <section class="student-card student-card-pad student-feedback-visit-summary">
                <div class="student-card-header"><div><p class="student-feedback-eyebrow">General feedback</p><h2>Share your clinic experience</h2></div><span class="student-badge student-badge-success">No visit link</span></div>
                <p class="student-feedback-muted">You can submit anonymous feedback even when you do not have a completed visit selected.</p>
            </section>
            <?php endif; ?>

            <form method="post" id="student-feedback-form" class="student-feedback-form">
                <input type="hidden" name="_csrf" value="<?= student_e($csrf) ?>">
                <input type="hidden" name="selected_visit_id" value="<?= $isGeneralFeedback ? '' : (int) $selectedVisit['visit_id'] ?>">
                <input type="hidden" name="consent" value="1">
                <input type="hidden" name="anonymous" value="<?= !empty($_SESSION['student_feedback_anonymous']) ? '1' : '0' ?>">
                <section class="student-card student-card-pad">
                    <div class="student-feedback-step-heading"><span>1</span><div><h2>Rate your visit</h2><p>Choose one answer for each statement.</p></div></div>
                    <?php if ($isGeneralFeedback): ?>
                    <label class="student-field">Service being reviewed<select class="student-input" name="service_type" required><option value="">Choose a service</option><?php foreach (clinic_feedback_services() as $service): ?><option value="<?= student_e($service) ?>" <?= student_feedback_value($values, 'service_type') === $service ? 'selected' : '' ?>><?= student_e($service) ?></option><?php endforeach; ?></select></label>
                    <?php endif; ?>
                    <?php foreach (clinic_feedback_sections() as $section => $questions): ?>
                        <details class="student-feedback-rating-group" <?= $section === 'Tangibles' ? 'open' : '' ?>><summary><?= student_e($section) ?><span class="material-symbols-outlined" aria-hidden="true">expand_more</span></summary>
                            <?php foreach ($questions as $code => $question): ?><fieldset class="student-feedback-question"><legend><?= student_e($question) ?> <span aria-label="required">*</span></legend><div class="student-feedback-scale"><?php for ($rating = 1; $rating <= 7; $rating++): ?><label><input type="radio" name="ratings[<?= student_e($code) ?>]" value="<?= $rating ?>" required <?= is_array($values['ratings'] ?? null) && (string) ($values['ratings'][$code] ?? '') === (string) $rating ? 'checked' : '' ?>><span><?= $rating ?></span></label><?php endfor; ?></div><div class="student-feedback-scale-labels"><span>Strongly disagree</span><span>Strongly agree</span></div></fieldset><?php endforeach; ?>
                        </details>
                    <?php endforeach; ?>
                </section>
                <section class="student-card student-card-pad">
                    <div class="student-feedback-step-heading"><span>2</span><div><h2>Anything else?</h2><p>Optional comments help us understand your experience.</p></div></div>
                    <label class="student-field">Comments<textarea class="student-input student-feedback-textarea" name="comments" maxlength="5000" rows="4" placeholder="Share a suggestion, compliment, or concern"><?= student_e(student_feedback_value($values, 'comments')) ?></textarea></label>
                    <button type="submit" class="student-button student-feedback-submit"><span class="material-symbols-outlined" aria-hidden="true">send</span>Submit feedback</button>
                </section>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php if ($feedbackStarted && !$success): ?>
<style>
    #feedback-leave-dialog { margin: auto; width: min(460px, calc(100% - 32px)); max-height: calc(100dvh - 32px); overflow: auto; padding: 28px; }
    #feedback-leave-dialog::backdrop { background: rgb(15 23 42 / 55%); backdrop-filter: blur(3px); }
    #feedback-leave-dialog h2 { margin: 0 0 12px; }
    #feedback-leave-dialog .feedback-leave-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 12px; margin-top: 24px; }
</style>
<dialog id="feedback-leave-dialog" class="student-card" aria-labelledby="feedback-leave-title" aria-describedby="feedback-leave-description">
    <h2 id="feedback-leave-title">Leave survey?</h2>
    <p id="feedback-leave-description">Leaving will reset this survey and discard your answers. You will need to confirm your consent again when you return.</p>
    <p id="feedback-leave-error" role="alert" hidden>Unable to reset the survey. Please try again.</p>
    <div class="feedback-leave-actions">
        <button type="button" id="feedback-stay" class="student-button student-button-secondary" autofocus>Stay</button>
        <button type="button" id="feedback-leave" class="student-button">Leave and reset</button>
    </div>
</dialog>
<script>
(() => {
    const csrf = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let leaving = false;
    let submitting = false;
    const resetFeedback = () => {
        const body = new URLSearchParams({ _csrf: csrf, mode: 'reset_feedback' });
        if (navigator.sendBeacon) {
            navigator.sendBeacon('patient-feedback.php', body);
        } else {
            fetch('patient-feedback.php', { method: 'POST', body, keepalive: true, credentials: 'same-origin' }).catch(() => {});
        }
    };
    window.addEventListener('beforeunload', (event) => {
        if (!leaving && !submitting) {
            event.preventDefault();
            event.returnValue = 'Leaving will reset this feedback survey and discard your answers.';
        }
    });
    window.addEventListener('pagehide', () => {
        if (leaving && !submitting) resetFeedback();
    });
    const leaveDialog = document.getElementById('feedback-leave-dialog');
    const leaveButton = document.getElementById('feedback-leave');
    const stayButton = document.getElementById('feedback-stay');
    const leaveError = document.getElementById('feedback-leave-error');
    let destination = null;
    let resetting = false;
    stayButton.addEventListener('click', () => leaveDialog.close());
    leaveDialog.addEventListener('cancel', (event) => {
        if (resetting) event.preventDefault();
    });
    leaveDialog.addEventListener('close', () => { destination = null; });
    leaveButton.addEventListener('click', async () => {
        if (!destination || resetting) return;
        resetting = true;
        leaveButton.disabled = stayButton.disabled = true;
        leaveButton.textContent = 'Resetting…';
        leaveError.hidden = true;
        try {
            const response = await fetch('patient-feedback.php', {
                method: 'POST', credentials: 'same-origin',
                body: new URLSearchParams({ _csrf: csrf, mode: 'reset_feedback' })
            });
            if (response.status !== 204) throw new Error('Reset failed');
            leaving = true;
            window.location.assign(destination);
        } catch (_) {
            leaveError.hidden = false;
            resetting = false;
            leaveButton.disabled = stayButton.disabled = false;
            leaveButton.textContent = 'Leave and reset';
        }
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || link.dataset.feedbackInternal === '1' || link.closest('#student-feedback-form')) return;
        if (link.href && !link.href.includes('patient-feedback.php')) {
            event.preventDefault();
            destination = link.href;
            leaveError.hidden = true;
            if (!leaveDialog.open) leaveDialog.showModal();
        }
    });
    const surveyForm = document.getElementById('student-feedback-form');
    const ratingSections = Array.from(surveyForm?.querySelectorAll('.student-feedback-rating-group') || []);
    surveyForm?.addEventListener('change', (event) => {
        if (!event.target.matches('input[type="radio"]')) return;
        const section = event.target.closest('.student-feedback-rating-group');
        const sectionIndex = ratingSections.indexOf(section);
        if (sectionIndex < 0) return;
        const questions = Array.from(section.querySelectorAll('.student-feedback-question'));
        if (questions.length && questions.every((question) => question.querySelector('input[type="radio"]:checked'))) {
            const nextSection = ratingSections[sectionIndex + 1];
            if (nextSection) nextSection.open = true;
        }
    });
    surveyForm?.addEventListener('submit', () => { submitting = true; });
})();
</script>
<?php endif; ?>
<?php render_student_footer(); ?>
