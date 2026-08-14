USE Cliniq_db;

ALTER TABLE ape_records
  ADD COLUMN patient_height_cm DECIMAL(5,2) NULL AFTER patient_visible_note,
  ADD COLUMN patient_weight_kg DECIMAL(5,2) NULL AFTER patient_height_cm,
  ADD COLUMN patient_bmi DECIMAL(5,2) NULL AFTER patient_weight_kg,
  ADD COLUMN patient_temperature DECIMAL(4,1) NULL AFTER patient_bmi,
  ADD COLUMN patient_blood_pressure VARCHAR(20) NULL AFTER patient_temperature,
  ADD COLUMN patient_pulse_rate SMALLINT UNSIGNED NULL AFTER patient_blood_pressure,
  ADD COLUMN patient_vitals_status ENUM('Not Started', 'Confirmed') NOT NULL DEFAULT 'Not Started' AFTER patient_pulse_rate,
  ADD COLUMN patient_vitals_confirmed_at DATETIME NULL AFTER patient_vitals_status;
