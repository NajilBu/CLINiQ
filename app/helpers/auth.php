<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/AuditLog.php';
require_once __DIR__ . '/../services/ProfilePhoto.php';
require_once __DIR__ . '/../services/SystemSettings.php';
require_once __DIR__ . '/../helpers/mail.php';
require_once __DIR__ . '/data_normalization.php';
require_once __DIR__ . '/emergency_contact.php';

if (session_status() === PHP_SESSION_NONE) {
    $configuredAppUrl = (string) env_value('APP_URL', '');
    $forwardedProtocol = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $requestUsesHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || $forwardedProtocol === 'https'
        || str_starts_with(strtolower($configuredAppUrl), 'https://');
    $secureCookieSetting = strtolower((string) env_value('SESSION_SECURE_COOKIE', 'auto'));
    $secureCookie = $secureCookieSetting === 'true'
        || ($secureCookieSetting !== 'false' && $requestUsesHttps);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/security.php';
csrf_enforce_request();

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
            'profile_photo_path' => profile_photo_normalize_path($account['profile_photo_path'] ?? null),
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

function complete_first_registration(string $password, string $confirmPassword, bool $legalAcknowledgement = false): void
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
    if (!$legalAcknowledgement) {
        throw new InvalidArgumentException('You must review and acknowledge the Terms of Use and Privacy Notice before activating your account.');
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
                status_reason = NULL,
                activated_at = NOW()
            WHERE id = ?
        ');
        $update->execute([
            password_hash($password, PASSWORD_DEFAULT),
            (int) $context['account_id'],
        ]);
        $authDb->commit();
        unset($_SESSION['first_registration']);
        audit_log_event('privacy', 'student_legal_acknowledged', (int) $context['person_id'], 'student', 'account', (int) $context['account_id'], [
            'terms_version' => cliniq_legal_documents()['version'],
            'privacy_notice_version' => cliniq_legal_documents()['version'],
            'source' => 'first-registration',
        ]);
    } catch (Throwable $e) {
        if ($authDb->inTransaction()) {
            $authDb->rollBack();
        }
        throw $e;
    }
}

/**
 * Begin the re-enrollment confirmation flow for a returning student whose
 * account was reset at the start of a new school year.
 * Unlike first_registration, no password change is required.
 */
