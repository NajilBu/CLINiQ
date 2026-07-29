<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/student_id.php';

function can_manage_patient_accounts(?array $user): bool
{
    return in_array($user['role'] ?? '', ['admin', 'doctor'], true);
}

function patient_account_type(string $value): string
{
    $normalized = strtolower(trim($value));
    return in_array($normalized, ['student', 'faculty', 'patient'], true)
        ? $normalized
        : 'patient';
}

function patient_account_temporary_password(string $idNumber): string
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

/**
 * Create one inactive patient account and category profile.
 *
 * @return array{id_number:string,name:string,type:string,temporary_password:string,status:string}
 */
function create_inactive_patient_account(array $input): array
{
    $idNumber = trim((string) ($input['id_number'] ?? ''));
    $type = patient_account_type((string) ($input['patient_type'] ?? $input['category'] ?? 'patient'));
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $middleName = trim((string) ($input['middle_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $birthdate = trim((string) ($input['birthdate'] ?? ''));
    $programDepartment = trim((string) ($input['program_or_department'] ?? ''));
    $yearEmployment = trim((string) ($input['year_level_or_employment_type'] ?? ''));
    $sectionPosition = trim((string) ($input['section_or_position'] ?? ''));
    $academicYear = trim((string) ($input['academic_year'] ?? ''));

    if (!is_valid_id_number($idNumber)) {
        throw new InvalidArgumentException(id_number_validation_message());
    }
    if ($firstName === '' || $lastName === '') {
        throw new InvalidArgumentException('First name and last name are required.');
    }
    if (!patient_account_valid_birthdate($birthdate)) {
        throw new InvalidArgumentException('Birthdate must use the YYYY-MM-DD format.');
    }

    $temporaryPassword = patient_account_temporary_password($idNumber);
    $db = auth_db();

    try {
        $db->beginTransaction();

        $duplicate = $db->prepare('SELECT id FROM people WHERE id_number = ? LIMIT 1');
        $duplicate->execute([$idNumber]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException('ID number already exists.');
        }

        $personStmt = $db->prepare('
            INSERT INTO people (id_number, first_name, middle_name, last_name, birthdate)
            VALUES (?, ?, ?, ?, ?)
        ');
        $personStmt->execute([
            $idNumber,
            $firstName,
            $middleName !== '' ? $middleName : null,
            $lastName,
            $birthdate,
        ]);
        $personId = (int) $db->lastInsertId();

        if ($type === 'student') {
            $profile = $db->prepare('
                INSERT INTO students (person_id, program, year_level, section, academic_year)
                VALUES (?, ?, ?, ?, ?)
            ');
            $profile->execute([
                $personId,
                $programDepartment !== '' ? $programDepartment : null,
                $yearEmployment !== '' ? $yearEmployment : null,
                $sectionPosition !== '' ? $sectionPosition : null,
                $academicYear !== '' ? $academicYear : null,
            ]);
        } elseif ($type === 'faculty') {
            $profile = $db->prepare('
                INSERT INTO faculty (person_id, department, employment_type, position_title)
                VALUES (?, ?, ?, ?)
            ');
            $profile->execute([
                $personId,
                $programDepartment !== '' ? $programDepartment : null,
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
                password_hash = NULL,
                temporary_password_hash = ?,
                account_status = "inactive",
                activated_at = NULL
            WHERE person_id = ?
        ');
        $account->execute([
            password_hash($temporaryPassword, PASSWORD_DEFAULT),
            $personId,
        ]);

        $db->commit();

        return [
            'id_number' => $idNumber,
            'name' => trim(implode(' ', array_filter([$firstName, $middleName, $lastName]))),
            'type' => $type,
            'temporary_password' => $temporaryPassword,
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
                'id_number' => trim((string) (($row['id_number'] ?? '') ?: '—')),
                'name' => trim(implode(' ', array_filter([
                    $row['first_name'] ?? '',
                    $row['middle_name'] ?? '',
                    $row['last_name'] ?? '',
                ]))) ?: '—',
                'type' => patient_account_type((string) ($row['patient_type'] ?? $row['category'] ?? 'patient')),
                'temporary_password' => '',
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
                WHEN f.person_id IS NOT NULL THEN 'Faculty'
                ELSE 'Patient'
            END AS patient_type
        FROM people p
        JOIN accounts a ON a.person_id = p.id
        JOIN patients pt ON pt.person_id = p.id
        LEFT JOIN students s ON s.person_id = p.id
        LEFT JOIN faculty f ON f.person_id = p.id
        ORDER BY a.created_at DESC, p.id DESC
        LIMIT {$limit}
    ")->fetchAll();
}
