<?php

require_once __DIR__ . '/../config/database.php';

function ensure_audit_log_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    auth_db()->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_person_id BIGINT UNSIGNED NULL,
            actor_type VARCHAR(30) NOT NULL DEFAULT 'system',
            module VARCHAR(60) NOT NULL,
            action VARCHAR(100) NOT NULL,
            target_type VARCHAR(60) NULL,
            target_id BIGINT UNSIGNED NULL,
            outcome VARCHAR(30) NOT NULL DEFAULT 'success',
            metadata JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_cliniq_audit_logs_actor
                FOREIGN KEY (actor_person_id) REFERENCES people(id) ON DELETE SET NULL,
            INDEX idx_audit_logs_created (created_at),
            INDEX idx_audit_logs_module_action (module, action),
            INDEX idx_audit_logs_actor (actor_person_id, created_at),
            INDEX idx_audit_logs_target (target_type, target_id, created_at)
        )
    ");
    $ready = true;
}

function audit_log_event(
    string $module,
    string $action,
    ?int $actorPersonId = null,
    string $actorType = 'system',
    ?string $targetType = null,
    ?int $targetId = null,
    array $metadata = [],
    string $outcome = 'success'
): int {
    try {
        ensure_audit_log_schema();
        $stmt = auth_db()->prepare("
            INSERT INTO audit_logs
                (actor_person_id, actor_type, module, action, target_type, target_id, outcome, metadata, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $actorPersonId ?: null,
            $actorType,
            $module,
            $action,
            $targetType,
            $targetId ?: null,
            $outcome,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
        return (int) auth_db()->lastInsertId();
    } catch (Throwable $exception) {
        // Audit failures must never prevent the underlying clinic workflow.
        return 0;
    }
}

function audit_log_module_label(string $module): string
{
    return [
        'accounts' => 'Staff accounts',
        'ape' => 'Annual physical examination',
        'auth' => 'Sign-in activity',
        'incident' => 'Emergency incidents',
        'inventory' => 'Clinic inventory',
        'passport' => 'Emergency health passport',
        'profile' => 'Profile pictures',
        'settings' => 'System settings',
        'visits' => 'Clinic visits',
    ][$module] ?? ucwords(str_replace(['_', '-'], ' ', $module));
}

function audit_log_action_label(string $action): string
{
    $labels = [
        'alert_resolved' => 'Resolved an emergency alert',
        'alert_status_updated' => 'Updated an emergency alert',
        'ape_required_documents_updated' => 'Updated required APE documents',
        'backup_completed' => 'Completed a system backup',
        'backup_failed' => 'System backup failed',
        'backup_skipped' => 'Skipped a duplicate system backup',
        'backup_verified' => 'Verified the latest system backup',
        'clinic_profile_updated' => 'Updated the clinic profile',
        'incident_report_submitted' => 'Submitted an emergency incident report',
        'inventory_transaction_recorded' => 'Recorded an inventory transaction',
        'passport_profile_updated' => 'Updated emergency passport information',
        'passport_viewed' => 'Viewed an emergency health passport',
        'patient_profile_photo_updated' => 'Updated their patient profile picture',
        'risk_settings_reset' => 'Restored the default incident-risk settings',
        'risk_settings_updated' => 'Updated incident-risk settings',
        'staff_login_failed' => 'Staff sign-in attempt failed',
        'staff_login_success' => 'Signed in to the clinic system',
        'staff_logout' => 'Signed out of the clinic system',
        'staff_password_changed' => 'Changed their clinic-system password',
        'staff_password_reset' => 'Reset a staff password',
        'staff_profile_created' => 'Created a staff profile',
        'staff_profile_updated' => 'Updated a staff profile',
        'staff_profile_photo_updated' => 'Updated their clinic profile picture',
        'student_login_failed' => 'Patient sign-in attempt failed',
        'student_login_success' => 'Signed in to the patient portal',
        'student_logout' => 'Signed out of the patient portal',
        'theme_updated' => 'Updated the system appearance',
        'viewer_authenticated' => 'Verified identity for passport access',
        'viewer_authentication_failed' => 'Passport-access sign-in failed',
        'visit_created' => 'Created a clinic visit',
    ];

    return $labels[$action] ?? ucfirst(str_replace(['_', '-'], ' ', $action));
}

function audit_log_target_label(array $log): string
{
    $targetName = trim((string) ($log['target_name'] ?? ''));
    $targetIdNumber = trim((string) ($log['target_id_number'] ?? ''));
    $targetType = (string) ($log['target_type'] ?? '');
    $targetId = (int) ($log['target_id'] ?? 0);

    if ($targetName !== '') {
        $person = $targetName . ($targetIdNumber !== '' ? " ({$targetIdNumber})" : '');
        return match ($targetType) {
            'ape_record' => "APE record for {$person}",
            'nurse_alert' => "Emergency alert for {$person}",
            'visit' => "Clinic visit for {$person}",
            default => $person,
        };
    }

    if (($log['target_item_name'] ?? '') !== '') {
        return 'Inventory item: ' . trim((string) $log['target_item_name']);
    }

    return match ($targetType) {
        'account' => $targetId > 0 ? "Account {$targetId}" : 'User account',
        'ape_cycle' => $targetId > 0 ? "APE cycle {$targetId}" : 'APE cycle',
        'ape_record' => $targetId > 0 ? "APE record {$targetId}" : 'APE record',
        'backup' => 'Backup system',
        'inventory_transaction' => $targetId > 0 ? "Inventory transaction {$targetId}" : 'Inventory transaction',
        'nurse_alert' => $targetId > 0 ? "Emergency alert {$targetId}" : 'Emergency alert',
        'patient' => $targetId > 0 ? "Patient record {$targetId}" : 'Patient record',
        'person' => $targetId > 0 ? "Person record {$targetId}" : 'Person record',
        'settings' => 'System settings',
        'visit' => $targetId > 0 ? "Clinic visit {$targetId}" : 'Clinic visit',
        default => $targetType !== '' ? ucwords(str_replace(['_', '-'], ' ', $targetType)) : 'Not applicable',
    };
}

function audit_log_metadata_summary(?string $metadata): string
{
    if ($metadata === null || trim($metadata) === '') {
        return 'No additional information';
    }

    $data = json_decode($metadata, true);
    if (!is_array($data)) {
        return 'Additional technical information recorded';
    }

    $details = [];
    if (isset($data['route']) && str_contains((string) $data['route'], 'emergency.php')) {
        $details[] = 'Opened through QR/NFC';
    }
    if (array_key_exists('authenticated', $data)) {
        $details[] = $data['authenticated'] ? 'Viewer signed in' : 'Viewer was not signed in';
    }
    if (!empty($data['status'])) {
        $details[] = 'New status: ' . $data['status'];
    }
    if (!empty($data['location'])) {
        $details[] = 'Location: ' . $data['location'];
    }
    if (!empty($data['risk_rating'])) {
        $details[] = 'Reported urgency: ' . $data['risk_rating'];
    }
    if (!empty($data['source'])) {
        $details[] = 'Source: ' . $data['source'];
    }
    if (!empty($data['type'])) {
        $details[] = 'Type: ' . $data['type'];
    }
    if (isset($data['quantity_change'])) {
        $change = (int) $data['quantity_change'];
        $details[] = 'Quantity change: ' . ($change > 0 ? '+' : '') . $change;
    }
    if (!empty($data['notes'])) {
        $details[] = 'Notes: ' . $data['notes'];
    }
    if (!empty($data['fields']) && is_array($data['fields'])) {
        $fieldLabels = array_map(static fn(string $field): string => ucwords(str_replace('_', ' ', $field)), $data['fields']);
        $details[] = 'Updated: ' . implode(', ', $fieldLabels);
    }
    if (!empty($data['documents']) && is_array($data['documents'])) {
        $details[] = count($data['documents']) . ' required document(s) configured';
    }
    if (!empty($data['message'])) {
        $details[] = (string) $data['message'];
    }

    return $details ? implode(' • ', $details) : 'Additional technical information recorded';
}
