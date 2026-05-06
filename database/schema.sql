-- Fresh Database Schema
-- Generated: 2026-02-09 05:31:46

SET FOREIGN_KEY_CHECKS = 0;

-- Table: activity_log
DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=387 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: admin_credentials
DROP TABLE IF EXISTS `admin_credentials`;
CREATE TABLE `admin_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category` enum('Software','Device','Account','Server','Database','API','Other') DEFAULT 'Other',
  `username` varchar(255) DEFAULT NULL,
  `password_encrypted` text DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL,
  `last_used` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_category` (`admin_id`,`category`),
  KEY `idx_tags` (`tags`),
  FULLTEXT KEY `idx_search` (`title`,`notes`,`tags`),
  CONSTRAINT `admin_credentials_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: admin_meetings
DROP TABLE IF EXISTS `admin_meetings`;
CREATE TABLE `admin_meetings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `meeting_with` varchar(255) DEFAULT NULL,
  `meeting_date` date NOT NULL,
  `meeting_time` time NOT NULL,
  `duration_minutes` int(11) DEFAULT 30,
  `location` varchar(255) DEFAULT NULL,
  `meeting_link` varchar(500) DEFAULT NULL,
  `reminder_minutes` int(11) DEFAULT 15,
  `reminder_sent` tinyint(1) DEFAULT 0,
  `status` enum('Scheduled','Completed','Cancelled','Rescheduled') DEFAULT 'Scheduled',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_date` (`admin_id`,`meeting_date`),
  KEY `idx_admin_status` (`admin_id`,`status`),
  KEY `idx_reminder` (`meeting_date`,`meeting_time`,`reminder_sent`),
  FULLTEXT KEY `idx_search` (`title`,`description`,`meeting_with`),
  CONSTRAINT `admin_meetings_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: admin_notes
DROP TABLE IF EXISTS `admin_notes`;
CREATE TABLE `admin_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text DEFAULT NULL,
  `category` enum('General','Project','Meeting','Technical','Personal','Important') DEFAULT 'General',
  `color` varchar(20) DEFAULT '#ffffff',
  `is_pinned` tinyint(1) DEFAULT 0,
  `tags` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_pinned` (`admin_id`,`is_pinned`),
  KEY `idx_admin_category` (`admin_id`,`category`),
  FULLTEXT KEY `idx_search` (`title`,`content`,`tags`),
  CONSTRAINT `admin_notes_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: admin_todos
DROP TABLE IF EXISTS `admin_todos`;
CREATE TABLE `admin_todos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('Low','Medium','High','Critical') DEFAULT 'Medium',
  `status` enum('Pending','In Progress','Completed','Cancelled') DEFAULT 'Pending',
  `due_date` date DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_status` (`admin_id`,`status`),
  KEY `idx_admin_priority` (`admin_id`,`priority`),
  KEY `idx_due_date` (`due_date`),
  FULLTEXT KEY `idx_search` (`title`,`description`,`tags`),
  CONSTRAINT `admin_todos_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: affected_user_groups
DROP TABLE IF EXISTS `affected_user_groups`;
CREATE TABLE `affected_user_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: assignments
DROP TABLE IF EXISTS `assignments`;
CREATE TABLE `assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `page_id` int(11) DEFAULT NULL,
  `environment_id` int(11) DEFAULT NULL,
  `task_type` enum('page_assignment','env_assignment','testing_task','other','regression') NOT NULL DEFAULT 'other',
  `assigned_user_id` int(11) NOT NULL,
  `assigned_role` varchar(50) DEFAULT NULL,
  `meta` longtext DEFAULT NULL COMMENT 'JSON metadata (json_valid expected)',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_assignments_project` (`project_id`),
  KEY `idx_assignments_page` (`page_id`),
  KEY `idx_assignments_env` (`environment_id`),
  KEY `idx_assignments_user` (`assigned_user_id`),
  CONSTRAINT `assignments_fk_env` FOREIGN KEY (`environment_id`) REFERENCES `testing_environments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignments_fk_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignments_fk_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_assignments_page_id_project` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: automated_findings
