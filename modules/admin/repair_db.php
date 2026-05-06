<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin']); // Only admin, not regular admin

// Extra safety: block access in production unless explicitly enabled
$allowRepairInProduction = defined('ALLOW_DB_REPAIR') && ALLOW_DB_REPAIR === true;
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true);

if (!$allowRepairInProduction && !$isLocalhost) {
    http_response_code(403);
    die('This utility is disabled in production. Set ALLOW_DB_REPAIR=true in constants.php to enable (not recommended).');
}

$db = Database::getInstance();

echo "<h2>Database Repair Utility</h2>";
echo "<p>Checking and fixing database constraints for PMS...</p>";

$fixes = [
    // testing_results
    "ALTER TABLE testing_results DROP FOREIGN KEY IF EXISTS testing_results_ibfk_1",
    "ALTER TABLE testing_results ADD CONSTRAINT testing_results_ibfk_1 FOREIGN KEY (page_id) REFERENCES project_pages(id) ON DELETE CASCADE",
    
    // qa_results
    "ALTER TABLE qa_results DROP FOREIGN KEY IF EXISTS qa_results_ibfk_1",
    "ALTER TABLE qa_results ADD CONSTRAINT qa_results_ibfk_1 FOREIGN KEY (page_id) REFERENCES project_pages(id) ON DELETE CASCADE",
    
    // chat_messages
    "ALTER TABLE chat_messages DROP FOREIGN KEY IF EXISTS chat_messages_ibfk_1",
    "ALTER TABLE chat_messages DROP FOREIGN KEY IF EXISTS chat_messages_ibfk_2",
    "ALTER TABLE chat_messages ADD CONSTRAINT chat_messages_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE",
    "ALTER TABLE chat_messages ADD CONSTRAINT chat_messages_ibfk_2 FOREIGN KEY (page_id) REFERENCES project_pages(id) ON DELETE CASCADE"
];

foreach ($fixes as $sql) {
    try {
        $db->exec($sql);
        echo "<div style='color: green;'>Success: " . htmlspecialchars($sql) . "</div>";
    } catch (PDOException $e) {
        // Drop might fail if it doesn't exist, which is fine if we use IF EXISTS, 
        // but older MySQL doesn't support IF EXISTS on DROP FOREIGN KEY.
        // So we catch and continue.
        echo "<div style='color: orange;'>Skipped/Note: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

echo "<p><strong>Database repair complete.</strong> You should now be able to delete projects without errors.</p>";
echo "<a href='dashboard.php'>Back to Dashboard</a>";
