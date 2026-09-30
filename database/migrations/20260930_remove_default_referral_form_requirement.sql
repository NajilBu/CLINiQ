USE Cliniq_db;

-- Referral Form is a clinic-created referral record, not a standard patient upload.
-- Remove only untouched legacy initial checklist rows; uploaded, reviewed, and follow-up records remain intact.
CREATE TEMPORARY TABLE ape_legacy_referral_requirement_removals AS
SELECT DISTINCT requirement.ape_id
FROM ape_requirements requirement
LEFT JOIN ape_documents document
  ON document.ape_id = requirement.ape_id
 AND document.document_type = requirement.requirement_name
WHERE requirement.requirement_name = 'Referral Form'
  AND COALESCE(requirement.upload_group, 'initial') = 'initial'
  AND requirement.status = 'Missing'
  AND requirement.checked_at IS NULL
  AND (requirement.remarks IS NULL OR TRIM(requirement.remarks) = '')
  AND document.document_id IS NULL;

DELETE requirement
FROM ape_requirements requirement
LEFT JOIN ape_documents document
  ON document.ape_id = requirement.ape_id
 AND document.document_type = requirement.requirement_name
WHERE requirement.requirement_name = 'Referral Form'
  AND COALESCE(requirement.upload_group, 'initial') = 'initial'
  AND requirement.status = 'Missing'
  AND requirement.checked_at IS NULL
  AND (requirement.remarks IS NULL OR TRIM(requirement.remarks) = '')
  AND document.document_id IS NULL;

INSERT INTO audit_logs (actor_type, module, action, target_type, target_id, outcome, metadata)
SELECT
    'system',
    'ape',
    'legacy_default_referral_requirement_removed',
    'ape',
    removal.ape_id,
    'success',
    JSON_OBJECT('migration', '20260930_remove_default_referral_form_requirement')
FROM ape_legacy_referral_requirement_removals removal;

DROP TEMPORARY TABLE ape_legacy_referral_requirement_removals;
