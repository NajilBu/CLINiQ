USE Cliniq_db;

ALTER TABLE appointments
  DROP INDEX uq_appointments_reserved_slot,
  ADD UNIQUE INDEX uq_appointments_reserved_slot (reserved_slot, purpose);
