<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/mail.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/ApeCycleService.php';
require_once __DIR__ . '/ApeWorkflow.php';
require_once __DIR__ . '/PatientAccountService.php';
require_once __DIR__ . '/SystemSettings.php';

const CLINIQ_PATIENT_REGISTRATION_CODE_MINUTES = 15;
const CLINIQ_PATIENT_REGISTRATION_MAX_ATTEMPTS = 5;
const CLINIQ_PATIENT_REGISTRATION_MAX_REQUESTS = 3;

function ensure_patient_registration_schema(): void
{
    auth_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS patient_registration_verifications (
    registration_verification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_number VARCHAR(50) NOT NULL,
    email VARCHAR(160) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    requested_ip VARCHAR(45) NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_patient_registration_identity_created (student_number, email, created_at),
    INDEX idx_patient_registration_expiry (expires_at, verified_at, consumed_at),
    INDEX idx_patient_registration_ip_created (requested_ip, created_at)
)
SQL);
}

function patient_registration_assert_identity_available(PDO $db, string $studentNumber, string $email): void
{
    $idCheck = $db->prepare('SELECT 1 FROM people WHERE id_number = ? LIMIT 1');
    $idCheck->execute([$studentNumber]);
    if ($idCheck->fetchColumn()) {
        throw new InvalidArgumentException('This student number already has an account. Sign in or use password recovery.');
    }
    $emailCheck = $db->prepare('SELECT 1 FROM accounts WHERE LOWER(email) = ? LIMIT 1');
    $emailCheck->execute([$email]);
    if ($emailCheck->fetchColumn()) {
        throw new InvalidArgumentException('This email address is already registered. Sign in or use password recovery.');
    }
}

