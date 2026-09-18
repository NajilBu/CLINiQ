-- Align clinic staff login IDs with Faculty and NTP: exactly seven digits.
-- Only legacy clinic_staff IDs are changed. Existing numeric IDs are preserved,
-- and new values start after the highest seven-digit ID used by any person.
START TRANSACTION;

CREATE TEMPORARY TABLE cliniq_legacy_staff_id_sequence AS
SELECT
    p.id AS person_id,
    p.id_number AS previous_id_number,
    ROW_NUMBER() OVER (ORDER BY p.id) AS sequence_number
FROM people p
JOIN clinic_staff cs ON cs.person_id = p.id
WHERE p.id_number NOT REGEXP '^[0-9]{7}$';

SET @cliniq_staff_starting_number := COALESCE((
    SELECT MAX(CAST(p.id_number AS UNSIGNED))
    FROM people p
    WHERE p.id_number REGEXP '^[0-9]{7}$'
), 0);

CREATE TEMPORARY TABLE cliniq_staff_id_capacity_guard (
    highest_required_id INT NOT NULL CHECK (highest_required_id <= 9999999)
);
INSERT INTO cliniq_staff_id_capacity_guard (highest_required_id)
SELECT @cliniq_staff_starting_number + COUNT(*)
FROM cliniq_legacy_staff_id_sequence;

UPDATE people p
JOIN cliniq_legacy_staff_id_sequence ids ON ids.person_id = p.id
SET p.id_number = CONCAT('TEMP-STAFF-', p.id);

UPDATE people p
JOIN cliniq_legacy_staff_id_sequence ids ON ids.person_id = p.id
SET p.id_number = LPAD(CAST(@cliniq_staff_starting_number + ids.sequence_number AS UNSIGNED), 7, '0');

DROP TEMPORARY TABLE cliniq_legacy_staff_id_sequence;
DROP TEMPORARY TABLE cliniq_staff_id_capacity_guard;

COMMIT;
