USE Cliniq_db;

-- A replacement upload is a new Pending submission, not an outstanding return.
-- Preserve its Follow-up group, due date, instructions, original file versions,
-- and audit history; only normalize the stale requirement state.
CREATE TEMPORARY TABLE ape_replacement_state_repairs AS
SELECT DISTINCT r.ape_id
FROM ape_requirements r
INNER JOIN ape_documents latest_document ON latest_document.document_id = (
    SELECT MAX(d.document_id)
    FROM ape_documents d
    WHERE d.ape_id = r.ape_id
      AND d.document_type = r.requirement_name
)
WHERE r.status = 'Needs Correction'
  AND latest_document.verification_status = 'Pending';

UPDATE ape_requirements r
INNER JOIN ape_documents latest_document ON latest_document.document_id = (
    SELECT MAX(d.document_id)
    FROM ape_documents d
    WHERE d.ape_id = r.ape_id
      AND d.document_type = r.requirement_name
)
SET r.status = 'Submitted',
    r.checked_by_person_id = NULL,
    r.checked_at = NULL
WHERE r.status = 'Needs Correction'
  AND latest_document.verification_status = 'Pending';

INSERT INTO audit_logs (actor_type, module, action, target_type, target_id, outcome, metadata)
SELECT
    'system',
    'ape',
    'replacement_submission_state_normalized',
    'ape',
    repair.ape_id,
    'success',
    JSON_OBJECT('migration', '20260926_normalize_ape_replacement_submission_state')
FROM ape_replacement_state_repairs repair;

DROP TEMPORARY TABLE ape_replacement_state_repairs;
