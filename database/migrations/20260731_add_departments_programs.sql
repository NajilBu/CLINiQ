USE Cliniq_db;

CREATE TABLE IF NOT EXISTS departments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_code VARCHAR(20) NOT NULL UNIQUE,
  department_name VARCHAR(160) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_departments_name (department_name),
  INDEX idx_departments_active (is_active)
);

CREATE TABLE IF NOT EXISTS programs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id BIGINT UNSIGNED NOT NULL,
  program_code VARCHAR(20) NOT NULL UNIQUE,
  program_name VARCHAR(200) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_programs_department
    FOREIGN KEY (department_id) REFERENCES departments(id),
  INDEX idx_programs_department (department_id),
  INDEX idx_programs_name (program_name),
  INDEX idx_programs_active (is_active)
);

INSERT INTO departments (department_code, department_name)
VALUES
  ('CCS', 'College of Computer Studies'),
  ('CBA', 'College of Business Administration'),
  ('COE', 'College of Education'),
  ('CAS', 'College of Arts and Sciences')
ON DUPLICATE KEY UPDATE
  department_name = VALUES(department_name),
  is_active = 1;

INSERT INTO programs (department_id, program_code, program_name)
VALUES
  ((SELECT id FROM departments WHERE department_code = 'CCS'), 'BSIT', 'Bachelor of Science in Information Technology'),
  ((SELECT id FROM departments WHERE department_code = 'CCS'), 'BSCS', 'Bachelor of Science in Computer Science'),
  ((SELECT id FROM departments WHERE department_code = 'CBA'), 'BSBA', 'Bachelor of Science in Business Administration'),
  ((SELECT id FROM departments WHERE department_code = 'CBA'), 'BSA', 'Bachelor of Science in Accountancy'),
  ((SELECT id FROM departments WHERE department_code = 'COE'), 'BSED', 'Bachelor of Secondary Education'),
  ((SELECT id FROM departments WHERE department_code = 'COE'), 'BEED', 'Bachelor of Elementary Education')
ON DUPLICATE KEY UPDATE
  department_id = VALUES(department_id),
  program_name = VALUES(program_name),
  is_active = 1;
