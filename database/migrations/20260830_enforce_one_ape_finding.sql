USE Cliniq_db;

-- Keep the newest finding for each APE before enforcing the one-to-one rule.
DELETE older
FROM ape_findings older
INNER JOIN ape_findings newer
  ON newer.ape_id = older.ape_id
 AND (
      newer.recorded_at > older.recorded_at
      OR (newer.recorded_at = older.recorded_at AND newer.finding_id > older.finding_id)
 );

ALTER TABLE ape_findings
  ADD UNIQUE KEY uq_ape_findings_ape (ape_id);
