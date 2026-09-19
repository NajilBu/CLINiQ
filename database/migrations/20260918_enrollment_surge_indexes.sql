-- Composite indexes for the enrollment-period access patterns.
-- Applied once by scripts/database/migrate.php.

ALTER TABLE appointments
  ADD INDEX idx_appointments_patient_feed (patient_id, appointment_datetime, created_at);

ALTER TABLE ape_documents
  ADD INDEX idx_ape_documents_feed (ape_id, uploaded_at, document_id);

ALTER TABLE patient_registration_verifications
  ADD INDEX idx_patient_registration_identity_status (student_number, email, consumed_at, expires_at);

ALTER TABLE patient_notifications
  ADD INDEX idx_patient_notifications_unread_feed (patient_person_id, read_at, created_at);
