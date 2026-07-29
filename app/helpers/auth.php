<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function first_registration_context(): ?array
{
    $context = $_SESSION['first_registration'] ?? null;
    return is_array($context) ? $context : null;
}

function first_registration_pending(?string $portal = null): bool
{
    $context = first_registration_context();
    if ($context === null) {
        return false;
    }

    return $portal === null || ($context['portal'] ?? '') === $portal;
}

function begin_first_registration(array $account): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_regenerate_id(true);

    $accountType = (string) ($account['account_type'] ?? 'patient');
    $portal = $accountType === 'clinic_staff' ? 'staff' : 'patient';
    $accountId = (int) ($account['account_id'] ?? 0);
    $personId = (int) ($account['person_id'] ?? 0);

    if ($portal === 'staff') {
        $idNumber = (string) ($account['id_number'] ?? '');
        if (!preg_match('/^STAFF-(\d{4})$/', $idNumber, $matches)) {
            throw new RuntimeException('This clinic staff account is not linked to a staff profile.');
        }

        $legacyUserId = (int) $matches[1];
        $legacyStmt = db()->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
        $legacyStmt->execute([$legacyUserId]);
        $legacyUser = $legacyStmt->fetch();
        if (!$legacyUser) {
            throw new RuntimeException('This clinic staff account is not linked to a staff profile.');
        }

        $_SESSION['user'] = [
            'id' => $legacyUserId,
            'account_id' => $accountId,
            'person_id' => $personId,
            'id_number' => $idNumber,
            'name' => trim(implode(' ', array_filter([
                $account['first_name'] ?? '',
                $account['middle_name'] ?? '',
                $account['last_name'] ?? '',
            ]))),
            'email' => (string) $legacyUser['email'],
            'role' => (string) ($account['staff_role'] ?? 'staff'),
        ];
    } else {
        $legacyStmt = db()->prepare('SELECT id FROM patients WHERE id_number = ? LIMIT 1');
        $legacyStmt->execute([(string) ($account['id_number'] ?? '')]);
        $_SESSION['patient_legacy_id'] = (int) ($legacyStmt->fetchColumn() ?: 0);
        $_SESSION['patient_account_id'] = $accountId;
        $_SESSION['patient_person_id'] = $personId;
    }

    $_SESSION['first_registration'] = [
        'portal' => $portal,
        'account_id' => $accountId,
        'person_id' => $personId,
    ];

    return $portal;
}

function complete_first_registration(string $password, string $confirmPassword): void
{
    $context = first_registration_context();
    if ($context === null) {
        throw new RuntimeException('The first-registration session has expired. Start registration again.');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
    }
    if (!preg_match('/\d/', $password)) {
        throw new InvalidArgumentException('Password must contain at least one number.');
    }
    if ($password !== $confirmPassword) {
        throw new InvalidArgumentException('Passwords do not match.');
    }

    $authDb = auth_db();
    try {
        $authDb->beginTransaction();
        $stmt = $authDb->prepare('
            SELECT account_status, password_hash, temporary_password_hash
            FROM accounts
            WHERE id = ? AND person_id = ?
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([
            (int) ($context['account_id'] ?? 0),
            (int) ($context['person_id'] ?? 0),
        ]);
        $account = $stmt->fetch();

        if (
            !$account
            || $account['account_status'] !== 'inactive'
            || !empty($account['password_hash'])
            || empty($account['temporary_password_hash'])
        ) {
            throw new RuntimeException('This account can no longer complete first registration.');
        }

        $update = $authDb->prepare('
            UPDATE accounts
            SET
                password_hash = ?,
                temporary_password_hash = NULL,
                account_status = "active",
                activated_at = NOW()
            WHERE id = ?
        ');
        $update->execute([
            password_hash($password, PASSWORD_DEFAULT),
            (int) $context['account_id'],
        ]);
        $authDb->commit();
        unset($_SESSION['first_registration']);
    } catch (Throwable $e) {
        if ($authDb->inTransaction()) {
            $authDb->rollBack();
        }
        throw $e;
    }
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: ' . app_url('index.php'));
        exit;
    }

    if (first_registration_pending('staff')) {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($script, ['dashboard.php', 'logout.php'], true)) {
            header('Location: ' . app_url('dashboard.php'));
            exit;
        }
    }
}

function login_attempt(string $idNumber, string $password): bool
{
    $stmt = auth_db()->prepare('
        SELECT
            a.id AS account_id,
            a.password_hash,
            a.temporary_password_hash,
            a.account_status,
            p.id AS person_id,
            p.id_number,
            p.first_name,
            p.middle_name,
            p.last_name,
            cs.staff_role
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        JOIN clinic_staff cs ON cs.person_id = p.id
        WHERE p.id_number = ?
        LIMIT 1
    ');
    $stmt->execute([trim($idNumber)]);
    $account = $stmt->fetch();

    if (!$account || $account['account_status'] === 'suspended') {
        return false;
    }

    if ($account['account_status'] === 'inactive') {
        if (
            empty($account['temporary_password_hash'])
            || !password_verify($password, $account['temporary_password_hash'])
        ) {
            return false;
        }

        $account['account_type'] = 'clinic_staff';
        begin_first_registration($account);
        return true;
    }

    if (
        $account['account_status'] !== 'active'
        || empty($account['password_hash'])
        || !password_verify($password, $account['password_hash'])
    ) {
        return false;
    }

    if (!preg_match('/^STAFF-(\d{4})$/', (string) $account['id_number'], $matches)) {
        return false;
    }

    $legacyUserId = (int) $matches[1];
    $legacyStmt = db()->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
    $legacyStmt->execute([$legacyUserId]);
    $legacyUser = $legacyStmt->fetch();
    if (!$legacyUser) {
        return false;
    }

    $name = trim(implode(' ', array_filter([
        $account['first_name'],
        $account['middle_name'],
        $account['last_name'],
    ])));

    $_SESSION['user'] = [
        // Keep the legacy user ID because clinical tables still reference it.
        'id' => $legacyUserId,
        'account_id' => (int) $account['account_id'],
        'person_id' => (int) $account['person_id'],
        'id_number' => $account['id_number'],
        'name' => $name,
        'email' => $legacyUser['email'],
        'role' => $account['staff_role'],
    ];

    $update = auth_db()->prepare('UPDATE accounts SET last_login_at = NOW() WHERE id = ?');
    $update->execute([(int) $account['account_id']]);

    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}
