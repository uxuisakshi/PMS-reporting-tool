-- Migration: Add Client Reporting and Analytics System
-- Date: 2026-03-11
-- Description: Adds tables and columns for client reporting system with analytics

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Add client role to users table
ALTER TABLE `users` 
MODIFY COLUMN `role` enum('admin','admin','project_lead','qa','at_tester','ft_tester','client') NOT NULL;

-- 2. Add client_ready column to issues table if not exists
ALTER TABLE `issues` 
ADD COLUMN `client_ready` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether issue is ready for client viewing';

-- 3. Create client_project_assignments table
CREATE TABLE IF NOT EXISTS `client_project_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_user_id` int(11) NOT NULL COMMENT 'User ID with client role',
  `project_id` int(11) NOT NULL COMMENT 'Project ID being assigned',
  `assigned_by_admin_id` int(11) NOT NULL COMMENT 'Admin who made the assignment',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Optional expiration date',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Whether assignment is active',
  `notes` text DEFAULT NULL COMMENT 'Optional notes about the assignment',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_client_project` (`client_user_id`, `project_id`),
  KEY `idx_client_user` (`client_user_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_assigned_by` (`assigned_by_admin_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_cpa_client_user` FOREIGN KEY (`client_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cpa_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cpa_assigned_by` FOREIGN KEY (`assigned_by_admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Client project assignments for reporting access';

-- 4. Create analytics_reports table for caching
CREATE TABLE IF NOT EXISTS `analytics_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_type` varchar(50) NOT NULL COMMENT 'Type of analytics report',
  `project_ids` json NOT NULL COMMENT 'Array of project IDs included in report',
  `generated_by_user_id` int(11) NOT NULL COMMENT 'User who generated the report',
  `report_data` json NOT NULL COMMENT 'Cached report data',
  `chart_config` json DEFAULT NULL COMMENT 'Chart configuration for visualization',
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Cache expiration time',
  `cache_key` varchar(255) NOT NULL COMMENT 'Unique cache key',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cache_key` (`cache_key`),
  KEY `idx_report_type` (`report_type`),
  KEY `idx_generated_by` (`generated_by_user_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_generated_at` (`generated_at`),
  CONSTRAINT `fk_ar_user` FOREIGN KEY (`generated_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cached analytics reports for performance';

-- 5. Create export_requests table for tracking exports
CREATE TABLE IF NOT EXISTS `export_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'User requesting the export',
  `export_type` enum('pdf','excel') NOT NULL COMMENT 'Type of export requested',
  `report_type` varchar(50) NOT NULL COMMENT 'Type of analytics report to export',
  `project_ids` json NOT NULL COMMENT 'Array of project IDs to include',
  `export_options` json DEFAULT NULL COMMENT 'Export configuration options',
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `file_path` varchar(500) DEFAULT NULL COMMENT 'Path to generated export file',
  `file_size` bigint DEFAULT NULL COMMENT 'Size of generated file in bytes',
  `error_message` text DEFAULT NULL COMMENT 'Error message if export failed',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'When export file expires',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_export_type` (`export_type`),
  KEY `idx_report_type` (`report_type`),
  KEY `idx_requested_at` (`requested_at`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_er_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Export request tracking and file management';

-- 6. Create client_audit_log table for security and compliance
CREATE TABLE IF NOT EXISTS `client_audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_user_id` int(11) NOT NULL COMMENT 'Client user performing the action',
  `action_type` varchar(50) NOT NULL COMMENT 'Type of action performed',
  `resource_type` varchar(50) DEFAULT NULL COMMENT 'Type of resource accessed',
  `resource_id` int(11) DEFAULT NULL COMMENT 'ID of resource accessed',
  `project_id` int(11) DEFAULT NULL COMMENT 'Project context if applicable',
  `action_details` json DEFAULT NULL COMMENT 'Additional action details',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'Client IP address',
  `user_agent` text DEFAULT NULL COMMENT 'Client user agent string',
  `session_id` varchar(255) DEFAULT NULL COMMENT 'Session identifier',
  `success` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Whether action was successful',
  `error_message` text DEFAULT NULL COMMENT 'Error message if action failed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client_user` (`client_user_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_resource` (`resource_type`, `resource_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_success` (`success`),
  CONSTRAINT `fk_cal_client_user` FOREIGN KEY (`client_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cal_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for client user activities';

-- 7. Add indexes for performance optimization
ALTER TABLE `issues` ADD INDEX `idx_client_ready_project` (`client_ready`, `project_id`);
ALTER TABLE `issues` ADD INDEX `idx_client_ready_status` (`client_ready`, `status_id`);
ALTER TABLE `issues` ADD INDEX `idx_client_ready_created` (`client_ready`, `created_at`);

-- 8. Create general audit_logs table for system-wide audit trail
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'User performing the action',
  `action` varchar(100) NOT NULL COMMENT 'Action performed',
  `details` json DEFAULT NULL COMMENT 'Action details and context',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'Client IP address',
  `user_agent` text DEFAULT NULL COMMENT 'Client user agent string',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='General system audit log';

-- 9. Add indexes for issue metadata queries (for analytics)
ALTER TABLE `issue_metadata` ADD INDEX `idx_meta_key_value` (`meta_key`, `meta_value`);

-- 9. Add indexes for issue comments (for commented issues analytics)
ALTER TABLE `issue_comments` ADD INDEX `idx_issue_created` (`issue_id`, `created_at`);

SET FOREIGN_KEY_CHECKS = 1;

-- Insert default analytics report types
INSERT IGNORE INTO `analytics_reports` (`id`, `report_type`, `project_ids`, `generated_by_user_id`, `report_data`, `cache_key`) VALUES
(1, 'user_affected', '[]', 1, '{}', 'default_user_affected'),
(2, 'wcag_compliance', '[]', 1, '{}', 'default_wcag_compliance'),
(3, 'severity_analysis', '[]', 1, '{}', 'default_severity_analysis'),
(4, 'common_issues', '[]', 1, '{}', 'default_common_issues'),
(5, 'blocker_issues', '[]', 1, '{}', 'default_blocker_issues'),
(6, 'page_issues', '[]', 1, '{}', 'default_page_issues'),
(7, 'commented_issues', '[]', 1, '{}', 'default_commented_issues'),
(8, 'compliance_trend', '[]', 1, '{}', 'default_compliance_trend');