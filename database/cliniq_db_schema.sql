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

CREATE TABLE system_settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value MEDIUMTEXT NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_system_settings_updated_by
    FOREIGN KEY (updated_by) REFERENCES people(id) ON DELETE SET NULL
);

-- One login per person. Imported people start inactive with no password.
CREATE TABLE accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id BIGINT UNSIGNED NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  email VARCHAR(160) NULL,
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
  allergies TEXT NULL,
  existing_conditions TEXT NULL,
  medications TEXT NULL,
  emergency_instructions TEXT NULL,
  guardian_or_contact_name VARCHAR(160) NULL,
  guardian_or_contact_number VARCHAR(50) NULL,
  guardian_relationship VARCHAR(80) NULL,
  secondary_contact_number VARCHAR(32) NULL,
  height_cm DECIMAL(5,2) NULL,
  weight_kg DECIMAL(5,2) NULL,
  bmi DECIMAL(4,1) NULL,
  emergency_token CHAR(64) NULL UNIQUE,
  token_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
);

CREATE TABLE passport_access_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id BIGINT UNSIGNED NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  accessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_passport_access_logs_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id) ON DELETE CASCADE,
  INDEX idx_passport_access_logs_patient_accessed (patient_id, accessed_at)
);

CREATE TABLE nurse_alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id BIGINT UNSIGNED NULL,
  reporter_name VARCHAR(120) NOT NULL,
  reporter_role VARCHAR(80) NULL,
  location VARCHAR(160) NOT NULL,
  concern VARCHAR(255) NOT NULL,
  incident_type VARCHAR(120) NULL,
  details TEXT NULL,
  report_answers MEDIUMTEXT NULL,
  risk_level VARCHAR(40) NOT NULL DEFAULT 'Low',
  risk_score INT NOT NULL DEFAULT 0,
  risk_reasons TEXT NULL,
  response_guidance TEXT NULL,
  photo_path VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Pending',
  resolution_report TEXT NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_nurse_alerts_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id) ON DELETE SET NULL,
  CONSTRAINT fk_nurse_alerts_resolved_by
    FOREIGN KEY (resolved_by) REFERENCES people(id) ON DELETE SET NULL,
  INDEX idx_nurse_alerts_status_created (status, created_at),
  INDEX idx_nurse_alerts_risk_created (risk_level, risk_score, created_at),
  INDEX idx_nurse_alerts_patient (patient_id)
);

