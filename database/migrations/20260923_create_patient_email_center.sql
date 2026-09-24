ALTER TABLE email_queue
  ADD COLUMN IF NOT EXISTS patient_person_id BIGINT UNSIGNED NULL AFTER queue_key,
  ADD COLUMN IF NOT EXISTS event_type VARCHAR(80) NULL AFTER patient_person_id,
  ADD COLUMN IF NOT EXISTS source_type VARCHAR(50) NULL AFTER event_type,
  ADD COLUMN IF NOT EXISTS source_id BIGINT UNSIGNED NULL AFTER source_type,
  ADD COLUMN IF NOT EXISTS dedupe_key VARCHAR(190) NULL AFTER source_id,
  ADD COLUMN IF NOT EXISTS automation_key VARCHAR(80) NULL AFTER dedupe_key,
  ADD COLUMN IF NOT EXISTS origin ENUM('automatic','manual','system') NOT NULL DEFAULT 'automatic' AFTER automation_key,
  ADD COLUMN IF NOT EXISTS created_by_person_id BIGINT UNSIGNED NULL AFTER origin,
  ADD COLUMN IF NOT EXISTS resent_from_id BIGINT UNSIGNED NULL AFTER created_by_person_id,
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER sent_at,
  ADD COLUMN IF NOT EXISTS cancelled_by_person_id BIGINT UNSIGNED NULL AFTER cancelled_at;

ALTER TABLE email_queue
  MODIFY status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending';

CREATE INDEX idx_email_queue_patient_created ON email_queue (patient_person_id, created_at);
CREATE INDEX idx_email_queue_source ON email_queue (source_type, source_id, created_at);
CREATE INDEX idx_email_queue_event_status ON email_queue (event_type, status, available_at);
CREATE INDEX idx_email_queue_dedupe ON email_queue (dedupe_key);

ALTER TABLE email_queue
  ADD CONSTRAINT fk_email_queue_patient
    FOREIGN KEY (patient_person_id) REFERENCES patients(person_id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_email_queue_created_by
    FOREIGN KEY (created_by_person_id) REFERENCES people(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_email_queue_cancelled_by
    FOREIGN KEY (cancelled_by_person_id) REFERENCES people(id) ON DELETE SET NULL;
