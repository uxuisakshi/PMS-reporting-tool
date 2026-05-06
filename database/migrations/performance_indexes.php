<?php
/**
 * ⚡ Performance Indexes Migration
 * Adds highly targeted indexes to speed up dashboard queries and timesheet fetching.
 */
require_once __DIR__ . '/../../config/database.php';

try {
    $db = Database::getInstance();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to database.\n";

    $indexes = [
        ['table' => 'projects', 'name' => 'idx_perf_projects_status', 'sql' => 'ALTER TABLE projects ADD INDEX idx_perf_projects_status (status)'],
        ['table' => 'project_time_logs', 'name' => 'idx_perf_time_logs_date', 'sql' => 'ALTER TABLE project_time_logs ADD INDEX idx_perf_time_logs_date (log_date)'],
        ['table' => 'users', 'name' => 'idx_perf_users_active', 'sql' => 'ALTER TABLE users ADD INDEX idx_perf_users_active (is_active)']
    ];

    foreach ($indexes as $index) {
        $table = $index['table'];
        $name = $index['name'];
        $sql = $index['sql'];
        
        try {
            $db->exec($sql);
            echo "✅ Created index '{$name}' on table '{$table}'.\n";
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate key name') !== false) {
                echo "ℹ️ Index '{$name}' already exists on '{$table}'. Skipping.\n";
            } else {
                echo "❌ Failed to create index '{$name}' on '{$table}': {$msg}\n";
            }
        }
    }
    
    echo "🎉 Indexing complete!\n";

} catch (Exception $e) {
    die("❌ Migration failed completely: " . $e->getMessage() . "\n");
}
