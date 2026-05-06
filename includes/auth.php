<?php
// includes/auth.php

// Set timezone to IST (Indian Standard Time) for all time operations
require_once __DIR__ . '/../config/timezone.php';

// Start session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    // Configure session security
    session_name('PMS_SESSION');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    
    // For localhost development, use Lax; for production, use Strict
    $host = strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? '');
    $isLocalhost = ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1');
    $samesite = $isLocalhost ? 'Lax' : 'Strict';
    ini_set('session.cookie_samesite', $samesite);
    // Detect HTTPS: check HTTPS server var OR X-Forwarded-Proto (for reverse proxies/load balancers)
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    // Enforce Secure cookie on HTTPS; allow non-secure only on localhost HTTP
    ini_set('session.cookie_secure', ($isHttps || !$isLocalhost) ? 1 : 0);

    // Try Redis session handler first — eliminates file-locking under concurrent load
    $redisSessionSet = false;
    if (class_exists('Redis')) {
        try {
            $redisHost = $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: 'localhost';
            $redisPort = (int)($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379);
            $redisPass = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: null;
            $redisDb   = (int)($_ENV['REDIS_DB']   ?? getenv('REDIS_DB')   ?: 0);

            $redisClass = 'Redis';
            $testRedis = new $redisClass();
            if (@$testRedis->connect($redisHost, $redisPort, 1.0)) {
                if ($redisPass) $testRedis->auth($redisPass);
                $testRedis->select($redisDb);
                $testRedis->ping();

                ini_set('session.save_handler', 'redis');
                $redisDsn = "tcp://{$redisHost}:{$redisPort}?database={$redisDb}";
                if ($redisPass) $redisDsn .= "&auth={$redisPass}";
                ini_set('session.save_path', $redisDsn);
                $redisSessionSet = true;
            }
        } catch (Exception $e) {
            // Redis unavailable — fall through to file-based sessions
        }
    }

    if (!$redisSessionSet) {
        // Fallback: file-based sessions
        $preferredSessionPath = __DIR__ . '/../tmp/sessions';
        $sessionPathSet = false;

        if (!is_dir($preferredSessionPath)) {
            @mkdir($preferredSessionPath, 0750, true);
        }
        if (is_dir($preferredSessionPath)) {
            if (!is_writable($preferredSessionPath)) @chmod($preferredSessionPath, 0750);
            if (is_writable($preferredSessionPath)) {
                session_save_path($preferredSessionPath);
                $sessionPathSet = true;
            }
        }
        if (!$sessionPathSet && is_dir('/tmp') && is_writable('/tmp')) {
            session_save_path('/tmp');
            $sessionPathSet = true;
        }
        if (!$sessionPathSet) {
            $sysTemp = sys_get_temp_dir();
            if (is_dir($sysTemp) && is_writable($sysTemp)) session_save_path($sysTemp);
        }
    }

    session_start();
    
    // Regenerate session ID periodically to prevent session fixation
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        // Regenerate session ID every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

// Include configuration
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/helpers.php';

