START TRANSACTION;

UPDATE accounts
SET email = 'salen_yaniemeilourin@plpasig.edu.ph'
WHERE person_id = 72
  AND (email IS NULL OR TRIM(email) = '')
  AND NOT EXISTS (
      SELECT 1
      FROM accounts existing
      WHERE existing.person_id <> 72
        AND existing.email = 'salen_yaniemeilourin@plpasig.edu.ph'
  );

COMMIT;
