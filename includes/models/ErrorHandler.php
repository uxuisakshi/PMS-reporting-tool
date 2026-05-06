<?php
/**
 * ErrorHandler
 * Comprehensive error handling and graceful degradation for the client system
 * Implements fallback mechanisms when components fail
 */

class ErrorHandler {
    private $auditLogger;
    private $fallbackData;
    
    public function __construct($auditLogger = null) {
        $this->auditLogger = $auditLogger;
        $this->initializeFallbackData();
    }
    
    /**
     * Handle analytics engine failures with graceful degradation
     */
    public function handleAnalyticsFailure($engineType, $error, $projectIds = []) {
        // Log the error
        $this->logError('analytics_failure', $engineType, $error, [
            'project_ids' => $projectIds,
            'user_id' => $_SESSION['client_user_id'] ?? null
        ]);
        
        // Return fallback data based on engine type
        switch ($engineType) {
            case 'user_affected':
                return $this->getUserAffectedFallback();
            case 'wcag_compliance':
                return $this->getWCAGComplianceFallback();
            case 'severity':
                return $this->getSeverityFallback();
            case 'common_issues':
                return $this->getCommonIssuesFallback();
            case 'blocker_issues':
                return $this->getBlockerIssuesFallback();
            case 'page_issues':
                return $this->getPageIssuesFallback();
            case 'commented_issues':
                return $this->getCommentedIssuesFallback();
            case 'compliance_trend':
                return $this->getComplianceTrendFallback();
            default:
                return $this->getGenericFallback($engineType);
        }
    }
    
    /**
     * Handle database connection failures
     */
    public function handleDatabaseFailure($operation, $error) {
        $this->logError('database_failure', $operation, $error);
        
        // Return cached data if available
        $cacheManager = new CacheManager();
        if ($cacheManager->isAvailable()) {
            $cachedData = $this->getCachedFallbackData($operation);
            if ($cachedData) {
                return $cachedData;
            }
        }
        
        // Return static fallback data
        return $this->getStaticFallbackData($operation);
    }
    
    /**
     * Handle Redis/cache failures
     */
    public function handleCacheFailure($operation, $error) {
        $this->logError('cache_failure', $operation, $error);
        
        // Continue without caching - degrade gracefully
        return [
            'cache_available' => false,
            'fallback_mode' => true,
            'message' => 'Operating without cache - performance may be reduced'
        ];
    }
    
    /**
     * Handle export generation failures
     */
    public function handleExportFailure($exportType, $reportType, $error, $requestId = null) {
        $this->logError('export_failure', $exportType, $error, [
            'report_type' => $reportType,
            'request_id' => $requestId
        ]);
        
        // Update export request status if available
        if ($requestId) {
            try {
                $db = Database::getInstance();
                $stmt = $db->prepare("
                    UPDATE export_requests 
                    SET status = 'failed', error_message = ?, completed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$error, $requestId]);
            } catch (Exception $e) {
                // Log but don't throw - we're already handling an error
                error_log("Failed to update export request status: " . $e->getMessage());
            }
        }
        
