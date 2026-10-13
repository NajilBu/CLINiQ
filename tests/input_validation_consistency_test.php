<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/data_normalization.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $callback) use ($assert): void {
    try {
        $callback();
        $assert(false, 'Expected validation to reject the value.');
    } catch (InvalidArgumentException) {
        $assert(true, 'Validation rejected the value.');
    }
};

$assert(cliniq_assert_bounded_text("  Clinic\n\nconcern  ", 'Concern', 30, true) === "Clinic\n\nconcern", 'Free text should be normalized.');
$throws(static fn() => cliniq_assert_bounded_text('', 'Concern', 30, true));
$throws(static fn() => cliniq_assert_bounded_text(str_repeat('a', 31), 'Concern', 30));

$root = dirname(__DIR__);
$sources = [
    'patient profile' => $root . '/app/services/CliniqPatientProfile.php',
    'staff profile' => $root . '/app/services/SystemSettings.php',
    'visits' => $root . '/app/services/CliniqVisitWorkflow.php',
    'alerts' => $root . '/public/alerts/create.php',
    'authentication' => $root . '/app/helpers/auth.php',
    'password reset' => $root . '/app/services/PatientPasswordResetService.php',
];
foreach ($sources as $label => $path) {
    $source = file_get_contents($path);
    $assert(is_string($source), $label . ' source must be readable.');
    $contents[$label] = $source;
}
$assert(str_contains($contents['patient profile'], 'account_valid_person_name($firstName)'), 'Patient edits must validate names.');
$assert(str_contains($contents['staff profile'], 'account_valid_person_name($name)'), 'Staff edits must validate names.');
$assert(str_contains($contents['visits'], 'Visitor / guest name may contain only letters'), 'Guest visits must validate names.');
$assert(str_contains($contents['alerts'], 'Reporter name may contain only letters'), 'Public alerts must validate reporter names.');
$assert(str_contains($contents['authentication'], 'account_assert_strong_password($password, $confirmPassword)'), 'Staff activation must use the shared password policy.');
$assert(str_contains($contents['authentication'], "account_assert_strong_password(\$newPassword, \$confirmPassword, 'New password')"), 'Patient password changes must use the shared policy.');
$assert(str_contains($contents['password reset'], 'account_assert_strong_password($password, $confirmation)'), 'Password resets must use the shared policy.');
$settingsPage = file_get_contents($root . '/public/settings/index.php');
$assert(is_string($settingsPage) && str_contains($settingsPage, "account_assert_strong_password(\$newPassword, \$confirmPassword, 'New password')"), 'Staff password changes must use the shared policy.');
$liveValidation = file_get_contents($root . '/public/assets/js/form-validation.js');
$assert(is_string($liveValidation) && str_contains($liveValidation, "field.matches('[data-id-number-format]')") && str_contains($liveValidation, 'window.CliniqIdNumber.kindFor(field)') && str_contains($liveValidation, 'validateRelated(field, true, false);'), 'Incomplete ID numbers must wait until blur or submit before showing a format error.');

echo "Input validation consistency tests passed ({$assertions} assertions).\n";
