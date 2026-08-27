<?php

require_once __DIR__ . '/SystemSettings.php';

function ape_workflow_steps(): array
{
    return [
        'Patient Vitals and BMI',
        'Examination',
        'Digital Submission',
        'Final Decision / Follow-up',
        'Completed',
    ];
}

function ape_requirement_status_options(): array
{
    return dropdown_options('ape_requirement_status');
}

function ape_verification_status_options(): array
{
    return dropdown_options('ape_verification_status');
}

function ape_workflow_status_options(): array
{
    return dropdown_options('ape_workflow_status');
}

function ape_work_queues(): array
{
    return [
        'examination' => [
            'title' => 'Examination',
            'short_title' => 'Examination',
            'description' => 'After the patient confirms vitals and BMI, authorized clinic staff examines the patient and checks hard-copy documents together.',
            'icon' => 'stethoscope',
        ],
        'digital_submission' => [
            'title' => 'Digital Submission',
            'short_title' => 'Digital Keeping',
            'description' => 'After examination, patients submit checked documents online for clinic record keeping and archive review.',
            'icon' => 'upload_file',
        ],
        'final_decision' => [
            'title' => 'Final Decision',
            'short_title' => 'Final Decision',
            'description' => 'Clinic staff reviews the completed examination and archived documents, then clears the patient or records follow-up.',
            'icon' => 'clinical_notes',
        ],
        'follow_up' => [
            'title' => 'Follow-up Patients',
            'short_title' => 'Follow-up',
            'description' => 'Patients with findings who must submit treatment proof, clearance, or other required follow-up documents.',
            'icon' => 'medical_information',
        ],
        'completed' => [
            'title' => 'Completed APE Records',
            'short_title' => 'Completed',
            'description' => 'Patients whose checked documents are archived and whose follow-up requirements are cleared.',
            'icon' => 'task_alt',
        ],
    ];
}

function ape_record_queue(array $record): string
{
    if (($record['workflow_status'] ?? '') === 'Cleared' || ($record['clearance_status'] ?? '') === 'Cleared') {
        return 'completed';
    }

    if ((int)($record['follow_up_required'] ?? 0) === 1 || in_array(($record['clearance_status'] ?? ''), ['For Follow-up', 'Submitted'], true)) {
        return 'follow_up';
    }

    if (($record['patient_vitals_status'] ?? 'Not Started') !== 'Confirmed' || empty($record['exam_date']) || ($record['requirement_status'] ?? '') !== 'Pre-Verified') {
        return 'examination';
    }

    $requiredDocumentCount = array_key_exists('required_document_count', $record)
        ? (int) $record['required_document_count']
        : (empty($record['document_path']) ? 0 : 5);
    $unverifiedDocumentCount = array_key_exists('required_unverified_count', $record)
        ? (int) $record['required_unverified_count']
        : (in_array(($record['verification_status'] ?? 'Pending'), ['Pending', 'Needs Correction'], true) ? 1 : 0);
    if ($requiredDocumentCount < count(ape_default_requirements()) || $unverifiedDocumentCount > 0) {
        return 'digital_submission';
    }

    return 'final_decision';
}

function ape_next_action(array $record): array
{
    return match (ape_record_queue($record)) {
        'examination' => (($record['patient_vitals_status'] ?? 'Not Started') !== 'Confirmed'
            ? ['label' => 'Wait for Patient Vitals', 'icon' => 'monitor_heart']
            : ['label' => 'Record Examination', 'icon' => 'stethoscope']),
        'digital_submission' => (int) ($record['required_document_count'] ?? 0) < count(ape_default_requirements())
            ? ['label' => 'Wait for Patient Upload', 'icon' => 'upload_file']
            : ['label' => 'Archive Submission', 'icon' => 'inventory_2'],
        'final_decision' => ['label' => 'Record Final Decision', 'icon' => 'clinical_notes'],
        'follow_up' => ['label' => 'Update Follow-up', 'icon' => 'medical_information'],
        'completed' => ['label' => 'Completed', 'icon' => 'check_circle'],
        default => ['label' => 'Review Record', 'icon' => 'visibility'],
    };
}

