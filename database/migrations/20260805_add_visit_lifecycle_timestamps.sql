USE Cliniq_db;

ALTER TABLE visits
  ADD COLUMN IF NOT EXISTS addressed_at DATETIME NULL AFTER visit_datetime,
  ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER addressed_at;
