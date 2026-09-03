-- Additive only: existing APE records and schedules are preserved.
-- Apply once; ensure_ape_workflow_schema also checks for each column.
ALTER TABLE ape_records
    ADD COLUMN requirements_saved_at DATETIME NULL AFTER requirement_status,
    ADD COLUMN follow_up_due_time TIME NULL AFTER follow_up_due_date;