function ape_record_stage_label(array $record): string
{
    $queueKey = ape_record_queue($record);
    $queues = ape_work_queues();

    return $queues[$queueKey]['short_title'] ?? $queues[$queueKey]['title'] ?? 'APE Review';
}

function ape_record_step_index(array $record): int
{
    return match (ape_record_queue($record)) {
        'examination' => 0,
        'digital_submission' => 2,
        'final_decision' => 3,
        'follow_up' => 3,
        'completed' => 4,
        default => 0,
    };
}

function ape_waiting_days(array $record): int
{
    $rawDate = $record['updated_at'] ?? $record['created_at'] ?? null;
    if (!$rawDate) {
        return 0;
    }

    $timestamp = strtotime($rawDate);
    if (!$timestamp) {
        return 0;
    }

    return max(0, (int)floor((time() - $timestamp) / 86400));
}

function ape_follow_up_due_date(array $record): ?string
{
    $rawDate = trim((string) ($record['follow_up_due_date'] ?? ''));
    if ($rawDate !== '') {
        $timestamp = strtotime($rawDate);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    return null;
}

function ape_deadline_status(array $record): ?array
{
    $queueKey = ape_record_queue($record);
    $dueDate = null;

    if ($queueKey === 'digital_submission') {
        $examDate = $record['exam_date'] ?? null;
        $examTimestamp = $examDate ? strtotime((string) $examDate) : false;
        if (!$examTimestamp) {
            return null;
        }
        $dueDate = date('Y-m-d', strtotime('+7 days', $examTimestamp));
    } elseif ($queueKey === 'follow_up') {
        $dueDate = ape_follow_up_due_date($record);
    }

    if (!$dueDate) {
        return null;
    }

    $today = new DateTimeImmutable('today');
    $due = DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);
    if (!$due) {
        return null;
    }

    $diff = (int) $today->diff($due)->format('%r%a');
    if ($diff < 0) {
        return [
            'label' => 'Overdue',
            'class' => 'badge-critical',
            'due_date' => $dueDate,
            'days' => abs($diff),
        ];
    }

    if ($diff <= 2) {
        return [
            'label' => 'Urgent',
            'class' => 'badge-high',
            'due_date' => $dueDate,
            'days' => $diff,
        ];
    }

    return [
        'label' => 'On Track',
        'class' => 'badge-in-progress',
        'due_date' => $dueDate,
        'days' => $diff,
    ];
}

function ape_priority_badge(array $record): array
{
    $queueKey = ape_record_queue($record);
    if ($queueKey === 'completed') {
        return ['label' => 'Done', 'class' => 'badge-completed'];
    }

    $deadline = ape_deadline_status($record);
    if ($deadline && in_array($deadline['label'], ['Overdue', 'Urgent'], true)) {
        return ['label' => $deadline['label'], 'class' => $deadline['class']];
    }

    if ($queueKey === 'follow_up') {
        return ['label' => 'Clinical', 'class' => 'badge-high'];
    }

    // If an exam schedule date is set and has passed and the patient hasn't confirmed vitals, mark as Missed.
    $examDate = $record['exam_schedule_date'] ?? null;
    if (
        $examDate
        && ($record['patient_vitals_status'] ?? 'Not Started') === 'Not Started'
        && date('Y-m-d') > $examDate
    ) {
        return ['label' => 'Missed', 'class' => 'badge-critical'];
    }

    if ($queueKey === 'digital_submission') {
        return ['label' => 'Waiting', 'class' => 'badge-pending'];
    }

    return ['label' => 'Ready', 'class' => 'badge-in-progress'];
}

function ape_waiting_label(array $record): string
{
    $days = ape_waiting_days($record);
    if ($days === 0) {
        return 'Updated today';
    }

    return $days . ' day' . ($days === 1 ? '' : 's') . ' waiting';
}


