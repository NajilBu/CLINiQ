USE Cliniq_db;

-- The old urgency query treated every non-Verified requirement as a patient
-- overdue document. Remove only unsent drafts generated from that retired
-- rule; sent and in-flight delivery history is retained unchanged.
CREATE TEMPORARY TABLE stale_ape_overdue_email_drafts AS
SELECT q.id AS email_queue_id, q.patient_person_id, q.source_id AS ape_id
FROM email_queue q
INNER JOIN ape_records ar ON ar.ape_id = q.source_id
WHERE q.status = 'pending'
  AND q.event_type = 'ape_documents_overdue'
  AND q.source_type = 'ape'
  AND NOT EXISTS (
      SELECT 1
      FROM ape_requirements r
      LEFT JOIN ape_documents latest_document ON latest_document.document_id = (
          SELECT MAX(d.document_id)
          FROM ape_documents d
          WHERE d.ape_id = r.ape_id
            AND d.document_type = r.requirement_name
      )
      WHERE r.ape_id = ar.ape_id
        AND COALESCE(r.upload_group, 'initial') = 'initial'
        AND r.status IN ('Missing', 'Needs Correction')
        AND COALESCE(latest_document.verification_status, '') NOT IN ('Pending', 'Verified')
        AND COALESCE(r.upload_due_date, DATE_ADD(ar.exam_date, INTERVAL 7 DAY)) < CURDATE()
  );

INSERT INTO audit_logs (actor_type, module, action, target_type, target_id, outcome, metadata)
SELECT
    'system',
    'email',
    'stale_ape_overdue_draft_removed',
    'email',
    stale.email_queue_id,
    'success',
    JSON_OBJECT(
        'migration', '20260926_remove_stale_ape_overdue_email_drafts',
        'patient_person_id', stale.patient_person_id,
        'ape_id', stale.ape_id
    )
FROM stale_ape_overdue_email_drafts stale;

DELETE q
FROM email_queue q
INNER JOIN stale_ape_overdue_email_drafts stale ON stale.email_queue_id = q.id;

DROP TEMPORARY TABLE stale_ape_overdue_email_drafts;
