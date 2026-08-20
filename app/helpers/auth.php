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
        if ($idNumber === '') {
            throw new RuntimeException('This clinic staff account is not linked to a staff profile.');
        }

        $_SESSION['user'] = [
            'id' => $personId,
            'account_id' => $accountId,
            'person_id' => $personId,
            'id_number' => $idNumber,
            'name' => trim(implode(' ', array_filter([
                $account['first_name'] ?? '',
                $account['middle_name'] ?? '',
                $account['last_name'] ?? '',
            ]))),
            'email' => $account['email'] ?? (strtolower(str_replace(' ', '', $account['last_name'] ?? '') . '_' . str_replace(' ', '', $account['first_name'] ?? '')) . '@plpasig.edu.ph'),
            'role' => (string) ($account['staff_role'] ?? 'staff'),
        ];
    } else {
        $_SESSION['patient_legacy_id'] = $personId;
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
            SELECT account_status, password_hash
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
            || empty($account['password_hash'])
        ) {
            throw new RuntimeException('This account can no longer complete first registration.');
        }

        $update = $authDb->prepare('
            UPDATE accounts
            SET
                password_hash = ?,
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

/**
 * Begin the re-enrollment/re-employment confirmation flow for a returning patient
 * whose account was reset at the start of a new school year.
 * Unlike first_registration, no password change is required.
 */
function begin_re_enrollment(array $account): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_regenerate_id(true);
    $_SESSION['patient_legacy_id'] = (int) ($account['person_id'] ?? 0);
    $_SESSION['patient_account_id'] = (int) ($account['account_id'] ?? 0);
    $_SESSION['patient_person_id'] = (int) ($account['person_id'] ?? 0);
    $_SESSION['re_enrollment'] = [
        'account_id' => (int) ($account['account_id'] ?? 0),
        'person_id'  => (int) ($account['person_id'] ?? 0),
        'type'       => (string) ($account['account_type'] ?? 'patient'),
    ];
}

function re_enrollment_pending(): bool
{
    return isset($_SESSION['re_enrollment']) && is_array($_SESSION['re_enrollment']);
}

function re_enrollment_context(): ?array
{
    $ctx = $_SESSION['re_enrollment'] ?? null;
    return is_array($ctx) ? $ctx : null;
}

/**
 * Confirm re-enrollment/re-employment: reactivates the account.
 */
function complete_re_enrollment(): void
{
    $ctx = re_enrollment_context();
    if ($ctx === null) {
        throw new RuntimeException('Re-enrollment session has expired. Please log in again.');
    }
    $db = auth_db();
    $stmt = $db->prepare("
        UPDATE accounts SET account_status = 'active', activated_at = NOW()
        WHERE id = ? AND person_id = ? AND account_status = 'inactive'
    ");
    $stmt->execute([(int) $ctx['account_id'], (int) $ctx['person_id']]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Your account could not be reactivated. Please contact the clinic.');
    }
    unset($_SESSION['re_enrollment']);
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
    require_once __DIR__ . '/../services/SystemSettings.php';
    ensure_staff_profiles_schema();

    $stmt = auth_db()->prepare('
        SELECT
            a.id AS account_id,
            a.password_hash,
            a.account_status,
            a.email,
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
            empty($account['password_hash'])
            || !password_verify($password, $account['password_hash'])
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

    $name = trim(implode(' ', array_filter([
        $account['first_name'],
        $account['middle_name'],
        $account['last_name'],
    ])));

    $_SESSION['user'] = [
        'id' => (int) $account['person_id'],
        'account_id' => (int) $account['account_id'],
        'person_id' => (int) $account['person_id'],
        'id_number' => $account['id_number'],
        'name' => $name,
        'email' => $account['email'] ?? (strtolower(str_replace(' ', '', $account['last_name'] ?? '') . '_' . str_replace(' ', '', $account['first_name'] ?? '')) . '@plpasig.edu.ph'),
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
