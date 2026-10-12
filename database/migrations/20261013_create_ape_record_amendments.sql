CREATE TABLE IF NOT EXISTS ape_record_amendments (
  amendment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ape_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  correction_note TEXT NOT NULL,
  created_by_person_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ape_amendment_record FOREIGN KEY (ape_id) REFERENCES ape_records(ape_id) ON DELETE RESTRICT,
  CONSTRAINT fk_ape_amendment_author FOREIGN KEY (created_by_person_id) REFERENCES clinic_staff(person_id) ON DELETE RESTRICT,
  INDEX idx_ape_amendments_record (ape_id, created_at, amendment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
