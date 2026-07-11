CREATE DATABASE IF NOT EXISTS cliniq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cliniq;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','nurse','staff','it_expert') NOT NULL DEFAULT 'staff',
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
  first_name VARCHAR(80) NOT NULL,
  middle_name VARCHAR(80) NULL,
  last_name VARCHAR(80) NOT NULL,
  birthdate DATE NULL,
  sex ENUM('Male','Female','Other') NULL,
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
  risk_level ENUM('Low','Moderate','High','Critical') NOT NULL DEFAULT 'Low',
  risk_score INT NOT NULL DEFAULT 0,
  risk_reasons TEXT NULL,
  status ENUM('Unaddressed','Active','Completed','Cancelled') NOT NULL DEFAULT 'Unaddressed',
  visit_purpose VARCHAR(80) NULL,
  visit_source ENUM('Self Logbook','Staff Recorded','Nurse Emergency') NOT NULL DEFAULT 'Staff Recorded',
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
  batch_name VARCHAR(40) NULL,
  exam_date DATE NULL,
  document_type VARCHAR(80) NOT NULL DEFAULT 'APE Form',
  requirement_status ENUM('Not Checked','Pre-Verified','Needs Correction') NOT NULL DEFAULT 'Not Checked',
  workflow_status ENUM('Registered','Batch Assigned','Requirements Checked','Submitted','Reviewed','Scheduled','Exam Done','Follow-up Required','Cleared') NOT NULL DEFAULT 'Submitted',
  document_path VARCHAR(255) NULL,
  extracted_text MEDIUMTEXT NULL,
  verification_status ENUM('Pending','Verified','Needs Correction') NOT NULL DEFAULT 'Pending',
  verified_by INT NULL,
  appointment_datetime DATETIME NULL,
  appointment_location VARCHAR(160) NULL,
  clearance_status ENUM('Pending','For Follow-up','Submitted','Cleared') NOT NULL DEFAULT 'Pending',
  clinical_remarks TEXT NULL,
  student_visible_note TEXT NULL,
  follow_up_required TINYINT(1) NOT NULL DEFAULT 0,
  missing_items TEXT NULL,
  result_status ENUM('Pending','Completed','With Finding','Fit to Proceed') NOT NULL DEFAULT 'Pending',
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
  details TEXT NULL,
  photo_path VARCHAR(255) NULL,
  status ENUM('Pending','In Progress','Resolved','Cancelled') NOT NULL DEFAULT 'Pending',
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
  status ENUM('New','Acknowledged','Responding','Resolved','False Report') NOT NULL DEFAULT 'New',
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
  status ENUM('Borrowed','Returned','Lost') NOT NULL DEFAULT 'Borrowed',
  return_condition ENUM('Good','Defective','Lost') NULL,
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

CREATE TABLE referrals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  referral_date DATE NOT NULL,
  referred_to VARCHAR(160) NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('Pending','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
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
  status ENUM('Pending', 'Scheduled', 'Completed', 'Cancelled', 'No Show') NOT NULL DEFAULT 'Pending',
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id)
);

INSERT INTO users (name, email, password_hash, role)
VALUES (
  'System Administrator',
  'admin@cliniq.local',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'admin'
);
