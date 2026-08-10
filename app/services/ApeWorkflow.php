<?php

require_once __DIR__ . '/SystemSettings.php';

function ape_workflow_steps(): array
{
    return [
        'Document Review',
        'Digital Submission',
        'Follow-up',
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
        'document_review' => [
            'title' => 'Document Review',
            'short_title' => 'Document Review',
            'description' => 'Clinic staff checks hard-copy medical documents, records findings, and lists follow-up requirements.',
            'icon' => 'fact_check',
        ],
        'digital_submission' => [
            'title' => 'Digital Submission',
            'short_title' => 'Digital Keeping',
            'description' => 'Students submit checked documents online; clinic staff reviews and archives them for paperless record keeping.',
            'icon' => 'upload_file',
        ],
        'follow_up' => [
            'title' => 'Follow-up Students',
            'short_title' => 'Follow-up',
            'description' => 'Students with findings who must submit treatment proof, clearance, or other required follow-up documents.',
            'icon' => 'medical_information',
        ],
        'completed' => [
            'title' => 'Completed APE Records',
            'short_title' => 'Completed',
            'description' => 'Students whose checked documents are archived and whose follow-up requirements are cleared.',
            'icon' => 'task_alt',
        ],
    ];
}

function ape_record_queue(array $record): string
{
    if (($record['workflow_status'] ?? '') === 'Cleared' || ($record['clearance_status'] ?? '') === 'Cleared') {
        return 'completed';
    }

    if (($record['requirement_status'] ?? '') !== 'Pre-Verified') {
        return 'document_review';
    }

    if (empty($record['document_path']) || in_array(($record['verification_status'] ?? 'Pending'), ['Pending', 'Needs Correction'], true)) {
        return 'digital_submission';
    }

    if ((int)($record['follow_up_required'] ?? 0) === 1 || in_array(($record['clearance_status'] ?? ''), ['For Follow-up', 'Submitted'], true)) {
        return 'follow_up';
    }

    return 'completed';
}

function ape_next_action(array $record): array
{
    return match (ape_record_queue($record)) {
        'document_review' => ['label' => 'Review Hard Copy', 'icon' => 'fact_check'],
        'digital_submission' => empty($record['document_path'])
            ? ['label' => 'Wait for Student Upload', 'icon' => 'upload_file']
            : ['label' => 'Archive Submission', 'icon' => 'inventory_2'],
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
        'document_review' => 0,
        'digital_submission' => 1,
        'follow_up' => 2,
        'completed' => 3,
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

function ape_priority_badge(array $record): array
{
    $queueKey = ape_record_queue($record);
    if ($queueKey === 'completed') {
        return ['label' => 'Done', 'class' => 'badge-completed'];
    }

    $days = ape_waiting_days($record);
    if ($queueKey === 'follow_up') {
        return ['label' => $days >= 3 ? 'Urgent' : 'Clinical', 'class' => 'badge-high'];
    }

    if ($days >= 7) {
        return ['label' => 'Overdue', 'class' => 'badge-critical'];
    }

    if ($days >= 3) {
        return ['label' => 'Waiting', 'class' => 'badge-pending'];
    }

    return ['label' => 'Ready', 'class' => 'badge-in-progress'];
}

function ape_waiting_label(array $record): string
{
    $queueKey = ape_record_queue($record);
    $days = ape_waiting_days($record);
    if ($days === 0) {
        return 'Updated today';
    }

    return $days . ' day' . ($days === 1 ? '' : 's') . ' waiting';
}

function ape_next_action_card(array $record): array
{
    return match (ape_record_queue($record)) {
        'document_review' => [
            'title' => 'Check the hard-copy medical documents',
            'body' => 'Record findings, health concerns, missing items, and any required treatment or clearance before the student uploads the checked documents online.',
        ],
        'digital_submission' => empty($record['document_path'])
            ? [
                'title' => 'Wait for the student to submit checked documents online',
                'body' => 'The student uploads the checked requirements for clinic record keeping. Expected files include Lab Request Form, UHS Consent Form, UHS Medical Record, UHS Dental Record, and Referral Form.',
            ]
            : [
                'title' => 'Archive the digital submission',
                'body' => 'Confirm that the online files match the checked hard copies, then archive them. If there is no follow-up requirement, this completes the APE record.',
            ],
        'follow_up' => [
            'title' => 'Track the required follow-up',
            'body' => 'Keep the record open until the student submits the required treatment proof, medical clearance, or other follow-up document.',
        ],
        'completed' => [
            'title' => 'APE process completed',
            'body' => 'This record is cleared and stored as part of the student clinic file.',
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
    if (($record['requirement_status'] ?? '') === 'Not Checked') {
        return 'Hard-copy documents not reviewed';
    }
    if (($record['requirement_status'] ?? '') === 'Needs Correction') {
        return 'Hard-copy requirements need attention';
    }
    if (empty($record['document_path'])) {
        return 'Waiting for student online submission';
    }
    if (($record['verification_status'] ?? '') === 'Pending') {
        return 'Online documents waiting for archive review';
    }
    if (($record['verification_status'] ?? '') === 'Needs Correction') {
        return 'Online submission correction needed';
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
        'Requirements Checked', 'Submitted', 'Reviewed' => 1,
        'Follow-up Required' => 2,
        'Cleared' => 3,
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
    foreach (['ape_records', 'ape_requirements', 'ape_documents', 'ape_findings', 'ape_activity_logs'] as $table) {
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
            (SELECT COUNT(*) FROM ape_documents d WHERE d.ape_id = ar.ape_id) AS document_count
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
        return 'Student needs treatment follow-up and clearance before APE completion.';
    }

    return match ($record['workflow_status'] ?? '') {
        'Registered', 'Batch Assigned' => 'Hard-copy medical documents are waiting for clinic review.',
        'Requirements Checked' => 'Hard-copy medical documents were checked by the clinic.',
        'Submitted' => 'Checked documents were submitted online for clinic record keeping.',
        'Reviewed' => 'Digital documents were archived by the clinic.',
        'Cleared' => 'APE process is complete.',
        default => 'APE record is pending clinic action.',
    };
}
