USE Cliniq_db;

ALTER TABLE appointments
  DROP INDEX uq_appointments_reserved_slot,
  MODIFY reserved_slot VARCHAR(320)
    GENERATED ALWAYS AS (
      CASE
        WHEN status IN ('Pending', 'Scheduled', 'For Confirmation')
        THEN CONCAT(appointment_datetime, '|', purpose)
        ELSE NULL
      END
    ) STORED,
  ADD UNIQUE INDEX uq_appointments_reserved_slot (reserved_slot);
