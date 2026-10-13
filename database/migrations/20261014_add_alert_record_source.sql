ALTER TABLE nurse_alerts
  ADD COLUMN record_source VARCHAR(40) NOT NULL DEFAULT 'Legacy / unknown' AFTER incident_report_id,
  ADD INDEX idx_nurse_alerts_source_created (record_source, created_at);
