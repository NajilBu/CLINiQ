USE Cliniq_db;

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

ALTER TABLE ape_records
  ADD COLUMN ape_cycle_id BIGINT UNSIGNED NULL AFTER ape_id,
  ADD CONSTRAINT fk_ape_records_cycle
    FOREIGN KEY (ape_cycle_id) REFERENCES ape_cycles(ape_cycle_id)
    ON DELETE RESTRICT,
  ADD INDEX idx_ape_records_cycle (ape_cycle_id);
