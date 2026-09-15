CREATE TABLE IF NOT EXISTS patient_notifications (
  notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_person_id BIGINT UNSIGNED NOT NULL,
  actor_person_id BIGINT UNSIGNED NULL,
  category VARCHAR(40) NOT NULL,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  target_url VARCHAR(255) NULL,
  source_type VARCHAR(50) NULL,
  source_id BIGINT UNSIGNED NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_patient_notifications_patient
    FOREIGN KEY (patient_person_id) REFERENCES patients(person_id) ON DELETE CASCADE,
  CONSTRAINT fk_patient_notifications_actor
    FOREIGN KEY (actor_person_id) REFERENCES people(id) ON DELETE SET NULL,
  INDEX idx_patient_notifications_patient_created (patient_person_id, created_at),
  INDEX idx_patient_notifications_unread (patient_person_id, read_at),
  INDEX idx_patient_notifications_source (source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
