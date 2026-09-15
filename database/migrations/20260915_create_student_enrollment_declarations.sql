CREATE TABLE IF NOT EXISTS student_enrollment_declarations (
  declaration_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  enrollment_status ENUM('Still Enrolled', 'Not Currently Enrolled') NOT NULL,
  non_enrollment_reason ENUM('Leave of Absence', 'Graduated', 'Transferred', 'Withdrawn', 'Other') NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_student_enrollment_declaration_reason
    CHECK (
      (enrollment_status = 'Still Enrolled' AND non_enrollment_reason IS NULL)
      OR
      (enrollment_status = 'Not Currently Enrolled' AND non_enrollment_reason IS NOT NULL)
    ),
  CONSTRAINT fk_student_enrollment_declarations_account
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  UNIQUE INDEX uq_student_enrollment_declaration_year (account_id, academic_year),
  INDEX idx_student_enrollment_declarations_status_year (enrollment_status, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
