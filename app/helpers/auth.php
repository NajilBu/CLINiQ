<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/AuditLog.php';
require_once __DIR__ . '/../services/AccountValidation.php';
require_once __DIR__ . '/../services/ProfilePhoto.php';
require_once __DIR__ . '/../services/SystemSettings.php';
require_once __DIR__ . '/../helpers/mail.php';
require_once __DIR__ . '/../services/PatientEmail.php';
require_once __DIR__ . '/data_normalization.php';
require_once __DIR__ . '/emergency_contact.php';

if (session_status() === PHP_SESSION_NONE) {
    $forwardedProtocol = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $requestUsesHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || $forwardedProtocol === 'https';
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
            'name' => staff_display_name(trim(implode(' ', array_filter([
                $account['first_name'] ?? '',
                $account['middle_name'] ?? '',
                $account['last_name'] ?? '',
            ]))), $account['honorific'] ?? null),
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
    account_assert_strong_password($password, $confirmPassword);
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
    return dropdown_options('non_enrollment_reason');
}

function student_enrollment_is_confirmed(int $personId): bool
{
    $stmt = auth_db()->prepare('SELECT e.enrollment_status FROM students s LEFT JOIN student_school_year_enrollments e ON e.student_person_id = s.person_id AND e.academic_year COLLATE utf8mb4_unicode_ci = s.academic_year COLLATE utf8mb4_unicode_ci WHERE s.person_id = ? LIMIT 1');
    $stmt->execute([$personId]);
    $status = $stmt->fetchColumn();
    return $status === false || $status === null || $status === 'Enrolled';
}

/**
 * Confirm the current school-year enrollment without letting the student rewrite
 * clinic-assigned placement data.
 */
