-- Retire the old patient-entered-vitals APE workflow metadata.
-- Clinical measurement fields remain available on APE and regular visit records.
ALTER TABLE ape_records
    DROP COLUMN IF EXISTS patient_vitals_confirmed_at,
    DROP COLUMN IF EXISTS patient_vitals_status;
