USE Cliniq_db;

-- Every eligible school or clinic role needs a clinical patient profile
-- before that person can create a Visit Log entry.
INSERT INTO patients (person_id, emergency_token, token_enabled)
SELECT
  pe.id,
  SHA2(CONCAT(UUID(), ':', pe.id, ':', pe.id_number), 256),
  1
FROM people pe
LEFT JOIN faculty f ON f.person_id = pe.id
LEFT JOIN school_personnel sp ON sp.person_id = pe.id
LEFT JOIN clinic_staff cs ON cs.person_id = pe.id
LEFT JOIN patients pt ON pt.person_id = pe.id
WHERE pt.person_id IS NULL
  AND (
    f.person_id IS NOT NULL
    OR sp.person_id IS NOT NULL
    OR cs.person_id IS NOT NULL
  );