function complete_student_enrollment_confirmation(int $accountId, int $personId, string $enrollmentStatus, string $nonEnrollmentReason = '', string $contactNumber = '', string $contactConfirmed = ''): void
{
    if ($accountId <= 0 || $personId <= 0) {
        throw new RuntimeException('Your signed-in student account is unavailable. Please sign in again.');
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
        throw new InvalidArgumentException('Confirm that your enrollment status and contact information are correct.');
    }

    $db = auth_db();
    $db->beginTransaction();
    try {
        $account = $db->prepare('SELECT a.id FROM accounts a INNER JOIN students s ON s.person_id = a.person_id WHERE a.id = ? AND a.person_id = ? FOR UPDATE');
        $account->execute([$accountId, $personId]);
        if (!$account->fetchColumn()) {
            throw new RuntimeException('Your signed-in student account is unavailable. Please sign in again.');
        }
        $placement = $db->prepare('SELECT academic_year, year_level, section FROM students WHERE person_id = ? FOR UPDATE');
        $placement->execute([$personId]);
        $storedPlacement = $placement->fetch() ?: [];
        $academicYear = trim((string) ($storedPlacement['academic_year'] ?? ''));
        if ($academicYear === '') {
            throw new RuntimeException('Your current school-year placement is unavailable. Please contact the clinic.');
        }
        $yearLevel = (string) ($storedPlacement['year_level'] ?? '');
        $section = (string) ($storedPlacement['section'] ?? '');
        $history = $db->prepare('SELECT enrollment_status FROM student_school_year_enrollments WHERE student_person_id = ? AND academic_year = ? FOR UPDATE');
        $history->execute([$personId, $academicYear]);
        if ((string) $history->fetchColumn() !== 'Pending Confirmation') {
            throw new RuntimeException('Your enrollment status was already updated. Refresh the dashboard to continue.');
        }
        $db->prepare('UPDATE patients SET guardian_or_contact_number = ?, updated_at = NOW() WHERE person_id = ?')->execute([$contactNumber, $personId]);

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
            WHERE a.id = ? AND a.person_id = ? AND a.account_status IN ('active', 'inactive')
        ");
        if ($enrollmentStatus === 'Still Enrolled') {
            $stmt->execute([$accountId, $personId]);
        } else {
            $db->prepare("UPDATE accounts SET account_status = 'inactive', status_reason = 'Manually deactivated: No longer enrolled' WHERE id = ?")
                ->execute([$accountId]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    audit_log_event('auth', 'student_enrollment_declared', $personId, 'student', 'account', $accountId, [
        'academic_year' => $academicYear,
        'enrollment_status' => $enrollmentStatus,
        'non_enrollment_reason' => $nonEnrollmentReason !== '' ? $nonEnrollmentReason : null,
        'year_level' => $yearLevel,
        'section' => $section,
    ]);
    // Send a best-effort confirmation after the database transaction succeeds.
    try {
        $clinic = clinic_profile_settings();
        patient_email_dispatch_event([
            'patient_person_id' => $personId,
            'event_type' => 'enrollment_confirmation',
            'origin' => 'system',
            'source_type' => 'account',
            'source_id' => $accountId,
            'subject' => '[' . ($clinic['system_name'] ?? 'CLINiQ') . '] Enrollment information confirmed',
            'message' => $enrollmentStatus === 'Still Enrolled'
                ? 'Your enrollment information for ' . $academicYear . ' was confirmed. Your clinic-assigned placement remains Year ' . $yearLevel . ' - ' . $section . '.'
                : 'Your non-enrollment declaration for ' . $academicYear . ' was recorded. Contact the clinic when you enroll again.',
            'deliver_now' => true,
        ]);
    } catch (Throwable $mailException) {
        error_log('[CLINiQ Re-enrollment] Confirmation email failed: ' . $mailException->getMessage());
    }
}

function student_enrollment_correction_request(int $personId, string $yearLevel, string $section, string $note): void
{
    $yearLevel = trim($yearLevel);
    $section = strtoupper(trim($section));
    $note = trim($note);
    if (!in_array($yearLevel, ['1', '2', '3', '4'], true)) {
        throw new InvalidArgumentException('Select the year level you believe is correct.');
    }
    if ($section === '' || !preg_match('/^[A-Z0-9][A-Z0-9 .-]{0,79}$/', $section)) {
        throw new InvalidArgumentException('Enter a valid requested section.');
    }
    if ($note === '' || mb_strlen($note) > 1000) {
        throw new InvalidArgumentException('Explain the placement correction in no more than 1,000 characters.');
    }

    $db = auth_db();
    $placement = $db->prepare("SELECT s.academic_year, s.year_level, s.section, e.enrollment_status FROM students s LEFT JOIN student_school_year_enrollments e ON e.student_person_id = s.person_id AND e.academic_year COLLATE utf8mb4_unicode_ci = s.academic_year COLLATE utf8mb4_unicode_ci WHERE s.person_id = ? LIMIT 1");
    $placement->execute([$personId]);
    $current = $placement->fetch();
    if (!$current || (string) ($current['enrollment_status'] ?? '') !== 'Pending Confirmation') {
        throw new RuntimeException('A placement correction can only be requested while enrollment confirmation is pending.');
    }

    $request = $db->prepare("INSERT INTO student_enrollment_correction_requests (student_person_id, academic_year, current_year_level, current_section, requested_year_level, requested_section, request_note) VALUES (?, ?, ?, ?, ?, ?, ?)");
    try {
        $request->execute([$personId, (string) $current['academic_year'], $current['year_level'], $current['section'], $yearLevel, $section, $note]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            throw new InvalidArgumentException('You already have a pending placement correction request for this school year.');
        }
        throw $e;
    }
    audit_log_event('auth', 'student_enrollment_correction_requested', $personId, 'student', 'student', $personId, ['academic_year' => $current['academic_year']]);
}

function student_enrollment_correction_request_for_student(int $personId): ?array
{
    $stmt = auth_db()->prepare("SELECT * FROM student_enrollment_correction_requests WHERE student_person_id = ? ORDER BY created_at DESC, request_id DESC LIMIT 1");
    $stmt->execute([$personId]);
    return $stmt->fetch() ?: null;
}

function review_student_enrollment_correction_request(int $requestId, int $reviewerId, string $decision, string $reviewNote = ''): void
{
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        throw new InvalidArgumentException('Choose whether to approve or reject the placement correction.');
    }
    $reviewNote = trim($reviewNote);
    if ($decision === 'Rejected' && $reviewNote === '') {
        throw new InvalidArgumentException('Explain why the placement correction was rejected.');
    }
    if (mb_strlen($reviewNote) > 1000) {
        throw new InvalidArgumentException('Review notes may not exceed 1,000 characters.');
    }
    $db = auth_db();
    $db->beginTransaction();
    try {
        $request = $db->prepare('SELECT * FROM student_enrollment_correction_requests WHERE request_id = ? FOR UPDATE');
        $request->execute([$requestId]);
        $row = $request->fetch();
        if (!$row || (string) $row['status'] !== 'Pending') {
            throw new InvalidArgumentException('This placement correction is no longer pending.');
        }
        if ($decision === 'Approved') {
            $history = $db->prepare('SELECT enrollment_status FROM student_school_year_enrollments WHERE student_person_id = ? AND academic_year = ? FOR UPDATE');
            $history->execute([$row['student_person_id'], $row['academic_year']]);
            if ((string) $history->fetchColumn() !== 'Pending Confirmation') {
                throw new InvalidArgumentException('The student has already updated this enrollment status.');
            }
            $student = $db->prepare('SELECT person_id FROM students WHERE person_id = ? AND academic_year = ? FOR UPDATE');
            $student->execute([$row['student_person_id'], $row['academic_year']]);
            if (!$student->fetchColumn()) {
                throw new InvalidArgumentException('The student no longer has this current school-year placement.');
            }
            $db->prepare('UPDATE students SET year_level = ?, section = ? WHERE person_id = ? AND academic_year = ?')->execute([$row['requested_year_level'], $row['requested_section'], $row['student_person_id'], $row['academic_year']]);
            $db->prepare("UPDATE student_school_year_enrollments SET year_level = ?, section = ?, promotion_source = 'Clinic Correction' WHERE student_person_id = ? AND academic_year = ?")->execute([$row['requested_year_level'], $row['requested_section'], $row['student_person_id'], $row['academic_year']]);
        }
        $db->prepare('UPDATE student_enrollment_correction_requests SET status = ?, reviewed_by_person_id = ?, review_note = ?, reviewed_at = NOW() WHERE request_id = ?')->execute([$decision, $reviewerId, $reviewNote !== '' ? $reviewNote : null, $requestId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    audit_log_event('auth', 'student_enrollment_correction_' . strtolower($decision), $reviewerId, 'staff', 'student', (int) $row['student_person_id'], ['request_id' => $requestId, 'academic_year' => $row['academic_year']]);
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

function require_report_access(): void
{
    require_login();

    $role = (string) ((current_user() ?? [])['role'] ?? '');
    if (in_array($role, ['admin', 'doctor', 'nurse'], true)) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit('You do not have permission to view clinic reports.');
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
            cs.staff_role,
            cs.honorific
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

    $name = staff_display_name(trim(implode(' ', array_filter([
        $account['first_name'],
        $account['middle_name'],
        $account['last_name'],
    ]))), $account['honorific'] ?? null);

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
    account_assert_strong_password($newPassword, $confirmPassword, 'New password');

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
