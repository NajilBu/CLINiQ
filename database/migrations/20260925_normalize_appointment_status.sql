USE Cliniq_db;

ALTER TABLE appointments
  MODIFY status VARCHAR(40) NOT NULL DEFAULT 'Pending';
