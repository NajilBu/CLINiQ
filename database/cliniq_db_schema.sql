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
  sex ENUM('Male', 'Female', 'Other') NULL,
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
  year_level VARCHAR(40) NULL,
  section VARCHAR(80) NULL,
  academic_year VARCHAR(20) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  FOREIGN KEY (program_id) REFERENCES programs(id),
  INDEX idx_students_program (program_id)
);

CREATE TABLE school_employees (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  department_id BIGINT UNSIGNED NULL,
  role_classification ENUM('Faculty', 'School Personnel') NOT NULL,
  employment_type VARCHAR(80) NULL,
  position_title VARCHAR(160) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id),
  INDEX idx_school_employees_department (department_id),
  INDEX idx_school_employees_classification (role_classification)
);

CREATE TABLE clinic_staff (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  department_id BIGINT UNSIGNED NULL,
  staff_role ENUM('admin', 'doctor', 'nurse', 'staff', 'it_expert')
    NOT NULL DEFAULT 'staff',
  position_title VARCHAR(160) NULL,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id),
  INDEX idx_clinic_staff_department (department_id),
  INDEX idx_clinic_staff_role (staff_role)
);

-- A patient profile may belong to a student, school employee,
-- clinic employee, or another authorized person. It contains health information.
CREATE TABLE patients (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  blood_type VARCHAR(10) NULL,
  emergency_instructions TEXT NULL,
  guardian_or_contact_name VARCHAR(160) NULL,
  guardian_or_contact_number VARCHAR(50) NULL,
  emergency_token CHAR(64) NULL UNIQUE,
  token_enabled TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
);

