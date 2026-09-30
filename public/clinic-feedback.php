<?php
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/services/ClinicFeedback.php';
require_once __DIR__ . '/../app/services/PatientNotification.php';
$feedbackSurveyRoute = !empty($feedbackSurveyRoute);

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
$generalRequested = ($_GET['general'] ?? '') === '1';
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
        $pendingVisits = $personId > 0 ? clinic_feedback_pending_completed_visits($db, $personId) : [];
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
            unset($_SESSION['feedback_context'], $_SESSION['feedback_success'], $_SESSION['feedback_success_general']);
            if (($_POST['async'] ?? '') === '1') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true]);
                exit;
            }
            header('Location: ' . app_url('clinic-feedback.php'));
            exit;
        }
        if ($action === 'start_general') {
            if ($feedbackSurveyRoute) throw new InvalidArgumentException('Start from the consent page before opening the survey.');
            if (($_POST['participate'] ?? '') !== '1' || ($_POST['consent'] ?? '') !== '1') {
                throw new InvalidArgumentException('Please confirm your participation and privacy consent before continuing.');
            }
            $_SESSION['feedback_context'] = [
                'mode' => 'general', 'identifier' => '', 'visit_id' => 0,
                'anonymous' => true, 'consented' => true,
                'expires' => time() + 3600, 'token' => bin2hex(random_bytes(32)),
            ];
            header('Location: ' . app_url('clinic-feedback-survey.php?general=1'));
            exit;
        }
        if ($action === 'start_linked') {
            if ($feedbackSurveyRoute || !$context || (int) ($context['visit_id'] ?? 0) < 1 || !hash_equals((string) ($context['token'] ?? ''), (string) ($_POST['visit_token'] ?? ''))) {
                throw new InvalidArgumentException('Choose a completed visit before confirming consent.');
            }
            if (($_POST['participate'] ?? '') !== '1' || ($_POST['consent'] ?? '') !== '1') {
                throw new InvalidArgumentException('Please confirm your participation and privacy consent before continuing.');
            }
            $selected = clinic_feedback_visit($db, (string) $context['identifier'], (int) $context['visit_id']);
            if (!$selected || !clinic_feedback_eligible((string) $selected['status']) || clinic_feedback_already_sent($db, (int) $selected['visit_id'])) {
                throw new InvalidArgumentException('The selected completed visit is no longer available for feedback.');
            }
            $_SESSION['feedback_context'] = array_replace($context, ['consented' => true, 'anonymous' => ($_POST['private_identity'] ?? '') === '1', 'token' => bin2hex(random_bytes(32))]);
            header('Location: ' . app_url('clinic-feedback-survey.php'));
            exit;
        }
        if ($action === 'lookup') {
            if ($feedbackSurveyRoute) throw new InvalidArgumentException('Start from the visit selection page.');
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
            if (!$found) throw new InvalidArgumentException('No Completed clinic visits were found for this student ID. Check your ID or ask the clinic staff. You may still give general feedback without a visit.');
            $_SESSION['feedback_context'] = [
                'identifier' => $identifier, 'visit_id' => 0,
                'expires' => time() + 3600, 'token' => bin2hex(random_bytes(32)),
            ];
            header('Location: ' . app_url('clinic-feedback.php'));
            exit;
        }
        if (in_array($action, ['select', 'choose_again'], true)) {
            if ($feedbackSurveyRoute) throw new InvalidArgumentException('Start from the visit selection page.');
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
            unset($context['consented'], $context['anonymous']);
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
            if (!$feedbackSurveyRoute || empty($context['consented'])) {
                throw new InvalidArgumentException('Please confirm your participation and privacy consent before submitting feedback.');
            }
            $generalSubmission = ($context['mode'] ?? '') === 'general';
            $values['consent'] = '1';
            $values['anonymous'] = ($generalSubmission || !empty($context['anonymous'])) ? '1' : '0';
            $submittedVisitId = (int) ($context['visit_id'] ?? 0);
            clinic_feedback_submit($db, $context, $values);
            $portalPersonId = (int) ($_SESSION['patient_person_id'] ?? $_SESSION['student_person_id'] ?? 0);
            if ($portalPersonId > 0 && $submittedVisitId > 0) {
                patient_notification_mark_source_read($db, $portalPersonId, 'clinic_feedback', $submittedVisitId);
            }
            unset($_SESSION['feedback_context']);
            $_SESSION['feedback_success'] = true;
            $_SESSION['feedback_success_general'] = $generalSubmission;
            $_SESSION['feedback_csrf'] = bin2hex(random_bytes(32));
            header('Location: ' . app_url('clinic-feedback.php' . ($generalSubmission ? '?general=1' : '')));
            exit;
        }
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Clinic feedback: ' . $exception->getMessage());
    $error = 'Feedback is temporarily unavailable. Please try again later.';
}
if ($available && $context && ($context['mode'] ?? '') !== 'general') {
    try {
        $found = $context['visit_id'] ? clinic_feedback_visit($db, $context['identifier'], (int) $context['visit_id']) : null;
        if ($found && clinic_feedback_eligible($found['status']) && !clinic_feedback_already_sent($db, (int) $found['visit_id'])) {
            $visit = $found;
        } elseif ($context['visit_id']) {
            $context['visit_id'] = 0;
            unset($context['consented'], $context['anonymous']);
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
function feedback_value(array $values, string $key): string {
    return is_string($values[$key] ?? null) ? $values[$key] : '';
}
$theme = active_cliniq_theme();
$generalActive = ($context['mode'] ?? '') === 'general' && !empty($context['consented']);
$generalFlow = $feedbackSurveyRoute ? $generalActive : ($generalRequested || $generalActive);
$successGeneral = !empty($_SESSION['feedback_success_general']);
unset($_SESSION['feedback_success_general']);
if ($feedbackSurveyRoute && (!$available || !$context || empty($context['consented']) || (!$generalActive && !$visit))) {
    header('Location: ' . app_url('clinic-feedback.php' . ($generalRequested ? '?general=1' : '')));
    exit;
}
if (!$feedbackSurveyRoute && !$success && $error === '' && !empty($context['consented']) && ($generalActive || $visit)) {
    header('Location: ' . app_url('clinic-feedback-survey.php' . ($generalActive ? '?general=1' : '')));
    exit;
}
$feedbackStep = $generalFlow ? ($success ? 3 : ($generalActive ? 2 : 1)) : ($success ? 5 : ($feedbackSurveyRoute ? 4 : ($visit ? 3 : ($context ? 2 : 1))));
$feedbackSteps = $generalFlow
    ? [1 => ['label' => 'Before you begin', 'description' => 'Confirm consent'], 2 => ['label' => 'Give feedback', 'description' => 'Share your experience']]
    : [1 => ['label' => 'Find visit', 'description' => 'Enter your student ID'], 2 => ['label' => 'Choose visit', 'description' => 'Select a completed visit'], 3 => ['label' => 'Before you begin', 'description' => 'Confirm consent'], 4 => ['label' => 'Give feedback', 'description' => 'Share your experience']];
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
    <link rel="stylesheet" href="<?= e(app_url('assets/css/feedback.css?v=' . filemtime(__DIR__ . '/assets/css/feedback.css'))) ?>">
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
        <span class="feedback-eyebrow">University Clinic · Clinic experience</span>
        <h1>Give Clinic Feedback</h1>
        <p>Help us improve your campus healthcare.</p>
        <p class="feedback-muted"><?= $generalFlow ? 'No visit or ID number is needed for general feedback.' : 'You can link feedback to a clinic visit using your student ID, or provide general feedback without one.' ?></p>
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
        <section class="clinic-card feedback-section feedback-success" role="status"><h2>Thank you for your valuable feedback!</h2><p><?= $successGeneral ? 'Your general feedback has been recorded without a visit or ID number.' : 'Your response has been recorded for your clinic visit.' ?> Your input helps improve our university's clinical care.</p><p class="feedback-muted">Returning to the homepage in <strong id="feedbackCountdown">10</strong> seconds.</p><a class="btn btn-primary" href="<?= e(app_url('index.php')) ?>">Return to homepage</a></section>
    <?php elseif (!$available): ?>
        <div class="feedback-notice" role="alert">Feedback is temporarily unavailable. Please try again later.</div>
    <?php elseif (!$feedbackSurveyRoute && $generalRequested && !$generalActive): ?>
        <section class="clinic-card feedback-section">
            <span class="feedback-eyebrow">Before you begin</span>
            <h2>Would you like to provide clinic feedback?</h2>
            <p class="feedback-muted">Your feedback helps improve clinic services. Participation is voluntary, and your response will be anonymous.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>">
                <input type="hidden" name="action" value="start_general">
                <input type="hidden" name="participate" value="1">
                <p class="feedback-muted">This general feedback is anonymous because no visit or ID number is linked to the response.</p>
                <label class="feedback-consent"><input type="checkbox" name="consent" value="1" required><span>I have read the RA 10173 privacy notice and consent to the collection and use of my feedback.</span></label>
                <div class="feedback-privacy">
                    <p><strong>Data privacy notice — RA 10173</strong></p>
                    <p>Your feedback is collected under the Data Privacy Act of 2012 (Republic Act No. 10173) to evaluate and improve clinic services.</p>
                    <details><summary>Read the privacy notice and your rights</summary><div><p>We collect your ratings, comments, and consent record for service quality improvement and clinic reporting. Your general response is anonymous and is not linked to a visit or ID number.</p><p>Access is limited to authorized clinic personnel who need it for evaluation, support, or reporting.</p></div></details>
                </div>
                <div class="feedback-actions"><button class="btn btn-primary" type="submit">Submit</button><a class="btn btn-outline" href="<?= e(app_url('index.php')) ?>">Not now</a></div>
            </form>
        </section>
    <?php elseif (!$visit && $context && !$generalActive): ?>
        <section class="clinic-card feedback-section">
            <h2>Choose a clinic visit</h2>
            <p class="feedback-muted">Completed visits are listed newest first. Each visit accepts one response.</p>
            <?php if (!$visits): ?><p>No Completed visits are currently available.</p><?php endif; ?>
            <?php if ($visits && !array_filter($visits, fn($item) => !$item['feedback_submitted'])): ?><p role="status">Feedback has been submitted for all listed visits. Thank you!</p><?php endif; ?>
            <?php foreach ($visits as $item): ?>
                <?php if ($item['feedback_submitted']): ?>
                    <article class="feedback-visit feedback-visit-submitted" aria-label="Feedback already submitted">
                        <strong><?= e(date('F j, Y · g:i A', strtotime($item['visit_datetime']))) ?></strong><br>
                        <strong>Reason for visit:</strong> <?= e($item['visit_purpose'] ?: 'Not recorded') ?><br>
                        <strong>Patient concern:</strong> <?= e($item['chief_complaint'] ?: 'Not recorded') ?><br>
                        <span class="badge <?= e(status_badge_class($item['status'])) ?>"><?= e($item['status']) ?></span>
                        <p class="feedback-visit-status"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span> Feedback submitted — responses cannot be edited.</p>
                    </article>
                <?php else: ?>
                    <form method="post" class="feedback-visit">
                        <strong><?= e(date('F j, Y · g:i A', strtotime($item['visit_datetime']))) ?></strong><br>
                        <strong>Reason for visit:</strong> <?= e($item['visit_purpose'] ?: 'Not recorded') ?><br>
                        <strong>Patient concern:</strong> <?= e($item['chief_complaint'] ?: 'Not recorded') ?><br>
                        <span class="badge <?= e(status_badge_class($item['status'])) ?>"><?= e($item['status']) ?></span>
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>">
                        <input type="hidden" name="visit_token" value="<?= e($context['token']) ?>">
                        <input type="hidden" name="action" value="select">
                        <input type="hidden" name="visit_id" value="<?= (int) $item['visit_id'] ?>">
                        <div class="feedback-actions"><button class="btn btn-primary">Give feedback for this visit</button></div>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="feedback-actions"><form method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>"><input type="hidden" name="action" value="reset"><button class="btn btn-outline">Use a different student ID</button></form><a class="btn btn-outline" href="<?= e(app_url('clinic-feedback.php?general=1')) ?>">Provide anonymous feedback</a></div>
        </section>
    <?php elseif (!$visit && !$generalActive): ?>
        <form method="post" class="clinic-card feedback-section">
            <h2>Find your clinic visits</h2>
            <p class="feedback-muted" id="feedback-id-help">Visit-linked feedback is available once your visit is Completed. Each visit accepts one response.</p>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>">
            <input type="hidden" name="action" value="lookup">
            <label class="feedback-field">Student ID<input class="clinic-input<?= $error ? ' input-error' : '' ?>" name="identifier" value="<?= e($identifier) ?>" placeholder="23-00262" data-id-number-format autocomplete="off" aria-describedby="feedback-id-help<?= $error ? ' feedback-error' : '' ?>" <?= $error ? 'aria-invalid="true"' : '' ?> required></label>
            <button class="btn btn-primary" type="submit">Find my visits</button>
        </form>
        <div class="feedback-actions"><a class="btn btn-outline" href="<?= e(app_url('clinic-feedback.php?general=1')) ?>">Provide anonymous feedback</a></div>
    <?php elseif (!$feedbackSurveyRoute): ?>
        <section class="clinic-card feedback-section">
            <span class="feedback-eyebrow">Selected visit</span>
            <h2><?= e(date('F j, Y · g:i A', strtotime($visit['visit_datetime']))) ?></h2>
            <div class="feedback-visit"><strong>Reason for visit:</strong> <?= e($visit['visit_purpose'] ?: 'Not recorded') ?><br><strong>Patient concern:</strong> <?= e($visit['chief_complaint'] ?: 'Not recorded') ?><br><span class="badge <?= e(status_badge_class($visit['status'])) ?>"><?= e($visit['status']) ?></span></div>
        </section>
        <section class="clinic-card feedback-section">
            <span class="feedback-eyebrow">Before you begin</span>
            <h2>Would you like to provide clinic feedback?</h2>
            <p class="feedback-muted">Your feedback helps improve clinic services. Participation is voluntary. Choose whether this response stays linked to this visit or is submitted anonymously.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>">
                <input type="hidden" name="visit_token" value="<?= e($context['token']) ?>">
                <input type="hidden" name="action" value="start_linked" data-feedback-start-action>
                <label class="feedback-anonymous-toggle">
                    <span><strong>Keep my identity private</strong><small>Your feedback still completes this visit’s requirement, but staff will not see which visit or student submitted it.</small></span>
                    <input type="checkbox" name="private_identity" value="1" data-feedback-anonymous-toggle aria-describedby="feedback-privacy-mode">
                </label>
                <p class="feedback-anonymous-status" id="feedback-privacy-mode" data-feedback-privacy-mode>Feedback is confidential and linked to the selected visit for clinic follow-up.</p>
                <input type="hidden" name="participate" value="1">
                <label class="feedback-consent"><input type="checkbox" name="consent" value="1" required><span>I have read the RA 10173 privacy notice and consent to the collection and use of my feedback.</span></label>
                <div class="feedback-privacy">
                    <p><strong>Data privacy notice — RA 10173</strong></p>
                    <p>Your feedback is collected under the Data Privacy Act of 2012 (Republic Act No. 10173) to evaluate and improve clinic services.</p>
                    <details><summary>Read the privacy notice and your rights</summary><div><p>We collect your visit details, ratings, comments, and consent record for service quality improvement and clinic reporting. Your response is confidential but linked to this visit for follow-up.</p><p>Access is limited to authorized clinic personnel who need it for evaluation, support, or reporting.</p></div></details>
                </div>
                <div class="feedback-actions feedback-start-actions"><button class="btn btn-primary" type="submit" data-feedback-start-submit>Continue to feedback <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button><a class="btn btn-outline" href="<?= e(app_url('index.php')) ?>">Not now</a></div>
            </form>
        </section>
    <?php else: ?>
        <?php if ($generalActive): ?>
        <section class="clinic-card feedback-section"><span class="feedback-eyebrow">General feedback</span><h2>Share your clinic experience</h2><p class="feedback-muted">This response will not be linked to a visit or ID number.</p></section>
        <?php else: ?>
        <section class="clinic-card feedback-section">
            <h2>Your clinic visit</h2>
            <div class="feedback-visit"><strong><?= e(date('F j, Y · g:i A', strtotime($visit['visit_datetime']))) ?></strong><br><strong>Reason for visit:</strong> <?= e($visit['visit_purpose'] ?: 'Not recorded') ?><br><strong>Patient concern:</strong> <?= e($visit['chief_complaint'] ?: 'Not recorded') ?><br><span class="badge <?= e(status_badge_class($visit['status'])) ?>"><?= e($visit['status']) ?></span></div>
        </section>
        <?php endif; ?>
        <form method="post" id="feedback-survey" data-no-discard-warning>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['feedback_csrf']) ?>">
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="visit_token" value="<?= e($context['token']) ?>">
            <?php if ($generalActive): ?><input type="hidden" name="consent" value="1"><?php endif; ?>
            <nav class="feedback-survey-progress feedback-survey-progress--two clinic-card" aria-label="Feedback form progress">
                <span class="is-current" data-feedback-progress="1">1<span>Ratings</span></span>
                <span data-feedback-progress="2">2<span>Comments</span></span>
            </nav>
            <div class="feedback-survey-step is-active" data-feedback-step="1">
            <section class="clinic-card feedback-section"><h2 id="feedback-rating-title">Rate your visit</h2><p id="feedback-rating-help">Choose one answer for each statement. Rate from <strong>1 (Strongly disagree)</strong> to <strong>7 (Strongly agree)</strong>.</p>
                <?php if ($generalActive): ?><label class="feedback-field">Service being reviewed<select class="clinic-select" name="service_type" required><option value="">Choose a service</option><?php foreach (clinic_feedback_services() as $serviceOption): ?><option value="<?= e($serviceOption) ?>" <?= feedback_value($values, 'service_type') === $serviceOption ? 'selected' : '' ?>><?= e($serviceOption) ?></option><?php endforeach; ?></select></label><label class="feedback-field" data-feedback-other-service hidden>Other service<input class="clinic-input" name="service_other" maxlength="160" value="<?= e(feedback_value($values, 'service_other')) ?>"></label><?php endif; ?>
            </section>
            <?php foreach (clinic_feedback_sections() as $section => $questions): ?>
                <details class="clinic-card feedback-section feedback-rating-group" <?= $section === 'Tangibles' ? 'open' : '' ?>><summary><?= e($section) ?><span class="material-symbols-outlined" aria-hidden="true">expand_more</span></summary>
                    <?php foreach ($questions as $code => $question): ?>
                        <fieldset class="feedback-question" aria-describedby="feedback-rating-help"><legend><?= e($code . '. ' . $question) ?> <span aria-label="required">*</span></legend>
                            <div class="feedback-scale"><?php for ($rating = 1; $rating <= 7; $rating++): ?>
                                <label class="feedback-rating"><span><?= $rating ?></span><input type="radio" name="ratings[<?= e($code) ?>]" value="<?= $rating ?>" aria-label="<?= $rating ?> of 7<?= $rating === 1 ? ' — Strongly Disagree' : ($rating === 7 ? ' — Strongly Agree' : '') ?>" required <?= is_array($values['ratings'] ?? null) && is_scalar($values['ratings'][$code] ?? null) && (string) $values['ratings'][$code] === (string) $rating ? 'checked' : '' ?>></label>
                            <?php endfor; ?></div><div class="feedback-scale-labels" aria-hidden="true"><span>Strongly Disagree</span><span>Strongly Agree</span></div>
                        </fieldset>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
            <div class="feedback-actions feedback-wizard-actions"><button class="btn btn-primary" type="button" data-feedback-next>Continue to comments <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button></div>
            </div>
            <div class="feedback-survey-step" data-feedback-step="2" hidden>
            <section class="clinic-card feedback-section"><h2>Anything else?</h2><p class="feedback-muted">Optional comments help us understand your experience.</p>
                <label class="feedback-field">Is there anything else you would like to share about your experience? <span class="feedback-muted">(Optional, up to 5,000 characters)</span><textarea class="clinic-textarea" name="comments" maxlength="5000" placeholder="Suggestions, compliments, or specific issues encountered"><?= e(feedback_value($values, 'comments')) ?></textarea></label>
                <p class="feedback-muted" id="feedback-submit-help">Please review your answers. Submitted answers cannot be edited.</p>
                <div class="feedback-actions feedback-wizard-actions"><button class="btn btn-outline" type="button" data-feedback-back><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Back</button><button class="btn btn-primary" type="submit" aria-describedby="feedback-submit-help">Submit feedback <span class="material-symbols-outlined" aria-hidden="true">send</span></button></div>
            </section>
            </div>
        </form>
    <?php endif; ?>
</main>
<?php if (!$feedbackSurveyRoute && $visit): ?>
<script>
(() => {
    const toggle = document.querySelector('[data-feedback-anonymous-toggle]');
    const action = document.querySelector('[data-feedback-start-action]');
    const status = document.querySelector('[data-feedback-privacy-mode]');
    const submit = document.querySelector('[data-feedback-start-submit]');
    if (!toggle || !action || !status || !submit) return;
    const syncFeedbackMode = () => {
        const anonymous = toggle.checked;
        action.value = 'start_linked';
        status.textContent = anonymous
            ? 'Your response remains linked internally to complete this visit’s feedback requirement. Staff feedback screens will not show the visit or your identity.'
            : 'Feedback is confidential and linked to the selected visit for clinic follow-up.';
        submit.innerHTML = anonymous
            ? 'Continue privately <span class="material-symbols-outlined" aria-hidden="true">visibility_off</span>'
            : 'Continue to feedback <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>';
    };
    toggle.addEventListener('change', syncFeedbackMode);
    syncFeedbackMode();
})();
</script>
<?php endif; ?>
<?php if ($feedbackSurveyRoute && ($generalActive || $visit)): ?>
<style>
    #feedback-leave-dialog { margin: auto; width: min(29rem, calc(100% - 2rem)); max-height: calc(100dvh - 2rem); overflow: auto; padding: 1.5rem; border: 1px solid var(--cliniq-outline); border-radius: 1rem; background: var(--cliniq-surface); color: var(--cliniq-foreground); box-shadow: 0 24px 70px rgba(var(--cliniq-shadow-rgb), .22); }
    #feedback-leave-dialog::backdrop { background: rgba(15, 23, 42, .55); backdrop-filter: blur(3px); }
    #feedback-leave-dialog h2 { margin: 0 0 .75rem; font-size: 1.25rem; font-weight: 700; }
    #feedback-leave-dialog p { margin: 0; line-height: 1.5; }
    #feedback-leave-error { margin-top: .75rem !important; color: #b91c1c; }
</style>
<dialog id="feedback-leave-dialog" aria-labelledby="feedback-leave-title" aria-describedby="feedback-leave-description">
    <h2 id="feedback-leave-title">Leave feedback?</h2>
    <p id="feedback-leave-description">Leaving will reset this feedback form and discard your answers and consent. Continue?</p>
    <p id="feedback-leave-error" role="alert" hidden>Unable to reset the form. Please try again.</p>
    <div class="feedback-actions"><button type="button" class="btn btn-outline" id="feedback-stay" autofocus>Stay</button><button type="button" class="btn btn-primary" id="feedback-leave">Leave and reset</button></div>
</dialog>
<?php endif; ?>
<script>
const feedbackSurvey = document.getElementById('feedback-survey');
if (feedbackSurvey) {
    let currentStep = 1;
    const panels = Array.from(feedbackSurvey.querySelectorAll('[data-feedback-step]'));
    const progress = Array.from(feedbackSurvey.querySelectorAll('[data-feedback-progress]'));
    const serviceSelect = feedbackSurvey.querySelector('select[name="service_type"]');
    const otherService = feedbackSurvey.querySelector('[data-feedback-other-service]');
    const updateOtherService = () => {
        if (!otherService || !serviceSelect) return;
        otherService.hidden = serviceSelect.value !== 'Other';
        otherService.querySelector('input').required = serviceSelect.value === 'Other';
    };
    serviceSelect?.addEventListener('change', updateOtherService);
    updateOtherService();

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
            const group = invalid.closest('.feedback-rating-group');
            if (group) group.open = true;
            invalid.reportValidity();
            return false;
        }
        return true;
    };

    feedbackSurvey.querySelectorAll('[data-feedback-next]').forEach(button => button.addEventListener('click', () => {
        if (validateCurrentStep()) showStep(Math.min(currentStep + 1, panels.length));
    }));
    feedbackSurvey.querySelectorAll('[data-feedback-back]').forEach(button => button.addEventListener('click', () => showStep(Math.max(currentStep - 1, 1))));
    const ratingGroups = Array.from(feedbackSurvey.querySelectorAll('.feedback-rating-group'));
    feedbackSurvey.addEventListener('change', event => {
        if (!event.target.matches('input[type="radio"]')) return;
        const group = event.target.closest('.feedback-rating-group');
        const index = ratingGroups.indexOf(group);
        if (index < 0) return;
        const questions = Array.from(group.querySelectorAll('.feedback-question'));
        if (questions.length && questions.every(question => question.querySelector('input[type="radio"]:checked'))) {
            if (ratingGroups[index + 1]) ratingGroups[index + 1].open = true;
        }
    });
}
let feedbackSubmitting = false;
feedbackSurvey?.addEventListener('submit', event => {
    feedbackSubmitting = true;
    const button = event.target.querySelector('button[type="submit"]');
    button.disabled = true; button.setAttribute('aria-busy', 'true'); button.textContent = 'Submitting…';
});
window.addEventListener('pageshow', () => {
    const button = document.querySelector('#feedback-survey button[type="submit"]');
    if (button) { button.disabled = false; button.removeAttribute('aria-busy'); button.textContent = 'Submit feedback'; }
});
const feedbackLeaveDialog = document.getElementById('feedback-leave-dialog');
const feedbackResetMarker = 'cliniqFeedbackResetPending';
if (feedbackSurvey && feedbackLeaveDialog) {
    const stayButton = document.getElementById('feedback-stay');
    const leaveButton = document.getElementById('feedback-leave');
    const leaveError = document.getElementById('feedback-leave-error');
    const resetUrl = <?= json_encode(app_url('clinic-feedback.php')) ?>;
    const resetFields = { _csrf: <?= json_encode(csrf_token()) ?>, csrf: <?= json_encode($_SESSION['feedback_csrf']) ?>, action: 'reset', async: '1' };
    let pendingUrl = '';
    let leaving = false;

    const resetFeedback = async () => {
        const response = await fetch(resetUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(resetFields)
        });
        if (!response.ok || !(await response.json()).ok) throw new Error('Feedback reset failed');
    };

    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank' || link.hasAttribute('download')) return;
        event.preventDefault();
        pendingUrl = link.href;
        leaveError.hidden = true;
        feedbackLeaveDialog.showModal();
    }, true);
    stayButton.addEventListener('click', () => { pendingUrl = ''; feedbackLeaveDialog.close(); });
    leaveButton.addEventListener('click', async () => {
        if (!pendingUrl) return;
        leaveButton.disabled = true;
        leaveError.hidden = true;
        try {
            await resetFeedback();
            leaving = true;
            sessionStorage.removeItem(feedbackResetMarker);
            window.location.assign(pendingUrl);
        } catch (error) {
            leaveError.hidden = false;
            leaveButton.disabled = false;
        }
    });
    window.addEventListener('beforeunload', event => {
        if (feedbackSubmitting || leaving) return;
        event.preventDefault();
        event.returnValue = '';
    });
    window.addEventListener('pagehide', () => {
        if (feedbackSubmitting || leaving) return;
        sessionStorage.setItem(feedbackResetMarker, '1');
        const body = new URLSearchParams(resetFields);
        if (!navigator.sendBeacon?.(resetUrl, body)) {
            fetch(resetUrl, { method: 'POST', credentials: 'same-origin', body, keepalive: true }).catch(() => {});
        }
    });
    let resettingPending = false;
    const completePendingReset = () => {
        if (resettingPending || sessionStorage.getItem(feedbackResetMarker) !== '1') return;
        resettingPending = true;
        feedbackSurvey.hidden = true;
        resetFeedback().then(() => {
            sessionStorage.removeItem(feedbackResetMarker);
            leaving = true;
            window.location.replace(window.location.href);
        }).catch(() => {
            resettingPending = false;
            feedbackSurvey.hidden = false;
            pendingUrl = window.location.href;
            leaveError.hidden = false;
            feedbackLeaveDialog.showModal();
        });
    };
    window.addEventListener('pageshow', event => {
        if (event.persisted) sessionStorage.setItem(feedbackResetMarker, '1');
        completePendingReset();
    });
    completePendingReset();
} else {
    sessionStorage.removeItem(feedbackResetMarker);
}
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
