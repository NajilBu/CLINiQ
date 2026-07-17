CREATE DATABASE IF NOT EXISTS cliniq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cliniq;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS appointment_availability_blocks;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS passport_access_logs;
DROP TABLE IF EXISTS referrals;
DROP TABLE IF EXISTS visit_treatment_dispensings;
DROP TABLE IF EXISTS inventory_loans;
DROP TABLE IF EXISTS inventory_items;
DROP TABLE IF EXISTS incident_reports;
DROP TABLE IF EXISTS nurse_alerts;
DROP TABLE IF EXISTS ape_activity_logs;
DROP TABLE IF EXISTS ape_records;
DROP TABLE IF EXISTS visit_treatment_entries;
DROP TABLE IF EXISTS clinic_visits;
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','doctor','nurse','staff','it_expert') NOT NULL DEFAULT 'staff',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE system_settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value MEDIUMTEXT NOT NULL,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE patients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_number VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  first_name VARCHAR(80) NOT NULL,
  middle_name VARCHAR(80) NULL,
  last_name VARCHAR(80) NOT NULL,
  birthdate DATE NULL,
  sex VARCHAR(20) NULL,
  course_section VARCHAR(120) NULL,
  blood_type VARCHAR(10) NULL,
  allergies TEXT NULL,
  existing_conditions TEXT NULL,
  emergency_instructions TEXT NULL,
  guardian_name VARCHAR(120) NULL,
  guardian_contact VARCHAR(50) NULL,
  emergency_token CHAR(64) NOT NULL UNIQUE,
  token_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE clinic_visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  visit_datetime DATETIME NOT NULL,
  chief_complaint VARCHAR(255) NOT NULL,
  symptoms TEXT NULL,
  temperature DECIMAL(4,1) NULL,
  blood_pressure VARCHAR(20) NULL,
  pulse_rate INT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Unaddressed',
  visit_purpose VARCHAR(80) NULL,
  visit_source VARCHAR(80) NOT NULL DEFAULT 'Staff Recorded',
  action_taken TEXT NULL,
  recorded_by INT NULL,
  attended_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  FOREIGN KEY (attended_by) REFERENCES users(id)
);

CREATE TABLE visit_treatment_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  symptoms_note TEXT NULL,
  diagnosis TEXT NULL,
  management_treatment TEXT NULL,
  referral_type VARCHAR(120) NULL,
  remarks TEXT NULL,
  amendment_reason TEXT NULL,
  dispensed_inventory_item_id INT NULL,
  dispensed_quantity INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (visit_id) REFERENCES clinic_visits(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE ape_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  exam_date DATE NULL,
  document_type VARCHAR(80) NOT NULL DEFAULT 'APE Form',
  requirement_status VARCHAR(80) NOT NULL DEFAULT 'Not Checked',
  workflow_status VARCHAR(80) NOT NULL DEFAULT 'Submitted',
  document_path VARCHAR(255) NULL,
  extracted_text MEDIUMTEXT NULL,
  verification_status VARCHAR(80) NOT NULL DEFAULT 'Pending',
  verified_by INT NULL,
  appointment_datetime DATETIME NULL,
  appointment_location VARCHAR(160) NULL,
  clearance_status VARCHAR(80) NOT NULL DEFAULT 'Pending',
  clinical_remarks TEXT NULL,
  student_visible_note TEXT NULL,
  follow_up_required TINYINT(1) NOT NULL DEFAULT 0,
  missing_items TEXT NULL,
  result_status VARCHAR(80) NOT NULL DEFAULT 'Pending',
  result_notes TEXT NULL,
  clearance_document_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (verified_by) REFERENCES users(id)
);

CREATE TABLE ape_activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ape_record_id INT NOT NULL,
  user_id INT NULL,
  action_label VARCHAR(160) NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ape_record_id) REFERENCES ape_records(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE nurse_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NULL,
  reporter_name VARCHAR(120) NOT NULL,
  reporter_role VARCHAR(80) NULL,
  location VARCHAR(160) NOT NULL,
  concern VARCHAR(255) NOT NULL,
  incident_type VARCHAR(120) NULL,
  details TEXT NULL,
  report_answers MEDIUMTEXT NULL,
  risk_level VARCHAR(40) NOT NULL DEFAULT 'Low',
  risk_score INT NOT NULL DEFAULT 0,
  risk_reasons TEXT NULL,
  response_guidance TEXT NULL,
  photo_path VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Pending',
  resolution_report TEXT NULL,
  resolved_by INT NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (resolved_by) REFERENCES users(id)
);

