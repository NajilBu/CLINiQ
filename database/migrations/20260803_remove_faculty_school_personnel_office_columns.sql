USE Cliniq_db;

-- Faculty and school-personnel affiliations are represented by department_id.
ALTER TABLE faculty
  DROP COLUMN IF EXISTS office;

ALTER TABLE school_personnel
  DROP COLUMN IF EXISTS department_or_office;