CREATE TABLE visits (
  visit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_person_id BIGINT UNSIGNED NOT NULL,
  visit_datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  addressed_at DATETIME NULL,
  completed_at DATETIME NULL,
  chief_complaint VARCHAR(255) NOT NULL,
  status ENUM('Unaddressed', 'Active', 'Completed', 'Cancelled')
    NOT NULL DEFAULT 'Unaddressed',
  visit_purpose VARCHAR(80) NULL,
  visit_source VARCHAR(80) NOT NULL DEFAULT 'Staff Recorded',
  action_taken TEXT NULL,
  recorded_by_person_id BIGINT UNSIGNED NULL,
  attended_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_visits_patient
    FOREIGN KEY (patient_person_id) REFERENCES patients(person_id),
  CONSTRAINT fk_visits_recorded_by
    FOREIGN KEY (recorded_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_visits_attended_by
    FOREIGN KEY (attended_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_visits_patient_datetime (patient_person_id, visit_datetime),
  INDEX idx_visits_status (status),
  INDEX idx_visits_recorded_by (recorded_by_person_id),
  INDEX idx_visits_attended_by (attended_by_person_id)
);

CREATE TABLE visit_entries (
  entry_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id BIGINT UNSIGNED NOT NULL,
  diagnosis TEXT NULL,
  symptoms TEXT NULL,
  treatment TEXT NULL,
  referral TEXT NULL,
  remarks TEXT NULL,
  amendment_reason TEXT NULL,
  addressed_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_visit_entries_visit
    FOREIGN KEY (visit_id) REFERENCES visits(visit_id) ON DELETE CASCADE,
  CONSTRAINT fk_visit_entries_addressed_by
    FOREIGN KEY (addressed_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_visit_entries_visit_created (visit_id, created_at),
  INDEX idx_visit_entries_addressed_by (addressed_by_person_id)
);

CREATE TABLE vital_signs (
  vital_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id BIGINT UNSIGNED NOT NULL,
  entry_id BIGINT UNSIGNED NULL,
  temperature DECIMAL(4,1) NULL,
  blood_pressure VARCHAR(20) NULL,
  pulse_rate SMALLINT UNSIGNED NULL,
  measured_by_person_id BIGINT UNSIGNED NULL,
  measured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vital_signs_visit
    FOREIGN KEY (visit_id) REFERENCES visits(visit_id) ON DELETE CASCADE,
  CONSTRAINT fk_vital_signs_entry
    FOREIGN KEY (entry_id) REFERENCES visit_entries(entry_id) ON DELETE SET NULL,
  CONSTRAINT fk_vital_signs_measured_by
    FOREIGN KEY (measured_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_vital_signs_visit_measured (visit_id, measured_at),
  INDEX idx_vital_signs_entry (entry_id),
  INDEX idx_vital_signs_measured_by (measured_by_person_id)
);

CREATE TABLE inventory_items (
  item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_code VARCHAR(40) NOT NULL,
  item_name VARCHAR(160) NOT NULL,
  item_type ENUM('Medicine', 'Equipment') NOT NULL,
  description TEXT NULL,
  unit VARCHAR(40) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 0,
  reorder_level INT UNSIGNED NOT NULL DEFAULT 0,
  expiration_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_inventory_items_code UNIQUE (item_code),
  INDEX idx_inventory_items_type_active (item_type, is_active),
  INDEX idx_inventory_items_stock (quantity, reorder_level),
  INDEX idx_inventory_items_expiration (expiration_date)
);

CREATE TABLE medicine_dispensings (
  dispensing_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  remarks TEXT NULL,
  dispensed_by_person_id BIGINT UNSIGNED NULL,
  dispensed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_medicine_dispensings_quantity CHECK (quantity > 0),
  CONSTRAINT fk_medicine_dispensings_entry
    FOREIGN KEY (entry_id) REFERENCES visit_entries(entry_id) ON DELETE CASCADE,
  CONSTRAINT fk_medicine_dispensings_item
    FOREIGN KEY (item_id) REFERENCES inventory_items(item_id),
  CONSTRAINT fk_medicine_dispensings_staff
    FOREIGN KEY (dispensed_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_medicine_dispensings_entry (entry_id),
  INDEX idx_medicine_dispensings_item_date (item_id, dispensed_at),
  INDEX idx_medicine_dispensings_staff (dispensed_by_person_id)
);

CREATE TABLE equipment_loans (
  loan_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id BIGINT UNSIGNED NOT NULL,
  borrower_person_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL,
  borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at DATETIME NULL,
  returned_at DATETIME NULL,
  status ENUM('Borrowed', 'Returned', 'Overdue', 'Cancelled')
    NOT NULL DEFAULT 'Borrowed',
  released_by_person_id BIGINT UNSIGNED NULL,
  received_by_person_id BIGINT UNSIGNED NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_equipment_loans_quantity CHECK (quantity > 0),
  CONSTRAINT fk_equipment_loans_item
    FOREIGN KEY (item_id) REFERENCES inventory_items(item_id),
  CONSTRAINT fk_equipment_loans_borrower
    FOREIGN KEY (borrower_person_id) REFERENCES patients(person_id),
  CONSTRAINT fk_equipment_loans_visit
    FOREIGN KEY (visit_id) REFERENCES visits(visit_id) ON DELETE SET NULL,
  CONSTRAINT fk_equipment_loans_released_by
    FOREIGN KEY (released_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_equipment_loans_received_by
    FOREIGN KEY (received_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_equipment_loans_item_status (item_id, status),
  INDEX idx_equipment_loans_borrower_status (borrower_person_id, status),
  INDEX idx_equipment_loans_visit (visit_id),
  INDEX idx_equipment_loans_due (status, due_at),
  INDEX idx_equipment_loans_released_by (released_by_person_id),
  INDEX idx_equipment_loans_received_by (received_by_person_id)
);

CREATE TABLE inventory_transactions (
  transaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id BIGINT UNSIGNED NOT NULL,
  transaction_type ENUM(
    'Stock In', 'Dispensed', 'Loaned', 'Returned',
    'Adjustment', 'Expired', 'Damaged'
  ) NOT NULL,
  quantity_change INT NOT NULL,
  balance_after INT UNSIGNED NOT NULL,
  dispensing_id BIGINT UNSIGNED NULL,
  loan_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  performed_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_inventory_transactions_change CHECK (quantity_change <> 0),
  CONSTRAINT fk_inventory_transactions_item
    FOREIGN KEY (item_id) REFERENCES inventory_items(item_id),
  CONSTRAINT fk_inventory_transactions_dispensing
    FOREIGN KEY (dispensing_id) REFERENCES medicine_dispensings(dispensing_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_inventory_transactions_loan
    FOREIGN KEY (loan_id) REFERENCES equipment_loans(loan_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_inventory_transactions_staff
    FOREIGN KEY (performed_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_inventory_transactions_item_created (item_id, created_at),
  INDEX idx_inventory_transactions_type_created (transaction_type, created_at),
  INDEX idx_inventory_transactions_dispensing (dispensing_id),
  INDEX idx_inventory_transactions_loan (loan_id),
  INDEX idx_inventory_transactions_staff (performed_by_person_id)
);

CREATE TABLE referrals (
  referral_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_person_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NULL,
  referred_to VARCHAR(160) NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('Pending', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
  referred_by_person_id BIGINT UNSIGNED NULL,
  referral_date DATE NOT NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_referrals_patient
    FOREIGN KEY (patient_person_id) REFERENCES patients(person_id),
  CONSTRAINT fk_referrals_visit
    FOREIGN KEY (visit_id) REFERENCES visits(visit_id) ON DELETE SET NULL,
  CONSTRAINT fk_referrals_referred_by
    FOREIGN KEY (referred_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_referrals_patient_date (patient_person_id, referral_date),
  INDEX idx_referrals_visit (visit_id),
  INDEX idx_referrals_status_date (status, referral_date),
  INDEX idx_referrals_referred_by (referred_by_person_id)
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
