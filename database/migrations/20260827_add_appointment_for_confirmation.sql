USE Cliniq_db;

ALTER TABLE appointments
  MODIFY status VARCHAR(40) NOT NULL DEFAULT 'Pending';

UPDATE appointments
SET status = 'For Confirmation'
WHERE status = 'Scheduled'
  AND DATE_ADD(appointment_datetime, INTERVAL 60 MINUTE) <= NOW();
