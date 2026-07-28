USE cliniq;

-- Preserve every existing identifier while making the patient identity generic.
-- Run this once on an existing CLINiQ database. New installations already use
-- id_number through schema.sql and do not need this migration.
ALTER TABLE patients
  CHANGE COLUMN student_number id_number VARCHAR(50) NOT NULL;
