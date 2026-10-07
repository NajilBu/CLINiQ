<?php

declare(strict_types=1);

if (getenv('REGISTRATION_E2E') !== '1') {
    throw new RuntimeException('Set REGISTRATION_E2E=1. This test is only for compose.registration-proof.yaml.');
}

require_once '/var/www/html/app/services/PatientRegistrationService.php';
require_once '/var/www/html/patient-portal/includes/patient-layout.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$db = auth_db();

$mailpitCode = static function (string $email): string {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $listing = @file_get_contents('http://mailpit:8025/api/v1/messages');
        $messages = is_string($listing) ? (json_decode($listing, true)['messages'] ?? []) : [];
        foreach ($messages as $message) {
            $id = $message['ID'] ?? $message['id'] ?? null;
            if ($id === null || !str_contains(strtolower(json_encode($message) ?: ''), strtolower($email))) continue;
            $detail = @file_get_contents('http://mailpit:8025/api/v1/message/' . rawurlencode((string) $id));
            if (is_string($detail) && preg_match('/\b([1-9][0-9]{5})\b/', $detail, $match) === 1) return $match[1];
        }
        usleep(100000);
    }
    throw new RuntimeException("Mailpit did not capture a verification code for {$email}.");
};
$verified = static function (string $studentNumber, string $email) use ($mailpitCode): array {
    $requested = request_patient_registration_code($studentNumber, $email, strtoupper($email), '127.0.0.1');
    return verify_patient_registration_code((int) $requested['verification_id'], $mailpitCode($email));
};
$input = static fn (array $overrides = []): array => array_replace([
    'first_name' => 'María',
    'middle_name' => 'Dela',
    'last_name' => 'Cruz',
    'birthdate' => '2005-05-15',
    'sex' => 'Female',
    'program_code' => 'BSIT',
    'year_level' => '2',
    'section' => 'A',
    'password' => 'Strong#123',
    'password_confirmation' => 'Strong#123',
    'legal_acknowledgement' => '1',
], $overrides);
$complete = static fn (array $context): array => complete_patient_registration((int) $context['verification_id'], $input());
$countIdentity = static function (string $studentNumber) use ($db): int {
    $statement = $db->prepare('SELECT COUNT(*) FROM people WHERE id_number = ?');
    $statement->execute([$studentNumber]);
    return (int) $statement->fetchColumn();
};

$service = file_get_contents('/var/www/html/app/services/PatientRegistrationService.php');
$settings = file_get_contents('/var/www/html/app/services/SystemSettings.php');
$settingsGuard = $settings === false ? '' : substr($settings, 0, (int) strpos($settings, 'function ensure_staff_profiles_schema'));
$assert($service !== false && !str_contains($service, 'ensure_patient_registration_schema') && !str_contains($service, 'ensure_ape_cycle_schema'), 'Registration must not invoke runtime schema setup.');
$assert(!str_contains($settingsGuard, '->exec('), 'System settings must not create schema during registration.');
$assert((int) $db->query("SELECT COUNT(*) FROM ape_cycles WHERE status = 'Active'")->fetchColumn() === 0, 'The proof database must have no active APE cycle.');

$successContext = $verified('26-90001', 'proof.success@plpasig.edu.ph');
student_begin_verified_onboarding($successContext);
$assert(student_verified_onboarding_context()['verification_id'] === $successContext['verification_id'], 'Verified onboarding context must survive session handoff.');
$success = $complete($successContext);
student_upgrade_verified_onboarding_session($success);
$assert((int) ($_SESSION['patient_account_id'] ?? 0) === (int) $success['account_id'], 'Successful registration must create an authenticated student session.');
$assert($countIdentity('26-90001') === 1, 'Successful registration must create exactly one person.');
$assert((int) $db->query("SELECT COUNT(*) FROM students s JOIN people p ON p.id = s.person_id WHERE p.id_number = '26-90001'")->fetchColumn() === 1, 'Successful registration must create one student.');
$assert((int) $db->query("SELECT COUNT(*) FROM patients p JOIN people pe ON pe.id = p.person_id WHERE pe.id_number = '26-90001'")->fetchColumn() === 1, 'Successful registration must create one patient.');
$assert((int) $db->query("SELECT COUNT(*) FROM ape_records ar JOIN people p ON p.id = ar.patient_id WHERE p.id_number = '26-90001'")->fetchColumn() === 0, 'Registration without an active cycle must not create an APE record.');
$throws(static fn () => request_patient_registration_code('26-90001', 'proof.success@plpasig.edu.ph', 'proof.success@plpasig.edu.ph'), 'Duplicate identity must be rejected.');

$invalidCode = request_patient_registration_code('26-90002', 'proof.code@plpasig.edu.ph', 'proof.code@plpasig.edu.ph', '127.0.0.1');
$throws(static fn () => verify_patient_registration_code((int) $invalidCode['verification_id'], '000000'), 'Invalid verification code must be rejected.');
$assert($countIdentity('26-90002') === 0, 'An invalid verification code must not create a person.');

$expired = request_patient_registration_code('26-90003', 'proof.expired@plpasig.edu.ph', 'proof.expired@plpasig.edu.ph', '127.0.0.1');
$db->prepare('UPDATE patient_registration_verifications SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE registration_verification_id = ?')->execute([(int) $expired['verification_id']]);
$throws(static fn () => verify_patient_registration_code((int) $expired['verification_id'], '123456'), 'Expired verification code must be rejected.');

$badName = $verified('26-90004', 'proof.name@plpasig.edu.ph');
$throws(static fn () => complete_patient_registration((int) $badName['verification_id'], $input(['first_name' => '<script>'])), 'Invalid names must be rejected.');
$assert($countIdentity('26-90004') === 0, 'Invalid names must not create a person.');

$badProgram = $verified('26-90005', 'proof.program@plpasig.edu.ph');
$throws(static fn () => complete_patient_registration((int) $badProgram['verification_id'], $input(['first_name' => 'Test', 'last_name' => 'Program', 'program_code' => 'NOPE'])), 'Inactive or unknown programs must be rejected.');
$assert($countIdentity('26-90005') === 0, 'Invalid programs must not create a person.');

$badPassword = $verified('26-90006', 'proof.password@plpasig.edu.ph');
$throws(static fn () => complete_patient_registration((int) $badPassword['verification_id'], $input(['first_name' => 'Test', 'last_name' => 'Password', 'password_confirmation' => 'Different#123'])), 'Mismatched passwords must be rejected.');
$assert($countIdentity('26-90006') === 0, 'Mismatched passwords must not create a person.');

$rollback = $verified('26-90007', 'proof.rollback@plpasig.edu.ph');
$db->exec("CREATE TRIGGER proof_registration_rollback BEFORE INSERT ON patients FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proof rollback'");
try {
    $throws(static fn () => $complete($rollback), 'A forced database failure must be surfaced.');
} finally {
    $db->exec('DROP TRIGGER IF EXISTS proof_registration_rollback');
}
$assert($countIdentity('26-90007') === 0, 'A database failure must roll back every partial registration record.');

echo "Patient registration isolated end-to-end test passed ({$assertions} assertions).\n";
