USE Cliniq_db;

INSERT INTO departments (department_code, department_name)
VALUES
  ('CON', 'College of Nursing'),
  ('UHS', 'University Health Services')
ON DUPLICATE KEY UPDATE
  department_name = VALUES(department_name),
  is_active = 1;

INSERT INTO programs (department_id, program_code, program_name)
VALUES (
  (SELECT id FROM departments WHERE department_code = 'CON'),
  'BSN',
  'Bachelor of Science in Nursing'
)
ON DUPLICATE KEY UPDATE
  department_id = VALUES(department_id),
  program_name = VALUES(program_name),
  is_active = 1;

DELIMITER //
CREATE PROCEDURE add_cliniq_reference_foreign_keys()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'Cliniq_db' AND TABLE_NAME = 'students' AND COLUMN_NAME = 'program_id'
  ) THEN
    ALTER TABLE students
      ADD COLUMN program_id BIGINT UNSIGNED NULL AFTER person_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'Cliniq_db'
      AND TABLE_NAME = 'students'
      AND CONSTRAINT_NAME = 'fk_students_program'
  ) THEN
    ALTER TABLE students
      ADD CONSTRAINT fk_students_program
      FOREIGN KEY (program_id) REFERENCES programs(id);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'Cliniq_db' AND TABLE_NAME = 'faculty' AND COLUMN_NAME = 'department_id'
  ) THEN
    ALTER TABLE faculty
      ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER person_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'Cliniq_db'
      AND TABLE_NAME = 'faculty'
      AND CONSTRAINT_NAME = 'fk_faculty_department'
  ) THEN
    ALTER TABLE faculty
      ADD CONSTRAINT fk_faculty_department
      FOREIGN KEY (department_id) REFERENCES departments(id);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'Cliniq_db'
      AND TABLE_NAME = 'school_personnel'
      AND COLUMN_NAME = 'department_id'
  ) THEN
    ALTER TABLE school_personnel
      ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER person_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'Cliniq_db'
      AND TABLE_NAME = 'school_personnel'
      AND CONSTRAINT_NAME = 'fk_school_personnel_department'
  ) THEN
    ALTER TABLE school_personnel
      ADD CONSTRAINT fk_school_personnel_department
      FOREIGN KEY (department_id) REFERENCES departments(id);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'Cliniq_db' AND TABLE_NAME = 'clinic_staff' AND COLUMN_NAME = 'department_id'
  ) THEN
    ALTER TABLE clinic_staff
      ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER person_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'Cliniq_db'
      AND TABLE_NAME = 'clinic_staff'
      AND CONSTRAINT_NAME = 'fk_clinic_staff_department'
  ) THEN
    ALTER TABLE clinic_staff
      ADD CONSTRAINT fk_clinic_staff_department
      FOREIGN KEY (department_id) REFERENCES departments(id);
  END IF;
END//
DELIMITER ;

CALL add_cliniq_reference_foreign_keys();
DROP PROCEDURE add_cliniq_reference_foreign_keys;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BEED'),
    year_level = '1', section = 'A'
WHERE person_id = 17;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSN'),
    year_level = '1', section = 'A'
WHERE person_id = 18;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSIT'),
    year_level = '1', section = 'C'
WHERE person_id = 19;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSED'),
    year_level = '1', section = 'A'
WHERE person_id = 20;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSED'),
    year_level = '1', section = 'B'
WHERE person_id = 21;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSBA'),
    year_level = '1', section = 'D'
WHERE person_id = 22;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSBA'),
    year_level = '1', section = 'A'
WHERE person_id = 23;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSCS'),
    year_level = '1', section = 'B'
WHERE person_id = 24;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSA'),
    year_level = '1', section = 'A'
WHERE person_id = 25;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSCS'),
    year_level = '1', section = 'C'
WHERE person_id = 26;

UPDATE students
SET program_id = (SELECT id FROM programs WHERE program_code = 'BSIT'),
    year_level = '4', section = 'D'
WHERE person_id IN (27, 28, 29, 55);

UPDATE faculty f
JOIN people p ON p.id = f.person_id
SET f.department_id = (SELECT id FROM departments WHERE department_code = 'CCS')
WHERE p.id_number = 'FAC-0001';

UPDATE clinic_staff
SET department_id = (SELECT id FROM departments WHERE department_code = 'UHS');
