ALTER TABLE email_queue
  MODIFY COLUMN delivery_state ENUM('smtp_accepted','delivered','bounced','rejected','unknown','deferred','complained') NULL;