function ape_next_action_card(array $record): array
{
    return match (ape_record_queue($record)) {
        'examination' => [
            'title' => (($record['patient_vitals_status'] ?? 'Not Started') !== 'Confirmed' ? 'Wait for patient vitals and BMI confirmation' : 'Record the examination'),
            'body' => (($record['patient_vitals_status'] ?? 'Not Started') !== 'Confirmed' ? 'The patient must enter and confirm their height, weight, BMI, and vital signs before the clinic can begin the examination.' : 'Enter the examination result while checking the patient’s hard-copy APE documents in the same step.'),
        ],
        'digital_submission' => (int) ($record['required_document_count'] ?? 0) < count(ape_default_requirements())
            ? [
                'title' => 'Wait for the patient to submit all checked documents online',
                'body' => 'The patient uploads the checked requirements for clinic record keeping. Expected files include Lab Request Form, UHS Consent Form, UHS Medical Record, UHS Dental Record, and Referral Form.',
            ]
            : [
                'title' => 'Archive the digital submission',
                'body' => 'Confirm that the online files match the checked hard copies, then archive them so the record can proceed to final decision.',
            ],
        'final_decision' => [
            'title' => 'Record the final clinical decision',
            'body' => 'Review the examination and archived documents, then explicitly clear the patient, require follow-up, or create a referral.',
        ],
        'follow_up' => [
            'title' => 'Track the required follow-up',
            'body' => 'Keep the record open until the patient submits the required treatment proof, medical clearance, or other follow-up document.',
        ],
        'completed' => [
            'title' => 'APE process completed',
            'body' => 'This record is cleared and stored as part of the patient clinic file.',
        ],
        default => [
            'title' => 'Review this APE record',
            'body' => 'Open the record details and complete the next clinic action.',
        ],
    };
}

function ape_missing_item(array $record): string
{
    if (!empty($record['missing_items'])) {
        return $record['missing_items'];
    }
    if (($record['patient_vitals_status'] ?? 'Not Started') !== 'Confirmed') {
        return 'Waiting for patient vitals and BMI confirmation';
    }
    if (empty($record['exam_date']) || ($record['requirement_status'] ?? '') === 'Not Checked') {
        return 'Ready for examination';
    }
    if (($record['requirement_status'] ?? '') === 'Needs Correction') {
        return 'Hard-copy requirements need attention';
    }
    if ((int)($record['follow_up_required'] ?? 0) === 1 || ($record['clearance_status'] ?? '') === 'For Follow-up') {
        return 'Follow-up action required';
    }
    if ((int) ($record['required_document_count'] ?? 0) < count(ape_default_requirements())) {
        return 'Waiting for patient online submission';
    }
    if ((int) ($record['required_unverified_count'] ?? 0) > 0 && ($record['verification_status'] ?? '') === 'Pending') {
        return 'Online documents waiting for archive review';
    }
    if (($record['verification_status'] ?? '') === 'Needs Correction') {
        return 'Online submission correction needed';
    }
    if (ape_record_queue($record) === 'final_decision') {
        return 'Final clearance decision required';
    }
    if ((int)($record['follow_up_required'] ?? 0) === 1 && ($record['clearance_status'] ?? '') !== 'Submitted') {
        return 'Waiting for follow-up requirement';
    }
    if (($record['clearance_status'] ?? '') === 'Submitted') {
        return 'Follow-up document waiting for approval';
    }
    return 'None';
}

function ape_workflow_step_index(?string $status): int
{
    return match ($status) {
        'Registered', 'Batch Assigned' => 0,
        'Exam Done' => 1,
        'Requirements Checked', 'Scheduled' => 2,
        'Submitted', 'Reviewed' => 3,
        'Follow-up Required' => 3,
        'Cleared' => 4,
        default => 0,
    };
}

