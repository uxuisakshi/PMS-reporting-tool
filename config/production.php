<?php
// Define LOG_LOCAL0 if not defined (e.g. on Windows)
if (!defined('LOG_LOCAL0')) {
    define('LOG_LOCAL0', 128); // Standard value for LOG_LOCAL0
}

/**
 * System Logger
 */
if (class_exists('Redis')) {
    // Redis related logging or caching config...
}
// Define LOG_LOCAL0 if not defined (e.g. on Windows)
if (!defined('LOG_LOCAL0')) {
    define('LOG_LOCAL0', 128); // Standard value for LOG_LOCAL0
}
/**
 * Production Configuration for Client Reporting and Analytics System
 * 
 * This file contains production-specific settings for:
 * - Caching strategies with appropriate TTL settings
 * - Security headers and performance optimization
 * - Email configuration for notifications
 * - File storage permissions for secure export handling
 * 
 * Requirements: 18.1 (Performance and Caching), 17.5 (Security), 19.4 (Notifications)
 */

class ProductionConfig {
    
    /**
     * Caching Configuration
     * Requirement 18.1: Performance and Caching
     */
    public static function getCacheConfig() {
        return [
            // Redis Configuration
            'redis' => [
                'enabled' => true,
                'host' => $_ENV['REDIS_HOST'] ?? 'localhost',
                'port' => $_ENV['REDIS_PORT'] ?? 6379,
                'password' => $_ENV['REDIS_PASSWORD'] ?? null,
                'database' => $_ENV['REDIS_DB'] ?? 0,
                'timeout' => 5.0,
                'read_timeout' => 10.0,
                'persistent' => true,
                'prefix' => 'pms_prod_',
            ],
            
            // Cache TTL Settings (in seconds)
            'ttl' => [
                // Analytics reports - cache for 30 minutes (data changes frequently)
                'analytics_user_affected' => 1800,
                'analytics_wcag_compliance' => 1800,
                'analytics_severity' => 1800,
                'analytics_common_issues' => 1800,
                'analytics_blocker_issues' => 900,  // 15 minutes (more critical)
                'analytics_page_issues' => 1800,
                'analytics_commented_issues' => 600, // 10 minutes (comments change often)
                'analytics_compliance_trend' => 3600, // 1 hour (historical data)
                'analytics_unified_dashboard' => 1800,
                
                // Project data - cache for 1 hour
                'project_assignments' => 3600,
                'client_permissions' => 3600,
                'project_metadata' => 3600,
                
                // Static data - cache for 4 hours
                'user_roles' => 14400,
                'system_settings' => 14400,
                
                // Session data - cache for 30 minutes
                'user_sessions' => 1800,
                'auth_tokens' => 1800,
            ],
            
            // Cache invalidation rules
            'invalidation' => [
                'on_issue_update' => [
                    'analytics_user_affected',
                    'analytics_wcag_compliance',
                    'analytics_severity',
                    'analytics_common_issues',
                    'analytics_blocker_issues',
                    'analytics_page_issues',
                    'analytics_commented_issues',
                    'analytics_unified_dashboard'
                ],
                'on_project_assignment' => [
                    'project_assignments',
                    'client_permissions',
                    'analytics_unified_dashboard'
                ],
                'on_user_role_change' => [
                    'user_roles',
                    'client_permissions'
                ]
            ],
            
            // Memory limits
            'memory' => [
                'max_memory_usage' => '256M',
                'cache_size_limit' => '128M',
                'cleanup_threshold' => 0.8, // Clean up when 80% full
            ]
        ];
    }
    
    /**
     * Security Headers Configuration
     * Requirement 17.5: Security
     */
    public static function getSecurityHeaders() {
        return [
            // Content Security Policy
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com https://cdn.datatables.net https://code.highcharts.com",
                "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net https://fonts.googleapis.com",
                "img-src 'self' data: https:",
                "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.gstatic.com",
                "connect-src 'self' https://code.highcharts.com",
                "media-src 'self'",
                "object-src 'none'",
                "frame-src 'none'",
                "base-uri 'self'",
                "form-action 'self'"
            ]),
            