function begin_re_enrollment(array $account): void
{
    if ((string) ($account['account_type'] ?? '') !== 'student') {
        throw new RuntimeException('Only student accounts can complete school-year enrollment confirmation.');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_regenerate_id(true);
    $academicYear = student_current_academic_year();
    $studentDetails = [];
    try {
        $yearQuery = auth_db()->prepare('SELECT s.academic_year, s.year_level, s.section, p.first_name, p.last_name, pt.guardian_or_contact_number, pt.secondary_contact_number FROM students s INNER JOIN people p ON p.id = s.person_id LEFT JOIN patients pt ON pt.person_id = s.person_id WHERE s.person_id = ? LIMIT 1');
        $yearQuery->execute([(int) ($account['person_id'] ?? 0)]);
        $studentDetails = $yearQuery->fetch() ?: [];
        $storedAcademicYear = trim((string) ($studentDetails['academic_year'] ?? ''));
        if ($storedAcademicYear !== '') {
            $academicYear = $storedAcademicYear;
        }
    } catch (Throwable $e) {
        // Fall back to the calendar-derived school year on legacy schemas.
    }
    $_SESSION['patient_legacy_id'] = (int) ($account['person_id'] ?? 0);
    $_SESSION['patient_account_id'] = (int) ($account['account_id'] ?? 0);
    $_SESSION['patient_person_id'] = (int) ($account['person_id'] ?? 0);
    $_SESSION['re_enrollment'] = [
        'account_id' => (int) ($account['account_id'] ?? 0),
        'person_id'  => (int) ($account['person_id'] ?? 0),
        'type'       => (string) ($account['account_type'] ?? 'patient'),
        'academic_year' => $academicYear,
        'year_level' => (string) ($studentDetails['year_level'] ?? ''),
        'section' => strtoupper(trim((string) ($studentDetails['section'] ?? ''))),
        'first_name' => (string) ($studentDetails['first_name'] ?? ''),
        'last_name' => (string) ($studentDetails['last_name'] ?? ''),
        'contact_number' => (string) ($studentDetails['guardian_or_contact_number'] ?? ''),
        'secondary_contact_number' => (string) ($studentDetails['secondary_contact_number'] ?? ''),
    ];
}

function student_current_academic_year(?DateTimeImmutable $today = null): string
{
    $today ??= new DateTimeImmutable('today');
    $startYear = (int) $today->format('Y');
    if ((int) $today->format('n') < 6) {
        $startYear--;
    }
    return $startYear . '-' . ($startYear + 1);
}

function student_non_enrollment_reasons(): array
{
    return ['Leave of Absence', 'Graduated', 'Transferred', 'Withdrawn', 'Other'];
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
 * Confirm student re-enrollment and reactivate the student account.
 */
function complete_re_enrollment(string $enrollmentStatus, string $nonEnrollmentReason = '', string $contactNumber = '', string $contactConfirmed = '', string $yearLevel = '', string $section = ''): void
{
    $ctx = re_enrollment_context();
    if ($ctx === null) {
        throw new RuntimeException('Re-enrollment session has expired. Please log in again.');
    }
    if ((string) ($ctx['type'] ?? '') !== 'student') {
        unset($_SESSION['re_enrollment']);
        throw new RuntimeException('Only student accounts can complete school-year enrollment confirmation.');
    }
    $enrollmentStatus = trim($enrollmentStatus);
    $nonEnrollmentReason = trim($nonEnrollmentReason);
    if (!in_array($enrollmentStatus, ['Still Enrolled', 'Not Currently Enrolled'], true)) {
        throw new InvalidArgumentException('Select your current enrollment status.');
    }
    if ($enrollmentStatus === 'Not Currently Enrolled'
        && !in_array($nonEnrollmentReason, student_non_enrollment_reasons(), true)) {
        throw new InvalidArgumentException('Select why you are not currently enrolled.');
    }
    if ($enrollmentStatus === 'Still Enrolled') {
        $nonEnrollmentReason = '';
    }
    $contactNumber = cliniq_normalize_phone($contactNumber);
    if ($contactNumber === null) {
        throw new InvalidArgumentException('Enter a valid contact number so the clinic can reach you.');
    }
    if ($contactConfirmed !== '1') {
        throw new InvalidArgumentException('Confirm that your section and contact information are correct.');
    }
    $yearLevel = trim($yearLevel);
    $section = strtoupper(trim($section));
    if (!in_array($yearLevel, ['1', '2', '3', '4'], true)) {
        throw new InvalidArgumentException('Select a valid year level.');
    }
    if ($section === '' || !preg_match('/^[A-Z0-9][A-Z0-9 .-]{0,79}$/', $section)) {
        throw new InvalidArgumentException('Enter a valid section.');
    }

    $db = auth_db();
    $db->beginTransaction();
    try {
        $accountId = (int) $ctx['account_id'];
        $personId = (int) $ctx['person_id'];
        $academicYear = trim((string) ($ctx['academic_year'] ?? '')) ?: student_current_academic_year();
        $db->prepare('UPDATE patients SET guardian_or_contact_number = ?, updated_at = NOW() WHERE person_id = ?')->execute([$contactNumber, $personId]);
        $db->prepare('UPDATE students SET year_level = ?, section = ?, academic_year = ? WHERE person_id = ?')->execute([$yearLevel, $section, $academicYear, $personId]);

        $declaration = $db->prepare("
            INSERT INTO student_enrollment_declarations
                (account_id, academic_year, enrollment_status, non_enrollment_reason)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                enrollment_status = VALUES(enrollment_status),
                non_enrollment_reason = VALUES(non_enrollment_reason),
                submitted_at = CURRENT_TIMESTAMP
        ");
        $declaration->execute([
            $accountId,
            $academicYear,
            $enrollmentStatus,
            $nonEnrollmentReason !== '' ? $nonEnrollmentReason : null,
        ]);

        $history = $db->prepare("
            UPDATE student_school_year_enrollments
            SET enrollment_status = ?, non_enrollment_reason = ?
            WHERE student_person_id = ? AND academic_year = ?
        ");
        $history->execute([
            $enrollmentStatus === 'Still Enrolled' ? 'Enrolled' : 'Not Enrolled',
            $nonEnrollmentReason !== '' ? $nonEnrollmentReason : null,
            $personId,
            $academicYear,
        ]);

        $stmt = $db->prepare("
            UPDATE accounts a
            INNER JOIN students s ON s.person_id = a.person_id
            SET a.account_status = 'active',
                a.status_reason = NULL,
                a.activated_at = COALESCE(a.activated_at, NOW())
            WHERE a.id = ? AND a.person_id = ? AND a.account_status = 'inactive'
        ");
        $stmt->execute([$accountId, $personId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Your account could not be reactivated. Please contact the clinic.');
        }

        if ($enrollmentStatus === 'Still Enrolled') {
            $activeCycle = $db->prepare("SELECT ape_cycle_id FROM ape_cycles WHERE academic_year = ? AND status = 'Active' LIMIT 1");
            $activeCycle->execute([$academicYear]);
            $activeCycleId = (int) ($activeCycle->fetchColumn() ?: 0);
            if ($activeCycleId > 0) {
                $db->prepare('INSERT IGNORE INTO ape_records (ape_cycle_id, patient_id, academic_year) VALUES (?, ?, ?)')
                    ->execute([$activeCycleId, $personId, $academicYear]);
                $apeIdQuery = $db->prepare('SELECT ape_id FROM ape_records WHERE patient_id = ? AND academic_year = ? LIMIT 1');
                $apeIdQuery->execute([$personId, $academicYear]);
                $apeId = (int) ($apeIdQuery->fetchColumn() ?: 0);
                if ($apeId > 0) {
                    require_once __DIR__ . '/../services/ApeWorkflow.php';
                    ape_seed_default_requirements($apeId);
                }
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    audit_log_event('auth', 'student_enrollment_declared', (int) $ctx['person_id'], 'student', 'account', (int) $ctx['account_id'], [
        'academic_year' => (string) ($ctx['academic_year'] ?? student_current_academic_year()),
        'enrollment_status' => $enrollmentStatus,
        'non_enrollment_reason' => $nonEnrollmentReason !== '' ? $nonEnrollmentReason : null,
        'year_level' => $yearLevel,
        'section' => $section,
    ]);
    // Send a best-effort confirmation after the database transaction succeeds.
    try {
        $emailStmt = auth_db()->prepare('SELECT a.email, p.first_name FROM accounts a INNER JOIN people p ON p.id = a.person_id WHERE a.id = ? LIMIT 1');
        $emailStmt->execute([(int) $ctx['account_id']]);
        $emailRow = $emailStmt->fetch();
        if (!empty($emailRow['email'])) {
            $clinic = clinic_profile_settings();
            $body = cliniq_custom_email_body('Your enrollment information for ' . $academicYear . ' was confirmed. Updated year and section: Year ' . $yearLevel . ' - ' . $section . '. Your account is now active.', (string) ($clinic['system_name'] ?? 'CLINiQ Clinic'));
            send_cliniq_email((string) $emailRow['email'], (string) ($emailRow['first_name'] ?? 'Student'), '[' . ($clinic['system_name'] ?? 'CLINiQ') . '] Enrollment information confirmed', $body);
        }
    } catch (Throwable $mailException) {
        error_log('[CLINiQ Re-enrollment] Confirmation email failed: ' . $mailException->getMessage());
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

    $authDb = auth_db();
    $idNumber = trim($idNumber);
    auth_throttle_assert_allowed($authDb, 'staff', $idNumber);

    $stmt = $authDb->prepare('
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
            p.profile_photo_path,
            cs.staff_role
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        JOIN clinic_staff cs ON cs.person_id = p.id
        WHERE p.id_number = ?
        LIMIT 1
    ');
    $stmt->execute([$idNumber]);
    $account = $stmt->fetch();

    if (!$account || $account['account_status'] === 'suspended') {
        auth_throttle_record_failure($authDb, 'staff', $idNumber);
        audit_log_event('auth', 'staff_login_failed', null, 'guest', 'account', null, ['id_number' => trim($idNumber)], 'failure');
        return false;
    }

    if ($account['account_status'] === 'inactive') {
        if (
            empty($account['password_hash'])
            || !password_verify($password, $account['password_hash'])
        ) {
            auth_throttle_record_failure($authDb, 'staff', $idNumber);
            audit_log_event('auth', 'staff_login_failed', null, 'guest', 'account', null, ['id_number' => trim($idNumber)], 'failure');
            return false;
        }

        $account['account_type'] = 'clinic_staff';
        auth_throttle_clear($authDb, 'staff', $idNumber);
        begin_first_registration($account);
        return true;
    }

    if (
        $account['account_status'] !== 'active'
        || empty($account['password_hash'])
        || !password_verify($password, $account['password_hash'])
    ) {
        auth_throttle_record_failure($authDb, 'staff', $idNumber);
        audit_log_event('auth', 'staff_login_failed', null, 'guest', 'account', null, ['id_number' => trim($idNumber)], 'failure');
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
        'profile_photo_path' => profile_photo_normalize_path($account['profile_photo_path'] ?? null),
    ];

    auth_throttle_clear($authDb, 'staff', $idNumber);
    csrf_rotate_token();

    $update = $authDb->prepare('UPDATE accounts SET last_login_at = NOW() WHERE id = ?');
    $update->execute([(int) $account['account_id']]);
    audit_log_event('auth', 'staff_login_success', (int) $account['person_id'], 'staff', 'person', (int) $account['person_id']);

    return true;
}

function logout_user(): void
{
    $user = current_user();
    audit_log_event('auth', 'staff_logout', (int) ($user['person_id'] ?? 0) ?: null, 'staff', 'person', (int) ($user['person_id'] ?? 0) ?: null);
    $_SESSION = [];
    session_destroy();
}

/**
 * Update password for a logged-in patient account after validating their current password.
 */
function change_patient_password(int $accountId, string $currentPassword, string $newPassword, string $confirmPassword): void
{
    if ($accountId <= 0) {
        throw new InvalidArgumentException('Invalid patient account session.');
    }
    if ($currentPassword === '') {
        throw new InvalidArgumentException('Please enter your current password.');
    }
    if (strlen($newPassword) < 8) {
        throw new InvalidArgumentException('New password must be at least 8 characters long.');
    }
    if ($newPassword !== $confirmPassword) {
        throw new InvalidArgumentException('New password and confirmation password do not match.');
    }

    $db = auth_db();
    $stmt = $db->prepare('SELECT password_hash FROM accounts WHERE id = ? LIMIT 1');
    $stmt->execute([$accountId]);
    $currentHash = (string) $stmt->fetchColumn();

    if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
        throw new InvalidArgumentException('Incorrect current password. Please try again.');
    }

    if (password_verify($newPassword, $currentHash)) {
        throw new InvalidArgumentException('New password must be different from your current password.');
    }

    $update = $db->prepare('UPDATE accounts SET password_hash = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([
        password_hash($newPassword, PASSWORD_DEFAULT),
        $accountId,
    ]);
}
