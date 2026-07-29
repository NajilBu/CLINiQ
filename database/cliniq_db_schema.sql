CREATE DATABASE IF NOT EXISTS Cliniq_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE Cliniq_db;

-- Shared identity. The four profile tables below contain category-specific data.
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
  program VARCHAR(160) NULL,
  year_level VARCHAR(40) NULL,
  section VARCHAR(80) NULL,
  academic_year VARCHAR(20) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
);

CREATE TABLE faculty (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  department VARCHAR(160) NULL,
  employment_type VARCHAR(80) NULL,
  position_title VARCHAR(160) NULL,
  office VARCHAR(160) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
);

CREATE TABLE clinic_staff (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  staff_role ENUM('admin', 'doctor', 'nurse', 'staff', 'it_expert')
    NOT NULL DEFAULT 'staff',
  department VARCHAR(160) NULL DEFAULT 'University Health Services',
  position_title VARCHAR(160) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  INDEX idx_clinic_staff_role (staff_role)
);

-- A patient profile may belong to a student, faculty member, clinic employee,
-- or another eligible person. It contains health-related profile information.
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
