ALTER TABLE patients
  ADD COLUMN show_bmi_on_passport TINYINT(1) NOT NULL DEFAULT 1 AFTER bmi;
