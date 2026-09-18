<?php
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/services/ClinicFeedback.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
$_SESSION['feedback_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$visit = null;
$visits = [];
$values = [];
$success = !empty($_SESSION['feedback_success']);
unset($_SESSION['feedback_success']);
$available = false;
$identifier = '';
$context = $_SESSION['feedback_context'] ?? null;
if ($context && (int) ($context['expires'] ?? 0) < time()) {
    unset($_SESSION['feedback_context']);
    $context = null;
}
try {
    $db = auth_db();
    $available = clinic_feedback_ready($db);
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['portal'] ?? '') === '1' && $available) {
        $personId = (int) ($_SESSION['patient_person_id'] ?? $_SESSION['student_person_id'] ?? 0);
        $identity = null;
        if ($personId > 0) {
            $identityQuery = $db->prepare("SELECT p.id_number
                FROM people p
                JOIN accounts a ON a.person_id = p.id
                WHERE p.id = ? AND a.account_status = 'active'
                LIMIT 1");
            $identityQuery->execute([$personId]);
            $identity = $identityQuery->fetch();
        }
        $pendingVisits = $personId > 0 ? clinic_feedback_pending_active_visits($db, $personId) : [];
        if ($identity && $pendingVisits) {
            $_SESSION['feedback_context'] = [
                'identifier' => normalize_id_number((string) $identity['id_number']), 'visit_id' => 0,
                'expires' => time() + 3600, 'token' => bin2hex(random_bytes(32)),
            ];
            header('Location: ' . app_url('clinic-feedback.php'));
            exit;
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$available) throw new InvalidArgumentException('Feedback is temporarily unavailable. Please try again later.');
        $token = $_POST['csrf'] ?? null;
        if (!is_string($token) || !hash_equals($_SESSION['feedback_csrf'], $token)) {
            throw new InvalidArgumentException('This form has expired. Reload the page and try again.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'reset') {
            unset($_SESSION['feedback_context']);
            header('Location: ' . app_url('clinic-feedback.php'));
            exit;
        }
        if ($action === 'lookup') {
            unset($_SESSION['feedback_context']);
            $context = null;
            if (time() - (int) ($_SESSION['feedback_last_lookup'] ?? 0) < 2) {
                throw new InvalidArgumentException('Please wait a moment before trying again.');
            }
            $_SESSION['feedback_last_lookup'] = time();
            $raw = $_POST['identifier'] ?? '';
            if (!is_string($raw) || strlen($raw) > 30) throw new InvalidArgumentException('Enter a valid student ID.');
            $identifier = normalize_id_number($raw);
            if (!preg_match('/^\d{2}-\d{5}$/D', $identifier)) throw new InvalidArgumentException('Enter a student ID such as 23-00262.');
            $found = clinic_feedback_visits($db, $identifier);
            if (!$found) throw new InvalidArgumentException('No Active or Completed clinic visits were found for this student ID. Check your ID or ask the clinic staff.');
            $_SESSION['feedback_context'] = [
                'identifier' => $identifier, 'visit_id' => 0,
                'expires' => time() + 3600, 'token' => bin2hex(random_bytes(32)),
            ];
            header('Location: ' . app_url('clinic-feedback.php'));
            exit;
        }
        if (in_array($action, ['select', 'choose_again'], true)) {
            $selectionToken = $_POST['visit_token'] ?? null;
            if (!$context || !is_string($selectionToken) || !hash_equals($context['token'], $selectionToken)) {
                throw new InvalidArgumentException('Your visit selection has expired or changed. Look up your student ID again.');
            }
            $selectedId = 0;
            if ($action === 'select') {
                $selectedId = filter_var($_POST['visit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $selected = $selectedId ? clinic_feedback_visit($db, $context['identifier'], $selectedId) : null;
                if (!$selected || !clinic_feedback_eligible($selected['status'])) throw new InvalidArgumentException('Please select an available visit from your list.');
                if (clinic_feedback_already_sent($db, $selectedId)) throw new InvalidArgumentException('Feedback has already been submitted for this visit. Please choose another.');
            }
            $_SESSION['feedback_context'] = array_replace($context, ['visit_id' => $selectedId, 'token' => bin2hex(random_bytes(32))]);
            header('Location: ' . app_url('clinic-feedback.php'));
            exit;
        }
        if ($action === 'submit') {
            $values = $_POST;
            $formToken = $_POST['visit_token'] ?? null;
            if (!$context || !is_string($formToken) || !hash_equals($context['token'], $formToken)) {
                unset($_SESSION['feedback_context']);
                $context = null;
                $values = [];
                throw new InvalidArgumentException('Your visit selection has expired or changed. Look up your student ID again.');
            }
            clinic_feedback_submit($db, $context, $_POST);
            unset($_SESSION['feedback_context']);
            $_SESSION['feedback_success'] = true;
            $_SESSION['feedback_csrf'] = bin2hex(random_bytes(32));
            header('Location: ' . app_url('clinic-feedback.php'));
            exit;
        }
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Clinic feedback: ' . $exception->getMessage());
    $error = 'Feedback is temporarily unavailable. Please try again later.';
}
if ($available && $context) {
    try {
        $found = $context['visit_id'] ? clinic_feedback_visit($db, $context['identifier'], (int) $context['visit_id']) : null;
        if ($found && clinic_feedback_eligible($found['status']) && !clinic_feedback_already_sent($db, (int) $found['visit_id'])) {
            $visit = $found;
        } elseif ($context['visit_id']) {
            $context['visit_id'] = 0;
            $context['token'] = bin2hex(random_bytes(32));
            $_SESSION['feedback_context'] = $context;
            $values = [];
            $error = $error ?: 'The selected visit is no longer available or already received feedback. Please choose another visit.';
        }
        if (!$visit) $visits = clinic_feedback_visits($db, $context['identifier']);
    } catch (Throwable $exception) {
        error_log('Clinic feedback lookup: ' . $exception->getMessage());
        $error = 'Feedback is temporarily unavailable. Please try again later.';
    }
}
if ($visit && !$values) {
    $years = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
    $purpose = (string) ($visit['visit_purpose'] ?? '');
    $values = [
        'service_type' => clinic_feedback_default_service($purpose),
        'service_other' => clinic_feedback_default_service($purpose) === 'Other' ? $purpose : '',
        'year_level' => $years[(int) $visit['year_level']] ?? 'Other',
        'year_other' => isset($years[(int) $visit['year_level']]) ? '' : (string) $visit['year_level'],
        'program' => (string) ($visit['program_code'] ?? ''),
    ];
}
function feedback_value(array $values, string $key): string {
    return is_string($values[$key] ?? null) ? $values[$key] : '';
}
$theme = active_cliniq_theme();
$feedbackStep = $success ? 4 : ($visit ? 3 : ($context ? 2 : 1));
$feedbackSteps = [
    1 => ['label' => 'Find visit', 'description' => 'Enter your student ID'],
    2 => ['label' => 'Choose visit', 'description' => 'Select the visit to review'],
    3 => ['label' => 'Give feedback', 'description' => 'Share your experience'],
];
?>
<!doctype html>
<html lang="en" class="feedback-document<?= !empty($theme['dark_mode']) ? ' cliniq-dark' : '' ?>"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <script src="<?= app_url('assets/js/csrf.js?v=' . filemtime(__DIR__ . '/assets/js/csrf.js')) ?>" defer></script>
    <script src="<?= app_url('assets/js/id-number-format.js?v=' . filemtime(__DIR__ . '/assets/js/id-number-format.js')) ?>" defer></script>
    <title>Give Clinic Feedback | CLINiQ</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/vendor/fonts/inter-manrope.css?v=offline-1')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/vendor/fonts/material-symbols.css?v=offline-1')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/app.css?v=' . filemtime(__DIR__ . '/assets/css/app.css'))) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/feedback.css?v=design-3')) ?>">
    <style>
        :root {
            --cliniq-primary: <?= e($theme['primary']) ?>;
            --cliniq-primary-hover: <?= e($theme['primary_container']) ?>;
            --cliniq-primary-fixed: <?= e($theme['primary_fixed']) ?>;
            --cliniq-accent: <?= e($theme['accent']) ?>;
            --cliniq-accent-foreground: <?= e($theme['primary_container']) ?>;
            --cliniq-surface: <?= e($theme['surface']) ?>;
            --cliniq-surface-low: <?= e($theme['surface_container_low']) ?>;
            --cliniq-outline: <?= e($theme['outline_variant']) ?>;
            --cliniq-focus-rgb: <?= e($theme['focus_rgb']) ?>;
            --cliniq-shadow-rgb: <?= e($theme['shadow_rgb']) ?>;
        }
    </style>
</head>
<body class="feedback-page feedback-public">
<?php render_cliniq_entry_header(['homeUrl' => app_url('index.php')]); ?>
<main class="feedback-shell">
    <header class="clinic-card feedback-section">
        <span class="feedback-eyebrow">University Clinic · Student experience</span>
        <h1>Give Clinic Feedback</h1>
        <p>Help us improve your campus healthcare in three simple steps.</p>
        <p class="feedback-muted">Your student ID is all you need. No login, password, or email required.</p>
    </header>
    <nav class="feedback-progress clinic-card" aria-label="Feedback progress">
        <?php foreach ($feedbackSteps as $stepNumber => $step): ?>
            <?php $stepState = $stepNumber < $feedbackStep ? 'is-complete' : ($stepNumber === $feedbackStep ? 'is-current' : ''); ?>
            <div class="feedback-progress-item <?= e($stepState) ?>" <?= $stepNumber === $feedbackStep ? 'aria-current="step"' : '' ?>>
                <span class="feedback-progress-marker" aria-hidden="true"><?= $stepNumber < $feedbackStep ? '<span class="material-symbols-outlined">check</span>' : (int) $stepNumber ?></span>
                <span class="feedback-progress-copy"><strong><?= e($step['label']) ?></strong><span><?= e($step['description']) ?></span></span>
            </div>
            <?php if ($stepNumber < count($feedbackSteps)): ?><span class="feedback-progress-line" aria-hidden="true"></span><?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php if ($error): ?><div class="feedback-notice" role="alert" id="feedback-error" tabindex="-1"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
        <section class="clinic-card feedback-section feedback-success" role="status"><h2>Thank you for your valuable feedback!</h2><p>Your response has been recorded for your clinic visit. Your input helps improve our university's clinical care.</p><p class="feedback-muted">Returning to the homepage in <strong id="feedbackCountdown">10</strong> seconds.</p><a class="btn btn-primary" href="<?= e(app_url('index.php')) ?>">Return to homepage</a></section>
    <?php elseif (!$available): ?>
        <div class="feedback-notice" role="alert">Feedback is temporarily unavailable. Please try again later.</div>
    <?php elseif (!$visit && $context): ?>
        <section class="clinic-card feedback-section">
            <h2>Choose a clinic visit</h2>
            <p class="feedback-muted">Active and Completed visits are listed newest first. Each visit accepts one response.</p>
            <?php if (!$visits): ?><p>No Active or Completed visits are currently available.</p><?php endif; ?>
            <?php if ($visits && !array_filter($visits, fn($item) => !$item['feedback_submitted'])): ?><p role="status">Feedback has been submitted for all listed visits. Thank you!</p><?php endif; ?>
            <?php foreach ($visits as $item): ?>
                <?php if ($item['feedback_submitted']): ?>
                    <article class="feedback-visit feedback-visit-submitted" aria-label="Feedback already submitted">
                        <strong><?= e(date('F j, Y · g:i A', strtotime($item['visit_datetime']))) ?></strong><br>
                        <?= e($item['visit_purpose'] ?: 'Clinic service') ?> · <span class="badge <?= e(status_badge_class($item['status'])) ?>"><?= e($item['status']) ?></span> · Visit #<?= (int) $item['visit_id'] ?>
                        <p class="feedback-visit-status"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span> Feedback submitted — responses cannot be edited.</p>
                    </article>
                <?php else: ?>
                    <form method="post" class="feedback-visit">
                        <strong><?= e(date('F j, Y · g:i A', strtotime($item['visit_datetime']))) ?></strong><br>
                        <?= e($item['visit_purpose'] ?: 'Clinic service') ?> · <span class="badge <?= e(status_badge_class($item['status'])) ?>"><?= e($item['status']) ?></span> · Visit #<?= (int) $item['visit_id'] ?>
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>">
                        <input type="hidden" name="visit_token" value="<?= e($context['token']) ?>">
                        <input type="hidden" name="action" value="select">
                        <input type="hidden" name="visit_id" value="<?= (int) $item['visit_id'] ?>">
                        <div class="feedback-actions"><button class="btn btn-primary">Give feedback for this visit</button></div>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>"><input type="hidden" name="action" value="reset"><button class="btn btn-outline">Use a different student ID</button></form>
        </section>
    <?php elseif (!$visit): ?>
        <form method="post" class="clinic-card feedback-section">
            <h2>Find your clinic visits</h2>
            <p class="feedback-muted" id="feedback-id-help">Feedback is available once your visit is Active or Completed. Each visit accepts one response.</p>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>">
            <input type="hidden" name="action" value="lookup">
            <label class="feedback-field">Student ID<input class="clinic-input<?= $error ? ' input-error' : '' ?>" name="identifier" value="<?= e($identifier) ?>" placeholder="23-00262" data-id-number-format autocomplete="off" aria-describedby="feedback-id-help<?= $error ? ' feedback-error' : '' ?>" <?= $error ? 'aria-invalid="true"' : '' ?> required></label>
            <button class="btn btn-primary" type="submit">Find my visits</button>
        </form>
    <?php else: ?>
        <section class="clinic-card feedback-section">
            <h2>Your clinic visit</h2>
            <div class="feedback-visit"><strong><?= e(date('F j, Y · g:i A', strtotime($visit['visit_datetime']))) ?></strong><br><?= e($visit['visit_purpose'] ?: 'Clinic service') ?> · <span class="badge <?= e(status_badge_class($visit['status'])) ?>"><?= e($visit['status']) ?></span></div>
            <form method="post" class="feedback-actions"><input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>"><input type="hidden" name="visit_token" value="<?= e($context['token']) ?>"><input type="hidden" name="action" value="choose_again"><button class="btn btn-outline">Choose another visit</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>"><input type="hidden" name="action" value="reset"><button class="btn btn-outline">Use a different student ID</button></form>
        </section>
        <form method="post" id="feedback-survey">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>">
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="visit_token" value="<?= e($context['token']) ?>">
            <nav class="feedback-survey-progress clinic-card" aria-label="Feedback form progress">
                <span class="is-current" data-feedback-progress="1">1<span>Details</span></span>
                <span data-feedback-progress="2">2<span>Ratings</span></span>
                <span data-feedback-progress="3">3<span>Comments</span></span>
            </nav>
            <div class="feedback-survey-step is-active" data-feedback-step="1">
            <section class="clinic-card feedback-section">
                <h2>Privacy notice and consent</h2>
                <p>Participation is voluntary and does not affect your access to clinic services. Your ratings, student/visit information, and comments will be linked to this clinic visit and used for service evaluation and improvement.</p>
                <p>Responses are confidential, but not anonymous. Authorized clinic administrators and doctors can review feedback. Summary reports combine responses from students. Please avoid including medical details or other people's personal information in your comments.</p>
                <label class="feedback-consent"><input type="checkbox" name="consent" value="1" required <?= feedback_value($values, 'consent') === '1' ? 'checked' : '' ?>><span>I have read this notice and voluntarily consent to the clinic collecting, storing, and using my response for service quality improvement.</span></label>
            </section>
            <section class="clinic-card feedback-section">
                <h2>Section 1: Student &amp; Visit Information</h2><p class="feedback-muted" id="feedback-details-help">Confirm the information that matches this visit. All fields in this section are required.</p>
                <?php foreach ([
                    ['service_type', 'What service did you receive? (Main reason for your visit)', clinic_feedback_services(), 'service_other', 160],
                    ['academic_term', 'Current Academic Term', ['1st Semester', '2nd Semester', 'Mid-Year Term', 'Other'], 'term_other', 80],
                    ['year_level', 'Year Level', ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Other'], 'year_other', 80],
                ] as [$key, $label, $options, $other, $max]): ?>
                    <label class="feedback-field"><?= e($label) ?><select class="clinic-select" name="<?= e($key) ?>" required aria-describedby="feedback-details-help" data-other="<?= e($other) ?>"><option value="">Select an option</option><?php foreach ($options as $option): ?><option <?= feedback_value($values, $key) === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
                    <label class="feedback-field" id="<?= e($other) ?>">If Other, please specify<input class="clinic-input" name="<?= e($other) ?>" maxlength="<?= $max ?>" value="<?= e(feedback_value($values, $other)) ?>"></label>
                <?php endforeach; ?>
                <label class="feedback-field">College Department / Program<input class="clinic-input" name="program" value="<?= e(feedback_value($values, 'program')) ?>" maxlength="160" required></label>
            </section>
            <div class="feedback-actions feedback-wizard-actions"><button class="btn btn-primary" type="button" data-feedback-next>Continue to service evaluation <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button></div>
            </div>
            <div class="feedback-survey-step" data-feedback-step="2" hidden>
            <section class="clinic-card feedback-section"><h2 id="feedback-rating-title">Section 2: Service Evaluation (SERVPERF)</h2><p id="feedback-rating-help">Based on your actual experience during the visit shown above, rate each statement from <strong>1 (Strongly Disagree)</strong> to <strong>7 (Strongly Agree)</strong>. All 22 statements are required.</p></section>
            <?php foreach (clinic_feedback_sections() as $section => $questions): ?>
                <section class="clinic-card feedback-section"><h3><?= e($section) ?></h3>
                    <?php foreach ($questions as $code => $question): ?>
                        <fieldset class="feedback-question" aria-describedby="feedback-rating-help"><legend><?= e($code . '. ' . $question) ?> <span aria-label="required">*</span></legend>
                            <div class="feedback-scale"><?php for ($rating = 1; $rating <= 7; $rating++): ?>
                                <label class="feedback-rating"><span><?= $rating ?></span><input type="radio" name="ratings[<?= e($code) ?>]" value="<?= $rating ?>" aria-label="<?= $rating ?> of 7<?= $rating === 1 ? ' — Strongly Disagree' : ($rating === 7 ? ' — Strongly Agree' : '') ?>" required <?= is_array($values['ratings'] ?? null) && is_scalar($values['ratings'][$code] ?? null) && (string) $values['ratings'][$code] === (string) $rating ? 'checked' : '' ?>></label>
                            <?php endfor; ?></div><div class="feedback-scale-labels" aria-hidden="true"><span>Strongly Disagree</span><span>Strongly Agree</span></div>
                        </fieldset>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            <div class="feedback-actions feedback-wizard-actions"><button class="btn btn-outline" type="button" data-feedback-back><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Back</button><button class="btn btn-primary" type="button" data-feedback-next>Continue to comments <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button></div>
            </div>
            <div class="feedback-survey-step" data-feedback-step="3" hidden>
            <section class="clinic-card feedback-section"><h2>Section 3: Open Feedback</h2>
                <label class="feedback-field">Is there anything else you would like to share about your experience? <span class="feedback-muted">(Optional, up to 5,000 characters)</span><textarea class="clinic-textarea" name="comments" maxlength="5000" placeholder="Suggestions, compliments, or specific issues encountered"><?= e(feedback_value($values, 'comments')) ?></textarea></label>
                <p class="feedback-muted" id="feedback-submit-help">Please review your answers. Each visit accepts one response and submitted answers cannot be edited.</p>
                <div class="feedback-actions feedback-wizard-actions"><button class="btn btn-outline" type="button" data-feedback-back><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Back</button><button class="btn btn-primary" type="submit" aria-describedby="feedback-submit-help">Submit feedback <span class="material-symbols-outlined" aria-hidden="true">send</span></button></div>
            </section>
            </div>
        </form>
    <?php endif; ?>
</main>
<script>
document.querySelectorAll('select[data-other]').forEach(select => {
    const field = document.getElementById(select.dataset.other);
    const sync = () => { field.hidden = select.value !== 'Other'; field.querySelector('input').required = !field.hidden; };
    select.addEventListener('change', sync); sync();
});
const feedbackSurvey = document.getElementById('feedback-survey');
if (feedbackSurvey) {
    let currentStep = 1;
    const panels = Array.from(feedbackSurvey.querySelectorAll('[data-feedback-step]'));
    const progress = Array.from(feedbackSurvey.querySelectorAll('[data-feedback-progress]'));

    const showStep = step => {
        currentStep = step;
        panels.forEach(panel => {
            const active = Number(panel.dataset.feedbackStep) === step;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });
        progress.forEach(item => {
            const itemStep = Number(item.dataset.feedbackProgress);
            item.classList.toggle('is-current', itemStep === step);
            item.classList.toggle('is-complete', itemStep < step);
        });
        feedbackSurvey.querySelector('.feedback-survey-progress')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const validateCurrentStep = () => {
        const panel = feedbackSurvey.querySelector(`[data-feedback-step="${currentStep}"]`);
        const fields = Array.from(panel?.querySelectorAll('input, select, textarea') || []).filter(field => field.required && !field.disabled);
        const invalid = fields.find(field => !field.checkValidity());
        if (invalid) {
            invalid.reportValidity();
            return false;
        }
        return true;
    };

    feedbackSurvey.querySelectorAll('[data-feedback-next]').forEach(button => button.addEventListener('click', () => {
        if (validateCurrentStep()) showStep(Math.min(currentStep + 1, panels.length));
    }));
    feedbackSurvey.querySelectorAll('[data-feedback-back]').forEach(button => button.addEventListener('click', () => showStep(Math.max(currentStep - 1, 1))));
}
document.getElementById('feedback-survey')?.addEventListener('submit', event => {
    const button = event.target.querySelector('button[type="submit"]');
    button.disabled = true; button.setAttribute('aria-busy', 'true'); button.textContent = 'Submitting…';
});
window.addEventListener('pageshow', () => {
    const button = document.querySelector('#feedback-survey button[type="submit"]');
    if (button) { button.disabled = false; button.removeAttribute('aria-busy'); button.textContent = 'Submit feedback'; }
});
document.getElementById('feedback-error')?.focus();
const feedbackCountdown = document.getElementById('feedbackCountdown');
if (feedbackCountdown) {
    let secondsRemaining = 10;
    const homepageUrl = <?= json_encode(app_url('index.php')) ?>;
    const timer = window.setInterval(() => {
        secondsRemaining -= 1;
        feedbackCountdown.textContent = String(Math.max(secondsRemaining, 0));
        if (secondsRemaining <= 0) {
            window.clearInterval(timer);
            window.location.assign(homepageUrl);
        }
    }, 1000);
}
</script>
</body></html>
