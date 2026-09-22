<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/student_id.php';
require_once __DIR__ . '/../helpers/data_normalization.php';
require_once __DIR__ . '/ApeWorkflow.php';
require_once __DIR__ . '/ApeCycleService.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/PatientAccessStatus.php';
require_once __DIR__ . '/AccountValidation.php';

function can_manage_patient_accounts(?array $user): bool
{
    return in_array($user['role'] ?? '', ['admin', 'doctor'], true);
}

function patient_account_type(string $value): string
{
    $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value))) ?? '';
    if (in_array($normalized, ['school_personnel', 'personnel', 'patient'], true)) {
        return 'school_personnel';
    }

    return in_array($normalized, ['student', 'faculty'], true)
        ? $normalized
        : 'school_personnel';
}

function patient_account_type_label(string $value): string
{
    return match (patient_account_type($value)) {
        'student' => 'Student',
        'faculty' => 'Faculty',
        default => 'Non-Teaching Personnel (NTP)',
    };
}

function patient_account_manual_inactive_reasons(): array
{
    return [
        'No longer enrolled',
        'Leave of absence',
        'Graduated',
        'Transferred',
        'Withdrawn',
        'Employment ended',
        'Duplicate account',
        'Account owner requested deactivation',
        'Administrative hold',
    ];
}

function patient_account_manual_inactive_prefix(): string
{
    return 'Manually deactivated: ';
}

