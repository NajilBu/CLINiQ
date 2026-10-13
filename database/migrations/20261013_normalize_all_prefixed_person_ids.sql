-- Retire every legacy prefixed or otherwise noncanonical person identifier.
-- Existing person IDs and all foreign-key relationships remain unchanged.
START TRANSACTION;

CREATE TEMPORARY TABLE cliniq_noncanonical_person_id_sequence AS
SELECT
    p.id AS person_id,
    ROW_NUMBER() OVER (ORDER BY p.id) AS sequence_number
FROM people p
WHERE p.id_number NOT REGEXP '^([0-9]{7}|[0-9]{2}-[0-9]{5})$';

SET @cliniq_person_id_starting_number := COALESCE((
    SELECT MAX(CAST(p.id_number AS UNSIGNED))
    FROM people p
    WHERE p.id_number REGEXP '^[0-9]{7}$'
), 0);

CREATE TEMPORARY TABLE cliniq_person_id_capacity_guard (
    highest_required_id INT NOT NULL CHECK (highest_required_id <= 9999999)
);
INSERT INTO cliniq_person_id_capacity_guard (highest_required_id)
SELECT @cliniq_person_id_starting_number + COUNT(*)
FROM cliniq_noncanonical_person_id_sequence;

UPDATE people p
JOIN cliniq_noncanonical_person_id_sequence ids ON ids.person_id = p.id
SET p.id_number = CONCAT('TEMP-ID-', p.id);

UPDATE people p
JOIN cliniq_noncanonical_person_id_sequence ids ON ids.person_id = p.id
SET p.id_number = LPAD(CAST(@cliniq_person_id_starting_number + ids.sequence_number AS UNSIGNED), 7, '0');

DROP TEMPORARY TABLE cliniq_noncanonical_person_id_sequence;
DROP TEMPORARY TABLE cliniq_person_id_capacity_guard;

COMMIT;
