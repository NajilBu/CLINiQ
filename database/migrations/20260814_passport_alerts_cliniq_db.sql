USE Cliniq_db;

ALTER TABLE patients
  ADD COLUMN allergies TEXT NULL AFTER blood_type,
  ADD COLUMN existing_conditions TEXT NULL AFTER allergies,
  ADD COLUMN medications TEXT NULL AFTER existing_conditions,
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS passport_access_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id BIGINT UNSIGNED NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  accessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cliniq_passport_access_logs_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id) ON DELETE CASCADE,
  INDEX idx_passport_access_logs_patient_accessed (patient_id, accessed_at)
);

CREATE TABLE IF NOT EXISTS nurse_alerts (
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
  CONSTRAINT fk_cliniq_nurse_alerts_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id) ON DELETE SET NULL,
  CONSTRAINT fk_cliniq_nurse_alerts_resolved_by
    FOREIGN KEY (resolved_by) REFERENCES people(id) ON DELETE SET NULL,
  INDEX idx_nurse_alerts_status_created (status, created_at),
  INDEX idx_nurse_alerts_risk_created (risk_level, risk_score, created_at),
  INDEX idx_nurse_alerts_patient (patient_id)
);

CREATE TABLE IF NOT EXISTS incident_reports (
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
  CONSTRAINT fk_cliniq_incident_reports_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id) ON DELETE CASCADE,
  INDEX idx_incident_reports_patient_reported (patient_id, reported_at),
  INDEX idx_incident_reports_status_reported (status, reported_at)
);
