<?php
/**
 * ClientAuthenticationController
 * Handles client login/logout with security logging and session management
 * Implements session timeout and re-authentication for sensitive operations
 */

require_once __DIR__ . '/../models/ClientUser.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/redis.php';

class ClientAuthenticationController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Handle client logout request
     */
    public function logout() {
        header('Content-Type: application/json');
        
        try {
            $currentUser = ClientUser::getCurrentUser();
            
            // Destroy session
            ClientUser::destroySession();
            
            // Log logout
            if ($currentUser) {
                $this->logLogout($currentUser['id'], $currentUser['username']);
            }
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Logged out successfully',
                'redirect' => '/modules/auth/login.php?client=1'
            ]);
            
        } catch (Exception $e) {
            error_log('ClientAuthenticationController logout error: ' . $e->getMessage());
            return $this->jsonError('Logout error', 500);
        }
    }
    
    /**
     * Check session status
     */
    public function checkSession() {
        header('Content-Type: application/json');
        
        try {
            if (ClientUser::validateSession()) {
                $currentUser = ClientUser::getCurrentUser();
                
                return $this->jsonResponse([
                    'success' => true,
                    'authenticated' => true,
                    'user' => $currentUser,
                    'requires_reauth' => ClientUser::requiresReauth()
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => true,
                    'authenticated' => false,
                    'message' => 'Session expired or invalid'
                ]);
            }
            
        } catch (Exception $e) {
            error_log('ClientAuthenticationController checkSession error: ' . $e->getMessage());
            return $this->jsonError('Session check error', 500);
        }
    }
    
    /**
     * Handle re-authentication for sensitive operations
     */
    public function reauth() {
        header('Content-Type: application/json');
        
        try {
            // Check if user is logged in
            if (!ClientUser::validateSession()) {
                return $this->jsonError('Session expired. Please login again.', 401);
            }
            
            $currentUser = ClientUser::getCurrentUser();
            $password = $_POST['password'] ?? '';
            
            if (empty($password)) {
                return $this->jsonError('Password is required for re-authentication', 400);
            }
            
            // Verify password
            $authResult = ClientUser::authenticate($currentUser['username'], $password);
            
            if (!$authResult['success']) {
                $this->logFailedReauth($currentUser['id'], 'Invalid password for re-authentication');
                return $this->jsonError('Invalid password', 401);
            }
            
            // Update session with new activity time
            $_SESSION['last_activity'] = time();
            $_SESSION['last_reauth'] = time();
            
            // Log successful re-authentication
            $this->logSuccessfulReauth($currentUser['id']);
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Re-authentication successful'
            ]);
            
        } catch (Exception $e) {
            error_log('ClientAuthenticationController reauth error: ' . $e->getMessage());
            return $this->jsonError('Re-authentication error', 500);
        }
    }
    
    /**
     * Rate limiting check
     */
    private function isRateLimited($username) {
        $cacheKey = 'client_login_attempts_' . md5($username . $_SERVER['REMOTE_ADDR']);

        // Use Redis if available
        $redis = RedisConfig::getInstance();
        if ($redis->isAvailable()) {
            $attempts = $redis->get($cacheKey) ?? 0;
            return $attempts >= 5;
        }

        // Fallback: DB-based rate limiting (session-based is bypassable)
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM login_attempts
                WHERE ip_address = ?
                  AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? 'unknown']);
            return (int)$stmt->fetchColumn() >= 5;
        } catch (Exception $e) {
            // Fail-closed: deny on DB error
            return true;
        }
    }
    
    /**
     * Clear rate limiting
     */
    private function clearRateLimit($username) {
        $cacheKey = 'client_login_attempts_' . md5($username . $_SERVER['REMOTE_ADDR']);

        $redis = RedisConfig::getInstance();
        if ($redis->isAvailable()) {
            $redis->delete($cacheKey);
            return;
        }

        // DB-based: clear login_attempts for this IP
        try {
            $stmt = $this->db->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        } catch (Exception $e) {
            // non-fatal
        }
    }
    
    /**
     * Log failed login attempt
     */
    private function logFailedAttempt($username, $error) {
        // Log to audit table and DB-based rate limit counter
        try {
            // Increment DB rate limit counter (shared with main login_attempts table)
            $ipAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $usernameHash = md5(strtolower($username));
            $incrStmt = $this->db->prepare("INSERT INTO login_attempts (ip_address, username_hash) VALUES (?, ?)");
            $incrStmt->execute([$ipAddr, $usernameHash]);
        } catch (Exception $e) {
            // non-fatal
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, action_details, ip_address, user_agent, success, error_message, created_at)
                VALUES (0, 'login_failed', ?, ?, ?, 0, ?, NOW())
            ");
            
            $actionDetails = json_encode([
                'username' => $username,
                'error' => $error,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $error
            ]);
            
        } catch (Exception $e) {
            error_log('Failed to log client login attempt: ' . $e->getMessage());
        }
    }
    
    /**
     * Log successful login
     */
    private function logSuccessfulLogin($userId, $username) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, action_details, ip_address, user_agent, success, created_at)
                VALUES (?, 'login_success', ?, ?, ?, 1, NOW())
            ");
            
            $actionDetails = json_encode([
                'username' => $username,
                'timestamp' => date('Y-m-d H:i:s'),
                'session_id' => session_id()
            ]);
            
            $stmt->execute([
                $userId,
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log('Failed to log successful client login: ' . $e->getMessage());
        }
    }
    
    /**
     * Log logout
     */
    private function logLogout($userId, $username) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, action_details, ip_address, user_agent, success, created_at)
                VALUES (?, 'logout', ?, ?, ?, 1, NOW())
            ");
            
            $actionDetails = json_encode([
                'username' => $username,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $userId,
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log('Failed to log client logout: ' . $e->getMessage());
        }
    }
    
    /**
     * Log failed re-authentication
     */
    private function logFailedReauth($userId, $error) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, action_details, ip_address, user_agent, success, error_message, created_at)
                VALUES (?, 'reauth_failed', ?, ?, ?, 0, ?, NOW())
            ");
            
            $actionDetails = json_encode([
                'error' => $error,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $userId,
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $error
            ]);
            
        } catch (Exception $e) {
            error_log('Failed to log client reauth attempt: ' . $e->getMessage());
        }
    }
    
    /**
     * Log successful re-authentication
     */
    private function logSuccessfulReauth($userId) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, action_details, ip_address, user_agent, success, created_at)
                VALUES (?, 'reauth_success', ?, ?, ?, 1, NOW())
            ");
            
            $actionDetails = json_encode([
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $userId,
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log('Failed to log successful client reauth: ' . $e->getMessage());
        }
    }
    
    /**
     * Send JSON response
     */
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Send JSON error response
     */
    private function jsonError($message, $statusCode = 400) {
        http_response_code($statusCode);
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}