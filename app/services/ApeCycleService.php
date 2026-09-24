<?php

require_once __DIR__ . '/PatientNotification.php';
require_once __DIR__ . '/PatientEmail.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/mail.php';
require_once __DIR__ . '/ApeWorkflow.php';
require_once __DIR__ . '/AppointmentWorkflow.php';

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
    $schoolYearTable = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_school_year_enrollments'")->fetchColumn();
    if ((int) $schoolYearTable !== 1) {
        throw new RuntimeException('Student school-year history is missing. Run database/migrations/20260916_create_student_school_year_enrollments.sql.');
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

function can_start_new_school_year(?array $currentCycle): bool
{
    return ($currentCycle['status'] ?? '') === 'Closed';
}

function next_school_year_from_cycle(?array $cycle): string
{
    $fallbackStart = (int) date('Y') - ((int) date('n') < 6 ? 1 : 0);
    $academicYear = normalize_ape_academic_year((string) ($cycle['academic_year'] ?? ($fallbackStart . '-' . ($fallbackStart + 1))));
    [$startYear, $endYear] = array_map('intval', explode('-', $academicYear));
    return $endYear . '-' . ($endYear + 1);
}

function school_year_default_promotion(string $yearLevel): string
{
    $year = (int) trim($yearLevel);
    return $year >= 4 ? 'graduated' : (string) max(1, $year + 1);
}

function school_year_promotion_preview(?array $currentCycle = null): array
{
    ensure_ape_cycle_schema();
    $currentCycle ??= ape_cycle_current();
    if (!can_start_new_school_year($currentCycle)) {
        return ['academic_year' => '', 'students' => [], 'already_processed' => false];
    }
    $academicYear = next_school_year_from_cycle($currentCycle);
    $duplicate = auth_db()->prepare('SELECT COUNT(*) FROM student_school_year_enrollments WHERE academic_year = ?');
    $duplicate->execute([$academicYear]);
    $students = auth_db()->query("
        SELECT s.person_id, s.program_id, s.year_level, s.section, s.academic_year,
               p.id_number, TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS student_name,
               pr.program_code
        FROM students s
        INNER JOIN people p ON p.id = s.person_id
        INNER JOIN patients pt ON pt.person_id = s.person_id
        INNER JOIN accounts a ON a.person_id = s.person_id AND a.account_status = 'active'
        LEFT JOIN programs pr ON pr.id = s.program_id
        ORDER BY pr.program_code, CAST(s.year_level AS UNSIGNED), s.section, p.last_name, p.first_name
    ")->fetchAll();
    foreach ($students as &$student) {
        $student['default_promotion'] = school_year_default_promotion((string) ($student['year_level'] ?? ''));
    }
    unset($student);
    return [
        'academic_year' => $academicYear,
        'students' => $students,
        'already_processed' => (int) $duplicate->fetchColumn() > 0,
    ];
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
            INNER JOIN students s ON s.person_id = pt.person_id
            WHERE a.account_status = 'active'
        ");
        $insertRecords->execute([$cycleId, $academicYear]);
        $created = $insertRecords->rowCount();

        $adoptRecords = $db->prepare("
            UPDATE ape_records ar
            INNER JOIN accounts a ON a.person_id = ar.patient_id AND a.account_status = 'active'
            INNER JOIN students s ON s.person_id = ar.patient_id
            SET ar.ape_cycle_id = ?
            WHERE ar.academic_year = ? AND ar.ape_cycle_id IS NULL
        ");
        $adoptRecords->execute([$cycleId, $academicYear]);
        $adopted = $adoptRecords->rowCount();

        $seedRequirement = $db->prepare("
            INSERT IGNORE INTO ape_requirements (ape_id, requirement_name, status)
            SELECT ar.ape_id, ?, 'Missing'
            FROM ape_records ar
            WHERE ar.ape_cycle_id = ?
        ");
        $requirementsCreated = 0;
        foreach (ape_default_requirements() as $requirementName) {
            $seedRequirement->execute([$requirementName, $cycleId]);
            $requirementsCreated += $seedRequirement->rowCount();
        }

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

function update_ape_required_documents(array $documents, ?int $actorPersonId): array
{
    ensure_ape_cycle_schema();
    $normalized = normalize_ape_required_documents($documents);
    $previous = ape_required_documents();
    $newKeys = array_map(static fn(string $name): string => mb_strtolower($name), $normalized);
    $removed = array_values(array_filter(
        $previous,
        static fn(string $name): bool => !in_array(mb_strtolower($name), $newKeys, true)
    ));

    $db = auth_db();
    $db->beginTransaction();
    try {
        $activeCycleId = (int) ($db->query("SELECT ape_cycle_id FROM ape_cycles WHERE status = 'Active' LIMIT 1 FOR UPDATE")->fetchColumn() ?: 0);
        save_ape_required_documents($normalized, $actorPersonId);

        $addedRows = 0;
        $removedRows = 0;
        if ($activeCycleId > 0) {
            $insert = $db->prepare("
                INSERT IGNORE INTO ape_requirements (ape_id, requirement_name, status)
                SELECT ar.ape_id, ?, 'Missing'
                FROM ape_records ar
                WHERE ar.ape_cycle_id = ?
                  AND ar.requirements_saved_at IS NULL
            ");
            foreach ($normalized as $documentName) {
                $insert->execute([$documentName, $activeCycleId]);
                $addedRows += $insert->rowCount();
            }

            if ($removed) {
                $placeholders = implode(', ', array_fill(0, count($removed), '?'));
                $delete = $db->prepare("
                    DELETE requirement
                    FROM ape_requirements requirement
                    INNER JOIN ape_records record ON record.ape_id = requirement.ape_id
                    LEFT JOIN ape_documents document
                      ON document.ape_id = requirement.ape_id
                     AND document.document_type = requirement.requirement_name
                    WHERE record.ape_cycle_id = ?
                      AND record.requirements_saved_at IS NULL
                      AND requirement.requirement_name IN ({$placeholders})
                      AND requirement.status = 'Missing'
                      AND requirement.checked_at IS NULL
                      AND requirement.upload_group IS NULL
                      AND requirement.upload_due_date IS NULL
                      AND (requirement.remarks IS NULL OR TRIM(requirement.remarks) = '')
                      AND document.document_id IS NULL
                ");
                $delete->execute(array_merge([$activeCycleId], $removed));
                $removedRows = $delete->rowCount();
            }
        }

        $db->commit();
        audit_log_event(
            'settings',
            'ape_required_documents_updated',
            $actorPersonId,
            'staff',
            'ape_cycle',
            $activeCycleId ?: null,
            [
                'documents' => $normalized,
                'active_cycle_added_rows' => $addedRows,
                'active_cycle_removed_rows' => $removedRows,
            ]
        );

        return [
            'documents' => $normalized,
            'active_cycle_id' => $activeCycleId ?: null,
            'added_rows' => $addedRows,
            'removed_rows' => $removedRows,
        ];
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
    return ['Student'];
}

function populate_manual_ape_records_for_staff(int $cycleId, ?int $actorPersonId): int
{
    ensure_ape_cycle_schema();
    $cycle = ape_cycle_fetch($cycleId);
    if (($cycle['status'] ?? '') !== 'Active') {
        throw new InvalidArgumentException('Faculty and NTP records can be populated only for an active APE cycle.');
    }

    $db = auth_db();
    $db->beginTransaction();
    try {
        $candidates = $db->prepare("
            SELECT pt.person_id
            FROM patients pt
            JOIN accounts a ON a.person_id = pt.person_id AND a.account_status = 'active'
            JOIN school_employees se ON se.person_id = pt.person_id
            LEFT JOIN ape_records ar ON ar.patient_id = pt.person_id AND ar.academic_year = ?
            WHERE se.role_classification IN ('Faculty', 'Non-Teaching Personnel')
              AND ar.ape_id IS NULL
            FOR UPDATE
        ");
        $candidates->execute([(string) $cycle['academic_year']]);
        $personIds = array_map(static fn(array $row): int => (int) $row['person_id'], $candidates->fetchAll());

        $insert = $db->prepare("
            INSERT INTO ape_records (ape_cycle_id, patient_id, academic_year, entry_mode, workflow_status, requirement_status)
            VALUES (?, ?, ?, 'Clinic Manual', 'Registered', 'Not Checked')
        ");
        $seedRequirement = $db->prepare("INSERT IGNORE INTO ape_requirements (ape_id, requirement_name, status, upload_group) VALUES (?, ?, 'Missing', 'initial')");
        $log = $db->prepare("INSERT INTO ape_activity_logs (ape_id, performed_by_person_id, action, notes) VALUES (?, ?, 'Faculty/NTP APE record populated', ?)");
        $created = 0;
        foreach ($personIds as $personId) {
            $insert->execute([$cycleId, $personId, (string) $cycle['academic_year']]);
            $apeId = (int) $db->lastInsertId();
            foreach (ape_default_requirements() as $requirement) {
                $seedRequirement->execute([$apeId, $requirement]);
            }
            $log->execute([$apeId, $actorPersonId, 'Clinic-manual record created for the active APE cycle.']);
            $created++;
        }
        $db->commit();
        return $created;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function normalize_ape_batch_time(string $value, string $label): string
{
    $value = preg_replace('/\s+/', ' ', strtoupper(trim($value))) ?? '';
    $time = null;

    if (preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9])\s*(AM|PM)$/', $value, $matches)
        || preg_match('/^(0?[1-9]|1[0-2])([0-5][0-9])\s*(AM|PM)$/', $value, $matches)) {
        $time = DateTimeImmutable::createFromFormat('!g:i A', "{$matches[1]}:{$matches[2]} {$matches[3]}") ?: null;
    } elseif (preg_match('/^(0?[1-9]|1[0-2])\s*(AM|PM)$/', $value, $matches)) {
        $time = DateTimeImmutable::createFromFormat('!g:i A', "{$matches[1]}:00 {$matches[2]}") ?: null;
    } elseif (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
        // Retain support for normalized internal values used by existing integrations.
        $time = DateTimeImmutable::createFromFormat('!H:i', $value) ?: null;
    }

    if (!$time) {
        throw new InvalidArgumentException("Enter a valid {$label} using AM or PM.");
    }

    return $time->format('H:i:s');
}

function ape_validate_batch_working_hours(string $scheduleDate, string $startTime, string $endTime, array $weeklySchedule): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $scheduleDate);
    $day = $date ? (int) $date->format('N') : 0;
    $hours = $weeklySchedule[$day] ?? null;
    if (!$hours || empty($hours['enabled'])) {
        throw new InvalidArgumentException('Choose a clinic working day for the APE batch.');
    }

    $opening = (string) ($hours['start'] ?? '');
    $closing = (string) ($hours['end'] ?? '');
    $start = substr($startTime, 0, 5);
    $end = substr($endTime, 0, 5);
    if ($start < $opening || $end > $closing) {
        throw new InvalidArgumentException(sprintf(
            'The selected day is open from %s to %s. Keep the APE batch within those hours.',
            date('g:i A', strtotime($opening)),
            date('g:i A', strtotime($closing))
        ));
    }

    return $hours;
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
                'Student' AS patient_category,
               COALESCE(pr.program_code, '') AS program_code,
               COALESCE(s.year_level, '') AS year_level,
               COALESCE(s.section, '') AS section
        FROM ape_records ar
        JOIN patients pt ON pt.person_id = ar.patient_id
        JOIN people p ON p.id = pt.person_id
        JOIN accounts a ON a.person_id = pt.person_id AND a.account_status = 'active'
         INNER JOIN students s ON s.person_id = pt.person_id
        LEFT JOIN programs pr ON pr.id = s.program_id
        WHERE ar.ape_cycle_id = ?
          AND ar.schedule_batch_id IS NULL
         ORDER BY p.last_name, p.first_name, ar.ape_id
    ");
    $stmt->execute([$cycleId]);
    return $stmt->fetchAll();
}

function ape_schedule_candidate_groups(int $cycleId): array
{
    $groups = [];
    foreach (ape_schedule_candidates($cycleId) as $candidate) {
        $category = (string) $candidate['patient_category'];
        $program = trim((string) $candidate['program_code']) ?: 'No Program';
        $year = trim((string) $candidate['year_level']) ?: 'No Year';
        $section = trim((string) $candidate['section']) ?: 'No Section';
        $identity = [$category, $program, $year, $section];
        $label = $program . ' • Year ' . $year . ' • Section ' . $section;
        $detail = 'Complete student section';

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
    $category = 'Student';
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
    if ($startTime >= $endTime) {
        throw new InvalidArgumentException('The batch end time must be later than its start time.');
    }
    if ($capacity < 1 || $capacity > 5000) {
        throw new InvalidArgumentException('Enter a patient limit from 1 to 5000.');
    }
    if (!$selectedGroupKeys) {
        throw new InvalidArgumentException('Select at least one student section for this batch.');
    }

    $availableGroups = [];
    foreach (ape_schedule_candidate_groups($cycleId) as $group) {
        $availableGroups[$group['group_key']] = $group;
    }
    $selectedIds = [];
    foreach ($selectedGroupKeys as $groupKey) {
        $group = $availableGroups[$groupKey] ?? null;
        if (!$group) {
            throw new RuntimeException('A selected student section is no longer available.');
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
        if ($scheduleDate < date('Y-m-d')) {
            throw new InvalidArgumentException('The APE batch date cannot be in the past.');
        }
        ape_validate_batch_working_hours($scheduleDate, $startTime, $endTime, appointment_schedule_for_date($scheduleDate));

        $duplicateName = $db->prepare("SELECT batch_id FROM ape_schedule_batches WHERE ape_cycle_id = ? AND LOWER(batch_name) = LOWER(?) LIMIT 1");
        $duplicateName->execute([$cycleId, $batchName]);
        if ($duplicateName->fetchColumn()) {
            throw new InvalidArgumentException('Use a different batch name. That name already exists in this APE cycle.');
        }

        $overlap = $db->prepare("
            SELECT batch_name, patient_category
            FROM ape_schedule_batches
            WHERE ape_cycle_id = ? AND schedule_date = ? AND status = 'Scheduled'
              AND start_time < ? AND end_time > ?
            LIMIT 1
        ");
        $overlap->execute([$cycleId, $scheduleDate, $endTime, $startTime]);
        $overlappingBatch = $overlap->fetch();
        if ($overlappingBatch) {
            throw new InvalidArgumentException(sprintf(
                'This time overlaps the existing APE batch "%s". Choose another time.',
                $overlappingBatch['batch_name']
            ));
        }

        $blocked = $db->prepare("
            SELECT reason
            FROM appointment_availability_blocks
            WHERE block_date = ?
              AND ((start_time IS NULL AND end_time IS NULL) OR (start_time < ? AND end_time > ?))
            LIMIT 1
        ");
        $blocked->execute([$scheduleDate, $endTime, $startTime]);
        $blockedReason = $blocked->fetchColumn();
        if ($blockedReason !== false) {
            $reason = trim((string) $blockedReason);
            throw new InvalidArgumentException('This time is marked unavailable for appointments'
                . ($reason !== '' ? ": {$reason}." : '.')
                . ' Choose another time.');
        }

        $appointment = $db->prepare("
            SELECT appointment_datetime
            FROM appointments
            WHERE status IN ('Pending', 'Scheduled')
              AND appointment_datetime < TIMESTAMP(?, ?)
              AND DATE_ADD(appointment_datetime, INTERVAL 60 MINUTE) > TIMESTAMP(?, ?)
            ORDER BY appointment_datetime
            LIMIT 1
        ");
        $appointment->execute([$scheduleDate, $endTime, $scheduleDate, $startTime]);
        $appointmentTime = $appointment->fetchColumn();
        if ($appointmentTime !== false) {
            throw new InvalidArgumentException(
                'This time conflicts with an existing appointment at '
                . date('g:i A', strtotime((string) $appointmentTime))
                . '. Resolve that appointment or choose another time.'
            );
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $candidate = $db->prepare("
            SELECT ar.ape_id, ar.patient_id
            FROM ape_records ar
            JOIN patients pt ON pt.person_id = ar.patient_id
            JOIN accounts a ON a.person_id = pt.person_id AND a.account_status = 'active'
            INNER JOIN students s ON s.person_id = pt.person_id
            WHERE ar.ape_cycle_id = ? AND ar.schedule_batch_id IS NULL
              AND ar.entry_mode = 'Student Scheduled'
              AND ar.ape_id IN ({$placeholders})
            FOR UPDATE
        ");
        $candidate->execute(array_merge([$cycleId], $selectedIds));
        $candidateRows = $candidate->fetchAll();
        if (count($candidateRows) !== count($selectedIds)) {
            throw new RuntimeException('One or more selected patients are inactive, already assigned, or no longer available.');
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
            WHERE ape_cycle_id = ? AND schedule_batch_id IS NULL
              AND entry_mode = 'Student Scheduled' AND ape_id IN ({$placeholders})
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
        $scheduleLabel = date('F j, Y', strtotime($scheduleDate)) . ' from '
            . date('g:i A', strtotime($startTime)) . ' to ' . date('g:i A', strtotime($endTime));
        foreach ($candidateRows as $candidateRow) {
            patient_notification_create(
                $db,
                (int) $candidateRow['patient_id'],
                $actorPersonId,
                'ape',
                'APE schedule assigned',
                "Your APE schedule is {$scheduleLabel}. Batch: {$batchName}.",
                'patient-ape-status.php',
                'ape_batch',
                $batchId
            );
        }
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
            SELECT batch_name, schedule_date, start_time, end_time FROM ape_schedule_batches
            WHERE batch_id = ? AND ape_cycle_id = ? AND status = 'Scheduled'
            FOR UPDATE
        ");
        $batch->execute([$batchId, $cycleId]);
        $batchRow = $batch->fetch();
        if (!$batchRow) {
            throw new RuntimeException('Only a scheduled batch can be cancelled.');
        }
        $batchName = (string) $batchRow['batch_name'];
        $patients = $db->prepare('SELECT patient_id FROM ape_records WHERE schedule_batch_id = ? FOR UPDATE');
        $patients->execute([$batchId]);
        $patientIds = $patients->fetchAll(PDO::FETCH_COLUMN);
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
        foreach ($patientIds as $patientId) {
            patient_notification_create(
                $db,
                (int) $patientId,
                $actorPersonId,
                'ape',
                'APE schedule cancelled',
                "Your APE schedule in batch {$batchName} was cancelled. The clinic will assign a new schedule.",
                'patient-ape-status.php',
                'ape_batch',
                $batchId
            );
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Promote and reset active student patient accounts at the start of a new school year.
 * Faculty, school personnel, clinic staff, and other patients remain active.
 * Sends a re-enrollment email to each affected student with an email address.
 *
 * Returns promotion and notification totals.
 */
function reset_school_year_accounts(string $academicYear = '', array $submittedPromotions = [], ?int $actorPersonId = null): array
{
    ensure_ape_cycle_schema();
    $currentCycle = ape_cycle_current();
    if (!can_start_new_school_year($currentCycle)) {
        throw new RuntimeException('Close the current APE cycle before starting a new school year.');
    }

    $academicYear = $academicYear !== '' ? normalize_ape_academic_year($academicYear) : next_school_year_from_cycle($currentCycle);
    if ($academicYear !== next_school_year_from_cycle($currentCycle)) {
        throw new InvalidArgumentException('The new school year must immediately follow the closed school year.');
    }

    $db = auth_db();
    $db->beginTransaction();
    try {
        $alreadyProcessed = $db->prepare('SELECT enrollment_id FROM student_school_year_enrollments WHERE academic_year = ? LIMIT 1 FOR UPDATE');
        $alreadyProcessed->execute([$academicYear]);
        if ($alreadyProcessed->fetchColumn()) {
            throw new RuntimeException("Student promotion for school year {$academicYear} has already been completed.");
        }

        $fetch = $db->query("
        SELECT
            a.id AS account_id,
            a.email,
            p.first_name,
            p.middle_name,
            p.last_name,
            s.person_id,
            s.program_id,
            s.year_level,
            s.section,
            s.academic_year
        FROM accounts a
        INNER JOIN patients pt ON pt.person_id = a.person_id
        INNER JOIN people p ON p.id = a.person_id
        INNER JOIN students s ON s.person_id = p.id
        WHERE a.account_status = 'active'
        FOR UPDATE
        ");
        $students = $fetch->fetchAll();

        $saveYear = $db->prepare("
            INSERT INTO student_school_year_enrollments
                (student_person_id, academic_year, program_id, year_level, section, enrollment_status, non_enrollment_reason, promotion_source, promoted_by_person_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                program_id = VALUES(program_id), year_level = VALUES(year_level), section = VALUES(section),
                enrollment_status = VALUES(enrollment_status), non_enrollment_reason = VALUES(non_enrollment_reason),
                promotion_source = VALUES(promotion_source), promoted_by_person_id = VALUES(promoted_by_person_id)
        ");
        $updateStudent = $db->prepare('UPDATE students SET year_level = ?, section = ?, academic_year = ? WHERE person_id = ?');
        $updateGraduatedStudent = $db->prepare('UPDATE students SET academic_year = ? WHERE person_id = ?');
        $deactivate = $db->prepare("UPDATE accounts SET account_status = 'inactive', status_reason = ? WHERE id = ? AND account_status = 'active'");
        $promoted = 0;
        $kept = 0;
        $graduated = 0;
        $notificationPatients = [];

        foreach ($students as $student) {
            $personId = (int) $student['person_id'];
            $currentYearLevel = (string) ($student['year_level'] ?? '');
            $choice = (array) ($submittedPromotions[$personId] ?? []);
            $target = trim((string) ($choice['year_level'] ?? school_year_default_promotion($currentYearLevel)));
            $targetSection = strtoupper(trim((string) ($choice['section'] ?? $student['section'] ?? '')));
            if (!in_array($target, ['1', '2', '3', '4', 'graduated'], true)) {
                throw new InvalidArgumentException('Choose a valid promoted year level for every student.');
            }
            if ($target !== 'graduated' && ($targetSection === '' || mb_strlen($targetSection) > 80)) {
                throw new InvalidArgumentException('Enter a valid section for every continuing student.');
            }

            $previousAcademicYear = trim((string) ($student['academic_year'] ?? '')) ?: (string) $currentCycle['academic_year'];
            $saveYear->execute([$personId, $previousAcademicYear, $student['program_id'], $currentYearLevel, $student['section'], 'Enrolled', null, 'Snapshot', $actorPersonId]);
            $source = $target === school_year_default_promotion($currentYearLevel)
                && $targetSection === strtoupper(trim((string) ($student['section'] ?? '')))
                ? 'Automatic' : 'Manual';

            if ($target === 'graduated') {
                $updateGraduatedStudent->execute([$academicYear, $personId]);
                $deactivate->execute(['Graduated', (int) $student['account_id']]);
                $saveYear->execute([$personId, $academicYear, $student['program_id'], $currentYearLevel, $student['section'], 'Graduated', 'Graduated', $source, $actorPersonId]);
                $graduated++;
                continue;
            }

            $updateStudent->execute([$target, $targetSection, $academicYear, $personId]);
            $deactivate->execute(['New school year enrollment status required', (int) $student['account_id']]);
            $saveYear->execute([$personId, $academicYear, $student['program_id'], $target, $targetSection, 'Pending Confirmation', null, $source, $actorPersonId]);
            (int) $target > (int) $currentYearLevel ? $promoted++ : $kept++;
            $notificationPatients[] = $student;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // Queue re-enrollment emails. The clinic UI processes these in small batches
    // so a large school population cannot block or time out the reset request.
    require_once __DIR__ . '/../services/SystemSettings.php';
    $clinicProfile = clinic_profile_settings();
    $clinicName    = $clinicProfile['system_name'] ?? 'CLINiQ Clinic';
    $loginUrl      = rtrim(env_value('PATIENT_PORTAL_URL', 'http://localhost/CLINiQ/patient-portal'), '/') . '/patient-login.php';

    $queueKey = 'school_year_' . $academicYear . '_' . bin2hex(random_bytes(6));
    $queued = 0;
    foreach ($notificationPatients as $patient) {
        $firstName = (string) ($patient['first_name'] ?? '');
        $notification = cliniq_notification_email('student_re_enrollment', [
            'patient_name' => $firstName,
            'clinic_name' => $clinicName,
        ], $loginUrl);

        $personId = (int) ($patient['person_id'] ?? 0);
        $emailId = patient_email_dispatch_event([
            'patient_person_id' => $personId,
            'event_type' => 'student_re_enrollment',
            'automation_key' => 'school_year_enrollment',
            'source_type' => 'student_school_year_enrollment',
            'source_id' => $personId,
            'dedupe_suffix' => $academicYear,
            'queue_key' => $queueKey,
            'origin' => 'automatic',
            'created_by_person_id' => $actorPersonId,
            'subject' => $notification['subject'],
            'html_body' => $notification['html'],
            'message' => strip_tags((string) $notification['html']),
            'notification' => [
                'enabled' => true,
                'category' => 'enrollment',
                'title' => $notification['subject'],
                'message' => 'Please confirm your enrollment for the new school year.',
                'target_url' => 'patient-dashboard.php',
            ],
        ]);
        if ($emailId > 0) {
            $queued++;
        }
    }

    return [
        'reset' => $promoted + $kept + $graduated,
        'promoted' => $promoted,
        'kept' => $kept,
        'graduated' => $graduated,
        'email_queue_key' => $queueKey,
        'email_queued' => $queued,
        'emailed' => 0,
        'failed' => 0,
        'academic_year' => $academicYear,
    ];
}
