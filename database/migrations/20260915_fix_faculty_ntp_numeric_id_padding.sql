-- Cast the sequence to an integer before padding so IDs are seven digits, not decimals.
START TRANSACTION;

CREATE TEMPORARY TABLE cliniq_faculty_ntp_id_sequence_fix AS
SELECT
    p.id AS person_id,
    ROW_NUMBER() OVER (ORDER BY p.id) AS sequence_number
FROM people p
JOIN school_employees se ON se.person_id = p.id
WHERE se.role_classification IN ('Faculty', 'Non-Teaching Personnel');

SET @starting_number := COALESCE((
    SELECT MAX(CAST(p.id_number AS UNSIGNED))
    FROM people p
    LEFT JOIN school_employees se ON se.person_id = p.id
    WHERE p.id_number REGEXP '^[0-9]{7}$'
      AND (se.person_id IS NULL OR se.role_classification NOT IN ('Faculty', 'Non-Teaching Personnel'))
), 0);

UPDATE people p
JOIN cliniq_faculty_ntp_id_sequence_fix ids ON ids.person_id = p.id
SET p.id_number = LPAD(CAST(@starting_number + ids.sequence_number AS UNSIGNED), 7, '0');

DROP TEMPORARY TABLE cliniq_faculty_ntp_id_sequence_fix;

COMMIT;
