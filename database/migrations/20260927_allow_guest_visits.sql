USE Cliniq_db;

ALTER TABLE visits
  MODIFY COLUMN patient_person_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS guest_name VARCHAR(255) NULL AFTER patient_person_id;
