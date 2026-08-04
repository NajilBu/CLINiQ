USE Cliniq_db;

ALTER TABLE people
  ADD COLUMN sex ENUM('Male', 'Female', 'Other') NULL AFTER birthdate;
