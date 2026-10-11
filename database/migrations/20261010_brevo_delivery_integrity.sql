ALTER TABLE email_queue
  MODIFY COLUMN delivery_state ENUM('smtp_accepted','delivered','bounced','blocked','rejected','unknown','deferred','complained') NULL,
  ADD COLUMN delivery_event_at DATETIME NULL AFTER delivery_state_updated_at;

CREATE UNIQUE INDEX uq_email_queue_provider_message_id ON email_queue (provider_message_id);