CREATE TABLE incident_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  emergency_token CHAR(64) NOT NULL,
  reporter_name VARCHAR(120) NULL,
  reporter_contact VARCHAR(80) NULL,
  location VARCHAR(160) NOT NULL,
  notes TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'New',
  reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  acknowledged_at DATETIME NULL,
  FOREIGN KEY (patient_id) REFERENCES patients(id)
);

CREATE TABLE inventory_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_name VARCHAR(160) NOT NULL,
  category VARCHAR(80) NULL,
  quantity INT NOT NULL DEFAULT 0,
  unit VARCHAR(40) NOT NULL DEFAULT 'pcs',
  reorder_level INT NOT NULL DEFAULT 0,
  expiration_date DATE NULL,
  archived_at DATETIME NULL,
  archived_reason VARCHAR(255) NULL,
  archived_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE inventory_loans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  borrower_name VARCHAR(160) NOT NULL,
  borrower_identifier VARCHAR(80) NULL,
  borrowed_quantity INT NOT NULL DEFAULT 1,
  borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at DATETIME NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Borrowed',
  return_condition VARCHAR(40) NULL,
  return_notes TEXT NULL,
  returned_at DATETIME NULL,
  borrowed_by INT NULL,
  returned_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  FOREIGN KEY (borrowed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE visit_treatment_dispensings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  treatment_entry_id INT NOT NULL,
  visit_id INT NOT NULL,
  inventory_item_id INT NOT NULL,
  item_type VARCHAR(40) NOT NULL DEFAULT 'Medicine',
  quantity INT NOT NULL,
  inventory_loan_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (treatment_entry_id) REFERENCES visit_treatment_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (visit_id) REFERENCES clinic_visits(id) ON DELETE CASCADE,
  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  FOREIGN KEY (inventory_loan_id) REFERENCES inventory_loans(id) ON DELETE SET NULL
);

CREATE TABLE referrals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  referral_date DATE NOT NULL,
  referred_to VARCHAR(160) NOT NULL,
  reason TEXT NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id)
);

CREATE TABLE passport_access_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id)
);

CREATE TABLE appointments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  appointment_datetime DATETIME NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Pending',
  notes TEXT NULL,
  cancellation_reason TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id)
);

