ALTER TABLE email_queue
  MODIFY recipient_email VARCHAR(160) NULL,
  MODIFY attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  MODIFY status ENUM('pending','processing','sent','failed','blocked','cancelled') NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS blocked_reason VARCHAR(500) NULL AFTER last_error,
  ADD COLUMN IF NOT EXISTS last_attempt_at DATETIME NULL AFTER blocked_reason,
  ADD COLUMN IF NOT EXISTS failed_at DATETIME NULL AFTER last_attempt_at,
  ADD COLUMN IF NOT EXISTS next_attempt_at DATETIME NULL AFTER failed_at,
  ADD COLUMN IF NOT EXISTS locked_at DATETIME NULL AFTER next_attempt_at,
  ADD COLUMN IF NOT EXISTS locked_by VARCHAR(100) NULL AFTER locked_at,
  ADD COLUMN IF NOT EXISTS max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER locked_by,
  ADD COLUMN IF NOT EXISTS retryable TINYINT(1) NOT NULL DEFAULT 1 AFTER max_attempts,
  ADD COLUMN IF NOT EXISTS sender_email VARCHAR(160) NULL AFTER retryable,
  ADD COLUMN IF NOT EXISTS sender_name VARCHAR(160) NULL AFTER sender_email,
  ADD COLUMN IF NOT EXISTS provider_message_id VARCHAR(255) NULL AFTER sender_name,
  ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(500) NULL AFTER cancelled_by_person_id,
  ADD COLUMN IF NOT EXISTS follow_up_required TINYINT(1) NOT NULL DEFAULT 0 AFTER cancellation_reason,
  ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL AFTER follow_up_required,
  ADD COLUMN IF NOT EXISTS resolved_by_person_id BIGINT UNSIGNED NULL AFTER resolved_at;

UPDATE email_queue
SET next_attempt_at = COALESCE(next_attempt_at, available_at)
WHERE status IN ('pending', 'failed') AND next_attempt_at IS NULL;

CREATE UNIQUE INDEX uq_email_queue_dedupe_key ON email_queue (dedupe_key);
CREATE INDEX idx_email_queue_claim ON email_queue (status, retryable, next_attempt_at, locked_at, id);
CREATE INDEX idx_email_queue_follow_up ON email_queue (follow_up_required, resolved_at, status, created_at);

ALTER TABLE email_queue
  ADD CONSTRAINT fk_email_queue_resolved_by
    FOREIGN KEY (resolved_by_person_id) REFERENCES people(id) ON DELETE SET NULL;
