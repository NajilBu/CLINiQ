CREATE TABLE IF NOT EXISTS patient_registration_verifications (
  registration_verification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_number VARCHAR(50) NOT NULL,
  email VARCHAR(160) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  requested_ip VARCHAR(45) NULL,
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_patient_registration_identity_created (student_number, email, created_at),
  INDEX idx_patient_registration_expiry (expires_at, verified_at, consumed_at),
  INDEX idx_patient_registration_ip_created (requested_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
