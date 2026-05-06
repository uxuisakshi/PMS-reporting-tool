<?php
/**
 * AuditLogger
 * Centralized audit logging system for security and compliance
 * Logs all client activities and admin actions with secure audit trail
 */

require_once __DIR__ . '/../../config/database.php';

class AuditLogger {
    private $db;
    private $retentionDays;
    
    // Action types for consistent logging
    const ACTION_LOGIN_SUCCESS = 'login_success';
    const ACTION_LOGIN_FAILED = 'login_failed';
    const ACTION_LOGOUT = 'logout';
    const ACTION_REAUTH_SUCCESS = 'reauth_success';
    const ACTION_REAUTH_FAILED = 'reauth_failed';
    const ACTION_PROJECT_ASSIGNMENT = 'project_assignment';
    const ACTION_PROJECT_REVOCATION = 'project_revocation';
    const ACTION_PROJECT_ACCESS = 'project_access';
    const ACTION_EXPORT_REQUEST = 'export_request';
    const ACTION_EXPORT_DOWNLOAD = 'export_download';
    const ACTION_NOTIFICATION_SENT = 'notification_sent';
    const ACTION_DATA_ACCESS = 'data_access';
    const ACTION_SECURITY_VIOLATION = 'security_violation';
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->retentionDays = 365; // Default 1 year retention
    }
    
    /**
     * Log client activity to audit trail
     */
    public function logClientActivity($clientUserId, $actionType, $actionDetails, $success = true, $errorMessage = null, $resourceType = null, $resourceId = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, action_details, resource_type, resource_id, 
                 ip_address, user_agent, success, error_message, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $clientUserId,
                $actionType,
                $actionDetails,
                $resourceType,
                $resourceId,
                $this->getClientIP(),
                $this->getUserAgent(),
                $success ? 1 : 0,
                $errorMessage
            ]);
            
            return true;
            
        } catch (Exception $e) {
            // Log to system error log but don't throw exception
            error_log("Audit logging failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log admin activity to audit trail
     */
    public function logAdminActivity($adminUserId, $actionType, $actionDetails, $targetUserId = null, $success = true, $errorMessage = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs 
                (user_id, action, details, target_user_id, ip_address, success, error_message, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $adminUserId,
                $actionType,
                $actionDetails,
                $targetUserId,
                $this->getClientIP(),
                $success ? 1 : 0,
                $errorMessage
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Admin audit logging failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log security violation
     */
    public function logSecurityViolation($userId, $violationType, $details, $severity = 'medium') {
        $actionDetails = json_encode([
            'violation_type' => $violationType,
            'severity' => $severity,
            'details' => $details,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        return $this->logClientActivity(
            $userId, 
            self::ACTION_SECURITY_VIOLATION, 
            $actionDetails, 
            false, 
            "Security violation: $violationType"
        );
    }
    
    /**
     * Get audit trail for a specific user
     */
    public function getUserAuditTrail($userId, $limit = 100, $offset = 0, $actionType = null) {
        try {
            $whereClause = "WHERE client_user_id = ?";
            $params = [$userId];
            
            if ($actionType) {
                $whereClause .= " AND action_type = ?";
                $params[] = $actionType;
            }
            
            $stmt = $this->db->prepare("
                SELECT * FROM client_audit_log 
                $whereClause
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to retrieve audit trail: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get security violations within time period
     */
    public function getSecurityViolations($hours = 24) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM client_audit_log 
                WHERE action_type = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY created_at DESC
            ");
            
            $stmt->execute([self::ACTION_SECURITY_VIOLATION, $hours]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to retrieve security violations: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clean up old audit logs based on retention policy
     */
    public function cleanupOldLogs() {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM client_audit_log 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            $stmt->execute([$this->retentionDays]);
            $deletedRows = $stmt->rowCount();
            
            // Also clean up general audit logs
            $stmt = $this->db->prepare("
                DELETE FROM audit_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            $stmt->execute([$this->retentionDays]);
            $deletedRows += $stmt->rowCount();
            
            return $deletedRows;
            
        } catch (Exception $e) {
            error_log("Failed to cleanup audit logs: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get audit statistics
     */
    public function getAuditStats($days = 30) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    action_type,
                    COUNT(*) as count,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed
                FROM client_audit_log 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY action_type
                ORDER BY count DESC
            ");
            
            $stmt->execute([$days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to retrieve audit stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Set retention policy
     */
    public function setRetentionDays($days) {
        $this->retentionDays = max(30, $days); // Minimum 30 days
    }
    
    /**
     * Get client IP address
     */
    private function getClientIP() {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) && !empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Get user agent
     */
    private function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
    
    /**
     * Validate audit log integrity
     */
    public function validateLogIntegrity($logId) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM client_audit_log WHERE id = ?
            ");
            
            $stmt->execute([$logId]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$log) {
                return false;
            }
            
            // Basic integrity checks
            $checks = [
                'has_timestamp' => !empty($log['created_at']),
                'has_action_type' => !empty($log['action_type']),
                'has_user_id' => !empty($log['client_user_id']),
                'valid_success_flag' => in_array($log['success'], [0, 1])
            ];
            
            return array_sum($checks) === count($checks);
            
        } catch (Exception $e) {
            error_log("Failed to validate log integrity: " . $e->getMessage());
            return false;
        }
    }
}
