USE Cliniq_db;

ALTER TABLE patients
  ADD COLUMN IF NOT EXISTS guardian_relationship VARCHAR(80) NULL AFTER guardian_or_contact_number,
  ADD COLUMN IF NOT EXISTS secondary_contact_number VARCHAR(32) NULL AFTER guardian_relationship;
