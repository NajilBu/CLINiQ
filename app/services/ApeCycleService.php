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
    // Auto-add exam_schedule_date column if missing.
    $scheduleCol = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ape_cycles' AND COLUMN_NAME = 'exam_schedule_date'")->fetchColumn();
    if ((int) $scheduleCol === 0) {
        $db->exec("ALTER TABLE ape_cycles ADD COLUMN exam_schedule_date DATE NULL AFTER compliance_end");
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