function request_patient_registration_code(string $studentNumber, string $email, string $confirmation, ?string $ipAddress = null): array
{
    ensure_patient_registration_schema();
    $studentNumber = patient_account_normalize_id_number($studentNumber, 'student');
    $email = account_assert_institutional_email($email);
    $confirmation = account_normalize_email($confirmation);
    $ipAddress = substr(trim((string) $ipAddress), 0, 45) ?: null;
    if (!patient_account_id_number_is_valid($studentNumber, 'student')) {
        throw new InvalidArgumentException(patient_account_id_number_validation_message('student'));
    }
    if ($email !== $confirmation) {
        throw new InvalidArgumentException('Email address and confirmation do not match.');
    }

    $db = auth_db();
    patient_registration_assert_identity_available($db, $studentNumber, $email);
    $db->exec('DELETE FROM patient_registration_verifications WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $rate = $db->prepare('SELECT COUNT(*) FROM patient_registration_verifications WHERE (student_number = ? OR email = ?) AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $rate->execute([$studentNumber, $email]);
    if ((int) $rate->fetchColumn() >= CLINIQ_PATIENT_REGISTRATION_MAX_REQUESTS) {
        throw new RuntimeException('Too many verification requests. Wait 15 minutes before trying again.');
    }
    if ($ipAddress !== null) {
        $ipRate = $db->prepare('SELECT COUNT(*) FROM patient_registration_verifications WHERE requested_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $ipRate->execute([$ipAddress]);
        if ((int) $ipRate->fetchColumn() >= 10) {
            throw new RuntimeException('Too many verification requests from this connection. Try again later.');
        }
    }

    $code = (string) random_int(100000, 999999);
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE patient_registration_verifications SET consumed_at = NOW() WHERE (student_number = ? OR email = ?) AND consumed_at IS NULL')->execute([$studentNumber, $email]);
        $insert = $db->prepare('INSERT INTO patient_registration_verifications (student_number, email, code_hash, requested_ip, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))');
        $insert->execute([$studentNumber, $email, password_hash($code, PASSWORD_DEFAULT), $ipAddress]);
        $verificationId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }

    $clinicName = (string) (clinic_profile_settings()['system_name'] ?? 'CLINiQ Clinic');
    $message = cliniq_custom_email_body("Your student-account verification code is {$code}. It expires in 15 minutes. Do not share this code.", $clinicName);
    try {
        $sent = send_cliniq_email($email, 'Student Applicant', "[{$clinicName}] Verify your student account", $message);
    } catch (Throwable $exception) {
        error_log('[CLINiQ Registration] Verification delivery failed: ' . $exception->getMessage());
        $sent = false;
    }
    if (!$sent) {
        $db->prepare('DELETE FROM patient_registration_verifications WHERE registration_verification_id = ?')->execute([$verificationId]);
        throw new RuntimeException('The verification email could not be sent. Check the address or contact the clinic.');
    }
    audit_log_event('accounts', 'patient_registration_verification_requested', null, 'guest', 'registration', $verificationId, ['student_number' => $studentNumber]);
    return ['verification_id' => $verificationId, 'student_number' => $studentNumber, 'email' => $email];
}

function verify_patient_registration_code(int $verificationId, string $code): array
{
    ensure_patient_registration_schema();
    $code = trim($code);
    if ($verificationId < 1 || !preg_match('/^\d{6}$/', $code)) {
        throw new InvalidArgumentException('Enter the six-digit verification code from your email.');
    }
    $db = auth_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM patient_registration_verifications WHERE registration_verification_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$verificationId]);
        $verification = $stmt->fetch();
        if (!$verification || !empty($verification['consumed_at']) || strtotime((string) $verification['expires_at']) <= time()) {
            throw new RuntimeException('This verification code has expired. Request a new code.');
        }
        if ((int) $verification['attempt_count'] >= CLINIQ_PATIENT_REGISTRATION_MAX_ATTEMPTS) {
            throw new RuntimeException('Too many incorrect attempts. Request a new verification code.');
        }
        if (!password_verify($code, (string) $verification['code_hash'])) {
            $db->prepare('UPDATE patient_registration_verifications SET attempt_count = attempt_count + 1 WHERE registration_verification_id = ?')->execute([$verificationId]);
            $db->commit();
            throw new InvalidArgumentException('The verification code is incorrect.');
        }
        $db->prepare('UPDATE patient_registration_verifications SET verified_at = NOW() WHERE registration_verification_id = ?')->execute([$verificationId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
    audit_log_event('accounts', 'patient_registration_email_verified', null, 'guest', 'registration', $verificationId);
    return ['verification_id' => $verificationId, 'student_number' => (string) $verification['student_number'], 'email' => (string) $verification['email']];
}

function complete_patient_registration(int $verificationId, array $input): array
{
    ensure_patient_registration_schema();
    ensure_ape_cycle_schema();
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $middleName = trim((string) ($input['middle_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $birthdate = trim((string) ($input['birthdate'] ?? ''));
    $sex = normalize_person_sex(trim((string) ($input['sex'] ?? '')));
    $programCode = patient_account_active_program_code((string) ($input['program_code'] ?? ''));
    $programId = patient_account_active_program_id($programCode);
    $yearLevel = normalize_student_year_level((string) ($input['year_level'] ?? ''));
    $section = normalize_student_section_code((string) ($input['section'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');
    if (($input['legal_acknowledgement'] ?? '') !== '1') {
        throw new InvalidArgumentException('You must review and acknowledge the Terms of Use and Privacy Notice before creating an account.');
    }
    if (!patient_account_valid_name($firstName) || !patient_account_valid_name($lastName) || ($middleName !== '' && !patient_account_valid_name($middleName))) {
        throw new InvalidArgumentException('Enter valid first, middle, and last names.');
    }
    if (!patient_account_valid_birthdate($birthdate)) {
        throw new InvalidArgumentException('Enter a valid birthdate within the last 120 years and not in the future.');
    }
    account_assert_strong_password($password, $passwordConfirmation);

    $db = auth_db();
    $activeCycle = $db->query("SELECT ape_cycle_id, academic_year FROM ape_cycles WHERE status = 'Active' ORDER BY started_at DESC LIMIT 1")->fetch();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM patient_registration_verifications WHERE registration_verification_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$verificationId]);
        $verification = $stmt->fetch();
        if (!$verification || empty($verification['verified_at']) || !empty($verification['consumed_at']) || strtotime((string) $verification['expires_at']) <= time()) {
            throw new RuntimeException('Email verification expired. Start registration again.');
        }
        $studentNumber = (string) $verification['student_number'];
        $email = strtolower((string) $verification['email']);
        patient_registration_assert_identity_available($db, $studentNumber, $email);

        $person = $db->prepare('INSERT INTO people (id_number, first_name, middle_name, last_name, birthdate, sex) VALUES (?, ?, ?, ?, ?, ?)');
        $person->execute([$studentNumber, $firstName, $middleName !== '' ? $middleName : null, $lastName, $birthdate, $sex]);
        $personId = (int) $db->lastInsertId();
        $account = $db->prepare("UPDATE accounts SET password_hash = ?, email = ?, account_status = 'active', status_reason = NULL, activated_at = NOW() WHERE person_id = ?");
        $account->execute([password_hash($password, PASSWORD_DEFAULT), $email, $personId]);
        $accountLookup = $db->prepare('SELECT id FROM accounts WHERE person_id = ? LIMIT 1');
        $accountLookup->execute([$personId]);
        $accountId = (int) $accountLookup->fetchColumn();
        if ($accountId < 1) {
            throw new RuntimeException('The student login account could not be created.');
        }
        $db->prepare('INSERT INTO students (person_id, program_id, year_level, section, academic_year) VALUES (?, ?, ?, ?, ?)')->execute([$personId, $programId, $yearLevel, $section, $activeCycle['academic_year'] ?? null]);
        $db->prepare("INSERT INTO patients (person_id, emergency_token, token_enabled, access_status) VALUES (?, ?, 1, 'Applicant')")->execute([$personId, bin2hex(random_bytes(32))]);
        if ($activeCycle) {
            $ape = $db->prepare("INSERT INTO ape_records (patient_id, academic_year, ape_cycle_id, requirement_status, workflow_status, clearance_status, follow_up_required) VALUES (?, ?, ?, 'Not Checked', 'Registered', 'Pending', 0)");
            $ape->execute([$personId, (string) $activeCycle['academic_year'], (int) $activeCycle['ape_cycle_id']]);
            ape_seed_default_requirements((int) $db->lastInsertId(), 'Missing');
        }
        $db->prepare('UPDATE patient_registration_verifications SET consumed_at = NOW() WHERE registration_verification_id = ?')->execute([$verificationId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
    audit_log_event('accounts', 'patient_self_registered', $personId, 'student', 'account', $accountId, ['access_status' => 'Applicant']);
    audit_log_event('privacy', 'student_legal_acknowledged', $personId, 'student', 'account', $accountId, [
        'terms_version' => cliniq_legal_documents()['version'],
        'privacy_notice_version' => cliniq_legal_documents()['version'],
        'source' => 'patient-registration',
    ]);
    return ['person_id' => $personId, 'account_id' => $accountId, 'student_number' => $studentNumber, 'email' => $email, 'access_status' => 'Applicant'];
}
