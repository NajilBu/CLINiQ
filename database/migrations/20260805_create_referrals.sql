USE Cliniq_db;

CREATE TABLE IF NOT EXISTS referrals (
  referral_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_person_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NULL,
  referred_to VARCHAR(160) NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('Pending', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
  referred_by_person_id BIGINT UNSIGNED NULL,
  referral_date DATE NOT NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_referrals_patient
    FOREIGN KEY (patient_person_id) REFERENCES patients(person_id),
  CONSTRAINT fk_referrals_visit
    FOREIGN KEY (visit_id) REFERENCES visits(visit_id) ON DELETE SET NULL,
  CONSTRAINT fk_referrals_referred_by
    FOREIGN KEY (referred_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_referrals_patient_date (patient_person_id, referral_date),
  INDEX idx_referrals_visit (visit_id),
  INDEX idx_referrals_status_date (status, referral_date),
  INDEX idx_referrals_referred_by (referred_by_person_id)
);
