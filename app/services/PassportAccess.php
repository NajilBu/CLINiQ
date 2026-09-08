<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/AuditLog.php';

function passport_viewer_from_person_id(int $personId): ?array
{
    if ($personId <= 0) {
        return null;
    }

    $stmt = auth_db()->prepare('
        SELECT a.id AS account_id, a.account_status, p.id AS person_id, p.id_number,
               p.first_name, p.middle_name, p.last_name, a.email
        FROM people p
        JOIN accounts a ON a.person_id = p.id
        JOIN students s ON s.person_id = p.id
        WHERE p.id = ? AND a.account_status = \'active\'
        LIMIT 1
    ');
    $stmt->execute([$personId]);
    $viewer = $stmt->fetch();
    if (!$viewer) {
        return null;
    }

    $viewer['name'] = trim(implode(' ', array_filter([
        $viewer['first_name'] ?? '',
        $viewer['middle_name'] ?? '',
        $viewer['last_name'] ?? '',
    ])));
    return $viewer;
}

function passport_current_viewer(): ?array
{
    $personId = (int) ($_SESSION['patient_person_id'] ?? $_SESSION['student_person_id'] ?? 0);
    if ($personId <= 0) {
        $personId = (int) ($_SESSION['passport_viewer_person_id'] ?? 0);
    }
    return passport_viewer_from_person_id($personId);
}

function passport_authenticate_viewer(string $studentNumber, string $password): ?array
{
    $stmt = auth_db()->prepare('
        SELECT a.id AS account_id, a.password_hash, a.account_status,
               p.id AS person_id, p.id_number, p.first_name, p.middle_name, p.last_name, a.email
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        JOIN students s ON s.person_id = p.id
        WHERE p.id_number = ?
        LIMIT 1
    ');
    $stmt->execute([trim($studentNumber)]);
    $account = $stmt->fetch();
    if (!$account || $account['account_status'] !== 'active' || !password_verify($password, (string) $account['password_hash'])) {
        return null;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_regenerate_id(true);
    $_SESSION['passport_viewer_person_id'] = (int) $account['person_id'];
    $_SESSION['passport_viewer_account_id'] = (int) $account['account_id'];

    $viewer = passport_viewer_from_person_id((int) $account['person_id']);
    if ($viewer) {
        audit_log_event('passport', 'viewer_authenticated', (int) $viewer['person_id'], 'student', 'person', (int) $viewer['person_id']);
    }
    return $viewer;
}

function passport_clear_viewer(): void
{
    unset($_SESSION['passport_viewer_person_id'], $_SESSION['passport_viewer_account_id']);
}
