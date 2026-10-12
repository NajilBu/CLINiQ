ALTER TABLE visits
  ADD COLUMN appointment_id BIGINT UNSIGNED NULL AFTER visit_id,
  ADD CONSTRAINT fk_visits_appointment
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id)
    ON DELETE SET NULL,
  ADD UNIQUE INDEX uq_visits_appointment (appointment_id);
