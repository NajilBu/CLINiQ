<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/mail.php';

function ensure_ape_cycle_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $db = auth_db();
    $table = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ape_cycles'")->fetchColumn();
    $column = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ape_records' AND COLUMN_NAME = 'ape_cycle_id'")->fetchColumn();
    if ((int) $table !== 1 || (int) $column !== 1) {
        throw new RuntimeException('APE cycle schema is missing. Run database/migrations/20260811_create_ape_cycles.sql.');
    }
    $batchTable = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ape_schedule_batches'")->fetchColumn();
    $batchColumn = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ape_records' AND COLUMN_NAME = 'schedule_batch_id'")->fetchColumn();
    if ((int) $batchTable !== 1 || (int) $batchColumn !== 1) {
        throw new RuntimeException('APE batch scheduling is missing. Run database/migrations/20260902_create_ape_schedule_batches.sql.');
    }
    $ready = true;
}

function normalize_ape_academic_year(string $academicYear): string
{
    $academicYear = trim($academicYear);
    if (!preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $matches) || (int) $matches[2] !== (int) $matches[1] + 1) {
        throw new InvalidArgumentException('Enter a consecutive school year using the format YYYY-YYYY.');
    }
    return $academicYear;
}

function normalize_ape_cycle_date(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', trim($value));
    if (!$date || $date->format('Y-m-d') !== trim($value)) {
        throw new InvalidArgumentException("Select a valid {$label} date.");
    }
    return $date->format('Y-m-d');
}

function ape_cycle_progress(int $cycleId): array
{
    $stmt = auth_db()->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN workflow_status = 'Cleared' OR clearance_status = 'Cleared' THEN 1 ELSE 0 END) AS cleared,
            SUM(CASE WHEN workflow_status <> 'Cleared' AND clearance_status <> 'Cleared'
                      AND (workflow_status = 'Follow-up Required' OR clearance_status = 'For Follow-up' OR follow_up_required = 1)
                     THEN 1 ELSE 0 END) AS follow_up,
            SUM(CASE WHEN workflow_status = 'Registered' AND clearance_status <> 'Cleared'
                      AND follow_up_required = 0 AND clearance_status <> 'For Follow-up'
                     THEN 1 ELSE 0 END) AS not_started,
            SUM(CASE WHEN workflow_status = 'Reviewed' AND clearance_status <> 'Cleared'
                      AND follow_up_required = 0 AND clearance_status <> 'For Follow-up'
                     THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN workflow_status NOT IN ('Registered', 'Reviewed', 'Cleared', 'Follow-up Required')
                      AND clearance_status NOT IN ('Cleared', 'For Follow-up') AND follow_up_required = 0
                     THEN 1 ELSE 0 END) AS in_progress
        FROM ape_records
        WHERE ape_cycle_id = ?
    ");
    $stmt->execute([$cycleId]);
    $progress = $stmt->fetch() ?: [];
    foreach (['total', 'not_started', 'in_progress', 'completed', 'cleared', 'follow_up'] as $key) {
        $progress[$key] = (int) ($progress[$key] ?? 0);
    }
    $progress['compliance_percent'] = $progress['total'] > 0
        ? (int) round(($progress['cleared'] / $progress['total']) * 100)
        : 0;
    return $progress;
}

