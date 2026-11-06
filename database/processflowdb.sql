-- File: database/processflowdb.sql
-- Purpose: Complete ProcessFlow schema and current records; import into an empty database.
-- Author: Imran IT Intern
-- Contains internal data. Back up existing installations before restoring.

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: processflowdb
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
-- Table structure for table `bd_access_roles`
--

DROP TABLE IF EXISTS `bd_access_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_access_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `workflow_role` varchar(50) NOT NULL,
  `default_key` varchar(50) DEFAULT NULL,
  `can_request` tinyint(4) NOT NULL DEFAULT 0,
  `can_work` tinyint(4) NOT NULL DEFAULT 0,
  `can_manage_department` tinyint(4) NOT NULL DEFAULT 0,
  `can_approve` tinyint(4) NOT NULL DEFAULT 0,
  `can_chat` tinyint(4) NOT NULL DEFAULT 0,
  `can_view_all` tinyint(4) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `default_key` (`default_key`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_access_roles`
--

LOCK TABLES `bd_access_roles` WRITE;
/*!40000 ALTER TABLE `bd_access_roles` DISABLE KEYS */;
INSERT INTO `bd_access_roles` VALUES (1,'Staff','STAFF','STAFF',1,1,0,0,1,0,0),(2,'Head of Department','HOD','HOD',1,0,1,1,1,0,0),(3,'C-Suite','C_LEVEL','C_LEVEL',0,0,0,1,1,0,0),(4,'Group CEO','GROUP_CEO','GROUP_CEO',0,0,0,0,0,1,0);
/*!40000 ALTER TABLE `bd_access_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_approval_role_rules`
--

DROP TABLE IF EXISTS `bd_approval_role_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_approval_role_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requester_role_id` int(11) NOT NULL,
  `approver_role_id` int(11) NOT NULL,
  `same_department` tinyint(4) NOT NULL DEFAULT 0,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `approval_role_pair` (`requester_role_id`,`approver_role_id`),
  KEY `approval_rule_approver_fk` (`approver_role_id`),
  CONSTRAINT `approval_rule_approver_fk` FOREIGN KEY (`approver_role_id`) REFERENCES `bd_access_roles` (`id`),
  CONSTRAINT `approval_rule_requester_fk` FOREIGN KEY (`requester_role_id`) REFERENCES `bd_access_roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_approval_role_rules`
--

LOCK TABLES `bd_approval_role_rules` WRITE;
/*!40000 ALTER TABLE `bd_approval_role_rules` DISABLE KEYS */;
INSERT INTO `bd_approval_role_rules` VALUES (1,1,2,1,1),(2,2,3,0,1);
/*!40000 ALTER TABLE `bd_approval_role_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_companies`
--

DROP TABLE IF EXISTS `bd_companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_companies`
--

LOCK TABLES `bd_companies` WRITE;
/*!40000 ALTER TABLE `bd_companies` DISABLE KEYS */;
INSERT INTO `bd_companies` VALUES (1,'Example Company'),(2,'Demo Company');
/*!40000 ALTER TABLE `bd_companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_department_chat_messages`
--

DROP TABLE IF EXISTS `bd_department_chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_department_chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_role` enum('Staff','HOD','BD PIC') NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `department_chat_room` (`project_id`,`department_id`,`id`),
  KEY `department_chat_department_fk` (`department_id`),
  KEY `department_chat_sender_fk` (`sender_id`),
  CONSTRAINT `department_chat_department_fk` FOREIGN KEY (`department_id`) REFERENCES `bd_departments` (`id`),
  CONSTRAINT `department_chat_project_fk` FOREIGN KEY (`project_id`) REFERENCES `bd_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `department_chat_sender_fk` FOREIGN KEY (`sender_id`) REFERENCES `bd_users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_department_chat_messages`
--

LOCK TABLES `bd_department_chat_messages` WRITE;
/*!40000 ALTER TABLE `bd_department_chat_messages` DISABLE KEYS */;
INSERT INTO `bd_department_chat_messages` VALUES (1,15,3,2,'BD PIC','Tunggu Engineering dlu yeee','2026-11-03 07:19:37',NULL,NULL),(2,15,3,8,'HOD','Baik bos','2026-11-03 07:20:05',NULL,NULL),(3,16,2,4,'BD PIC','Buat kerja baik2, nanti hantar kpd Proc.','2026-11-04 00:12:21',NULL,NULL),(4,16,2,5,'HOD','On the way','2026-11-04 00:41:12',NULL,NULL),(5,16,2,7,'Staff','Bagi saya ruang & masa','2026-11-04 00:42:14',NULL,NULL),(6,16,2,4,'BD PIC','Suka hati...','2026-11-04 00:42:25',NULL,NULL),(7,16,2,7,'Staff','Dah submit','2026-11-04 00:44:45',NULL,NULL),(8,16,3,4,'BD PIC','Engineering dah hantar dah tu','2026-11-04 00:47:38',NULL,NULL),(9,16,4,11,'HOD','Resit resit, attach sekali','2026-11-04 01:09:55',NULL,NULL);
/*!40000 ALTER TABLE `bd_department_chat_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_department_tasks`
--

DROP TABLE IF EXISTS `bd_department_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_department_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `assigned_by_hod_id` int(11) NOT NULL,
  `assigned_to_staff_id` int(11) DEFAULT NULL,
  `task_description` text NOT NULL,
  `deliverables_summary` text DEFAULT NULL,
  `reassignment_reason` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'ASSIGNED_TO_STAFF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assignment_started_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `department_id` (`department_id`),
  KEY `assigned_by_hod_id` (`assigned_by_hod_id`),
  KEY `assigned_to_staff_id` (`assigned_to_staff_id`),
  CONSTRAINT `bd_department_tasks_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `bd_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bd_department_tasks_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `bd_departments` (`id`),
  CONSTRAINT `bd_department_tasks_ibfk_3` FOREIGN KEY (`assigned_by_hod_id`) REFERENCES `bd_users` (`id`),
  CONSTRAINT `bd_department_tasks_ibfk_4` FOREIGN KEY (`assigned_to_staff_id`) REFERENCES `bd_users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_department_tasks`
--

LOCK TABLES `bd_department_tasks` WRITE;
/*!40000 ALTER TABLE `bd_department_tasks` DISABLE KEYS */;
INSERT INTO `bd_department_tasks` VALUES (39,13,2,5,6,'Buat kerja','dah',NULL,NULL,'APPROVED_BY_HOD','2026-10-29 06:34:50','2026-11-06 00:29:21','2026-10-29 06:34:50'),(40,13,3,8,9,'asasa','dah',NULL,NULL,'APPROVED_BY_HOD','2026-10-29 06:39:10','2026-11-06 00:29:21','2026-10-29 06:39:10'),(41,13,5,14,15,'asasa','dah',NULL,NULL,'APPROVED_BY_HOD','2026-10-29 06:40:27','2026-11-06 00:29:21','2026-10-29 06:40:27'),(42,13,6,17,19,'Buat kerja','dah',NULL,NULL,'APPROVED_BY_HOD','2026-10-29 06:41:19','2026-11-06 00:29:21','2026-10-29 06:41:19'),(43,13,4,11,12,'asasa','dah',NULL,'uploads/1793257161_Screenshot 2026-10-08 143646.png','APPROVED_BY_HOD','2026-10-29 06:43:56','2026-11-06 00:29:21','2026-10-29 06:43:56'),(44,13,7,20,22,'aaa','aaa',NULL,NULL,'APPROVED_BY_HOD','2026-10-29 07:00:12','2026-11-06 00:29:21','2026-10-29 07:00:12'),(45,13,8,23,25,'aaa','OKay done',NULL,NULL,'APPROVED_BY_HOD','2026-10-29 07:01:09','2026-11-06 00:29:21','2026-10-29 07:01:09'),(46,14,2,5,6,'Buat kerja','Dah',NULL,NULL,'APPROVED_BY_HOD','2026-11-02 03:07:51','2026-11-06 00:29:21','2026-11-02 03:07:51'),(47,14,3,8,9,'Buat kerja','Document already submitted.',NULL,NULL,'APPROVED_BY_HOD','2026-11-02 05:43:08','2026-11-06 00:29:21','2026-11-02 05:43:08'),(48,14,7,20,21,'Buat kerja','Done',NULL,NULL,'APPROVED_BY_HOD','2026-11-02 05:50:04','2026-11-06 00:29:21','2026-11-02 05:50:04'),(49,14,5,14,16,'Buat kerja','BOLEHHH',NULL,NULL,'APPROVED_BY_HOD','2026-11-02 05:51:41','2026-11-06 00:29:21','2026-11-02 05:51:41'),(50,14,6,17,19,'Buat kerja','FAHHH',NULL,NULL,'APPROVED_BY_HOD','2026-11-02 05:52:30','2026-11-06 00:29:21','2026-11-02 05:52:30'),(51,14,8,23,25,'Buat kerja','asd',NULL,NULL,'APPROVED_BY_HOD','2026-11-02 05:53:39','2026-11-06 00:29:21','2026-11-02 05:53:39'),(52,14,4,11,12,'Buat kerja','Done',NULL,NULL,'APPROVED_BY_HOD','2026-11-02 07:34:54','2026-11-06 00:29:21','2026-11-02 07:34:54'),(53,16,2,5,7,'123','1. Beli skru',NULL,'uploads/1793753060_Screenshot 2026-10-08 084253.png','APPROVED_BY_HOD','2026-11-04 00:41:39','2026-11-06 00:29:21','2026-11-04 00:41:39'),(54,16,3,8,9,'Resit','dah',NULL,'uploads/1793754640_Screenshot 2026-10-08 143646.png','APPROVED_BY_HOD','2026-11-04 00:50:21','2026-11-06 00:29:21','2026-11-04 00:50:21'),(55,16,5,14,16,'Buat ni','123',NULL,NULL,'APPROVED_BY_HOD','2026-11-04 01:03:25','2026-11-06 00:29:21','2026-11-04 01:03:25'),(56,16,6,17,18,'aaa','asdasd',NULL,NULL,'APPROVED_BY_HOD','2026-11-04 01:04:32','2026-11-06 00:29:21','2026-11-04 01:04:32'),(57,16,7,20,21,'Buat kerja','asdasd',NULL,NULL,'APPROVED_BY_HOD','2026-11-04 01:05:22','2026-11-06 00:29:21','2026-11-04 01:05:22'),(58,16,8,23,25,'Buat ni','Dah',NULL,NULL,'APPROVED_BY_HOD','2026-11-04 01:06:49','2026-11-06 00:29:21','2026-11-04 01:06:49'),(59,16,4,11,13,'Buat kerja','Okay done',NULL,'uploads/1793754700_Screenshot 2026-10-08 143146.png','APPROVED_BY_HOD','2026-11-04 01:08:26','2026-11-06 00:29:21','2026-11-04 01:08:26');
/*!40000 ALTER TABLE `bd_department_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_department_workflow_rules`
--

DROP TABLE IF EXISTS `bd_department_workflow_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_department_workflow_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_department_id` int(11) NOT NULL,
  `to_department_id` int(11) DEFAULT NULL COMMENT 'NULL means route directly to BD Consolidation',
  `required_involved_department_id` int(11) DEFAULT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_from_dept` (`from_department_id`),
  KEY `fk_to_dept` (`to_department_id`),
  KEY `fk_required_involved_dept` (`required_involved_department_id`),
  CONSTRAINT `fk_dept_wf_from` FOREIGN KEY (`from_department_id`) REFERENCES `bd_departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dept_wf_to` FOREIGN KEY (`to_department_id`) REFERENCES `bd_departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_required_involved_dept` FOREIGN KEY (`required_involved_department_id`) REFERENCES `bd_departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_department_workflow_rules`
--

LOCK TABLES `bd_department_workflow_rules` WRITE;
/*!40000 ALTER TABLE `bd_department_workflow_rules` DISABLE KEYS */;
INSERT INTO `bd_department_workflow_rules` VALUES (1,2,3,3,1,1,'2026-10-26 06:35:56'),(2,2,4,NULL,0,1,'2026-10-26 06:35:56'),(3,3,4,NULL,0,1,'2026-10-26 06:35:56'),(4,5,4,NULL,0,1,'2026-10-26 06:35:56'),(5,6,4,NULL,0,1,'2026-10-26 06:35:56'),(6,4,NULL,NULL,0,1,'2026-10-26 06:35:56'),(7,7,NULL,NULL,0,1,'2026-10-26 06:35:56'),(8,8,NULL,NULL,0,1,'2026-10-26 06:35:56');
/*!40000 ALTER TABLE `bd_department_workflow_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_departments`
--

DROP TABLE IF EXISTS `bd_departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `company_id` int(11) NOT NULL,
  `is_bd_department` tinyint(1) NOT NULL DEFAULT 0,
  `is_consolidation_department` tinyint(1) NOT NULL DEFAULT 0,
  `consolidation_department_id` int(11) DEFAULT NULL,
  `can_request_projects` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  KEY `consolidation_department_id` (`consolidation_department_id`),
  CONSTRAINT `bd_departments_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `bd_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bd_departments_ibfk_2` FOREIGN KEY (`consolidation_department_id`) REFERENCES `bd_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_departments`
--

LOCK TABLES `bd_departments` WRITE;
/*!40000 ALTER TABLE `bd_departments` DISABLE KEYS */;
INSERT INTO `bd_departments` VALUES (1,'Business Development (BD)',1,1,0,NULL,1),(2,'Engineering',1,0,0,4,0),(3,'Procurement',1,0,0,4,0),(4,'Project Development / Finance & Commercial (PDFC)',1,0,1,NULL,0),(5,'Operation (O&M)',1,0,0,4,0),(6,'Project',1,0,0,4,0),(7,'Legal',1,0,0,NULL,0),(8,'Finance',1,0,0,NULL,0);
/*!40000 ALTER TABLE `bd_departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_project_custom_field_choices`
--

DROP TABLE IF EXISTS `bd_project_custom_field_choices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_project_custom_field_choices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `field_id` int(11) NOT NULL,
  `choice_value` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_field_choice` (`field_id`,`choice_value`),
  CONSTRAINT `custom_choice_field_fk` FOREIGN KEY (`field_id`) REFERENCES `bd_project_custom_fields` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_project_custom_field_choices`
--

LOCK TABLES `bd_project_custom_field_choices` WRITE;
/*!40000 ALTER TABLE `bd_project_custom_field_choices` DISABLE KEYS */;
/*!40000 ALTER TABLE `bd_project_custom_field_choices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_project_custom_fields`
--

DROP TABLE IF EXISTS `bd_project_custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_project_custom_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(150) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  `is_required` tinyint(4) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0,
  `field_type` varchar(20) NOT NULL DEFAULT 'text',
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_project_field_label` (`label`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_project_custom_fields`
--

LOCK TABLES `bd_project_custom_fields` WRITE;
/*!40000 ALTER TABLE `bd_project_custom_fields` DISABLE KEYS */;
INSERT INTO `bd_project_custom_fields` VALUES (6,'Siapa owner',1,0,0,1,'text');
/*!40000 ALTER TABLE `bd_project_custom_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_project_form_options`
--

DROP TABLE IF EXISTS `bd_project_form_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_project_form_options` (
  `field_name` varchar(50) NOT NULL,
  `option_value` varchar(100) NOT NULL,
  `label` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `requires_extra` tinyint(4) NOT NULL DEFAULT 0,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`field_name`,`option_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_project_form_options`
--

LOCK TABLES `bd_project_form_options` WRITE;
/*!40000 ALTER TABLE `bd_project_form_options` DISABLE KEYS */;
INSERT INTO `bd_project_form_options` VALUES ('capacity_unit','MW','MW = Transmission',4,0,1,0),('capacity_unit','MWac','MWac = Wind / Hydro',2,0,1,0),('capacity_unit','MWh','MWh = BESS / RECs',3,0,1,0),('capacity_unit','MWp','MWp = Solar',1,0,1,0),('contract_type','BESS Leasing','BESS Leasing',9,0,1,0),('contract_type','BESS Zero Capex','BESS Zero Capex',8,0,1,0),('contract_type','ELA','ELA',5,0,1,0),('contract_type','EPC','EPC',2,0,1,0),('contract_type','EPC & O&M','EPC & O&M',3,0,1,0),('contract_type','I-REC','I-REC',11,0,1,0),('contract_type','O&M','O&M',4,0,1,0),('contract_type','PPA','PPA',1,0,1,0),('contract_type','PTA','PTA',6,0,1,0),('contract_type','SUP','SUP',7,0,1,0),('contract_type','TIGR','TIGR',10,0,1,0),('country_code','AU','AU = Australia',7,0,1,0),('country_code','ID','ID = Indonesia',6,0,1,0),('country_code','JP','JP = Japan',8,0,1,0),('country_code','MY','MY = Malaysia',2,0,1,0),('country_code','NZ','NZ = New Zealand',9,0,1,0),('country_code','PH','PH = Philippines',5,0,1,0),('country_code','SG','SG = Singapore',1,0,1,0),('country_code','TH','TH = Thailand',3,0,1,0),('country_code','VN','VN = Vietnam',4,0,1,0),('project_nature','Brownfield','Brownfield',2,0,1,0),('project_nature','Greenfield','Greenfield',1,1,1,0),('project_nature','Not Applicable','Not Applicable',3,0,1,0),('renewable_energy_type','B','B = BESS',5,0,1,0),('renewable_energy_type','C','C = C&I Solar',1,0,1,0),('renewable_energy_type','G','G = Biogas',7,0,1,0),('renewable_energy_type','H','H = Hydro',3,0,1,0),('renewable_energy_type','M','M = Biomass',8,0,1,0),('renewable_energy_type','R','R = RECs',10,0,1,0),('renewable_energy_type','S','S = Utility Solar',2,0,1,0),('renewable_energy_type','SB','SB = Solar + BESS',6,0,1,0),('renewable_energy_type','T','T = Transmission Line',9,0,1,0),('renewable_energy_type','W','W = Wind',4,0,1,0),('sector','C&I','C&I',1,0,1,0),('sector','Utility','Utility',2,0,1,0),('source_of_lead','Direct','Direct',1,0,1,0),('source_of_lead','EPC Contractor','EPC Contractor',3,1,1,0),('source_of_lead','Existing Client','Existing Client',2,0,1,0),('source_of_lead','Others','Others',5,1,1,0),('source_of_lead','Referral','Referral',4,0,1,0);
/*!40000 ALTER TABLE `bd_project_form_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_project_involved_departments`
--

DROP TABLE IF EXISTS `bd_project_involved_departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_project_involved_departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'IN_PROGRESS',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `bd_project_involved_departments_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `bd_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bd_project_involved_departments_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `bd_departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_project_involved_departments`
--

LOCK TABLES `bd_project_involved_departments` WRITE;
/*!40000 ALTER TABLE `bd_project_involved_departments` DISABLE KEYS */;
INSERT INTO `bd_project_involved_departments` VALUES (66,13,2,'CONSOLIDATION_APPROVED','2026-10-29 06:31:30','2026-10-29 06:42:14'),(67,13,3,'CONSOLIDATION_APPROVED','2026-10-29 06:31:30','2026-10-29 06:42:16'),(68,13,4,'APPROVED','2026-10-29 06:31:30','2026-10-29 06:59:30'),(69,13,5,'CONSOLIDATION_APPROVED','2026-10-29 06:31:30','2026-10-29 06:42:17'),(70,13,6,'CONSOLIDATION_APPROVED','2026-10-29 06:31:30','2026-10-29 06:43:44'),(71,13,7,'APPROVED','2026-10-29 06:31:30','2026-10-29 07:02:16'),(72,13,8,'APPROVED','2026-10-29 06:31:30','2026-10-29 07:02:49'),(73,14,2,'CONSOLIDATION_APPROVED','2026-11-02 02:16:17','2026-11-02 05:55:34'),(74,14,3,'CONSOLIDATION_APPROVED','2026-11-02 02:16:17','2026-11-02 05:55:33'),(75,14,4,'APPROVED','2026-11-02 02:16:17','2026-11-02 07:36:26'),(76,14,5,'CONSOLIDATION_APPROVED','2026-11-02 02:16:17','2026-11-02 05:55:31'),(77,14,6,'CONSOLIDATION_APPROVED','2026-11-02 02:16:17','2026-11-02 05:55:29'),(78,14,7,'APPROVED','2026-11-02 02:16:17','2026-11-02 07:36:30'),(79,14,8,'APPROVED','2026-11-02 02:16:17','2026-11-02 07:36:28'),(80,15,2,'IN_PROGRESS','2026-11-03 07:19:04','2026-11-03 07:19:04'),(81,15,3,'WAITING_FOR_HANDOFF','2026-11-03 07:19:04','2026-11-03 07:19:04'),(82,15,4,'WAITING_FOR_GROUP','2026-11-03 07:19:04','2026-11-03 07:19:04'),(83,15,5,'IN_PROGRESS','2026-11-03 07:19:04','2026-11-03 07:19:04'),(84,15,6,'IN_PROGRESS','2026-11-03 07:19:04','2026-11-03 07:19:04'),(85,15,7,'IN_PROGRESS','2026-11-03 07:19:04','2026-11-03 07:19:04'),(86,15,8,'IN_PROGRESS','2026-11-03 07:19:04','2026-11-03 07:19:04'),(87,16,2,'CONSOLIDATION_APPROVED','2026-11-04 00:11:16','2026-11-04 01:07:46'),(88,16,3,'CONSOLIDATION_APPROVED','2026-11-04 00:11:16','2026-11-04 01:11:08'),(89,16,4,'APPROVED','2026-11-04 00:11:16','2026-11-04 01:11:56'),(90,16,5,'CONSOLIDATION_APPROVED','2026-11-04 00:11:16','2026-11-04 01:07:43'),(91,16,6,'CONSOLIDATION_APPROVED','2026-11-04 00:11:16','2026-11-04 01:07:40'),(92,16,7,'APPROVED','2026-11-04 00:11:16','2026-11-04 01:11:58'),(93,16,8,'APPROVED','2026-11-04 00:11:16','2026-11-04 01:12:00');
/*!40000 ALTER TABLE `bd_project_involved_departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_project_requests`
--

DROP TABLE IF EXISTS `bd_project_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_project_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requester_id` int(11) NOT NULL,
  `approver_id` int(11) NOT NULL,
  `reviewed_by_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `payload_json` longtext NOT NULL,
  `department_ids_json` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'PENDING',
  `decision_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `requester_id` (`requester_id`),
  KEY `approver_id` (`approver_id`),
  KEY `reviewed_by_id` (`reviewed_by_id`),
  KEY `project_id` (`project_id`),
  KEY `status_approver` (`status`,`approver_id`),
  CONSTRAINT `project_requests_approver_fk` FOREIGN KEY (`approver_id`) REFERENCES `bd_users` (`id`),
  CONSTRAINT `project_requests_project_fk` FOREIGN KEY (`project_id`) REFERENCES `bd_projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_requests_requester_fk` FOREIGN KEY (`requester_id`) REFERENCES `bd_users` (`id`),
  CONSTRAINT `project_requests_reviewer_fk` FOREIGN KEY (`reviewed_by_id`) REFERENCES `bd_users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_project_requests`
--

LOCK TABLES `bd_project_requests` WRITE;
/*!40000 ALTER TABLE `bd_project_requests` DISABLE KEYS */;
INSERT INTO `bd_project_requests` VALUES (1,3,2,2,13,'{\"country_code\":\"SG\",\"renewable_energy_type\":\"H\",\"title\":\"asdf\",\"project_ref_no\":\"1222\",\"description\":\"asdasdads\",\"registration_date\":\"2026-10-29\",\"project_deadline_date\":\"2026-10-31\",\"source_of_lead\":\"Direct\",\"lead_company_name\":\"\",\"lead_pic_name\":\"\",\"lead_pic_contact\":\"\",\"lead_pic_email\":\"\",\"client_company_name\":\"KAO GROUP\",\"project_nature\":\"Brownfield\",\"sector\":\"C&I\",\"region\":\"\",\"contract_type\":\"PPA\",\"capacity_unit\":\"MWp\",\"est_mw\":\"123124\",\"est_cod\":null,\"remarks\":\"\"}','[2,3,4,5,6,7,8]','APPROVED','Approved','2026-10-29 06:30:46','2026-10-29 06:31:30'),(2,2,27,27,14,'{\"country_code\":\"MY\",\"renewable_energy_type\":\"H\",\"title\":\"Buka Battery\",\"project_ref_no\":\"1222\",\"description\":\"asdasdasd\",\"registration_date\":\"2026-11-02\",\"project_deadline_date\":\"2026-11-03\",\"source_of_lead\":\"Direct\",\"lead_company_name\":\"\",\"lead_pic_name\":\"\",\"lead_pic_contact\":\"\",\"lead_pic_email\":\"\",\"client_company_name\":\"Ride Anarch\",\"project_nature\":\"Not Applicable\",\"sector\":\"C&I\",\"region\":\"\",\"contract_type\":\"PPA\",\"capacity_unit\":\"MWp\",\"est_mw\":\"32111\",\"est_cod\":null,\"remarks\":\"\"}','[2,3,4,5,6,7,8]','APPROVED','Approved','2026-11-02 02:15:34','2026-11-02 02:16:17'),(3,2,27,27,15,'{\"country_code\":\"SG\",\"renewable_energy_type\":\"W\",\"title\":\"Basikal Solar\",\"project_ref_no\":\"\",\"description\":\"asdasdasd\",\"registration_date\":\"2026-11-03\",\"project_deadline_date\":\"2026-11-10\",\"source_of_lead\":\"Direct\",\"lead_company_name\":\"\",\"lead_pic_name\":\"\",\"lead_pic_contact\":\"\",\"lead_pic_email\":\"\",\"client_company_name\":\"Project Juta Juta\",\"project_nature\":\"Brownfield\",\"sector\":\"Utility\",\"region\":\"\",\"contract_type\":\"TIGR\",\"capacity_unit\":\"MWp\",\"est_mw\":\"112233\",\"est_cod\":null,\"remarks\":\"\"}','[2,3,4,5,6,7,8]','APPROVED','Approved','2026-11-03 07:15:52','2026-11-03 07:19:04'),(4,4,2,2,16,'{\"country_code\":\"TH\",\"renewable_energy_type\":\"H\",\"title\":\"Lantai Elektrik\",\"project_ref_no\":\"\",\"description\":\"Pijak lantai jadi elektrik\",\"registration_date\":\"2026-11-04\",\"project_deadline_date\":\"2026-11-11\",\"source_of_lead\":\"Direct\",\"lead_company_name\":\"\",\"lead_pic_name\":\"\",\"lead_pic_contact\":\"\",\"lead_pic_email\":\"\",\"client_company_name\":\"KAO GROUP\",\"project_nature\":\"Not Applicable\",\"sector\":\"Utility\",\"region\":\"\",\"contract_type\":\"BESS Zero Capex\",\"capacity_unit\":\"MWac\",\"est_mw\":\"12333\",\"est_cod\":null,\"remarks\":\"\"}','[2,3,4,5,6,7,8]','APPROVED','Ngam, jalan teruih','2026-11-04 00:10:37','2026-11-04 00:11:16');
/*!40000 ALTER TABLE `bd_project_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_project_timeline_audits`
--

DROP TABLE IF EXISTS `bd_project_timeline_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_project_timeline_audits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(32) DEFAULT NULL,
  `project_id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `actor_id` int(11) NOT NULL,
  `from_department_id` int(11) DEFAULT NULL,
  `to_department_id` int(11) DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `stage_title` varchar(255) NOT NULL,
  `work_snapshot` longtext DEFAULT NULL,
  `remarks_or_comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_id` (`actor_id`),
  KEY `project_timeline_audits_project_restrict_fk` (`project_id`),
  CONSTRAINT `bd_project_timeline_audits_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `bd_users` (`id`),
  CONSTRAINT `project_timeline_audits_project_restrict_fk` FOREIGN KEY (`project_id`) REFERENCES `bd_projects` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=361 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_project_timeline_audits`
--

LOCK TABLES `bd_project_timeline_audits` WRITE;
/*!40000 ALTER TABLE `bd_project_timeline_audits` DISABLE KEYS */;
INSERT INTO `bd_project_timeline_audits` VALUES (198,'4C38985AD66511F18161E073E7338670',13,NULL,2,NULL,NULL,'PROJECT_CREATED','Project Approved and Activated','Project-level activation, department notifications are recorded separately.','Project [SGH-26-0001] activated after manager approval and assigned to 7 department(s).','2026-10-29 06:31:30'),(199,'841136AEB1669227',13,39,5,2,2,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja\nSubmitted work: dah','Task assigned to Engineering Staff 1 with instructions: Buat kerja','2026-10-29 06:34:50'),(200,'23343DE7CB3618D3',13,39,6,2,2,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-10-29 06:35:13'),(201,'41B6331A39605F5F',13,39,5,2,3,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Buat kerja\nSubmitted work: dah','HOD approved deliverable and submitted to next department.','2026-10-29 06:36:04'),(202,'4C38A46FD66511F18161E073E7338670',13,39,8,2,3,'PROCUREMENT_APPROVED_ENGINEERING','Procurement Received and Approved Engineering Work','Task: Buat kerja\nSubmitted work: dah','Approved for Procurement work.','2026-10-29 06:38:15'),(203,'0703786DF38BA998',13,40,8,3,3,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: asasa\nSubmitted work: dah','Task assigned to Procurement Staff 1 with instructions: asasa','2026-10-29 06:39:10'),(204,'F9383025DB473630',13,40,9,3,3,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: asasa\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-10-29 06:39:31'),(205,'AD29C99AE48CE317',13,40,8,3,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: asasa\nSubmitted work: dah','HOD approved deliverable and submitted to next department.','2026-10-29 06:39:36'),(206,'45FB716B1FC8E08A',13,41,14,5,5,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: asasa\nSubmitted work: dah','Task assigned to Operations Staff 1 with instructions: asasa','2026-10-29 06:40:27'),(207,'B98FAFF17B577D0E',13,41,15,5,5,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: asasa\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-10-29 06:40:41'),(208,'2699CC8C0A89FE56',13,41,14,5,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: asasa\nSubmitted work: dah','HOD approved deliverable and submitted to next department.','2026-10-29 06:40:46'),(209,'B3F43C960D373BBE',13,42,17,6,6,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja\nSubmitted work: dah','Task assigned to Project Staff 1 with instructions: asasa','2026-10-29 06:41:19'),(210,'CB005C6D056FE40D',13,42,18,6,6,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-10-29 06:41:43'),(211,'241AD0A2C7A21AF8',13,42,17,6,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Buat kerja\nSubmitted work: dah','HOD approved deliverable and submitted to next department.','2026-10-29 06:41:46'),(212,'4AADAB89FA3298D3',13,39,11,2,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: Buat kerja\nSubmitted work: dah','Consolidation department reviewed and approved the deliverable.','2026-10-29 06:42:14'),(213,'CE0B091E0DF746EF',13,40,11,3,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: asasa\nSubmitted work: dah','Consolidation department reviewed and approved the deliverable.','2026-10-29 06:42:16'),(214,'71B497A0BC121A1D',13,41,11,5,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: asasa\nSubmitted work: dah','Consolidation department reviewed and approved the deliverable.','2026-10-29 06:42:17'),(215,'0283E810094F2DD1',13,42,11,4,6,'CONSOLIDATION_REJECTED_DEPT','Consolidation Rejected Department Deliverable (Returned to HOD First)','Task: Buat kerja\nSubmitted work: dah','Mana attachment','2026-10-29 06:42:25'),(216,'5F8FB10F059BE3BB',13,42,17,6,6,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja\nSubmitted work: dah','Task assigned to Project Staff 2 with instructions: Buat kerja','2026-10-29 06:42:44'),(217,'0458C03CE7AF0E92',13,42,19,6,6,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-10-29 06:43:09'),(218,'9A1DC367B4BA3220',13,42,17,6,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Buat kerja\nSubmitted work: dah','HOD approved deliverable and submitted to next department.','2026-10-29 06:43:19'),(219,'BC1230C2FC61CB20',13,42,11,6,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: Buat kerja\nSubmitted work: dah','Consolidation department reviewed and approved the deliverable.','2026-10-29 06:43:44'),(220,'A0B67423E6EAE644',13,43,11,4,4,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: asasa\nSubmitted work: dah','Task assigned to PDFC Staff 1 with instructions: Buat kerja','2026-10-29 06:43:56'),(221,'A2482FAB1B27895D',13,43,12,4,4,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: asasa\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-10-29 06:44:14'),(222,'BD315CC2957DD358',13,43,11,4,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: asasa\nSubmitted work: dah','HOD approved deliverables and submitted directly to BD.','2026-10-29 06:44:19'),(223,'68B61C24453332D9',13,43,3,1,4,'REQUESTER_REJECTED','Deliverables Rejected Back to Department','Task: asasa\nSubmitted work: dah','Tak cukup document','2026-10-29 06:44:34'),(224,'F54B702A08795D3D',13,43,11,4,4,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: asasa\nSubmitted work: dah','Task assigned to PDFC Staff 2 with instructions: pdfc report','2026-10-29 06:45:13'),(225,'38911A57BFEA486B',13,43,13,4,4,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: asasa\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-10-29 06:45:32'),(226,'08EBD0BE049C0A67',13,43,11,4,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: asasa\nSubmitted work: dah','HOD approved deliverables and submitted directly to BD.','2026-10-29 06:45:35'),(227,'C8B96F43804758DD',13,43,3,1,4,'REQUESTER_REJECTED','Deliverables Rejected Back to Department','Task: asasa\nSubmitted work: dah','Tak cukup document','2026-10-29 06:45:39'),(228,'72788DAFD51F0186',13,43,11,4,4,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: asasa\nSubmitted work: dah','Task assigned to PDFC Staff 1 with instructions: asasa','2026-10-29 06:58:53'),(229,'765E026E7090DFE5',13,43,12,4,4,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: asasa\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-10-29 06:59:21'),(230,'FCAB32435A3073D2',13,43,11,4,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: asasa\nSubmitted work: dah','HOD approved deliverables and submitted directly to BD.','2026-10-29 06:59:24'),(231,'4049B5AD6960CECA',13,43,3,4,1,'REQUESTER_APPROVED_DEPT','Department Deliverable Approved by BD','Task: asasa\nSubmitted work: dah','BD approved department deliverable.','2026-10-29 06:59:30'),(232,'13144EABAEFB91F2',13,44,20,7,7,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: aaa\nSubmitted work: aaa','Task assigned to Legal Staff 2 with instructions: aaa','2026-10-29 07:00:12'),(233,'FF2852AF0CB59CEC',13,44,22,7,7,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: aaa\nSubmitted work: aaa','Staff submitted deliverable response to HOD.','2026-10-29 07:00:32'),(234,'AB6AEAE07C196A64',13,44,20,7,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: aaa\nSubmitted work: aaa','HOD approved deliverables and submitted directly to BD.','2026-10-29 07:00:35'),(235,'A299A65527DE5108',13,45,23,8,8,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: aaa\nSubmitted work: OKay done','Task assigned to Group Finance Staff 1 with instructions: aaa','2026-10-29 07:01:09'),(236,'D64CD929AD681B6C',13,45,24,8,8,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: aaa\nSubmitted work: OKay done','Staff submitted deliverable response to HOD.','2026-10-29 07:01:22'),(237,'9475D32865CACB80',13,45,23,8,8,'HOD_REJECTED_WORK','Task Rejected back to Staff','Task: aaa\nSubmitted work: OKay done','Bendaaa','2026-10-29 07:01:30'),(238,'DB2BC93B337DEABF',13,45,24,8,8,'STAFF_REQUESTED_REASSIGNMENT','Staff Requested Reassignment','Task: aaa\nSubmitted work: OKay done','Reason: Nak resign','2026-10-29 07:01:38'),(239,'4C38A5D1D66511F18161E073E7338670',13,45,23,8,8,'HOD_APPROVED_REASSIGNMENT','Reassignment approved and assigned to staff ID 25.','Task: aaa\nSubmitted work: OKay done','Staff reason: Nak resign | HOD: SAMBUNG','2026-10-29 07:01:49'),(240,'77CA4D98FDD8C820',13,44,3,7,1,'REQUESTER_APPROVED_DEPT','Department Deliverable Approved by BD','Task: aaa\nSubmitted work: aaa','BD approved department deliverable.','2026-10-29 07:02:16'),(241,'0983180B275A989D',13,45,25,8,8,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: aaa\nSubmitted work: OKay done','Staff submitted deliverable response to HOD.','2026-10-29 07:02:38'),(242,'55AE17B2BC727863',13,45,23,8,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: aaa\nSubmitted work: OKay done','HOD approved deliverables and submitted directly to BD.','2026-10-29 07:02:40'),(243,'404B0285825B6F58',13,45,3,8,1,'REQUESTER_APPROVED_DEPT','Department Deliverable Approved by BD','Task: aaa\nSubmitted work: OKay done','BD approved department deliverable.','2026-10-29 07:02:49'),(244,'4646859B860F9863',13,NULL,3,1,1,'FINALIZATION_SUBMITTED','Project Submitted to Manager for Finalization',NULL,'BD PIC submitted all approved department deliverables to their direct manager for finalization.','2026-10-29 07:02:52'),(245,'4C38A653D66511F18161E073E7338670',13,NULL,2,1,1,'FINALIZATION_APPROVED','Project Finalization Approved',NULL,'','2026-10-29 07:03:51'),(246,'4C38A69DD66511F18161E073E7338670',13,NULL,2,1,2,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Historical project notification reconstructed from project creation.','2026-10-29 06:31:30'),(247,'4C38A6CDD66511F18161E073E7338670',13,NULL,2,1,3,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Historical project notification reconstructed from project creation.','2026-10-29 06:31:30'),(248,'4C38A6FAD66511F18161E073E7338670',13,NULL,2,1,4,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Historical project notification reconstructed from project creation.','2026-10-29 06:31:30'),(249,'4C38A724D66511F18161E073E7338670',13,NULL,2,1,5,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Historical project notification reconstructed from project creation.','2026-10-29 06:31:30'),(250,'4C38A74DD66511F18161E073E7338670',13,NULL,2,1,6,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Historical project notification reconstructed from project creation.','2026-10-29 06:31:30'),(251,'4C38A774D66511F18161E073E7338670',13,NULL,2,1,7,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Historical project notification reconstructed from project creation.','2026-10-29 06:31:30'),(252,'4C38A79ED66511F18161E073E7338670',13,NULL,2,1,8,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Historical project notification reconstructed from project creation.','2026-10-29 06:31:30'),(253,'4C38A859D66511F18161E073E7338670',14,NULL,27,1,2,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-02 02:16:17'),(254,'4C38A882D66511F18161E073E7338670',14,NULL,27,1,3,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified about the project; work is waiting for the required upstream department handoff.','2026-11-02 02:16:17'),(255,'4C38A8B3D66511F18161E073E7338670',14,NULL,27,1,4,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified about the project; work is waiting for the included departments to submit to the consolidation stage.','2026-11-02 02:16:17'),(256,'4C38A8E8D66511F18161E073E7338670',14,NULL,27,1,5,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-02 02:16:17'),(257,'4C38A910D66511F18161E073E7338670',14,NULL,27,1,6,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-02 02:16:17'),(258,'4C38A937D66511F18161E073E7338670',14,NULL,27,1,7,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-02 02:16:17'),(259,'4C38A95DD66511F18161E073E7338670',14,NULL,27,1,8,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-02 02:16:17'),(260,'4C38A984D66511F18161E073E7338670',14,NULL,27,NULL,NULL,'PROJECT_CREATED','Project Approved and Activated','Project-level activation, department notifications are recorded separately.','Project [MYH-26-0001] activated after selected upper level approval and assigned to 7 department(s).','2026-11-02 02:16:17'),(266,'B827DBFA952F0C7D',14,46,5,2,2,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to Engineering Staff 1 with instructions: Buat kerja','2026-11-02 03:07:51'),(267,'45DC60FF182213F6',14,46,6,2,2,'STAFF_REQUESTED_REASSIGNMENT','Staff Requested Reassignment','Task: Buat kerja','Reason: Tak boleh laa','2026-11-02 03:12:40'),(268,'FFBC42893B3AB874',14,46,5,2,2,'HOD_APPROVED_REASSIGNMENT','Reassignment approved and assigned to staff ID 7.','Task: Buat kerja','Staff reason: Tak boleh laa | HOD: SAMBUNG','2026-11-02 03:14:06'),(269,'32C7C1A2A6DEF49A',14,46,7,2,2,'STAFF_REQUESTED_REASSIGNMENT','Staff Requested Reassignment','Task: Buat kerja','Reason: Saya cuti','2026-11-02 03:24:52'),(270,'4E770ADB8F78EC40',14,46,5,2,2,'HOD_DENIED_REASSIGNMENT','Reassignment request rejected; task returned to the current staff member.','Task: Buat kerja','Staff reason: Saya cuti','2026-11-02 03:25:01'),(271,'65EF0F77A0AFF295',14,46,7,2,2,'STAFF_REQUESTED_REASSIGNMENT','Staff Requested Reassignment','Task: Buat kerja','Reason: Nak resign','2026-11-02 03:32:50'),(272,'4E348FB82F125D64',14,46,5,2,2,'HOD_APPROVED_REASSIGNMENT','Reassignment request approved; awaiting HOD selection of another staff member.','Task: Buat kerja','Staff reason: Nak resign','2026-11-02 03:37:46'),(273,'1325BAAEA2789D07',14,46,5,2,2,'HOD_REASSIGNED_TASK','Approved reassignment assigned to Engineering Staff 1.','Task: Buat kerja','Staff reason: Nak resign | HOD: Staff 2 cuti','2026-11-02 03:37:56'),(274,'2C3AA62A955E25F5',14,46,6,2,2,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: Dah','Staff submitted deliverable response to HOD.','2026-11-02 03:45:47'),(275,'D22ABBD7BD33CE9D',14,46,5,2,3,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Buat kerja\nSubmitted work: Dah','HOD approved deliverable and submitted to next department.','2026-11-02 04:19:59'),(276,'D98B82EB11A9F56C',14,46,8,2,3,'PROCUREMENT_APPROVED_ENGINEERING','Procurement Received and Approved Engineering Work','Task: Buat kerja\nSubmitted work: Dah','Engineering work received and approved; Procurement may begin.','2026-11-02 04:24:03'),(277,'E437CB08009D1A00',14,47,8,3,3,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to Procurement Staff 1 with instructions: Buat kerja','2026-11-02 05:43:08'),(278,'3014CDB7267BF290',14,47,9,3,3,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: Document already submitted.','Staff submitted deliverable response to HOD.','2026-11-02 05:43:34'),(279,'77A8D3A1159398BF',14,47,8,3,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Buat kerja\nSubmitted work: Document already submitted.','Dia dah buat kerja','2026-11-02 05:43:59'),(280,'CDD027C126C2623E',14,48,20,7,7,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to Legal Staff 1 with instructions: Buat kerja','2026-11-02 05:50:04'),(281,'CEFBB8B776379643',14,48,21,7,7,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: Done','Staff submitted deliverable response to HOD.','2026-11-02 05:50:20'),(282,'17E75ABDFD121775',14,48,20,7,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: Buat kerja\nSubmitted work: Done','HOD approved deliverables and submitted directly to BD.','2026-11-02 05:50:26'),(283,'37EC13B2F7BF9870',14,49,14,5,5,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to Operations Staff 2 with instructions: Buat kerja','2026-11-02 05:51:41'),(284,'9405A8CAF7B19C60',14,49,16,5,5,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: BOLEHHH','Staff submitted deliverable response to HOD.','2026-11-02 05:52:00'),(285,'463B78FEBF19D0D2',14,49,14,5,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Buat kerja\nSubmitted work: BOLEHHH','HOD approved deliverable and submitted to next department.','2026-11-02 05:52:08'),(286,'F46553B6F8113766',14,50,17,6,6,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to Project Staff 2 with instructions: Buat kerja','2026-11-02 05:52:30'),(287,'ECE2D902DF2742BD',14,50,19,6,6,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: FAHHH','Staff submitted deliverable response to HOD.','2026-11-02 05:52:49'),(288,'1E06DEA41005BA27',14,50,17,6,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Buat kerja\nSubmitted work: FAHHH','HOD approved deliverable and submitted to next department.','2026-11-02 05:53:17'),(289,'269979FD579C86AF',14,51,23,8,8,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to Group Finance Staff 2 with instructions: Buat kerja','2026-11-02 05:53:39'),(290,'77A654973877FDEC',14,51,25,8,8,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: asd','Staff submitted deliverable response to HOD.','2026-11-02 05:53:52'),(291,'B6A49109676B3AAC',14,51,23,8,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: Buat kerja\nSubmitted work: asd','HOD approved deliverables and submitted directly to BD.','2026-11-02 05:53:57'),(292,'B934928210B83237',14,50,11,6,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: Buat kerja\nSubmitted work: FAHHH','Consolidation department reviewed and approved the deliverable.','2026-11-02 05:55:29'),(293,'C9E41638A29BE9D9',14,49,11,5,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: Buat kerja\nSubmitted work: BOLEHHH','Consolidation department reviewed and approved the deliverable.','2026-11-02 05:55:31'),(294,'7BF09E2FD5CEF9DA',14,47,11,3,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: Buat kerja\nSubmitted work: Document already submitted.','Consolidation department reviewed and approved the deliverable.','2026-11-02 05:55:33'),(295,'67C420129CB1FD4F',14,46,11,2,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: Buat kerja\nSubmitted work: Dah','Consolidation department reviewed and approved the deliverable.','2026-11-02 05:55:34'),(296,'559E5AB3336AFA0B',14,52,11,4,4,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to PDFC Staff 1 with instructions: Buat kerja','2026-11-02 07:34:54'),(297,'C67E00DDCCCB5A06',14,52,12,4,4,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: Done','Staff submitted deliverable response to HOD.','2026-11-02 07:35:16'),(298,'B944506273E9CEB1',14,52,11,4,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: Buat kerja\nSubmitted work: Done','HOD approved deliverables and submitted directly to BD.','2026-11-02 07:36:10'),(299,'AC9FBDFE60046260',14,52,2,4,1,'REQUESTER_APPROVED_DEPT','Department Deliverable Approved by BD','Task: Buat kerja\nSubmitted work: Done','BD approved department deliverable.','2026-11-02 07:36:26'),(300,'1D4BEBF64BF9B251',14,51,2,8,1,'REQUESTER_APPROVED_DEPT','Department Deliverable Approved by BD','Task: Buat kerja\nSubmitted work: asd','BD approved department deliverable.','2026-11-02 07:36:28'),(301,'EC88B29BD98DDA30',14,48,2,7,1,'REQUESTER_APPROVED_DEPT','Department Deliverable Approved by BD','Task: Buat kerja\nSubmitted work: Done','BD approved department deliverable.','2026-11-02 07:36:30'),(302,'5135D395E35B0273',14,NULL,2,1,1,'FINALIZATION_SUBMITTED','Project Submitted to Manager for Finalization','Final project package submitted for manager finalization.','DONE!!!','2026-11-02 07:36:41'),(303,'4FA3972451D447D3',14,NULL,27,1,1,'FINALIZATION_APPROVED','Project Finalization Approved','BD finalization package reviewed by the direct approver.','Approved by manager.','2026-11-02 07:37:05'),(304,'F21683305D669F7A',14,NULL,1,NULL,NULL,'ADMIN_PROJECT_DETAILS_UPDATED','Project Details Updated',NULL,'An administrator updated the project title, client, or description.','2026-11-03 03:29:21'),(305,'4AEEDD9DF4F351FC',15,NULL,27,1,2,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-03 07:19:04'),(306,'5210395A0467ECE9',15,NULL,27,1,3,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified about the project; work is waiting for the required upstream department handoff.','2026-11-03 07:19:04'),(307,'85273A975B0F1AEE',15,NULL,27,1,4,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified about the project; work is waiting for the included departments to submit to the consolidation stage.','2026-11-03 07:19:04'),(308,'B9F6E2607C4B3E50',15,NULL,27,1,5,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-03 07:19:04'),(309,'6C993FDFB4C2D1A4',15,NULL,27,1,6,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-03 07:19:04'),(310,'CB37D1D17FD37161',15,NULL,27,1,7,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-03 07:19:04'),(311,'A3D5088D842D7080',15,NULL,27,1,8,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-03 07:19:04'),(312,'C5CA4B1A54FF5938',15,NULL,27,NULL,NULL,'PROJECT_CREATED','Project Approved and Activated','Project-level activation, department notifications are recorded separately.','Project [SGW-26-0001] activated after selected upper level approval and assigned to 7 department(s).','2026-11-03 07:19:04'),(313,'F586311C7788C4D2',16,NULL,2,1,2,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-04 00:11:16'),(314,'7402ED011024656C',16,NULL,2,1,3,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified about the project; work is waiting for the required upstream department handoff.','2026-11-04 00:11:16'),(315,'CF150FEDFEC3DB6C',16,NULL,2,1,4,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified about the project; work is waiting for the included departments to submit to the consolidation stage.','2026-11-04 00:11:16'),(316,'71B1CA40FC9F0C1B',16,NULL,2,1,5,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-04 00:11:16'),(317,'D48EDB33E462FE78',16,NULL,2,1,6,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-04 00:11:16'),(318,'6DBDE2B47357F4B5',16,NULL,2,1,7,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-04 00:11:16'),(319,'A720467CDA8037D2',16,NULL,2,1,8,'PROJECT_DEPARTMENT_NOTIFIED','Project Notification Sent to Department','Project notification only, department task assignment is pending.','Department notified that the project is active and work may begin.','2026-11-04 00:11:16'),(320,'84CA83AD36B2B417',16,NULL,2,NULL,NULL,'PROJECT_CREATED','Project Approved and Activated','Project-level activation, department notifications are recorded separately.','Project [THH-26-0001] activated after selected upper level approval and assigned to 7 department(s).','2026-11-04 00:11:16'),(321,'23AFB9A380AF213F',16,53,5,2,2,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: 123','Task assigned to Engineering Staff 2 with instructions: 123','2026-11-04 00:41:39'),(322,'17AC611B54064E76',16,53,7,2,2,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: 123\nSubmitted work: 1. Beli skru','Staff submitted deliverable response to HOD.','2026-11-04 00:44:20'),(323,'7343ED06B4B5A4DE',16,53,5,2,3,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: 123\nSubmitted work: 1. Beli skru','HOD approved deliverable and submitted to next department.','2026-11-04 00:45:42'),(324,'DF98849001C2C70C',16,53,8,2,3,'HANDOFF_APPROVED','Department handoff approved','Task: 123\nSubmitted work: 1. Beli skru','Engineering work approved by Procurement.','2026-11-04 00:49:47'),(325,'C9993B2922EB6071',16,54,8,3,3,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to Procurement Staff 1 with instructions: Buat kerja','2026-11-04 00:50:21'),(326,'C92608275ECE1736',16,54,9,3,3,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: 123','Staff submitted deliverable response to HOD.','2026-11-04 00:50:42'),(327,'CB0F02E9A62545D4',16,54,8,3,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Buat kerja\nSubmitted work: 123','HOD approved deliverable and submitted to next department.','2026-11-04 00:50:52'),(328,'7C76CD966744E34C',16,55,14,5,5,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat ni','Task assigned to Operations Staff 2 with instructions: Buat ni','2026-11-04 01:03:25'),(329,'DFB6F4B3C8C39C91',16,55,16,5,5,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat ni\nSubmitted work: 123','Staff submitted deliverable response to HOD.','2026-11-04 01:03:56'),(330,'D139218F7FC6E281',16,55,14,5,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Buat ni\nSubmitted work: 123','HOD approved deliverable and submitted to next department.','2026-11-04 01:04:06'),(331,'7CE78375B2E3E287',16,56,17,6,6,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: aaa','Task assigned to Project Staff 1 with instructions: aaa','2026-11-04 01:04:32'),(332,'F2922B4197061184',16,56,18,6,6,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: aaa\nSubmitted work: asdasd','Staff submitted deliverable response to HOD.','2026-11-04 01:04:51'),(333,'CCB337519A9882D5',16,56,17,6,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: aaa\nSubmitted work: asdasd','HOD approved deliverable and submitted to next department.','2026-11-04 01:05:01'),(334,'08873BA390E3C305',16,57,20,7,7,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to Legal Staff 1 with instructions: Buat kerja','2026-11-04 01:05:22'),(335,'DC2371F4DFE9A63F',16,57,21,7,7,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: asdasd','Staff submitted deliverable response to HOD.','2026-11-04 01:06:24'),(336,'E5DEA761EB4A87B1',16,57,20,7,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: Buat kerja\nSubmitted work: asdasd','HOD approved deliverables and submitted directly to BD.','2026-11-04 01:06:30'),(337,'C6A900B818BBFB92',16,58,23,8,8,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat ni','Task assigned to Group Finance Staff 2 with instructions: Buat ni','2026-11-04 01:06:49'),(338,'05B524519789FF7C',16,58,25,8,8,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat ni\nSubmitted work: Dah','Staff submitted deliverable response to HOD.','2026-11-04 01:07:03'),(339,'F511E031A5FD2E0B',16,58,23,8,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: Buat ni\nSubmitted work: Dah','HOD approved deliverables and submitted directly to BD.','2026-11-04 01:07:08'),(340,'FD11C3348FA90D39',16,56,11,6,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: aaa\nSubmitted work: asdasd','Consolidation department reviewed and approved the deliverable.','2026-11-04 01:07:40'),(341,'E29BF041C53B5234',16,55,11,5,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: Buat ni\nSubmitted work: 123','Consolidation department reviewed and approved the deliverable.','2026-11-04 01:07:43'),(342,'05A6EB33B93B18FD',16,53,11,2,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: 123\nSubmitted work: 1. Beli skru','Consolidation department reviewed and approved the deliverable.','2026-11-04 01:07:46'),(343,'1AB5811A162C5144',16,54,11,3,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: Buat kerja\nSubmitted work: 123','Consolidation department reviewed and approved the deliverable.','2026-11-04 01:07:51'),(344,'448486233F89A8B7',16,59,11,4,4,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: 123','Task assigned to PDFC Staff 1 with instructions: 123','2026-11-04 01:08:26'),(345,'3A7A2247C037B2C7',16,59,12,4,4,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: 123\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-11-04 01:08:41'),(346,'151AF5AD93BC2575',16,59,11,4,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: 123\nSubmitted work: dah','HOD approved deliverables and submitted directly to BD.','2026-11-04 01:08:45'),(347,'C6369114D0F3714A',16,59,4,1,4,'REQUESTER_REJECTED','Deliverables Rejected Back to Department','Task: 123\nSubmitted work: dah','Mana attachment resit','2026-11-04 01:09:07'),(348,'351DF63B55BC2F0F',16,54,11,4,3,'CONSOLIDATION_REASSIGNED_REWORK','Consolidation Delegated Rework (Sent to HOD First)','Task: Buat kerja\nSubmitted work: 123','Resit bang','2026-11-04 01:09:33'),(349,'A9AB52E087A0B139',16,54,8,3,3,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Resit','Task assigned to Procurement Staff 1 with instructions: Resit','2026-11-04 01:10:20'),(350,'D895D0B9EED5243F',16,54,9,3,3,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Resit\nSubmitted work: dah','Staff submitted deliverable response to HOD.','2026-11-04 01:10:40'),(351,'BA199918D48E164F',16,54,8,3,4,'HOD_FORWARDED_DEPT','Forwarded Deliverable to Next Department','Task: Resit\nSubmitted work: dah','HOD approved deliverable and submitted to next department.','2026-11-04 01:10:49'),(352,'69E7FADF2EA86E39',16,54,11,3,4,'CONSOLIDATION_APPROVED_DEPT','Consolidation Approved Department Deliverable','Task: Resit\nSubmitted work: dah','Consolidation department reviewed and approved the deliverable.','2026-11-04 01:11:08'),(353,'0A520DEC448940E2',16,59,11,4,4,'HOD_ASSIGNED_TASK','Task Assigned to Staff by HOD','Task: Buat kerja','Task assigned to PDFC Staff 2 with instructions: Buat kerja','2026-11-04 01:11:17'),(354,'0D309ED333CFDE5D',16,59,13,4,4,'STAFF_SUBMITTED_WORK','Task Deliverable Submitted to HOD','Task: Buat kerja\nSubmitted work: Okay done','Staff submitted deliverable response to HOD.','2026-11-04 01:11:40'),(355,'2F73BDD72C889949',16,59,11,4,1,'HOD_SUBMITTED_TO_BD','Submitted to BD Consolidation','Task: Buat kerja\nSubmitted work: Okay done','HOD approved deliverables and submitted directly to BD.','2026-11-04 01:11:47'),(356,'5DFB2AEF152DB69B',16,59,4,4,1,'REQUESTER_APPROVED_DEPT','Department Deliverable Approved by BD','Task: Buat kerja\nSubmitted work: Okay done','BD approved department deliverable.','2026-11-04 01:11:56'),(357,'D36218BB442646A2',16,57,4,7,1,'REQUESTER_APPROVED_DEPT','Department Deliverable Approved by BD','Task: Buat kerja\nSubmitted work: asdasd','BD approved department deliverable.','2026-11-04 01:11:58'),(358,'1A858CBEE900C2A1',16,58,4,8,1,'REQUESTER_APPROVED_DEPT','Department Deliverable Approved by BD','Task: Buat ni\nSubmitted work: Dah','BD approved department deliverable.','2026-11-04 01:12:00'),(359,'3086C97BAA32A034',16,NULL,4,1,1,'FINALIZATION_SUBMITTED','Project Submitted to Manager for Finalization','Final project package submitted for manager finalization.','Review dan submit','2026-11-04 01:12:24'),(360,'F6F7F1F4C4F60204',16,NULL,2,1,1,'FINALIZATION_APPROVED','Project Finalization Approved','BD finalization package reviewed by the direct approver.','Approved by manager.','2026-11-04 01:13:14');
/*!40000 ALTER TABLE `bd_project_timeline_audits` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER processflow_audit_token_before_insert
BEFORE INSERT ON `bd_project_timeline_audits` FOR EACH ROW
SET NEW.token = CASE
    WHEN NEW.token IS NULL OR TRIM(NEW.token) = '' OR UPPER(TRIM(NEW.token)) = 'N/A'
    THEN UPPER(REPLACE(UUID(), '-', ''))
    ELSE NEW.token
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `bd_projects`
--

DROP TABLE IF EXISTS `bd_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_code` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `project_ref_no` varchar(100) DEFAULT NULL,
  `description` text NOT NULL,
  `requester_id` int(11) NOT NULL,
  `registration_date` date NOT NULL,
  `project_deadline_date` date NOT NULL,
  `source_of_lead` varchar(100) NOT NULL,
  `lead_company_name` varchar(255) DEFAULT NULL,
  `lead_pic_name` varchar(255) DEFAULT NULL,
  `lead_pic_contact` varchar(100) DEFAULT NULL,
  `lead_pic_email` varchar(255) DEFAULT NULL,
  `client_company_name` varchar(255) NOT NULL,
  `project_nature` varchar(100) NOT NULL,
  `sector` varchar(100) NOT NULL,
  `renewable_energy_type` varchar(50) NOT NULL,
  `country_code` varchar(10) NOT NULL,
  `region` varchar(150) DEFAULT NULL,
  `contract_type` varchar(100) NOT NULL,
  `capacity_unit` varchar(20) NOT NULL,
  `est_mw` decimal(10,2) NOT NULL,
  `est_cod` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'IN_PROGRESS',
  `current_department_id` int(11) DEFAULT NULL,
  `previous_department_id` int(11) DEFAULT NULL,
  `final_approver_id` int(11) DEFAULT NULL,
  `final_approval_status` varchar(30) NOT NULL DEFAULT 'NOT_SUBMITTED',
  `final_approval_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_code` (`project_code`),
  KEY `requester_id` (`requester_id`),
  KEY `final_approver_id` (`final_approver_id`),
  CONSTRAINT `bd_projects_ibfk_1` FOREIGN KEY (`requester_id`) REFERENCES `bd_users` (`id`),
  CONSTRAINT `projects_final_approver_fk` FOREIGN KEY (`final_approver_id`) REFERENCES `bd_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_projects`
--

LOCK TABLES `bd_projects` WRITE;
/*!40000 ALTER TABLE `bd_projects` DISABLE KEYS */;
INSERT INTO `bd_projects` VALUES (13,'SGH-26-0001','asdf','1222','asdasdads',3,'2026-10-29','2026-10-31','Direct','','','','','KAO GROUP','Brownfield','C&I','H','SG','','PPA','MWp',123124.00,NULL,'','COMPLETED',NULL,8,2,'APPROVED','Approved by manager.','2026-10-29 06:31:30','2026-10-29 07:03:51'),(14,'MYH-26-0001','Buka Battery - Completed','1222','asdasdasd',2,'2026-11-02','2026-11-03','Direct','','','','','Ride Anarch','Not Applicable','C&I','H','MY','','PPA','MWp',32111.00,NULL,'','COMPLETED',NULL,4,27,'APPROVED','Approved by manager.','2026-11-02 02:16:17','2026-11-03 03:29:21'),(15,'SGW-26-0001','Basikal Solar','','asdasdasd',2,'2026-11-03','2026-11-10','Direct','','','','','Project Juta Juta','Brownfield','Utility','W','SG','','TIGR','MWp',112233.00,NULL,'','IN_PROGRESS',NULL,NULL,27,'NOT_SUBMITTED',NULL,'2026-11-03 07:19:04','2026-11-03 07:19:04'),(16,'THH-26-0001','Lantai Elektrik','','Pijak lantai jadi elektrik',4,'2026-11-04','2026-11-11','Direct','','','','','KAO GROUP','Not Applicable','Utility','H','TH','','BESS Zero Capex','MWac',12333.00,NULL,'','COMPLETED',NULL,4,2,'APPROVED','Approved by manager.','2026-11-04 00:11:16','2026-11-04 01:13:14');
/*!40000 ALTER TABLE `bd_projects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bd_users`
--

DROP TABLE IF EXISTS `bd_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bd_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'STAFF',
  `department_id` int(11) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `access_role_id` int(11) DEFAULT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `department_id` (`department_id`),
  KEY `manager_id` (`manager_id`),
  KEY `users_access_role_fk` (`access_role_id`),
  CONSTRAINT `bd_users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `bd_departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_access_role_fk` FOREIGN KEY (`access_role_id`) REFERENCES `bd_access_roles` (`id`),
  CONSTRAINT `users_manager_fk` FOREIGN KEY (`manager_id`) REFERENCES `bd_users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bd_users`
--

LOCK TABLES `bd_users` WRITE;
/*!40000 ALTER TABLE `bd_users` DISABLE KEYS */;
INSERT INTO `bd_users` VALUES (1,'admin','$2y$10$GthuXc5vV3vg2XuDQcxyVuMLOFWvdohk1TM449svJk924W3qagW5.','admin@example.com','System Admin','ADMIN',NULL,NULL,1,NULL,0),(2,NULL,NULL,'bd.hod@example.com','BD HOD','HOD',1,27,1,2,0),(3,NULL,NULL,'bd.staff1@example.com','BD Staff 1','STAFF',1,2,1,1,0),(4,NULL,NULL,'bd.staff2@example.com','BD Staff 2','STAFF',1,2,1,1,0),(5,NULL,NULL,'eng.hod@example.com','Engineering HOD','HOD',2,NULL,1,2,0),(6,NULL,NULL,'eng.staff1@example.com','Engineering Staff 1','STAFF',2,5,1,1,0),(7,NULL,NULL,'eng.staff2@example.com','Engineering Staff 2','STAFF',2,5,1,1,0),(8,NULL,NULL,'proc.hod@example.com','Procurement HOD','HOD',3,NULL,1,2,0),(9,NULL,NULL,'proc.staff1@example.com','Procurement Staff 1','STAFF',3,8,1,1,0),(10,NULL,NULL,'proc.staff2@example.com','Procurement Staff 2','STAFF',3,8,1,1,0),(11,NULL,NULL,'pdfc.hod@example.com','PDFC HOD','HOD',4,NULL,1,2,0),(12,NULL,NULL,'pdfc.staff1@example.com','PDFC Staff 1','STAFF',4,11,1,1,0),(13,NULL,NULL,'pdfc.staff2@example.com','PDFC Staff 2','STAFF',4,11,1,1,0),(14,NULL,NULL,'om.hod@example.com','Operations HOD','HOD',5,NULL,1,2,0),(15,NULL,NULL,'om.staff1@example.com','Operations Staff 1','STAFF',5,14,1,1,0),(16,NULL,NULL,'om.staff2@example.com','Operations Staff 2','STAFF',5,14,1,1,0),(17,NULL,NULL,'proj.hod@example.com','Project HOD','HOD',6,NULL,1,2,0),(18,NULL,NULL,'proj.staff1@example.com','Project Staff 1','STAFF',6,17,1,1,0),(19,NULL,NULL,'proj.staff2@example.com','Project Staff 2','STAFF',6,17,1,1,0),(20,NULL,NULL,'legal.hod@example.com','Legal HOD','HOD',7,NULL,1,2,0),(21,NULL,NULL,'legal.staff1@example.com','Legal Staff 1','STAFF',7,20,1,1,0),(22,NULL,NULL,'legal.staff2@example.com','Legal Staff 2','STAFF',7,20,1,1,0),(23,NULL,NULL,'fin.hod@example.com','Group Finance HOD','HOD',8,NULL,1,2,0),(24,NULL,NULL,'fin.staff1@example.com','Group Finance Staff 1','STAFF',8,23,1,1,0),(25,NULL,NULL,'fin.staff2@example.com','Group Finance Staff 2','STAFF',8,23,1,1,0),(26,NULL,NULL,'gceo@example.com','Group CEO','GROUP_CEO',NULL,NULL,1,4,0),(27,NULL,NULL,'bd.csuite@example.com','BD C-Suite','C_LEVEL',1,26,1,3,0);
/*!40000 ALTER TABLE `bd_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'processflowdb'
--

--
-- Dumping routines for database 'processflowdb'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-06  9:15:54
