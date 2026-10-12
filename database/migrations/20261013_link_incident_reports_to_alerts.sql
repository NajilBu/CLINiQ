ALTER TABLE nurse_alerts
  ADD COLUMN incident_report_id BIGINT UNSIGNED NULL UNIQUE AFTER id,
  ADD CONSTRAINT fk_nurse_alerts_incident_report
    FOREIGN KEY (incident_report_id) REFERENCES incident_reports(id)
    ON DELETE SET NULL;
