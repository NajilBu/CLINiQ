ALTER TABLE clinic_staff
  ADD COLUMN IF NOT EXISTS honorific VARCHAR(8) NULL AFTER staff_role;