function deactivate_patient_account(int $accountId, string $reason, ?int $actorPersonId): array
{
    $reason = trim($reason);
    if ($accountId < 1) {
        throw new InvalidArgumentException('Select a valid patient account.');
    }
    if (!in_array($reason, patient_account_manual_inactive_reasons(), true)) {
        throw new InvalidArgumentException('Select a valid reason for deactivating this account.');
    }

    $db = auth_db();
    $db->beginTransaction();
    try {
        $query = $db->prepare("
            SELECT a.id AS account_id, a.person_id, a.account_status,
                   TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS patient_name
            FROM accounts a
            INNER JOIN people p ON p.id = a.person_id
            INNER JOIN patients pt ON pt.person_id = a.person_id
            LEFT JOIN clinic_staff cs ON cs.person_id = a.person_id
            WHERE a.id = ? AND cs.person_id IS NULL
            LIMIT 1
            FOR UPDATE
        ");
        $query->execute([$accountId]);
        $account = $query->fetch();
        if (!$account) {
            throw new RuntimeException('Patient account was not found.');
        }
        if (($account['account_status'] ?? '') !== 'active') {
            throw new RuntimeException('Only an active patient account can be deactivated.');
        }

        $update = $db->prepare("UPDATE accounts SET account_status = 'inactive', status_reason = ? WHERE id = ? AND account_status = 'active'");
        $update->execute([patient_account_manual_inactive_prefix() . $reason, $accountId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The patient account could not be deactivated.');
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    audit_log_event('accounts', 'patient_account_deactivated', $actorPersonId, 'staff', 'account', $accountId, [
        'reason' => $reason,
        'patient_person_id' => (int) $account['person_id'],
    ]);
    return $account;
}

function reactivate_patient_account(int $accountId, ?int $actorPersonId): array
{
    if ($accountId < 1) {
        throw new InvalidArgumentException('Select a valid patient account.');
    }

    $db = auth_db();
    $db->beginTransaction();
    try {
        $query = $db->prepare("
            SELECT a.id AS account_id, a.person_id, a.account_status, a.status_reason,
                   TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS patient_name
            FROM accounts a
            INNER JOIN people p ON p.id = a.person_id
            INNER JOIN patients pt ON pt.person_id = a.person_id
            LEFT JOIN clinic_staff cs ON cs.person_id = a.person_id
            WHERE a.id = ? AND cs.person_id IS NULL
            LIMIT 1
            FOR UPDATE
        ");
        $query->execute([$accountId]);
        $account = $query->fetch();
        if (!$account) {
            throw new RuntimeException('Patient account was not found.');
        }
        $expectedPrefix = patient_account_manual_inactive_prefix();
        if (($account['account_status'] ?? '') !== 'inactive'
            || !str_starts_with((string) ($account['status_reason'] ?? ''), $expectedPrefix)) {
            throw new RuntimeException('Only an account manually deactivated by the clinic can be reactivated here.');
        }

        $update = $db->prepare("UPDATE accounts SET account_status = 'active', status_reason = NULL WHERE id = ? AND account_status = 'inactive'");
        $update->execute([$accountId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The patient account could not be reactivated.');
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    audit_log_event('accounts', 'patient_account_reactivated', $actorPersonId, 'staff', 'account', $accountId, [
        'previous_reason' => (string) $account['status_reason'],
        'patient_person_id' => (int) $account['person_id'],
    ]);
    return $account;
}

function change_patient_access_status(int $accountId, string $accessStatus, ?int $actorPersonId): array
{
    if ($accountId < 1) {
        throw new InvalidArgumentException('Choose a valid patient account.');
    }

    $db = auth_db();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('
            SELECT a.person_id, TRIM(CONCAT_WS(" ", p.first_name, p.middle_name, p.last_name)) AS patient_name
            FROM accounts a
            JOIN people p ON p.id = a.person_id
            JOIN patients pt ON pt.person_id = p.id
            WHERE a.id = ?
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new RuntimeException('Patient account not found.');
        }

        $result = patient_access_status_set(
            $db,
            (int) $account['person_id'],
            $accessStatus,
            $actorPersonId,
            'manual_account_management'
        );
        $db->commit();

        return $result + [
            'patient_name' => trim((string) $account['patient_name']),
            'person_id' => (int) $account['person_id'],
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function patient_account_default_email(string $firstName, string $lastName): string
{
    $normalize = static function (string $value): string {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
        $value = strtolower($ascii === false ? trim($value) : $ascii);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim($value, '_');
    };

    return $normalize($lastName) . '_' . $normalize($firstName) . '@plpasig.edu.ph';
}

function normalize_faculty_employment_type(string $value): string
{
    $normalized = preg_replace('/[^a-z]+/', '', strtolower(trim($value))) ?? '';

    return match ($normalized) {
        'fulltime' => 'Full-time',
        'parttime' => 'Part-time',
        default => throw new InvalidArgumentException(
            'Faculty employment type must be Full-time or Part-time.'
        ),
    };
}

function patient_account_normalize_id_number(string $idNumber, string $type): string
{
    if ($type === 'student') {
        return normalize_id_number($idNumber);
    }

    return preg_replace('/\D+/', '', trim($idNumber)) ?? '';
}

function patient_account_id_number_is_valid(string $idNumber, string $type): bool
{
    return $type === 'student'
        ? is_valid_id_number($idNumber)
        : preg_match('/^\d{7}$/', $idNumber) === 1;
}

function patient_account_id_number_validation_message(string $type): string
{
    return $type === 'student'
        ? id_number_validation_message()
        : 'ID number is required and must contain exactly seven digits.';
}

function normalize_student_program_code(string $value): string
{
    $code = strtoupper(trim($value));
    if (!preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $code)) {
        throw new InvalidArgumentException(
            'Student program must be a code such as BSIT.'
        );
    }

    return $code;
}

/**
 * @return array<int,array{code:string,name:string,department_code:string}>
 */
function patient_account_active_programs(): array
{
    return auth_db()->query('
        SELECT
            p.program_code AS code,
            p.program_name AS name,
            d.department_code
        FROM programs p
        JOIN departments d ON d.id = p.department_id
        WHERE p.is_active = 1
          AND d.is_active = 1
        ORDER BY p.program_code
    ')->fetchAll();
}

/**
 * @return array<int,array{code:string,name:string}>
 */
function patient_account_active_departments(): array
{
    return auth_db()->query('
        SELECT
            department_code AS code,
            department_name AS name
        FROM departments
        WHERE is_active = 1
        ORDER BY department_code
    ')->fetchAll();
}

function patient_account_active_program_code(string $value): string
{
    $code = normalize_student_program_code($value);
    $statement = auth_db()->prepare('
        SELECT 1
        FROM programs p
        JOIN departments d ON d.id = p.department_id
        WHERE p.program_code = ?
          AND p.is_active = 1
          AND d.is_active = 1
        LIMIT 1
    ');
    $statement->execute([$code]);

    if (!$statement->fetchColumn()) {
        throw new InvalidArgumentException('Select an active student program from the list.');
    }

    return $code;
}

function patient_account_active_program_id(string $code): int
{
    $statement = auth_db()->prepare('
        SELECT p.id
        FROM programs p
        JOIN departments d ON d.id = p.department_id
        WHERE p.program_code = ?
          AND p.is_active = 1
          AND d.is_active = 1
        LIMIT 1
    ');
    $statement->execute([strtoupper(trim($code))]);
    $programId = (int) $statement->fetchColumn();

    if ($programId < 1) {
        throw new InvalidArgumentException('Select an active student program from the list.');
    }

    return $programId;
}

function patient_account_active_department_code(string $value): string
{
    $code = strtoupper(trim($value));
    if ($code === '') {
        throw new InvalidArgumentException('Select an active department from the list.');
    }
    if (!preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $code)) {
        throw new InvalidArgumentException('Select an active department from the list.');
    }

    $statement = auth_db()->prepare('
        SELECT 1
        FROM departments
        WHERE department_code = ?
          AND is_active = 1
        LIMIT 1
    ');
    $statement->execute([$code]);

    if (!$statement->fetchColumn()) {
        throw new InvalidArgumentException('Select an active department from the list.');
    }

    return $code;
}

function patient_account_active_department_id(string $code): int
{
    $statement = auth_db()->prepare('
        SELECT id
        FROM departments
        WHERE department_code = ?
          AND is_active = 1
        LIMIT 1
    ');
    $statement->execute([strtoupper(trim($code))]);
    $departmentId = (int) $statement->fetchColumn();

    if ($departmentId < 1) {
        throw new InvalidArgumentException('Select an active department from the list.');
    }

    return $departmentId;
}

function normalize_student_year_level(string $value): string
{
    $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';
    $suffixes = [1 => 'st', 2 => 'nd', 3 => 'rd', 4 => 'th'];

    foreach ($suffixes as $year => $suffix) {
        if (in_array($normalized, [
            (string) $year,
            $year . $suffix,
            $year . $suffix . 'year',
            'year' . $year,
        ], true)) {
            return (string) $year;
        }
    }

    throw new InvalidArgumentException('Student year level must be 1, 2, 3, or 4.');
}

function normalize_student_section_code(string $value): string
{
    $code = strtoupper(trim($value));
    if (!in_array($code, ['A', 'B', 'C', 'D', 'E'], true)) {
        throw new InvalidArgumentException('Student section code must be A, B, C, D, or E.');
    }

    return $code;
}

function patient_account_initial_password(string $idNumber): string
{
    $digits = preg_replace('/\D+/', '', $idNumber) ?? '';
    if (strlen($digits) < 3) {
        throw new InvalidArgumentException('ID number must contain at least three digits.');
    }

    $lastThree = substr($digits, -3);
    return $lastThree . $lastThree;
}

function patient_account_valid_birthdate(string $value): bool
{
    try {
        account_assert_valid_birthdate($value);
        return true;
    } catch (InvalidArgumentException) {
        return false;
    }
}

function patient_account_valid_name(string $value): bool
{
    return account_valid_person_name($value);
}

function normalize_person_sex(string $value): string
{
    return match (strtolower(trim($value))) {
        'male' => 'Male',
        'female' => 'Female',
        'other' => 'Other',
        default => throw new InvalidArgumentException('Sex must be Male, Female, or Other.'),
    };
}

/**
 * Create one inactive patient account and category profile.
 *
 * @return array{id_number:string,name:string,type:string,password:string,status:string}
 */
function create_inactive_patient_account(array $input): array
{
    $rawType = trim((string) ($input['patient_type'] ?? $input['category'] ?? ''));
    $normalizedType = preg_replace('/[^a-z0-9]+/', '_', strtolower($rawType)) ?? '';
    if (!in_array($normalizedType, ['student', 'faculty', 'school_personnel'], true)) {
        throw new InvalidArgumentException('Select a patient type.');
    }
    $type = $normalizedType;
    $idNumber = patient_account_normalize_id_number((string) ($input['id_number'] ?? ''), $type);
    $firstName = cliniq_normalize_person_name($input['first_name'] ?? '');
    $middleName = cliniq_normalize_person_name($input['middle_name'] ?? '');
    $lastName = cliniq_normalize_person_name($input['last_name'] ?? '');
    $birthdate = trim((string) ($input['birthdate'] ?? ''));
    $rawSex = trim((string) ($input['sex'] ?? ''));
    $programDepartment = trim((string) ($input['program_or_department'] ?? ''));
    $yearEmployment = trim((string) ($input['year_level_or_employment_type'] ?? ''));
    $sectionPosition = trim((string) ($input['section_or_position'] ?? ''));
    $academicYear = trim((string) ($input['academic_year'] ?? ''));
    $accessStatus = $type === 'student'
        ? patient_access_status_normalize($input['access_status'] ?? 'Applicant')
        : 'Official';
    $programId = null;
    $departmentId = null;

    $missingFields = [];
    foreach ([
        'ID number' => $idNumber,
        'first name' => $firstName,
        'last name' => $lastName,
        'birthdate' => $birthdate,
        'sex' => $rawSex,
        $type === 'student' ? 'program' : 'department' => $programDepartment,
        $type === 'student' ? 'year level' : 'employment type' => $yearEmployment,
        $type === 'student' ? 'section code' : ($type === 'faculty' ? 'title' : 'position') => $sectionPosition,
    ] as $label => $value) {
        if ($value === '') {
            $missingFields[] = $label;
        }
    }
    if ($missingFields !== []) {
        throw new InvalidArgumentException('Complete the required field' . (count($missingFields) > 1 ? 's' : '') . ': ' . implode(', ', $missingFields) . '.');
    }

    $sex = normalize_person_sex($rawSex);

    if ($type === 'student') {
        $programDepartment = patient_account_active_program_code($programDepartment);
        $programId = patient_account_active_program_id($programDepartment);
        $yearEmployment = normalize_student_year_level($yearEmployment);
        $sectionCode = normalize_student_section_code($sectionPosition);
        $sectionPosition = $sectionCode;
    } elseif ($type === 'faculty') {
        $programDepartment = patient_account_active_department_code($programDepartment);
        $departmentId = patient_account_active_department_id($programDepartment);
        $yearEmployment = normalize_faculty_employment_type($yearEmployment);
        $sectionPosition = strtoupper($sectionPosition);
    } else {
        $programDepartment = patient_account_active_department_code($programDepartment);
        $departmentId = patient_account_active_department_id($programDepartment);
    }

    if (!patient_account_id_number_is_valid($idNumber, $type)) {
        throw new InvalidArgumentException(patient_account_id_number_validation_message($type));
    }
    if (!patient_account_valid_name($firstName) || !patient_account_valid_name($lastName)) {
        throw new InvalidArgumentException("First name and last name may contain only letters, spaces, apostrophes, periods, and hyphens.");
    }
    if ($middleName !== '' && !patient_account_valid_name($middleName)) {
        throw new InvalidArgumentException("Middle name may contain only letters, spaces, apostrophes, periods, and hyphens.");
    }
    if (!patient_account_valid_birthdate($birthdate)) {
        throw new InvalidArgumentException('Enter a valid birthdate within the last 120 years and not in the future.');
    }
    if (strlen($yearEmployment) > 100 || strlen($sectionPosition) > 100) {
        throw new InvalidArgumentException('Employment type, title, or position cannot exceed 100 characters.');
    }

    $initialPassword = patient_account_initial_password($idNumber);
    $email = patient_account_default_email($firstName, $lastName);
    $db = auth_db();

    try {
        $db->beginTransaction();

        $duplicate = $db->prepare('SELECT id FROM people WHERE id_number = ? LIMIT 1');
        $duplicate->execute([$idNumber]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException('ID number already exists.');
        }
        account_assert_email_available($db, $email);

        $personStmt = $db->prepare('
            INSERT INTO people (id_number, first_name, middle_name, last_name, birthdate, sex)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $personStmt->execute([
            $idNumber,
            $firstName,
            $middleName !== '' ? $middleName : null,
            $lastName,
            $birthdate,
            $sex,
        ]);
        $personId = (int) $db->lastInsertId();

        if ($type === 'student') {
            $profile = $db->prepare('
                INSERT INTO students (person_id, program_id, year_level, section, academic_year)
                VALUES (?, ?, ?, ?, ?)
            ');
            $profile->execute([
                $personId,
                $programId,
                $yearEmployment !== '' ? $yearEmployment : null,
                $sectionPosition !== '' ? $sectionPosition : null,
                $academicYear !== '' ? $academicYear : null,
            ]);
        } elseif (in_array($type, ['faculty', 'school_personnel'], true)) {
            $profile = $db->prepare('
                INSERT INTO school_employees (
                    person_id, department_id, role_classification, employment_type, position_title
                ) VALUES (?, ?, ?, ?, ?)
            ');
            $profile->execute([
                $personId,
                $departmentId,
                $type === 'faculty' ? 'Faculty' : 'Non-Teaching Personnel',
                $yearEmployment !== '' ? $yearEmployment : null,
                $sectionPosition !== '' ? $sectionPosition : null,
            ]);
        }

        $patient = $db->prepare('
            INSERT INTO patients (person_id, emergency_token, token_enabled, access_status)
            VALUES (?, ?, 1, ?)
        ');
        $patient->execute([$personId, bin2hex(random_bytes(32)), $accessStatus]);

        $account = $db->prepare('
            UPDATE accounts
            SET
                password_hash = ?,
                email = ?,
                account_status = "inactive",
                status_reason = "Awaiting initial account activation",
                activated_at = NULL
            WHERE person_id = ?
        ');
        $account->execute([
            password_hash($initialPassword, PASSWORD_DEFAULT),
            $email,
            $personId,
        ]);

        // Auto-enroll the new patient into an active APE cycle if one exists.
        try {
            ensure_ape_cycle_schema();
            $activeCycle = auth_db()->query("
                SELECT ape_cycle_id, academic_year
                FROM ape_cycles
                WHERE status = 'Active'
                ORDER BY started_at DESC
                LIMIT 1
            ")->fetch();
            if ($activeCycle && $type === 'student') {
                // Only create the record if one doesn't already exist for this patient + cycle.
                $existsCheck = $db->prepare('
                    SELECT COUNT(*) FROM ape_records
                    WHERE patient_id = ? AND ape_cycle_id = ?
                ');
                $existsCheck->execute([$personId, (int) $activeCycle['ape_cycle_id']]);
                if ((int) $existsCheck->fetchColumn() === 0) {
                    $apeStmt = $db->prepare("
                        INSERT INTO ape_records (
                            patient_id, academic_year, ape_cycle_id,
                            requirement_status, workflow_status, clearance_status,
                            follow_up_required
                        ) VALUES (?, ?, ?, 'Not Checked', 'Registered', 'Pending', 0)
                    ");
                    $apeStmt->execute([
                        $personId,
                        $activeCycle['academic_year'],
                        (int) $activeCycle['ape_cycle_id'],
                    ]);
                    $apeId = (int) $db->lastInsertId();
                    ape_seed_default_requirements($apeId, 'Missing');
                }
            }
        } catch (Throwable) {
            // Non-fatal: APE cycle schema may not be ready yet; skip silently.
        }

        $db->commit();

        return [
            'id_number' => $idNumber,
            'name' => trim(implode(' ', array_filter([$firstName, $middleName, $lastName]))),
            'type' => $type,
            'password' => $initialPassword,
            'access_status' => $accessStatus,
            'status' => 'created',
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if ($e instanceof InvalidArgumentException) {
            throw $e;
        }
        if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
            throw new InvalidArgumentException('ID number already exists.');
        }

        throw $e;
    }
}

/**
 * @return array<int,array<string,string>>
 */
function create_bulk_inactive_patient_accounts(array $rows): array
{
    if (count($rows) > 500) {
        throw new InvalidArgumentException('A bulk import can contain at most 500 rows.');
    }

    $results = [];
    foreach ($rows as $index => $row) {
        $rowNumber = $index + 2;
        try {
            $result = create_inactive_patient_account(is_array($row) ? $row : []);
            $results[] = array_merge(['row' => (string) $rowNumber], $result);
        } catch (Throwable $e) {
            $results[] = [
                'row' => (string) $rowNumber,
                'id_number' => strtoupper(trim((string) (($row['id_number'] ?? '') ?: '—'))),
                'name' => trim(implode(' ', array_filter([
                    $row['first_name'] ?? '',
                    $row['middle_name'] ?? '',
                    $row['last_name'] ?? '',
                ]))) ?: '—',
                'type' => patient_account_type((string) ($row['patient_type'] ?? $row['category'] ?? 'school_personnel')),
                'password' => '',
                'access_status' => '',
                'status' => $e->getMessage(),
            ];
        }
    }

    return $results;
}

function recent_patient_accounts(?int $limit = null): array
{
    $sql = "
        SELECT
            a.id AS account_id,
            p.id AS person_id,
            p.id_number,
            CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) AS full_name,
            a.account_status,
            a.status_reason,
            pt.access_status,
            a.activated_at,
            a.created_at,
            CASE
                WHEN s.person_id IS NOT NULL THEN 'Student'
                WHEN se.person_id IS NOT NULL THEN se.role_classification
                ELSE 'Patient'
            END AS patient_type
        FROM people p
        JOIN accounts a ON a.person_id = p.id
        JOIN patients pt ON pt.person_id = p.id
        LEFT JOIN students s ON s.person_id = p.id
        LEFT JOIN school_employees se ON se.person_id = p.id
        LEFT JOIN clinic_staff cs ON cs.person_id = p.id
        WHERE cs.person_id IS NULL
        ORDER BY a.created_at DESC, p.id DESC
    ";
    if ($limit !== null) {
        $limit = max(1, $limit);
        $sql .= " LIMIT {$limit}";
    }
    return auth_db()->query($sql)->fetchAll();
}
