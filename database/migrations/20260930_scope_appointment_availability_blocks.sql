USE Cliniq_db;

ALTER TABLE appointment_availability_blocks
    ADD COLUMN applies_to VARCHAR(32) NOT NULL DEFAULT 'Both' AFTER end_time,
    ADD INDEX idx_appointment_blocks_scope_date (applies_to, block_date);
