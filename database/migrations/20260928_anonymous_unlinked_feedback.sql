USE Cliniq_db;

ALTER TABLE clinic_feedback
  MODIFY COLUMN visit_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS is_anonymous TINYINT(1) NOT NULL DEFAULT 1 AFTER consent_version;
