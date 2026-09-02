USE cliniq_db;

CREATE TABLE ape_schedule_batches (
  batch_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ape_cycle_id BIGINT UNSIGNED NOT NULL,
  batch_name VARCHAR(120) NOT NULL,
  patient_category ENUM('Student', 'Faculty', 'School Personnel') NOT NULL,
  schedule_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL,
  status ENUM('Scheduled', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Scheduled',
  created_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_ape_batch_time CHECK (start_time < end_time),
  CONSTRAINT chk_ape_batch_capacity CHECK (capacity > 0),
  CONSTRAINT fk_ape_batches_cycle
    FOREIGN KEY (ape_cycle_id) REFERENCES ape_cycles(ape_cycle_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_ape_batches_created_by
    FOREIGN KEY (created_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  UNIQUE INDEX uq_ape_batch_name (ape_cycle_id, batch_name),
  INDEX idx_ape_batches_schedule (ape_cycle_id, schedule_date, start_time),
  INDEX idx_ape_batches_category (ape_cycle_id, patient_category, status)
);

ALTER TABLE ape_records
  ADD COLUMN schedule_batch_id BIGINT UNSIGNED NULL AFTER ape_cycle_id,
  ADD CONSTRAINT fk_ape_records_schedule_batch
    FOREIGN KEY (schedule_batch_id) REFERENCES ape_schedule_batches(batch_id)
    ON DELETE SET NULL,
  ADD INDEX idx_ape_records_schedule_batch (schedule_batch_id);
