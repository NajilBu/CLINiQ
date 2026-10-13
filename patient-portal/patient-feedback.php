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
$feedbackStarted = $startToken !== '' && $storedStartToken !== '' && hash_equals($storedStartToken, $startToken)
    && !empty($_SESSION['student_feedback_started']) && !empty($_SESSION['student_feedback_consented'])
    && array_key_exists('student_feedback_selected_visit_id', $_SESSION);
$selectedId = (int) ($_SESSION['student_feedback_selected_visit_id'] ?? 0);
$selectedVisit = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$feedbackStarted && !$success) {
        $requestedVisitId = filter_var($_GET['visit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $consentQuery = $requestedVisitId > 0 ? '?visit_id=' . $requestedVisitId : '';
        header('Location: patient-feedback-consent.php' . $consentQuery);
        exit;
    }
    if ($feedbackStarted && $selectedId > 0) {
        $selectedVisit = clinic_feedback_visit_for_person($db, $personId, $selectedId);
        if (!$selectedVisit || $selectedVisit['status'] !== 'Completed' || clinic_feedback_already_sent($db, $selectedId)) {
            unset($_SESSION['student_feedback_started'], $_SESSION['student_feedback_consented'], $_SESSION['student_feedback_anonymous'], $_SESSION['student_feedback_selected_visit_id'], $_SESSION['student_feedback_start_token']);
            $feedbackStarted = false;
            throw new InvalidArgumentException('That completed visit is no longer available for feedback. Start again from the consent page.');
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_request_is_valid()) {
            throw new InvalidArgumentException('This form has expired. Reload the page and try again.');
        }
        if (($_POST['mode'] ?? '') === 'reset_feedback') {
            unset($_SESSION['student_feedback_started'], $_SESSION['student_feedback_consented'], $_SESSION['student_feedback_anonymous'], $_SESSION['student_feedback_selected_visit_id'], $_SESSION['student_feedback_start_token']);
            http_response_code(204);
            exit;
        }
        if (!$feedbackStarted || !hash_equals($storedStartToken, (string) ($_POST['start_token'] ?? ''))) {
            throw new InvalidArgumentException('Confirm participation and privacy consent before submitting feedback.');
        }
        $values = $_POST;
        $values['consent'] = '1';
        $values['anonymous'] = !empty($_SESSION['student_feedback_anonymous']) ? '1' : '0';
        clinic_feedback_submit($db, ['person_id' => $personId, 'visit_id' => $selectedId], $values);
        if ($selectedId) {
            patient_notification_mark_source_read($db, $personId, 'clinic_feedback', $selectedId);
        }
        $_SESSION['student_feedback_success'] = true;
        unset($_SESSION['student_feedback_started'], $_SESSION['student_feedback_consented'], $_SESSION['student_feedback_anonymous'], $_SESSION['student_feedback_selected_visit_id'], $_SESSION['student_feedback_start_token']);
        header('Location: patient-feedback.php');
        exit;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Student feedback: ' . $exception->getMessage());
    $error = 'Feedback is temporarily unavailable. Please try again later.';
}

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
        <p>Tell us about your clinic experience.</p>
    </section>
    <?php if ($feedbackStarted && !$success): ?><nav class="student-feedback-flow-progress" aria-label="Feedback progress"><span class="is-complete"><strong>1 · Before you begin</strong><small>Consent confirmed</small></span><span class="is-current"><strong>2 · Give feedback</strong><small>Share your experience</small></span></nav><?php endif; ?>

    <?php if ($error): ?>
        <div class="student-note student-note-danger student-feedback-notice" role="alert"><span class="material-symbols-outlined" aria-hidden="true">error</span><span><?= student_e($error) ?></span></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <section class="student-card student-card-pad student-feedback-success" role="status">
            <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
            <h2>Feedback submitted</h2>
            <p>Thank you. Your clinic feedback has been recorded.</p>
            <a class="student-button cliniq-floating-back cliniq-floating-back--patient" href="patient-dashboard.php">Back to dashboard</a>
        </section>
    <?php elseif (!$feedbackStarted): ?>
        <section class="student-card student-card-pad student-feedback-visit-summary">
            <div class="student-card-header"><div><p class="student-feedback-eyebrow">Before you begin</p><h2>Consent is required before the survey</h2></div></div>
            <p>Choose a completed visit, then confirm participation and privacy consent.</p>
            <a class="student-button text-decoration-none" href="patient-feedback-consent.php">Go to consent page</a>
        </section>
    <?php else: ?>
        <?php if ($selectedVisit): ?>
            <section class="student-card student-card-pad student-feedback-visit-summary">
                <div class="student-card-header"><div><p class="student-feedback-eyebrow">Selected visit</p><h2><?= student_e(date('F j, Y · g:i A', strtotime((string) $selectedVisit['visit_datetime']))) ?></h2></div><span class="student-badge student-badge-success">Completed</span></div>
                <dl class="student-feedback-visit-details">
                    <div><dt>Reason for visit</dt><dd><?= student_e($selectedVisit['visit_purpose'] ?: 'Not recorded') ?></dd></div>
                    <div><dt>Patient concern</dt><dd><?= student_e($selectedVisit['chief_complaint'] ?: 'Not recorded') ?></dd></div>
                </dl>
            </section>
            <form method="post" id="student-feedback-form" class="student-feedback-form" data-no-discard-warning>
                <input type="hidden" name="_csrf" value="<?= student_e($csrf) ?>">
                <input type="hidden" name="start_token" value="<?= student_e($startToken) ?>">
                <nav class="student-feedback-survey-progress" aria-label="Feedback form progress"><span class="is-current" data-feedback-progress="1">1 · Ratings</span><span data-feedback-progress="2">2 · Comments</span></nav>
                <div class="student-feedback-survey-step" data-feedback-step="1">
                <section class="student-card student-card-pad">
                    <div class="student-feedback-step-heading"><span>1</span><div><h2>Rate your visit</h2><p>Choose one answer for each statement.</p></div></div>
                    <?php foreach (clinic_feedback_sections() as $section => $questions): ?>
                        <details class="student-feedback-rating-group" <?= $section === 'Tangibles' ? 'open' : '' ?>><summary><?= student_e($section) ?><span class="material-symbols-outlined" aria-hidden="true">expand_more</span></summary>
                            <?php foreach ($questions as $code => $question): ?><fieldset class="student-feedback-question"><legend><?= student_e($question) ?> <span aria-label="required">*</span></legend><div class="student-feedback-scale"><?php for ($rating = 1; $rating <= 7; $rating++): ?><label><input type="radio" name="ratings[<?= student_e($code) ?>]" value="<?= $rating ?>" required <?= is_array($values['ratings'] ?? null) && (string) ($values['ratings'][$code] ?? '') === (string) $rating ? 'checked' : '' ?>><span><?= $rating ?></span></label><?php endfor; ?></div><div class="student-feedback-scale-labels"><span>Strongly disagree</span><span>Strongly agree</span></div></fieldset><?php endforeach; ?>
                        </details>
                    <?php endforeach; ?>
                    <div class="student-feedback-step-actions"><button type="button" class="student-button" data-feedback-next>Continue to comments <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button></div>
                </section>
                </div>
                <div class="student-feedback-survey-step" data-feedback-step="2" hidden>
                <section class="student-card student-card-pad">
                    <div class="student-feedback-step-heading"><span>2</span><div><h2>Anything else?</h2><p>Optional comments help us understand your experience.</p></div></div>
                    <label class="student-field">Comments<textarea class="student-input student-feedback-textarea" name="comments" maxlength="5000" rows="4" placeholder="Share a suggestion, compliment, or concern"><?= student_e(student_feedback_value($values, 'comments')) ?></textarea></label>
                    <div class="student-feedback-step-actions"><button type="button" class="student-button student-button-secondary" data-feedback-back>Back to ratings</button><button type="submit" class="student-button student-feedback-submit"><span class="material-symbols-outlined" aria-hidden="true">send</span>Submit feedback</button></div>
                </section>
                </div>
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
<dialog id="feedback-leave-dialog" class="student-card" data-student-overlay aria-labelledby="feedback-leave-title" aria-describedby="feedback-leave-description">
    <h2 id="feedback-leave-title">Leave feedback?</h2>
    <p id="feedback-leave-description">Leaving will reset this feedback form and discard your answers and consent. Continue?</p>
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
    const resetMarker = 'cliniqPatientFeedbackResetPending';
    const resetFeedback = () => {
        const body = new URLSearchParams({ _csrf: csrf, mode: 'reset_feedback' });
        if (!navigator.sendBeacon?.('patient-feedback.php', body)) {
            fetch('patient-feedback.php', { method: 'POST', body, keepalive: true, credentials: 'same-origin' }).catch(() => {});
        }
    };
    window.addEventListener('beforeunload', (event) => {
        if (!leaving && !submitting) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.addEventListener('pagehide', () => {
        if (leaving || submitting) return;
        sessionStorage.setItem(resetMarker, '1');
        resetFeedback();
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
            sessionStorage.removeItem(resetMarker);
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
        if (!link || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank' || link.hasAttribute('download')) return;
        event.preventDefault();
        destination = link.href;
        leaveError.hidden = true;
        if (!leaveDialog.open) leaveDialog.showModal();
    });
    const surveyForm = document.getElementById('student-feedback-form');
    const surveySteps = Array.from(surveyForm?.querySelectorAll('[data-feedback-step]') || []);
    const surveyProgress = Array.from(surveyForm?.querySelectorAll('[data-feedback-progress]') || []);
    let currentStep = 1;
    const showStep = step => {
        currentStep = step;
        surveySteps.forEach(panel => { panel.hidden = Number(panel.dataset.feedbackStep) !== step; });
        surveyProgress.forEach(item => {
            const number = Number(item.dataset.feedbackProgress);
            item.classList.toggle('is-current', number === step);
            item.classList.toggle('is-complete', number < step);
        });
        surveyForm?.querySelector('.student-feedback-survey-progress')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    surveyForm?.querySelector('[data-feedback-next]')?.addEventListener('click', () => {
        const fields = Array.from(surveySteps[0]?.querySelectorAll('[required]') || []);
        const invalid = fields.find(field => !field.checkValidity());
        if (invalid) {
            const group = invalid.closest('details');
            if (group) group.open = true;
            invalid.reportValidity();
            return;
        }
        showStep(2);
    });
    surveyForm?.querySelector('[data-feedback-back]')?.addEventListener('click', () => showStep(1));
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
    let resettingPending = false;
    const completePendingReset = () => {
        if (resettingPending || sessionStorage.getItem(resetMarker) !== '1') return;
        resettingPending = true;
        if (surveyForm) surveyForm.hidden = true;
        fetch('patient-feedback.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ _csrf: csrf, mode: 'reset_feedback' }) })
            .then(response => {
                if (response.status !== 204) throw new Error('Reset failed');
                sessionStorage.removeItem(resetMarker);
                leaving = true;
                window.location.replace('patient-feedback-consent.php');
            }).catch(() => {
                resettingPending = false;
                if (surveyForm) surveyForm.hidden = false;
                destination = 'patient-feedback-consent.php';
                leaveError.hidden = false;
                if (!leaveDialog.open) leaveDialog.showModal();
            });
    };
    window.addEventListener('pageshow', event => {
        if (event.persisted) sessionStorage.setItem(resetMarker, '1');
        completePendingReset();
    });
    completePendingReset();
})();
</script>
<?php endif; ?>
<?php render_student_footer(); ?>
