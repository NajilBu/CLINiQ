USE Cliniq_db;

-- Preserve every existing initial password before removing the redundant column.
UPDATE accounts
SET password_hash = temporary_password_hash
WHERE password_hash IS NULL
  AND temporary_password_hash IS NOT NULL;

ALTER TABLE accounts
  DROP COLUMN temporary_password_hash;
