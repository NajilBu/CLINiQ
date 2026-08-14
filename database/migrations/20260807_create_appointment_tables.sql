USE Cliniq_db;

CREATE TABLE IF NOT EXISTS appointments (
  appointment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id BIGINT UNSIGNED NOT NULL,
  appointment_datetime DATETIME NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  status ENUM('Pending', 'Scheduled', 'Completed', 'Cancelled', 'No Show')
    NOT NULL DEFAULT 'Pending',
  request_source ENUM('Patient Portal', 'Clinic Staff')
    NOT NULL DEFAULT 'Patient Portal',
  notes TEXT NULL,
  cancellation_reason TEXT NULL,
  cancelled_by ENUM('Patient', 'Clinic') NULL,
  reviewed_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_appointments_patient
    FOREIGN KEY (patient_id) REFERENCES patients(person_id),
  CONSTRAINT fk_appointments_reviewed_by
    FOREIGN KEY (reviewed_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_appointments_patient_datetime (patient_id, appointment_datetime),
  INDEX idx_appointments_status_datetime (status, appointment_datetime),
  INDEX idx_appointments_datetime (appointment_datetime),
  INDEX idx_appointments_reviewed_by (reviewed_by_person_id)
);

CREATE TABLE IF NOT EXISTS appointment_availability_blocks (
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
