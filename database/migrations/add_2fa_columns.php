<?php
// Run this file via browser or CLI to add 2FA columns

require_once __DIR__ . '/../../config/database.php';

try {
    $db = Database::getInstance();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Checking users table for 2FA columns...\n<br>";
    
    // Check two_factor_secret
    $checkSecret = $db->query("SHOW COLUMNS FROM users LIKE 'two_factor_secret'");
    if ($checkSecret->rowCount() === 0) {
        $db->exec("ALTER TABLE users ADD COLUMN `two_factor_secret` varchar(255) DEFAULT NULL COMMENT 'Stores Base32 TOTP secret' AFTER `password`");
        echo "Added 'two_factor_secret' column to users table.\n<br>";
    } else {
        echo "Column 'two_factor_secret' already exists.\n<br>";
    }

    // Check two_factor_enabled
    $checkEnabled = $db->query("SHOW COLUMNS FROM users LIKE 'two_factor_enabled'");
    if ($checkEnabled->rowCount() === 0) {
        $db->exec("ALTER TABLE users ADD COLUMN `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if 2FA is active' AFTER `two_factor_secret`");
        echo "Added 'two_factor_enabled' column to users table.\n<br>";
    } else {
        echo "Column 'two_factor_enabled' already exists.\n<br>";
    }

    echo "<br><strong style='color:green'>Migration completed successfully!</strong>";
    
} catch (Exception $e) {
    echo "<br><strong style='color:red'>Migration Failed: " . htmlspecialchars($e->getMessage()) . "</strong>";
}
