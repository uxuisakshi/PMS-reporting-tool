<?php
/**
 * Production Environment Initialization
 * 
 * This script initializes all production configurations for the Client Reporting
 * and Analytics System, including caching, security, email, and file storage.
 * 
 * Requirements: 18.1, 17.5, 19.4
 */

// Prevent direct access
if (!defined('INIT_PRODUCTION') && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Direct access not allowed');
}

// Load configuration classes
require_once __DIR__ . '/production.php';
require_once __DIR__ . '/cache_production.php';
require_once __DIR__ . '/email_production.php';
require_once __DIR__ . '/storage_production.php';

class ProductionInitializer {
    
    private static $initialized = false;
    private static $config = [];
    
    /**
     * Initialize production environment
     */
    public static function initialize($force = false) {
        if (self::$initialized && !$force) {
            return self::$config;
        }
        
        try {
            // Apply base production configuration
            ProductionConfig::apply();
            
            // Initialize caching
            $cacheConfig = ProductionCacheConfig::initialize();
            if ($cacheConfig) {
                self::$config['cache'] = 'Redis cache initialized successfully';
            } else {
                self::$config['cache'] = 'Cache initialization failed - using fallback';
                error_log('Production cache initialization failed');
            }
            
            // Initialize email configuration
            $emailConfig = ProductionEmailConfig::initialize();
            self::$config['email'] = 'Email configuration initialized';
            
            // Initialize storage directories
            ProductionStorageConfig::initializeDirectories();
            self::$config['storage'] = 'Storage directories initialized';
            
            // Set up error handling
            self::setupErrorHandling();
            self::$config['error_handling'] = 'Production error handling configured';
            
            // Set up logging
            self::setupLogging();
            self::$config['logging'] = 'Production logging configured';
            
            // Schedule cleanup tasks
            self::scheduleCleanupTasks();
            self::$config['cleanup'] = 'Cleanup tasks scheduled';
            
            self::$initialized = true;
            
            // Log successful initialization
            error_log('Production environment initialized successfully');
            
            return self::$config;
            
        } catch (Exception $e) {
            error_log('Production initialization failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to initialize production environment: ' . $e->getMessage());
        }
    }
    
