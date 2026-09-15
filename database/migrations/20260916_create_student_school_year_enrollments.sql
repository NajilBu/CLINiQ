CREATE TABLE IF NOT EXISTS student_school_year_enrollments (
  enrollment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_person_id BIGINT UNSIGNED NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  program_id BIGINT UNSIGNED NULL,
  year_level VARCHAR(40) NULL,
  section VARCHAR(80) NULL,
  enrollment_status VARCHAR(40) NOT NULL DEFAULT 'Pending Confirmation',
  non_enrollment_reason VARCHAR(80) NULL,
  promotion_source VARCHAR(30) NOT NULL DEFAULT 'Automatic',
  promoted_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_student_year_enrollment_student
    FOREIGN KEY (student_person_id) REFERENCES students(person_id) ON DELETE CASCADE,
  CONSTRAINT fk_student_year_enrollment_program
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
  CONSTRAINT fk_student_year_enrollment_promoter
    FOREIGN KEY (promoted_by_person_id) REFERENCES people(id) ON DELETE SET NULL,
  UNIQUE INDEX uq_student_school_year (student_person_id, academic_year),
  INDEX idx_student_school_year_status (academic_year, enrollment_status),
  INDEX idx_student_school_year_program (program_id, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
