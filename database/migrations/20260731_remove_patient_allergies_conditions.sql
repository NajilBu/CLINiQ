USE Cliniq_db;

ALTER TABLE patients
  DROP COLUMN IF EXISTS allergies;

ALTER TABLE patients
  DROP COLUMN IF EXISTS existing_conditions;
