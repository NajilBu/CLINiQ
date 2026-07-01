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
  action_taken TEXT NULL,
  recorded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE ape_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  exam_date DATE NULL,
  document_path VARCHAR(255) NULL,
  extracted_text MEDIUMTEXT NULL,
  verification_status ENUM('Pending','Verified','Needs Correction') NOT NULL DEFAULT 'Pending',
  verified_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (verified_by) REFERENCES users(id)
);

CREATE TABLE nurse_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NULL,
  reporter_name VARCHAR(120) NOT NULL,
  reporter_role VARCHAR(80) NULL,
  location VARCHAR(160) NOT NULL,
  concern VARCHAR(255) NOT NULL,
  details TEXT NULL,
  status ENUM('Pending','In Progress','Resolved','Cancelled') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

INSERT INTO users (name, email, password_hash, role)
VALUES (
  'System Administrator',
  'admin@cliniq.local',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'admin'
);
