USE Cliniq_db;

CREATE TABLE IF NOT EXISTS visits (
  visit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_person_id BIGINT UNSIGNED NOT NULL,
  visit_datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

CREATE TABLE IF NOT EXISTS visit_entries (
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

CREATE TABLE IF NOT EXISTS vital_signs (
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
