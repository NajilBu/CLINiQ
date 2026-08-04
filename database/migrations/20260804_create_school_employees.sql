USE Cliniq_db;

CREATE TABLE school_employees (
  person_id BIGINT UNSIGNED PRIMARY KEY,
  department_id BIGINT UNSIGNED NULL,
  role_classification ENUM('Faculty', 'School Personnel') NOT NULL,
  employment_type VARCHAR(80) NULL,
  position_title VARCHAR(160) NULL,
  CONSTRAINT fk_school_employees_person
    FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  CONSTRAINT fk_school_employees_department
    FOREIGN KEY (department_id) REFERENCES departments(id),
  INDEX idx_school_employees_department (department_id),
  INDEX idx_school_employees_classification (role_classification)
);

INSERT INTO school_employees (
  person_id, department_id, role_classification, employment_type, position_title
)
SELECT person_id, department_id, 'Faculty', employment_type, position_title
FROM faculty;

INSERT INTO school_employees (
  person_id, department_id, role_classification, employment_type, position_title
)
SELECT person_id, department_id, 'School Personnel', employment_type, position_title
FROM school_personnel;
