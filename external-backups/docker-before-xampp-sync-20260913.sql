/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.4.13-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: Cliniq_db
-- ------------------------------------------------------
-- Server version	11.4.13-MariaDB-ubu2404

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint(20) unsigned NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `account_status` enum('inactive','active','suspended') NOT NULL DEFAULT 'inactive',
  `activated_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  KEY `idx_accounts_status` (`account_status`),
  CONSTRAINT `accounts_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_activity_logs`
--

DROP TABLE IF EXISTS `ape_activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ape_activity_logs` (
  `activity_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ape_id` bigint(20) unsigned NOT NULL,
  `performed_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(160) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  KEY `idx_ape_activity_logs_timeline` (`ape_id`,`created_at`),
  KEY `idx_ape_activity_logs_performed_by` (`performed_by_person_id`),
  CONSTRAINT `fk_ape_activity_logs_ape` FOREIGN KEY (`ape_id`) REFERENCES `ape_records` (`ape_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ape_activity_logs_performed_by` FOREIGN KEY (`performed_by_person_id`) REFERENCES `people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_activity_logs`
--

LOCK TABLES `ape_activity_logs` WRITE;
/*!40000 ALTER TABLE `ape_activity_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `ape_activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_cycles`
--

DROP TABLE IF EXISTS `ape_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ape_cycles` (
  `ape_cycle_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(20) NOT NULL,
  `compliance_start` date NOT NULL,
  `compliance_end` date NOT NULL,
  `exam_schedule_date` date DEFAULT NULL,
  `status` enum('Active','Closed','Archived') NOT NULL DEFAULT 'Active',
  `started_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closed_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `archived_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `active_cycle_slot` tinyint(4) GENERATED ALWAYS AS (case when `status` = 'Active' then 1 else NULL end) STORED,
  PRIMARY KEY (`ape_cycle_id`),
  UNIQUE KEY `uq_ape_cycles_academic_year` (`academic_year`),
  UNIQUE KEY `uq_ape_cycles_one_active` (`active_cycle_slot`),
  KEY `idx_ape_cycles_status_started` (`status`,`started_at`),
  KEY `idx_ape_cycles_started_by` (`started_by_person_id`),
  KEY `idx_ape_cycles_closed_by` (`closed_by_person_id`),
  KEY `idx_ape_cycles_archived_by` (`archived_by_person_id`),
  CONSTRAINT `fk_ape_cycles_archived_by` FOREIGN KEY (`archived_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ape_cycles_closed_by` FOREIGN KEY (`closed_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ape_cycles_started_by` FOREIGN KEY (`started_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_ape_cycles_dates` CHECK (`compliance_start` <= `compliance_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_cycles`
--

LOCK TABLES `ape_cycles` WRITE;
/*!40000 ALTER TABLE `ape_cycles` DISABLE KEYS */;
/*!40000 ALTER TABLE `ape_cycles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_documents`
--

DROP TABLE IF EXISTS `ape_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ape_documents` (
  `document_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ape_id` bigint(20) unsigned NOT NULL,
  `document_type` varchar(120) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `verification_status` enum('Pending','Verified','Needs Correction') NOT NULL DEFAULT 'Pending',
  `uploaded_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `verified_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`document_id`),
  KEY `idx_ape_documents_type` (`ape_id`,`document_type`),
  KEY `idx_ape_documents_status` (`verification_status`),
  KEY `idx_ape_documents_uploaded_by` (`uploaded_by_person_id`),
  KEY `idx_ape_documents_verified_by` (`verified_by_person_id`),
  CONSTRAINT `fk_ape_documents_ape` FOREIGN KEY (`ape_id`) REFERENCES `ape_records` (`ape_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ape_documents_uploaded_by` FOREIGN KEY (`uploaded_by_person_id`) REFERENCES `people` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ape_documents_verified_by` FOREIGN KEY (`verified_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_documents`
--

LOCK TABLES `ape_documents` WRITE;
/*!40000 ALTER TABLE `ape_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `ape_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_findings`
--

DROP TABLE IF EXISTS `ape_findings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ape_findings` (
  `finding_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ape_id` bigint(20) unsigned NOT NULL,
  `finding_type` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `result_status` enum('Normal','With Finding','Referred') NOT NULL DEFAULT 'With Finding',
  `follow_up_required` tinyint(1) NOT NULL DEFAULT 0,
  `recorded_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`finding_id`),
  UNIQUE KEY `uq_ape_findings_ape` (`ape_id`),
  KEY `idx_ape_findings_type` (`ape_id`,`finding_type`),
  KEY `idx_ape_findings_result` (`result_status`),
  KEY `idx_ape_findings_recorded_by` (`recorded_by_person_id`),
  CONSTRAINT `fk_ape_findings_ape` FOREIGN KEY (`ape_id`) REFERENCES `ape_records` (`ape_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ape_findings_recorded_by` FOREIGN KEY (`recorded_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_findings`
--

LOCK TABLES `ape_findings` WRITE;
/*!40000 ALTER TABLE `ape_findings` DISABLE KEYS */;
/*!40000 ALTER TABLE `ape_findings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_records`
--

DROP TABLE IF EXISTS `ape_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ape_records` (
  `ape_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ape_cycle_id` bigint(20) unsigned DEFAULT NULL,
  `schedule_batch_id` bigint(20) unsigned DEFAULT NULL,
  `patient_id` bigint(20) unsigned NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `exam_date` date DEFAULT NULL,
  `appointment_id` bigint(20) unsigned DEFAULT NULL,
  `requirement_status` varchar(80) NOT NULL DEFAULT 'Not Checked',
  `requirements_saved_at` datetime DEFAULT NULL,
  `workflow_status` varchar(80) NOT NULL DEFAULT 'Submitted',
  `clearance_status` varchar(80) NOT NULL DEFAULT 'Pending',
  `follow_up_required` tinyint(1) NOT NULL DEFAULT 0,
  `follow_up_due_date` date DEFAULT NULL,
  `follow_up_due_time` time DEFAULT NULL,
  `clinical_remarks` text DEFAULT NULL,
  `patient_visible_note` text DEFAULT NULL,
  `patient_height_cm` decimal(5,2) DEFAULT NULL,
  `patient_weight_kg` decimal(5,2) DEFAULT NULL,
  `patient_bmi` decimal(5,2) DEFAULT NULL,
  `patient_temperature` decimal(4,1) DEFAULT NULL,
  `patient_blood_pressure` varchar(20) DEFAULT NULL,
  `patient_pulse_rate` smallint(5) unsigned DEFAULT NULL,
  `patient_vitals_status` enum('Not Started','Confirmed') NOT NULL DEFAULT 'Not Started',
  `patient_vitals_confirmed_at` datetime DEFAULT NULL,
  `reviewed_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ape_id`),
  UNIQUE KEY `uq_ape_records_patient_year` (`patient_id`,`academic_year`),
  UNIQUE KEY `uq_ape_records_appointment` (`appointment_id`),
  KEY `idx_ape_records_workflow` (`workflow_status`),
  KEY `idx_ape_records_clearance` (`clearance_status`),
  KEY `idx_ape_records_cycle` (`ape_cycle_id`),
  KEY `idx_ape_records_schedule_batch` (`schedule_batch_id`),
  KEY `idx_ape_records_reviewed_by` (`reviewed_by_person_id`),
  CONSTRAINT `fk_ape_records_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ape_records_cycle` FOREIGN KEY (`ape_cycle_id`) REFERENCES `ape_cycles` (`ape_cycle_id`),
  CONSTRAINT `fk_ape_records_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`),
  CONSTRAINT `fk_ape_records_reviewed_by` FOREIGN KEY (`reviewed_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ape_records_schedule_batch` FOREIGN KEY (`schedule_batch_id`) REFERENCES `ape_schedule_batches` (`batch_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_records`
--

LOCK TABLES `ape_records` WRITE;
/*!40000 ALTER TABLE `ape_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `ape_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_requirements`
--

DROP TABLE IF EXISTS `ape_requirements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ape_requirements` (
  `requirement_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ape_id` bigint(20) unsigned NOT NULL,
  `requirement_name` varchar(160) NOT NULL,
  `upload_group` enum('initial','follow_up') DEFAULT NULL,
  `upload_due_date` date DEFAULT NULL,
  `status` enum('Missing','Submitted','Verified','Needs Correction') NOT NULL DEFAULT 'Missing',
  `remarks` text DEFAULT NULL,
  `checked_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`requirement_id`),
  UNIQUE KEY `uq_ape_requirement_name` (`ape_id`,`requirement_name`),
  KEY `idx_ape_requirements_status` (`status`),
  KEY `idx_ape_requirements_checked_by` (`checked_by_person_id`),
  CONSTRAINT `fk_ape_requirements_ape` FOREIGN KEY (`ape_id`) REFERENCES `ape_records` (`ape_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ape_requirements_checked_by` FOREIGN KEY (`checked_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_requirements`
--

LOCK TABLES `ape_requirements` WRITE;
/*!40000 ALTER TABLE `ape_requirements` DISABLE KEYS */;
/*!40000 ALTER TABLE `ape_requirements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_schedule_batches`
--

DROP TABLE IF EXISTS `ape_schedule_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ape_schedule_batches` (
  `batch_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ape_cycle_id` bigint(20) unsigned NOT NULL,
  `batch_name` varchar(120) NOT NULL,
  `patient_category` enum('Student','Faculty','School Personnel') NOT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `capacity` smallint(5) unsigned NOT NULL,
  `status` enum('Scheduled','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
  `created_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`batch_id`),
  UNIQUE KEY `uq_ape_batch_name` (`ape_cycle_id`,`batch_name`),
  KEY `fk_ape_batches_created_by` (`created_by_person_id`),
  KEY `idx_ape_batches_schedule` (`ape_cycle_id`,`schedule_date`,`start_time`),
  KEY `idx_ape_batches_category` (`ape_cycle_id`,`patient_category`,`status`),
  CONSTRAINT `fk_ape_batches_created_by` FOREIGN KEY (`created_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ape_batches_cycle` FOREIGN KEY (`ape_cycle_id`) REFERENCES `ape_cycles` (`ape_cycle_id`),
  CONSTRAINT `chk_ape_batch_time` CHECK (`start_time` < `end_time`),
  CONSTRAINT `chk_ape_batch_capacity` CHECK (`capacity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_schedule_batches`
--

LOCK TABLES `ape_schedule_batches` WRITE;
/*!40000 ALTER TABLE `ape_schedule_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `ape_schedule_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_availability_blocks`
--

DROP TABLE IF EXISTS `appointment_availability_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_availability_blocks` (
  `availability_block_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `block_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`availability_block_id`),
  KEY `idx_appointment_blocks_date` (`block_date`),
  KEY `idx_appointment_blocks_created_by` (`created_by_person_id`),
  CONSTRAINT `fk_appointment_blocks_created_by` FOREIGN KEY (`created_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_appointment_block_time` CHECK (`start_time` is null and `end_time` is null or `start_time` is not null and `end_time` is not null and `start_time` < `end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_availability_blocks`
--

LOCK TABLES `appointment_availability_blocks` WRITE;
/*!40000 ALTER TABLE `appointment_availability_blocks` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_availability_blocks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointments` (
  `appointment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `appointment_datetime` datetime NOT NULL,
  `purpose` varchar(255) NOT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'Pending',
  `request_source` enum('Patient Portal','Clinic Staff') NOT NULL DEFAULT 'Patient Portal',
  `notes` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_by` enum('Patient','Clinic') DEFAULT NULL,
  `reviewed_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reserved_slot` datetime GENERATED ALWAYS AS (case when `status` in ('Pending','Scheduled') then `appointment_datetime` else NULL end) STORED,
  PRIMARY KEY (`appointment_id`),
  UNIQUE KEY `uq_appointments_reserved_slot` (`reserved_slot`),
  KEY `idx_appointments_patient_datetime` (`patient_id`,`appointment_datetime`),
  KEY `idx_appointments_status_datetime` (`status`,`appointment_datetime`),
  KEY `idx_appointments_datetime` (`appointment_datetime`),
  KEY `idx_appointments_reviewed_by` (`reviewed_by_person_id`),
  CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`),
  CONSTRAINT `fk_appointments_reviewed_by` FOREIGN KEY (`reviewed_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_person_id` bigint(20) unsigned DEFAULT NULL,
  `actor_type` varchar(30) NOT NULL DEFAULT 'system',
  `module` varchar(60) NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(60) DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `outcome` varchar(30) NOT NULL DEFAULT 'success',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_created` (`created_at`),
  KEY `idx_audit_logs_module_action` (`module`,`action`),
  KEY `idx_audit_logs_actor` (`actor_person_id`,`created_at`),
  KEY `idx_audit_logs_target` (`target_type`,`target_id`,`created_at`),
  CONSTRAINT `fk_audit_logs_actor` FOREIGN KEY (`actor_person_id`) REFERENCES `people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES
(1,NULL,'guest','auth','staff_login_failed','account',NULL,'failure','{\"id_number\":\"STAFF-0001\"}','172.20.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 10:29:22'),
(2,NULL,'guest','auth','staff_login_failed','account',NULL,'failure','{\"id_number\":\"STAFF-0001\"}','172.20.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 10:29:24'),
(3,NULL,'guest','auth','staff_login_failed','account',NULL,'failure','{\"id_number\":\"STAFF-0001\"}','172.20.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 10:29:26'),
(4,NULL,'guest','auth','staff_login_failed','account',NULL,'failure','{\"id_number\":\"STAFF-0001\"}','172.20.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 10:33:58');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clinic_feedback`
--

DROP TABLE IF EXISTS `clinic_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinic_feedback` (
  `feedback_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `visit_id` bigint(20) unsigned NOT NULL,
  `survey_version` varchar(20) NOT NULL DEFAULT 'servperf-v1',
  `consent_version` varchar(20) NOT NULL DEFAULT 'visit-linked-v1',
  `service_type` varchar(160) NOT NULL,
  `service_other` varchar(160) DEFAULT NULL,
  `academic_term` varchar(80) NOT NULL,
  `term_other` varchar(80) DEFAULT NULL,
  `year_level` varchar(80) NOT NULL,
  `year_other` varchar(80) DEFAULT NULL,
  `program` varchar(160) NOT NULL,
  `comments` text NOT NULL,
  `ratings_json` longtext NOT NULL,
  `tangibles` decimal(9,6) NOT NULL,
  `reliability` decimal(9,6) NOT NULL,
  `responsiveness` decimal(9,6) NOT NULL,
  `assurance` decimal(9,6) NOT NULL,
  `empathy` decimal(9,6) NOT NULL,
  `overall` decimal(9,6) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`feedback_id`),
  UNIQUE KEY `uq_clinic_feedback_visit` (`visit_id`),
  KEY `idx_clinic_feedback_submitted` (`submitted_at`),
  CONSTRAINT `fk_clinic_feedback_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`),
  CONSTRAINT `chk_clinic_feedback_scores` CHECK (`tangibles` between 1 and 7 and `reliability` between 1 and 7 and `responsiveness` between 1 and 7 and `assurance` between 1 and 7 and `empathy` between 1 and 7 and `overall` between 1 and 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clinic_feedback`
--

LOCK TABLES `clinic_feedback` WRITE;
/*!40000 ALTER TABLE `clinic_feedback` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinic_feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clinic_staff`
--

DROP TABLE IF EXISTS `clinic_staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinic_staff` (
  `person_id` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `staff_role` enum('admin','doctor','nurse','staff','it_expert') NOT NULL DEFAULT 'staff',
  `position_title` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`person_id`),
  KEY `idx_clinic_staff_department` (`department_id`),
  KEY `idx_clinic_staff_role` (`staff_role`),
  CONSTRAINT `clinic_staff_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clinic_staff_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clinic_staff`
--

LOCK TABLES `clinic_staff` WRITE;
/*!40000 ALTER TABLE `clinic_staff` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinic_staff` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_code` varchar(20) NOT NULL,
  `department_name` varchar(160) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `department_code` (`department_code`),
  KEY `idx_departments_name` (`department_name`),
  KEY `idx_departments_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES
(1,'CCS','College of Computer Studies',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(2,'CBA','College of Business Administration',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(3,'COE','College of Education',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(4,'CAS','College of Arts and Sciences',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(5,'CON','College of Nursing',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(6,'UHS','University Health Services',1,'2026-09-13 10:05:51','2026-09-13 10:05:51');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `equipment_loans`
--

DROP TABLE IF EXISTS `equipment_loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_loans` (
  `loan_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) unsigned NOT NULL,
  `borrower_person_id` bigint(20) unsigned NOT NULL,
  `visit_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `borrowed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `due_at` datetime DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `status` enum('Borrowed','Returned','Overdue','Cancelled') NOT NULL DEFAULT 'Borrowed',
  `released_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `received_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`loan_id`),
  KEY `idx_equipment_loans_item_status` (`item_id`,`status`),
  KEY `idx_equipment_loans_borrower_status` (`borrower_person_id`,`status`),
  KEY `idx_equipment_loans_visit` (`visit_id`),
  KEY `idx_equipment_loans_due` (`status`,`due_at`),
  KEY `idx_equipment_loans_released_by` (`released_by_person_id`),
  KEY `idx_equipment_loans_received_by` (`received_by_person_id`),
  CONSTRAINT `fk_equipment_loans_borrower` FOREIGN KEY (`borrower_person_id`) REFERENCES `patients` (`person_id`),
  CONSTRAINT `fk_equipment_loans_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_equipment_loans_received_by` FOREIGN KEY (`received_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_equipment_loans_released_by` FOREIGN KEY (`released_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_equipment_loans_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_equipment_loans_quantity` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `equipment_loans`
--

LOCK TABLES `equipment_loans` WRITE;
/*!40000 ALTER TABLE `equipment_loans` DISABLE KEYS */;
/*!40000 ALTER TABLE `equipment_loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `incident_reports`
--

DROP TABLE IF EXISTS `incident_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `emergency_token` char(64) NOT NULL,
  `reporter_name` varchar(120) DEFAULT NULL,
  `reporter_contact` varchar(80) DEFAULT NULL,
  `location` varchar(160) NOT NULL,
  `notes` text DEFAULT NULL,
  `reporter_risk_rating` varchar(20) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'New',
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `acknowledged_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_incident_reports_patient_reported` (`patient_id`,`reported_at`),
  KEY `idx_incident_reports_status_reported` (`status`,`reported_at`),
  CONSTRAINT `fk_incident_reports_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `incident_reports`
--

LOCK TABLES `incident_reports` WRITE;
/*!40000 ALTER TABLE `incident_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `incident_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_items`
--

DROP TABLE IF EXISTS `inventory_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_items` (
  `item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_name` varchar(160) NOT NULL,
  `item_type` enum('Medicine','Equipment') NOT NULL,
  `description` text DEFAULT NULL,
  `unit` varchar(40) NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 0,
  `reorder_level` int(10) unsigned NOT NULL DEFAULT 0,
  `expiration_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `idx_inventory_items_type_active` (`item_type`,`is_active`),
  KEY `idx_inventory_items_stock` (`quantity`,`reorder_level`),
  KEY `idx_inventory_items_expiration` (`expiration_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_items`
--

LOCK TABLES `inventory_items` WRITE;
/*!40000 ALTER TABLE `inventory_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_transactions`
--

DROP TABLE IF EXISTS `inventory_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_transactions` (
  `transaction_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) unsigned NOT NULL,
  `transaction_type` enum('Stock In','Dispensed','Loaned','Returned','Adjustment','Expired','Damaged') NOT NULL,
  `quantity_change` int(11) NOT NULL,
  `balance_after` int(10) unsigned NOT NULL,
  `dispensing_id` bigint(20) unsigned DEFAULT NULL,
  `loan_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `performed_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  KEY `idx_inventory_transactions_item_created` (`item_id`,`created_at`),
  KEY `idx_inventory_transactions_type_created` (`transaction_type`,`created_at`),
  KEY `idx_inventory_transactions_dispensing` (`dispensing_id`),
  KEY `idx_inventory_transactions_loan` (`loan_id`),
  KEY `idx_inventory_transactions_staff` (`performed_by_person_id`),
  CONSTRAINT `fk_inventory_transactions_dispensing` FOREIGN KEY (`dispensing_id`) REFERENCES `medicine_dispensings` (`dispensing_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_transactions_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_inventory_transactions_loan` FOREIGN KEY (`loan_id`) REFERENCES `equipment_loans` (`loan_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_transactions_staff` FOREIGN KEY (`performed_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_inventory_transactions_change` CHECK (`quantity_change` <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_transactions`
--

LOCK TABLES `inventory_transactions` WRITE;
/*!40000 ALTER TABLE `inventory_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `portal` varchar(32) NOT NULL,
  `identifier_hash` char(64) NOT NULL,
  `ip_hash` char(64) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_identifier` (`portal`,`identifier_hash`,`attempted_at`),
  KEY `idx_login_attempts_ip` (`portal`,`ip_hash`,`attempted_at`),
  KEY `idx_login_attempts_cleanup` (`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
INSERT INTO `login_attempts` VALUES
(1,'staff','f288f8096aac2ecea2bc3d366b25e1111b457fcb804a9e26d1967910ac9f751f','6695b928b4240fe537e02aa8b55fff752ab6338352d345e16b0e006d30631ca5','2026-09-13 10:29:22'),
(2,'staff','f288f8096aac2ecea2bc3d366b25e1111b457fcb804a9e26d1967910ac9f751f','6695b928b4240fe537e02aa8b55fff752ab6338352d345e16b0e006d30631ca5','2026-09-13 10:29:24'),
(3,'staff','f288f8096aac2ecea2bc3d366b25e1111b457fcb804a9e26d1967910ac9f751f','6695b928b4240fe537e02aa8b55fff752ab6338352d345e16b0e006d30631ca5','2026-09-13 10:29:26'),
(4,'staff','f288f8096aac2ecea2bc3d366b25e1111b457fcb804a9e26d1967910ac9f751f','6695b928b4240fe537e02aa8b55fff752ab6338352d345e16b0e006d30631ca5','2026-09-13 10:33:58');
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `medicine_dispensings`
--

DROP TABLE IF EXISTS `medicine_dispensings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `medicine_dispensings` (
  `dispensing_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `remarks` text DEFAULT NULL,
  `dispensed_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `dispensed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`dispensing_id`),
  KEY `idx_medicine_dispensings_entry` (`entry_id`),
  KEY `idx_medicine_dispensings_item_date` (`item_id`,`dispensed_at`),
  KEY `idx_medicine_dispensings_staff` (`dispensed_by_person_id`),
  CONSTRAINT `fk_medicine_dispensings_entry` FOREIGN KEY (`entry_id`) REFERENCES `visit_entries` (`entry_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_medicine_dispensings_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  CONSTRAINT `fk_medicine_dispensings_staff` FOREIGN KEY (`dispensed_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_medicine_dispensings_quantity` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `medicine_dispensings`
--

LOCK TABLES `medicine_dispensings` WRITE;
/*!40000 ALTER TABLE `medicine_dispensings` DISABLE KEYS */;
/*!40000 ALTER TABLE `medicine_dispensings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nurse_alerts`
--

DROP TABLE IF EXISTS `nurse_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nurse_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned DEFAULT NULL,
  `reporter_name` varchar(120) NOT NULL,
  `reporter_role` varchar(80) DEFAULT NULL,
  `location` varchar(160) NOT NULL,
  `concern` varchar(255) NOT NULL,
  `incident_type` varchar(120) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `report_answers` mediumtext DEFAULT NULL,
  `reporter_risk_rating` varchar(20) DEFAULT NULL,
  `risk_level` varchar(40) NOT NULL DEFAULT 'Not assessed',
  `risk_score` int(11) NOT NULL DEFAULT 0,
  `risk_reasons` text DEFAULT NULL,
  `response_guidance` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'Pending',
  `resolution_report` text DEFAULT NULL,
  `resolved_by` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_nurse_alerts_resolved_by` (`resolved_by`),
  KEY `idx_nurse_alerts_status_created` (`status`,`created_at`),
  KEY `idx_nurse_alerts_risk_created` (`risk_level`,`risk_score`,`created_at`),
  KEY `idx_nurse_alerts_patient` (`patient_id`),
  CONSTRAINT `fk_nurse_alerts_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nurse_alerts_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nurse_alerts`
--

LOCK TABLES `nurse_alerts` WRITE;
/*!40000 ALTER TABLE `nurse_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `nurse_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passport_access_logs`
--

DROP TABLE IF EXISTS `passport_access_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `passport_access_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `viewer_person_id` bigint(20) unsigned DEFAULT NULL,
  `audit_log_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `viewer_person_id` (`viewer_person_id`),
  KEY `idx_passport_access_logs_patient_accessed` (`patient_id`,`accessed_at`),
  CONSTRAINT `fk_passport_access_logs_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`) ON DELETE CASCADE,
  CONSTRAINT `passport_access_logs_ibfk_1` FOREIGN KEY (`viewer_person_id`) REFERENCES `people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passport_access_logs`
--

LOCK TABLES `passport_access_logs` WRITE;
/*!40000 ALTER TABLE `passport_access_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `passport_access_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patient_password_resets`
--

DROP TABLE IF EXISTS `patient_password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_password_resets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `requested_ip` varchar(45) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_patient_password_resets_account_created` (`account_id`,`created_at`),
  KEY `idx_patient_password_resets_expiry` (`expires_at`,`used_at`),
  CONSTRAINT `fk_patient_password_resets_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patient_password_resets`
--

LOCK TABLES `patient_password_resets` WRITE;
/*!40000 ALTER TABLE `patient_password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patients`
--

DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `patients` (
  `person_id` bigint(20) unsigned NOT NULL,
  `blood_type` varchar(10) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `existing_conditions` text DEFAULT NULL,
  `medications` text DEFAULT NULL,
  `emergency_instructions` text DEFAULT NULL,
  `guardian_or_contact_name` varchar(160) DEFAULT NULL,
  `guardian_or_contact_number` varchar(50) DEFAULT NULL,
  `guardian_relationship` varchar(80) DEFAULT NULL,
  `secondary_contact_number` varchar(32) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `bmi` decimal(4,1) DEFAULT NULL,
  `show_bmi_on_passport` tinyint(1) NOT NULL DEFAULT 1,
  `emergency_token` char(64) DEFAULT NULL,
  `token_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`person_id`),
  UNIQUE KEY `emergency_token` (`emergency_token`),
  CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patients`
--

LOCK TABLES `patients` WRITE;
/*!40000 ALTER TABLE `patients` DISABLE KEYS */;
/*!40000 ALTER TABLE `patients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `people`
--

DROP TABLE IF EXISTS `people`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `people` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_number` varchar(50) NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `sex` enum('Male','Female','Other') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_number` (`id_number`),
  KEY `idx_people_name` (`last_name`,`first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `people`
--

LOCK TABLES `people` WRITE;
/*!40000 ALTER TABLE `people` DISABLE KEYS */;
/*!40000 ALTER TABLE `people` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_uca1400_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`cliniq`@`%`*/ /*!50003 TRIGGER trg_people_create_inactive_account
AFTER INSERT ON people
FOR EACH ROW
BEGIN
  INSERT INTO accounts (person_id, account_status)
  VALUES (NEW.id, 'inactive');
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `programs`
--

DROP TABLE IF EXISTS `programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `programs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) unsigned NOT NULL,
  `program_code` varchar(20) NOT NULL,
  `program_name` varchar(200) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `program_code` (`program_code`),
  KEY `idx_programs_department` (`department_id`),
  KEY `idx_programs_name` (`program_name`),
  KEY `idx_programs_active` (`is_active`),
  CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `programs`
--

LOCK TABLES `programs` WRITE;
/*!40000 ALTER TABLE `programs` DISABLE KEYS */;
INSERT INTO `programs` VALUES
(1,1,'BSIT','Bachelor of Science in Information Technology',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(2,1,'BSCS','Bachelor of Science in Computer Science',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(3,2,'BSBA','Bachelor of Science in Business Administration',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(4,2,'BSA','Bachelor of Science in Accountancy',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(5,3,'BSED','Bachelor of Secondary Education',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(6,3,'BEED','Bachelor of Elementary Education',1,'2026-09-13 10:05:51','2026-09-13 10:05:51'),
(7,5,'BSN','Bachelor of Science in Nursing',1,'2026-09-13 10:05:51','2026-09-13 10:05:51');
/*!40000 ALTER TABLE `programs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `referrals`
--

DROP TABLE IF EXISTS `referrals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `referrals` (
  `referral_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_person_id` bigint(20) unsigned NOT NULL,
  `visit_id` bigint(20) unsigned DEFAULT NULL,
  `referred_to` varchar(160) NOT NULL,
  `reason` text NOT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'Completed',
  `referred_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `referral_date` date NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`referral_id`),
  KEY `idx_referrals_patient_date` (`patient_person_id`,`referral_date`),
  KEY `idx_referrals_visit` (`visit_id`),
  KEY `idx_referrals_status_date` (`status`,`referral_date`),
  KEY `idx_referrals_referred_by` (`referred_by_person_id`),
  CONSTRAINT `fk_referrals_patient` FOREIGN KEY (`patient_person_id`) REFERENCES `patients` (`person_id`),
  CONSTRAINT `fk_referrals_referred_by` FOREIGN KEY (`referred_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_referrals_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `referrals`
--

LOCK TABLES `referrals` WRITE;
/*!40000 ALTER TABLE `referrals` DISABLE KEYS */;
/*!40000 ALTER TABLE `referrals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `schema_migrations` (
  `migration` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `execution_ms` int(10) unsigned NOT NULL DEFAULT 0,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schema_migrations`
--

LOCK TABLES `schema_migrations` WRITE;
/*!40000 ALTER TABLE `schema_migrations` DISABLE KEYS */;
INSERT INTO `schema_migrations` VALUES
('20260731_add_departments_programs.sql','59341d5ede576355cd93078099736348945da2d09cbc92486add04129b869916',0,'2026-09-13 10:05:52'),
('20260731_normalize_program_department_references.sql','83795dac7fce9ea761dd8afd2012aea67934e03d286ab4c187fe8536f34b5c52',0,'2026-09-13 10:05:52'),
('20260731_remove_patient_allergies_conditions.sql','acdcec13abf6e8a268c36a12841781c715400235e04f77ebf98923fd391a0595',0,'2026-09-13 10:05:52'),
('20260803_create_visit_tables.sql','36867f448b46d112bc3b34d05f37b55556a81c70e39f9980708b405fe13ec143',0,'2026-09-13 10:05:52'),
('20260803_remove_faculty_school_personnel_office_columns.sql','488b182f122cbcdc2ad7c1fd3ba0875addd1ac240c760f250b85dbb184a6f7cc',0,'2026-09-13 10:05:52'),
('20260803_remove_redundant_profile_text_columns.sql','c64d3161263f2b8b99beab0253a851bb150383c419a909d2306916982fec62e4',0,'2026-09-13 10:05:52'),
('20260804_add_people_sex.sql','7876b87bd45d2c1c0583f8793f726b95ae6025ba66eecee55bb714aa945595cc',0,'2026-09-13 10:05:52'),
('20260804_backfill_staff_patient_profiles.sql','cb7570930eaa8f3435d3c933efbe79eb768a2e46300adc8a91f59b3e961568c5',0,'2026-09-13 10:05:52'),
('20260804_create_inventory_tables.sql','9778a893968a29dd69a75fb264680178ea9dd7ada246c9efa1cbe561dd63a738',0,'2026-09-13 10:05:52'),
('20260804_create_school_employees.sql','0be9fc9a1a1db7e6b1ffdb95db44ff5a5a6ad185ab3702b561ddd84bbe1a0e6f',0,'2026-09-13 10:05:52'),
('20260804_drop_legacy_school_employee_tables.sql','bd3b8fc6f5b5b9f9ad7f8e2e3cc81c937fa61448cad0cce3b6c23c8ab066f7cf',0,'2026-09-13 10:05:52'),
('20260805_add_visit_lifecycle_timestamps.sql','9e154d840893415a9d700431977b9b83abce25e315cab24b33e6df8db6ca499e',0,'2026-09-13 10:05:52'),
('20260805_create_referrals.sql','2963e5380265365e91eb7834cc2e8c882428b6f674bd0a9d76bf08e43dec1f12',0,'2026-09-13 10:05:52'),
('20260807_create_appointment_tables.sql','551ec9cf5daefab031ee0a3e8754192a0aa5f143ae39cd63a988ae015e8b95e1',0,'2026-09-13 10:05:52'),
('20260808_prevent_appointment_double_booking.sql','1d7011f35f9ee8db19a88dd000149be3a623d6aa8f0c150dbd7df5ba28b86548',0,'2026-09-13 10:05:52'),
('20260809_use_single_account_password_hash.sql','e286856a644d5576252faf30f8b300a51fa68d043013198ac5cce128ee87a6ea',0,'2026-09-13 10:05:52'),
('20260810_create_ape_tables.sql','1242d742bffde592e79dbfbdc15e3075ae0193f1555fd72e700f50d8138eb3b6',0,'2026-09-13 10:05:52'),
('20260811_create_ape_cycles.sql','353af571872915f26d915cfe9aa72abb54da7727b93d2eb234197dafb7bb6d2a',0,'2026-09-13 10:05:52'),
('20260812_add_patient_ape_vitals.sql','a0b505cc4e5cd4cf7d1341512aac2104f16c7d2d87764b563ee7fdf0fa894949',0,'2026-09-13 10:05:52'),
('20260814_passport_alerts_cliniq_db.sql','f6763d517d9255727760ff069f5b5e10799682f9d7f8684359f67ab0d5a7060a',0,'2026-09-13 10:05:52'),
('20260814_settings_staff_cliniq_db.sql','e344258fbf0c4ea15b769436a170666038736bdd6f147f12b3befc37124b0a26',0,'2026-09-13 10:05:52'),
('20260827_add_appointment_for_confirmation.sql','f67923f7e8607c2e77466b2741a98716d74ed31c8440fbdb2faacf446d47d581',0,'2026-09-13 10:05:52'),
('20260830_enforce_one_ape_finding.sql','47a63980a106987657906e166f232057702e589985b3e214e3b4a11bfde60c16',0,'2026-09-13 10:05:52'),
('20260901_add_emergency_contact_details.sql','9cf8cf15568bd9c154a9b9fdec64a559a33ac8324470f21779ec8ada98e0c1da',0,'2026-09-13 10:05:52'),
('20260901_add_patient_password_resets.sql','bfb38911e953998f5b3b335fdb2e230b6eb0397f0a78b05c1b7863eec428ed1b',0,'2026-09-13 10:05:52'),
('20260902_create_ape_schedule_batches.sql','f01b331a650f3b5131a641edbc110bec18464e19e1edfe7a5707b249480e4a87',0,'2026-09-13 10:05:52'),
('20260903_ape_document_upload_groups.sql','5be7fde4f1fdb22f96e77fe23497523c573fd5e80089f2d7b11c8162dc78bbf3',0,'2026-09-13 10:05:52'),
('20260903_ape_requirement_lock_return_time.sql','d5e2ea2eb2fcee23d8867c54cdef950a9df997926cefa96472eece82e2d0ec75',0,'2026-09-13 10:05:52'),
('20260906_remove_inventory_item_codes.sql','8a18019fcb6ff962500a5e787488eb7ebfb0265d3f811b95805b07ddce13c6ec',0,'2026-09-13 10:05:52'),
('20260907_create_clinic_feedback.sql','a92bb0c045374f55c1f716e097f1c2f4e824e3beee9ad735b85373c15530cc89',0,'2026-09-13 10:05:52'),
('20260908_passport_access_audit_reporting.sql','e1625afb90949d1c13969e20943a5029ac4ecf19a4d7da0b547ece0f302d7f8a',0,'2026-09-13 10:05:52'),
('20260909_create_login_attempts.sql','85850453d4bf181252d1bb0410533c7a75beebe14453defc9ee75f3797129912',56,'2026-09-13 10:05:52'),
('20260910_add_passport_bmi_visibility.sql','6e35c61702b87156c46e5a6eec19fa1766efbe3fb1cbe20baecc9fc92fd7913c',63,'2026-09-13 10:06:50'),
('20260910_add_profile_photos.sql','5420fc4e6ebefa06685e6a7cc8b829c8332acbce0e765eb2b28ab7626d40c1c6',60,'2026-09-13 10:06:50');
/*!40000 ALTER TABLE `schema_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_employees`
--

DROP TABLE IF EXISTS `school_employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_employees` (
  `person_id` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `role_classification` enum('Faculty','School Personnel') NOT NULL,
  `employment_type` varchar(80) DEFAULT NULL,
  `position_title` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`person_id`),
  KEY `idx_school_employees_department` (`department_id`),
  KEY `idx_school_employees_classification` (`role_classification`),
  CONSTRAINT `school_employees_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE,
  CONSTRAINT `school_employees_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_employees`
--

LOCK TABLES `school_employees` WRITE;
/*!40000 ALTER TABLE `school_employees` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `students` (
  `person_id` bigint(20) unsigned NOT NULL,
  `program_id` bigint(20) unsigned DEFAULT NULL,
  `year_level` varchar(40) DEFAULT NULL,
  `section` varchar(80) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`person_id`),
  KEY `idx_students_program` (`program_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE,
  CONSTRAINT `students_ibfk_2` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(120) NOT NULL,
  `setting_value` mediumtext NOT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `fk_system_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_system_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visit_entries`
--

DROP TABLE IF EXISTS `visit_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `visit_entries` (
  `entry_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `visit_id` bigint(20) unsigned NOT NULL,
  `diagnosis` text DEFAULT NULL,
  `symptoms` text DEFAULT NULL,
  `treatment` text DEFAULT NULL,
  `referral` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `amendment_reason` text DEFAULT NULL,
  `addressed_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`entry_id`),
  KEY `idx_visit_entries_visit_created` (`visit_id`,`created_at`),
  KEY `idx_visit_entries_addressed_by` (`addressed_by_person_id`),
  CONSTRAINT `fk_visit_entries_addressed_by` FOREIGN KEY (`addressed_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visit_entries_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visit_entries`
--

LOCK TABLES `visit_entries` WRITE;
/*!40000 ALTER TABLE `visit_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `visit_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visits`
--

DROP TABLE IF EXISTS `visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `visits` (
  `visit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_person_id` bigint(20) unsigned NOT NULL,
  `visit_datetime` datetime NOT NULL DEFAULT current_timestamp(),
  `addressed_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `chief_complaint` varchar(255) NOT NULL,
  `status` enum('Unaddressed','Active','Completed','Cancelled') NOT NULL DEFAULT 'Unaddressed',
  `visit_purpose` varchar(80) DEFAULT NULL,
  `visit_source` varchar(80) NOT NULL DEFAULT 'Staff Recorded',
  `action_taken` text DEFAULT NULL,
  `recorded_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `attended_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`visit_id`),
  KEY `idx_visits_patient_datetime` (`patient_person_id`,`visit_datetime`),
  KEY `idx_visits_status` (`status`),
  KEY `idx_visits_recorded_by` (`recorded_by_person_id`),
  KEY `idx_visits_attended_by` (`attended_by_person_id`),
  CONSTRAINT `fk_visits_attended_by` FOREIGN KEY (`attended_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_patient` FOREIGN KEY (`patient_person_id`) REFERENCES `patients` (`person_id`),
  CONSTRAINT `fk_visits_recorded_by` FOREIGN KEY (`recorded_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visits`
--

LOCK TABLES `visits` WRITE;
/*!40000 ALTER TABLE `visits` DISABLE KEYS */;
/*!40000 ALTER TABLE `visits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vital_signs`
--

DROP TABLE IF EXISTS `vital_signs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vital_signs` (
  `vital_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `visit_id` bigint(20) unsigned DEFAULT NULL,
  `patient_id` bigint(20) unsigned DEFAULT NULL,
  `entry_id` bigint(20) unsigned DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `blood_pressure` varchar(20) DEFAULT NULL,
  `pulse_rate` smallint(5) unsigned DEFAULT NULL,
  `measured_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `measured_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`vital_id`),
  KEY `idx_vital_signs_visit_measured` (`visit_id`,`measured_at`),
  KEY `idx_vital_signs_patient` (`patient_id`),
  KEY `idx_vital_signs_entry` (`entry_id`),
  KEY `idx_vital_signs_measured_by` (`measured_by_person_id`),
  CONSTRAINT `fk_vital_signs_entry` FOREIGN KEY (`entry_id`) REFERENCES `visit_entries` (`entry_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vital_signs_measured_by` FOREIGN KEY (`measured_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vital_signs_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vital_signs_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vital_signs`
--

LOCK TABLES `vital_signs` WRITE;
/*!40000 ALTER TABLE `vital_signs` DISABLE KEYS */;
/*!40000 ALTER TABLE `vital_signs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'Cliniq_db'
--

--
-- Dumping routines for database 'Cliniq_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-09-13 10:39:08
