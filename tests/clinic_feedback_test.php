<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/services/ClinicFeedback.php';
function check_feedback(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects_feedback(callable $action, string $message): void {
    try { $action(); } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException($message);
}
$ratings = [];
foreach (clinic_feedback_sections() as $questions) foreach ($questions as $code => $question) $ratings[$code] = 7;
check_feedback(count($ratings) === 22, 'Expected all 22 source questions.');
check_feedback(clinic_feedback_scores($ratings)['Overall'] === 7, 'Perfect score.');
$ratings['T1'] = 4;
$scores = clinic_feedback_scores($ratings);
check_feedback($scores['Tangibles'] === 6.25 && abs($scores['Overall'] - 6.85) < 0.000001, 'Screenshot example must equal 6.85.');
$low = array_fill_keys(array_keys($ratings), 1);
check_feedback(clinic_feedback_scores($low)['Overall'] === 1, 'Minimum score.');
foreach ([0, 8, '1.5', '7abc', [], null, true] as $invalid) {
    $bad = $ratings; $bad['T1'] = $invalid;
    rejects_feedback(fn() => clinic_feedback_scores($bad), 'Invalid rating accepted.');
}
$missing = $ratings; unset($missing['E5']);
rejects_feedback(fn() => clinic_feedback_scores($missing), 'Missing answer accepted.');
rejects_feedback(fn() => clinic_feedback_scores($ratings + ['X1' => 7]), 'Unknown answer accepted.');
foreach (['Active' => true, 'Completed' => true, 'Unaddressed' => false, 'Cancelled' => false] as $status => $expected) {
    check_feedback(clinic_feedback_eligible($status) === $expected, 'Wrong visit eligibility.');
}
foreach (['3.999' => 'Critical', '4' => 'Satisfactory', '5.999' => 'Satisfactory', '6' => 'Excellent', '7' => 'Excellent'] as $score => $tier) {
    check_feedback(clinic_feedback_tier((float) $score) === $tier, 'Tier boundary incorrect.');
}
$input = ['consent' => '1', 'service_type' => clinic_feedback_services()[0], 'academic_term' => '1st Semester', 'year_level' => '1st Year', 'program' => 'BSIT', 'ratings' => $ratings];
$validated = clinic_feedback_validate($input);
check_feedback(!array_key_exists('email', $validated) && $validated['comments'] === '', 'No email or comment required.');
rejects_feedback(fn() => clinic_feedback_validate(array_replace($input, ['consent' => '0'])), 'Missing consent accepted.');
rejects_feedback(fn() => clinic_feedback_validate(array_replace($input, ['service_type' => 'Other'])), 'Other explanation required.');
rejects_feedback(fn() => clinic_feedback_validate(array_replace($input, ['program' => []])), 'Array accepted for text.');
rejects_feedback(fn() => clinic_feedback_validate(array_replace($input, ['comments' => str_repeat('a', 5001)])), 'Oversized comment accepted.');
echo "Clinic feedback validation and scoring tests passed.\n";
