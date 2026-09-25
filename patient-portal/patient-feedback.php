<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';
require_once __DIR__ . '/../app/services/ClinicFeedback.php';
require_once __DIR__ . '/../app/services/PatientNotification.php';

$profile = student_require_login();
student_start_session();
$db = auth_db();
$personId = (int) ($profile['person_id'] ?? 0);
$csrf = $_SESSION['student_feedback_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$values = [];
$success = !empty($_SESSION['student_feedback_success']);
unset($_SESSION['student_feedback_success']);

try {
    $pendingVisits = clinic_feedback_pending_completed_visits($db, $personId);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['_csrf'] ?? '';
        if (!is_string($token) || !hash_equals($csrf, $token)) {
            throw new InvalidArgumentException('This form has expired. Reload the page and try again.');
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
        $_SESSION['student_feedback_csrf'] = bin2hex(random_bytes(32));
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

    <?php if ($error): ?>
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
                        <a class="student-feedback-visit-option <?= (int) $item['visit_id'] === $selectedId ? 'is-selected' : '' ?>" href="patient-feedback.php?visit_id=<?= (int) $item['visit_id'] ?>">
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
                <section class="student-card student-card-pad">
                    <div class="student-feedback-step-heading"><span>1</span><div><h2>Before you begin</h2><p>Review the visit details above and confirm your consent.</p></div></div>
                    <div class="student-feedback-privacy">
                        <p><strong>Data privacy notice — RA 10173</strong></p>
                        <p>Your feedback is collected under the Data Privacy Act of 2012 (Republic Act No. 10173) to evaluate and improve clinic services.</p>
                        <details>
                            <summary>Read the privacy notice and your rights</summary>
                            <div>
                                <p>We collect your ratings, comments, and consent record for service quality improvement and clinic reporting. You may submit anonymously; when you choose a visit, it is used only to improve visit-specific follow-up.</p>
                                <p>Access is limited to authorized clinic personnel who need it for evaluation, support, or reporting. We apply reasonable safeguards and retain the record only for as long as needed for these purposes and applicable requirements.</p>
                                <p>Under RA 10173, you may request access to or correction of your personal data and may raise questions or concerns about its processing through the clinic.</p>
                            </div>
                        </details>
                    </div>
                    <?php if ($isGeneralFeedback): ?>
                    <label class="student-field">Service being reviewed<select class="student-input" name="service_type" required><option value="">Choose a service</option><?php foreach (clinic_feedback_services() as $service): ?><option value="<?= student_e($service) ?>" <?= student_feedback_value($values, 'service_type') === $service ? 'selected' : '' ?>><?= student_e($service) ?></option><?php endforeach; ?></select></label>
                    <?php endif; ?>
                    <label class="student-feedback-consent"><input type="checkbox" name="anonymous" value="1" checked><span>Submit anonymously (do not identify me in the feedback record).</span></label>
                    <label class="student-feedback-consent"><input type="checkbox" name="consent" value="1" required <?= student_feedback_value($values, 'consent') === '1' ? 'checked' : '' ?>><span>I have read the RA 10173 notice and agree to the collection and use of my feedback for the purposes described above.</span></label>
                </section>
                <section class="student-card student-card-pad">
                    <div class="student-feedback-step-heading"><span>2</span><div><h2>Rate your visit</h2><p>Choose one answer for each statement.</p></div></div>
                    <?php foreach (clinic_feedback_sections() as $section => $questions): ?>
                        <details class="student-feedback-rating-group" <?= $section === 'Tangibles' ? 'open' : '' ?>><summary><?= student_e($section) ?><span class="material-symbols-outlined" aria-hidden="true">expand_more</span></summary>
                            <?php foreach ($questions as $code => $question): ?><fieldset class="student-feedback-question"><legend><?= student_e($question) ?> <span aria-label="required">*</span></legend><div class="student-feedback-scale"><?php for ($rating = 1; $rating <= 7; $rating++): ?><label><input type="radio" name="ratings[<?= student_e($code) ?>]" value="<?= $rating ?>" required <?= is_array($values['ratings'] ?? null) && (string) ($values['ratings'][$code] ?? '') === (string) $rating ? 'checked' : '' ?>><span><?= $rating ?></span></label><?php endfor; ?></div><div class="student-feedback-scale-labels"><span>Strongly disagree</span><span>Strongly agree</span></div></fieldset><?php endforeach; ?>
                        </details>
                    <?php endforeach; ?>
                </section>
                <section class="student-card student-card-pad">
                    <div class="student-feedback-step-heading"><span>3</span><div><h2>Anything else?</h2><p>Optional comments help us understand your experience.</p></div></div>
                    <label class="student-field">Comments<textarea class="student-input student-feedback-textarea" name="comments" maxlength="5000" rows="4" placeholder="Share a suggestion, compliment, or concern"><?= student_e(student_feedback_value($values, 'comments')) ?></textarea></label>
                    <button type="submit" class="student-button student-feedback-submit"><span class="material-symbols-outlined" aria-hidden="true">send</span>Submit feedback</button>
                </section>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php render_student_footer(); ?>
