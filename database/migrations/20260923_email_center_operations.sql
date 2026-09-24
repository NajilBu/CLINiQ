ALTER TABLE email_queue
  ADD COLUMN IF NOT EXISTS manual_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attempts,
  ADD COLUMN IF NOT EXISTS last_manual_retry_at DATETIME NULL AFTER manual_attempts,
  ADD COLUMN IF NOT EXISTS retry_reason VARCHAR(500) NULL AFTER last_error,
  ADD COLUMN IF NOT EXISTS follow_up_note TEXT NULL AFTER follow_up_required,
  ADD COLUMN IF NOT EXISTS follow_up_assigned_to_person_id BIGINT UNSIGNED NULL AFTER follow_up_note,
  ADD COLUMN IF NOT EXISTS follow_up_due_at DATETIME NULL AFTER follow_up_assigned_to_person_id,
  ADD COLUMN IF NOT EXISTS edited_at DATETIME NULL AFTER follow_up_due_at,
  ADD COLUMN IF NOT EXISTS edited_by_person_id BIGINT UNSIGNED NULL AFTER edited_at,
  ADD COLUMN IF NOT EXISTS delivery_state ENUM('smtp_accepted','delivered','bounced','rejected','unknown') NULL AFTER provider_message_id,
  ADD COLUMN IF NOT EXISTS delivery_state_updated_at DATETIME NULL AFTER delivery_state;

UPDATE email_queue
SET delivery_state = CASE WHEN status = 'sent' THEN 'smtp_accepted' ELSE delivery_state END,
    delivery_state_updated_at = CASE WHEN status = 'sent' THEN COALESCE(sent_at, created_at) ELSE delivery_state_updated_at END
WHERE status = 'sent' AND delivery_state IS NULL;

CREATE INDEX idx_email_queue_blocked ON email_queue (status, blocked_reason, created_at);
CREATE INDEX idx_email_queue_follow_up_owner ON email_queue (follow_up_assigned_to_person_id, follow_up_due_at, follow_up_required);
CREATE INDEX idx_email_queue_delivery_state ON email_queue (delivery_state, delivery_state_updated_at);

ALTER TABLE email_queue
  ADD CONSTRAINT fk_email_queue_follow_up_assignee
    FOREIGN KEY (follow_up_assigned_to_person_id) REFERENCES people(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_email_queue_edited_by
    FOREIGN KEY (edited_by_person_id) REFERENCES people(id) ON DELETE SET NULL;
