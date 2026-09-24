START TRANSACTION;

UPDATE accounts a
INNER JOIN people p ON p.id = a.person_id
INNER JOIN students s ON s.person_id = p.id
SET a.email = CASE
    WHEN LOWER(p.first_name) = 'chrisha mazel' AND LOWER(p.middle_name) = 'flores' AND LOWER(p.last_name) = 'balbacal'
        THEN 'balbacal_chrishamazel@plpasig.edu.ph'
    WHEN LOWER(p.first_name) = 'yanie mei' AND (p.middle_name IS NULL OR TRIM(p.middle_name) = '') AND LOWER(p.last_name) = 'salen'
        THEN 'salen_yaniemei@plpasig.edu.ph'
    ELSE a.email
END
WHERE (a.email IS NULL OR TRIM(a.email) = '')
  AND (
      (LOWER(p.first_name) = 'chrisha mazel' AND LOWER(p.middle_name) = 'flores' AND LOWER(p.last_name) = 'balbacal')
      OR (LOWER(p.first_name) = 'yanie mei' AND (p.middle_name IS NULL OR TRIM(p.middle_name) = '') AND LOWER(p.last_name) = 'salen')
  )
  AND NOT EXISTS (
      SELECT 1
      FROM accounts existing
      WHERE existing.person_id <> a.person_id
        AND existing.email = CASE
            WHEN LOWER(p.first_name) = 'chrisha mazel' AND LOWER(p.middle_name) = 'flores' AND LOWER(p.last_name) = 'balbacal'
                THEN 'balbacal_chrishamazel@plpasig.edu.ph'
            WHEN LOWER(p.first_name) = 'yanie mei' AND (p.middle_name IS NULL OR TRIM(p.middle_name) = '') AND LOWER(p.last_name) = 'salen'
                THEN 'salen_yaniemei@plpasig.edu.ph'
            ELSE a.email
        END
  );

COMMIT;
