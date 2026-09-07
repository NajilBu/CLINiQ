-- Run against the configured AUTH_DB_NAME database after the visit migrations.
CREATE TABLE IF NOT EXISTS clinic_feedback (
    feedback_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_id BIGINT UNSIGNED NOT NULL,
    survey_version VARCHAR(20) NOT NULL DEFAULT 'servperf-v1',
    consent_version VARCHAR(20) NOT NULL DEFAULT 'visit-linked-v1',
    service_type VARCHAR(160) NOT NULL,
    service_other VARCHAR(160) NULL,
    academic_term VARCHAR(80) NOT NULL,
    term_other VARCHAR(80) NULL,
    year_level VARCHAR(80) NOT NULL,
    year_other VARCHAR(80) NULL,
    program VARCHAR(160) NOT NULL,
    comments TEXT NOT NULL,
    ratings_json LONGTEXT NOT NULL,
    tangibles DECIMAL(9,6) NOT NULL,
    reliability DECIMAL(9,6) NOT NULL,
    responsiveness DECIMAL(9,6) NOT NULL,
    assurance DECIMAL(9,6) NOT NULL,
    empathy DECIMAL(9,6) NOT NULL,
    overall DECIMAL(9,6) NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clinic_feedback_visit (visit_id),
    INDEX idx_clinic_feedback_submitted (submitted_at),
    CONSTRAINT fk_clinic_feedback_visit FOREIGN KEY (visit_id) REFERENCES visits(visit_id),
    CONSTRAINT chk_clinic_feedback_scores CHECK (
        tangibles BETWEEN 1 AND 7 AND reliability BETWEEN 1 AND 7 AND
        responsiveness BETWEEN 1 AND 7 AND assurance BETWEEN 1 AND 7 AND
        empathy BETWEEN 1 AND 7 AND overall BETWEEN 1 AND 7
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