function ape_cycle_fetch(int $cycleId): ?array
{
    ensure_ape_cycle_schema();
    $stmt = auth_db()->prepare("
        SELECT ac.*,
               TRIM(CONCAT_WS(' ', starter.first_name, starter.middle_name, starter.last_name)) AS started_by_name,
               TRIM(CONCAT_WS(' ', closer.first_name, closer.middle_name, closer.last_name)) AS closed_by_name,
               TRIM(CONCAT_WS(' ', archiver.first_name, archiver.middle_name, archiver.last_name)) AS archived_by_name
        FROM ape_cycles ac
        LEFT JOIN people starter ON starter.id = ac.started_by_person_id
        LEFT JOIN people closer ON closer.id = ac.closed_by_person_id
        LEFT JOIN people archiver ON archiver.id = ac.archived_by_person_id
        WHERE ac.ape_cycle_id = ?
        LIMIT 1
    ");
    $stmt->execute([$cycleId]);
    $cycle = $stmt->fetch() ?: null;
    if ($cycle) {
        $cycle['progress'] = ape_cycle_progress((int) $cycle['ape_cycle_id']);
    }
    return $cycle;
}

function ape_cycle_current(): ?array
{
    ensure_ape_cycle_schema();
    $cycleId = auth_db()->query("
        SELECT ape_cycle_id
        FROM ape_cycles
        ORDER BY CASE status WHEN 'Active' THEN 0 WHEN 'Closed' THEN 1 ELSE 2 END,
                 started_at DESC, ape_cycle_id DESC
        LIMIT 1
    ")->fetchColumn();
    return $cycleId ? ape_cycle_fetch((int) $cycleId) : null;
}

function start_ape_cycle(string $academicYear, string $complianceStart, string $complianceEnd, ?int $actorPersonId, ?string $examScheduleDate = null): array
{
    ensure_ape_cycle_schema();
    $academicYear = normalize_ape_academic_year($academicYear);
    $complianceStart = normalize_ape_cycle_date($complianceStart, 'compliance start');
    $complianceEnd = normalize_ape_cycle_date($complianceEnd, 'compliance end');
    if ($complianceStart > $complianceEnd) {
        throw new InvalidArgumentException('The compliance end date must be on or after the start date.');
    }

    $db = auth_db();
    $db->beginTransaction();
    try {
        $existing = $db->prepare('SELECT status FROM ape_cycles WHERE academic_year = ? FOR UPDATE');
        $existing->execute([$academicYear]);
        if ($existing->fetchColumn() !== false) {
            throw new RuntimeException("An APE cycle already exists for school year {$academicYear}.");
        }
        $active = $db->query("SELECT academic_year FROM ape_cycles WHERE status = 'Active' LIMIT 1 FOR UPDATE")->fetchColumn();
        if ($active !== false) {
            throw new RuntimeException("Close the active {$active} APE cycle before starting another school year.");
        }

        $examDate = null;
        if ($examScheduleDate !== null && $examScheduleDate !== '') {
            $examDate = normalize_ape_cycle_date($examScheduleDate, 'exam schedule');
        }
        $insertCycle = $db->prepare('INSERT INTO ape_cycles (academic_year, compliance_start, compliance_end, exam_schedule_date, started_by_person_id) VALUES (?, ?, ?, ?, ?)');
        $insertCycle->execute([$academicYear, $complianceStart, $complianceEnd, $examDate, $actorPersonId]);
        $cycleId = (int) $db->lastInsertId();

        $insertRecords = $db->prepare("
            INSERT IGNORE INTO ape_records (ape_cycle_id, patient_id, academic_year)
            SELECT ?, pt.person_id, ?
            FROM patients pt
            INNER JOIN accounts a ON a.person_id = pt.person_id
            WHERE a.account_status = 'active'
        ");
        $insertRecords->execute([$cycleId, $academicYear]);
        $created = $insertRecords->rowCount();

        $adoptRecords = $db->prepare("
            UPDATE ape_records ar
            INNER JOIN accounts a ON a.person_id = ar.patient_id AND a.account_status = 'active'
            SET ar.ape_cycle_id = ?
            WHERE ar.academic_year = ? AND ar.ape_cycle_id IS NULL
        ");
        $adoptRecords->execute([$cycleId, $academicYear]);
        $adopted = $adoptRecords->rowCount();

        $seedRequirements = $db->prepare("
            INSERT IGNORE INTO ape_requirements (ape_id, requirement_name, status)
            SELECT ar.ape_id, defaults.requirement_name, 'Missing'
            FROM ape_records ar
            CROSS JOIN (
                SELECT 'Lab Request Form' AS requirement_name
                UNION ALL SELECT 'UHS Consent Form'
                UNION ALL SELECT 'UHS Medical Record'
                UNION ALL SELECT 'UHS Dental Record'
                UNION ALL SELECT 'Referral Form'
            ) defaults
            WHERE ar.ape_cycle_id = ?
        ");
        $seedRequirements->execute([$cycleId]);
        $requirementsCreated = $seedRequirements->rowCount();

        $log = $db->prepare("
            INSERT INTO ape_activity_logs (ape_id, performed_by_person_id, action, notes)
            SELECT ape_id, ?, 'Annual APE cycle started', ?
            FROM ape_records WHERE ape_cycle_id = ?
        ");
        $log->execute([$actorPersonId, "School year {$academicYear}; compliance period {$complianceStart} to {$complianceEnd}.", $cycleId]);
        $db->commit();

        $cycle = ape_cycle_fetch($cycleId);
        if (!$cycle) {
            throw new RuntimeException('The newly created APE cycle could not be loaded.');
        }
        $cycle['created_records'] = $created;
        $cycle['adopted_records'] = $adopted;
        $cycle['created_requirements'] = $requirementsCreated;
        return $cycle;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function close_ape_cycle(int $cycleId, ?int $actorPersonId): array
{
    ensure_ape_cycle_schema();
    $db = auth_db();
    $db->beginTransaction();
    try {
        $update = $db->prepare("UPDATE ape_cycles SET status = 'Closed', closed_by_person_id = ?, closed_at = NOW() WHERE ape_cycle_id = ? AND status = 'Active'");
        $update->execute([$actorPersonId, $cycleId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Only an active APE cycle can be closed.');
        }
        $log = $db->prepare("INSERT INTO ape_activity_logs (ape_id, performed_by_person_id, action, notes) SELECT ape_id, ?, 'Annual APE cycle closed', 'The school-year APE cycle was closed.' FROM ape_records WHERE ape_cycle_id = ?");
        $log->execute([$actorPersonId, $cycleId]);
        $db->commit();
        return ape_cycle_fetch($cycleId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function archive_ape_cycle(int $cycleId, ?int $actorPersonId): array
{
    ensure_ape_cycle_schema();
    $db = auth_db();
    $db->beginTransaction();
    try {
        $update = $db->prepare("UPDATE ape_cycles SET status = 'Archived', archived_by_person_id = ?, archived_at = NOW() WHERE ape_cycle_id = ? AND status = 'Closed'");
        $update->execute([$actorPersonId, $cycleId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Close the APE cycle before archiving it.');
        }
        $log = $db->prepare("INSERT INTO ape_activity_logs (ape_id, performed_by_person_id, action, notes) SELECT ape_id, ?, 'Annual APE cycle archived', 'The school-year APE cycle was archived.' FROM ape_records WHERE ape_cycle_id = ?");
        $log->execute([$actorPersonId, $cycleId]);
        $db->commit();
        return ape_cycle_fetch($cycleId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Update the exam schedule date on an active APE cycle.
 */
function update_ape_cycle_schedule(int $cycleId, string $examScheduleDate): void
{
    ensure_ape_cycle_schema();
    $examDate = $examScheduleDate !== '' ? normalize_ape_cycle_date($examScheduleDate, 'exam schedule') : null;
    $stmt = auth_db()->prepare("UPDATE ape_cycles SET exam_schedule_date = ? WHERE ape_cycle_id = ? AND status = 'Active'");
    $stmt->execute([$examDate, $cycleId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The exam schedule could not be updated. Make sure the cycle is still active.');
    }
}

function ape_schedule_batch_categories(): array
{
    return ['Student', 'Faculty', 'School Personnel'];
}

function normalize_ape_batch_time(string $value, string $label): string
{
    $value = preg_replace('/\s+/', ' ', strtoupper(trim($value))) ?? '';
    $time = null;

    if (preg_match('/^(?:0?[1-9]|1[0-2]):[0-5][0-9] (?:AM|PM)$/', $value)) {
        $time = DateTimeImmutable::createFromFormat('!g:i A', $value) ?: null;
    } elseif (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
        // Retain support for normalized internal values used by existing integrations.
        $time = DateTimeImmutable::createFromFormat('!H:i', $value) ?: null;
    }

    if (!$time) {
        throw new InvalidArgumentException("Enter a valid {$label} using AM or PM.");
    }

    $normalized = $time->format('H:i');
    if ($normalized < '08:00' || $normalized > '17:00') {
        throw new InvalidArgumentException('APE batch times must be between 8:00 AM and 5:00 PM.');
    }
    return $time->format('H:i:s');
}

function ape_schedule_batches(int $cycleId): array
{
    ensure_ape_cycle_schema();
    $stmt = auth_db()->prepare("
        SELECT b.*,
               COUNT(ar.ape_id) AS assigned_count,
               TRIM(CONCAT_WS(' ', creator.first_name, creator.middle_name, creator.last_name)) AS created_by_name
        FROM ape_schedule_batches b
        LEFT JOIN ape_records ar ON ar.schedule_batch_id = b.batch_id
        LEFT JOIN people creator ON creator.id = b.created_by_person_id
        WHERE b.ape_cycle_id = ?
        GROUP BY b.batch_id
        ORDER BY b.schedule_date, b.start_time, b.batch_id
    ");
    $stmt->execute([$cycleId]);
    return $stmt->fetchAll();
}

function ape_schedule_candidates(int $cycleId): array
{
    ensure_ape_cycle_schema();
    $stmt = auth_db()->prepare("
        SELECT ar.ape_id,
               p.id_number,
               TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS patient_name,
               CASE
                   WHEN s.person_id IS NOT NULL THEN 'Student'
                   WHEN se.role_classification = 'Faculty' THEN 'Faculty'
                   ELSE 'School Personnel'
               END AS patient_category,
               COALESCE(pr.program_code, '') AS program_code,
               COALESCE(s.year_level, '') AS year_level,
               COALESCE(s.section, '') AS section,
               COALESCE(d.department_code, staff_department.department_code, '') AS department_code,
               COALESCE(d.department_name, staff_department.department_name, '') AS department_name
        FROM ape_records ar
        JOIN patients pt ON pt.person_id = ar.patient_id
        JOIN people p ON p.id = pt.person_id
        JOIN accounts a ON a.person_id = pt.person_id AND a.account_status = 'active'
        LEFT JOIN students s ON s.person_id = pt.person_id
        LEFT JOIN programs pr ON pr.id = s.program_id
        LEFT JOIN school_employees se ON se.person_id = pt.person_id
        LEFT JOIN departments d ON d.id = se.department_id
        LEFT JOIN clinic_staff cs ON cs.person_id = pt.person_id
        LEFT JOIN departments staff_department ON staff_department.id = cs.department_id
        WHERE ar.ape_cycle_id = ?
          AND ar.schedule_batch_id IS NULL
        ORDER BY patient_category, p.last_name, p.first_name, ar.ape_id
    ");
    $stmt->execute([$cycleId]);
    return $stmt->fetchAll();
}

function ape_schedule_candidate_groups(int $cycleId): array
{
    $groups = [];
    foreach (ape_schedule_candidates($cycleId) as $candidate) {
        $category = (string) $candidate['patient_category'];
        if ($category === 'Student') {
            $program = trim((string) $candidate['program_code']) ?: 'No Program';
            $year = trim((string) $candidate['year_level']) ?: 'No Year';
            $section = trim((string) $candidate['section']) ?: 'No Section';
            $identity = [$category, $program, $year, $section];
            $label = $program . ' • Year ' . $year . ' • Section ' . $section;
            $detail = 'Complete student section';
        } else {
            $departmentCode = trim((string) $candidate['department_code']);
            $departmentName = trim((string) $candidate['department_name']);
            $office = $departmentCode ?: ($departmentName ?: 'Unassigned Office');
            $identity = [$category, $office];
            $label = $office;
            $detail = $departmentName !== '' && $departmentName !== $departmentCode
                ? $departmentName
                : $category . ' department or office';
        }

        $key = strtolower(str_replace(' ', '_', $category)) . ':' . hash('sha256', implode("\0", $identity));
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'group_key' => $key,
                'patient_category' => $category,
                'group_label' => $label,
                'group_detail' => $detail,
                'patient_count' => 0,
                'ape_ids' => [],
            ];
        }
        $groups[$key]['patient_count']++;
        $groups[$key]['ape_ids'][] = (int) $candidate['ape_id'];
    }

    $groups = array_values($groups);
    usort($groups, static fn(array $a, array $b): int => [
        $a['patient_category'],
        $a['group_label'],
    ] <=> [
        $b['patient_category'],
        $b['group_label'],
    ]);
    return $groups;
}

function create_ape_schedule_batch(array $input, ?int $actorPersonId): array
{
    ensure_ape_cycle_schema();
    $cycleId = (int) ($input['ape_cycle_id'] ?? 0);
    $batchName = trim((string) ($input['batch_name'] ?? ''));
    $category = trim((string) ($input['patient_category'] ?? ''));
    $scheduleDate = normalize_ape_cycle_date((string) ($input['schedule_date'] ?? ''), 'batch schedule');
    $startTime = normalize_ape_batch_time((string) ($input['start_time'] ?? ''), 'start time');
    $endTime = normalize_ape_batch_time((string) ($input['end_time'] ?? ''), 'end time');
    $capacity = (int) ($input['capacity'] ?? 0);
    $selectedGroupKeys = array_values(array_unique(array_filter(array_map('strval', (array) ($input['group_keys'] ?? [])))));

    if ($cycleId < 1) {
        throw new InvalidArgumentException('Select an active APE cycle.');
    }
    if ($batchName === '' || mb_strlen($batchName) > 120) {
        throw new InvalidArgumentException('Enter a batch name up to 120 characters.');
    }
    if (!in_array($category, ape_schedule_batch_categories(), true)) {
        throw new InvalidArgumentException('Select Student, Faculty, or School Personnel for this batch.');
    }
    if ($startTime >= $endTime) {
        throw new InvalidArgumentException('The batch end time must be later than its start time.');
    }
    if ($capacity < 1 || $capacity > 5000) {
        throw new InvalidArgumentException('Enter a patient limit from 1 to 5000.');
    }
    if (!$selectedGroupKeys) {
        throw new InvalidArgumentException('Select at least one section, department, or office for this batch.');
    }

    $availableGroups = [];
    foreach (ape_schedule_candidate_groups($cycleId) as $group) {
        $availableGroups[$group['group_key']] = $group;
    }
    $selectedIds = [];
    foreach ($selectedGroupKeys as $groupKey) {
        $group = $availableGroups[$groupKey] ?? null;
        if (!$group) {
            throw new RuntimeException('A selected section, department, or office is no longer available.');
        }
        if (($group['patient_category'] ?? '') !== $category) {
            throw new InvalidArgumentException('A batch cannot mix Students, Faculty, and School Personnel.');
        }
        $selectedIds = array_merge($selectedIds, $group['ape_ids']);
    }
    $selectedIds = array_values(array_unique(array_map('intval', $selectedIds)));
    if (count($selectedIds) > $capacity) {
        throw new InvalidArgumentException('The selected groups exceed the patient limit.');
    }

    $db = auth_db();
    $db->beginTransaction();
    try {
        $cycle = $db->prepare("SELECT * FROM ape_cycles WHERE ape_cycle_id = ? AND status = 'Active' FOR UPDATE");
        $cycle->execute([$cycleId]);
        $cycleRow = $cycle->fetch();
        if (!$cycleRow) {
            throw new RuntimeException('Only an active APE cycle can receive schedule batches.');
        }
        if ($scheduleDate < $cycleRow['compliance_start'] || $scheduleDate > $cycleRow['compliance_end']) {
            throw new InvalidArgumentException('The batch date must be within the APE compliance period.');
        }

        $overlap = $db->prepare("
            SELECT batch_name, patient_category
            FROM ape_schedule_batches
            WHERE ape_cycle_id = ? AND schedule_date = ? AND status = 'Scheduled'
              AND patient_category <> ? AND start_time < ? AND end_time > ?
            LIMIT 1
        ");
        $overlap->execute([$cycleId, $scheduleDate, $category, $endTime, $startTime]);
        $overlappingBatch = $overlap->fetch();
        if ($overlappingBatch) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot overlap with %s because Student, Faculty, and School Personnel schedules must use separate times.',
                $batchName,
                $overlappingBatch['batch_name']
            ));
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $candidate = $db->prepare("
            SELECT ar.ape_id,
                   CASE
                       WHEN s.person_id IS NOT NULL THEN 'Student'
                       WHEN se.role_classification = 'Faculty' THEN 'Faculty'
                       ELSE 'School Personnel'
                   END AS patient_category
            FROM ape_records ar
            JOIN patients pt ON pt.person_id = ar.patient_id
            JOIN accounts a ON a.person_id = pt.person_id AND a.account_status = 'active'
            LEFT JOIN students s ON s.person_id = pt.person_id
            LEFT JOIN school_employees se ON se.person_id = pt.person_id
            WHERE ar.ape_cycle_id = ? AND ar.schedule_batch_id IS NULL
              AND ar.ape_id IN ({$placeholders})
            FOR UPDATE
        ");
        $candidate->execute(array_merge([$cycleId], $selectedIds));
        $candidateRows = $candidate->fetchAll();
        if (count($candidateRows) !== count($selectedIds)) {
            throw new RuntimeException('One or more selected patients are inactive, already assigned, or no longer available.');
        }
        foreach ($candidateRows as $candidateRow) {
            if (($candidateRow['patient_category'] ?? '') !== $category) {
                throw new InvalidArgumentException('A batch cannot mix Students, Faculty, and School Personnel.');
            }
        }

        $insert = $db->prepare("
            INSERT INTO ape_schedule_batches
                (ape_cycle_id, batch_name, patient_category, schedule_date, start_time, end_time, capacity, created_by_person_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$cycleId, $batchName, $category, $scheduleDate, $startTime, $endTime, $capacity, $actorPersonId]);
        $batchId = (int) $db->lastInsertId();

        $assign = $db->prepare("
            UPDATE ape_records
            SET schedule_batch_id = ?,
                workflow_status = CASE WHEN workflow_status = 'Registered' THEN 'Batch Assigned' ELSE workflow_status END
            WHERE ape_cycle_id = ? AND schedule_batch_id IS NULL AND ape_id IN ({$placeholders})
        ");
        $assign->execute(array_merge([$batchId, $cycleId], $selectedIds));
        if ($assign->rowCount() !== count($selectedIds)) {
            throw new RuntimeException('Not all selected patients could be assigned. No batch was saved.');
        }

        $log = $db->prepare("
            INSERT INTO ape_activity_logs (ape_id, performed_by_person_id, action, notes)
            SELECT ape_id, ?, 'Assigned APE schedule batch', ?
            FROM ape_records WHERE schedule_batch_id = ?
        ");
        $log->execute([$actorPersonId, "{$batchName}: {$scheduleDate} {$startTime}-{$endTime}", $batchId]);
        $db->commit();
        return ['batch_id' => $batchId, 'batch_name' => $batchName, 'assigned_count' => count($selectedIds)];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function cancel_ape_schedule_batch(int $batchId, int $cycleId, ?int $actorPersonId): void
{
    ensure_ape_cycle_schema();
    $db = auth_db();
    $db->beginTransaction();
    try {
        $batch = $db->prepare("
            SELECT batch_name FROM ape_schedule_batches
            WHERE batch_id = ? AND ape_cycle_id = ? AND status = 'Scheduled'
            FOR UPDATE
        ");
        $batch->execute([$batchId, $cycleId]);
        $batchName = $batch->fetchColumn();
        if ($batchName === false) {
            throw new RuntimeException('Only a scheduled batch can be cancelled.');
        }
        $log = $db->prepare("
            INSERT INTO ape_activity_logs (ape_id, performed_by_person_id, action, notes)
            SELECT ape_id, ?, 'APE schedule batch cancelled', ?
            FROM ape_records WHERE schedule_batch_id = ?
        ");
        $log->execute([$actorPersonId, "Batch {$batchName} was cancelled; the patient can be assigned again.", $batchId]);
        $db->prepare("
            UPDATE ape_records
            SET schedule_batch_id = NULL,
                workflow_status = CASE WHEN workflow_status = 'Batch Assigned' THEN 'Registered' ELSE workflow_status END
            WHERE schedule_batch_id = ?
        ")->execute([$batchId]);
        $db->prepare("UPDATE ape_schedule_batches SET status = 'Cancelled' WHERE batch_id = ?")->execute([$batchId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Reset all patient accounts to inactive at the start of a new school year.
 * Students must confirm re-enrollment; faculty/personnel must confirm re-employment.
 * Sends an email to each affected patient.
 *
 * Returns an array: ['reset' => int, 'emailed' => int, 'failed' => int]
 */
function reset_school_year_accounts(): array
{
    $db = auth_db();

    // Fetch all patients that will be deactivated BEFORE the reset, so we have their info.
    $fetch = $db->query("
        SELECT
            a.id AS account_id,
            a.email,
            p.first_name,
            p.last_name,
            CASE
                WHEN s.person_id IS NOT NULL THEN 'student'
                WHEN se.role_classification = 'Faculty' THEN 'faculty'
                ELSE 'school_personnel'
            END AS account_type
        FROM accounts a
        INNER JOIN patients pt ON pt.person_id = a.person_id
        INNER JOIN people p ON p.id = a.person_id
        LEFT JOIN students s ON s.person_id = p.id
        LEFT JOIN school_employees se ON se.person_id = p.id
        WHERE a.account_status = 'active'
          AND a.email IS NOT NULL
          AND a.email <> ''
    ");
    $patients = $fetch->fetchAll();

    // Now perform the reset.
    $reset = $db->prepare("
        UPDATE accounts a
        INNER JOIN patients pt ON pt.person_id = a.person_id
        SET a.account_status = 'inactive',
            a.activated_at = NULL
        WHERE a.account_status = 'active'
    ");
    $reset->execute();
    $resetCount = $reset->rowCount();

    // Send re-enrollment emails.
    require_once __DIR__ . '/../services/SystemSettings.php';
    $clinicProfile = clinic_profile_settings();
    $clinicName    = $clinicProfile['system_name'] ?? 'CLINiQ Clinic';
    $loginUrl      = rtrim(env_value('PATIENT_PORTAL_URL', 'http://localhost/CLINiQ/patient-portal'), '/') . '/patient-login.php';

    $emailed = 0;
    $failed  = 0;
    foreach ($patients as $patient) {
        $firstName   = (string) ($patient['first_name'] ?? '');
        $accountType = (string) ($patient['account_type'] ?? 'student');
        $templateKey = $accountType === 'student' ? 'student_re_enrollment' : 'employee_re_employment';
        $notification = cliniq_notification_email($templateKey, [
            'patient_name' => $firstName,
            'clinic_name' => $clinicName,
        ], $loginUrl);

        $fullName = trim($firstName . ' ' . ($patient['last_name'] ?? ''));
        $sent = send_cliniq_email(
            (string) $patient['email'],
            $fullName ?: 'Patient',
            $notification['subject'],
            $notification['html']
        );

        $sent ? $emailed++ : $failed++;
    }

    return ['reset' => $resetCount, 'emailed' => $emailed, 'failed' => $failed];
}
