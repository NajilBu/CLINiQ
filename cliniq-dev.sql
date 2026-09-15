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
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
INSERT INTO `accounts` VALUES
(15,17,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','bautista_sofia@plpasig.edu.ph','active','2026-07-18 00:47:54','2026-08-30 23:26:11','2026-07-29 10:46:24','2026-08-30 15:26:11'),
(16,18,'$2y$10$V7l6Ypxyj5EwG0gMdyi/7.aNYRKLbInGRyo9KxDLA2cn0bOPCO5E.','ilagan_rhea@plpasig.edu.ph','active','2026-07-18 00:47:54','2026-08-14 17:45:50','2026-07-29 10:46:24','2026-08-19 14:16:21'),
(17,19,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','villanueva_marco@plpasig.edu.ph','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(18,20,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','mendoza_chloe@plpasig.edu.ph','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(19,21,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','reyes_daniel@plpasig.edu.ph','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(20,22,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','ocampo_jessa@plpasig.edu.ph','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(21,23,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','navarro_kevin@plpasig.edu.ph','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(22,24,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','santos_alyssa@plpasig.edu.ph','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(23,25,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','flores_miguel@plpasig.edu.ph','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(24,26,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','garcia_bianca@plpasig.edu.ph','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(25,27,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','faustino_justineangelo@plpasig.edu.ph','active','2026-07-18 00:47:54','2026-09-14 05:47:20','2026-07-29 10:46:24','2026-09-13 21:47:20'),
(26,28,'$2y$10$7PCvWXIALwbg45uLsA/cm.ZyOl0CoZHtzBp/iXftYj8OTqkfcAYeq','cainglet_janalain@plpasig.edu.ph','active','2026-07-18 00:47:54',NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(27,29,'$2y$10$ERoGDNyV0hqUVQsKpKgXO.bDxEJJ9Q21TyIv9Fq41cGA3h/38m6hC','bumacod_najil@plpasig.edu.ph','active','2026-07-18 00:47:54','2026-09-03 13:34:13','2026-07-29 10:46:24','2026-09-03 05:34:13'),
(28,32,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','administrator_system@plpasig.edu.ph','active','2026-07-18 00:47:53','2026-09-14 13:26:30','2026-07-29 10:46:24','2026-09-14 05:26:30'),
(29,33,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','santos_maria@plpasig.edu.ph','active','2026-07-18 00:47:53','2026-07-29 19:30:57','2026-07-29 10:46:24','2026-08-19 14:14:41'),
(30,34,'$2y$10$MHHZGNP3N/HBnLzq78cxAOugRGWqx4NEuKagw7ahkCTM7vUnDVXZ.','reyes_carlo@plpasig.edu.ph','active','2026-07-18 00:47:53','2026-09-04 18:48:46','2026-07-29 10:46:24','2026-09-04 10:48:46'),
(31,35,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','cruz_elena@plpasig.edu.ph','active','2026-07-18 00:47:53','2026-07-29 19:31:23','2026-07-29 10:46:24','2026-08-19 14:14:41'),
(32,36,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','manalo_liza@plpasig.edu.ph','active','2026-07-18 00:47:53',NULL,'2026-07-29 10:46:24','2026-08-19 14:14:41'),
(33,37,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','support_it@plpasig.edu.ph','active','2026-07-18 00:47:53',NULL,'2026-07-29 10:46:24','2026-08-19 14:14:41'),
(34,38,'$2y$10$ueOmuGgHtFCt/JjeckBzAuVkMxA3jxIeSsHxKyu8Led.gf3iLOpOW','santos_maria@plpasig.edu.ph','inactive',NULL,NULL,'2026-07-29 10:46:24','2026-08-19 14:16:21'),
(51,55,'$2y$10$14ZjBASS.TQ1f.OgjLArnupWLvxMu3mhhlc3jxPfpM6XdIPzFkwSq','carlos_juan@plpasig.edu.ph','active','2026-07-29 20:33:31',NULL,'2026-07-29 12:32:44','2026-08-19 14:16:21'),
(69,72,'$2y$10$1MVYdHwR0SjiYOnw.cb.BOEh7Srix5R/iUcPzQd2m8hzQaoYz9N6G',NULL,'active','2026-08-19 23:09:34','2026-09-06 19:12:33','2026-08-19 15:09:02','2026-09-06 11:12:33'),
(70,73,'$2y$10$uB6qrfjBKMocYyS0vIsTru9avKg/YxLWfO2UYJpoAgVyJkZEsFFY.',NULL,'active','2026-09-04 18:37:29','2026-09-14 16:41:32','2026-09-04 10:37:00','2026-09-14 08:41:32');
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
) ENGINE=InnoDB AUTO_INCREMENT=98 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_activity_logs`
--

LOCK TABLES `ape_activity_logs` WRITE;
/*!40000 ALTER TABLE `ape_activity_logs` DISABLE KEYS */;
INSERT INTO `ape_activity_logs` VALUES
(70,15,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),
(71,23,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),
(72,24,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),
(73,25,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),
(74,32,32,'Assigned APE schedule batch','BSIT: 2026-09-03 08:00:00-14:00:00','2026-09-02 11:52:35'),
(77,25,29,'Confirmed patient-entered vitals and BMI','Patient completed the vitals profile before clinic examination.','2026-09-03 04:46:16'),
(78,25,32,'Updated APE requirement','TB Cert: Missing','2026-09-03 04:46:39'),
(79,25,32,'Recorded APE examination with document correction','Get Tb certs','2026-09-03 04:55:48'),
(80,25,32,'Updated APE requirement','Lab Request Form: Missing','2026-09-03 04:57:21'),
(81,25,32,'Updated APE requirement','UHS Consent Form: Missing','2026-09-03 05:04:16'),
(82,25,32,'Updated APE requirement','UHS Medical Record: Missing','2026-09-03 05:04:20'),
(83,25,32,'Updated APE requirement','UHS Dental Record: Missing','2026-09-03 05:04:22'),
(84,25,32,'Updated APE requirement','Referral Form: Missing','2026-09-03 05:04:26'),
(85,25,32,'Updated hard-copy document review','Follow-up documents: TB Cert. Get Tb certs','2026-09-03 05:18:22'),
(86,25,NULL,'Reset saved examination','User-requested reset: cleared examination date and removed saved finding #17. Restored Batch Assigned workflow. Schedule, vitals, requirements, uploads, and notes preserved. Recovery backup created before reset.','2026-09-03 05:50:56'),
(87,25,32,'Recorded APE examination and document review','APE Examination: With Finding. Follow-up documents: TB Cert. Kuha cert Return date: 2026-09-04','2026-09-03 06:17:36'),
(88,25,29,'Uploaded APE documents','Lab Request Form, UHS Consent Form, UHS Medical Record, UHS Dental Record, Referral Form','2026-09-03 11:28:26'),
(89,25,32,'Archived APE documents',NULL,'2026-09-03 11:29:20'),
(90,25,29,'Uploaded APE documents','TB Cert','2026-09-03 11:32:15'),
(91,25,32,'Opened returned documents for review',NULL,'2026-09-03 11:36:53'),
(92,25,32,'Archived follow-up documents','TB Cert','2026-09-03 11:55:13'),
(93,25,32,'Cleared patient after APE examination','Get Tb certs','2026-09-03 11:55:30'),
(94,34,34,'Assigned APE schedule batch','BSIT BATCH 2: 2026-09-05 08:00:00-09:00:00','2026-09-04 10:42:31'),
(95,34,73,'Confirmed patient-entered vitals and BMI','Patient completed the vitals profile before clinic examination.','2026-09-04 10:47:26'),
(96,34,34,'Added APE requirement','TB Accomplishment Certificate: Missing','2026-09-04 10:51:57'),
(97,34,34,'Recorded APE examination and document review','APE Examination: Normal. Follow-up documents: Lab Request Form, TB Accomplishment Certificate. Have TB needs follow up with cert of completion from barangay health center Return date: 2026-09-06','2026-09-04 10:52:02');
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_cycles`
--

LOCK TABLES `ape_cycles` WRITE;
/*!40000 ALTER TABLE `ape_cycles` DISABLE KEYS */;
INSERT INTO `ape_cycles` VALUES
(4,'2026-2027','2026-06-01','2027-05-31',NULL,'Active',32,'2026-08-11 13:20:59',NULL,NULL,NULL,NULL,1);
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
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_documents`
--

LOCK TABLES `ape_documents` WRITE;
/*!40000 ALTER TABLE `ape_documents` DISABLE KEYS */;
INSERT INTO `ape_documents` VALUES
(35,25,'Lab Request Form','92499a6d-0e43-4bae-b499-bc050479e432.jpg','uploads/ape/patient-ape_1788434906_ef431e41.jpg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),
(36,25,'UHS Consent Form','68625641-85b0-434c-b6aa-a4c98209e7a8.jpg','uploads/ape/patient-ape_1788434906_0f300e4e.jpg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),
(37,25,'UHS Medical Record','back.jpeg','uploads/ape/patient-ape_1788434906_2e596592.jpeg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),
(38,25,'UHS Dental Record','front.jpeg','uploads/ape/patient-ape_1788434906_80224691.jpeg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),
(39,25,'Referral Form','Assessment.jpg','uploads/ape/patient-ape_1788434906_fef2dceb.jpg','Verified',29,32,'2026-09-03 11:28:26','2026-09-03 19:29:20'),
(40,25,'TB Cert','survey.png','uploads/ape/patient-ape_1788435135_bd13591b.png','Verified',29,32,'2026-09-03 11:32:15','2026-09-03 19:55:13');
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
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_findings`
--

LOCK TABLES `ape_findings` WRITE;
/*!40000 ALTER TABLE `ape_findings` DISABLE KEYS */;
INSERT INTO `ape_findings` VALUES
(18,25,'APE Examination','May sakit','With Finding',0,32,'2026-09-03 06:17:36'),
(19,34,'APE Examination','No abnormal findings noted during the APE examination.','Normal',0,34,'2026-09-04 10:52:02');
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
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_records`
--

LOCK TABLES `ape_records` WRITE;
/*!40000 ALTER TABLE `ape_records` DISABLE KEYS */;
INSERT INTO `ape_records` VALUES
(13,4,NULL,17,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 07:50:29'),
(14,4,NULL,18,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-30 01:27:25'),
(15,4,1,19,'2026-2027',NULL,NULL,'Not Checked',NULL,'Batch Assigned','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 11:52:35'),
(16,4,NULL,20,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(17,4,NULL,21,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(18,4,NULL,22,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(19,4,NULL,23,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(20,4,NULL,24,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(21,4,NULL,25,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(22,4,NULL,26,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(23,4,1,27,'2026-2027',NULL,NULL,'Not Checked',NULL,'Batch Assigned','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 11:52:35'),
(24,4,1,28,'2026-2027',NULL,NULL,'Not Checked',NULL,'Batch Assigned','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 11:52:35'),
(25,4,1,29,'2026-2027','2026-09-03',NULL,'Checked','2026-09-03 19:55:13','Cleared','Cleared',0,'2026-09-04',NULL,'Return after getting the cert','Get Tb certs',170.00,55.00,19.03,36.0,'120/80',75,'Confirmed','2026-09-03 12:46:16',32,'2026-08-11 13:20:59','2026-09-03 11:55:30'),
(26,4,NULL,32,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(27,4,NULL,33,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(28,4,NULL,34,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(29,4,NULL,35,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(30,4,NULL,36,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(31,4,NULL,37,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-08-11 13:20:59'),
(32,4,1,55,'2026-2027',NULL,NULL,'Not Checked',NULL,'Batch Assigned','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-11 13:20:59','2026-09-02 11:52:35'),
(33,4,NULL,72,'2026-2027',NULL,NULL,'Not Checked',NULL,'Registered','Pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Not Started',NULL,NULL,'2026-08-19 16:22:13','2026-09-02 07:50:29'),
(34,4,3,73,'2026-2027','2026-09-04',NULL,'Not Checked','2026-09-04 18:52:02','Follow-up Required','For Follow-up',1,'2026-09-06',NULL,NULL,NULL,150.00,45.00,20.00,36.2,'120/80',69,'Confirmed','2026-09-04 18:47:26',34,'2026-09-04 10:37:00','2026-09-04 10:52:02');
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
) ENGINE=InnoDB AUTO_INCREMENT=281 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_requirements`
--

LOCK TABLES `ape_requirements` WRITE;
/*!40000 ALTER TABLE `ape_requirements` DISABLE KEYS */;
INSERT INTO `ape_requirements` VALUES
(147,13,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(148,14,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(149,15,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(150,16,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(151,17,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(152,18,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(153,19,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(154,20,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(155,21,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(156,22,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(157,23,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(158,24,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(159,25,'Lab Request Form','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),
(160,26,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(161,27,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(162,28,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(163,29,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(164,30,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(165,31,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(166,32,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(167,33,'Lab Request Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(168,13,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(169,14,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(170,15,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(171,16,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(172,17,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(173,18,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(174,19,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(175,20,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(176,21,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(177,22,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(178,23,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(179,24,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(180,25,'UHS Consent Form','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),
(181,26,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(182,27,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(183,28,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(184,29,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(185,30,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(186,31,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(187,32,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(188,33,'UHS Consent Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(189,13,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(190,14,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(191,15,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(192,16,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(193,17,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(194,18,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(195,19,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(196,20,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(197,21,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(198,22,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(199,23,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(200,24,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(201,25,'UHS Medical Record','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),
(202,26,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(203,27,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(204,28,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(205,29,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(206,30,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(207,31,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(208,32,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(209,33,'UHS Medical Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(210,13,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(211,14,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(212,15,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(213,16,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(214,17,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(215,18,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(216,19,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(217,20,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(218,21,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(219,22,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(220,23,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(221,24,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(222,25,'UHS Dental Record','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),
(223,26,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(224,27,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(225,28,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(226,29,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(227,30,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(228,31,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(229,32,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(230,33,'UHS Dental Record',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(231,13,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(232,14,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(233,15,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(234,16,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(235,17,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(236,18,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(237,19,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(238,20,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(239,21,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(240,22,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(241,23,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(242,24,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(243,25,'Referral Form','initial','2026-09-10','Verified',NULL,32,'2026-09-03 08:17:36','2026-09-02 07:50:29','2026-09-03 06:17:36'),
(244,26,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(245,27,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(246,28,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(247,29,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(248,30,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(249,31,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(250,32,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(251,33,'Referral Form',NULL,NULL,'Missing',NULL,NULL,NULL,'2026-09-02 07:50:29','2026-09-02 07:50:29'),
(274,25,'TB Cert','follow_up','2026-09-04','Verified','Kuha cert',32,'2026-09-03 19:55:13','2026-09-03 04:46:39','2026-09-03 11:55:13'),
(275,34,'Lab Request Form','follow_up','2026-09-06','Missing','Have TB needs follow up with cert of completion from barangay health center',NULL,NULL,'2026-09-04 10:37:00','2026-09-04 10:52:02'),
(276,34,'UHS Consent Form','initial','2026-09-11','Verified',NULL,34,'2026-09-04 12:52:02','2026-09-04 10:37:00','2026-09-04 10:52:02'),
(277,34,'UHS Medical Record','initial','2026-09-11','Verified',NULL,34,'2026-09-04 12:52:02','2026-09-04 10:37:00','2026-09-04 10:52:02'),
(278,34,'UHS Dental Record','initial','2026-09-11','Verified',NULL,34,'2026-09-04 12:52:02','2026-09-04 10:37:00','2026-09-04 10:52:02'),
(279,34,'Referral Form','initial','2026-09-11','Verified',NULL,34,'2026-09-04 12:52:02','2026-09-04 10:37:00','2026-09-04 10:52:02'),
(280,34,'TB Accomplishment Certificate','follow_up','2026-09-06','Missing','Have TB needs follow up with cert of completion from barangay health center',NULL,NULL,'2026-09-04 10:51:57','2026-09-04 10:52:02');
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ape_schedule_batches`
--

LOCK TABLES `ape_schedule_batches` WRITE;
/*!40000 ALTER TABLE `ape_schedule_batches` DISABLE KEYS */;
INSERT INTO `ape_schedule_batches` VALUES
(1,4,'BSIT','Student','2026-09-03','08:00:00','14:00:00',50,'Scheduled',32,'2026-09-02 11:52:35','2026-09-02 11:52:35'),
(3,4,'BSIT BATCH 2','Student','2026-09-05','08:00:00','09:00:00',50,'Scheduled',34,'2026-09-04 10:42:31','2026-09-04 10:42:31');
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
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_availability_blocks`
--

LOCK TABLES `appointment_availability_blocks` WRITE;
/*!40000 ALTER TABLE `appointment_availability_blocks` DISABLE KEYS */;
INSERT INTO `appointment_availability_blocks` VALUES
(1,'2026-10-09',NULL,NULL,'Class Suspension',32,'2026-09-04 08:46:48'),
(2,'2026-10-08',NULL,NULL,'Class Suspension',32,'2026-09-04 08:46:48'),
(3,'2026-10-07',NULL,NULL,'Class Suspension',32,'2026-09-04 08:46:48'),
(4,'2026-10-06',NULL,NULL,'Class Suspension',32,'2026-09-04 08:46:48'),
(13,'2026-09-04','08:00:00','17:00:00',NULL,32,'2026-09-04 10:00:23'),
(14,'2026-09-09','08:00:00','09:00:00',NULL,32,'2026-09-04 10:04:44'),
(15,'2026-09-09','09:00:00','10:00:00',NULL,32,'2026-09-04 10:04:44'),
(16,'2026-09-09','10:00:00','11:00:00',NULL,32,'2026-09-04 10:04:44'),
(17,'2026-09-09','11:00:00','12:00:00',NULL,32,'2026-09-04 10:04:44'),
(18,'2026-09-08','08:00:00','12:00:00','Wala ako busy',32,'2026-09-04 10:06:56'),
(19,'2026-09-09','12:00:00','13:00:00',NULL,32,'2026-09-06 10:49:17'),
(20,'2026-09-09','13:00:00','14:00:00',NULL,32,'2026-09-06 10:49:17'),
(21,'2026-09-09','14:00:00','15:00:00',NULL,32,'2026-09-06 10:49:17'),
(22,'2026-09-09','15:00:00','16:00:00',NULL,32,'2026-09-06 10:49:17'),
(23,'2026-09-09','16:00:00','17:00:00',NULL,32,'2026-09-06 10:49:17'),
(24,'2026-09-29','10:00:00','11:00:00','breaktime ngani',32,'2026-09-06 11:09:06'),
(25,'2026-09-29','11:00:00','12:00:00','breaktime ngani',32,'2026-09-06 11:09:06'),
(26,'2026-09-30','10:00:00','11:00:00','breaktime ngani',32,'2026-09-06 11:09:06'),
(27,'2026-09-30','11:00:00','12:00:00','breaktime ngani',32,'2026-09-06 11:09:06'),
(28,'2026-10-01','10:00:00','11:00:00','breaktime ngani',32,'2026-09-06 11:09:06'),
(29,'2026-10-01','11:00:00','12:00:00','breaktime ngani',32,'2026-09-06 11:09:06');
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES
(7,17,'2026-08-16 10:00:00','Dental Consultation','Completed','Patient Portal','Patient requested this appointment through the patient portal. Awaiting clinic approval.',NULL,NULL,32,'2026-08-14 09:41:14','2026-08-27 04:15:33',NULL),
(8,17,'2026-08-28 12:00:00','General Checkup','Completed','Patient Portal','Patient requested this appointment through the patient portal. Awaiting clinic approval.',NULL,NULL,32,'2026-08-27 04:14:45','2026-08-29 11:24:27',NULL),
(9,72,'2026-09-01 15:00:00','General Checkup','Completed','Patient Portal','Patient requested this appointment through the patient portal. Awaiting clinic approval.',NULL,NULL,32,'2026-09-01 05:31:19','2026-09-02 07:05:08',NULL),
(10,73,'2026-09-23 08:00:00','APE Follow-up','Scheduled','Patient Portal','Patient requested this appointment through the patient portal. Awaiting clinic approval. Patient note: mag susubmit po ng kulang',NULL,NULL,32,'2026-09-06 11:10:28','2026-09-13 21:50:06','2026-09-23 08:00:00'),
(11,72,'2026-09-23 09:00:00','General Checkup','Pending','Patient Portal','Patient requested this appointment through the patient portal. Awaiting clinic approval. Patient note: Sakit totoy',NULL,NULL,NULL,'2026-09-06 11:13:05','2026-09-06 11:13:05','2026-09-23 09:00:00');
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
) ENGINE=InnoDB AUTO_INCREMENT=108 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES
(1,32,'staff','auth','staff_login_success','person',32,'success',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-08 14:02:45'),
(2,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:23:02'),
(3,32,'staff','settings','backup_failed','backup',NULL,'failed','{\"message\":\"SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user \'cliniq\'@\'%\' to database \'cliniq_restore_verify_20260913_202702_4e06ec\'\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:27:02'),
(4,32,'staff','settings','backup_failed','backup',NULL,'failed','{\"message\":\"SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user \'cliniq\'@\'%\' to database \'cliniq_restore_verify_20260913_202714_5aa84b\'\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:27:14'),
(5,32,'staff','settings','backup_failed','backup',NULL,'failed','{\"message\":\"SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user \'cliniq\'@\'%\' to database \'cliniq_restore_verify_20260913_202722_fdab8a\'\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:27:22'),
(6,32,'staff','settings','backup_failed','backup',NULL,'failed','{\"message\":\"SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user \'cliniq\'@\'%\' to database \'cliniq_restore_verify_20260913_202730_927390\'\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:27:30'),
(7,32,'staff','settings','backup_failed','backup',NULL,'failed','{\"message\":\"SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user \'cliniq\'@\'%\' to database \'cliniq_restore_verify_20260913_202741_0e37f5\'\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:27:41'),
(8,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:32:08'),
(9,32,'staff','settings','backup_verified','backup',NULL,'success','{\"path\":\"/var/backups/cliniq/Daily/CLINiQ_daily_2026-09-13_201628\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:32:15'),
(10,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:46:46'),
(11,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:47:34'),
(12,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:49:08'),
(13,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:50:55'),
(14,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:53:22'),
(15,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 12:54:33'),
(16,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:00:06'),
(17,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:07:33'),
(18,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:08:20'),
(19,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:09:03'),
(20,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:15:31'),
(21,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:19:26'),
(22,32,'staff','settings','backup_destination_updated','backup',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:19:29'),
(23,32,'staff','settings','external_backup_completed','backup',NULL,'success','{\"path\":\"/var/backups/external/Daily/CLINiQ_daily_2026-09-13_211953\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:19:55'),
(24,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:22:22'),
(25,32,'staff','settings','backup_destination_updated','backup',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:23:16'),
(26,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:25:31'),
(27,32,'staff','settings','backup_destination_updated','backup',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:26:11'),
(28,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:29:03'),
(29,32,'staff','settings','backup_destination_updated','backup',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:29:37'),
(30,32,'staff','settings','backup_destination_updated','backup',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 13:50:15'),
(31,32,'staff','settings','backup_destination_updated','backup',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:04:48'),
(32,32,'staff','auth','staff_logout','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:04:53'),
(33,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:04:55'),
(34,32,'staff','auth','staff_logout','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:06:17'),
(35,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:44:59'),
(36,32,'staff','auth','staff_logout','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:46:09'),
(37,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:46:12'),
(38,32,'staff','auth','staff_logout','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:46:15'),
(39,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:50:57'),
(40,32,'staff','settings','backup_destination_updated','backup',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 14:55:28'),
(41,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:01:11'),
(42,32,'staff','settings','clinic_server_designated','server',NULL,'success','{\"configured\":true,\"hostname\":\"gelo\",\"local_ip\":\"172.21.176.1\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:01:29'),
(43,32,'staff','settings','clinic_server_designated','server',NULL,'success','{\"configured\":true,\"hostname\":\"gelo\",\"local_ip\":\"172.21.176.1\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:04:24'),
(44,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:07:21'),
(45,32,'staff','settings','clinic_server_designated','server',NULL,'success','{\"configured\":true,\"hostname\":\"gelo\",\"local_ip\":\"192.168.254.123\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:07:48'),
(46,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:16:19'),
(47,32,'staff','settings','clinic_server_designated','server',NULL,'success','{\"configured\":true,\"hostname\":\"gelo\",\"local_ip\":\"192.168.254.123\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:16:30'),
(48,32,'staff','settings','clinic_server_designated','server',NULL,'success','{\"configured\":true,\"hostname\":\"gelo\",\"local_ip\":\"192.168.254.123\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:17:11'),
(49,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:41:16'),
(50,32,'staff','settings','clinic_server_undesignated','server',NULL,'success','{\"configured\":true,\"hostname\":\"gelo\",\"local_ip\":\"192.168.254.123\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:41:33'),
(51,32,'staff','settings','clinic_server_designated','server',NULL,'success','{\"configured\":true,\"hostname\":\"gelo\",\"local_ip\":\"192.168.254.123\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-13 15:41:54'),
(52,27,'student','auth','student_login_success','person',27,'success',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 21:47:20'),
(53,73,'student','auth','student_login_success','person',73,'success',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 21:47:30'),
(54,73,'guest','auth','student_login_failed','account',70,'failure',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 21:48:01'),
(55,73,'student','auth','student_login_success','person',73,'success',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 21:48:09'),
(56,73,'student','passport','passport_profile_updated','patient',73,'success','{\"fields\":[\"blood_type\",\"allergies\",\"conditions\",\"medications\",\"instructions\",\"emergency_contacts\",\"show_bmi_on_passport\"]}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 21:53:08'),
(57,73,'student','passport','passport_profile_updated','patient',73,'success','{\"fields\":[\"blood_type\",\"allergies\",\"conditions\",\"medications\",\"instructions\",\"emergency_contacts\",\"show_bmi_on_passport\"]}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 21:53:12'),
(58,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 21:55:58'),
(59,73,'student','auth','student_login_success','person',73,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 23:55:28'),
(60,73,'student','auth','student_logout','person',73,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 23:57:26'),
(61,32,'staff','auth','staff_logout','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) cliniq-clinic-desktop/0.1.0 Chrome/152.0.7977.65 Electron/44.1.0 Safari/537.36 CLINiQElectron/0.1.0','2026-09-14 00:12:39'),
(62,73,'student','auth','student_login_success','person',73,'success',NULL,'172.21.0.5','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 00:57:58'),
(63,NULL,'staff','visits','visit_created','visit',15,'success','{\"patient_person_id\":73,\"source\":\"Self Logbook\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 01:06:56'),
(64,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 01:39:08'),
(65,32,'staff','auth','staff_logout','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 01:44:57'),
(66,73,'student','auth','student_login_success','person',73,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 01:45:17'),
(67,73,'student','auth','student_logout','person',73,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 01:50:55'),
(68,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 01:51:05'),
(69,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:19:30'),
(70,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:19:37'),
(71,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:19:39'),
(72,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:19:39'),
(73,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:19:39'),
(74,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:19:48'),
(75,73,'student','auth','student_login_success','person',73,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:20:02'),
(76,73,'student','passport','passport_profile_updated','patient',73,'success','{\"fields\":[\"blood_type\",\"allergies\",\"conditions\",\"medications\",\"instructions\",\"emergency_contacts\",\"show_bmi_on_passport\"]}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:20:44'),
(77,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:20:48'),
(78,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:21:55'),
(79,73,'student','incident','incident_report_submitted','patient',73,'success','{\"location\":\"5th\",\"risk_rating\":null}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:21:55'),
(80,73,'student','passport','passport_profile_updated','patient',73,'success','{\"fields\":[\"blood_type\",\"allergies\",\"conditions\",\"medications\",\"instructions\",\"emergency_contacts\",\"show_bmi_on_passport\"]}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:22:05'),
(81,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:22:18'),
(82,32,'staff','incident','alert_status_updated','nurse_alert',2,'success','{\"status\":\"In Progress\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:22:40'),
(83,32,'staff','incident','alert_resolved','nurse_alert',2,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:22:53'),
(84,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:45'),
(85,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:47'),
(86,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:49'),
(87,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:55'),
(88,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:55'),
(89,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:56'),
(90,73,'student','passport','passport_viewed','patient',73,'success','{\"route\":\"public/emergency.php\",\"authenticated\":true}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:56'),
(91,73,'student','auth','student_logout','person',73,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:44:08'),
(92,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:09:32'),
(93,73,'student','auth','student_login_success','person',73,'success',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:10:11'),
(94,73,'student','auth','student_logout','person',73,'success',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:10:53'),
(95,32,'staff','auth','staff_login_success','person',32,'success',NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:26:30'),
(96,NULL,'staff','visits','visit_created','visit',16,'success','{\"patient_person_id\":73,\"source\":\"Self Logbook\"}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:44:56'),
(97,32,'staff','inventory','inventory_transaction_recorded','inventory_transaction',13,'success','{\"item_id\":3,\"type\":\"Dispensed\",\"quantity_change\":-1}','172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:46:41'),
(98,73,'student','auth','student_login_success','person',73,'success',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:47:02'),
(99,73,'student','auth','student_logout','person',73,'success',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:50:15'),
(100,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:50:21'),
(101,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:50:23'),
(102,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:50:24'),
(103,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:50:34'),
(104,NULL,'student','auth','student_logout','person',NULL,'success',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:50:42'),
(105,27,'guest','auth','student_login_failed','account',25,'failure',NULL,'172.21.0.2','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 05:51:06'),
(106,73,'student','auth','student_login_success','person',73,'success',NULL,'172.21.0.2','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36','2026-09-14 06:25:42'),
(107,73,'student','auth','student_login_success','person',73,'success',NULL,'172.21.0.2','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36','2026-09-14 08:41:32');
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clinic_feedback`
--

LOCK TABLES `clinic_feedback` WRITE;
/*!40000 ALTER TABLE `clinic_feedback` DISABLE KEYS */;
INSERT INTO `clinic_feedback` VALUES
(1,15,'servperf-v1','visit-linked-v1','General Medical Consultation (Check-up, illness, sick leave validation)',NULL,'2nd Semester',NULL,'4th Year',NULL,'BSIT','Thankyou','{\"T1\":5,\"T2\":3,\"T3\":5,\"T4\":3,\"R1\":4,\"R2\":6,\"R3\":5,\"R4\":4,\"R5\":6,\"RES1\":3,\"RES2\":4,\"RES3\":5,\"RES4\":6,\"A1\":7,\"A2\":6,\"A3\":5,\"A4\":4,\"E1\":3,\"E2\":4,\"E3\":5,\"E4\":6,\"E5\":5}',4.000000,5.000000,4.500000,5.500000,4.600000,4.720000,'2026-09-14 13:18:13');
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
INSERT INTO `clinic_staff` VALUES
(32,10,'admin','System Administrator'),
(33,10,'doctor','Dr. Maria Santos'),
(34,10,'doctor','Dr. Carlo Reyes'),
(35,10,'nurse','Nurse Elena Cruz'),
(36,10,'staff','Clinic Staff Liza Manalo'),
(37,10,'it_expert','IT Support');
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
(1,'CCS','College of Computer Studies',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(2,'CBA','College of Business Administration',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(3,'COE','College of Education',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(4,'CAS','College of Arts and Sciences',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(5,'CON','College of Nursing',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(6,'UHS','University Health Services',1,'2026-09-13 12:11:24','2026-09-13 12:11:24');
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `equipment_loans`
--

LOCK TABLES `equipment_loans` WRITE;
/*!40000 ALTER TABLE `equipment_loans` DISABLE KEYS */;
INSERT INTO `equipment_loans` VALUES
(1,4,27,NULL,1,'2026-09-04 17:44:48',NULL,NULL,'Borrowed',32,NULL,NULL,'2026-09-04 09:44:48','2026-09-04 09:44:48'),
(2,4,55,NULL,1,'2026-09-04 17:47:07','2026-09-26 20:00:00',NULL,'Borrowed',32,NULL,NULL,'2026-09-04 09:47:07','2026-09-04 09:47:07'),
(3,4,27,13,2,'2026-09-06 17:09:13','2026-09-06 12:00:00',NULL,'Borrowed',32,NULL,NULL,'2026-09-06 09:09:13','2026-09-06 09:09:13');
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `incident_reports`
--

LOCK TABLES `incident_reports` WRITE;
/*!40000 ALTER TABLE `incident_reports` DISABLE KEYS */;
INSERT INTO `incident_reports` VALUES
(1,73,'a53bff71e9ef3a03986a9d38b4849371db8d62947ba32fa3b93540148a59d675','Alain Cainglet','09276345340','Gym','masakit po tiyan',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','New','2026-09-06 09:58:02',NULL),
(2,73,'a53bff71e9ef3a03986a9d38b4849371db8d62947ba32fa3b93540148a59d675','Chrisha Mazel Flores Balbacal',NULL,'5th',NULL,NULL,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','New','2026-09-14 02:21:55',NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_items`
--

LOCK TABLES `inventory_items` WRITE;
/*!40000 ALTER TABLE `inventory_items` DISABLE KEYS */;
INSERT INTO `inventory_items` VALUES
(3,'Shabu','Medicine','Bawal yan','Sachet',222,10,'2026-12-24',1,'2026-08-05 13:26:41','2026-09-14 05:46:41'),
(4,'Foil','Equipment','Pwede to','Sheet',6,1,NULL,1,'2026-08-05 13:41:46','2026-09-06 17:09:13');
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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_transactions`
--

LOCK TABLES `inventory_transactions` WRITE;
/*!40000 ALTER TABLE `inventory_transactions` DISABLE KEYS */;
INSERT INTO `inventory_transactions` VALUES
(5,3,'Stock In',20,20,NULL,NULL,'Initial inventory quantity',32,'2026-08-05 05:26:41'),
(6,3,'Stock In',5,25,NULL,NULL,'Medicine restock',32,'2026-08-05 05:34:54'),
(7,4,'Stock In',10,10,NULL,NULL,'Initial inventory quantity',32,'2026-08-05 05:41:46'),
(8,3,'Dispensed',-2,23,4,NULL,'Dispensed during treatment entry #6',32,'2026-08-05 09:15:33'),
(9,3,'Stock In',200,223,NULL,NULL,'Medicine restock',32,'2026-09-04 09:34:32'),
(10,4,'Loaned',-1,9,NULL,1,'Equipment loaned to Justine Angelo Faustino (23-00211)',32,'2026-09-04 09:44:48'),
(11,4,'Loaned',-1,8,NULL,2,'Equipment loaned to Juan Jusko Carlos (23-00213)',32,'2026-09-04 09:47:07'),
(12,4,'Loaned',-2,6,NULL,3,'Equipment issued during visit #13',32,'2026-09-06 09:09:13'),
(13,3,'Dispensed',-1,222,5,NULL,'Dispensed during treatment entry #16',32,'2026-09-14 05:46:41');
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
INSERT INTO `login_attempts` VALUES
(1,'patient','6cb6f66c85a3453573c1fb38b91c1b7a3dc94ae39da28ac1a11f008e37ce5aff','cb20dd425bd4468e8d4c06a0587b04a1522bc2252e939cb22a01a207a0b05852','2026-09-14 05:51:06');
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `medicine_dispensings`
--

LOCK TABLES `medicine_dispensings` WRITE;
/*!40000 ALTER TABLE `medicine_dispensings` DISABLE KEYS */;
INSERT INTO `medicine_dispensings` VALUES
(4,6,3,2,NULL,32,'2026-08-05 17:15:33'),
(5,16,3,1,NULL,32,'2026-09-14 13:46:41');
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nurse_alerts`
--

LOCK TABLES `nurse_alerts` WRITE;
/*!40000 ALTER TABLE `nurse_alerts` DISABLE KEYS */;
INSERT INTO `nurse_alerts` VALUES
(1,73,'Alain Cainglet','Emergency Passport Scanner','Gym','Emergency passport incident report','Breathing difficulty','Emergency passport incident report submitted.\nReporter: Alain Cainglet\nContact: 09276345340\nPhotos attached: 1\n\nIncident type: Breathing difficulty\nObserved condition: Awake and responsive\nBreathing: Normal\nBleeding: None observed\nPain level: 1-3 - Mild pain\nMobility: Can walk\nReporter notes: masakit po tiyan','Incident type: Breathing difficulty\nObserved condition: Awake and responsive\nBreathing: Normal\nBleeding: None observed\nPain level: 1-3 - Mild pain\nMobility: Can walk\nReporter notes: masakit po tiyan',NULL,'Moderate',5,'Incident type involves breathing difficulty (+4)\nKeyword indicator found: pain (+1)','Prompt clinic assessment needed. Assist the student to the clinic when safe, provide first aid, observe symptoms, and document the response.','uploads/incidents/incident-34cbab17df5edd0d.jpg','Cancelled',NULL,NULL,NULL,'2026-09-06 09:58:02','2026-09-06 10:02:32'),
(2,73,'Chrisha Mazel Flores Balbacal','Emergency passport scanner','5th','Possible accident reported from ID number',NULL,'Reported from QR/NFC emergency tag.\nNo structured incident answers were submitted.','No structured incident answers were submitted.',NULL,'Not assessed',0,'No clinical details were provided for risk classification','The report did not include enough clinical details for risk classification. Verify the student condition promptly and contact the clinic if the situation may be urgent.',NULL,'Resolved','gud',32,'2026-09-14 10:22:53','2026-09-14 02:21:55','2026-09-14 02:22:53');
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
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passport_access_logs`
--

LOCK TABLES `passport_access_logs` WRITE;
/*!40000 ALTER TABLE `passport_access_logs` DISABLE KEYS */;
INSERT INTO `passport_access_logs` VALUES
(1,73,73,58,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-13 21:55:58'),
(2,73,73,77,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:20:48'),
(3,73,73,78,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 02:21:55'),
(4,73,73,84,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:45'),
(5,73,73,85,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:47'),
(6,73,73,86,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:49'),
(7,73,73,87,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:55'),
(8,73,73,88,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:55'),
(9,73,73,89,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:56'),
(10,73,73,90,'172.21.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-14 04:43:56');
/*!40000 ALTER TABLE `passport_access_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patient_notifications`
--

DROP TABLE IF EXISTS `patient_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_notifications` (
  `notification_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_person_id` bigint(20) unsigned NOT NULL,
  `actor_person_id` bigint(20) unsigned DEFAULT NULL,
  `category` varchar(40) NOT NULL,
  `title` varchar(160) NOT NULL,
  `message` text NOT NULL,
  `target_url` varchar(255) DEFAULT NULL,
  `source_type` varchar(50) DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `fk_patient_notifications_actor` (`actor_person_id`),
  KEY `idx_patient_notifications_patient_created` (`patient_person_id`,`created_at`),
  KEY `idx_patient_notifications_unread` (`patient_person_id`,`read_at`),
  KEY `idx_patient_notifications_source` (`source_type`,`source_id`),
  CONSTRAINT `fk_patient_notifications_actor` FOREIGN KEY (`actor_person_id`) REFERENCES `people` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_patient_notifications_patient` FOREIGN KEY (`patient_person_id`) REFERENCES `patients` (`person_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patient_notifications`
--

LOCK TABLES `patient_notifications` WRITE;
/*!40000 ALTER TABLE `patient_notifications` DISABLE KEYS */;
INSERT INTO `patient_notifications` VALUES
(1,73,32,'appointment','Appointment approved','Your clinic appointment for September 23, 2026 at 8:00 AM has been approved.','patient-appointment.php','appointment',10,'2026-09-14 07:55:36','2026-09-13 23:53:03');
/*!40000 ALTER TABLE `patient_notifications` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patient_password_resets`
--

LOCK TABLES `patient_password_resets` WRITE;
/*!40000 ALTER TABLE `patient_password_resets` DISABLE KEYS */;
INSERT INTO `patient_password_resets` VALUES
(2,27,'eda65d6d295451ad4cc86fc0f4bb7dcb19464f6d3aeafd211e795535cea2e09e','::1','2026-09-01 18:00:37','2026-09-01 23:01:35','2026-09-01 15:00:37'),
(3,27,'7cd0d383f22a4f70b9f342b4e2d013e8e0867476f75219fec73e96c9b1eb03d6','::1','2026-09-01 18:01:35','2026-09-01 23:04:50','2026-09-01 15:01:35'),
(4,27,'74434851fd822b6bbe352d04e843c37658fe572c31e750b627351a7d683bf9ae','::1','2026-09-02 00:04:50','2026-09-01 23:05:42','2026-09-01 15:04:50'),
(5,27,'c64a883c6be747e43752779a066ca5ec26f0fc72bd741332ea1f3a4fff793f28','::1','2026-09-03 13:44:58',NULL,'2026-09-03 04:44:58'),
(7,25,'35982c6711580589990b157a1288ab0475169f5631533d8d00f4ceba51efb889','172.21.0.2','2026-09-14 15:06:18',NULL,'2026-09-14 06:06:18');
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
INSERT INTO `patients` VALUES
(17,'O+','None','None reported.','No current medications recorded.','If unconscious, place in recovery position and notify the clinic immediately.','Lorna Bautista','0917-204-1188',NULL,NULL,NULL,NULL,NULL,1,'09170de7b3c1378c67e47ef29aba825ed18b3fd78118eadaa098bffb3be80618',1,'2026-08-14 08:44:13','2026-08-14 09:50:04'),
(18,'A+',NULL,NULL,NULL,NULL,'Marites Ilagan','0918-337-9021',NULL,NULL,NULL,NULL,NULL,1,'5eac3c5a563f557eb5009550bba65857163755c1ab1810b2a1194cb54a1661cf',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(19,'B+',NULL,NULL,NULL,NULL,'Ramon Villanueva','0920-818-4432',NULL,NULL,NULL,NULL,NULL,1,'09c54bb606816a711c60b6588fbcfa7b5ed74b4685392c9fa0bbf841c9593837',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(20,'AB+',NULL,NULL,NULL,NULL,'Carina Mendoza','0916-552-0114',NULL,NULL,NULL,NULL,NULL,1,'fda267981ba81403bb085036075e2cc45a024e4b3fae360b693a213f54cd2cf0',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(21,'O-',NULL,NULL,NULL,NULL,'Paolo Reyes','0919-214-7710',NULL,NULL,NULL,NULL,NULL,1,'2c716e95d6381a0d8e98bfc72a274cc8f6244841639bec12a1b390863cde9e75',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(22,'A-',NULL,NULL,NULL,NULL,'Joy Ocampo','0995-310-2248',NULL,NULL,NULL,NULL,NULL,1,'9f066d8e3009c3832da9d676ea1b1851219b824340fdaa7e9caf80cf2ccc16ed',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(23,'B-',NULL,NULL,NULL,NULL,'Katrina Navarro','0917-998-6612',NULL,NULL,NULL,NULL,NULL,1,'5c977ab49bf00e09eb585d32290b2c70a379c87c630a4f7a21e923ea8cb3c871',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(24,'O+',NULL,NULL,NULL,NULL,'Diana Santos','0927-430-1195',NULL,NULL,NULL,NULL,NULL,1,'d65c0eb3c75b838db9b10e499e3071b11083210bb6827836db54c363231f17e9',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(25,'A+',NULL,NULL,NULL,NULL,'Anton Flores','0918-781-4403',NULL,NULL,NULL,NULL,NULL,1,'882013b9203933093b91ba25b5f3124a00953226539e026b622a663af2ba3392',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(26,'B+',NULL,NULL,NULL,NULL,'Riza Garcia','0916-773-9004',NULL,NULL,NULL,NULL,NULL,1,'1c33e3629af28e98113a9c4fe8d64a66f5394d18b16533b659e16dcc52c33bc0',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(27,'O+',NULL,NULL,NULL,NULL,'Maria Faustino','0917-230-0211',NULL,NULL,NULL,NULL,NULL,1,'6bbbafbbad061169f6ff8e1e3e25f9f1b3e70f5b635cdc57625f59db6327c872',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(28,'A+',NULL,NULL,NULL,NULL,'Analyn Cainglet','0917-230-0274',NULL,NULL,NULL,NULL,NULL,1,'863d632f8347d4dafcab9a0619f812b52f692656f19cc84adc412d129a32790f',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(29,'B+','None','None reported.','No current medications recorded.','If unconscious, place in recovery position and notify the clinic immediately.','Nadia Bumacod','+63 917 230 0262','Guardian',NULL,NULL,NULL,NULL,1,'2c63c358c6e94a156a37d779092871f17b29cc080889077c219116c96cb7b3fb',1,'2026-08-14 08:44:13','2026-09-03 14:37:14'),
(32,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'f65aa2c581110e94554a2ded61b09bee9edb3940974896d5acaccc70bf06214a',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(33,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'26b53834d261c98cdbb13a223a022c0277c6dbee83c366be0f8990eef3761d7f',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(34,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'5b87df5f2ab913a23df6a17f3d7e10ee8762aa6fc731bbf82dd969f858b451be',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(35,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'0cac6af61a1daaad98b52fdd6dfd24c5e5d172bc153b4f3bfbd71fafc0cb5ab0',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(36,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'1290244fad0d6f87f02c000786ce0a83cc1c951e9d94f46c2f325a792bb164c1',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(37,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'ba7869c72f9eb492c7bd0ddeaafc42df0d29dd69eaffef46bdb19b58e529eb0d',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(38,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'b5349175f5a427fbcce837907bf573897508d8067e02ccf5aa1cc3033c39dfec',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(55,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'6bc7af399c174b0553e96d066eb4a213c7d9876d18abbefcab00be56d08aa100',1,'2026-08-14 08:44:13','2026-08-14 08:44:13'),
(72,'B+','None','None reported.','No current medications recorded.','If unconscious, place in recovery position and notify the clinic immediately.','venneth Salen','0912-213-3131',NULL,NULL,NULL,NULL,NULL,1,'ad9274d21ad77a263de9135009bf254785d16dbfdd4eb18a8ed380b2bb11d35c',1,'2026-08-19 15:09:02','2026-09-01 05:30:55'),
(73,'B+','None','None reported.','No current medications recorded.','If unconscious, place in recovery position and notify the clinic immediately.','Maria Faustino','+63 974 171 9274','Guardian',NULL,NULL,NULL,NULL,1,'a53bff71e9ef3a03986a9d38b4849371db8d62947ba32fa3b93540148a59d675',1,'2026-09-04 10:37:00','2026-09-13 21:53:08');
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
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `people`
--

LOCK TABLES `people` WRITE;
/*!40000 ALTER TABLE `people` DISABLE KEYS */;
INSERT INTO `people` VALUES
(17,'26-01024','Sofia','L.','Bautista',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(18,'26-01041','Rhea','C.','Ilagan',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(19,'26-01058','Marco','T.','Villanueva',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(20,'26-01073','Chloe','V.','Mendoza',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(21,'26-01089','Daniel','P.','Reyes',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(22,'26-01102','Jessa','M.','Ocampo',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(23,'26-01119','Kevin','R.','Navarro',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(24,'26-01136','Alyssa','D.','Santos',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(25,'26-01155','Miguel','A.','Flores',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(26,'26-01178','Bianca','R.','Garcia',NULL,'2008-07-18',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(27,'23-00211','Justine Angelo',NULL,'Faustino',NULL,'2004-08-15',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(28,'23-00274','Jan Alain',NULL,'Cainglet',NULL,'2004-05-22',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(29,'23-00262','Najil',NULL,'Bumacod',NULL,'2004-11-03',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(32,'STAFF-0001','System',NULL,'Administrator',NULL,NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(33,'STAFF-0002','Maria',NULL,'Santos',NULL,NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(34,'STAFF-0003','Carlo',NULL,'Reyes',NULL,NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(35,'STAFF-0004','Elena',NULL,'Cruz',NULL,NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(36,'STAFF-0005','Liza',NULL,'Manalo',NULL,NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(37,'STAFF-0006','IT',NULL,'Support',NULL,NULL,NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(38,'FAC-0001','Maria','Reyes','Santos',NULL,'1985-06-20',NULL,'2026-07-29 10:46:24','2026-07-29 10:46:24'),
(55,'23-00213','Juan','Jusko','Carlos',NULL,'2000-12-12',NULL,'2026-07-29 12:32:44','2026-07-29 12:32:44'),
(72,'23-11111','Yanie Mei Lourin',NULL,'Salen',NULL,'2004-05-20','Female','2026-08-19 15:09:02','2026-08-19 15:09:02'),
(73,'23-00226','Chrisha Mazel','Flores','Balbacal',NULL,'2006-02-26','Female','2026-09-04 10:37:00','2026-09-04 10:37:00');
/*!40000 ALTER TABLE `people` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb3 */ ;
/*!50003 SET character_set_results = utf8mb3 */ ;
/*!50003 SET collation_connection  = utf8mb3_general_ci */ ;
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
(1,1,'BSIT','Bachelor of Science in Information Technology',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(2,1,'BSCS','Bachelor of Science in Computer Science',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(3,2,'BSBA','Bachelor of Science in Business Administration',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(4,2,'BSA','Bachelor of Science in Accountancy',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(5,3,'BSED','Bachelor of Secondary Education',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(6,3,'BEED','Bachelor of Elementary Education',1,'2026-09-13 12:11:24','2026-09-13 12:11:24'),
(7,5,'BSN','Bachelor of Science in Nursing',1,'2026-09-13 12:11:24','2026-09-13 12:11:24');
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `referrals`
--

LOCK TABLES `referrals` WRITE;
/*!40000 ALTER TABLE `referrals` DISABLE KEYS */;
INSERT INTO `referrals` VALUES
(1,19,NULL,'City Health Office - Pulmonary Clinic','Asthma symptoms during PE; student advised pulmonary clearance for APE follow-up.','Completed',NULL,'2026-07-17',NULL,'2026-07-17 16:47:54'),
(2,21,NULL,'Allergy and Immunology Clinic','Food allergy history and recent hives after canteen exposure.','Completed',NULL,'2026-07-18',NULL,'2026-07-17 16:47:54'),
(3,23,NULL,'Partner Diagnostic Center','Right ankle sprain; X-ray advised only if swelling worsens within 24 hours.','Completed',NULL,'2026-07-15',NULL,'2026-07-17 16:47:54'),
(4,28,NULL,'University Guidance and Wellness Office','Jan Alain requested wellness support resources after repeated stomach discomfort during exams.','Completed',NULL,'2026-07-18',NULL,'2026-07-17 16:47:54'),
(5,29,NULL,'Allergy and Immunology Clinic','Najil has recurring allergic rhinitis symptoms after dust exposure.','Completed',NULL,'2026-07-18',NULL,'2026-07-17 16:47:54');
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
('20260731_add_departments_programs.sql','59341d5ede576355cd93078099736348945da2d09cbc92486add04129b869916',0,'2026-09-13 12:11:24'),
('20260731_normalize_program_department_references.sql','83795dac7fce9ea761dd8afd2012aea67934e03d286ab4c187fe8536f34b5c52',0,'2026-09-13 12:11:24'),
('20260731_remove_patient_allergies_conditions.sql','acdcec13abf6e8a268c36a12841781c715400235e04f77ebf98923fd391a0595',0,'2026-09-13 12:11:24'),
('20260803_create_visit_tables.sql','36867f448b46d112bc3b34d05f37b55556a81c70e39f9980708b405fe13ec143',0,'2026-09-13 12:11:24'),
('20260803_remove_faculty_school_personnel_office_columns.sql','488b182f122cbcdc2ad7c1fd3ba0875addd1ac240c760f250b85dbb184a6f7cc',0,'2026-09-13 12:11:24'),
('20260803_remove_redundant_profile_text_columns.sql','c64d3161263f2b8b99beab0253a851bb150383c419a909d2306916982fec62e4',0,'2026-09-13 12:11:24'),
('20260804_add_people_sex.sql','7876b87bd45d2c1c0583f8793f726b95ae6025ba66eecee55bb714aa945595cc',0,'2026-09-13 12:11:24'),
('20260804_backfill_staff_patient_profiles.sql','cb7570930eaa8f3435d3c933efbe79eb768a2e46300adc8a91f59b3e961568c5',0,'2026-09-13 12:11:24'),
('20260804_create_inventory_tables.sql','9778a893968a29dd69a75fb264680178ea9dd7ada246c9efa1cbe561dd63a738',0,'2026-09-13 12:11:24'),
('20260804_create_school_employees.sql','0be9fc9a1a1db7e6b1ffdb95db44ff5a5a6ad185ab3702b561ddd84bbe1a0e6f',0,'2026-09-13 12:11:24'),
('20260804_drop_legacy_school_employee_tables.sql','bd3b8fc6f5b5b9f9ad7f8e2e3cc81c937fa61448cad0cce3b6c23c8ab066f7cf',0,'2026-09-13 12:11:24'),
('20260805_add_visit_lifecycle_timestamps.sql','9e154d840893415a9d700431977b9b83abce25e315cab24b33e6df8db6ca499e',0,'2026-09-13 12:11:24'),
('20260805_create_referrals.sql','2963e5380265365e91eb7834cc2e8c882428b6f674bd0a9d76bf08e43dec1f12',0,'2026-09-13 12:11:24'),
('20260807_create_appointment_tables.sql','551ec9cf5daefab031ee0a3e8754192a0aa5f143ae39cd63a988ae015e8b95e1',0,'2026-09-13 12:11:24'),
('20260808_prevent_appointment_double_booking.sql','1d7011f35f9ee8db19a88dd000149be3a623d6aa8f0c150dbd7df5ba28b86548',0,'2026-09-13 12:11:24'),
('20260809_use_single_account_password_hash.sql','e286856a644d5576252faf30f8b300a51fa68d043013198ac5cce128ee87a6ea',0,'2026-09-13 12:11:24'),
('20260810_create_ape_tables.sql','1242d742bffde592e79dbfbdc15e3075ae0193f1555fd72e700f50d8138eb3b6',0,'2026-09-13 12:11:24'),
('20260811_create_ape_cycles.sql','353af571872915f26d915cfe9aa72abb54da7727b93d2eb234197dafb7bb6d2a',0,'2026-09-13 12:11:24'),
('20260812_add_patient_ape_vitals.sql','a0b505cc4e5cd4cf7d1341512aac2104f16c7d2d87764b563ee7fdf0fa894949',0,'2026-09-13 12:11:24'),
('20260814_passport_alerts_cliniq_db.sql','f6763d517d9255727760ff069f5b5e10799682f9d7f8684359f67ab0d5a7060a',0,'2026-09-13 12:11:24'),
('20260814_settings_staff_cliniq_db.sql','e344258fbf0c4ea15b769436a170666038736bdd6f147f12b3befc37124b0a26',0,'2026-09-13 12:11:24'),
('20260827_add_appointment_for_confirmation.sql','f67923f7e8607c2e77466b2741a98716d74ed31c8440fbdb2faacf446d47d581',0,'2026-09-13 12:11:24'),
('20260830_enforce_one_ape_finding.sql','47a63980a106987657906e166f232057702e589985b3e214e3b4a11bfde60c16',0,'2026-09-13 12:11:24'),
('20260901_add_emergency_contact_details.sql','9cf8cf15568bd9c154a9b9fdec64a559a33ac8324470f21779ec8ada98e0c1da',0,'2026-09-13 12:11:24'),
('20260901_add_patient_password_resets.sql','bfb38911e953998f5b3b335fdb2e230b6eb0397f0a78b05c1b7863eec428ed1b',0,'2026-09-13 12:11:24'),
('20260902_create_ape_schedule_batches.sql','f01b331a650f3b5131a641edbc110bec18464e19e1edfe7a5707b249480e4a87',0,'2026-09-13 12:11:24'),
('20260903_ape_document_upload_groups.sql','5be7fde4f1fdb22f96e77fe23497523c573fd5e80089f2d7b11c8162dc78bbf3',0,'2026-09-13 12:11:24'),
('20260903_ape_requirement_lock_return_time.sql','d5e2ea2eb2fcee23d8867c54cdef950a9df997926cefa96472eece82e2d0ec75',0,'2026-09-13 12:11:24'),
('20260906_remove_inventory_item_codes.sql','8a18019fcb6ff962500a5e787488eb7ebfb0265d3f811b95805b07ddce13c6ec',0,'2026-09-13 12:11:24'),
('20260907_create_clinic_feedback.sql','a92bb0c045374f55c1f716e097f1c2f4e824e3beee9ad735b85373c15530cc89',0,'2026-09-13 12:11:24'),
('20260908_passport_access_audit_reporting.sql','7299d6106c0e398ce142207091850cb8d9c7fd24d252be76cd86fe289ada89cc',0,'2026-09-13 12:11:24'),
('20260909_create_login_attempts.sql','85850453d4bf181252d1bb0410533c7a75beebe14453defc9ee75f3797129912',140,'2026-09-13 12:11:25'),
('20260910_add_passport_bmi_visibility.sql','cb7b8a43ba0c48d9ee48b257c76ed0cd9a3fa9475fd2a04acbfc0c759ff048d9',147,'2026-09-13 12:11:25'),
('20260910_add_profile_photos.sql','5420fc4e6ebefa06685e6a7cc8b829c8332acbce0e765eb2b28ab7626d40c1c6',115,'2026-09-13 12:11:25'),
('20260913_create_patient_notifications.sql','54393ca51fa1a3aa5d4de114cef36d426f3d4cc7503e2f0fccb45b84585454b9',86,'2026-09-13 23:51:45');
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
INSERT INTO `school_employees` VALUES
(38,1,'Faculty','Full-time','Instructor');
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
INSERT INTO `students` VALUES
(17,6,'1','A',NULL),
(18,13,'1','A',NULL),
(19,1,'1','C',NULL),
(20,5,'1','A',NULL),
(21,5,'1','B',NULL),
(22,3,'1','D',NULL),
(23,3,'1','A',NULL),
(24,2,'1','B',NULL),
(25,4,'1','A',NULL),
(26,2,'1','C',NULL),
(27,1,'4','D',NULL),
(28,1,'4','D',NULL),
(29,1,'4','D',NULL),
(55,1,'4','D',NULL),
(72,4,'4','A',NULL),
(73,1,'4','D',NULL);
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
INSERT INTO `system_settings` VALUES
('backup.external_destination','{\"enabled\":true,\"folder\":\"\"}',32,'2026-09-13 14:55:28'),
('clinic.profile','{\"system_name\":\"CLINiQ\",\"institution_name\":\"Pamantasan ng Lungsod ng Pasig\",\"department\":\"University Health Services\",\"contact_email\":\"clinic@plpasig.edu.ph\",\"physical_address\":\"Alcalde Jose Street, Brgy. Kapasigan, Pasig City, Metro Manila, Philippines, 1600\",\"system_purpose\":\"School clinic information management system for patient records, visits, APE workflow, emergency alerts, appointments, inventory, referrals, and reports.\",\"logo_path\":\"uploads\\/settings\\/clinic-logo-20260901064934-ad51692a.png\"}',32,'2026-09-01 04:49:34'),
('clinic.server','{\"configured\":true,\"hostname\":\"gelo\",\"local_ip\":\"192.168.254.123\"}',32,'2026-09-13 15:41:54'),
('clinic.theme','{\"theme\":\"green\",\"custom_color\":\"#000000\"}',32,'2026-09-01 04:30:44'),
('mail.smtp','{\"host\":\"smtp.gmail.com\",\"port\":587,\"encryption\":\"tls\",\"username\":\"plpuhs2026@gmail.com\",\"password\":\"1pvFWDQD7xyYXsgYyFci\\/jo6VCtheDZEQy9KUTJ6cGtnTUEwZ1RVRXRqWjNZZS9PUVlmWDVtVkp4YU5PND0=\",\"from_email\":\"plpuhs2026@gmail.com\",\"from_name\":\"CLINiQ\"}',32,'2026-09-14 05:58:44'),
('mail.template.employee_re_employment','{\"subject\":\"[{{clinic_name}}] Confirm you are still employed \\u2014 new school year\",\"heading\":\"Confirm Employment, {{patient_name}}\",\"message\":\"A new school year has started at {{clinic_name}}. Log in to confirm that you are still employed and continue accessing your health records and clinic services.\",\"button_label\":\"Confirm Employment\",\"footer\":\"If you are no longer employed, ignore this email and your account will remain inactive.\"}',32,'2026-09-02 11:46:30');
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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visit_entries`
--

LOCK TABLES `visit_entries` WRITE;
/*!40000 ALTER TABLE `visit_entries` DISABLE KEYS */;
INSERT INTO `visit_entries` VALUES
(6,6,'Addict','Mukhang Addict','Tokhang',NULL,NULL,NULL,32,'2026-08-05 09:15:33'),
(7,7,NULL,'Sakit teeth',NULL,NULL,NULL,NULL,32,'2026-08-05 09:19:45'),
(10,12,'sparks joy','Infected with cuteness','kurotin mukha',NULL,NULL,NULL,32,'2026-08-27 12:24:23'),
(12,13,NULL,NULL,NULL,NULL,'Medicine dispensing recorded.',NULL,32,'2026-09-06 09:09:13'),
(13,14,NULL,'Tooth pain',NULL,NULL,NULL,NULL,NULL,'2026-09-06 09:22:15'),
(14,14,'Severe','Tooth pain','Visitor/patient self-registration. Awaiting clinic assessment.',NULL,NULL,'edit',32,'2026-09-13 23:56:54'),
(15,15,NULL,'checkup',NULL,NULL,NULL,NULL,32,'2026-09-14 01:06:56'),
(16,16,'ayaw ka gamutin ni doc','ok u do sick','no',NULL,'bawal',NULL,32,'2026-09-14 05:44:56');
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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visits`
--

LOCK TABLES `visits` WRITE;
/*!40000 ALTER TABLE `visits` DISABLE KEYS */;
INSERT INTO `visits` VALUES
(6,17,'2026-08-05 17:14:10',NULL,NULL,'Masakit puso ko','Completed','Medical Consult','Staff Recorded','Tokhang',32,32,'2026-08-05 09:14:10','2026-08-05 09:15:37'),
(7,55,'2026-08-05 17:19:45','2026-08-05 17:52:57','2026-08-05 17:53:06','Sakit teeth','Completed','Dental Consult','Self Logbook',NULL,32,32,'2026-08-05 09:19:45','2026-08-29 09:50:09'),
(12,72,'2026-08-27 20:24:23','2026-08-27 20:25:48','2026-08-27 20:26:58','May sakit','Completed','Medical Consult','Self Logbook','kurotin mukha',32,32,'2026-08-27 12:24:23','2026-08-29 09:50:09'),
(13,27,'2026-09-06 17:09:13','2026-09-06 17:09:13',NULL,'borrowing items','Active','Other','Staff Recorded',NULL,32,32,'2026-09-06 09:09:13','2026-09-06 09:09:13'),
(14,73,'2026-09-06 17:22:15',NULL,NULL,'Tooth pain','Unaddressed','Dental Consult','Self Logbook','Visitor/patient self-registration. Awaiting clinic assessment.',NULL,32,'2026-09-06 09:22:15','2026-09-13 23:56:54'),
(15,73,'2026-09-14 09:06:56','2026-09-14 10:09:11','2026-09-14 10:10:37','checkup','Completed','Medical Consult','Self Logbook',NULL,32,32,'2026-09-14 01:06:56','2026-09-14 02:10:37'),
(16,73,'2026-09-14 13:44:56','2026-09-14 13:46:01','2026-09-14 13:46:41','help me','Completed','Medical Consult','Self Logbook','no',32,32,'2026-09-14 05:44:56','2026-09-14 05:46:41');
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vital_signs`
--

LOCK TABLES `vital_signs` WRITE;
/*!40000 ALTER TABLE `vital_signs` DISABLE KEYS */;
INSERT INTO `vital_signs` VALUES
(5,6,NULL,NULL,36.0,'110/70',190,32,'2026-08-05 17:14:10'),
(6,6,NULL,6,36.0,'110/70',190,32,'2026-08-05 17:15:33');
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

-- Dump completed on 2026-09-14  9:02:52
