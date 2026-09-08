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
