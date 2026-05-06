<?php
// Smart configuration based on environment
$serverHost = $_SERVER['HTTP_HOST'] ?? '';
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';

// Default values (Live)
$dbName = 'athenaeu_project_management';
$dbUser = 'athenaeu_pms';
$dbPass = '$Sis@2026$';
$dbHost = 'localhost';

// UAT overrides
if (strpos($serverHost, 'uat') !== false || strpos($scriptPath, 'PMS-UAT') !== false) {
    $dbName = 'athenaeu_project_management_uat';
}

define('DB_HOST', getenv('DB_HOST') ?: $dbHost);
define('DB_NAME', getenv('DB_NAME') ?: $dbName);
define('DB_USER', getenv('DB_USER') ?: $dbUser);
define('DB_PASS', getenv('DB_PASS') ?: $dbPass);

// Warn if running with default insecure credentials (non-CLI only)
if (php_sapi_name() !== 'cli' && DB_USER === 'root' && DB_PASS === '') {
    error_log('SECURITY WARNING: Application is running with default root/empty database credentials.');
}

// Runtime performance tuning (OPcache hints, APCu)
require_once __DIR__ . '/performance.php';

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        // Set timezone before any database operations
        require_once __DIR__ . '/timezone.php';
        
        try {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=utf8mb4",
                DB_HOST,
                DB_NAME
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false, // Don't use persistent connections for better security
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+05:30'"
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log error instead of exposing it directly
            error_log("Database connection failed: " . $e->getMessage());
            // Show generic error to user
            if (php_sapi_name() === 'cli') {
                die("Database connection failed. Check error logs for details.\n");
            } else {
                http_response_code(500);
                die("Database connection failed. Please contact the administrator.");
            }
        }
    }
    
    /**
     * Get database connection instance
     * @return PDO
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance->connection;
    }
    
    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
