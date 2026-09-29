<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function graduation_batch_year(string $academicYear): int
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', trim($academicYear), $matches)
        || (int) $matches[2] !== (int) $matches[1] + 1) {
        throw new InvalidArgumentException('Set a valid student school year before recording graduation clearance.');
    }
    return (int) $matches[2];
}

function graduation_student_batch_year(string $academicYear): int
{
    if (trim($academicYear) !== '') {
        return graduation_batch_year($academicYear);
    }
    $cycleYear = auth_db()->query("SELECT academic_year FROM ape_cycles WHERE status IN ('Active', 'Closed') ORDER BY ape_cycle_id DESC LIMIT 1")->fetchColumn();
    if (!is_string($cycleYear)) {
        throw new InvalidArgumentException('Set a valid student school year or start an APE cycle before recording graduation clearance.');
    }
    return graduation_batch_year($cycleYear);
}

function graduation_default_promotion(string $yearLevel, bool $cleared): string
{
    $year = (int) trim($yearLevel);
    return $year >= 4 ? ($cleared ? 'graduated' : '4') : (string) max(1, $year + 1);
}

function graduation_clearance(int $personId, int $batchYear): ?array
{
    $stmt = auth_db()->prepare('SELECT gc.*, TRIM(CONCAT_WS(" ", p.first_name, p.last_name)) AS cleared_by_name
        FROM graduation_clearances gc
        JOIN people p ON p.id = gc.cleared_by_person_id
        WHERE gc.student_person_id = ? AND gc.batch_year = ? AND gc.revoked_at IS NULL
        LIMIT 1');
    $stmt->execute([$personId, $batchYear]);
    return $stmt->fetch() ?: null;
}

function graduation_is_graduated(int $personId): bool
{
    $stmt = auth_db()->prepare("SELECT 1 FROM student_school_year_enrollments WHERE student_person_id = ? AND enrollment_status = 'Graduated' LIMIT 1");
    $stmt->execute([$personId]);
    return (bool) $stmt->fetchColumn();
}

function graduation_set_clearance(int $personId, int $actorPersonId, bool $clear): void
{
    $db = auth_db();
    $db->beginTransaction();
    try {
        $studentStmt = $db->prepare("SELECT s.year_level, s.academic_year, a.account_status
            FROM students s JOIN accounts a ON a.person_id = s.person_id
            WHERE s.person_id = ? FOR UPDATE");
        $studentStmt->execute([$personId]);
        $student = $studentStmt->fetch();
        if (!$student || trim((string) $student['year_level']) !== '4') {
            throw new InvalidArgumentException('Graduation clearance is available only for fourth-year students.');
        }
        if ((string) $student['account_status'] !== 'active') {
            throw new InvalidArgumentException('Only an active fourth-year student can be cleared for graduation.');
        }
        $batchYear = graduation_student_batch_year((string) $student['academic_year']);
        $graduated = $db->prepare("SELECT 1 FROM student_school_year_enrollments WHERE student_person_id = ? AND enrollment_status = 'Graduated' LIMIT 1");
        $graduated->execute([$personId]);
        if ($graduated->fetchColumn()) {
            throw new InvalidArgumentException('This student is already marked graduated.');
        }
        if ($clear) {
            $save = $db->prepare('INSERT INTO graduation_clearances
                (student_person_id, batch_year, cleared_by_person_id)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE cleared_at = CURRENT_TIMESTAMP, cleared_by_person_id = VALUES(cleared_by_person_id), revoked_at = NULL, revoked_by_person_id = NULL');
            $save->execute([$personId, $batchYear, $actorPersonId]);
        } else {
            $revoke = $db->prepare('UPDATE graduation_clearances SET revoked_at = CURRENT_TIMESTAMP, revoked_by_person_id = ?
                WHERE student_person_id = ? AND batch_year = ? AND revoked_at IS NULL');
            $revoke->execute([$actorPersonId, $personId, $batchYear]);
            if ($revoke->rowCount() !== 1) {
                throw new InvalidArgumentException('No active graduation clearance was found for this batch.');
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function graduation_list(?int $batchYear = null): array
{
    $stmt = auth_db()->prepare("
        SELECT records.student_person_id, records.batch_year, records.status, records.cleared_at,
               p.id_number, TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS student_name,
               pr.program_code
        FROM (
            SELECT e.student_person_id, CAST(SUBSTRING_INDEX(e.academic_year, '-', 1) AS UNSIGNED) AS batch_year,
                   'Graduated' AS status, gc.cleared_at
            FROM student_school_year_enrollments e
            LEFT JOIN graduation_clearances gc ON gc.student_person_id = e.student_person_id
                AND gc.batch_year = CAST(SUBSTRING_INDEX(e.academic_year, '-', 1) AS UNSIGNED) AND gc.revoked_at IS NULL
            WHERE e.enrollment_status = 'Graduated'
            UNION ALL
            SELECT gc.student_person_id, gc.batch_year, 'Cleared for Graduation' AS status, gc.cleared_at
            FROM graduation_clearances gc
            WHERE gc.revoked_at IS NULL AND NOT EXISTS (
                SELECT 1 FROM student_school_year_enrollments e
                WHERE e.student_person_id = gc.student_person_id AND e.enrollment_status = 'Graduated'
                  AND CAST(SUBSTRING_INDEX(e.academic_year, '-', 1) AS UNSIGNED) = gc.batch_year
            )
        ) records
        JOIN people p ON p.id = records.student_person_id
        JOIN students s ON s.person_id = records.student_person_id
        LEFT JOIN programs pr ON pr.id = s.program_id
        WHERE (? IS NULL OR records.batch_year = ?)
        ORDER BY records.batch_year DESC, p.last_name, p.first_name
    ");
    $stmt->execute([$batchYear, $batchYear]);
    return $stmt->fetchAll();
}

function graduation_batch_years(): array
{
    return array_values(array_unique(array_map(
        static fn(array $row): int => (int) $row['batch_year'],
        graduation_list()
    )));
}
