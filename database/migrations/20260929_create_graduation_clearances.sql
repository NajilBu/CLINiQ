CREATE TABLE IF NOT EXISTS graduation_clearances (
  clearance_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_person_id BIGINT UNSIGNED NOT NULL,
  batch_year SMALLINT UNSIGNED NOT NULL,
  cleared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cleared_by_person_id BIGINT UNSIGNED NOT NULL,
  revoked_at TIMESTAMP NULL,
  revoked_by_person_id BIGINT UNSIGNED NULL,
  CONSTRAINT fk_graduation_clearance_student FOREIGN KEY (student_person_id) REFERENCES students(person_id) ON DELETE CASCADE,
  CONSTRAINT fk_graduation_clearance_staff FOREIGN KEY (cleared_by_person_id) REFERENCES people(id),
  CONSTRAINT fk_graduation_clearance_revoker FOREIGN KEY (revoked_by_person_id) REFERENCES people(id),
  UNIQUE INDEX uq_graduation_clearance_student_batch (student_person_id, batch_year),
  INDEX idx_graduation_clearance_batch_active (batch_year, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
