-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: Cliniq_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint(20) unsigned NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `account_status` enum('inactive','active','suspended') NOT NULL DEFAULT 'inactive',
  `activated_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `email` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  KEY `idx_accounts_status` (`account_status`),
  CONSTRAINT `accounts_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
INSERT INTO `accounts` VALUES (15,17,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54','2026-08-30 23:26:11','2026-07-29 10:46:24','2026-08-30 15:26:11','bautista_sofia@plpasig.edu.ph'),(16,18,'$2y$10$V7l6Ypxyj5EwG0gMdyi/7.aNYRKLbInGRyo9KxDLA2cn0bOPCO5E.','active','2026-07-18 00:47:54','2026-08-14 17:45:50','2026-07-29 10:46:24','2026-08-19 14:16:21','ilagan_rhea@plpasig.edu.ph'),(17,19,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','villanueva_marco@plpasig.edu.ph'),(18,20,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','mendoza_chloe@plpasig.edu.ph'),(19,21,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','reyes_daniel@plpasig.edu.ph'),(20,22,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','ocampo_jessa@plpasig.edu.ph'),(21,23,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','navarro_kevin@plpasig.edu.ph'),(22,24,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','santos_alyssa@plpasig.edu.ph'),(23,25,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','flores_miguel@plpasig.edu.ph'),(24,26,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','garcia_bianca@plpasig.edu.ph'),(25,27,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','faustino_justineangelo@plpasig.edu.ph'),(26,28,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','cainglet_janalain@plpasig.edu.ph'),(27,29,'$2y$10$ERoGDNyV0hqUVQsKpKgXO.bDxEJJ9Q21TyIv9Fq41cGA3h/38m6hC','active','2026-07-18 00:47:54','2026-09-06 11:00:00','2026-07-29 10:46:24','2026-09-06 03:00:00','bumacod_najil@plpasig.edu.ph'),(28,32,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','active','2026-07-18 00:47:53','2026-09-08 18:33:54','2026-07-29 10:46:24','2026-09-08 10:33:54','administrator_system@plpasig.edu.ph'),(29,33,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','active','2026-07-18 00:47:53','2026-07-29 19:30:57','2026-07-29 10:46:24','2026-08-19 14:14:41','santos_maria@plpasig.edu.ph'),(30,34,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','active','2026-07-18 00:47:53',NULL,'2026-07-29 10:46:24','2026-08-19 14:14:41','reyes_carlo@plpasig.edu.ph'),(31,35,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','active','2026-07-18 00:47:53','2026-07-29 19:31:23','2026-07-29 10:46:24','2026-08-19 14:14:41','cruz_elena@plpasig.edu.ph'),(32,36,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','active','2026-07-18 00:47:53',NULL,'2026-07-29 10:46:24','2026-08-19 14:14:41','manalo_liza@plpasig.edu.ph'),(33,37,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','active','2026-07-18 00:47:53',NULL,'2026-07-29 10:46:24','2026-08-19 14:14:41','support_it@plpasig.edu.ph'),(34,38,'$2y$10$ueOmuGgHtFCt/JjeckBzAuVkMxA3jxIeSsHxKyu8Led.gf3iLOpOW','inactive',NULL,NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21','santos_maria@plpasig.edu.ph'),(51,55,'$2y$10$14ZjBASS.TQ1f.OgjLArnupWLvxMu3mhhlc3jxPfpM6XdIPzFkwSq','active','2026-07-29 20:33:31',NULL,'2026-07-29 12:32:44','2026-08-19 14:16:21','carlos_juan@plpasig.edu.ph'),(69,72,'$2y$10$1MVYdHwR0SjiYOnw.cb.BOEh7Srix5R/iUcPzQd2m8hzQaoYz9N6G','active','2026-08-19 23:09:34','2026-09-07 11:39:14','2026-08-19 15:09:02','2026-09-07 03:39:14',NULL);
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_activity_logs`
--

DROP TABLE IF EXISTS `ape_activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_activity_logs`
--

LOCK TABLES `ape_activity_logs` WRITE;
/*!40000 ALTER TABLE `ape_activity_logs` DISABLE KEYS */;
INSERT INTO `ape_activity_logs` VALUES (70,15,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),(71,23,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),(72,24,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),(73,25,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),(74,32,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),(77,25,29,'Confirmed patient-entered vitals and BMI','Patient completed the vitals profile before clinic examination.','2026-09-03 04:46:16'),(78,25,32,'Updated APE requirement','TB Cert: Missing','2026-09-03 04:46:39'),(79,25,32,'Recorded APE examination with document correction','Get Tb certs','2026-09-03 04:55:48'),(80,25,32,'Updated APE requirement','Lab Request Form: Missing','2026-09-03 04:57:21'),(81,25,32,'Updated APE requirement','UHS Consent Form: Missing','2026-09-03 05:04:16'),(82,25,32,'Updated APE requirement','UHS Medical Record: Missing','2026-09-03 05:04:20'),(83,25,32,'Updated APE requirement','UHS Dental Record: Missing','2026-09-03 05:04:22'),(84,25,32,'Updated APE requirement','Referral Form: Missing','2026-09-03 05:04:26'),(85,25,32,'Updated hard-copy document review','Follow-up documents: TB Cert. Get Tb certs','2026-09-03 05:18:22'),(86,25,NULL,'Reset saved examination','User-requested reset: cleared examination date and removed saved finding #17. Restored Batch Assigned workflow. Schedule, vitals, requirements, uploads, and notes preserved. Recovery backup created before reset.','2026-09-03 05:50:56'),(87,25,32,'Recorded APE examination and document review','APE Examination: With Finding. Follow-up documents: TB Cert. Kuha cert Return date: 2026-09-04','2026-09-03 06:17:36'),(88,25,29,'Uploaded APE documents','Lab Request Form, UHS Consent Form, UHS Medical Record, UHS Dental Record, Referral Form','2026-09-03 11:28:26'),(89,25,32,'Archived APE documents',NULL,'2026-09-03 11:29:20'),(90,25,29,'Uploaded APE documents','TB Cert','2026-09-03 11:32:15'),(91,25,32,'Opened returned documents for review',NULL,'2026-09-03 11:36:53'),(92,25,32,'Archived follow-up documents','TB Cert','2026-09-03 11:55:13'),(93,25,32,'Cleared patient after APE examination','Get Tb certs','2026-09-03 11:55:30');
/*!40000 ALTER TABLE `ape_activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_cycles`
--

DROP TABLE IF EXISTS `ape_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_cycles`
--

LOCK TABLES `ape_cycles` WRITE;
/*!40000 ALTER TABLE `ape_cycles` DISABLE KEYS */;
INSERT INTO `ape_cycles` VALUES (4,'2026-2027','2026-06-01','2027-05-31',NULL,'Active',32,'2026-08-11 13:20:59',NULL,NULL,NULL,NULL,1);
/*!40000 ALTER TABLE `ape_cycles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_documents`
--

DROP TABLE IF EXISTS `ape_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_documents`
--

LOCK TABLES `ape_documents` WRITE;
/*!40000 ALTER TABLE `ape_documents` DISABLE KEYS */;
INSERT INTO `ape_documents` VALUES (35,25,'Lab Request Form','92499a6d-0e43-4bae-b499-bc050479e432.jpg','uploads/ape/patient-ape_1788434906_ef431e41.jpg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),(36,25,'UHS Consent Form','68625641-85b0-434c-b6aa-a4c98209e7a8.jpg','uploads/ape/patient-ape_1788434906_0f300e4e.jpg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),(37,25,'UHS Medical Record','back.jpeg','uploads/ape/patient-ape_1788434906_2e596592.jpeg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),(38,25,'UHS Dental Record','front.jpeg','uploads/ape/patient-ape_1788434906_80224691.jpeg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),(39,25,'Referral Form','Assessment.jpg','uploads/ape/patient-ape_1788434906_fef2dceb.jpg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),(40,25,'TB Cert','survey.png','uploads/ape/patient-ape_1788435135_bd13591b.png','Verified',29,32,'2026-09-03 11:32:15','2026-09-03 19:55:13');
/*!40000 ALTER TABLE `ape_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_findings`
--

DROP TABLE IF EXISTS `ape_findings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_findings`
--

LOCK TABLES `ape_findings` WRITE;
/*!40000 ALTER TABLE `ape_findings` DISABLE KEYS */;
INSERT INTO `ape_findings` VALUES (18,25,'APE Examination','May sakit','With Finding',0,32,'2026-09-03 06:17:36');
/*!40000 ALTER TABLE `ape_findings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_records`
--

DROP TABLE IF EXISTS `ape_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  KEY `idx_ape_records_reviewed_by` (`reviewed_by_person_id`),
  KEY `idx_ape_records_cycle` (`ape_cycle_id`),
  KEY `idx_ape_records_schedule_batch` (`schedule_batch_id`),
  CONSTRAINT `fk_ape_records_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ape_records_cycle` FOREIGN KEY (`ape_cycle_id`) REFERENCES `ape_cycles` (`ape_cycle_id`),
  CONSTRAINT `fk_ape_records_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`),
  CONSTRAINT `fk_ape_records_reviewed_by` FOREIGN KEY (`reviewed_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ape_records_schedule_batch` FOREIGN KEY (`schedule_batch_id`) REFERENCES `ape_schedule_batches` (`batch_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_records`
--

LOCK TABLES `ape_records` WRITE;
/*!40000 ALTER TABLE `ape_records` DISABLE KEYS */;
INSERT INTO `ape_records` VALUES (13,4,NULL,17,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 07:50:29'),(14,4,NULL,18,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-30 01:27:25'),(15,4,1,19,'2026-2027',NULL,NULL,'Not Checked',NULL,'Batch Assigned','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 11:52:35'),(16,4,NULL,20,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(17,4,NULL,21,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(18,4,NULL,22,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(19,4,NULL,23,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(20,4,NULL,24,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(21,4,NULL,25,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(22,4,NULL,26,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(23,4,1,27,'2026-2027',NULL,NULL,'Not Checked',NULL,'Batch Assigned','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 11:52:35'),(24,4,1,28,'2026-2027',NULL,NULL,'Not Checked',NULL,'Batch Assigned','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 11:52:35'),(25,4,1,29,'2026-2027','2026-09-03',NULL,'Checked','2026-09-03 19:55:13','Cleared','Cleared',0,'2026-09-04',NULL,'Return after getting the cert','Get Tb certs',170.00,55.00,19.03,36.0,'120/80',75,'Confirmed','2026-09-03 12:46:16',32,'2026-08-11 13:20:59','2026-09-03 11:55:30'),(26,4,NULL,32,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(27,4,NULL,33,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(28,4,NULL,34,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(29,4,NULL,35,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(30,4,NULL,36,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(31,4,NULL,37,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),(32,4,1,55,'2026-2027',NULL,NULL,'Not Checked',NULL,'Batch Assigned','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 11:52:35'),(33,4,NULL,72,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-19 16:22:13','2026-09-02 07:50:29');
/*!40000 ALTER TABLE `ape_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_requirements`
--

DROP TABLE IF EXISTS `ape_requirements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=275 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_requirements`
--

LOCK TABLES `ape_requirements` WRITE;
/*!40000 ALTER TABLE `ape_requirements` DISABLE KEYS */;
INSERT INTO `ape_requirements` VALUES (147,13,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(148,14,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(149,15,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(150,16,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(151,17,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(152,18,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(153,19,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(154,20,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(155,21,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(156,22,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(157,23,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(158,24,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(159,25,'Lab Request Form','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),(160,26,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(161,27,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(162,28,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(163,29,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(164,30,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(165,31,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(166,32,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(167,33,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(168,13,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(169,14,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(170,15,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(171,16,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(172,17,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(173,18,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(174,19,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(175,20,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(176,21,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(177,22,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(178,23,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(179,24,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(180,25,'UHS Consent Form','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),(181,26,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(182,27,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(183,28,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(184,29,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(185,30,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(186,31,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(187,32,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(188,33,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(189,13,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(190,14,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(191,15,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(192,16,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(193,17,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(194,18,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(195,19,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(196,20,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(197,21,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(198,22,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(199,23,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(200,24,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(201,25,'UHS Medical Record','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),(202,26,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(203,27,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(204,28,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(205,29,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(206,30,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(207,31,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(208,32,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(209,33,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(210,13,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(211,14,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(212,15,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(213,16,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(214,17,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(215,18,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(216,19,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(217,20,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(218,21,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(219,22,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(220,23,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(221,24,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(222,25,'UHS Dental Record','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),(223,26,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(224,27,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(225,28,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(226,29,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(227,30,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(228,31,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(229,32,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(230,33,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(231,13,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(232,14,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(233,15,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(234,16,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(235,17,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(236,18,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(237,19,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(238,20,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(239,21,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(240,22,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(241,23,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(242,24,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(243,25,'Referral Form','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),(244,26,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(245,27,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(246,28,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(247,29,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(248,30,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(249,31,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(250,32,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(251,33,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),(274,25,'TB Cert','follow_up','2026-09-04','Verified','Kuha cert',32,'2026-09-03 19:55:13','2026-09-03 04:46:39','2026-09-03 11:55:13');
/*!40000 ALTER TABLE `ape_requirements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ape_schedule_batches`
--

DROP TABLE IF EXISTS `ape_schedule_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_schedule_batches`
--

LOCK TABLES `ape_schedule_batches` WRITE;
/*!40000 ALTER TABLE `ape_schedule_batches` DISABLE KEYS */;
INSERT INTO `ape_schedule_batches` VALUES (1,4,'BSIT','Student','2026-09-03','08:00:00','14:00:00',50,'Scheduled',32,'2026-09-02 11:52:35','2026-09-02 11:52:35');
/*!40000 ALTER TABLE `ape_schedule_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_availability_blocks`
--

DROP TABLE IF EXISTS `appointment_availability_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_availability_blocks`
--

LOCK TABLES `appointment_availability_blocks` WRITE;
/*!40000 ALTER TABLE `appointment_availability_blocks` DISABLE KEYS */;
INSERT INTO `appointment_availability_blocks` VALUES (3,'2026-09-08','08:00:00','09:00:00',NULL,32,'2026-09-06 02:30:15'),(4,'2026-09-08','09:00:00','10:00:00',NULL,32,'2026-09-06 02:30:15'),(5,'2026-09-08','10:00:00','11:00:00',NULL,32,'2026-09-06 02:30:15'),(6,'2026-09-08','11:00:00','12:00:00',NULL,32,'2026-09-06 02:30:15'),(7,'2026-09-08','12:00:00','13:00:00',NULL,32,'2026-09-06 02:30:15'),(8,'2026-09-09',NULL,NULL,NULL,32,'2026-09-06 02:34:23'),(9,'2026-09-15','10:00:00','11:00:00',NULL,32,'2026-09-06 03:16:48'),(10,'2026-09-15','11:00:00','12:00:00',NULL,32,'2026-09-06 03:16:48'),(11,'2026-09-16','08:00:00','09:00:00',NULL,32,'2026-09-06 03:16:48'),(12,'2026-09-16','09:00:00','10:00:00',NULL,32,'2026-09-06 03:16:48'),(13,'2026-09-16','10:00:00','11:00:00',NULL,32,'2026-09-06 03:16:48'),(14,'2026-09-10',NULL,NULL,NULL,32,'2026-09-06 14:35:42');
/*!40000 ALTER TABLE `appointment_availability_blocks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (7,17,'2026-08-16 10:00:00','Dental Consultation','Completed','Patient Portal','Patient requested this appointment through the patient portal. Awaiting clinic approval.',NULL,NULL,32,'2026-08-14 09:41:14','2026-08-27 04:15:33',NULL),(8,17,'2026-08-28 12:00:00','General Checkup','Completed','Patient Portal','Patient requested this appointment through the patient portal. Awaiting clinic approval.',NULL,NULL,32,'2026-08-27 04:14:45','2026-08-29 11:24:27',NULL),(9,72,'2026-09-01 15:00:00','General Checkup','Completed','Patient Portal','Patient requested this appointment through the patient portal. Awaiting clinic approval.',NULL,NULL,32,'2026-09-01 05:31:19','2026-09-02 07:05:08',NULL),(10,72,'2026-09-07 14:00:00','General Checkup','For Confirmation','Patient Portal','Patient requested this appointment through the patient portal. Awaiting clinic approval.',NULL,NULL,32,'2026-09-07 03:39:37','2026-09-07 10:29:54',NULL);
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  CONSTRAINT `fk_cliniq_audit_logs_actor` FOREIGN KEY (`actor_person_id`) REFERENCES `people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clinic_staff`
--

DROP TABLE IF EXISTS `clinic_staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clinic_staff` (
  `person_id` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `staff_role` enum('admin','doctor','nurse','staff','it_expert') NOT NULL DEFAULT 'staff',
  `position_title` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`person_id`),
  KEY `idx_clinic_staff_role` (`staff_role`),
  KEY `fk_clinic_staff_department` (`department_id`),
  CONSTRAINT `clinic_staff_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_clinic_staff_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clinic_staff`
--

LOCK TABLES `clinic_staff` WRITE;
/*!40000 ALTER TABLE `clinic_staff` DISABLE KEYS */;
INSERT INTO `clinic_staff` VALUES (32,10,'admin','System Administrator'),(33,10,'doctor','Dr. Maria Santos'),(34,10,'doctor','Dr. Carlo Reyes'),(35,10,'nurse','Nurse Elena Cruz'),(36,10,'staff','Clinic Staff Liza Manalo'),(37,10,'it_expert','IT Support');
/*!40000 ALTER TABLE `clinic_staff` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'CCS','College of Computer Studies',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(2,'CBA','College of Business Administration',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(3,'COE','College of Education',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(4,'CAS','College of Arts and Sciences',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(9,'CON','College of Nursing',1,'2026-07-31 03:21:21','2026-07-31 03:21:21'),(10,'UHS','University Health Services',1,'2026-07-31 03:21:21','2026-07-31 03:21:21');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `equipment_loans`
--

DROP TABLE IF EXISTS `equipment_loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `equipment_loans`
--

LOCK TABLES `equipment_loans` WRITE;
/*!40000 ALTER TABLE `equipment_loans` DISABLE KEYS */;
INSERT INTO `equipment_loans` VALUES (2,4,29,13,1,'2026-09-05 15:12:38','2026-09-05 16:00:00','2026-09-05 23:50:28','Returned',32,32,'Condition: Good','2026-09-05 07:12:38','2026-09-05 15:50:28'),(3,4,29,14,1,'2026-09-06 17:00:43','2026-09-07 09:00:00',NULL,'Borrowed',32,NULL,NULL,'2026-09-06 09:00:43','2026-09-06 09:00:43');
/*!40000 ALTER TABLE `equipment_loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `incident_reports`
--

DROP TABLE IF EXISTS `incident_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  CONSTRAINT `fk_cliniq_incident_reports_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_items`
--

LOCK TABLES `inventory_items` WRITE;
/*!40000 ALTER TABLE `inventory_items` DISABLE KEYS */;
INSERT INTO `inventory_items` VALUES (3,'Shabu','Medicine','Bawal yan','Sachet',33,10,'2026-09-30',0,'2026-08-05 05:26:41','2026-09-04 12:12:31'),(4,'Foil','Equipment','Pwede to','Sheet',9,1,NULL,1,'2026-08-05 05:41:46','2026-09-06 09:00:43'),(6,'GAMOT','Medicine',NULL,'pcs',40,5,'2027-10-04',1,'2026-09-04 12:34:09','2026-09-04 12:34:09'),(7,'Tylenol','Medicine','PANG HEADACHE','pcs',50,10,'2027-10-20',1,'2026-09-06 10:16:12','2026-09-06 10:16:12');
/*!40000 ALTER TABLE `inventory_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_transactions`
--

DROP TABLE IF EXISTS `inventory_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_transactions`
--

LOCK TABLES `inventory_transactions` WRITE;
/*!40000 ALTER TABLE `inventory_transactions` DISABLE KEYS */;
INSERT INTO `inventory_transactions` VALUES (5,3,'Stock In',20,20,NULL,NULL,'Initial inventory quantity',32,'2026-08-05 05:26:41'),(6,3,'Stock In',5,25,NULL,NULL,'Medicine restock',32,'2026-08-05 05:34:54'),(7,4,'Stock In',10,10,NULL,NULL,'Initial inventory quantity',32,'2026-08-05 05:41:46'),(8,3,'Dispensed',-2,23,4,NULL,'Dispensed during treatment entry #6',32,'2026-08-05 09:15:33'),(9,3,'Stock In',10,33,NULL,NULL,'Medicine restock',32,'2026-09-04 12:11:46'),(10,6,'Stock In',40,40,NULL,NULL,'Initial inventory quantity',32,'2026-09-04 12:34:09'),(11,4,'Loaned',-1,9,NULL,2,'Equipment issued during visit #13',32,'2026-09-05 07:12:38'),(12,4,'Returned',1,10,NULL,2,'Condition: Good',32,'2026-09-05 15:50:28'),(13,4,'Loaned',-1,9,NULL,3,'Equipment issued during visit #14',32,'2026-09-06 09:00:43'),(14,7,'Stock In',50,50,NULL,NULL,'Initial inventory quantity',32,'2026-09-06 10:16:12');
/*!40000 ALTER TABLE `inventory_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `medicine_dispensings`
--

DROP TABLE IF EXISTS `medicine_dispensings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `medicine_dispensings`
--

LOCK TABLES `medicine_dispensings` WRITE;
/*!40000 ALTER TABLE `medicine_dispensings` DISABLE KEYS */;
INSERT INTO `medicine_dispensings` VALUES (4,6,3,2,NULL,32,'2026-08-05 17:15:33');
/*!40000 ALTER TABLE `medicine_dispensings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nurse_alerts`
--

DROP TABLE IF EXISTS `nurse_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  KEY `fk_cliniq_nurse_alerts_resolved_by` (`resolved_by`),
  KEY `idx_nurse_alerts_status_created` (`status`,`created_at`),
  KEY `idx_nurse_alerts_risk_created` (`risk_level`,`risk_score`,`created_at`),
  KEY `idx_nurse_alerts_patient` (`patient_id`),
  CONSTRAINT `fk_cliniq_nurse_alerts_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cliniq_nurse_alerts_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `passport_access_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `viewer_person_id` bigint(20) unsigned DEFAULT NULL,
  `audit_log_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_passport_access_logs_patient_accessed` (`patient_id`,`accessed_at`),
  CONSTRAINT `fk_cliniq_passport_access_logs_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patient_password_resets`
--

LOCK TABLES `patient_password_resets` WRITE;
/*!40000 ALTER TABLE `patient_password_resets` DISABLE KEYS */;
INSERT INTO `patient_password_resets` VALUES (2,27,'eda65d6d295451ad4cc86fc0f4bb7dcb19464f6d3aeafd211e795535cea2e09e','::1','2026-09-01 18:00:37','2026-09-01 23:01:35','2026-09-01 15:00:37'),(3,27,'7cd0d383f22a4f70b9f342b4e2d013e8e0867476f75219fec73e96c9b1eb03d6','::1','2026-09-01 18:01:35','2026-09-01 23:04:50','2026-09-01 15:01:35'),(4,27,'74434851fd822b6bbe352d04e843c37658fe572c31e750b627351a7d683bf9ae','::1','2026-09-02 00:04:50','2026-09-01 23:05:42','2026-09-01 15:04:50'),(5,27,'c64a883c6be747e43752779a066ca5ec26f0fc72bd741332ea1f3a4fff793f28','::1','2026-09-03 13:44:58',NULL,'2026-09-03 04:44:58');
/*!40000 ALTER TABLE `patient_password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patients`
--

DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  `emergency_token` char(64) DEFAULT NULL,
  `token_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `height_cm` decimal(5,2) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `bmi` decimal(4,1) DEFAULT NULL,
  PRIMARY KEY (`person_id`),
  UNIQUE KEY `emergency_token` (`emergency_token`),
  CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patients`
--

LOCK TABLES `patients` WRITE;
/*!40000 ALTER TABLE `patients` DISABLE KEYS */;
INSERT INTO `patients` VALUES (17,'O+','None','None reported.','No current medications recorded.','If unconscious, place in recovery position and notify the clinic immediately.','Lorna Bautista','0917-204-1188',NULL,NULL,'09170de7b3c1378c67e47ef29aba825ed18b3fd78118eadaa098bffb3be80618',1,'2026-08-14 08:44:13','2026-08-14 09:50:04',NULL,NULL,NULL),(18,'A+',NULL,NULL,NULL,NULL,'Marites Ilagan','0918-337-9021',NULL,NULL,'5eac3c5a563f557eb5009550bba65857163755c1ab1810b2a1194cb54a1661cf',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(19,'B+',NULL,NULL,NULL,NULL,'Ramon Villanueva','0920-818-4432',NULL,NULL,'09c54bb606816a711c60b6588fbcfa7b5ed74b4685392c9fa0bbf841c9593837',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(20,'AB+',NULL,NULL,NULL,NULL,'Carina Mendoza','0916-552-0114',NULL,NULL,'fda267981ba81403bb085036075e2cc45a024e4b3fae360b693a213f54cd2cf0',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(21,'O-',NULL,NULL,NULL,NULL,'Paolo Reyes','0919-214-7710',NULL,NULL,'2c716e95d6381a0d8e98bfc72a274cc8f6244841639bec12a1b390863cde9e75',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(22,'A-',NULL,NULL,NULL,NULL,'Joy Ocampo','0995-310-2248',NULL,NULL,'9f066d8e3009c3832da9d676ea1b1851219b824340fdaa7e9caf80cf2ccc16ed',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(23,'B-',NULL,NULL,NULL,NULL,'Katrina Navarro','0917-998-6612',NULL,NULL,'5c977ab49bf00e09eb585d32290b2c70a379c87c630a4f7a21e923ea8cb3c871',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(24,'O+',NULL,NULL,NULL,NULL,'Diana Santos','0927-430-1195',NULL,NULL,'d65c0eb3c75b838db9b10e499e3071b11083210bb6827836db54c363231f17e9',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(25,'A+',NULL,NULL,NULL,NULL,'Anton Flores','0918-781-4403',NULL,NULL,'882013b9203933093b91ba25b5f3124a00953226539e026b622a663af2ba3392',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(26,'B+',NULL,NULL,NULL,NULL,'Riza Garcia','0916-773-9004',NULL,NULL,'1c33e3629af28e98113a9c4fe8d64a66f5394d18b16533b659e16dcc52c33bc0',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(27,'O+',NULL,NULL,NULL,NULL,'Maria Faustino','0917-230-0211',NULL,NULL,'6bbbafbbad061169f6ff8e1e3e25f9f1b3e70f5b635cdc57625f59db6327c872',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(28,'A+',NULL,NULL,NULL,NULL,'Analyn Cainglet','0917-230-0274',NULL,NULL,'863d632f8347d4dafcab9a0619f812b52f692656f19cc84adc412d129a32790f',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(29,'B+','None','None reported.','No current medications recorded.','If unconscious, place in recovery position and notify the clinic immediately.','Nadia Bumacod','+63 917 230 0262','Guardian',NULL,'2c63c358c6e94a156a37d779092871f17b29cc080889077c219116c96cb7b3fb',1,'2026-08-14 08:44:13','2026-09-03 14:37:14',NULL,NULL,NULL),(32,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'f65aa2c581110e94554a2ded61b09bee9edb3940974896d5acaccc70bf06214a',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(33,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'26b53834d261c98cdbb13a223a022c0277c6dbee83c366be0f8990eef3761d7f',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(34,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'5b87df5f2ab913a23df6a17f3d7e10ee8762aa6fc731bbf82dd969f858b451be',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(35,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0cac6af61a1daaad98b52fdd6dfd24c5e5d172bc153b4f3bfbd71fafc0cb5ab0',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(36,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'1290244fad0d6f87f02c000786ce0a83cc1c951e9d94f46c2f325a792bb164c1',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(37,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'ba7869c72f9eb492c7bd0ddeaafc42df0d29dd69eaffef46bdb19b58e529eb0d',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(38,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'b5349175f5a427fbcce837907bf573897508d8067e02ccf5aa1cc3033c39dfec',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(55,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'6bc7af399c174b0553e96d066eb4a213c7d9876d18abbefcab00be56d08aa100',1,'2026-08-14 08:44:13','2026-08-14 08:44:13',NULL,NULL,NULL),(72,'B+','None','None reported.','No current medications recorded.','If unconscious, place in recovery position and notify the clinic immediately.','venneth Salen','0912-213-3131',NULL,NULL,'ad9274d21ad77a263de9135009bf254785d16dbfdd4eb18a8ed380b2bb11d35c',1,'2026-08-19 15:09:02','2026-09-01 05:30:55',NULL,NULL,NULL);
/*!40000 ALTER TABLE `patients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `people`
--

DROP TABLE IF EXISTS `people`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `people` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_number` varchar(50) NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `birthdate` date DEFAULT NULL,
  `sex` enum('Male','Female','Other') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_number` (`id_number`),
  KEY `idx_people_name` (`last_name`,`first_name`)
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `people`
--

LOCK TABLES `people` WRITE;
/*!40000 ALTER TABLE `people` DISABLE KEYS */;
INSERT INTO `people` VALUES (17,'26-01024','Sofia','L.','Bautista','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(18,'26-01041','Rhea','C.','Ilagan','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(19,'26-01058','Marco','T.','Villanueva','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(20,'26-01073','Chloe','V.','Mendoza','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(21,'26-01089','Daniel','P.','Reyes','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(22,'26-01102','Jessa','M.','Ocampo','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(23,'26-01119','Kevin','R.','Navarro','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(24,'26-01136','Alyssa','D.','Santos','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(25,'26-01155','Miguel','A.','Flores','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(26,'26-01178','Bianca','R.','Garcia','2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(27,'23-00211','Justine Angelo',NULL,'Faustino','2004-08-15',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(28,'23-00274','Jan Alain',NULL,'Cainglet','2004-05-22',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(29,'23-00262','Najil',NULL,'Bumacod','2004-11-03',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(32,'STAFF-0001','System',NULL,'Administrator',NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(33,'STAFF-0002','Maria',NULL,'Santos',NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(34,'STAFF-0003','Carlo',NULL,'Reyes',NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(35,'STAFF-0004','Elena',NULL,'Cruz',NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(36,'STAFF-0005','Liza',NULL,'Manalo',NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(37,'STAFF-0006','IT',NULL,'Support',NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(38,'FAC-0001','Maria','Reyes','Santos','1985-06-20',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),(55,'23-00213','Juan','Jusko','Carlos','2000-12-12',NULL,'2026-07-29 12:32:44','2026-07-29 12:32:44'),(72,'23-11111','Yanie Mei Lourin',NULL,'Salen','2004-05-20','Female','2026-08-19 15:09:02','2026-08-19 15:09:02');
/*!40000 ALTER TABLE `people` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_people_create_inactive_account
AFTER INSERT ON people
FOR EACH ROW
BEGIN
  INSERT INTO accounts (person_id, account_status)
  VALUES (NEW.id, 'inactive');
END */;;
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
/*!40101 SET character_set_client = utf8 */;
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
  CONSTRAINT `fk_programs_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `programs`
--

LOCK TABLES `programs` WRITE;
/*!40000 ALTER TABLE `programs` DISABLE KEYS */;
INSERT INTO `programs` VALUES (1,1,'BSIT','Bachelor of Science in Information Technology',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(2,1,'BSCS','Bachelor of Science in Computer Science',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(3,2,'BSBA','Bachelor of Science in Business Administration',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(4,2,'BSA','Bachelor of Science in Accountancy',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(5,3,'BSED','Bachelor of Secondary Education',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(6,3,'BEED','Bachelor of Elementary Education',1,'2026-07-31 02:33:47','2026-07-31 02:33:47'),(13,9,'BSN','Bachelor of Science in Nursing',1,'2026-07-31 03:21:21','2026-07-31 03:21:21');
/*!40000 ALTER TABLE `programs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `referrals`
--

DROP TABLE IF EXISTS `referrals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `referrals`
--

LOCK TABLES `referrals` WRITE;
/*!40000 ALTER TABLE `referrals` DISABLE KEYS */;
INSERT INTO `referrals` VALUES (1,19,NULL,'City Health Office - Pulmonary Clinic','Asthma symptoms during PE; student advised pulmonary clearance for APE follow-up.','Completed',NULL,'2026-07-17',NULL,'2026-07-17 16:47:54'),(2,21,NULL,'Allergy and Immunology Clinic','Food allergy history and recent hives after canteen exposure.','Completed',NULL,'2026-07-18',NULL,'2026-07-17 16:47:54'),(3,23,NULL,'Partner Diagnostic Center','Right ankle sprain; X-ray advised only if swelling worsens within 24 hours.','Completed',NULL,'2026-07-15',NULL,'2026-07-17 16:47:54'),(4,28,NULL,'University Guidance and Wellness Office','Jan Alain requested wellness support resources after repeated stomach discomfort during exams.','Completed',NULL,'2026-07-18',NULL,'2026-07-17 16:47:54'),(5,29,NULL,'Allergy and Immunology Clinic','Najil has recurring allergic rhinitis symptoms after dust exposure.','Completed',NULL,'2026-07-18',NULL,'2026-07-17 16:47:54');
/*!40000 ALTER TABLE `referrals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_employees`
--

DROP TABLE IF EXISTS `school_employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_employees` (
  `person_id` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `role_classification` enum('Faculty','School Personnel') NOT NULL,
  `employment_type` varchar(80) DEFAULT NULL,
  `position_title` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`person_id`),
  KEY `idx_school_employees_department` (`department_id`),
  KEY `idx_school_employees_classification` (`role_classification`),
  CONSTRAINT `fk_school_employees_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_school_employees_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_employees`
--

LOCK TABLES `school_employees` WRITE;
/*!40000 ALTER TABLE `school_employees` DISABLE KEYS */;
INSERT INTO `school_employees` VALUES (38,1,'Faculty','Full-time','Instructor');
/*!40000 ALTER TABLE `school_employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `person_id` bigint(20) unsigned NOT NULL,
  `program_id` bigint(20) unsigned DEFAULT NULL,
  `year_level` varchar(40) DEFAULT NULL,
  `section` varchar(80) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`person_id`),
  KEY `fk_students_program` (`program_id`),
  CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (17,6,'1','A',NULL),(18,13,'1','A',NULL),(19,1,'1','C',NULL),(20,5,'1','A',NULL),(21,5,'1','B',NULL),(22,3,'1','D',NULL),(23,3,'1','A',NULL),(24,2,'1','B',NULL),(25,4,'1','A',NULL),(26,2,'1','C',NULL),(27,1,'4','D',NULL),(28,1,'4','D',NULL),(29,1,'4','D',NULL),(55,1,'4','D',NULL),(72,4,'4','A',NULL);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(120) NOT NULL,
  `setting_value` mediumtext NOT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES ('clinic.profile','{\"system_name\":\"CLINiQ\",\"institution_name\":\"Pamantasan ng Lungsod ng Pasig\",\"department\":\"University Health Services\",\"contact_email\":\"clinic@plpasig.edu.ph\",\"physical_address\":\"Alcalde Jose Street, Brgy. Kapasigan, Pasig City, Metro Manila, Philippines, 1600\",\"system_purpose\":\"School clinic information management system for patient records, visits, APE workflow, emergency alerts, appointments, inventory, referrals, and reports.\",\"logo_path\":\"uploads\\/settings\\/clinic-logo-20260901064934-ad51692a.png\"}',32,'2026-09-01 04:49:34'),('clinic.theme','{\"theme\":\"green\",\"custom_color\":\"#000000\"}',32,'2026-09-01 04:30:44'),('mail.smtp','{\"host\":\"smtp.gmail.com\",\"port\":587,\"encryption\":\"tls\",\"username\":\"anotherthreethreetwotwo@gmail.com\",\"password\":\"amJ8a\\/IVPS\\/iga\\/p+zNOtDo6MHpDYmZrbXNGbE5qMGUxVDYxVlN0NmtLZjVlZGtubmVPNWJqdDR5TXNVdz0=\",\"from_email\":\"anotherthreethreetwotwo@gmail.com\",\"from_name\":\"CLINiQ\"}',32,'2026-09-01 04:57:28'),('mail.template.employee_re_employment','{\"subject\":\"[{{clinic_name}}] Confirm you are still employed \\u2014 new school year\",\"heading\":\"Confirm Employment, {{patient_name}}\",\"message\":\"A new school year has started at {{clinic_name}}. Log in to confirm that you are still employed and continue accessing your health records and clinic services.\",\"button_label\":\"Confirm Employment\",\"footer\":\"If you are no longer employed, ignore this email and your account will remain inactive.\"}',32,'2026-09-02 11:46:30');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visit_entries`
--

DROP TABLE IF EXISTS `visit_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visit_entries`
--

LOCK TABLES `visit_entries` WRITE;
/*!40000 ALTER TABLE `visit_entries` DISABLE KEYS */;
INSERT INTO `visit_entries` VALUES (6,6,'Addict','Mukhang Addict','Tokhang',NULL,NULL,NULL,32,'2026-08-05 09:15:33'),(7,7,NULL,'Sakit teeth',NULL,NULL,NULL,NULL,32,'2026-08-05 09:19:45'),(10,12,'sparks joy','Infected with cuteness','kurotin mukha',NULL,NULL,NULL,32,'2026-08-27 12:24:23'),(12,13,'wala','kalokohan','wala rin',NULL,NULL,NULL,32,'2026-09-05 07:12:38'),(13,14,'SAKIT','SAKIT','SAKIT',NULL,'SAKIT',NULL,32,'2026-09-06 09:00:43'),(14,15,NULL,'SAki',NULL,NULL,NULL,NULL,NULL,'2026-09-06 13:07:20');
/*!40000 ALTER TABLE `visit_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visits`
--

DROP TABLE IF EXISTS `visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visits`
--

LOCK TABLES `visits` WRITE;
/*!40000 ALTER TABLE `visits` DISABLE KEYS */;
INSERT INTO `visits` VALUES (6,17,'2026-08-05 17:14:10',NULL,NULL,'Masakit puso ko','Completed','Medical Consult','Staff Recorded','Tokhang',32,32,'2026-08-05 09:14:10','2026-08-05 09:15:37'),(7,55,'2026-08-05 17:19:45','2026-08-05 17:52:57','2026-08-05 17:53:06','Sakit teeth','Completed','Dental Consult','Self Logbook',NULL,32,32,'2026-08-05 09:19:45','2026-08-29 09:50:09'),(12,72,'2026-08-27 20:24:23','2026-08-27 20:25:48','2026-08-27 20:26:58','May sakit','Completed','Medical Consult','Self Logbook','kurotin mukha',32,32,'2026-08-27 12:24:23','2026-08-29 09:50:09'),(13,29,'2026-09-05 15:12:38','2026-09-05 15:12:38','2026-09-05 15:12:52','sakit','Completed','Dental Consult','Staff Recorded',NULL,32,32,'2026-09-05 07:12:38','2026-09-05 07:12:52'),(14,29,'2026-09-06 17:00:43','2026-09-06 17:00:43','2026-09-06 17:15:16','SAKIT','Completed','Medical Consult','Staff Recorded',NULL,32,32,'2026-09-06 09:00:43','2026-09-06 09:15:16'),(15,29,'2026-09-06 21:07:20','2026-09-06 21:14:34',NULL,'SAki','Active','Medical Consult','Self Logbook','Visitor/patient self-registration. Awaiting clinic assessment.',32,32,'2026-09-06 13:07:20','2026-09-06 13:14:34');
/*!40000 ALTER TABLE `visits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vital_signs`
--

DROP TABLE IF EXISTS `vital_signs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vital_signs` (
  `vital_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `visit_id` bigint(20) unsigned DEFAULT NULL,
  `entry_id` bigint(20) unsigned DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `blood_pressure` varchar(20) DEFAULT NULL,
  `pulse_rate` smallint(5) unsigned DEFAULT NULL,
  `measured_by_person_id` bigint(20) unsigned DEFAULT NULL,
  `measured_at` datetime NOT NULL DEFAULT current_timestamp(),
  `patient_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`vital_id`),
  KEY `idx_vital_signs_visit_measured` (`visit_id`,`measured_at`),
  KEY `idx_vital_signs_entry` (`entry_id`),
  KEY `idx_vital_signs_measured_by` (`measured_by_person_id`),
  KEY `idx_vital_signs_patient` (`patient_id`),
  CONSTRAINT `fk_vital_signs_entry` FOREIGN KEY (`entry_id`) REFERENCES `visit_entries` (`entry_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vital_signs_measured_by` FOREIGN KEY (`measured_by_person_id`) REFERENCES `clinic_staff` (`person_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vital_signs_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`person_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vital_signs_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vital_signs`
--

LOCK TABLES `vital_signs` WRITE;
/*!40000 ALTER TABLE `vital_signs` DISABLE KEYS */;
INSERT INTO `vital_signs` VALUES (5,6,NULL,36.0,'110/70',190,32,'2026-08-05 17:14:10',NULL),(6,6,6,36.0,'110/70',190,32,'2026-08-05 17:15:33',NULL);
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
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-09 16:57:19