function ape_status_badge_class(?string $status): string
{
    return match (strtolower((string)$status)) {
        'cleared', 'verified', 'pre-verified', 'exam done', 'completed', 'fit to proceed' => 'badge-completed',
        'scheduled', 'reviewed', 'submitted', 'batch assigned', 'requirements checked', 'with finding' => 'badge-in-progress',
        'follow-up required', 'needs correction', 'for follow-up' => 'badge-high',
        'critical' => 'badge-critical',
        'low' => 'badge-low',
        'moderate', 'pending', 'not checked' => 'badge-pending',
        default => 'badge-pending',
    };
}

function ape_next_actions(): array
{
    return [
        'requirements_checked' => 'Requirements Checked',
        'submitted' => 'Submitted',
        'reviewed' => 'Reviewed',
        'follow_up' => 'Follow-up Required',
        'cleared' => 'Cleared',
        'needs_correction' => 'Submitted',
    ];
}

function ensure_ape_workflow_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = auth_db();
    foreach (['ape_cycles', 'ape_records', 'ape_requirements', 'ape_documents', 'ape_findings', 'ape_activity_logs'] as $table) {
        $stmt = $db->prepare('
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ');
        $stmt->execute([$table]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException("Required Cliniq_db table {$table} is missing. Run the APE migration first.");
        }
    }

    $measurementColumns = [
        'patient_height_cm' => "DECIMAL(5,2) NULL AFTER patient_visible_note",
        'patient_weight_kg' => "DECIMAL(5,2) NULL AFTER patient_height_cm",
        'patient_bmi' => "DECIMAL(5,2) NULL AFTER patient_weight_kg",
        'patient_temperature' => "DECIMAL(4,1) NULL AFTER patient_bmi",
        'patient_blood_pressure' => "VARCHAR(20) NULL AFTER patient_temperature",
        'patient_pulse_rate' => "SMALLINT UNSIGNED NULL AFTER patient_blood_pressure",
        'patient_vitals_status' => "ENUM('Not Started', 'Confirmed') NOT NULL DEFAULT 'Not Started' AFTER patient_pulse_rate",
        'patient_vitals_confirmed_at' => "DATETIME NULL AFTER patient_vitals_status",
    ];
    $workflowColumns = [
        'follow_up_due_date' => "DATE NULL AFTER follow_up_required",
    ];
    foreach (array_merge($measurementColumns, $workflowColumns) as $column => $definition) {
        $columnCheck = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'ape_records\' AND COLUMN_NAME = ?');
        $columnCheck->execute([$column]);
        if ((int) $columnCheck->fetchColumn() === 0) {
            $db->exec("ALTER TABLE ape_records ADD COLUMN {$column} {$definition}");
        }
    }

    $ready = true;
}

