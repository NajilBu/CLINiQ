START TRANSACTION;

-- Existing student/patient identities.
INSERT INTO Cliniq_db.people (
  id_number, first_name, middle_name, last_name, birthdate
)
SELECT
  id_number, first_name, middle_name, last_name, birthdate
FROM cliniq.patients
ORDER BY id;

-- Student academic profiles. Program, year, and section are assigned by the
-- normalization migration after these people are imported.
INSERT INTO Cliniq_db.students (person_id)
SELECT np.id
FROM cliniq.patients op
JOIN Cliniq_db.people np
  ON np.id_number COLLATE utf8mb4_general_ci = op.id_number;

-- Patient health profiles.
INSERT INTO Cliniq_db.patients (
  person_id,
  blood_type,
  emergency_instructions,
  guardian_or_contact_name,
  guardian_or_contact_number,
  emergency_token,
  token_enabled
)
SELECT
  np.id,
  op.blood_type,
  op.emergency_instructions,
  op.guardian_name,
  op.guardian_contact,
  op.emergency_token,
  op.token_enabled
FROM cliniq.patients op
JOIN Cliniq_db.people np
  ON np.id_number COLLATE utf8mb4_general_ci = op.id_number;

-- Preserve student passwords and activate their migrated accounts.
UPDATE Cliniq_db.accounts na
JOIN Cliniq_db.people np ON np.id = na.person_id
JOIN cliniq.patients op
  ON op.id_number = np.id_number COLLATE utf8mb4_general_ci
SET
  na.password_hash = op.password_hash,
  na.account_status = 'active',
  na.activated_at = op.created_at;

-- Existing clinic personnel use generated IDs because the old users table has
-- no institutional ID number or birthdate.
INSERT INTO Cliniq_db.people (
  id_number, first_name, middle_name, last_name, birthdate
) VALUES
  ('STAFF-0001', 'System', NULL, 'Administrator', NULL),
  ('STAFF-0002', 'Maria', NULL, 'Santos', NULL),
  ('STAFF-0003', 'Carlo', NULL, 'Reyes', NULL),
  ('STAFF-0004', 'Elena', NULL, 'Cruz', NULL),
  ('STAFF-0005', 'Liza', NULL, 'Manalo', NULL),
  ('STAFF-0006', 'IT', NULL, 'Support', NULL);

INSERT INTO Cliniq_db.clinic_staff (
  person_id, department_id, staff_role, position_title
)
SELECT
  np.id,
  (SELECT id FROM Cliniq_db.departments WHERE department_code = 'UHS'),
  ou.role,
  ou.name
FROM cliniq.users ou
JOIN Cliniq_db.people np
  ON np.id_number COLLATE utf8mb4_general_ci =
     CONCAT('STAFF-', LPAD(ou.id, 4, '0'));

-- Preserve clinic staff passwords and activate their migrated accounts.
UPDATE Cliniq_db.accounts na
JOIN Cliniq_db.people np ON np.id = na.person_id
JOIN cliniq.users ou
  ON np.id_number COLLATE utf8mb4_general_ci =
     CONCAT('STAFF-', LPAD(ou.id, 4, '0'))
SET
  na.password_hash = ou.password_hash,
  na.account_status = 'active',
  na.activated_at = ou.created_at;

-- Requested sample faculty account. It remains inactive until claimed.
INSERT INTO Cliniq_db.people (
  id_number, first_name, middle_name, last_name, birthdate
) VALUES (
  'FAC-0001', 'Maria', 'Reyes', 'Santos', '1985-06-20'
);

INSERT INTO Cliniq_db.faculty (
  person_id, department_id, employment_type, position_title
)
SELECT
  id,
  (SELECT id FROM Cliniq_db.departments WHERE department_code = 'CCS'),
  'Full-time',
  'Instructor'
FROM Cliniq_db.people
WHERE id_number = 'FAC-0001';

COMMIT;
