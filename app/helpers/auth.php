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

function require_login(): void
{
    if (!current_user()) {
        header('Location: ' . app_url('index.php'));
        exit;
    }
}

function login_attempt(string $idNumber, string $password): bool
{
    $stmt = auth_db()->prepare('
        SELECT
            a.id AS account_id,
            a.password_hash,
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

    if (
        !$account
        || $account['account_status'] !== 'active'
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
