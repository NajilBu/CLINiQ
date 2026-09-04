USE cliniq_db;

ALTER TABLE ape_records
  DROP FOREIGN KEY fk_ape_records_schedule_batch,
  DROP INDEX idx_ape_records_schedule_batch,
  DROP COLUMN schedule_batch_id;

DROP TABLE ape_schedule_batches;
