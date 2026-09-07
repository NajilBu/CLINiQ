<?php
// CLI-only tests include the actual controllers with connection-local temporary fixtures.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
require_once __DIR__ . '/clinic_feedback_database_test.php';
require_once __DIR__ . '/../app/helpers/view.php';
ob_clean();
$case = $argv[1] ?? 'render';
if (!in_array($case, ['render', 'submit', 'submit_old', 'picker', 'lookup', 'select', 'select_rated', 'select_foreign', 'csrf', 'stale', 'inactive', 'malformed', 'admin', 'forbidden', 'anonymous'], true)) throw new RuntimeException('Unknown test case.');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/cliniq/public/clinic-feedback.php';
$_SERVER['REQUEST_URI'] = '/cliniq/public/clinic-feedback.php';
$_GET = $_POST = [];
$_SESSION = [
    'feedback_csrf' => str_repeat('a', 64),
    'feedback_context' => ['identifier' => '99-99999', 'visit_id' => 6, 'expires' => time() + 3600, 'token' => str_repeat('b', 64)],
];
if ($case === 'submit_old') $_SESSION['feedback_context']['visit_id'] = 1;
if (in_array($case, ['submit', 'submit_old', 'csrf', 'stale', 'inactive', 'malformed'], true)) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $input + ['action' => 'submit', 'csrf' => str_repeat('a', 64), 'visit_token' => str_repeat('b', 64), 'visit_id' => 1];
    if ($case === 'csrf') $_POST['csrf'] = 'wrong';
    if ($case === 'stale') $_POST['visit_token'] = 'old-tab';
    if ($case === 'inactive') $db->exec("UPDATE visits SET status = 'Unaddressed' WHERE visit_id = 6");
    if ($case === 'malformed') $_POST['ratings']['T1'] = ['unexpected'];
}
if (in_array($case, ['picker', 'lookup', 'select', 'select_rated', 'select_foreign'], true)) {
    $_SESSION['feedback_context']['visit_id'] = 0;
    if ($case !== 'picker') {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf' => str_repeat('a', 64), 'visit_token' => str_repeat('b', 64), 'action' => $case === 'lookup' ? 'lookup' : 'select', 'identifier' => '99-99999', 'visit_id' => $case === 'select_rated' ? '2' : ($case === 'select_foreign' ? '4' : '1')];
    }
}
if (in_array($case, ['admin', 'forbidden'], true)) {
    $_SESSION['user'] = ['id' => 0, 'person_id' => 0, 'name' => 'Test Evaluator', 'role' => $case === 'admin' ? 'admin' : 'nurse'];
}
if ($case === 'admin') {
    $otherResponse = array_replace($input, ['service_type' => clinic_feedback_services()[1], 'ratings' => $low, 'comments' => '<script>test</script>']);
    clinic_feedback_submit($db, $_SESSION['feedback_context'], $otherResponse);
}
register_shutdown_function(function () use ($db, $case): void {
    $html = ob_get_clean();
    try {
        check_feedback(!preg_match('/Fatal error|Warning:|Notice:/', $html), 'PHP output error.');
        $count = (int) $db->query('SELECT COUNT(*) FROM clinic_feedback')->fetchColumn();
        check_feedback($count === (in_array($case, ['submit', 'submit_old', 'admin'], true) ? 3 : 2), 'Unexpected saved response count.');
        if ($case === 'submit_old') check_feedback(clinic_feedback_already_sent($db, 1), 'Older selected visit must accept feedback despite newer visits.');
        if ($case === 'picker') {
            check_feedback(str_contains($html, 'Choose a clinic visit') && substr_count($html, 'name="action" value="select"') === 4, 'Picker should list four owned eligible visits.');
            check_feedback(substr_count($html, 'disabled>Feedback submitted') === 2, 'Already-rated visits must be disabled.');
            check_feedback(!str_contains($html, 'id="feedback-survey"'), 'Do not select a visit automatically.');
        }
        if ($case === 'lookup') check_feedback($_SESSION['feedback_context']['visit_id'] === 0, 'Lookup should open picker, not auto-select.');
        if ($case === 'select') check_feedback($_SESSION['feedback_context']['visit_id'] === 1 && $_SESSION['feedback_context']['token'] !== str_repeat('b', 64), 'Select older visit and rotate token.');
        if (in_array($case, ['select_rated', 'select_foreign'], true)) check_feedback($_SESSION['feedback_context']['visit_id'] === 0 && !str_contains($html, 'id="feedback-survey"'), 'Invalid selection must not open survey.');
        if ($case === 'render') {
            check_feedback(substr_count($html, 'class="feedback-question"') === 22, 'Render all 22 questions.');
            check_feedback(substr_count($html, 'type="radio"') === 154, 'Render seven options per question.');
            check_feedback(!str_contains($html, 'name="email"') && !str_contains($html, 'type="password"'), 'Credentials must not be collected.');
            check_feedback(str_contains($html, 'name="consent"') && str_contains($html, 'name="program"'), 'Survey metadata missing.');
        }
        if ($case === 'submit') {
            check_feedback(clinic_feedback_already_sent($db, 6), 'Submitted to wrong visit.');
            check_feedback(!isset($_SESSION['feedback_context']) && !empty($_SESSION['feedback_success']), 'Clear session and redirect after successful submit.');
        }
        if ($case === 'csrf') check_feedback(str_contains($html, 'This form has expired.'), 'CSRF rejection missing.');
        if ($case === 'stale') check_feedback(!str_contains($html, 'id="feedback-survey"'), 'Stale token must require a fresh lookup.');
        if ($case === 'inactive') check_feedback(!str_contains($html, 'id="feedback-survey"'), 'Unaddressed visit must not display the survey.');
        if ($case === 'malformed') check_feedback(str_contains($html, 'Please answer all 22 statements'), 'Invalid nested rating must be rejected.');
        if ($case === 'admin') {
            check_feedback(str_contains($html, '4.90') && str_contains($html, 'Grand total'), 'Report must weight individual responses equally.');
            check_feedback(str_contains($html, '&lt;script&gt;test&lt;/script&gt;') && !str_contains($html, '<script>test</script>'), 'Escape written feedback.');
        }
        if ($case === 'forbidden') check_feedback(http_response_code() === 403, 'Non-admin staff must be denied.');
        if ($case === 'anonymous') check_feedback(!str_contains($html, 'Student responses'), 'Anonymous report access denied.');
        if (($GLOBALS['argv'][2] ?? '') === '--preview' && in_array($case, ['render', 'picker', 'admin'], true)) {
            $directory = __DIR__ . '/../.codex-work/feedback-design';
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            foreach (['green', 'blue'] as $themeName) {
                $previewTheme = cliniq_theme_presets()[$themeName];
                $preview = $html;
                foreach (['primary' => 'primary', 'primary-hover' => 'primary_container', 'primary-fixed' => 'primary_fixed', 'accent' => 'accent', 'accent-foreground' => 'primary_container', 'surface' => 'surface', 'surface-low' => 'surface_container_low', 'outline' => 'outline_variant', 'focus-rgb' => 'focus_rgb', 'shadow-rgb' => 'shadow_rgb'] as $variable => $key) {
                    $preview = preg_replace('/(--cliniq-' . preg_quote($variable, '/') . '\s*:)\s*[^;]+;/', '${1} ' . $previewTheme[$key] . ';', $preview);
                }
                // Static previews are inert: never submit fixture forms to an application route.
                $preview = str_replace('</body>', '<script>document.addEventListener("submit", e => e.preventDefault(), true);</script></body>', $preview);
                file_put_contents($directory . '/' . $case . '-' . $themeName . '.html', $preview);
            }
        }
        echo "Feedback page test passed: {$case}\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "Feedback page test failed ({$case}): " . $error->getMessage() . "\n");
        exit(1);
    }
});
require __DIR__ . (in_array($case, ['admin', 'forbidden', 'anonymous'], true) ? '/../public/feedback/index.php' : '/../public/clinic-feedback.php');
