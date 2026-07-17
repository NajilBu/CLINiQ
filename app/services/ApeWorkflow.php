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

    $db = db();
    $columns = [];
    $stmt = $db->query("SHOW COLUMNS FROM ape_records");
    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['Field']] = true;
    }

    $addColumn = function (string $name, string $definition) use ($db, &$columns): void {
        if (!isset($columns[$name])) {
            $db->exec("ALTER TABLE ape_records ADD COLUMN {$name} {$definition}");
            $columns[$name] = true;
        }
    };

    $addColumn('document_type', "VARCHAR(80) NOT NULL DEFAULT 'APE Form' AFTER exam_date");
    $addColumn('requirement_status', "ENUM('Not Checked','Pre-Verified','Needs Correction') NOT NULL DEFAULT 'Not Checked' AFTER document_type");
    $addColumn('workflow_status', "ENUM('Registered','Batch Assigned','Requirements Checked','Submitted','Reviewed','Scheduled','Exam Done','Follow-up Required','Cleared') NOT NULL DEFAULT 'Submitted' AFTER requirement_status");
    $addColumn('appointment_datetime', "DATETIME NULL AFTER verified_by");
    $addColumn('appointment_location', "VARCHAR(160) NULL AFTER appointment_datetime");
    $addColumn('clearance_status', "ENUM('Pending','For Follow-up','Submitted','Cleared') NOT NULL DEFAULT 'Pending' AFTER appointment_location");
    $addColumn('clinical_remarks', "TEXT NULL AFTER clearance_status");
    $addColumn('student_visible_note', "TEXT NULL AFTER clinical_remarks");
    $addColumn('follow_up_required', "TINYINT(1) NOT NULL DEFAULT 0 AFTER student_visible_note");
    $addColumn('missing_items', "TEXT NULL AFTER follow_up_required");
    $addColumn('result_status', "ENUM('Pending','Completed','With Finding','Fit to Proceed') NOT NULL DEFAULT 'Pending' AFTER missing_items");
    $addColumn('result_notes', "TEXT NULL AFTER result_status");
    $addColumn('clearance_document_path', "VARCHAR(255) NULL AFTER result_notes");
    $addColumn('updated_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

    $db->exec("
        CREATE TABLE IF NOT EXISTS ape_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ape_record_id INT NOT NULL,
            user_id INT NULL,
            action_label VARCHAR(160) NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ape_record_id) REFERENCES ape_records(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )
    ");

    $ready = true;
}

function ape_log_activity(int $apeRecordId, ?int $userId, string $actionLabel, ?string $notes = null): void
{
    $stmt = db()->prepare('INSERT INTO ape_activity_logs (ape_record_id, user_id, action_label, notes) VALUES (?, ?, ?, ?)');
    $stmt->execute([$apeRecordId, $userId, $actionLabel, $notes]);
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