CREATE TABLE appointment_availability_blocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  block_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_appointment_blocks_date (block_date),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO users (id, name, email, password_hash, role)
VALUES
(1, 'System Administrator', 'admin@cliniq.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
(2, 'Dr. Maria Santos', 'maria.santos@cliniq.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'doctor'),
(3, 'Dr. Carlo Reyes', 'carlo.reyes@cliniq.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'doctor'),
(4, 'Nurse Elena Cruz', 'elena.cruz@cliniq.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nurse'),
(5, 'Clinic Staff Liza Manalo', 'liza.manalo@cliniq.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff'),
(6, 'IT Support', 'it@cliniq.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'it_expert');

-- Demo / dummy data for fresh local installs.
-- Student numbers follow the CLINiQ format: 00-00000.
-- Staff demo login password: password.
-- Student portal demo login for seeded students: 123123.
INSERT INTO patients (
  id, student_number, password_hash, first_name, middle_name, last_name, birthdate, sex, course_section,
  blood_type, allergies, existing_conditions, guardian_name, guardian_contact, emergency_token
) VALUES
(1, '26-01024', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Sofia', 'L.', 'Bautista', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Female', 'BS Psychology A', 'O+', 'None', 'None reported.', 'Lorna Bautista', '0917-204-1188', SHA2('cliniq-demo-26-01024', 256)),
(2, '26-01041', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Rhea', 'C.', 'Ilagan', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Female', 'BS Nursing A', 'A+', 'Penicillin', 'None reported.', 'Marites Ilagan', '0918-337-9021', SHA2('cliniq-demo-26-01041', 256)),
(3, '26-01058', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Marco', 'T.', 'Villanueva', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Male', 'BSIT C', 'B+', 'Dust mites', 'History of mild asthma; carries rescue inhaler.', 'Ramon Villanueva', '0920-818-4432', SHA2('cliniq-demo-26-01058', 256)),
(4, '26-01073', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Chloe', 'V.', 'Mendoza', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Female', 'BS Biology A', 'AB+', 'None', 'None reported.', 'Carina Mendoza', '0916-552-0114', SHA2('cliniq-demo-26-01073', 256)),
(5, '26-01089', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Daniel', 'P.', 'Reyes', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Male', 'BSEd English B', 'O-', 'Seafood', 'Previous allergic reaction to seafood.', 'Paolo Reyes', '0919-214-7710', SHA2('cliniq-demo-26-01089', 256)),
(6, '26-01102', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Jessa', 'M.', 'Ocampo', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Female', 'BSBA Marketing D', 'A-', 'None', 'None reported.', 'Joy Ocampo', '0995-310-2248', SHA2('cliniq-demo-26-01102', 256)),
(7, '26-01119', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Kevin', 'R.', 'Navarro', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Male', 'BS Criminology A', 'B-', 'None', 'None reported.', 'Katrina Navarro', '0917-998-6612', SHA2('cliniq-demo-26-01119', 256)),
(8, '26-01136', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Alyssa', 'D.', 'Santos', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Female', 'BSCS B', 'O+', 'Latex', 'None reported.', 'Diana Santos', '0927-430-1195', SHA2('cliniq-demo-26-01136', 256)),
(9, '26-01155', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Miguel', 'A.', 'Flores', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Male', 'BS Accountancy A', 'A+', 'None', 'None reported.', 'Anton Flores', '0918-781-4403', SHA2('cliniq-demo-26-01155', 256)),
(10, '26-01178', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Bianca', 'R.', 'Garcia', DATE_SUB(CURDATE(), INTERVAL 18 YEAR), 'Female', 'BSTM C', 'B+', 'Pollen', 'Seasonal allergic rhinitis.', 'Riza Garcia', '0916-773-9004', SHA2('cliniq-demo-26-01178', 256)),
(11, '23-00211', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Justine Angelo', NULL, 'Faustino', '2004-08-15', 'Male', 'BSIT D', 'O+', 'None', 'None reported.', 'Maria Faustino', '0917-230-0211', SHA2('cliniq-23-00211', 256)),
(12, '23-00274', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Jan Alain', NULL, 'Cainglet', '2004-05-22', 'Male', 'BSIT D', 'A+', 'None', 'Occasional gastritis symptoms.', 'Analyn Cainglet', '0917-230-0274', SHA2('cliniq-23-00274', 256)),
(13, '23-00262', '$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq', 'Najil', NULL, 'Bumacod', '2004-11-03', 'Male', 'BSIT D', 'B+', 'Dust mites', 'Seasonal allergic rhinitis.', 'Nadia Bumacod', '0917-230-0262', SHA2('cliniq-23-00262', 256));

INSERT INTO inventory_items (id, item_name, category, quantity, unit, reorder_level, expiration_date, archived_at, archived_reason, archived_by)
VALUES
(1, 'Paracetamol 500mg', 'Analgesic', 86, 'tabs', 100, DATE_ADD(CURDATE(), INTERVAL 11 MONTH), NULL, NULL, NULL),
(2, 'Cetirizine 10mg', 'Antihistamine', 18, 'tabs', 40, DATE_ADD(CURDATE(), INTERVAL 20 DAY), NULL, NULL, NULL),
(3, 'Oral Rehydration Salts', 'Electrolyte', 24, 'sachets', 30, DATE_ADD(CURDATE(), INTERVAL 14 MONTH), NULL, NULL, NULL),
(4, 'Salbutamol Nebule 2.5mg', 'Respiratory', 9, 'nebules', 20, DATE_ADD(CURDATE(), INTERVAL 5 MONTH), NULL, NULL, NULL),
(5, 'Ibuprofen 200mg', 'Analgesic', 55, 'tabs', 30, DATE_ADD(CURDATE(), INTERVAL 9 MONTH), NULL, NULL, NULL),
(6, 'Amoxicillin 500mg', 'Antibiotic', 36, 'capsules', 25, DATE_ADD(CURDATE(), INTERVAL 7 MONTH), NULL, NULL, NULL),
(7, 'Digital Thermometer', 'Equipment', 3, 'units', 1, NULL, NULL, NULL, NULL),
(8, 'Pulse Oximeter', 'Equipment', 2, 'units', 1, NULL, NULL, NULL, NULL),
(9, 'Wheelchair', 'Equipment', 1, 'unit', 1, NULL, NULL, NULL, NULL),
(10, 'Ice Packs', 'Equipment', 3, 'pcs', 5, NULL, NULL, NULL, NULL),
(11, 'Expired Ibuprofen 200mg', 'Analgesic', 4, 'tabs', 30, DATE_SUB(CURDATE(), INTERVAL 2 MONTH), NOW(), 'Expired demo batch', 1);

INSERT INTO inventory_loans (
  id, item_id, borrower_name, borrower_identifier, borrowed_quantity, borrowed_at, due_at,
  status, return_condition, return_notes, returned_at, borrowed_by, returned_by
) VALUES
(1, 10, 'Marco Villanueva', '26-01058', 1, CONCAT(CURDATE(), ' 09:15:00'), CONCAT(CURDATE(), ' 17:00:00'), 'Borrowed', NULL, NULL, NULL, 4, NULL),
(2, 7, 'Jan Alain Cainglet', '23-00274', 1, CONCAT(CURDATE(), ' 12:40:00'), CONCAT(CURDATE(), ' 17:00:00'), 'Borrowed', NULL, NULL, NULL, 5, NULL);

INSERT INTO clinic_visits (
  id, patient_id, visit_datetime, chief_complaint, symptoms, temperature, blood_pressure, pulse_rate,
  status, visit_purpose, visit_source, action_taken, recorded_by, attended_by
) VALUES
(1, 1, CONCAT(CURDATE(), ' 08:10:00'), 'Fever with sore throat', 'Temperature 38.2 C, throat pain, mild body weakness', 38.2, '112/74', 92, 'Completed', 'Medical Consult', 'Staff Recorded', 'Given Paracetamol 500mg, advised oral fluids, mask use, and sent home with guardian notification.', 1, 1),
(2, 3, CONCAT(CURDATE(), ' 08:55:00'), 'Wheezing after PE class', 'Shortness of breath, audible wheeze, no chest pain', 37.0, '118/78', 104, 'Active', 'Emergency', 'Staff Recorded', 'Nebulized Salbutamol given, monitored for 45 minutes, advised follow-up if symptoms recur.', 1, 1),
(3, 7, CONCAT(CURDATE(), ' 09:40:00'), 'Right ankle sprain', 'Pain and swelling after basketball activity', 36.8, '120/80', 84, 'Completed', 'Wound Care', 'Staff Recorded', 'Cold compress applied, elastic bandage used, advised rest and elevation.', 1, 1),
(4, 8, CONCAT(CURDATE(), ' 10:25:00'), 'Migraine episode', 'Headache with light sensitivity, no vomiting', 36.9, '110/70', 76, 'Active', 'Health Monitoring', 'Staff Recorded', 'Rested in observation area, hydration encouraged, parent informed.', 1, 1),
(5, 5, CONCAT(CURDATE(), ' 11:15:00'), 'Seafood allergy concern', 'Itchy lips and scattered hives after lunch', 37.1, '116/72', 88, 'Unaddressed', 'Medical Consult', 'Self Logbook', 'Visitor/patient self-registration. Awaiting clinic assessment.', NULL, NULL),
(6, 9, CONCAT(CURDATE(), ' 13:05:00'), 'Minor hand laceration', 'Small cut from laboratory glassware, bleeding controlled', 36.7, '118/76', 78, 'Completed', 'Wound Care', 'Staff Recorded', 'Wound cleaned, gauze dressing applied, advised return for dressing check.', 1, 1),
(7, 10, CONCAT(CURDATE(), ' 14:20:00'), 'Allergic rhinitis flare-up', 'Sneezing, watery eyes, nasal congestion', 36.6, '108/68', 74, 'Completed', 'Medical Consult', 'Staff Recorded', 'Cetirizine provided, advised avoiding dusty storage room.', 1, 1),
(8, 11, CONCAT(CURDATE(), ' 15:10:00'), 'Headache and eye strain', 'Mild headache after extended computer laboratory work', 36.8, '118/76', 78, 'Completed', 'Medical Consult', 'Staff Recorded', 'Rest, hydration, Paracetamol, and screen-break guidance provided.', 2, 2),
(9, 12, CONCAT(CURDATE(), ' 12:35:00'), 'Stomach discomfort', 'Mild abdominal discomfort after lunch, no vomiting', 36.7, '116/74', 80, 'Completed', 'Medical Consult', 'Staff Recorded', 'ORS provided, temperature checked, advised light meal and hydration.', 3, 3),
(10, 13, CONCAT(CURDATE(), ' 16:05:00'), 'Allergic rhinitis flare-up', 'Sneezing, itchy eyes, nasal congestion after dusty classroom exposure', 36.6, '110/70', 74, 'Active', 'Medical Consult', 'Staff Recorded', 'Cetirizine provided and patient kept for short observation.', 4, 4);

INSERT INTO visit_treatment_entries (
  id, visit_id, symptoms_note, diagnosis, management_treatment, referral_type, remarks, amendment_reason,
  dispensed_inventory_item_id, dispensed_quantity, created_by, created_at
) VALUES
(1, 2, 'Wheezing improved after nebulization; oxygen saturation stable.', 'Exercise-induced asthma symptoms', 'Nebulized Salbutamol, rest, and observation.', 'None', 'Advise pulmonary follow-up if recurrence occurs.', 'Initial treatment record for active emergency visit.', 4, 1, 1, CONCAT(CURDATE(), ' 09:20:00')),
(2, 1, 'Fever persisted but patient was stable before dismissal.', 'Acute febrile illness', 'Paracetamol 500mg and oral fluids.', 'None', 'Guardian notified. Return if fever persists beyond 48 hours.', 'Follow-up note after observation.', 1, 1, 1, CONCAT(CURDATE(), ' 08:45:00')),
(3, 8, 'Mild headache; vital signs within normal limits.', 'Tension headache and digital eye strain', 'Rested in clinic, hydrated, given Paracetamol and Cetirizine as appropriate.', 'None', 'Return if symptoms persist or worsen.', 'Named student sample treatment.', 1, 1, 2, CONCAT(CURDATE(), ' 15:25:00')),
(4, 9, 'Mild abdominal discomfort; temperature normal.', 'Gastritis-like stomach discomfort', 'ORS provided and advised hydration and light meals.', 'None', 'Monitor for vomiting, fever, or worsening abdominal pain.', 'Named student sample treatment.', 3, 1, 3, CONCAT(CURDATE(), ' 12:50:00')),
(5, 10, 'Allergic rhinitis symptoms after dusty classroom exposure.', 'Allergic rhinitis flare-up', 'Cetirizine provided and thermometer loaned for observation.', 'None', 'Avoid dusty rooms and return if breathing difficulty develops.', 'Named student sample treatment.', 2, 1, 4, CONCAT(CURDATE(), ' 16:20:00'));

INSERT INTO visit_treatment_dispensings (
  id, treatment_entry_id, visit_id, inventory_item_id, item_type, quantity, inventory_loan_id
) VALUES
(1, 1, 2, 4, 'Medicine', 1, NULL),
(2, 2, 1, 1, 'Medicine', 1, NULL),
(3, 3, 8, 1, 'Medicine', 1, NULL),
(4, 3, 8, 2, 'Medicine', 1, NULL),
(5, 4, 9, 3, 'Medicine', 1, NULL),
(6, 5, 10, 2, 'Medicine', 1, NULL),
(7, 5, 10, 7, 'Equipment', 1, 2);

INSERT INTO appointments (id, patient_id, appointment_datetime, purpose, status, notes)
VALUES
(1, 9, CONCAT(CURDATE(), ' 09:30:00'), 'Wound dressing re-check', 'Pending', 'Check hand laceration dressing before afternoon laboratory class.'),
(2, 3, CONCAT(CURDATE(), ' 10:30:00'), 'Asthma follow-up assessment', 'Scheduled', 'Review breathing status after morning PE-related wheezing.'),
(3, 4, CONCAT(CURDATE(), ' 13:00:00'), 'APE hard-copy document review', 'Scheduled', 'Review UHS medical record, consent form, and lab request form.'),
(4, 5, CONCAT(CURDATE(), ' 14:00:00'), 'Food allergy counseling', 'Pending', 'Discuss canteen exposure, medication instructions, and emergency warning signs.'),
(5, 6, CONCAT(CURDATE(), ' 15:00:00'), 'APE online submission review', 'Scheduled', 'Confirm uploaded APE files match checked hard copies.'),
(6, 11, DATE_ADD(CONCAT(CURDATE(), ' 09:00:00'), INTERVAL 1 DAY), 'Eye strain follow-up', 'Scheduled', 'Check Justine after computer laboratory headache complaint.'),
(7, 12, DATE_ADD(CONCAT(CURDATE(), ' 10:00:00'), INTERVAL 1 DAY), 'Stomach discomfort follow-up', 'Pending', 'Confirm Jan Alain has no recurring abdominal discomfort.'),
(8, 13, DATE_ADD(CONCAT(CURDATE(), ' 11:00:00'), INTERVAL 1 DAY), 'Allergy monitoring', 'Scheduled', 'Review Najil after allergic rhinitis flare-up.');

INSERT INTO appointment_availability_blocks (id, block_date, start_time, end_time, reason, created_by)
VALUES
(1, DATE_ADD(CURDATE(), INTERVAL 2 DAY), NULL, NULL, 'Clinic team campus health audit', 1),
(2, DATE_ADD(CURDATE(), INTERVAL 5 DAY), '10:00:00', '12:00:00', 'Staff meeting and inventory check', 1);

INSERT INTO nurse_alerts (
  id, patient_id, reporter_name, reporter_role, location, concern, incident_type, details, report_answers,
  risk_level, risk_score, risk_reasons, response_guidance, photo_path, status, resolution_report, resolved_by, resolved_at, created_at, updated_at
) VALUES
(1, 3, 'Coach Marvin Dela Pena', 'PE Instructor', 'Gymnasium court', 'Student experiencing asthma symptoms', 'Breathing difficulty', 'Emergency passport incident report submitted by PE instructor. Photos attached: 1.', 'Incident type: Breathing difficulty\nObserved condition: Dizzy or weak\nBreathing: Wheezing\nBleeding: None observed\nPain level: 4-6 - Moderate pain\nMobility: Needs assistance\nReporter notes: Student reported tightness of chest after shuttle run. Clinic assistance requested immediately.', 'High', 9, 'Incident type involves breathing difficulty (+4)\nReporter observed dizziness or weakness (+1)\nWheezing reported (+3)\nStudent needs assistance moving (+1)', 'Urgent nurse response needed. Go to the reported location, check vital signs, give appropriate first aid, monitor closely, and prepare referral if symptoms worsen.', 'uploads/incidents/demo-asthma-alert.jpg', 'Pending', NULL, NULL, NULL, CONCAT(CURDATE(), ' 08:48:00'), CONCAT(CURDATE(), ' 08:48:00')),
(2, 1, 'Ms. Teresa Mendoza', 'Library Staff', 'Library second floor', 'Student with fever and weakness', 'Fever or illness', 'Student looked pale and requested help after feeling dizzy while studying.', 'Incident type: Fever or illness\nObserved condition: Dizzy or weak\nBreathing: Normal\nBleeding: None observed\nPain level: 1-3 - Mild pain\nMobility: Can walk\nReporter notes: Student looked pale and requested help after feeling dizzy while studying.', 'Moderate', 2, 'Incident type involves illness symptoms (+1)\nReporter observed dizziness or weakness (+1)', 'Prompt clinic assessment needed. Assist the student to the clinic when safe, provide first aid, observe symptoms, and document the response.', NULL, 'Pending', NULL, NULL, NULL, CONCAT(CURDATE(), ' 09:05:00'), CONCAT(CURDATE(), ' 09:05:00')),
(3, 9, 'Mr. Allan Cruz', 'Laboratory Technician', 'Science Laboratory 2', 'Minor glassware cut reported', 'Bleeding or wound', 'Student sustained a small hand cut while cleaning lab materials; bleeding controlled before clinic visit.', 'Incident type: Bleeding or wound\nObserved condition: Awake and responsive\nBreathing: Normal\nBleeding: Minor bleeding\nPain level: 1-3 - Mild pain\nMobility: Can walk\nReporter notes: Student sustained a small hand cut while cleaning lab materials; bleeding controlled before clinic visit.', 'Moderate', 4, 'Incident type involves bleeding or wound care (+3)\nMinor bleeding reported (+1)', 'Prompt clinic assessment needed. Assist the student to the clinic when safe, provide first aid, observe symptoms, and document the response.', NULL, 'Resolved', 'Assessed and cleaned wound in clinic. No further incident report needed.', 4, CONCAT(CURDATE(), ' 13:20:00'), CONCAT(CURDATE(), ' 13:00:00'), CONCAT(CURDATE(), ' 13:20:00')),
(4, 13, 'Prof. Laarni Ramos', 'Faculty', 'Computer Laboratory 4', 'Najil Bumacod has persistent allergy symptoms', 'Fever or illness', 'Student reported sneezing, itchy eyes, and discomfort after dusty classroom exposure.', 'Incident type: Fever or illness\nObserved condition: Awake and responsive\nBreathing: Normal\nBleeding: None observed\nPain level: 1-3 - Mild pain\nMobility: Can walk\nReporter notes: Student requested clinic assistance after dusty classroom exposure.', 'Low', 1, 'Incident type involves illness symptoms (+1)', 'Clinic assessment recommended. Escort the student to the clinic if symptoms continue and monitor for breathing difficulty.', NULL, 'Pending', NULL, NULL, NULL, CONCAT(CURDATE(), ' 16:00:00'), CONCAT(CURDATE(), ' 16:00:00')),
(5, 11, 'Prof. Ramon Cruz', 'Faculty', 'Computer Laboratory 3', 'Justine reported headache during laboratory class', 'Other concern', 'Student was escorted to the clinic for assessment and improved after rest.', 'Incident type: Other concern\nObserved condition: Awake and responsive\nBreathing: Normal\nBleeding: None observed\nPain level: 1-3 - Mild pain\nMobility: Can walk\nReporter notes: Headache after extended computer laboratory work.', 'Low', 0, 'No urgent incident indicators were detected from the submitted answers.', 'Routine clinic assessment and documentation recommended.', NULL, 'Resolved', 'Assessed in clinic; symptoms improved after rest and hydration.', 2, CONCAT(CURDATE(), ' 15:40:00'), CONCAT(CURDATE(), ' 15:05:00'), CONCAT(CURDATE(), ' 15:40:00'));

INSERT INTO ape_records (
  id, patient_id, exam_date, document_type, requirement_status, workflow_status, document_path,
  verification_status, verified_by, appointment_datetime, appointment_location, clearance_status,
  clinical_remarks, student_visible_note, follow_up_required, missing_items, result_status, result_notes,
  clearance_document_path, created_at, updated_at
) VALUES
(1, 4, CURDATE(), 'APE Form', 'Not Checked', 'Registered', NULL, 'Pending', NULL, NULL, NULL, 'Pending', 'UHS Medical Record and Dental Record pending hard-copy review.', 'Document review needed before student upload.', 0, 'UHS Medical Record and Dental Record pending hard-copy review.', 'Pending', NULL, NULL, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 6, CURDATE(), 'APE Form', 'Pre-Verified', 'Submitted', '/uploads/ape/26-01102-ape-bundle.pdf', 'Pending', NULL, NULL, NULL, 'Pending', 'Online files uploaded; verify against checked hard copies.', 'Consent, lab request, medical, dental, and referral forms uploaded.', 0, 'Online files uploaded; verify against checked hard copies.', 'Pending', NULL, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, 1, CURDATE(), 'APE Form', 'Needs Correction', 'Submitted', NULL, 'Needs Correction', 1, NULL, NULL, 'Pending', 'Lab request form lacks physician signature.', 'Please return with signed lab request form before online submission.', 0, 'Hard-copy requirements need correction.', 'Pending', NULL, NULL, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4, 3, CURDATE(), 'APE Form', 'Pre-Verified', 'Follow-up Required', '/uploads/ape/26-01058-ape-bundle.pdf', 'Verified', 1, NULL, NULL, 'For Follow-up', 'History of asthma noted during APE review. Needs pulmonary clearance after school clinic observation.', 'Submit pulmonary clearance or treatment note after follow-up consultation.', 1, 'Pulmonary clearance required.', 'With Finding', 'Follow-up requirement tracked by clinic.', NULL, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 5, CURDATE(), 'APE Form', 'Pre-Verified', 'Follow-up Required', '/uploads/ape/26-01089-ape-bundle.pdf', 'Verified', 1, NULL, NULL, 'Submitted', 'Food allergy history documented. Student submitted allergist clearance for review.', 'Clearance submitted; wait for clinic approval.', 1, 'Allergy clearance waiting for approval.', 'With Finding', 'Follow-up requirement tracked by clinic.', NULL, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(6, 8, CURDATE(), 'APE Form', 'Pre-Verified', 'Cleared', '/uploads/ape/26-01136-ape-bundle.pdf', 'Verified', 1, NULL, NULL, 'Cleared', 'No significant findings. Fit to study.', 'APE completed and archived.', 0, NULL, 'Fit to Proceed', 'Cleared for academic activities.', NULL, DATE_SUB(NOW(), INTERVAL 1 DAY), NOW()),
(7, 11, CURDATE(), 'APE Form', 'Pre-Verified', 'Cleared', '/uploads/ape/23-00211-ape-bundle.pdf', 'Verified', 2, NULL, NULL, 'Cleared', 'No significant findings. Fit to study.', 'APE requirements completed and cleared.', 0, NULL, 'Fit to Proceed', 'Cleared for academic activities.', NULL, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
(8, 12, CURDATE(), 'APE Form', 'Not Checked', 'Registered', NULL, 'Pending', NULL, NULL, NULL, 'Pending', 'Waiting for hard-copy APE documents.', 'Submit hard-copy APE documents for checking.', 0, 'Medical record and lab request form pending.', 'Pending', NULL, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(9, 13, CURDATE(), 'APE Form', 'Pre-Verified', 'Follow-up Required', '/uploads/ape/23-00262-ape-bundle.pdf', 'Verified', 4, NULL, NULL, 'For Follow-up', 'Allergic rhinitis history noted. Needs symptom monitoring.', 'Follow up with clinic if allergy symptoms persist.', 1, 'Allergy symptom monitoring required.', 'With Finding', 'Follow-up requirement tracked by clinic.', NULL, DATE_SUB(NOW(), INTERVAL 2 DAY), NOW());

INSERT INTO ape_activity_logs (id, ape_record_id, user_id, action_label, notes, created_at)
VALUES
(1, 1, 1, 'Dashboard demo status prepared', 'Document review needed before student upload.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 2, 1, 'Dashboard demo status prepared', 'Online files uploaded; verify against checked hard copies.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, 3, 1, 'Dashboard demo status prepared', 'Hard-copy requirements need correction.', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4, 4, 1, 'Dashboard demo status prepared', 'Pulmonary clearance required.', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 5, 1, 'Dashboard demo status prepared', 'Allergy clearance waiting for approval.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(6, 6, 1, 'Dashboard demo status prepared', 'APE completed and archived.', NOW()),
(7, 7, 2, 'Named student APE completed', 'Justine Angelo Faustino cleared.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(8, 8, 5, 'Named student APE registered', 'Jan Alain Cainglet waiting for requirements.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(9, 9, 4, 'Named student APE follow-up', 'Najil Bumacod needs allergy monitoring.', NOW());

INSERT INTO referrals (id, patient_id, referral_date, referred_to, reason, status)
VALUES
(1, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'City Health Office - Pulmonary Clinic', 'Asthma symptoms during PE; student advised pulmonary clearance for APE follow-up.', 'Pending'),
(2, 5, CURDATE(), 'Allergy and Immunology Clinic', 'Food allergy history and recent hives after canteen exposure.', 'Pending'),
(3, 7, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Partner Diagnostic Center', 'Right ankle sprain; X-ray advised only if swelling worsens within 24 hours.', 'Completed'),
(4, 12, CURDATE(), 'University Guidance and Wellness Office', 'Jan Alain requested wellness support resources after repeated stomach discomfort during exams.', 'Pending'),
(5, 13, CURDATE(), 'Allergy and Immunology Clinic', 'Najil has recurring allergic rhinitis symptoms after dust exposure.', 'Pending');

INSERT INTO passport_access_logs (id, patient_id, ip_address, user_agent, accessed_at)
VALUES
(1, 3, '127.0.0.1', 'CLINiQ Demo Browser', CONCAT(CURDATE(), ' 08:50:00')),
(2, 1, '127.0.0.1', 'CLINiQ Demo Browser', CONCAT(CURDATE(), ' 09:08:00')),
(3, 5, '127.0.0.1', 'CLINiQ Demo Browser', CONCAT(CURDATE(), ' 11:10:00')),
(4, 11, '127.0.0.1', 'CLINiQ Demo Browser', CONCAT(CURDATE(), ' 15:08:00')),
(5, 12, '127.0.0.1', 'CLINiQ Demo Browser', CONCAT(CURDATE(), ' 12:34:00')),
(6, 13, '127.0.0.1', 'CLINiQ Demo Browser', CONCAT(CURDATE(), ' 16:02:00'));
