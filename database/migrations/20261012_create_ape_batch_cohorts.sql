CREATE TABLE IF NOT EXISTS ape_schedule_batch_cohorts (
  batch_cohort_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ape_cycle_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  program_id BIGINT UNSIGNED NOT NULL,
  year_level VARCHAR(40) NOT NULL,
  section VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ape_batch_cohorts_cycle FOREIGN KEY (ape_cycle_id) REFERENCES ape_cycles(ape_cycle_id) ON DELETE RESTRICT,
  CONSTRAINT fk_ape_batch_cohorts_batch FOREIGN KEY (batch_id) REFERENCES ape_schedule_batches(batch_id) ON DELETE CASCADE,
  CONSTRAINT fk_ape_batch_cohorts_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT,
  UNIQUE INDEX uq_ape_batch_cohort (batch_id, program_id, year_level, section),
  INDEX idx_ape_batch_cohort_lookup (ape_cycle_id, program_id, year_level, section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