        return [
            'success' => false,
            'error' => 'Export generation failed. Please try again later.',
            'fallback_available' => $this->isSimpleExportAvailable($reportType),
            'retry_suggested' => true
        ];
    }
    
    /**
     * Handle authentication failures
     */
    public function handleAuthFailure($type, $error, $context = []) {
        $this->logError('auth_failure', $type, $error, $context);
        
        switch ($type) {
            case 'session_expired':
                return [
                    'action' => 'redirect_login',
                    'message' => 'Your session has expired. Please log in again.',
                    'redirect_url' => '/client/login'
                ];
                
            case 'insufficient_permissions':
                return [
                    'action' => 'show_error',
                    'message' => 'You do not have permission to access this resource.',
                    'suggested_action' => 'Contact your administrator if you believe this is an error.'
                ];
                
            case 'rate_limit_exceeded':
                return [
                    'action' => 'show_error',
                    'message' => 'Too many requests. Please wait before trying again.',
                    'retry_after' => 300 // 5 minutes
                ];
                
            default:
                return [
                    'action' => 'show_error',
                    'message' => 'Authentication error occurred. Please try again.',
                    'retry_suggested' => true
                ];
        }
    }
    
    /**
     * Handle visualization rendering failures
     */
    public function handleVisualizationFailure($chartType, $error, $data = []) {
        $this->logError('visualization_failure', $chartType, $error, [
            'data_size' => is_array($data) ? count($data) : 0
        ]);
        
        return [
            'fallback_type' => 'table',
            'message' => 'Chart could not be rendered. Showing data in table format.',
            'data' => $this->convertToTableData($data),
            'chart_type' => $chartType
        ];
    }
    
    /**
     * Handle notification system failures
     */
    public function handleNotificationFailure($type, $error, $context = []) {
        $this->logError('notification_failure', $type, $error, $context);
        
        // Store notification for later retry
        $this->queueNotificationRetry($type, $context);
        
        return [
            'notification_sent' => false,
            'queued_for_retry' => true,
            'message' => 'Notification could not be sent immediately but has been queued for retry.'
        ];
    }
    
    /**
     * Get system health status
     */
    public function getSystemHealth() {
        $health = [
            'overall_status' => 'healthy',
            'components' => [],
            'degraded_services' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Check database
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT 1");
            $health['components']['database'] = 'healthy';
        } catch (Exception $e) {
            $health['components']['database'] = 'failed';
            $health['degraded_services'][] = 'database';
            $health['overall_status'] = 'degraded';
        }
        
        // Check Redis
        try {
            $cacheManager = new CacheManager();
            if ($cacheManager->isAvailable()) {
                $health['components']['cache'] = 'healthy';
            } else {
                $health['components']['cache'] = 'unavailable';
                $health['degraded_services'][] = 'cache';
                if ($health['overall_status'] === 'healthy') {
                    $health['overall_status'] = 'degraded';
                }
            }
        } catch (Exception $e) {
            $health['components']['cache'] = 'failed';
            $health['degraded_services'][] = 'cache';
            $health['overall_status'] = 'degraded';
        }
        
        // Check file system (exports directory)
        $exportDir = __DIR__ . '/../../tmp/exports/';
        if (is_writable($exportDir)) {
            $health['components']['file_system'] = 'healthy';
        } else {
            $health['components']['file_system'] = 'failed';
            $health['degraded_services'][] = 'file_system';
            $health['overall_status'] = 'degraded';
        }
        
        return $health;
    }
    
    /**
     * Initialize fallback data structures
     */
    private function initializeFallbackData() {
        $this->fallbackData = [
            'user_affected' => [
                'total_affected' => 0,
                'categories' => [],
                'chart_data' => ['labels' => [], 'values' => []],
                'message' => 'User affected data temporarily unavailable'
            ],
            'wcag_compliance' => [
                'overall_compliance' => 0,
                'level_breakdown' => ['A' => 0, 'AA' => 0, 'AAA' => 0],
                'chart_data' => ['labels' => ['A', 'AA', 'AAA'], 'values' => [0, 0, 0]],
                'message' => 'WCAG compliance data temporarily unavailable'
            ],
            'severity' => [
                'distribution' => ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0],
                'chart_data' => ['labels' => ['Critical', 'High', 'Medium', 'Low'], 'values' => [0, 0, 0, 0]],
                'message' => 'Severity data temporarily unavailable'
            ]
        ];
    }
    
    /**
     * Get fallback data for specific analytics type
     */
    private function getUserAffectedFallback() {
        return array_merge($this->fallbackData['user_affected'], [
            'fallback' => true,
            'error_message' => 'Unable to load user affected analytics'
        ]);
    }
    
    private function getWCAGComplianceFallback() {
        return array_merge($this->fallbackData['wcag_compliance'], [
            'fallback' => true,
            'error_message' => 'Unable to load WCAG compliance analytics'
        ]);
    }
    
    private function getSeverityFallback() {
        return array_merge($this->fallbackData['severity'], [
            'fallback' => true,
            'error_message' => 'Unable to load severity analytics'
        ]);
    }
    
    private function getCommonIssuesFallback() {
        return [
            'top_issues' => [],
            'total_unique_issues' => 0,
            'fallback' => true,
            'error_message' => 'Unable to load common issues analytics'
        ];
    }
    
    private function getBlockerIssuesFallback() {
        return [
            'total_blockers' => 0,
            'open_blockers' => 0,
            'resolution_rate' => 0,
            'fallback' => true,
            'error_message' => 'Unable to load blocker issues analytics'
        ];
    }
    
    private function getPageIssuesFallback() {
        return [
            'top_pages' => [],
            'total_pages_affected' => 0,
            'fallback' => true,
            'error_message' => 'Unable to load page issues analytics'
        ];
    }
    
    private function getCommentedIssuesFallback() {
        return [
            'total_commented' => 0,
            'recent_activity' => [],
            'fallback' => true,
            'error_message' => 'Unable to load commented issues analytics'
        ];
    }
    
    private function getComplianceTrendFallback() {
        return [
            'trend_data' => [],
            'chart_data' => ['labels' => [], 'values' => []],
            'fallback' => true,
            'error_message' => 'Unable to load compliance trend analytics'
        ];
    }
    
    private function getGenericFallback($type) {
        return [
            'data' => [],
            'fallback' => true,
            'error_message' => "Unable to load $type analytics",
            'type' => $type
        ];
    }
    
    /**
     * Convert data to table format for visualization fallback
     */
    private function convertToTableData($data) {
        if (!is_array($data) || empty($data)) {
            return ['headers' => [], 'rows' => []];
        }
        
        // If it's already in table format
        if (isset($data['headers']) && isset($data['rows'])) {
            return $data;
        }
        
        // Convert chart data to table
        if (isset($data['labels']) && isset($data['values'])) {
            return [
                'headers' => ['Category', 'Value'],
                'rows' => array_map(function($label, $value) {
                    return [$label, $value];
                }, $data['labels'], $data['values'])
            ];
        }
        
        // Convert associative array to table
        if (is_array($data) && !isset($data[0])) {
            return [
                'headers' => ['Property', 'Value'],
                'rows' => array_map(function($key, $value) {
                    return [$key, is_array($value) ? json_encode($value) : $value];
                }, array_keys($data), array_values($data))
            ];
        }
        
        return ['headers' => [], 'rows' => []];
    }
    
    /**
     * Check if simple export is available as fallback
     */
    private function isSimpleExportAvailable($reportType) {
        // Simple exports that don't require complex processing
        $simpleExports = ['user_affected', 'severity', 'common_issues'];
        return in_array($reportType, $simpleExports);
    }
    
    /**
     * Queue notification for retry
     */
    private function queueNotificationRetry($type, $context) {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO notification_retry_queue 
                (notification_type, context_data, retry_count, next_retry_at, created_at)
                VALUES (?, ?, 0, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW())
            ");
            $stmt->execute([$type, json_encode($context)]);
        } catch (Exception $e) {
            error_log("Failed to queue notification retry: " . $e->getMessage());
        }
    }
    
    /**
     * Get cached fallback data
     */
    private function getCachedFallbackData($operation) {
        // This would retrieve the last known good data from cache
        // Implementation depends on specific caching strategy
        return null;
    }
    
    /**
     * Get static fallback data
     */
    private function getStaticFallbackData($operation) {
        return [
            'data' => [],
            'message' => 'Service temporarily unavailable. Please try again later.',
            'fallback' => true,
            'operation' => $operation
        ];
    }
    
    /**
     * Log error with context
     */
    private function logError($category, $type, $error, $context = []) {
        $errorData = [
            'category' => $category,
            'type' => $type,
            'error' => $error,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $_SESSION['client_user_id'] ?? null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ];
        
        // Log to error log
        error_log("ErrorHandler [$category:$type]: $error - " . json_encode($context));
        
        // Log to audit system if available
        if ($this->auditLogger) {
            $this->auditLogger->logSystemError($category, $type, $error, $context);
        }
        
        // Store in database for analysis
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO system_errors 
                (category, type, error_message, context_data, user_id, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $category,
                $type,
                $error,
                json_encode($context),
                $_SESSION['client_user_id'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (Exception $e) {
            // Don't throw - we're already handling an error
            error_log("Failed to log error to database: " . $e->getMessage());
        }
    }
}
