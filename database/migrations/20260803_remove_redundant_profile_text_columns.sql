USE Cliniq_db;

-- Program and department names are read through their foreign-key tables.
-- Keeping a second text copy on each profile can create conflicting values.
ALTER TABLE students
  DROP COLUMN IF EXISTS program;

ALTER TABLE faculty
  DROP COLUMN IF EXISTS department;

ALTER TABLE clinic_staff
  DROP COLUMN IF EXISTS department;