// Keep session permissions in sync with DB (so role/perm updates apply immediately)
if (isset($_SESSION['user_id'])) {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT role, is_active, force_password_reset, can_manage_issue_config, can_manage_devices FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            // If role changed, regenerate session ID to prevent privilege escalation via old session
            $prevRole = $_SESSION['role'] ?? null;
            if ($prevRole !== null && $prevRole !== $u['role']) {
                session_regenerate_id(true);
            }
            $_SESSION['role'] = $u['role'];
            $_SESSION['can_manage_issue_config'] = (bool)$u['can_manage_issue_config'];
            $_SESSION['can_manage_devices'] = !empty($u['can_manage_devices']);
            $_SESSION['force_reset'] = !empty($u['force_password_reset']);
            if ((int)$u['is_active'] !== 1) {
                // user deactivated; force logout
                session_destroy();
                require_once __DIR__ . '/helpers.php';
                redirect("/modules/auth/login.php");
                exit;
            }
        }
    } catch (Exception $e) {
        // non-fatal; keep existing session values
    }
}

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }

    private function get2FARateLimitKey($userId) {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return '2fa_attempts_' . (int)$userId . '_' . md5($ip);
    }

    private function ensure2FAAttemptsTable() {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        $this->db->exec("CREATE TABLE IF NOT EXISTS user_2fa_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_2fa_user_attempted (user_id, attempted_at),
            INDEX idx_2fa_ip_attempted (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function get2FAAttemptStats($userId) {
        $key = $this->get2FARateLimitKey($userId);
        $window = 600;
        $maxAttempts = 5;
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        try {
            $this->ensure2FAAttemptsTable();
            $this->db->prepare("DELETE FROM user_2fa_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->execute();

            $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt, MIN(attempted_at) AS first_attempt FROM user_2fa_attempts WHERE user_id = ? AND ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
            $stmt->execute([(int)$userId, $ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $_SESSION[$key] = [
                'count' => (int)($row['cnt'] ?? 0),
                'window_start' => isset($row['first_attempt']) && $row['first_attempt'] ? strtotime((string)$row['first_attempt']) : time()
            ];

            return [
                'count' => (int)($row['cnt'] ?? 0),
                'max' => $maxAttempts,
                'window' => $window
            ];
        } catch (Exception $e) {
            $bucket = $_SESSION[$key] ?? ['count' => 0, 'window_start' => time()];
            if (!isset($bucket['window_start']) || (time() - (int)$bucket['window_start']) > $window) {
                $bucket = ['count' => 0, 'window_start' => time()];
            }
            $_SESSION[$key] = $bucket;
            return [
                'count' => (int)($bucket['count'] ?? 0),
                'max' => $maxAttempts,
                'window' => $window
            ];
        }
    }

    private function register2FAFailure($userId) {
        $key = $this->get2FARateLimitKey($userId);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        try {
            $this->ensure2FAAttemptsTable();
            $stmt = $this->db->prepare("INSERT INTO user_2fa_attempts (user_id, ip_address) VALUES (?, ?)");
            $stmt->execute([(int)$userId, $ip]);
        } catch (Exception $e) {
            $bucket = $_SESSION[$key] ?? ['count' => 0, 'window_start' => time()];
            if (!isset($bucket['window_start']) || (time() - (int)$bucket['window_start']) > 600) {
                $bucket = ['count' => 0, 'window_start' => time()];
            }
            $bucket['count'] = (int)($bucket['count'] ?? 0) + 1;
            $_SESSION[$key] = $bucket;
        }
    }

    private function clear2FAFailures($userId) {
        $key = $this->get2FARateLimitKey($userId);
        unset($_SESSION[$key]);

        try {
            $this->ensure2FAAttemptsTable();
            $stmt = $this->db->prepare("DELETE FROM user_2fa_attempts WHERE user_id = ? AND ip_address = ?");
            $stmt->execute([(int)$userId, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')]);
        } catch (Exception $e) {
            // Session fallback already cleared.
        }
    }
    
    public function login($username, $password) {
        // Validate input
        if (empty($username) || empty($password)) {
            return false;
        }
        
        // Sanitize username input
        $username = trim(strip_tags($username));

        // Rate limiting: max 5 failed attempts per IP per 15 minutes (DB-based, not session-based)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $usernameHash = md5(strtolower($username));
        $maxAttempts = 5;
        $lockoutTime = 900; // 15 minutes

        try {
            // Clean up old attempts first
            $this->db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->execute();

            // Fixed window: count attempts since the FIRST attempt in the window.
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt, MIN(attempted_at) as first_attempt
                FROM login_attempts
                WHERE username_hash = ?
                AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $stmt->execute([$usernameHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $userAttempts = (int)($row['cnt'] ?? 0);

            if ($userAttempts >= $maxAttempts) {
                // Tell the user exactly when the lockout expires (15 min from first attempt)
                return 'locked';
            }
        } catch (Exception $e) {
            error_log('Login rate-limit DB check failed: ' . $e->getMessage());
            // If table is missing, don't block everyone. 
            // Only block if we actually found a 'locked' state in the try block above.
            if (strpos($e->getMessage(), '1146') !== false) {
                // Table doesn't exist - don't block, but log it
            } else {
                return 'locked';
            }
        }
        
        $stmt = $this->db->prepare("
            SELECT id, username, email, password, full_name, role, is_active, force_password_reset, can_manage_issue_config, can_manage_devices,
                   two_factor_secret, two_factor_enabled
            FROM users 
            WHERE (username = ? OR email = ?) AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Clear DB rate limit on successful login (by username only)
            try {
                $stmt = $this->db->prepare("DELETE FROM login_attempts WHERE username_hash = ?");
                $stmt->execute([$usernameHash]);
            } catch (Exception $e) {}
            // Clear legacy session-based rate limit keys if present
            unset($_SESSION['login_attempts_' . md5($ip)]);
            unset($_SESSION['login_attempts_user_' . md5(strtolower($username))]);
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            // Check if 2FA is enabled
            if (!empty($user['two_factor_enabled'])) {
                if (!$this->isDeviceTrusted($user['id'])) {
                    $_SESSION['2fa_pending_user_id'] = $user['id'];
                    return '2fa_required';
                }
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['can_manage_issue_config'] = (bool)$user['can_manage_issue_config'];
            $_SESSION['can_manage_devices'] = !empty($user['can_manage_devices']);
            $_SESSION['force_reset'] = $user['force_password_reset'];
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            
            // Log login activity with device/ip/session info
            try {
                $details = [
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'session_id' => session_id(),
                    'device_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_sections' => $_SESSION['user_sections'] ?? []
                ];
                // attempt to parse basic device/browser info
                if (!empty($details['user_agent'])) {
                    $details['ua_parsed'] = get_browser_info($details['user_agent']);
                }

                // Geo lookup (best-effort)
                $geo = get_geo_info($details['device_ip'] ?? '');
                if (!empty($geo)) $details['geo'] = $geo;

                logActivity($this->db, $user['id'], 'login', 'auth', null, $details);

                // Persist session record including ip_location JSON
                $stmt = $this->db->prepare("INSERT INTO user_sessions (user_id, session_id, user_agent, ip_address, ip_location, active) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE last_activity = NOW(), active = 1, ip_location = VALUES(ip_location)");
                try {
                    $ipLoc = !empty($geo) ? json_encode($geo) : null;
                    $stmt->execute([$user['id'], session_id(), $details['user_agent'], $details['device_ip'], $ipLoc]);
                } catch (Exception $_) {}
            } catch (Exception $e) {
                // non-fatal
            }

            return true;
        }
        
        // Track failed attempt in DB — only if not already locked (prevents window extension)
        try {
            if ($userAttempts < $maxAttempts) {
                $stmt = $this->db->prepare("INSERT INTO login_attempts (ip_address, username_hash) VALUES (?, ?)");
                $stmt->execute([$ip, $usernameHash]);
            }

            $remaining = max(0, $maxAttempts - ($userAttempts + 1));
            if ($remaining === 0) {
                return 'locked';
            }
            return $remaining;
        } catch (Exception $e) {}
        return false;
    }
    
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            // Log logout activity with device/ip/session info
            try {
                $details = [
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'session_id' => session_id(),
                    'device_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_sections' => $_SESSION['user_sections'] ?? []
                ];
                        if (!empty($details['user_agent'])) {
                            $details['ua_parsed'] = get_browser_info($details['user_agent']);
                        }
                        // Geo lookup on logout too
                        $geo = get_geo_info($details['device_ip'] ?? '');
                        if (!empty($geo)) $details['geo'] = $geo;
                        logActivity($this->db, $_SESSION['user_id'], 'logout', 'auth', null, $details);
            } catch (Exception $e) {
                // non-fatal
            }

            // Mark session as logged out in user_sessions
            try {
                $sid = session_id();
                $userId = $_SESSION['user_id'];
                // Update current session
                $ust = $this->db->prepare("UPDATE user_sessions SET logout_at = NOW(), active = 0, last_activity = NOW(), logout_type = 'manual' WHERE session_id = ? AND user_id = ?");
                $ust->execute([$sid, $userId]);
                
                // Optional: Also logout all other sessions for this user (uncomment if you want single-device login)
                // $ust2 = $this->db->prepare("UPDATE user_sessions SET logout_at = NOW(), active = 0, logout_type = 'manual_all' WHERE user_id = ? AND active = 1");
                // $ust2->execute([$userId]);
            } catch (Exception $_) {}
        }

        session_unset();
        session_destroy();
        
        // Use a short-lived cookie for the logout flash message instead of URL parameter
        // This avoids exposing session state in the URL (VAPT: sensitive data in URL)
        setcookie('logout_msg', '1', [
            'expires'  => time() + 30,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        require_once __DIR__ . '/helpers.php';
        redirect("/modules/auth/login.php");
    }

    public function verify2FALogin($userId, $code) {
        if (empty($code)) return false;

        $rate = $this->get2FAAttemptStats($userId);
        if ($rate['count'] >= $rate['max']) {
            return 'locked';
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['two_factor_secret'])) return false;

        require_once __DIR__ . '/GoogleAuthenticator.php';
        $ga = new GoogleAuthenticator();
        
        if ($ga->verifyCode($user['two_factor_secret'], $code, 1)) {
            // Success! Complete the login
            unset($_SESSION['2fa_pending_user_id']);
            $this->clear2FAFailures($user['id']);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['can_manage_issue_config'] = (bool)$user['can_manage_issue_config'];
            $_SESSION['can_manage_devices'] = !empty($user['can_manage_devices']);
            $_SESSION['force_reset'] = $user['force_password_reset'];
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();

            // Log activity
            try {
                $details = [
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'session_id' => session_id(),
                    'device_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'login_type' => '2fa'
                ];
                logActivity($this->db, $user['id'], 'login_2fa', 'auth', null, $details);
                
                // Persist session
                $stmt = $this->db->prepare("INSERT INTO user_sessions (user_id, session_id, user_agent, ip_address, active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE last_activity = NOW(), active = 1");
                $stmt->execute([$user['id'], session_id(), $details['user_agent'], $details['device_ip']]);
            } catch (Exception $e) {}

            return true;
        }

        $this->register2FAFailure($user['id']);

        $rate = $this->get2FAAttemptStats($user['id']);
        if ($rate['count'] >= $rate['max']) {
            return 'locked';
        }

        return false;
    }

    public function trustDevice($userId) {
        if (empty($userId)) return false;
        
        try {
            $token = bin2hex(random_bytes(32));
            $hashedToken = hash('sha256', $token);
            $days = 30;
            $expiresTs = time() + ($days * 24 * 60 * 60);
            $expiresAt = date('Y-m-d H:i:s', $expiresTs);
            
            $stmt = $this->db->prepare("INSERT INTO user_2fa_trusted_devices (user_id, trust_token, expires_at) VALUES (?, ?, ?)");
            $result = $stmt->execute([$userId, $hashedToken, $expiresAt]);
            
            if ($result) {
                // Determine if connection is HTTPS
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? null) == 443;
                // Determine if connection is from localhost (for development)
                $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1']);

                // Set cookie for 30 days. Use basic params for maximum compatibility.
                setcookie('pms_2fa_trust', $token, [
                    'expires' => $expiresTs,
                    'path' => '/',
                    'secure' => ($isHttps || !$isLocalhost) ? true : false,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function isDeviceTrusted($userId) {
        $token = $_COOKIE['pms_2fa_trust'] ?? '';
        if (empty($token) || empty($userId)) return false;
        
        $hashedToken = hash('sha256', $token);
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM user_2fa_trusted_devices 
                WHERE user_id = ? AND trust_token = ? AND expires_at > NOW() 
                LIMIT 1
            ");
            $stmt->execute([$userId, $hashedToken]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function isLoggedIn() {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        // Absolute session timeout: 8 hours regardless of activity
        $absoluteTimeout = 8 * 60 * 60; // 8 hours
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $absoluteTimeout)) {
            session_unset();
            session_destroy();
            return false;
        }
        
        // Auto-logout after 30 minutes of inactivity
        $idleTimeout = 30 * 60; // 30 minutes
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
            try {
                $sid = session_id();
                $ust = $this->db->prepare("UPDATE user_sessions SET logout_at = NOW(), active = 0, last_activity = NOW(), logout_type = 'idle_4h' WHERE session_id = ? AND user_id = ?");
                $ust->execute([$sid, $_SESSION['user_id']]);
            } catch (Exception $_) {}
            try {
                $details = [
                    'reason' => 'idle_4h',
                    'session_id' => session_id(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'device_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ];
                logActivity($this->db, $_SESSION['user_id'], 'auto_logout', 'auth', null, $details);
            } catch (Exception $_) {}
            session_unset();
            session_destroy();
            return false;
        }        
        // Verify session is still active in user_sessions (if table exists)
        try {
            $sid = session_id();
            $stmt = $this->db->prepare("SELECT active FROM user_sessions WHERE session_id = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$sid, $_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                // Session row missing (e.g., session ID rotated). Recreate it.
                try {
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $ust = $this->db->prepare("INSERT INTO user_sessions (user_id, session_id, user_agent, ip_address, active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE last_activity = NOW(), active = 1");
                    $ust->execute([$_SESSION['user_id'], $sid, $ua, $ip]);
                } catch (Exception $_) {
                    // ignore
                }
            } else if (intval($row['active']) !== 1) {
                // session was invalidated server-side
                $this->logout();
                return false;
            }
        } catch (Exception $e) {
            // ignore DB errors and proceed with normal session-based login
        }

        // Update last activity time
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    public function checkRole($requiredRole) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $userRole = $_SESSION['role'] ?? '';
        
        // If array of roles provided
        if (is_array($requiredRole)) {
            return in_array($userRole, $requiredRole);
        }
        
        // Single role with hierarchy
        $roleHierarchy = [
            'admin' => 5,
            'project_lead' => 4,
            'qa' => 3,
            'at_tester' => 2,
            'ft_tester' => 1
        ];
        
        // Check if roles exist in hierarchy
        if (!isset($roleHierarchy[$userRole]) || !isset($roleHierarchy[$requiredRole])) {
            return false;
        }
        
        $userLevel = $roleHierarchy[$userRole];
        $requiredLevel = $roleHierarchy[$requiredRole];
        
        return $userLevel >= $requiredLevel;
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            require_once __DIR__ . '/helpers.php';
            $baseDir = getBaseDir();
            redirect($baseDir . "/modules/auth/login.php");
        }
    }

    public function requireRole($requiredRole) {
        if (!$this->isLoggedIn()) {
            $baseDir = getBaseDir();
            header("Location: " . $baseDir . "/modules/auth/login.php");
            exit;
        }
        
        if (!$this->checkRole($requiredRole)) {
            $_SESSION['error'] = "You don't have permission to access this page.";
            $baseDir = getBaseDir();
            
            // Redirect to role-specific dashboard instead of login page
            $userRole = $_SESSION['role'] ?? '';
            $dashboardMap = [
                'admin' => '/modules/admin/dashboard.php',
                'project_lead' => '/modules/project_lead/dashboard.php',
                'qa' => '/modules/qa/dashboard.php',
                'at_tester' => '/modules/at_tester/dashboard.php',
                'ft_tester' => '/modules/ft_tester/dashboard.php',
            ];
            
            $redirectTo = $dashboardMap[$userRole] ?? '/modules/auth/login.php';
            header("Location: " . $baseDir . $redirectTo);
            exit;
        }
    }
    
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    public function getUserRole() {
        return $_SESSION['role'] ?? null;
    }
}

// Helper functions for backward compatibility
function isLoggedIn() {
    // Use Auth class for proper DB-backed session validation
    static $authInstance = null;
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    if ($authInstance === null) {
        $authInstance = new Auth();
    }
    return $authInstance->isLoggedIn();
}

function requireLogin() {
    if (!isLoggedIn()) {
        require_once __DIR__ . '/helpers.php';
        $baseDir = getBaseDir();
        redirect($baseDir . "/modules/auth/login.php");
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', ['admin'])) {
        $_SESSION['error'] = "You don't have permission to access this page.";
        require_once __DIR__ . '/helpers.php';
        $baseDir = getBaseDir();
        redirect($baseDir . "/");
        exit;
    }
}

function requireDeviceManager() {
    requireLogin();
    $role = $_SESSION['role'] ?? '';
    $canManage = !empty($_SESSION['can_manage_devices']);
    if (!$canManage && isset($_SESSION['user_id'])) {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT can_manage_devices FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $val = $stmt->fetchColumn();
            $canManage = !empty($val);
            $_SESSION['can_manage_devices'] = $canManage;
        } catch (Exception $_) {
            // ignore
        }
    }
    if (!in_array($role, ['admin']) && !$canManage) {
        $_SESSION['error'] = "You don't have permission to access this page.";
        require_once __DIR__ . '/helpers.php';
        $baseDir = getBaseDir();
        redirect($baseDir . "/");
        exit;
    }
}

function requireRole($role) {
    requireLogin();
    if (is_array($role)) {
        if (!in_array($_SESSION['role'] ?? '', $role)) {
            $_SESSION['error'] = "You don't have permission to access this page.";
            require_once __DIR__ . '/helpers.php';
            $baseDir = getBaseDir();
            
            // Redirect to role-specific dashboard instead of home
            $userRole = $_SESSION['role'] ?? '';
            $dashboardMap = [
                'admin' => '/modules/admin/dashboard.php',
                'project_lead' => '/modules/project_lead/dashboard.php',
                'qa' => '/modules/qa/dashboard.php',
                'at_tester' => '/modules/at_tester/dashboard.php',
                'ft_tester' => '/modules/ft_tester/dashboard.php',
            ];
            
            $redirectTo = $dashboardMap[$userRole] ?? '/';
            redirect($baseDir . $redirectTo);
            exit;
        }
    } else {
        if (($_SESSION['role'] ?? '') !== $role) {
            $_SESSION['error'] = "You don't have permission to access this page.";
            require_once __DIR__ . '/helpers.php';
            $baseDir = getBaseDir();
            
            // Redirect to role-specific dashboard instead of home
            $userRole = $_SESSION['role'] ?? '';
            $dashboardMap = [
                'admin' => '/modules/admin/dashboard.php',
                'project_lead' => '/modules/project_lead/dashboard.php',
                'qa' => '/modules/qa/dashboard.php',
                'at_tester' => '/modules/at_tester/dashboard.php',
                'ft_tester' => '/modules/ft_tester/dashboard.php',
            ];
            
            $redirectTo = $dashboardMap[$userRole] ?? '/';
            redirect($baseDir . $redirectTo);
            exit;
        }
    }
}
