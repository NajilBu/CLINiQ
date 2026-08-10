USE Cliniq_db;

CREATE TABLE ape_records (
  ape_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id BIGINT UNSIGNED NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  exam_date DATE NULL,
  appointment_id BIGINT UNSIGNED NULL,
  requirement_status ENUM('Not Checked', 'Pre-Verified', 'Needs Correction')
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
  reviewed_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ape_records_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id),
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
