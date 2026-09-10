ALTER TABLE people
  ADD COLUMN IF NOT EXISTS profile_photo_path VARCHAR(255) NULL AFTER last_name;
