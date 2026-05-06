CREATE TABLE IF NOT EXISTS `issue_client_snapshots` (
  `issue_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `pages_json` longtext DEFAULT NULL,
  `published_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`issue_id`),
  KEY `idx_project_published` (`project_id`,`published_at`),
  CONSTRAINT `fk_issue_client_snapshots_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_issue_client_snapshots_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;