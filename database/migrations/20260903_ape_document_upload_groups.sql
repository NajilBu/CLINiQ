-- Run once. Keep original clinical review separate from digital upload status.
ALTER TABLE ape_requirements
    ADD COLUMN upload_group ENUM('initial', 'follow_up') NULL AFTER requirement_name,
    ADD COLUMN upload_due_date DATE NULL AFTER upload_group;

-- Only initialize unambiguous, locked examinations with no uploads yet.
-- Unreviewed/legacy ambiguous records stay NULL for explicit clinic review.
UPDATE ape_requirements r
JOIN ape_records ar ON ar.ape_id = r.ape_id
SET r.upload_group = CASE WHEN r.status = 'Verified' THEN 'initial' ELSE 'follow_up' END,
    r.upload_due_date = CASE WHEN r.status = 'Verified' THEN DATE_ADD(ar.exam_date, INTERVAL 7 DAY) ELSE ar.follow_up_due_date END,
    r.updated_at = r.updated_at
WHERE ar.exam_date IS NOT NULL AND ar.requirements_saved_at IS NOT NULL
  AND r.upload_group IS NULL
  AND (r.status = 'Verified' OR (r.status IN ('Missing', 'Needs Correction') AND ar.follow_up_required = 1 AND ar.follow_up_due_date IS NOT NULL))
  AND NOT EXISTS (SELECT 1 FROM ape_documents d WHERE d.ape_id = ar.ape_id);
