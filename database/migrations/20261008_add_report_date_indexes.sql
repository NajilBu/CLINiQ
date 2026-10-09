-- Report filters use half-open ranges on these timestamp columns.
ALTER TABLE visits ADD INDEX idx_visits_report_datetime (visit_datetime);
ALTER TABLE appointments ADD INDEX idx_appointments_report_created (created_at);
ALTER TABLE referrals ADD INDEX idx_referrals_report_date (referral_date);
ALTER TABLE inventory_transactions ADD INDEX idx_inventory_transactions_report_created (created_at);
ALTER TABLE medicine_dispensings ADD INDEX idx_medicine_dispensings_report_date (dispensed_at);
ALTER TABLE equipment_loans ADD INDEX idx_equipment_loans_report_date (borrowed_at);
ALTER TABLE nurse_alerts ADD INDEX idx_nurse_alerts_report_created (created_at);
ALTER TABLE incident_reports ADD INDEX idx_incident_reports_reported (reported_at);
ALTER TABLE ape_records ADD INDEX idx_ape_records_report_exam (exam_date), ADD INDEX idx_ape_records_report_created (created_at);
ALTER TABLE ape_findings ADD INDEX idx_ape_findings_report_recorded (recorded_at);
