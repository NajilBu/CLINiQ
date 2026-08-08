USE Cliniq_db;

ALTER TABLE appointments
  ADD COLUMN reserved_slot DATETIME
    GENERATED ALWAYS AS (
      CASE
        WHEN status IN ('Pending', 'Scheduled') THEN appointment_datetime
        ELSE NULL
      END
    ) STORED AFTER updated_at,
  ADD UNIQUE INDEX uq_appointments_reserved_slot (reserved_slot);
