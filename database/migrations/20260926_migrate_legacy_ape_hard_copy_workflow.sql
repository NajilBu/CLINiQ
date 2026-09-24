USE Cliniq_db;

-- Retire the former hard-copy checklist without discarding its requirement
-- notes, uploaded versions, notification history, or existing audit events.
CREATE TEMPORARY TABLE legacy_ape_hard_copy_records AS
SELECT ape_id, patient_id
FROM ape_records
WHERE requirements_saved_at IS NOT NULL;

-- A historical checklist verification without a stored, archived file is not
-- proof of a completed digital archive. Keep it in Initial review so clinic
-- staff can attach the retained scan and use the normal Phase 3 archive flow.
UPDATE ape_requirements r
LEFT JOIN ape_documents latest_document ON latest_document.document_id = (
    SELECT MAX(d.document_id)
    FROM ape_documents d
    WHERE d.ape_id = r.ape_id
      AND d.document_type = r.requirement_name
)
INNER JOIN legacy_ape_hard_copy_records legacy ON legacy.ape_id = r.ape_id
SET r.upload_group = CASE
        WHEN r.status IN ('Needs Correction', 'Missing') THEN 'follow_up'
        ELSE COALESCE(r.upload_group, 'initial')
    END,
    r.checked_by_person_id = CASE
        WHEN r.status = 'Verified' AND (latest_document.document_id IS NULL OR latest_document.verification_status <> 'Verified') THEN NULL
        ELSE r.checked_by_person_id
    END,
    r.checked_at = CASE
        WHEN r.status = 'Verified' AND (latest_document.document_id IS NULL OR latest_document.verification_status <> 'Verified') THEN NULL
        ELSE r.checked_at
    END,
    r.status = CASE
        WHEN r.status = 'Verified' AND (latest_document.document_id IS NULL OR latest_document.verification_status <> 'Verified') THEN 'Submitted'
        ELSE r.status
    END;

UPDATE ape_records ar
INNER JOIN legacy_ape_hard_copy_records legacy ON legacy.ape_id = ar.ape_id
SET ar.requirements_saved_at = NULL,
    ar.workflow_status = CASE
        WHEN ar.workflow_status = 'Cleared' OR ar.clearance_status = 'Cleared' THEN ar.workflow_status
        WHEN EXISTS (
            SELECT 1 FROM ape_requirements r
            WHERE r.ape_id = ar.ape_id
              AND r.upload_group = 'follow_up'
              AND r.status <> 'Verified'
        ) THEN 'Follow-up Required'
        WHEN ar.exam_date IS NOT NULL THEN 'Reviewed'
        ELSE ar.workflow_status
    END,
    ar.clearance_status = CASE
        WHEN ar.workflow_status = 'Cleared' OR ar.clearance_status = 'Cleared' THEN ar.clearance_status
        WHEN EXISTS (
            SELECT 1 FROM ape_requirements r
            WHERE r.ape_id = ar.ape_id
              AND r.upload_group = 'follow_up'
              AND r.status <> 'Verified'
        ) THEN 'For Follow-up'
        ELSE ar.clearance_status
    END,
    ar.follow_up_required = CASE
        WHEN ar.workflow_status = 'Cleared' OR ar.clearance_status = 'Cleared' THEN ar.follow_up_required
        WHEN EXISTS (
            SELECT 1 FROM ape_requirements r
            WHERE r.ape_id = ar.ape_id
              AND r.upload_group = 'follow_up'
              AND r.status <> 'Verified'
        ) THEN 1
        ELSE ar.follow_up_required
    END;

INSERT INTO audit_logs (actor_type, module, action, target_type, target_id, outcome, metadata)
SELECT
    'system',
    'ape',
    'legacy_hard_copy_workflow_migrated',
    'ape',
    legacy.ape_id,
    'success',
    JSON_OBJECT('patient_id', legacy.patient_id, 'migration', '20260926_migrate_legacy_ape_hard_copy_workflow')
FROM legacy_ape_hard_copy_records legacy;

DROP TEMPORARY TABLE legacy_ape_hard_copy_records;
