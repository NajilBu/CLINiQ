<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/student_id.php';
require_once __DIR__ . '/ApeWorkflow.php';
require_once __DIR__ . '/ApeCycleService.php';

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
        default => 'School Personnel',
    };
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
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
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
    $idNumber = strtoupper(trim((string) ($input['id_number'] ?? '')));
    $type = patient_account_type((string) ($input['patient_type'] ?? $input['category'] ?? 'school_personnel'));
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $middleName = trim((string) ($input['middle_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $birthdate = trim((string) ($input['birthdate'] ?? ''));
    $sex = normalize_person_sex((string) ($input['sex'] ?? ''));
    $programDepartment = trim((string) ($input['program_or_department'] ?? ''));
    $yearEmployment = trim((string) ($input['year_level_or_employment_type'] ?? ''));
    $sectionPosition = trim((string) ($input['section_or_position'] ?? ''));
    $academicYear = trim((string) ($input['academic_year'] ?? ''));
    $programId = null;
    $departmentId = null;

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

    if (!is_valid_id_number($idNumber)) {
        throw new InvalidArgumentException(id_number_validation_message());
    }
    if ($firstName === '' || $lastName === '') {
        throw new InvalidArgumentException('First name and last name are required.');
    }
    if (!patient_account_valid_birthdate($birthdate)) {
        throw new InvalidArgumentException('Birthdate must use the YYYY-MM-DD format.');
    }

    $initialPassword = patient_account_initial_password($idNumber);
    $db = auth_db();

    try {
        $db->beginTransaction();

        $duplicate = $db->prepare('SELECT id FROM people WHERE id_number = ? LIMIT 1');
        $duplicate->execute([$idNumber]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException('ID number already exists.');
        }

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
                $type === 'faculty' ? 'Faculty' : 'School Personnel',
                $yearEmployment !== '' ? $yearEmployment : null,
                $sectionPosition !== '' ? $sectionPosition : null,
            ]);
        }

        $patient = $db->prepare('
            INSERT INTO patients (person_id, emergency_token, token_enabled)
            VALUES (?, ?, 1)
        ');
        $patient->execute([$personId, bin2hex(random_bytes(32))]);

        $account = $db->prepare('
            UPDATE accounts
            SET
                password_hash = ?,
                account_status = "inactive",
                activated_at = NULL
            WHERE person_id = ?
        ');
        $account->execute([
            password_hash($initialPassword, PASSWORD_DEFAULT),
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
            if ($activeCycle) {
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
                'status' => $e->getMessage(),
            ];
        }
    }

    return $results;
}

function recent_patient_accounts(int $limit = 100): array
{
    $limit = max(1, min(250, $limit));
    return auth_db()->query("
        SELECT
            p.id_number,
            CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) AS full_name,
            p.birthdate,
            a.account_status,
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
        ORDER BY a.created_at DESC, p.id DESC
        LIMIT {$limit}
    ")->fetchAll();
}