    /**
     * Setup production error handling
     */
    private static function setupErrorHandling() {
        // Set error handler
        set_error_handler(function($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            
            $errorTypes = [
                E_ERROR => 'ERROR',
                E_WARNING => 'WARNING',
                E_PARSE => 'PARSE',
                E_NOTICE => 'NOTICE',
                E_CORE_ERROR => 'CORE_ERROR',
                E_CORE_WARNING => 'CORE_WARNING',
                E_COMPILE_ERROR => 'COMPILE_ERROR',
                E_COMPILE_WARNING => 'COMPILE_WARNING',
                E_USER_ERROR => 'USER_ERROR',
                E_USER_WARNING => 'USER_WARNING',
                E_USER_NOTICE => 'USER_NOTICE',
                E_STRICT => 'STRICT',
                E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
                E_DEPRECATED => 'DEPRECATED',
                E_USER_DEPRECATED => 'USER_DEPRECATED',
            ];
            
            $errorType = $errorTypes[$severity] ?? 'UNKNOWN';
            $logMessage = "[{$errorType}] {$message} in {$file} on line {$line}";
            
            error_log($logMessage);
            
            // For critical errors, also send email alert
            if (in_array($severity, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
                self::sendErrorAlert($logMessage);
            }
            
            return true;
        });
        
        // Set exception handler
        set_exception_handler(function($exception) {
            $message = "Uncaught exception: " . $exception->getMessage() . 
                      " in " . $exception->getFile() . 
                      " on line " . $exception->getLine();
            
            error_log($message);
            self::sendErrorAlert($message);
            
            // Show generic error page in production
            if (php_sapi_name() !== 'cli') {
                http_response_code(500);
                include __DIR__ . '/../includes/templates/error_500.php';
            }
        });
    }
    
    /**
     * Setup production logging
     */
    private static function setupLogging() {
        $loggingConfig = ProductionConfig::getLoggingConfig();
        
        // Ensure log directory exists
        $logDir = dirname($loggingConfig['handlers']['file']['path']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
        
        // Set log file permissions
        $logFile = $loggingConfig['handlers']['file']['path'];
        if (file_exists($logFile)) {
            chmod($logFile, 0640);
        }
        
        // Configure PHP error logging
        ini_set('log_errors', '1');
        ini_set('error_log', $logFile);
    }
    
    /**
     * Send error alert email
     */
    private static function sendErrorAlert($message) {
        static $lastAlert = 0;
        $now = time();
        
        // Rate limit alerts (max 1 per 5 minutes)
        if ($now - $lastAlert < 300) {
            return;
        }
        
        $lastAlert = $now;
        
        try {
            $emailConfig = ProductionEmailConfig::getConfig();
            $adminEmail = $_ENV['ADMIN_EMAIL'] ?? $emailConfig['from']['email'];
            
            $subject = 'PMS Production Error Alert';
            $body = "A critical error occurred in the PMS production environment:\n\n";
            $body .= $message . "\n\n";
            $body .= "Time: " . date('Y-m-d H:i:s') . "\n";
            $body .= "Server: " . ($_SERVER['SERVER_NAME'] ?? 'Unknown') . "\n";
            $body .= "IP: " . ($_SERVER['SERVER_ADDR'] ?? 'Unknown') . "\n";
            
            // Use simple mail function for error alerts to avoid dependencies
            mail($adminEmail, $subject, $body);
            
        } catch (Exception $e) {
            error_log('Failed to send error alert: ' . $e->getMessage());
        }
    }
    
    /**
     * Schedule cleanup tasks
     */
    private static function scheduleCleanupTasks() {
        // Register shutdown function for cleanup
        register_shutdown_function(function() {
            // Only run cleanup occasionally to avoid performance impact
            if (rand(1, 100) <= 5) { // 5% chance
                try {
                    ProductionStorageConfig::cleanup();
                } catch (Exception $e) {
                    error_log('Cleanup task failed: ' . $e->getMessage());
                }
            }
        });
    }
    
    /**
     * Get initialization status
     */
    public static function getStatus() {
        return [
            'initialized' => self::$initialized,
            'config' => self::$config,
            'timestamp' => date('Y-m-d H:i:s'),
            'environment' => 'production'
        ];
    }
    
    /**
     * Health check for production environment
     */
    public static function healthCheck() {
        $health = [
            'status' => 'healthy',
            'checks' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Check cache connection
        try {
            $redis = ProductionCacheConfig::initialize();
            if ($redis && $redis->ping()) {
                $health['checks']['cache'] = 'OK';
            } else {
                $health['checks']['cache'] = 'FAILED';
                $health['status'] = 'degraded';
            }
        } catch (Exception $e) {
            $health['checks']['cache'] = 'ERROR: ' . $e->getMessage();
            $health['status'] = 'degraded';
        }
        
        // Check database connection
        try {
            /** @var PDO $db */
            $db = Database::getInstance();
            $db->query('SELECT 1');
            $health['checks']['database'] = 'OK';
        } catch (Exception $e) {
            $health['checks']['database'] = 'ERROR: ' . $e->getMessage();
            $health['status'] = 'unhealthy';
        }
        
        // Check storage directories
        $storageConfig = ProductionStorageConfig::getConfig();
        $directories = [
            'exports' => $storageConfig['exports']['base_path'],
            'uploads' => $storageConfig['uploads']['base_path'],
            'temp' => $storageConfig['temp']['base_path'],
            'logs' => $storageConfig['logs']['base_path'],
        ];
        
        foreach ($directories as $name => $path) {
            if (is_dir($path) && is_writable($path)) {
                $health['checks']['storage_' . $name] = 'OK';
            } else {
                $health['checks']['storage_' . $name] = 'FAILED';
                $health['status'] = 'degraded';
            }
        }
        
        // Check disk space
        $freeSpace = disk_free_space('.');
        $totalSpace = disk_total_space('.');
        $usagePercent = ($totalSpace - $freeSpace) / $totalSpace;
        
        if ($usagePercent > 0.9) {
            $health['checks']['disk_space'] = 'CRITICAL: ' . round($usagePercent * 100, 1) . '% used';
            $health['status'] = 'unhealthy';
        } elseif ($usagePercent > 0.8) {
            $health['checks']['disk_space'] = 'WARNING: ' . round($usagePercent * 100, 1) . '% used';
            if ($health['status'] === 'healthy') {
                $health['status'] = 'degraded';
            }
        } else {
            $health['checks']['disk_space'] = 'OK: ' . round($usagePercent * 100, 1) . '% used';
        }
        
        return $health;
    }
}

// Auto-initialize if not in CLI mode and not explicitly disabled
if (php_sapi_name() !== 'cli' && !defined('SKIP_PRODUCTION_INIT')) {
    try {
        ProductionInitializer::initialize();
    } catch (Exception $e) {
        error_log('Failed to auto-initialize production environment: ' . $e->getMessage());
        // Continue execution with degraded functionality
    }
}