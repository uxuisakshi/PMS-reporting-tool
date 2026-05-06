<?php
/**
 * ClientUser Model
 * Handles client user authentication, role validation, and session management
 * Extends base user functionality with client-specific restrictions
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../config/database.php';

class ClientUser {
    private $db;
    private $userId;
    private $userData;
    
    public function __construct($userId = null) {
        $this->db = Database::getInstance();
        if ($userId) {
            $this->userId = $userId;
            $this->loadUserData();
        }
    }
    
    /**
     * Load user data from database
     */
    private function loadUserData() {
        if (!$this->userId) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, email, full_name, role, is_active, 
                       account_setup_completed, created_at
                FROM users 
                WHERE id = ? AND role = 'client' AND is_active = 1
            ");
            $stmt->execute([$this->userId]);
            $this->userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $this->userData !== false;
        } catch (Exception $e) {
            error_log('ClientUser loadUserData error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Authenticate client user with credentials
     */
    public static function authenticate($username, $password) {
        $pdo = Database::getInstance();
        
        try {
            // Find user with client role
            $stmt = $pdo->prepare("
                SELECT id, username, email, password, full_name, is_active, 
                       account_setup_completed, force_password_reset
                FROM users 
                WHERE (username = ? OR email = ?) 
                AND role = 'client' 
                AND is_active = 1
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                // Log failed attempt for unknown user (best practice to detect enumeration/brute-force)
                self::logAuthAttempt(0, 'login_failed_unknown', 'Login attempted with unknown user: ' . $username);
                return ['success' => false, 'error' => 'Invalid credentials or access denied'];
            }
            
            // Verify password
            if (!password_verify($password, $user['password'])) {
                // Log failed attempt
                self::logAuthAttempt($user['id'], 'login_failed', 'Invalid password');
                return ['success' => false, 'error' => 'Invalid credentials'];
            }
            
            // Check if account setup is completed
            if (!$user['account_setup_completed']) {
                return [
                    'success' => false, 
                    'error' => 'Account setup not completed. Please contact administrator.',
                    'requires_setup' => true
                ];
            }
            
            // Check if password reset is required
            if ($user['force_password_reset']) {
                return [
                    'success' => false,
                    'error' => 'Password reset required. Please contact administrator.',
                    'requires_reset' => true
                ];
            }
            
            // Log successful attempt
            self::logAuthAttempt($user['id'], 'login_success', 'Client login successful');
            
            return [
                'success' => true,
                'user_id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'full_name' => $user['full_name']
            ];
            
        } catch (Exception $e) {
            error_log('ClientUser authentication error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Authentication system error'];
        }
    }
    
    /**
     * Validate if user has client role
     */
    public static function isClientUser($userId) {
        $pdo = Database::getInstance();
        
        try {
            $stmt = $pdo->prepare("
                SELECT id FROM users 
                WHERE id = ? AND role = 'client' AND is_active = 1
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchColumn() !== false;
        } catch (Exception $e) {
            error_log('ClientUser isClientUser error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create client session with security measures
     */
    public static function createSession($userId, $userData) {
        if (!self::isClientUser($userId)) {
            return false;
        }
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        // Set session data
        $_SESSION['user_id'] = $userId;
        $_SESSION['client_user_id'] = $userId; // For consistency with router
        $_SESSION['username'] = $userData['username'];
        $_SESSION['email'] = $userData['email'];
        $_SESSION['full_name'] = $userData['full_name'];
        $_SESSION['role'] = 'client';
        $_SESSION['client_role'] = 'client'; // For consistency with router
        $_SESSION['is_client'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['last_reauth'] = time(); // Track reauth separately from activity
        $_SESSION['session_token'] = bin2hex(random_bytes(32));
        
        // Set session timeout (4 hours for clients)
        $_SESSION['session_timeout'] = 4 * 3600; // 4 hours
        
        // Log session creation
        self::logAuthAttempt($userId, 'session_created', 'Client session created');
        
        return true;
    }
    
    /**
     * Validate client session
     */
    public static function validateSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if user is logged in as client
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_client']) || !$_SESSION['is_client']) {
            return false;
        }
        
        // Check session timeout
        $sessionTimeout = $_SESSION['session_timeout'] ?? 3600;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $sessionTimeout) {
            self::destroySession();
            return false;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        // Validate user still exists and is active client
        if (!self::isClientUser($_SESSION['user_id'])) {
            self::destroySession();
            return false;
        }
        
        return true;
    }
    
    /**
     * Destroy client session
     */
    public static function destroySession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $userId = $_SESSION['user_id'] ?? null;
        
        // Log session destruction
        if ($userId) {
            self::logAuthAttempt($userId, 'session_destroyed', 'Client session ended');
        }
        
        // Clear session data
        $_SESSION = [];
        
        // Destroy session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Destroy session
        session_destroy();
    }
    
    /**
     * Get current client user data
     */
    public static function getCurrentUser() {
        if (!self::validateSession()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'email' => $_SESSION['email'],
            'full_name' => $_SESSION['full_name'],
            'role' => $_SESSION['role'],
            'login_time' => $_SESSION['login_time'],
            'last_activity' => $_SESSION['last_activity']
        ];
    }
    
    /**
     * Check if current session requires re-authentication
     * Uses last_reauth timestamp (set on login or successful reauth), not last_activity
     * so that normal page activity doesn't reset the reauth clock.
     */
    public static function requiresReauth() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_client']) || !$_SESSION['is_client']) {
            return true;
        }

        $reauthTimeout = 3600; // 1 hour
        // Use dedicated reauth timestamp; fall back to login_time if not set
        $lastReauth = $_SESSION['last_reauth'] ?? $_SESSION['login_time'] ?? 0;

        return (time() - $lastReauth) > $reauthTimeout;
    }
    
    /**
     * Log authentication attempts and activities
     */
    private static function logAuthAttempt($userId, $actionType, $details) {
        $pdo = Database::getInstance();
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, action_details, ip_address, user_agent, success, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            
            $actionDetails = json_encode([
                'details' => $details,
                'timestamp' => date('Y-m-d H:i:s'),
                'session_id' => session_id()
            ]);
            
            $stmt->execute([
                $userId,
                $actionType,
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log('ClientUser logAuthAttempt error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user data
     */
    public function getUserData() {
        return $this->userData;
    }
    
    /**
     * Get user ID
     */
    public function getId() {
        return $this->userId;
    }
    
    /**
     * Check if user is active
     */
    public function isActive() {
        return $this->userData && $this->userData['is_active'] == 1;
    }
    
    /**
     * Get user's full name
     */
    public function getFullName() {
        return $this->userData['full_name'] ?? '';
    }
    
    /**
     * Get user's email
     */
    public function getEmail() {
        return $this->userData['email'] ?? '';
    }
    
    /**
     * Get user's username
     */
    public function getUsername() {
        return $this->userData['username'] ?? '';
    }
    
    /**
     * Check if account setup is completed
     */
    public function isSetupCompleted() {
        return $this->userData && $this->userData['account_setup_completed'] == 1;
    }
}