CREATE TABLE incident_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id BIGINT UNSIGNED NOT NULL,
  emergency_token CHAR(64) NOT NULL,
  reporter_name VARCHAR(120) NULL,
  reporter_contact VARCHAR(80) NULL,
  location VARCHAR(160) NOT NULL,
  notes TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'New',
  reported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_at DATETIME NULL,
  CONSTRAINT fk_incident_reports_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id) ON DELETE CASCADE,
  INDEX idx_incident_reports_patient_reported (patient_id, reported_at),
  INDEX idx_incident_reports_status_reported (status, reported_at)
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
  visit_id BIGINT UNSIGNED NULL,
  patient_id BIGINT UNSIGNED NULL,
  entry_id BIGINT UNSIGNED NULL,
  temperature DECIMAL(4,1) NULL,
  blood_pressure VARCHAR(20) NULL,
  pulse_rate SMALLINT UNSIGNED NULL,
  measured_by_person_id BIGINT UNSIGNED NULL,
  measured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vital_signs_visit
    FOREIGN KEY (visit_id) REFERENCES visits(visit_id) ON DELETE CASCADE,
  CONSTRAINT fk_vital_signs_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id) ON DELETE CASCADE,
  CONSTRAINT fk_vital_signs_entry
    FOREIGN KEY (entry_id) REFERENCES visit_entries(entry_id) ON DELETE SET NULL,
  CONSTRAINT fk_vital_signs_measured_by
    FOREIGN KEY (measured_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_vital_signs_visit_measured (visit_id, measured_at),
  INDEX idx_vital_signs_patient (patient_id),
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

CREATE TABLE appointments (
  appointment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id BIGINT UNSIGNED NOT NULL,
  appointment_datetime DATETIME NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  status ENUM('Pending', 'Scheduled', 'For Confirmation', 'Completed', 'Cancelled', 'No Show')
    NOT NULL DEFAULT 'Pending',
  request_source ENUM('Patient Portal', 'Clinic Staff')
    NOT NULL DEFAULT 'Patient Portal',
  notes TEXT NULL,
  cancellation_reason TEXT NULL,
  cancelled_by ENUM('Patient', 'Clinic') NULL,
  reviewed_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  reserved_slot DATETIME
    GENERATED ALWAYS AS (
      CASE
        WHEN status IN ('Pending', 'Scheduled') THEN appointment_datetime
        ELSE NULL
      END
    ) STORED,
  CONSTRAINT fk_appointments_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id),
  CONSTRAINT fk_appointments_reviewed_by
    FOREIGN KEY (reviewed_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_appointments_patient_datetime (patient_id, appointment_datetime),
  INDEX idx_appointments_status_datetime (status, appointment_datetime),
  INDEX idx_appointments_datetime (appointment_datetime),
  INDEX idx_appointments_reviewed_by (reviewed_by_person_id),
  UNIQUE INDEX uq_appointments_reserved_slot (reserved_slot)
);

CREATE TABLE appointment_availability_blocks (
  availability_block_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  block_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  reason VARCHAR(255) NULL,
  created_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_appointment_block_time
    CHECK (
      (start_time IS NULL AND end_time IS NULL)
      OR
      (start_time IS NOT NULL AND end_time IS NOT NULL AND start_time < end_time)
    ),
  CONSTRAINT fk_appointment_blocks_created_by
    FOREIGN KEY (created_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_appointment_blocks_date (block_date),
  INDEX idx_appointment_blocks_created_by (created_by_person_id)
);

CREATE TABLE ape_cycles (
  ape_cycle_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academic_year VARCHAR(20) NOT NULL,
  compliance_start DATE NOT NULL,
  compliance_end DATE NOT NULL,
  status ENUM('Active', 'Closed', 'Archived') NOT NULL DEFAULT 'Active',
  started_by_person_id BIGINT UNSIGNED NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by_person_id BIGINT UNSIGNED NULL,
  closed_at DATETIME NULL,
  archived_by_person_id BIGINT UNSIGNED NULL,
  archived_at DATETIME NULL,
  active_cycle_slot TINYINT
    GENERATED ALWAYS AS (CASE WHEN status = 'Active' THEN 1 ELSE NULL END) STORED,
  CONSTRAINT chk_ape_cycles_dates CHECK (compliance_start <= compliance_end),
  CONSTRAINT fk_ape_cycles_started_by
    FOREIGN KEY (started_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_ape_cycles_closed_by
    FOREIGN KEY (closed_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_ape_cycles_archived_by
    FOREIGN KEY (archived_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  UNIQUE INDEX uq_ape_cycles_academic_year (academic_year),
  UNIQUE INDEX uq_ape_cycles_one_active (active_cycle_slot),
  INDEX idx_ape_cycles_status_started (status, started_at),
  INDEX idx_ape_cycles_started_by (started_by_person_id),
  INDEX idx_ape_cycles_closed_by (closed_by_person_id),
  INDEX idx_ape_cycles_archived_by (archived_by_person_id)
);

CREATE TABLE ape_records (
  ape_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ape_cycle_id BIGINT UNSIGNED NULL,
  patient_id BIGINT UNSIGNED NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  exam_date DATE NULL,
  appointment_id BIGINT UNSIGNED NULL,
  requirement_status ENUM('Not Checked', 'Checked', 'Needs Correction')
    NOT NULL DEFAULT 'Not Checked',
  workflow_status ENUM(
    'Registered',
    'Batch Assigned',
    'Requirements Checked',
    'Submitted',
    'Reviewed',
    'Scheduled',
    'Exam Done',
    'Follow-up Required',
    'Cleared'
  ) NOT NULL DEFAULT 'Registered',
  clearance_status ENUM('Pending', 'Cleared', 'For Follow-up')
    NOT NULL DEFAULT 'Pending',
  follow_up_required TINYINT(1) NOT NULL DEFAULT 0,
  clinical_remarks TEXT NULL,
  patient_visible_note TEXT NULL,
  patient_height_cm DECIMAL(5,2) NULL,
  patient_weight_kg DECIMAL(5,2) NULL,
  patient_bmi DECIMAL(5,2) NULL,
  patient_temperature DECIMAL(4,1) NULL,
  patient_blood_pressure VARCHAR(20) NULL,
  patient_pulse_rate SMALLINT UNSIGNED NULL,
  patient_vitals_status ENUM('Not Started', 'Confirmed') NOT NULL DEFAULT 'Not Started',
  patient_vitals_confirmed_at DATETIME NULL,
  reviewed_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ape_records_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id),
  CONSTRAINT fk_ape_records_cycle
    FOREIGN KEY (ape_cycle_id) REFERENCES ape_cycles(ape_cycle_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_ape_records_appointment
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_ape_records_reviewed_by
    FOREIGN KEY (reviewed_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  UNIQUE INDEX uq_ape_records_patient_year (patient_id, academic_year),
  UNIQUE INDEX uq_ape_records_appointment (appointment_id),
  INDEX idx_ape_records_workflow (workflow_status),
  INDEX idx_ape_records_clearance (clearance_status),
  INDEX idx_ape_records_cycle (ape_cycle_id),
  INDEX idx_ape_records_reviewed_by (reviewed_by_person_id)
);

CREATE TABLE ape_requirements (
  requirement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ape_id BIGINT UNSIGNED NOT NULL,
  requirement_name VARCHAR(160) NOT NULL,
  status ENUM('Missing', 'Submitted', 'Verified', 'Needs Correction')
    NOT NULL DEFAULT 'Missing',
  remarks TEXT NULL,
  checked_by_person_id BIGINT UNSIGNED NULL,
  checked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ape_requirements_ape
    FOREIGN KEY (ape_id) REFERENCES ape_records(ape_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ape_requirements_checked_by
    FOREIGN KEY (checked_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  UNIQUE INDEX uq_ape_requirement_name (ape_id, requirement_name),
  INDEX idx_ape_requirements_status (status),
  INDEX idx_ape_requirements_checked_by (checked_by_person_id)
);

CREATE TABLE ape_documents (
  document_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ape_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(120) NOT NULL,
  original_filename VARCHAR(255) NULL,
  file_path VARCHAR(500) NOT NULL,
  verification_status ENUM('Pending', 'Verified', 'Needs Correction')
    NOT NULL DEFAULT 'Pending',
  uploaded_by_person_id BIGINT UNSIGNED NULL,
  verified_by_person_id BIGINT UNSIGNED NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at DATETIME NULL,
  CONSTRAINT fk_ape_documents_ape
    FOREIGN KEY (ape_id) REFERENCES ape_records(ape_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ape_documents_uploaded_by
    FOREIGN KEY (uploaded_by_person_id) REFERENCES people(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_ape_documents_verified_by
    FOREIGN KEY (verified_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_ape_documents_type (ape_id, document_type),
  INDEX idx_ape_documents_status (verification_status),
  INDEX idx_ape_documents_uploaded_by (uploaded_by_person_id),
  INDEX idx_ape_documents_verified_by (verified_by_person_id)
);

CREATE TABLE ape_findings (
  finding_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ape_id BIGINT UNSIGNED NOT NULL,
  finding_type VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  result_status ENUM('Normal', 'With Finding', 'Referred')
    NOT NULL DEFAULT 'With Finding',
  follow_up_required TINYINT(1) NOT NULL DEFAULT 0,
  recorded_by_person_id BIGINT UNSIGNED NULL,
  recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ape_findings_ape
    FOREIGN KEY (ape_id) REFERENCES ape_records(ape_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ape_findings_recorded_by
  FOREIGN KEY (recorded_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  UNIQUE KEY uq_ape_findings_ape (ape_id),
  INDEX idx_ape_findings_type (ape_id, finding_type),
  INDEX idx_ape_findings_result (result_status),
  INDEX idx_ape_findings_recorded_by (recorded_by_person_id)
);

CREATE TABLE ape_activity_logs (
  activity_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ape_id BIGINT UNSIGNED NOT NULL,
  performed_by_person_id BIGINT UNSIGNED NULL,
  action VARCHAR(160) NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ape_activity_logs_ape
    FOREIGN KEY (ape_id) REFERENCES ape_records(ape_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ape_activity_logs_performed_by
    FOREIGN KEY (performed_by_person_id) REFERENCES people(id)
    ON DELETE SET NULL,
  INDEX idx_ape_activity_logs_timeline (ape_id, created_at),
  INDEX idx_ape_activity_logs_performed_by (performed_by_person_id)
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
