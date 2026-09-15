UPDATE school_employees
SET role_classification = 'Non-Teaching Personnel'
WHERE role_classification = 'School Personnel';

ALTER TABLE school_employees
  MODIFY role_classification ENUM('Faculty', 'Non-Teaching Personnel') NOT NULL;

ALTER TABLE ape_records
  ADD COLUMN entry_mode ENUM('Student Scheduled', 'Clinic Manual') NOT NULL DEFAULT 'Student Scheduled'
  AFTER schedule_batch_id;

UPDATE ape_schedule_batches b
JOIN ape_records ar ON ar.schedule_batch_id = b.batch_id
LEFT JOIN students s ON s.person_id = ar.patient_id
SET b.status = 'Cancelled'
WHERE s.person_id IS NULL;

UPDATE ape_records ar
LEFT JOIN students s ON s.person_id = ar.patient_id
SET ar.entry_mode = 'Clinic Manual',
    ar.schedule_batch_id = NULL
WHERE s.person_id IS NULL;