function ape_record_select_sql(): string
{
    return "
        SELECT
            ar.*,
            ar.ape_id AS id,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.id_number,
            p.sex,
            p.birthdate,
            COALESCE(
                NULLIF(TRIM(CONCAT(pr.program_code, '-', s.year_level, UPPER(s.section))), ''),
                ed.department_code,
                'Patient'
            ) AS course_section,
            COALESCE((
                SELECT d.document_type
                FROM ape_documents d
                WHERE d.ape_id = ar.ape_id
                ORDER BY d.uploaded_at DESC, d.document_id DESC
                LIMIT 1
            ), 'APE Form') AS document_type,
            (
                SELECT d.file_path
                FROM ape_documents d
                WHERE d.ape_id = ar.ape_id
                ORDER BY d.uploaded_at DESC, d.document_id DESC
                LIMIT 1
            ) AS document_path,
            COALESCE((
                SELECT d.verification_status
                FROM ape_documents d
                WHERE d.ape_id = ar.ape_id
                  AND d.document_id = (
                      SELECT MAX(latest_document.document_id)
                      FROM ape_documents latest_document
                      WHERE latest_document.ape_id = d.ape_id
                        AND latest_document.document_type = d.document_type
                  )
                ORDER BY FIELD(d.verification_status, 'Needs Correction', 'Pending', 'Verified'), d.uploaded_at DESC
                LIMIT 1
            ), 'Pending') AS verification_status,
            COALESCE(
                TRIM(CONCAT_WS(' ', reviewer.first_name, reviewer.middle_name, reviewer.last_name)),
                (
                    SELECT TRIM(CONCAT_WS(' ', verifier.first_name, verifier.middle_name, verifier.last_name))
                    FROM ape_documents verified_document
                    JOIN people verifier ON verifier.id = verified_document.verified_by_person_id
                    WHERE verified_document.ape_id = ar.ape_id
                    ORDER BY verified_document.verified_at DESC, verified_document.document_id DESC
                    LIMIT 1
                )
            ) AS verified_by_name,
            ap.appointment_datetime,
            CASE WHEN ap.appointment_id IS NULL THEN NULL ELSE 'PLP Clinic' END AS appointment_location,
            (
                SELECT GROUP_CONCAT(CONCAT(r.requirement_name, ' (', r.status, ')') ORDER BY r.requirement_id SEPARATOR ', ')
                FROM ape_requirements r
                WHERE r.ape_id = ar.ape_id AND r.status <> 'Verified'
            ) AS missing_items,
            CASE
                WHEN EXISTS (SELECT 1 FROM ape_findings f WHERE f.ape_id = ar.ape_id AND f.result_status = 'Referred') THEN 'Referred'
                WHEN EXISTS (SELECT 1 FROM ape_findings f WHERE f.ape_id = ar.ape_id AND f.result_status = 'With Finding') THEN 'With Finding'
                WHEN EXISTS (SELECT 1 FROM ape_findings f WHERE f.ape_id = ar.ape_id) THEN 'Normal'
                ELSE 'Pending'
            END AS result_status,
            (
                SELECT GROUP_CONCAT(f.description ORDER BY f.recorded_at DESC SEPARATOR ' | ')
                FROM ape_findings f
                WHERE f.ape_id = ar.ape_id
            ) AS result_notes,
            (
                SELECT d.file_path
                FROM ape_documents d
                WHERE d.ape_id = ar.ape_id AND d.document_type = 'Clearance'
                ORDER BY d.uploaded_at DESC, d.document_id DESC
                LIMIT 1
            ) AS clearance_document_path,
            (SELECT COUNT(*) FROM ape_documents d WHERE d.ape_id = ar.ape_id) AS document_count,
            (
                SELECT COUNT(DISTINCT required_document.document_type)
                FROM ape_documents required_document
                WHERE required_document.ape_id = ar.ape_id
                  AND required_document.document_type IN ('Lab Request Form', 'UHS Consent Form', 'UHS Medical Record', 'UHS Dental Record', 'Referral Form')
            ) AS required_document_count,
            (
                SELECT COUNT(*)
                FROM ape_documents latest_required
                WHERE latest_required.ape_id = ar.ape_id
                  AND latest_required.document_type IN ('Lab Request Form', 'UHS Consent Form', 'UHS Medical Record', 'UHS Dental Record', 'Referral Form')
                  AND latest_required.document_id = (
                      SELECT MAX(required_version.document_id)
                      FROM ape_documents required_version
                      WHERE required_version.ape_id = latest_required.ape_id
                        AND required_version.document_type = latest_required.document_type
                  )
                  AND latest_required.verification_status <> 'Verified'
            ) AS required_unverified_count
        FROM ape_records ar
        JOIN patients patient_profile ON patient_profile.person_id = ar.patient_id
        JOIN people p ON p.id = patient_profile.person_id
        LEFT JOIN students s ON s.person_id = p.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        LEFT JOIN school_employees se ON se.person_id = p.id
        LEFT JOIN departments ed ON ed.id = se.department_id
        LEFT JOIN clinic_staff reviewer_staff ON reviewer_staff.person_id = ar.reviewed_by_person_id
        LEFT JOIN people reviewer ON reviewer.id = reviewer_staff.person_id
        LEFT JOIN appointments ap ON ap.appointment_id = ar.appointment_id
        LEFT JOIN ape_cycles ac ON ac.ape_cycle_id = ar.ape_cycle_id
    ";
}

