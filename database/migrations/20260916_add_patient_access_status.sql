ALTER TABLE patients
  ADD COLUMN IF NOT EXISTS access_status ENUM('Applicant', 'Official') NOT NULL DEFAULT 'Official' AFTER token_enabled;

UPDATE patients
SET access_status = 'Official'
WHERE access_status IS NULL OR access_status NOT IN ('Applicant', 'Official');
