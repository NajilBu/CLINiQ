CREATE INDEX IF NOT EXISTS idx_email_queue_work_lookup
    ON email_queue (patient_person_id, event_type, source_type, source_id, id);