function ape_fetch_records(string $search = '', int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $sql = ape_record_select_sql();
    $params = [];
    if ($search !== '') {
        $sql .= "
            WHERE p.first_name LIKE ?
               OR p.last_name LIKE ?
               OR p.id_number LIKE ?
               OR pr.program_code LIKE ?
               OR ed.department_code LIKE ?
               OR EXISTS (
                    SELECT 1 FROM ape_documents search_document
                    WHERE search_document.ape_id = ar.ape_id
                      AND search_document.document_type LIKE ?
               )
        ";
        $term = '%' . $search . '%';
        $params = array_fill(0, 6, $term);
    }
    $sql .= " ORDER BY ar.updated_at DESC, ar.created_at DESC LIMIT {$limit}";
    $stmt = auth_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function ape_fetch_record(int $apeId): ?array
{
    $stmt = auth_db()->prepare(ape_record_select_sql() . ' WHERE ar.ape_id = ? LIMIT 1');
    $stmt->execute([$apeId]);
    return $stmt->fetch() ?: null;
}

function ape_fetch_patient_record(int $patientId): ?array
{
    $stmt = auth_db()->prepare(ape_record_select_sql() . '
        WHERE ar.patient_id = ?
        ORDER BY ar.updated_at DESC, ar.created_at DESC
        LIMIT 1
    ');
    $stmt->execute([$patientId]);
    return $stmt->fetch() ?: null;
}

function ape_fetch_patient_records(int $patientId): array
{
    $stmt = auth_db()->prepare(ape_record_select_sql() . '
        WHERE ar.patient_id = ?
        ORDER BY ar.exam_date DESC, ar.created_at DESC
    ');
    $stmt->execute([$patientId]);
    return $stmt->fetchAll();
}

function ape_requirements_for_record(int $apeId): array
{
    $stmt = auth_db()->prepare("
        SELECT r.*, TRIM(CONCAT_WS(' ', checker.first_name, checker.middle_name, checker.last_name)) AS checked_by_name
        FROM ape_requirements r
        LEFT JOIN people checker ON checker.id = r.checked_by_person_id
        WHERE r.ape_id = ?
        ORDER BY r.requirement_id ASC
    ");
    $stmt->execute([$apeId]);
    return $stmt->fetchAll();
}

function ape_documents_for_record(int $apeId): array
{
    $stmt = auth_db()->prepare("
        SELECT d.*,
               TRIM(CONCAT_WS(' ', uploader.first_name, uploader.middle_name, uploader.last_name)) AS uploaded_by_name,
               TRIM(CONCAT_WS(' ', verifier.first_name, verifier.middle_name, verifier.last_name)) AS verified_by_name
        FROM ape_documents d
        LEFT JOIN people uploader ON uploader.id = d.uploaded_by_person_id
        LEFT JOIN people verifier ON verifier.id = d.verified_by_person_id
        WHERE d.ape_id = ?
        ORDER BY d.uploaded_at DESC, d.document_id DESC
    ");
    $stmt->execute([$apeId]);
    return $stmt->fetchAll();
}

function ape_findings_for_record(int $apeId): array
{
    $stmt = auth_db()->prepare("
        SELECT f.*, TRIM(CONCAT_WS(' ', recorder.first_name, recorder.middle_name, recorder.last_name)) AS recorded_by_name
        FROM ape_findings f
        LEFT JOIN people recorder ON recorder.id = f.recorded_by_person_id
        WHERE f.ape_id = ?
        ORDER BY f.recorded_at DESC, f.finding_id DESC
    ");
    $stmt->execute([$apeId]);
    return $stmt->fetchAll();
}

function ape_activities_for_record(int $apeId, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $stmt = auth_db()->prepare("
        SELECT l.*, l.action AS action_label,
               TRIM(CONCAT_WS(' ', actor.first_name, actor.middle_name, actor.last_name)) AS user_name
        FROM ape_activity_logs l
        LEFT JOIN people actor ON actor.id = l.performed_by_person_id
        WHERE l.ape_id = ?
        ORDER BY l.created_at DESC, l.activity_id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$apeId]);
    return $stmt->fetchAll();
}

function ape_activities_for_patient_record(int $apeId, int $patientId, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $stmt = auth_db()->prepare("
        SELECT l.*, l.action AS action_label,
               TRIM(CONCAT_WS(' ', actor.first_name, actor.middle_name, actor.last_name)) AS user_name
        FROM ape_activity_logs l
        INNER JOIN ape_records ar ON ar.ape_id = l.ape_id AND ar.patient_id = ?
        LEFT JOIN people actor ON actor.id = l.performed_by_person_id
        WHERE l.ape_id = ?
        ORDER BY l.created_at DESC, l.activity_id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$patientId, $apeId]);
    return $stmt->fetchAll();
}

function ape_default_requirements(): array
{
    return [
        'Lab Request Form',
        'UHS Consent Form',
        'UHS Medical Record',
        'UHS Dental Record',
        'Referral Form',
    ];
}

function ape_seed_default_requirements(int $apeId, string $status = 'Missing'): void
{
    $stmt = auth_db()->prepare('
        INSERT IGNORE INTO ape_requirements (ape_id, requirement_name, status)
        VALUES (?, ?, ?)
    ');
    foreach (ape_default_requirements() as $requirement) {
        $stmt->execute([$apeId, $requirement, $status]);
    }
}

function ape_log_activity(int $apeRecordId, ?int $personId, string $actionLabel, ?string $notes = null): void
{
    $stmt = auth_db()->prepare('INSERT INTO ape_activity_logs (ape_id, performed_by_person_id, action, notes) VALUES (?, ?, ?, ?)');
    $stmt->execute([$apeRecordId, $personId ?: null, $actionLabel, $notes]);
}

function ape_store_uploaded_file(array $file, string $prefix): array
{
    if (empty($file['name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a valid PDF or image to upload.');
    }
    if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('APE documents must not exceed 10 MB.');
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        throw new InvalidArgumentException('APE documents must be PDF, JPG, JPEG, or PNG files.');
    }

    $uploadDir = dirname(__DIR__, 2) . '/public/uploads/ape/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('The APE upload folder could not be created.');
    }

    $filename = preg_replace('/[^a-z0-9_-]+/i', '-', $prefix) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    if (!move_uploaded_file((string) $file['tmp_name'], $uploadDir . $filename)) {
        throw new RuntimeException('The APE document could not be saved.');
    }

    return [
        'original_filename' => basename((string) $file['name']),
        'file_path' => 'uploads/ape/' . $filename,
        'absolute_path' => $uploadDir . $filename,
    ];
}

function ape_workflow_summary(array $record): string
{
    if (($record['workflow_status'] ?? '') === 'Follow-up Required') {
        return 'Patient needs treatment follow-up and clearance before APE completion.';
    }

    return match ($record['workflow_status'] ?? '') {
        'Registered', 'Batch Assigned' => 'Patient vitals and BMI confirmation are required before clinical examination.',
        'Requirements Checked', 'Scheduled' => 'Clinical examination is complete and hard-copy documents are verified.',
        'Exam Done' => 'Clinical examination is recorded; hard-copy requirements still need correction or verification.',
        'Submitted' => 'Checked documents were submitted online for clinic record keeping.',
        'Reviewed' => 'Examination and digital archive are complete; final decision is pending.',
        'Cleared' => 'APE process is complete.',
        default => 'APE record is pending clinic action.',
    };
}