DROP TABLE IF EXISTS `automated_findings`;
CREATE TABLE `automated_findings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_id` int(11) NOT NULL,
  `environment_id` int(11) DEFAULT NULL,
  `instance_name` varchar(255) DEFAULT NULL,
  `issue_description` text DEFAULT NULL,
  `wcag_failure` varchar(255) DEFAULT NULL,
  `detected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `environment_id` (`environment_id`),
  KEY `fk_automated_findings_page_id_project` (`page_id`),
  CONSTRAINT `automated_findings_ibfk_2` FOREIGN KEY (`environment_id`) REFERENCES `testing_environments` (`id`),
  CONSTRAINT `fk_automated_findings_page_id_project` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: chat_messages
DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `page_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `mentions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`mentions`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reply_to` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `chat_messages_ibfk_1` (`project_id`),
  KEY `chat_messages_ibfk_2` (`page_id`),
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_chat_messages_page_id_project` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: clients
DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `project_code_prefix` varchar(20) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `clients_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: common_issues
DROP TABLE IF EXISTS `common_issues`;
CREATE TABLE `common_issues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_issue` (`issue_id`),
  CONSTRAINT `fk_common_issues_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_common_issues_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: daily_hours_compliance
DROP TABLE IF EXISTS `daily_hours_compliance`;
CREATE TABLE `daily_hours_compliance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `total_hours` decimal(5,2) DEFAULT 0.00,
  `is_compliant` tinyint(1) DEFAULT 0,
  `reminder_sent` tinyint(1) DEFAULT 0,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `checked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_date` (`user_id`,`date`),
  KEY `idx_date` (`date`),
  KEY `idx_compliant` (`is_compliant`),
  KEY `idx_user_date` (`user_id`,`date`),
  CONSTRAINT `daily_hours_compliance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: device_assignments
DROP TABLE IF EXISTS `device_assignments`;
CREATE TABLE `device_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `returned_at` timestamp NULL DEFAULT NULL,
  `status` enum('Active','Returned') DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `assigned_by` (`assigned_by`),
  KEY `idx_device_user` (`device_id`,`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `device_assignments_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_assignments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: device_rotation_history
DROP TABLE IF EXISTS `device_rotation_history`;
CREATE TABLE `device_rotation_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `from_user_id` int(11) DEFAULT NULL,
  `to_user_id` int(11) NOT NULL,
  `rotated_by` int(11) NOT NULL,
  `rotation_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `from_user_id` (`from_user_id`),
  KEY `to_user_id` (`to_user_id`),
  KEY `rotated_by` (`rotated_by`),
  KEY `idx_device` (`device_id`),
  KEY `idx_date` (`rotation_date`),
  CONSTRAINT `device_rotation_history_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_rotation_history_ibfk_2` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `device_rotation_history_ibfk_3` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_rotation_history_ibfk_4` FOREIGN KEY (`rotated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: device_switch_requests
DROP TABLE IF EXISTS `device_switch_requests`;
CREATE TABLE `device_switch_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `current_holder` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Cancelled') DEFAULT 'Pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `responded_at` timestamp NULL DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `response_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `responded_by` (`responded_by`),
  KEY `idx_status` (`status`),
  KEY `idx_requested_by` (`requested_by`),
  KEY `idx_current_holder` (`current_holder`),
  CONSTRAINT `device_switch_requests_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_switch_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_switch_requests_ibfk_3` FOREIGN KEY (`current_holder`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_switch_requests_ibfk_4` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: devices
DROP TABLE IF EXISTS `devices`;
CREATE TABLE `devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_name` varchar(255) NOT NULL,
  `device_type` enum('Android','iOS','Mac','Windows','BT Keyboard','Mouse','Tablet','Other') NOT NULL,
  `model` varchar(255) DEFAULT NULL,
  `version` varchar(100) DEFAULT NULL,
  `serial_number` varchar(255) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `status` enum('Available','Assigned','Maintenance','Retired') DEFAULT 'Available',
  `ownership_type` enum('Owned','Leased') NOT NULL DEFAULT 'Owned',
  `lease_owner` varchar(255) DEFAULT NULL,
  `storage_capacity` int(11) DEFAULT NULL,
  `charger_wire` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_device_type` (`device_type`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: env_status_master
DROP TABLE IF EXISTS `env_status_master`;
CREATE TABLE `env_status_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status_key` varchar(50) NOT NULL,
  `status_label` varchar(100) NOT NULL,
  `badge_color` varchar(20) NOT NULL DEFAULT '#6c757d',
  `description` text DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `status_key` (`status_key`),
  KEY `idx_status_key` (`status_key`),
  KEY `idx_display_order` (`display_order`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: feedback_recipients
DROP TABLE IF EXISTS `feedback_recipients`;
CREATE TABLE `feedback_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `feedback_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `feedback_id` (`feedback_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_fr_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `feedbacks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: feedbacks
DROP TABLE IF EXISTS `feedbacks`;
CREATE TABLE `feedbacks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `send_to_admin` tinyint(1) NOT NULL DEFAULT 0,
  `send_to_lead` tinyint(1) NOT NULL DEFAULT 0,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `project_id` int(11) DEFAULT NULL,
  `is_generic` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `target_user_id` (`target_user_id`),
  KEY `status` (`status`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `idx_feedbacks_status` (`status`),
  CONSTRAINT `fk_feedback_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_feedback_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feedback_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: generic_task_categories
DROP TABLE IF EXISTS `generic_task_categories`;
CREATE TABLE `generic_task_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: gigw_standards
DROP TABLE IF EXISTS `gigw_standards`;
CREATE TABLE `gigw_standards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clause_number` varchar(20) NOT NULL,
  `clause_name` varchar(255) NOT NULL,
  `version` varchar(10) DEFAULT '3.0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: grouped_urls
DROP TABLE IF EXISTS `grouped_urls`;
CREATE TABLE `grouped_urls` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `unique_page_id` int(11) DEFAULT NULL,
  `url` text NOT NULL,
  `normalized_url` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_unique_page` (`unique_page_id`),
  CONSTRAINT `fk_grouped_urls_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grouped_urls_unique_page` FOREIGN KEY (`unique_page_id`) REFERENCES `project_pages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=597 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: hours_reminder_settings
DROP TABLE IF EXISTS `hours_reminder_settings`;
CREATE TABLE `hours_reminder_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reminder_time` time DEFAULT '18:30:00',
  `minimum_hours` decimal(5,2) DEFAULT 8.00,
  `enabled` tinyint(1) DEFAULT 1,
  `notification_message` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: is_standards
DROP TABLE IF EXISTS `is_standards`;
CREATE TABLE `is_standards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `standard_number` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: issue_comments
DROP TABLE IF EXISTS `issue_comments`;
CREATE TABLE `issue_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `recipient_id` int(11) DEFAULT NULL,
  `qa_status_id` int(11) DEFAULT NULL,
  `comment_html` longtext NOT NULL,
  `comment_type` enum('normal','regression') NOT NULL DEFAULT 'normal',
  `reply_to` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_issue_comments_issue` (`issue_id`),
  KEY `idx_issue_comments_user` (`user_id`),
  KEY `fk_issue_comments_recipient` (`recipient_id`),
  KEY `fk_issue_comments_qa_status` (`qa_status_id`),
  KEY `idx_issue_comments_reply_to` (`reply_to`),
  KEY `idx_comment_type` (`comment_type`),
  CONSTRAINT `fk_issue_comments_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_issue_comments_qa_status` FOREIGN KEY (`qa_status_id`) REFERENCES `issue_statuses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_issue_comments_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_issue_comments_reply_to` FOREIGN KEY (`reply_to`) REFERENCES `issue_comments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_issue_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_config_permissions
DROP TABLE IF EXISTS `issue_config_permissions`;
CREATE TABLE `issue_config_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `can_manage_metadata` tinyint(1) NOT NULL DEFAULT 0,
  `can_manage_templates` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_issue_config_permission` (`project_id`,`user_id`),
  KEY `idx_icp_user` (`user_id`),
  CONSTRAINT `fk_icp_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_icp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_default_sections
DROP TABLE IF EXISTS `issue_default_sections`;
CREATE TABLE `issue_default_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_type` varchar(32) NOT NULL,
  `sections_json` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_issue_default_sections` (`project_type`),
  KEY `fk_issue_default_sections_user` (`created_by`),
  CONSTRAINT `fk_issue_default_sections_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_default_templates
DROP TABLE IF EXISTS `issue_default_templates`;
CREATE TABLE `issue_default_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_type` enum('web','app','pdf') NOT NULL DEFAULT 'web',
  `sections_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sections_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_project_type` (`project_type`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_drafts
DROP TABLE IF EXISTS `issue_drafts`;
CREATE TABLE `issue_drafts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `issue_params` longtext NOT NULL COMMENT 'JSON encoded issue parameters',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_project` (`user_id`,`project_id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `issue_drafts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issue_drafts_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=325 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_history
DROP TABLE IF EXISTS `issue_history`;
CREATE TABLE `issue_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `old_value` longtext DEFAULT NULL,
  `new_value` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `issue_id` (`issue_id`),
  CONSTRAINT `issue_history_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=136 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: issue_metadata
DROP TABLE IF EXISTS `issue_metadata`;
CREATE TABLE `issue_metadata` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) NOT NULL,
  `meta_key` varchar(191) NOT NULL,
  `meta_value` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_im_issue` (`issue_id`),
  KEY `idx_im_key` (`meta_key`),
  CONSTRAINT `fk_im_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=620 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_metadata_affected_users
DROP TABLE IF EXISTS `issue_metadata_affected_users`;
CREATE TABLE `issue_metadata_affected_users` (
  `issue_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  PRIMARY KEY (`issue_id`,`group_id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `issue_metadata_affected_users_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issue_metadata_affected_users_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `affected_user_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: issue_metadata_fields
DROP TABLE IF EXISTS `issue_metadata_fields`;
CREATE TABLE `issue_metadata_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_type` enum('web','app','pdf') NOT NULL DEFAULT 'web',
  `field_key` varchar(50) NOT NULL,
  `field_label` varchar(100) NOT NULL,
  `options_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options_json`)),
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_type_key` (`project_type`,`field_key`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_metadata_gigw
DROP TABLE IF EXISTS `issue_metadata_gigw`;
CREATE TABLE `issue_metadata_gigw` (
  `issue_id` int(11) NOT NULL,
  `gigw_id` int(11) NOT NULL,
  PRIMARY KEY (`issue_id`,`gigw_id`),
  KEY `gigw_id` (`gigw_id`),
  CONSTRAINT `issue_metadata_gigw_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issue_metadata_gigw_ibfk_2` FOREIGN KEY (`gigw_id`) REFERENCES `gigw_standards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: issue_metadata_is
DROP TABLE IF EXISTS `issue_metadata_is`;
CREATE TABLE `issue_metadata_is` (
  `issue_id` int(11) NOT NULL,
  `is_id` int(11) NOT NULL,
  PRIMARY KEY (`issue_id`,`is_id`),
  KEY `is_id` (`is_id`),
  CONSTRAINT `issue_metadata_is_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issue_metadata_is_ibfk_2` FOREIGN KEY (`is_id`) REFERENCES `is_standards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: issue_metadata_options
DROP TABLE IF EXISTS `issue_metadata_options`;
CREATE TABLE `issue_metadata_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `field_id` int(11) NOT NULL,
  `option_value` varchar(255) NOT NULL,
  `option_label` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_issue_metadata_option` (`field_id`,`option_value`),
  KEY `idx_issue_metadata_options_field` (`field_id`),
  CONSTRAINT `fk_imo_field` FOREIGN KEY (`field_id`) REFERENCES `issue_metadata_fields` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_metadata_values
DROP TABLE IF EXISTS `issue_metadata_values`;
CREATE TABLE `issue_metadata_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) NOT NULL,
  `field_id` int(11) NOT NULL,
  `value_text` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_imv_issue` (`issue_id`),
  KEY `idx_imv_field` (`field_id`),
  CONSTRAINT `fk_imv_field` FOREIGN KEY (`field_id`) REFERENCES `issue_metadata_fields` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_imv_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_metadata_wcag
DROP TABLE IF EXISTS `issue_metadata_wcag`;
CREATE TABLE `issue_metadata_wcag` (
  `issue_id` int(11) NOT NULL,
  `sc_id` int(11) NOT NULL,
  PRIMARY KEY (`issue_id`,`sc_id`),
  KEY `sc_id` (`sc_id`),
  CONSTRAINT `issue_metadata_wcag_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issue_metadata_wcag_ibfk_2` FOREIGN KEY (`sc_id`) REFERENCES `wcag_sc` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: issue_pages
DROP TABLE IF EXISTS `issue_pages`;
CREATE TABLE `issue_pages` (
  `issue_id` int(11) NOT NULL,
  `page_id` int(11) NOT NULL,
  PRIMARY KEY (`issue_id`,`page_id`),
  KEY `fk_issue_pages_page_id_project` (`page_id`),
  CONSTRAINT `fk_issue_pages_page_id_project` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issue_pages_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: issue_presets
DROP TABLE IF EXISTS `issue_presets`;
CREATE TABLE `issue_presets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_type` enum('web','app','pdf') NOT NULL DEFAULT 'web',
  `title` varchar(255) NOT NULL,
  `description_html` text DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_type` (`project_type`)
) ENGINE=InnoDB AUTO_INCREMENT=475 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_priorities
DROP TABLE IF EXISTS `issue_priorities`;
CREATE TABLE `issue_priorities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `level` int(11) DEFAULT 0,
  `color` varchar(32) DEFAULT '#6c757d',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_reporters
DROP TABLE IF EXISTS `issue_reporters`;
CREATE TABLE `issue_reporters` (
  `issue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`issue_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `issue_reporters_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issue_reporters_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: issue_status_master
DROP TABLE IF EXISTS `issue_status_master`;
CREATE TABLE `issue_status_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status_key` varchar(100) NOT NULL,
  `status_label` varchar(150) NOT NULL,
  `status_description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `badge_color` varchar(20) DEFAULT 'secondary',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `status_key` (`status_key`),
  KEY `idx_active` (`is_active`),
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: issue_statuses
DROP TABLE IF EXISTS `issue_statuses`;
CREATE TABLE `issue_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `category` varchar(64) DEFAULT NULL,
  `color` varchar(32) DEFAULT '#6c757d',
  `points` int(11) DEFAULT 0,
  `is_qa` tinyint(1) DEFAULT 0,
  `visible_to_client` tinyint(1) NOT NULL DEFAULT 1,
  `visible_to_internal` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_template_sections
DROP TABLE IF EXISTS `issue_template_sections`;
CREATE TABLE `issue_template_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `label` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_its_template` (`template_id`),
  CONSTRAINT `fk_its_template` FOREIGN KEY (`template_id`) REFERENCES `issue_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_templates
DROP TABLE IF EXISTS `issue_templates`;
CREATE TABLE `issue_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `project_type` varchar(32) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_issue_template_default` (`project_id`,`is_default`),
  KEY `idx_issue_templates_project` (`project_id`),
  KEY `fk_it_user` (`created_by`),
  CONSTRAINT `fk_it_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_it_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issue_types
DROP TABLE IF EXISTS `issue_types`;
CREATE TABLE `issue_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: issues
DROP TABLE IF EXISTS `issues`;
CREATE TABLE `issues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `issue_key` varchar(50) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `type_id` int(11) NOT NULL,
  `priority_id` int(11) NOT NULL,
  `status_id` int(11) NOT NULL,
  `qa_status_id` int(11) DEFAULT NULL,
  `assignee_id` int(11) DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `page_id` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `estimated_hours` decimal(10,2) DEFAULT NULL,
  `spent_hours` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `common_issue_title` varchar(255) DEFAULT NULL,
  `severity` enum('blocker','critical','major','minor','low') DEFAULT 'major',
  `is_final` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `issue_key` (`issue_key`),
  KEY `idx_project` (`project_id`),
  KEY `idx_status` (`status_id`),
  KEY `idx_assignee` (`assignee_id`),
  KEY `idx_reporter` (`reporter_id`),
  KEY `type_id` (`type_id`),
  KEY `priority_id` (`priority_id`),
  KEY `page_id` (`page_id`),
  KEY `idx_issues_qa_status_id` (`qa_status_id`),
  CONSTRAINT `fk_issues_page_id_project` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_issues_qa_status_id` FOREIGN KEY (`qa_status_id`) REFERENCES `issue_statuses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `issues_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issues_ibfk_2` FOREIGN KEY (`type_id`) REFERENCES `issue_types` (`id`),
  CONSTRAINT `issues_ibfk_3` FOREIGN KEY (`priority_id`) REFERENCES `issue_priorities` (`id`),
  CONSTRAINT `issues_ibfk_4` FOREIGN KEY (`status_id`) REFERENCES `issue_statuses` (`id`),
  CONSTRAINT `issues_ibfk_5` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `issues_ibfk_6` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: notifications
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('mention','assignment','system') NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: page_environments
DROP TABLE IF EXISTS `page_environments`;
CREATE TABLE `page_environments` (
  `page_id` int(11) NOT NULL,
  `environment_id` int(11) NOT NULL,
  `status` enum('not_started','in_progress','completed','pass','fail','on_hold','needs_review') DEFAULT 'not_started',
  `last_updated_by` int(11) DEFAULT NULL,
  `last_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `qa_status` enum('pending','pass','fail','na','completed') DEFAULT 'pending',
  `at_tester_id` int(11) DEFAULT NULL,
  `ft_tester_id` int(11) DEFAULT NULL,
  `qa_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`page_id`,`environment_id`),
  KEY `environment_id` (`environment_id`),
  KEY `fk_page_env_user` (`last_updated_by`),
  KEY `fk_pe_at_tester` (`at_tester_id`),
  KEY `fk_pe_ft_tester` (`ft_tester_id`),
  KEY `fk_pe_qa` (`qa_id`),
  CONSTRAINT `fk_page_env_user` FOREIGN KEY (`last_updated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_page_environments_page_id_project` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pe_at_tester` FOREIGN KEY (`at_tester_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pe_ft_tester` FOREIGN KEY (`ft_tester_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pe_qa` FOREIGN KEY (`qa_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `page_environments_ibfk_2` FOREIGN KEY (`environment_id`) REFERENCES `testing_environments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: page_qa_status_master
DROP TABLE IF EXISTS `page_qa_status_master`;
CREATE TABLE `page_qa_status_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status_key` varchar(50) NOT NULL,
  `status_label` varchar(100) NOT NULL,
  `status_description` text DEFAULT NULL,
  `badge_color` varchar(20) DEFAULT 'secondary',
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `status_key` (`status_key`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: page_testing_status_master
DROP TABLE IF EXISTS `page_testing_status_master`;
CREATE TABLE `page_testing_status_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status_key` varchar(50) NOT NULL,
  `status_label` varchar(100) NOT NULL,
  `status_description` text DEFAULT NULL,
  `badge_color` varchar(20) DEFAULT 'secondary',
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `status_key` (`status_key`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: phase_master
DROP TABLE IF EXISTS `phase_master`;
CREATE TABLE `phase_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phase_name` varchar(100) NOT NULL,
  `phase_description` text DEFAULT NULL,
  `typical_duration_days` int(11) DEFAULT NULL COMMENT 'Typical duration in days',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `phase_name` (`phase_name`),
  KEY `idx_active` (`is_active`),
  KEY `idx_order` (`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: project_assets
DROP TABLE IF EXISTS `project_assets`;
CREATE TABLE `project_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `asset_name` varchar(200) NOT NULL,
  `main_url` varchar(500) DEFAULT NULL,
  `app_name` varchar(200) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `asset_type` enum('link','file','text') DEFAULT 'link',
  `link_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` text DEFAULT NULL,
  `text_content` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_assets_created_by` (`created_by`),
  KEY `project_assets_ibfk_1` (`project_id`),
  CONSTRAINT `fk_assets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_assets_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_hours_summary
DROP TABLE IF EXISTS `project_hours_summary`;
CREATE TABLE `project_hours_summary` (
  `project_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `po_number` varchar(100) DEFAULT NULL,
  `total_hours` decimal(10,2) DEFAULT NULL,
  `allocated_hours` decimal(14,2) DEFAULT 0.00,
  `utilized_hours` decimal(14,2) DEFAULT 0.00,
  `available_hours` decimal(14,2) DEFAULT 0.00,
  `utilization_percentage` decimal(8,2) DEFAULT 0.00,
  PRIMARY KEY (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_pages
DROP TABLE IF EXISTS `project_pages`;
CREATE TABLE `project_pages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `page_name` varchar(200) NOT NULL,
  `page_number` varchar(50) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `screen_name` varchar(200) DEFAULT NULL,
  `status` enum('not_started','in_progress','on_hold','qa_in_progress','in_fixing','needs_review','completed') DEFAULT 'not_started',
  `at_tester_id` int(11) DEFAULT NULL,
  `ft_tester_id` int(11) DEFAULT NULL,
  `qa_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `at_tester_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`at_tester_ids`)),
  `ft_tester_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ft_tester_ids`)),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_pages_ibfk_1` (`project_id`),
  KEY `idx_project_pages_project_id` (`project_id`),
  KEY `idx_project_pages_page_number` (`project_id`,`page_number`),
  CONSTRAINT `project_pages_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_pages_backup_046
DROP TABLE IF EXISTS `project_pages_backup_046`;
CREATE TABLE `project_pages_backup_046` (
  `id` int(11) NOT NULL DEFAULT 0,
  `project_id` int(11) DEFAULT NULL,
  `page_name` varchar(200) NOT NULL,
  `page_number` varchar(50) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `screen_name` varchar(200) DEFAULT NULL,
  `status` enum('not_started','in_progress','on_hold','qa_in_progress','in_fixing','needs_review','completed') DEFAULT 'not_started',
  `at_tester_id` int(11) DEFAULT NULL,
  `ft_tester_id` int(11) DEFAULT NULL,
  `qa_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `at_tester_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`at_tester_ids`)),
  `ft_tester_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ft_tester_ids`)),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_pages_backup_048
DROP TABLE IF EXISTS `project_pages_backup_048`;
CREATE TABLE `project_pages_backup_048` (
  `id` int(11) NOT NULL DEFAULT 0,
  `project_id` int(11) DEFAULT NULL,
  `page_name` varchar(200) NOT NULL,
  `page_number` varchar(50) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `screen_name` varchar(200) DEFAULT NULL,
  `status` enum('not_started','in_progress','on_hold','qa_in_progress','in_fixing','needs_review','completed') DEFAULT 'not_started',
  `at_tester_id` int(11) DEFAULT NULL,
  `ft_tester_id` int(11) DEFAULT NULL,
  `qa_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `at_tester_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`at_tester_ids`)),
  `ft_tester_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ft_tester_ids`)),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_pages_legacy_048
DROP TABLE IF EXISTS `project_pages_legacy_048`;
CREATE TABLE `project_pages_legacy_048` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `page_name` varchar(200) NOT NULL,
  `page_number` varchar(50) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `screen_name` varchar(200) DEFAULT NULL,
  `status` enum('not_started','in_progress','on_hold','qa_in_progress','in_fixing','needs_review','completed') DEFAULT 'not_started',
  `at_tester_id` int(11) DEFAULT NULL,
  `ft_tester_id` int(11) DEFAULT NULL,
  `qa_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `at_tester_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`at_tester_ids`)),
  `ft_tester_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ft_tester_ids`)),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `at_tester_id` (`at_tester_id`),
  KEY `ft_tester_id` (`ft_tester_id`),
  KEY `qa_id` (`qa_id`),
  KEY `project_pages_ibfk_1` (`project_id`),
  KEY `idx_project_pages_project_id` (`project_id`),
  KEY `idx_project_pages_url` (`url`),
  CONSTRAINT `project_pages_legacy_048_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_pages_legacy_048_ibfk_2` FOREIGN KEY (`at_tester_id`) REFERENCES `users` (`id`),
  CONSTRAINT `project_pages_legacy_048_ibfk_3` FOREIGN KEY (`ft_tester_id`) REFERENCES `users` (`id`),
  CONSTRAINT `project_pages_legacy_048_ibfk_4` FOREIGN KEY (`qa_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=392 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_permissions
DROP TABLE IF EXISTS `project_permissions`;
CREATE TABLE `project_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `permission_type` varchar(50) NOT NULL,
  `granted_by` int(11) DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_project_user_permission` (`project_id`,`user_id`,`permission_type`),
  KEY `idx_project_permissions_project` (`project_id`),
  KEY `idx_project_permissions_user` (`user_id`),
  KEY `idx_project_permissions_active` (`is_active`),
  KEY `fk_pp_granted_by` (`granted_by`),
  CONSTRAINT `fk_pp_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pp_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_permissions_types
DROP TABLE IF EXISTS `project_permissions_types`;
CREATE TABLE `project_permissions_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_type` (`permission_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_phases
DROP TABLE IF EXISTS `project_phases`;
CREATE TABLE `project_phases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `phase_name` varchar(100) NOT NULL,
  `phase_master_id` int(11) DEFAULT NULL,
  `status` enum('not_started','in_progress','on_hold','completed') DEFAULT 'not_started',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `planned_hours` decimal(10,2) DEFAULT NULL,
  `actual_hours` decimal(10,2) DEFAULT 0.00,
  `completion_percentage` int(11) DEFAULT 0,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_phases_ibfk_1` (`project_id`),
  KEY `idx_phase_master_id` (`phase_master_id`),
  CONSTRAINT `fk_phase_master` FOREIGN KEY (`phase_master_id`) REFERENCES `phase_master` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_phases_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_statuses
DROP TABLE IF EXISTS `project_statuses`;
CREATE TABLE `project_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status_key` varchar(50) NOT NULL,
  `status_label` varchar(100) NOT NULL,
  `status_description` text DEFAULT NULL,
  `is_active_status` tinyint(1) DEFAULT 1 COMMENT '1 = Shows in active projects, 0 = Inactive/Completed',
  `display_order` int(11) DEFAULT 0,
  `badge_color` varchar(20) DEFAULT 'secondary',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `status_key` (`status_key`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: project_time_logs
DROP TABLE IF EXISTS `project_time_logs`;
CREATE TABLE `project_time_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `page_id` int(11) DEFAULT NULL,
  `environment_id` int(11) DEFAULT NULL,
  `issue_id` int(11) DEFAULT NULL,
  `task_type` enum('page_testing','project_phase','generic_task','regression','other') NOT NULL DEFAULT 'other',
  `phase_id` int(11) DEFAULT NULL,
  `generic_category_id` int(11) DEFAULT NULL,
  `testing_type` varchar(50) DEFAULT NULL,
  `log_date` date NOT NULL,
  `hours_spent` decimal(5,2) NOT NULL,
  `description` text DEFAULT NULL,
  `is_utilized` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `fk_ptl_page` (`page_id`),
  KEY `fk_ptl_env` (`environment_id`),
  KEY `fk_ptl_phase` (`phase_id`),
  KEY `fk_ptl_generic_cat` (`generic_category_id`),
  KEY `idx_time_logs_project_hours` (`project_id`,`is_utilized`,`hours_spent`),
  KEY `idx_project_time_logs_issue_id` (`issue_id`),
  KEY `idx_perf_time_logs_date` (`log_date`),
  CONSTRAINT `fk_project_time_logs_page_id_project` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ptl_env` FOREIGN KEY (`environment_id`) REFERENCES `testing_environments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ptl_generic_cat` FOREIGN KEY (`generic_category_id`) REFERENCES `generic_task_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ptl_phase` FOREIGN KEY (`phase_id`) REFERENCES `project_phases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_time_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `project_time_logs_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: projects
DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(50) NOT NULL,
  `project_code` varchar(50) DEFAULT NULL,
  `parent_project_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `project_type` enum('web','app','pdf') NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `priority` enum('critical','high','medium','low') DEFAULT 'medium',
  `status` enum('planning','in_progress','awaiting_client','on_hold','completed','cancelled','archived') DEFAULT 'planning',
  `regression_locked` tinyint(1) NOT NULL DEFAULT 0,
  `total_hours` decimal(10,2) DEFAULT NULL,
  `project_lead_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_number` (`po_number`),
  UNIQUE KEY `project_code` (`project_code`),
  KEY `client_id` (`client_id`),
  KEY `project_lead_id` (`project_lead_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_perf_projects_status` (`status`),
  KEY `fk_projects_parent_project` (`parent_project_id`),
  CONSTRAINT `fk_projects_parent_project` FOREIGN KEY (`parent_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  CONSTRAINT `projects_ibfk_2` FOREIGN KEY (`project_lead_id`) REFERENCES `users` (`id`),
  CONSTRAINT `projects_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: qa_results
DROP TABLE IF EXISTS `qa_results`;
CREATE TABLE `qa_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_id` int(11) DEFAULT NULL,
  `qa_id` int(11) DEFAULT NULL,
  `status` enum('pass','fail','na') NOT NULL,
  `issues_found` int(11) DEFAULT 0,
  `comments` text DEFAULT NULL,
  `hours_spent` decimal(5,2) DEFAULT NULL,
  `qa_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `qa_id` (`qa_id`),
  KEY `qa_results_ibfk_1` (`page_id`),
  CONSTRAINT `fk_qa_results_page_id_project` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `qa_results_ibfk_2` FOREIGN KEY (`qa_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: qa_status_master
DROP TABLE IF EXISTS `qa_status_master`;
CREATE TABLE `qa_status_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status_key` varchar(100) NOT NULL,
  `status_label` varchar(150) NOT NULL,
  `status_description` text DEFAULT NULL,
  `severity_level` enum('1','2','3') DEFAULT '2' COMMENT '1=Minor, 2=Moderate, 3=Major',
  `error_points` decimal(5,2) DEFAULT 1.00 COMMENT 'Points assigned for this error type',
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `badge_color` varchar(20) DEFAULT 'warning',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `status_key` (`status_key`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: regression_assignments
DROP TABLE IF EXISTS `regression_assignments`;
CREATE TABLE `regression_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) NOT NULL,
  `regression_round_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `env_label` varchar(150) DEFAULT NULL,
  `page_ids` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_regassign_user` (`user_id`),
  KEY `idx_regassign_issue` (`issue_id`),
  KEY `idx_regassign_round` (`regression_round_id`),
  CONSTRAINT `fk_regassign_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_regassign_round` FOREIGN KEY (`regression_round_id`) REFERENCES `regression_rounds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_regassign_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: regression_issue_changes
DROP TABLE IF EXISTS `regression_issue_changes`;
CREATE TABLE `regression_issue_changes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `old_status` varchar(64) DEFAULT NULL,
  `new_status` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `issue_id` (`issue_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: regression_rounds
DROP TABLE IF EXISTS `regression_rounds`;
CREATE TABLE `regression_rounds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `started_by` int(11) DEFAULT NULL,
  `round_number` int(11) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('in_progress','completed') DEFAULT 'in_progress',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `admin_confirmed` tinyint(1) DEFAULT 0,
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_rr_user` (`started_by`),
  KEY `idx_rr_project_active` (`project_id`,`is_active`),
  CONSTRAINT `fk_rr_user` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `regression_rounds_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: regression_sessions
DROP TABLE IF EXISTS `regression_sessions`;
CREATE TABLE `regression_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `started_by` int(11) NOT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  `status` varchar(32) DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `regression_round_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `started_by` (`started_by`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: regression_statuses
DROP TABLE IF EXISTS `regression_statuses`;
CREATE TABLE `regression_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `color` varchar(20) DEFAULT '#6c757d',
  `sort_order` int(11) DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: regression_tasks
DROP TABLE IF EXISTS `regression_tasks`;
CREATE TABLE `regression_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `page_id` int(11) DEFAULT NULL,
  `environment_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_user_id` int(11) DEFAULT NULL,
  `assigned_role` varchar(64) DEFAULT NULL,
  `status` varchar(64) DEFAULT 'open',
  `phase` varchar(128) DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `page_id` (`page_id`),
  KEY `environment_id` (`environment_id`),
  KEY `assigned_user_id` (`assigned_user_id`),
  KEY `completed_by` (`completed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: status_options
DROP TABLE IF EXISTS `status_options`;
CREATE TABLE `status_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` enum('project','page','phase','test_result','qa_result') NOT NULL,
  `status_key` varchar(50) NOT NULL,
  `status_label` varchar(100) NOT NULL,
  `color` varchar(20) DEFAULT 'secondary',
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_entity_status` (`entity_type`,`status_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: testing_environments
DROP TABLE IF EXISTS `testing_environments`;
CREATE TABLE `testing_environments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('web','app') NOT NULL,
  `browser` varchar(50) DEFAULT NULL,
  `assistive_tech` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: testing_results
DROP TABLE IF EXISTS `testing_results`;
CREATE TABLE `testing_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_id` int(11) DEFAULT NULL,
  `tester_id` int(11) DEFAULT NULL,
  `tester_role` enum('at_tester','ft_tester') NOT NULL,
  `status` enum('pass','fail','na') NOT NULL,
  `issues_found` int(11) DEFAULT 0,
  `comments` text DEFAULT NULL,
  `environment_id` int(11) DEFAULT NULL,
  `hours_spent` decimal(5,2) DEFAULT NULL,
  `tested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tester_id` (`tester_id`),
  KEY `environment_id` (`environment_id`),
  KEY `testing_results_ibfk_1` (`page_id`),
  CONSTRAINT `fk_testing_results_page_id_project` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `testing_results_ibfk_2` FOREIGN KEY (`tester_id`) REFERENCES `users` (`id`),
  CONSTRAINT `testing_results_ibfk_3` FOREIGN KEY (`environment_id`) REFERENCES `testing_environments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Legacy page table removed: project_pages is the single canonical page table.

-- Table: user_assignments
DROP TABLE IF EXISTS `user_assignments`;
CREATE TABLE `user_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` enum('project_lead','qa','at_tester','ft_tester') NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `hours_allocated` decimal(10,2) DEFAULT NULL,
  `is_removed` tinyint(1) DEFAULT 0 COMMENT 'Soft delete flag: 0=active, 1=removed',
  `removed_at` timestamp NULL DEFAULT NULL COMMENT 'When the assignment was removed',
  `removed_by` int(11) DEFAULT NULL COMMENT 'User ID who removed this assignment',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `assigned_by` (`assigned_by`),
  KEY `idx_user_assignments_project_hours` (`project_id`,`hours_allocated`),
  KEY `removed_by` (`removed_by`),
  KEY `idx_user_assignments_is_removed` (`is_removed`),
  KEY `idx_user_assignments_project_active` (`project_id`,`is_removed`),
  CONSTRAINT `user_assignments_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_assignments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `user_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `user_assignments_ibfk_4` FOREIGN KEY (`removed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: user_calendar_notes
DROP TABLE IF EXISTS `user_calendar_notes`;
CREATE TABLE `user_calendar_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `note_date` date NOT NULL,
  `content` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_date` (`user_id`,`note_date`),
  CONSTRAINT `user_calendar_notes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: user_daily_status
DROP TABLE IF EXISTS `user_daily_status`;
CREATE TABLE `user_daily_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `status_date` date NOT NULL,
  `status` enum('available','working','on_leave','busy','sick_leave','not_updated') NOT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_date` (`user_id`,`status_date`),
  CONSTRAINT `user_daily_status_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: user_edit_requests
DROP TABLE IF EXISTS `user_edit_requests`;
CREATE TABLE `user_edit_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `req_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','used') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_date` (`user_id`,`req_date`),
  CONSTRAINT `user_edit_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: user_generic_tasks
DROP TABLE IF EXISTS `user_generic_tasks`;
CREATE TABLE `user_generic_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `task_description` text DEFAULT NULL,
  `hours_spent` decimal(5,2) DEFAULT 0.00,
  `task_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `user_generic_tasks_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `user_generic_tasks_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `generic_task_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: user_pending_changes
DROP TABLE IF EXISTS `user_pending_changes`;
CREATE TABLE `user_pending_changes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `req_date` date NOT NULL,
  `status` enum('not_updated','available','busy','on_leave','sick_leave') DEFAULT 'not_updated',
  `notes` text DEFAULT NULL,
  `personal_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_date` (`user_id`,`req_date`),
  CONSTRAINT `user_pending_changes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: user_qa_performance
DROP TABLE IF EXISTS `user_qa_performance`;
CREATE TABLE `user_qa_performance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `issue_id` int(11) DEFAULT NULL,
  `qa_status_id` int(11) DEFAULT NULL,
  `error_points` decimal(5,2) DEFAULT 0.00,
  `comment_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `qa_status_id` (`qa_status_id`),
  KEY `idx_user_performance` (`user_id`,`comment_date`),
  KEY `idx_project_performance` (`project_id`,`user_id`),
  CONSTRAINT `user_qa_performance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_qa_performance_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_qa_performance_ibfk_3` FOREIGN KEY (`qa_status_id`) REFERENCES `qa_status_master` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: user_sessions
DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `ip_location` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_activity` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `logout_at` datetime DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `session_id` (`session_id`)
) ENGINE=InnoDB AUTO_INCREMENT=148 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `two_factor_secret` varchar(255) DEFAULT NULL COMMENT 'Stores Base32 TOTP secret',
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if 2FA is active',
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','project_lead','qa','at_tester','ft_tester') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  `force_password_reset` tinyint(1) DEFAULT 0,
  `can_manage_issue_config` tinyint(1) NOT NULL DEFAULT 0,
  `can_manage_devices` tinyint(1) NOT NULL DEFAULT 0,
  `account_setup_completed` tinyint(1) DEFAULT 0,
  `temp_password` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_perf_users_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: wcag_levels
DROP TABLE IF EXISTS `wcag_levels`;
CREATE TABLE `wcag_levels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `level_name` varchar(10) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: wcag_sc
DROP TABLE IF EXISTS `wcag_sc`;
CREATE TABLE `wcag_sc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sc_code` varchar(20) NOT NULL,
  `sc_name` varchar(255) NOT NULL,
  `level` enum('A','AA','AAA') NOT NULL,
  `version` varchar(10) DEFAULT '2.1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
