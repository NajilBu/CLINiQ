ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS status_reason VARCHAR(255) NULL AFTER account_status;

UPDATE accounts a
LEFT JOIN students s ON s.person_id = a.person_id
SET a.status_reason = CASE
  WHEN a.activated_at IS NULL THEN 'Awaiting initial account activation'
  WHEN s.person_id IS NOT NULL THEN 'New school year enrollment status required'
  ELSE 'Reason not recorded'
END
WHERE a.account_status = 'inactive'
  AND (a.status_reason IS NULL OR TRIM(a.status_reason) = '');
