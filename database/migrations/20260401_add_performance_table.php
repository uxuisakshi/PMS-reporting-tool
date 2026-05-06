<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = Database::getInstance();
    
    $sql = "CREATE TABLE IF NOT EXISTS resource_performance_feedback (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        project_id INT DEFAULT NULL COMMENT 'NULL for Overall performance',
        accuracy_score FLOAT DEFAULT 0,
        activity_score INT DEFAULT 0,
        positive_feedback TEXT,
        negative_feedback TEXT,
        ai_summary TEXT,
        last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_project (user_id, project_id),
        CONSTRAINT fk_rpf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->exec($sql);
    echo "Table 'resource_performance_feedback' created successfully.\n";
    
} catch (Exception $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
