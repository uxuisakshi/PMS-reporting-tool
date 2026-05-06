-- Add issue_page_screenshots table for storing page screenshots with reference to grouped URLs
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `issue_page_screenshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) DEFAULT NULL,
  `page_id` int(11) NOT NULL,
  `grouped_url_id` int(11) DEFAULT NULL,
  `url_text` varchar(2048) DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_size` bigint DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT 'image/png',
  `description` text DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_issue_id` (`issue_id`),
  KEY `idx_page_id` (`page_id`),
  KEY `idx_grouped_url_id` (`grouped_url_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_ips_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ips_page` FOREIGN KEY (`page_id`) REFERENCES `project_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ips_grouped_url` FOREIGN KEY (`grouped_url_id`) REFERENCES `grouped_urls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ips_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Alter existing table if issue_id is NOT NULL
ALTER TABLE `issue_page_screenshots` MODIFY COLUMN `issue_id` int(11) DEFAULT NULL;
ALTER TABLE `issue_page_screenshots` ADD COLUMN IF NOT EXISTS `url_text` varchar(2048) DEFAULT NULL AFTER `grouped_url_id`;
UPDATE `issue_page_screenshots` ips
LEFT JOIN `project_pages` pp ON pp.id = ips.page_id
SET ips.url_text = COALESCE(NULLIF(ips.url_text, ''), pp.url)
WHERE (ips.url_text IS NULL OR ips.url_text = '')
  AND ips.grouped_url_id IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