            // Security headers
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            // X-XSS-Protection intentionally removed — deprecated and can introduce XSS auditor bypass vulnerabilities
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            
            // HSTS — preload removed until domain is submitted to hstspreload.org
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            
            // Cache control for sensitive pages
            'Cache-Control' => 'no-cache, no-store, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ];
    }
    
    /**
     * Performance Optimization Configuration
     * Requirement 18.1: Performance and Caching
     */
    public static function getPerformanceConfig() {
        return [
            // Database optimization
            'database' => [
                'connection_pool_size' => 10,
                'query_timeout' => 30,
                'slow_query_log' => true,
                'slow_query_threshold' => 2.0, // seconds
                'enable_query_cache' => true,
                'max_connections' => 100,
            ],
            
            // PHP optimization
            'php' => [
                'memory_limit' => '512M',
                'max_execution_time' => 300, // 5 minutes for exports
                'max_input_time' => 60,
                'post_max_size' => '50M',
                'upload_max_filesize' => '20M',
                'opcache_enable' => true,
                'opcache_memory_consumption' => 256,
                'opcache_max_accelerated_files' => 20000,
            ],
            
            // Static asset caching
            'static_cache' => [
                'css_cache_time' => 2592000, // 30 days
                'js_cache_time' => 2592000,  // 30 days
                'image_cache_time' => 2592000, // 30 days
                'font_cache_time' => 2592000,  // 30 days
                'enable_gzip' => true,
                'enable_brotli' => true,
            ],
            
            // Response compression
            'compression' => [
                'enable_gzip' => true,
                'gzip_level' => 6,
                'min_compress_size' => 1024, // bytes
                'compress_types' => [
                    'text/html',
                    'text/css',
                    'text/javascript',
                    'application/javascript',
                    'application/json',
                    'text/xml',
                    'application/xml'
                ]
            ]
        ];
    }
    
    /**
     * Email Configuration for Production
     * Requirement 19.4: Notifications
     */
    public static function getEmailConfig() {
        return [
            // SMTP Configuration
            'smtp' => [
                'enabled' => true,
                'host' => $_ENV['SMTP_HOST'] ?? 'mail.athenaeumtransformation.com',
                'port' => (int)($_ENV['SMTP_PORT'] ?? 465),
                'secure' => $_ENV['SMTP_SECURE'] ?? 'ssl', // ssl, tls, or false
                'auth' => true,
                'username' => $_ENV['SMTP_USERNAME'] ?? 'noreply@athenaeumtransformation.com',
                'password' => $_ENV['SMTP_PASSWORD'] ?? '',
                'timeout' => 30,
                'keepalive' => true,
            ],
            
            // Email settings
            'from' => [
                'email' => $_ENV['MAIL_FROM'] ?? 'noreply@athenaeumtransformation.com',
                'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Athenaeum PMS - Client Analytics',
            ],
            
            // Email templates
            'templates' => [
                'project_assignment' => [
                    'subject' => 'New Project Access Granted - {project_name}',
                    'template_file' => 'email/project_assignment.html',
                ],
                'project_revocation' => [
                    'subject' => 'Project Access Revoked - {project_name}',
                    'template_file' => 'email/project_revocation.html',
                ],
                'export_ready' => [
                    'subject' => 'Your Analytics Export is Ready',
                    'template_file' => 'email/export_ready.html',
                ],
                'system_notification' => [
                    'subject' => 'System Notification - {notification_type}',
                    'template_file' => 'email/system_notification.html',
                ],
            ],
            
            // Email queue settings
            'queue' => [
                'enabled' => true,
                'max_retries' => 3,
                'retry_delay' => 300, // 5 minutes
                'batch_size' => 10,
                'process_interval' => 60, // 1 minute
            ],
            
            // Rate limiting
            'rate_limit' => [
                'max_emails_per_hour' => 100,
                'max_emails_per_day' => 1000,
                'max_emails_per_user_per_hour' => 10,
            ],
            
            // Email validation
            'validation' => [
                'verify_mx_record' => true,
                'check_disposable_domains' => true,
                'max_email_length' => 254,
            ]
        ];
    }
    
    /**
     * File Storage Configuration
     * Requirement 19.4: File storage permissions for secure export handling
     */
    public static function getFileStorageConfig() {
        return [
            // Export file settings
            'exports' => [
                'base_path' => $_ENV['EXPORT_PATH'] ?? dirname(__DIR__) . '/tmp/exports/',
                'max_file_size' => 50 * 1024 * 1024, // 50MB
                'allowed_formats' => ['pdf', 'xlsx', 'csv'],
                'retention_days' => 7, // Keep exports for 7 days
                'cleanup_interval' => 3600, // Clean up every hour
                'permissions' => 0640, // rw-r-----
                'user_subdirectories' => true,
            ],
            
            // Upload settings
            'uploads' => [
                'base_path' => $_ENV['UPLOAD_PATH'] ?? dirname(__DIR__) . '/uploads/',
                'max_file_size' => 20 * 1024 * 1024, // 20MB
                'allowed_types' => [
                    'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'],
                    'archive' => ['zip', 'tar', 'gz']
                ],
                'scan_uploads' => true, // Virus scanning if available
                'permissions' => 0644, // rw-r--r--
            ],
            
            // Temporary files
            'temp' => [
                'base_path' => $_ENV['TEMP_PATH'] ?? sys_get_temp_dir() . '/pms/',
                'max_age' => 3600, // 1 hour
                'cleanup_interval' => 1800, // Clean up every 30 minutes
                'permissions' => 0600, // rw-------
            ],
            
            // Logs
            'logs' => [
                'base_path' => $_ENV['LOG_PATH'] ?? dirname(__DIR__) . '/tmp/logs/',
                'max_file_size' => 100 * 1024 * 1024, // 100MB
                'max_files' => 30, // Keep 30 log files
                'permissions' => 0640, // rw-r-----
                'rotate_daily' => true,
            ],
            
            // Security settings
            'security' => [
                'disable_php_execution' => true,
                'block_dangerous_extensions' => [
                    'php', 'php3', 'php4', 'php5', 'phtml', 'phps',
                    'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb',
                    'exe', 'com', 'bat', 'cmd', 'scr', 'vbs', 'js'
                ],
                'content_type_validation' => true,
                'filename_sanitization' => true,
                'path_traversal_protection' => true,
            ]
        ];
    }
    
    /**
     * Logging Configuration
     * Requirement 17.5: Security (audit logging)
     */
    public static function getLoggingConfig() {
        return [
            // Log levels
            'levels' => [
                'emergency' => 0,
                'alert' => 1,
                'critical' => 2,
                'error' => 3,
                'warning' => 4,
                'notice' => 5,
                'info' => 6,
                'debug' => 7
            ],
            
            // Production log level
            'min_level' => $_ENV['LOG_LEVEL'] ?? 'warning',
            
            // Log destinations
            'handlers' => [
                'file' => [
                    'enabled' => true,
                    'path' => $_ENV['LOG_PATH'] ?? dirname(__DIR__) . '/tmp/logs/app.log',
                    'max_size' => 100 * 1024 * 1024, // 100MB
                    'backup_count' => 5,
                ],
                'syslog' => [
                    'enabled' => false,
                    'facility' => LOG_LOCAL0,
                    'ident' => 'pms_analytics',
                ],
                'email' => [
                    'enabled' => true,
                    'min_level' => 'error',
                    'to' => $_ENV['ADMIN_EMAIL'] ?? 'admin@athenaeumtransformation.com',
                    'subject' => 'PMS Analytics System Error',
                ]
            ],
            
            // Security logging
            'security' => [
                'log_failed_logins' => true,
                'log_permission_denials' => true,
                'log_data_exports' => true,
                'log_admin_actions' => true,
                'log_file_uploads' => true,
                'retention_days' => 365, // Keep security logs for 1 year
            ]
        ];
    }
    
    /**
     * Session Configuration
     * Requirement 17.5: Security
     */
    public static function getSessionConfig() {
        return [
            'cookie_lifetime' => 0, // Session cookie
            'cookie_path' => '/',
            'cookie_domain' => $_ENV['SESSION_DOMAIN'] ?? '',
            'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'use_strict_mode' => true,
            'use_cookies' => true,
            'use_only_cookies' => true,
            'use_trans_sid' => false,
            'cache_limiter' => 'nocache',
            'gc_maxlifetime' => 1800, // 30 minutes
            'gc_probability' => 1,
            'gc_divisor' => 100,
            'name' => 'PMS_SESSID',
            'save_handler' => 'redis', // Use Redis for session storage
            'save_path' => 'tcp://localhost:6379?database=1',
        ];
    }
    
    /**
     * Apply all production configurations
     */
    public static function apply() {
        // Set security headers
        foreach (self::getSecurityHeaders() as $header => $value) {
            if (!headers_sent()) {
                header("$header: $value");
            }
        }
        
        // Configure PHP settings
        $phpConfig = self::getPerformanceConfig()['php'];
        foreach ($phpConfig as $setting => $value) {
            if (is_bool($value)) {
                ini_set($setting, $value ? '1' : '0');
            } else {
                ini_set($setting, (string)$value);
            }
        }
        
        // Configure session settings (only if Redis is available)
        $sessionConfig = self::getSessionConfig();
        foreach ($sessionConfig as $setting => $value) {
            // Skip Redis session handler if Redis is not available
            if ($setting === 'save_handler' && $value === 'redis' && !class_exists('Redis')) {
                continue;
            }
            if ($setting === 'save_path' && strpos($value, 'redis') !== false && !class_exists('Redis')) {
                continue;
            }
            
            if (strpos($setting, 'cookie_') === 0) {
                $iniSetting = 'session.' . $setting;
            } else {
                $iniSetting = 'session.' . $setting;
            }
            
            if (is_bool($value)) {
                ini_set($iniSetting, $value ? '1' : '0');
            } else {
                ini_set($iniSetting, (string)$value);
            }
        }
        
        // Set error reporting for production
        error_reporting(E_ERROR | E_WARNING | E_PARSE);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        
        // Set timezone
        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');
    }
}

// Auto-apply configuration if this file is included
if (!defined('SKIP_PRODUCTION_CONFIG')) {
    ProductionConfig::apply();
}