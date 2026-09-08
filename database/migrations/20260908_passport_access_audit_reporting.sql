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
  CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_person_id) REFERENCES people(id) ON DELETE SET NULL,
  INDEX idx_audit_logs_created (created_at),
  INDEX idx_audit_logs_module_action (module, action),
  INDEX idx_audit_logs_actor (actor_person_id, created_at),
  INDEX idx_audit_logs_target (target_type, target_id, created_at)
);

ALTER TABLE passport_access_logs ADD COLUMN viewer_person_id BIGINT UNSIGNED NULL AFTER patient_id;
ALTER TABLE passport_access_logs ADD COLUMN audit_log_id BIGINT UNSIGNED NULL AFTER viewer_person_id;
ALTER TABLE incident_reports ADD COLUMN reporter_risk_rating VARCHAR(20) NULL AFTER notes;
ALTER TABLE nurse_alerts ADD COLUMN reporter_risk_rating VARCHAR(20) NULL AFTER report_answers;
ALTER TABLE nurse_alerts MODIFY COLUMN risk_level VARCHAR(40) NOT NULL DEFAULT 'Not assessed';
