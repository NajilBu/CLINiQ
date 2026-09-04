<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../helpers/mail.php';

const CLINIQ_PATIENT_RESET_EXPIRY_MINUTES = 60;
const CLINIQ_PATIENT_RESET_MAX_REQUESTS = 3;

function ensure_patient_password_reset_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    auth_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS patient_password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    requested_ip VARCHAR(45) NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_patient_password_resets_account
        FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX idx_patient_password_resets_account_created (account_id, created_at),
    INDEX idx_patient_password_resets_expiry (expires_at, used_at)
)
SQL);
    $ready = true;
}

function patient_password_reset_csrf_token(string $purpose): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['patient_password_reset_csrf'] ??= [];
    if (empty($_SESSION['patient_password_reset_csrf'][$purpose])) {
        $_SESSION['patient_password_reset_csrf'][$purpose] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['patient_password_reset_csrf'][$purpose];
}

function patient_password_reset_verify_csrf(string $purpose, string $token): bool
{
    $expected = patient_password_reset_csrf_token($purpose);
    return $token !== '' && hash_equals($expected, $token);
}

function patient_password_reset_url(string $token): string
{
    $portalBase = rtrim((string) env_value('PATIENT_PORTAL_URL', ''), '/');
    if ($portalBase === '') {
        $appBase = rtrim((string) env_value('APP_URL', 'http://localhost/CLINiQ'), '/');
        $portalBase = $appBase . '/patient-portal';
    }
    return $portalBase . '/patient-reset-password.php?token=' . rawurlencode($token);
}

/**
 * Request a reset email without revealing whether the supplied identity exists.
 */
function request_patient_password_reset(string $idNumber, string $email, ?string $ipAddress = null): void
{
    ensure_patient_password_reset_schema();
    $idNumber = trim($idNumber);
    $email = strtolower(trim($email));
    if ($idNumber === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $db = auth_db();
    $stmt = $db->prepare(<<<'SQL'
SELECT
    a.id AS account_id,
    a.email,
    p.first_name,
    p.last_name
FROM accounts a
INNER JOIN people p ON p.id = a.person_id
WHERE p.id_number = ?
  AND LOWER(a.email) = ?
  AND a.account_status = 'active'
  AND (
      EXISTS (SELECT 1 FROM students s WHERE s.person_id = p.id)
      OR EXISTS (SELECT 1 FROM school_employees se WHERE se.person_id = p.id)
      OR EXISTS (SELECT 1 FROM patients pt WHERE pt.person_id = p.id)
  )
LIMIT 1
SQL);
    $stmt->execute([$idNumber, $email]);
    $account = $stmt->fetch();
    if (!$account) {
        return;
    }

    $rate = $db->prepare('SELECT COUNT(*) FROM patient_password_resets WHERE account_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $rate->execute([(int) $account['account_id']]);
    if ((int) $rate->fetchColumn() >= CLINIQ_PATIENT_RESET_MAX_REQUESTS) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $db->beginTransaction();
    try {
        $invalidate = $db->prepare('UPDATE patient_password_resets SET used_at = NOW() WHERE account_id = ? AND used_at IS NULL');
        $invalidate->execute([(int) $account['account_id']]);
        $insert = $db->prepare('INSERT INTO patient_password_resets (account_id, token_hash, requested_ip, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))');
        $insert->execute([
            (int) $account['account_id'],
            $tokenHash,
            substr(trim((string) $ipAddress), 0, 45) ?: null,
        ]);
        $resetId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $clinic = clinic_profile_settings();
    $message = cliniq_notification_email('patient_password_reset', [
        'patient_name' => trim((string) $account['first_name']) ?: 'Patient',
        'clinic_name' => (string) ($clinic['system_name'] ?? 'CLINiQ Clinic'),
        'expiry_minutes' => (string) CLINIQ_PATIENT_RESET_EXPIRY_MINUTES,
    ], patient_password_reset_url($token));

    try {
        $sent = send_cliniq_email(
            (string) $account['email'],
            trim((string) $account['first_name'] . ' ' . (string) $account['last_name']) ?: 'Patient',
            $message['subject'],
            $message['html']
        );
    } catch (Throwable $exception) {
        error_log('[CLINiQ Password Reset] Delivery failed: ' . $exception->getMessage());
        $sent = false;
    }
    if (!$sent) {
        $delete = $db->prepare('DELETE FROM patient_password_resets WHERE id = ?');
        $delete->execute([$resetId]);
    }
}

function patient_password_reset_token_is_valid(string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    ensure_patient_password_reset_schema();
    $stmt = auth_db()->prepare('SELECT 1 FROM patient_password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    return (bool) $stmt->fetchColumn();
}

function complete_patient_password_reset(string $token, string $password, string $confirmation): void
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new InvalidArgumentException('This password reset link is invalid or has expired.');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
    }
    if (!preg_match('/\d/', $password)) {
        throw new InvalidArgumentException('Password must contain at least one number.');
    }
    if ($password !== $confirmation) {
        throw new InvalidArgumentException('Passwords do not match.');
    }

    ensure_patient_password_reset_schema();
    $db = auth_db();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare(<<<'SQL'
SELECT r.id, r.account_id, a.password_hash
FROM patient_password_resets r
INNER JOIN accounts a ON a.id = r.account_id
WHERE r.token_hash = ?
  AND r.used_at IS NULL
  AND r.expires_at > NOW()
  AND a.account_status = 'active'
LIMIT 1
FOR UPDATE
SQL);
        $stmt->execute([hash('sha256', $token)]);
        $reset = $stmt->fetch();
        if (!$reset) {
            throw new InvalidArgumentException('This password reset link is invalid or has expired.');
        }
        if (!empty($reset['password_hash']) && password_verify($password, (string) $reset['password_hash'])) {
            throw new InvalidArgumentException('Choose a password different from your current password.');
        }

        $update = $db->prepare('UPDATE accounts SET password_hash = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([password_hash($password, PASSWORD_DEFAULT), (int) $reset['account_id']]);
        $consume = $db->prepare('UPDATE patient_password_resets SET used_at = NOW() WHERE account_id = ? AND used_at IS NULL');
        $consume->execute([(int) $reset['account_id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
