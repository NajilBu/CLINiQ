CREATE DATABASE IF NOT EXISTS Cliniq_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE Cliniq_db;

-- Academic reference data used by student, faculty, and school personnel profiles.
CREATE TABLE departments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_code VARCHAR(20) NOT NULL UNIQUE,
  department_name VARCHAR(160) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_departments_name (department_name),
  INDEX idx_departments_active (is_active)
);

CREATE TABLE programs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id BIGINT UNSIGNED NOT NULL,
  program_code VARCHAR(20) NOT NULL UNIQUE,
  program_name VARCHAR(200) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id),
  INDEX idx_programs_department (department_id),
  INDEX idx_programs_name (program_name),
  INDEX idx_programs_active (is_active)
);

INSERT INTO departments (department_code, department_name) VALUES
  ('CCS', 'College of Computer Studies'),
  ('CBA', 'College of Business Administration'),
  ('COE', 'College of Education'),
  ('CAS', 'College of Arts and Sciences'),
  ('CON', 'College of Nursing'),
  ('UHS', 'University Health Services');

INSERT INTO programs (department_id, program_code, program_name) VALUES
  ((SELECT id FROM departments WHERE department_code = 'CCS'), 'BSIT', 'Bachelor of Science in Information Technology'),
  ((SELECT id FROM departments WHERE department_code = 'CCS'), 'BSCS', 'Bachelor of Science in Computer Science'),
  ((SELECT id FROM departments WHERE department_code = 'CBA'), 'BSBA', 'Bachelor of Science in Business Administration'),
  ((SELECT id FROM departments WHERE department_code = 'CBA'), 'BSA', 'Bachelor of Science in Accountancy'),
  ((SELECT id FROM departments WHERE department_code = 'COE'), 'BSED', 'Bachelor of Secondary Education'),
  ((SELECT id FROM departments WHERE department_code = 'COE'), 'BEED', 'Bachelor of Elementary Education'),
  ((SELECT id FROM departments WHERE department_code = 'CON'), 'BSN', 'Bachelor of Science in Nursing');

-- Shared identity. The profile tables below contain category-specific data.
CREATE TABLE people (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_number VARCHAR(50) NOT NULL UNIQUE,
  first_name VARCHAR(80) NOT NULL,
  middle_name VARCHAR(80) NULL,
  last_name VARCHAR(80) NOT NULL,
  birthdate DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_people_name (last_name, first_name)
);

-- One login per person. Imported people start inactive with no password.
CREATE TABLE accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id BIGINT UNSIGNED NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  temporary_password_hash VARCHAR(255) NULL,
  account_status ENUM('inactive', 'active', 'suspended')
    NOT NULL DEFAULT 'inactive',
  activated_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  INDEX idx_accounts_status (account_status)
);

CREATE TABLE students (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  program_id BIGINT UNSIGNED NULL,
  program VARCHAR(160) NULL,
  year_level VARCHAR(40) NULL,
  section VARCHAR(80) NULL,
  academic_year VARCHAR(20) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  FOREIGN KEY (program_id) REFERENCES programs(id),
  INDEX idx_students_program (program_id)
);

CREATE TABLE faculty (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  department_id BIGINT UNSIGNED NULL,
  department VARCHAR(160) NULL,
  employment_type VARCHAR(80) NULL,
  position_title VARCHAR(160) NULL,
  office VARCHAR(160) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id),
  INDEX idx_faculty_department (department_id)
);

CREATE TABLE school_personnel (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  department_id BIGINT UNSIGNED NULL,
  department_or_office VARCHAR(160) NULL,
  employment_type VARCHAR(80) NULL,
  position_title VARCHAR(160) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id),
  INDEX idx_school_personnel_department (department_id)
);

CREATE TABLE clinic_staff (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  department_id BIGINT UNSIGNED NULL,
  staff_role ENUM('admin', 'doctor', 'nurse', 'staff', 'it_expert')
    NOT NULL DEFAULT 'staff',
  department VARCHAR(160) NULL DEFAULT 'University Health Services',
  position_title VARCHAR(160) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id),
  INDEX idx_clinic_staff_department (department_id),
  INDEX idx_clinic_staff_role (staff_role)
);

-- A patient profile may belong to a student, faculty member, school personnel,
-- clinic employee, or another authorized person. It contains health information.
CREATE TABLE patients (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  blood_type VARCHAR(10) NULL,
  allergies TEXT NULL,
  existing_conditions TEXT NULL,
  emergency_instructions TEXT NULL,
  guardian_or_contact_name VARCHAR(160) NULL,
  guardian_or_contact_number VARCHAR(50) NULL,
  emergency_token CHAR(64) NULL UNIQUE,
  token_enabled TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
);

DELIMITER //
CREATE TRIGGER trg_people_create_inactive_account
AFTER INSERT ON people
FOR EACH ROW
BEGIN
  INSERT INTO accounts (person_id, account_status)
  VALUES (NEW.id, 'inactive');
END//
DELIMITER ;
