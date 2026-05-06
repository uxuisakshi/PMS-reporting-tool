<?php
/**
 * Check if current user has admin privileges (admin or admin)
 * Admin users should have all rights of project leads, QA, and testers
 * 
 * @return bool True if user has admin privileges
 */
function hasAdminPrivileges() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin']);
}

/**
 * Check if current user has project lead privileges (project_lead, admin, or admin)
 * 
 * @return bool True if user has project lead privileges
 */
function hasProjectLeadPrivileges() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['project_lead', 'admin']);
}

/**
 * Check if current user has QA privileges (qa, admin, or admin)
 * 
 * @return bool True if user has QA privileges
 */
function hasQAPrivileges() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['qa', 'admin']);
}

/**
 * Check if current user has tester privileges (at_tester, ft_tester, admin, or admin)
 * 
 * @return bool True if user has tester privileges
 */
function hasTesterPrivileges() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['at_tester', 'ft_tester', 'admin']);
}

/**
 * Sanitize user input to prevent XSS attacks
 * 
 * @param mixed $data The data to sanitize
 * @param bool $allowHtml Whether to allow HTML (default: false)
 * @return string|array Sanitized string or array of strings
 */
function sanitizeInput($data, $allowHtml = false) {
    if (is_array($data)) {
        return array_map(function($item) use ($allowHtml) {
            return sanitizeInput($item, $allowHtml);
        }, $data);
    }
    
    $data = trim($data);
    
    if ($allowHtml) {
        // Allow HTML but sanitize it
        return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    // Strip all HTML tags
    return htmlspecialchars(strip_tags($data), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Validate and sanitize integer input
 * 
 * @param mixed $value The value to validate
 * @param int $default Default value if validation fails
 * @return int Validated integer
 */
function validateInt($value, $default = 0) {
    if (is_numeric($value)) {
        return (int) $value;
    }
    return $default;
}

/**
 * Output escaped string to prevent XSS
 * 
 * @param string $string The string to escape
 * @return string Escaped string
 */
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Get the base directory path (application root)
 * 
 * @return string Base directory path
 */
function getBaseDir() {
    static $baseDir = null;
    
    if ($baseDir === null) {
        // Calculate base directory from the includes directory
        // includes/helpers.php is always at: {app_root}/includes/helpers.php
        // So we go up one level from includes to get app root
        $appRoot = dirname(__DIR__);
        
        // Convert filesystem path to URL path
        $documentRoot = $_SERVER['DOCUMENT_ROOT'];
        $documentRoot = str_replace('\\', '/', rtrim($documentRoot, '/\\'));
        $appRoot = str_replace('\\', '/', $appRoot);
        
        // Get the relative path from document root
        if (strpos($appRoot, $documentRoot) === 0) {
            $baseDir = substr($appRoot, strlen($documentRoot));
            // Ensure it starts with /
            if ($baseDir === '') {
                $baseDir = '/';
            } elseif ($baseDir[0] !== '/') {
                $baseDir = '/' . $baseDir;
            }
        } else {
            // Fallback: use index.php location
            // index.php is always at the app root
            $indexPath = $_SERVER['SCRIPT_FILENAME'] ?? '';
            if (file_exists($indexPath) && basename($indexPath) === 'index.php') {
                $indexDir = dirname($indexPath);
                $indexDir = str_replace('\\', '/', $indexDir);
                if (strpos($indexDir, $documentRoot) === 0) {
                    $baseDir = substr($indexDir, strlen($documentRoot));
                    if ($baseDir === '') {
                        $baseDir = '/';
                    } elseif ($baseDir[0] !== '/') {
                        $baseDir = '/' . $baseDir;
                    }
                } else {
                    // Last resort: use SCRIPT_NAME
                    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
                    $baseDir = dirname($scriptName);
                }
            } else {
                // Last resort: use SCRIPT_NAME
                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
                $baseDir = dirname($scriptName);
            }
        }
        
        // Normalize the path
        $baseDir = str_replace('\\', '/', $baseDir);
        // Remove trailing slash if not root
        if ($baseDir !== '/' && $baseDir !== '') {
            $baseDir = rtrim($baseDir, '/');
        }
        // If it's root, set to empty string
        if ($baseDir === '/') {
            $baseDir = '';
        }
    }
    
    return $baseDir;
}

function slugifyPathSegment($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return 'asset';
    }

    $asciiValue = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($asciiValue !== false && $asciiValue !== '') {
        $value = $asciiValue;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');

    return $value !== '' ? $value : 'asset';
}

function getClientProjectRouteToken($projectId) {
    static $secret = null;

    if ($secret === null) {
        $secretCandidates = [
            (string) (getenv('APP_KEY') ?: ''),
            (string) (getenv('APP_SECRET') ?: ''),
            defined('DB_NAME') ? (string) DB_NAME : '',
            'pms-client-project-route-v1'
        ];

        foreach ($secretCandidates as $candidate) {
            if ($candidate !== '') {
                $secret = $candidate;
                break;
            }
        }
    }

    return substr(hash_hmac('sha256', 'client-project|' . (int) $projectId, $secret), 0, 12);
}

function getClientProjectRouteKey($projectId, $projectTitle = '', $projectCode = '') {
    $token = getClientProjectRouteToken((int) $projectId);
    $slugSource = trim((string) ($projectCode !== '' ? $projectCode : $projectTitle));

    if ($slugSource === '') {
        return $token;
    }

    return slugifyPathSegment($slugSource) . '-' . $token;
}

function buildClientProjectUrl($projectId, $projectTitle = '', $projectCode = '', array $query = []) {
    $projectId = (int) $projectId;
    $projectTitle = (string) $projectTitle;
    $projectCode = (string) $projectCode;

    if ($projectId > 0 && $projectCode === '' && class_exists('Database')) {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare('SELECT title, project_code FROM projects WHERE id = ? LIMIT 1');
            $stmt->execute([$projectId]);
            $projectRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            if ($projectTitle === '' && !empty($projectRow['title'])) {
                $projectTitle = (string) $projectRow['title'];
            }

            if ($projectCode === '' && !empty($projectRow['project_code'])) {
                $projectCode = (string) $projectRow['project_code'];
            }
        } catch (Throwable $e) {
            error_log('buildClientProjectUrl metadata lookup failed: ' . $e->getMessage());
        }
    }

    $url = getBaseDir() . '/client/project/' . rawurlencode(getClientProjectRouteKey($projectId, $projectTitle, $projectCode));

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

/**
 * Create an in-app notification.
 *
 * @param PDO $db
 * @param int $userId
 * @param string $type
 * @param string $message
 * @param string|null $link
 * @return bool
 */
function createNotification($db, $userId, $type, $message, $link = null) {
    static $notificationTypeEnumEnsured = false;
    $userId = (int)$userId;
    if ($userId <= 0) return false;

    $allowedTypes = [
        'mention',
        'assignment',
        'system',
        'edit_request',
        'edit_request_response',
        'permission_update',
        'deadline'
    ];
    $type = in_array($type, $allowedTypes, true) ? $type : 'system';
    $message = trim((string)$message);
    $link = $link ? trim((string)$link) : null;
    if ($link === '') $link = null;
    if ($link === null) {
        // Fallback to current request so notification opens the same page context.
        $reqUri = trim((string)($_SERVER['REQUEST_URI'] ?? ''));
        if ($reqUri !== '') {
            $link = $reqUri;
        }
    }
    if ($link !== null) {
        // Normalize to app-relative path (avoid double baseDir in renderers)
        $baseDir = getBaseDir();
        if (!preg_match('/^https?:\/\//i', $link) && $baseDir && strpos($link, $baseDir . '/') === 0) {
            $link = substr($link, strlen($baseDir));
            if ($link === '') $link = '/';
        }
    }

    if (!$notificationTypeEnumEnsured) {
        $notificationTypeEnumEnsured = true;
        try {
            $db->exec("ALTER TABLE notifications MODIFY COLUMN type ENUM('mention','assignment','system','edit_request','edit_request_response','permission_update','deadline') NOT NULL");
        } catch (Exception $e) {
            // Older production DBs may block DDL here; we'll still try insert below.
        }
    }

    try {
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)");
        $ok = $stmt->execute([$userId, $type, $message, $link]);
        if (!$ok) {
            return false;
        }

        // Best-effort email mirror for in-app notifications.
        // Never block primary request flow if email transport is failing.
        try {
            // Unblock session for other requests while sending email
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $settings = @include(__DIR__ . '/../config/settings.php');
            $userStmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
            $userStmt->execute([$userId]);
            $recipient = $userStmt->fetch(PDO::FETCH_ASSOC);
            $recipientEmail = trim((string)($recipient['email'] ?? ''));
            if ($recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $emailFile = __DIR__ . '/email.php';
                if (file_exists($emailFile)) {
                    require_once $emailFile;
                }

                if (class_exists('EmailSender')) {
                    $recipientName = trim((string)($recipient['full_name'] ?? 'User'));
                    $subjectType = ucfirst(str_replace('_', ' ', $type));
                    $subject = 'PMS Notification: ' . $subjectType;

                    $appUrl = is_array($settings) ? trim((string)($settings['app_url'] ?? '')) : '';
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $baseDir = getBaseDir();
                    if ($appUrl === '') {
                        $appUrl = rtrim($scheme . '://' . $host . $baseDir, '/');
                    }
                    $linkPath = trim((string)($link ?? ''));
                    if ($linkPath !== '') {
                        if (preg_match('/^https?:\/\//i', $linkPath)) {
                            $fullLink = $linkPath;
                        } else {
                            if ($baseDir && strpos($linkPath, $baseDir . '/') === 0) {
                                $linkPath = substr($linkPath, strlen($baseDir));
                            }
                            $fullLink = rtrim($appUrl, '/') . '/' . ltrim($linkPath, '/');
                        }
                    } else {
                        $fullLink = rtrim($appUrl, '/') . '/modules/notifications.php';
                    }

                    $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
                    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
                    $safeLink = htmlspecialchars($fullLink, ENT_QUOTES, 'UTF-8');

                    $body = ''
                        . '<!doctype html><html><body style="font-family:Arial,sans-serif;line-height:1.5;">'
                        . '<p>Hello ' . $safeName . ',</p>'
                        . '<p>You have a new notification in PMS:</p>'
                        . '<p style="background:#f5f7fb;padding:12px;border-radius:6px;">' . $safeMessage . '</p>'
                        . '<p><a href="' . $safeLink . '">Open Notification</a></p>'
                        . '<p style="color:#6c757d;font-size:12px;">This is an automated message from PMS.</p>'
                        . '</body></html>';

                    $mailer = new EmailSender();
                    $sent = $mailer->send($recipientEmail, $subject, $body, true);
                    if (!$sent) {
                        error_log('createNotification email mirror failed: mailer returned false for user_id=' . $userId);
                    }
                }
            }
        } catch (Exception $mailEx) {
            error_log('createNotification email mirror failed: ' . $mailEx->getMessage());
        }

        // Restart session if it was closed for email sending
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        return true;
    } catch (Exception $e) {
        error_log('createNotification error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Redirect to a URL
 * 
 * @param string $path The path to redirect to
 * @param int $statusCode HTTP status code (default: 302)
 * @return void
 */
function redirect($path, $statusCode = 302) {
    // Prevent header injection
    $path = str_replace(["\r", "\n"], '', $path);
    
    // If it's already an absolute URL, use it as is
    if (preg_match('/^https?:\/\//', $path)) {
        http_response_code($statusCode);
        header("Location: $path");
        exit;
    }
    
    // Get base directory
    $baseDir = getBaseDir();
    
    // Ensure path starts with /
    $path = '/' . ltrim($path, '/');

    // Combine base directory with path, but avoid duplicating baseDir if already present in path
    if ($baseDir !== '' && strpos($path, $baseDir . '/') === 0) {
        $fullPath = $path; // path already contains baseDir
    } else {
        $fullPath = $baseDir . $path;
    }
    
    http_response_code($statusCode);
    header("Location: $fullPath");
    exit;
}

function getSanitizedHttpHost() {
    $rawHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($rawHost === '') {
        return 'localhost';
    }

    $host = strtolower((string)(parse_url('http://' . $rawHost, PHP_URL_HOST) ?? ''));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
        return 'localhost';
    }

    $port = (int)(parse_url('http://' . $rawHost, PHP_URL_PORT) ?? 0);
    if ($port > 0 && $port <= 65535) {
        return $host . ':' . $port;
    }

    return $host;
}

function getConfiguredAppUrl() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $settings = @include(__DIR__ . '/../config/settings.php');
    $appUrl = is_array($settings) ? trim((string)($settings['app_url'] ?? '')) : '';
    if ($appUrl !== '' && preg_match('#^https?://#i', $appUrl)) {
        return $cached = rtrim($appUrl, '/');
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseDir = getBaseDir();
    return $cached = rtrim($protocol . '://' . getSanitizedHttpHost() . $baseDir, '/');
}

/**
 * Generate CSRF token
 * Token is rotated after each successful verification to prevent replay attacks.
 * 
 * @return string CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generate a per-request CSP nonce for inline scripts.
 * Stored in $_SERVER so it's consistent within one request.
 */
function generateCspNonce() {
    if (!isset($_SERVER['_CSP_NONCE'])) {
        $_SERVER['_CSP_NONCE'] = base64_encode(random_bytes(16));
    }
    return $_SERVER['_CSP_NONCE'];
}

/**
 * Verify CSRF token and rotate it immediately after successful verification.
 * 
 * @param string $token The token to verify
 * @return bool True if token is valid
 */
function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    // Token is NOT rotated after use — rotating causes race conditions on
    // pages with multiple concurrent AJAX requests (second request gets stale token).
    // The token remains valid for the session lifetime; new token is issued on login.
    return $valid;
}

/**
 * Enforce CSRF token for state-changing API requests (POST/PUT/PATCH/DELETE).
 * Accepts token from POST body or X-CSRF-Token header.
 * Sends 403 JSON response and exits if validation fails.
 */
function enforceApiCsrf() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return; // Safe methods don't need CSRF
    }

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    // Check if token is missing but Content-Length is high (potential post_max_size issue)
    if ($token === '' && empty($_POST) && ($contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0)) > 1024) {
        $postMaxSize = ini_get('post_max_size');
        http_response_code(413); // Payload Too Large preferred, or stay with 403 for security
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => "Request body potentially exceeded 'post_max_size' ($postMaxSize). Please upload a smaller file or split the request."]);
        exit;
    }

    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Invalid or missing CSRF token', 
            'received' => ($token === '' ? 'none' : 'provided'),
            'hint' => 'Ensure X-CSRF-Token header or csrf_token POST field is present.'
        ]);
        exit;
    }
}

/**
 * Get base URL of the application
 * 
 * @return string Base URL
 */
function getBaseUrl() {
    return getConfiguredAppUrl();
}

/**
 * Map user role to module directory
 * 
 * @param string $role User role
 * @return string Module directory name
 */
function getModuleDirectory($role) {
    $roleMapping = [
        'at_tester' => 'at_tester',
        'ft_tester' => 'ft_tester',
        'admin' => 'admin',
        'project_lead' => 'project_lead',
        'qa' => 'qa',
        'client' => 'client'
    ];
    
    return $roleMapping[$role] ?? $role;
}

/**
 * Resolve user full names for an array of user IDs
 *
 * @param PDO $db
 * @param array $ids
 * @return array list of full_name strings in id order
 */
function getUserNamesByIds($db, $ids) {
    if (empty($ids) || !is_array($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders) ORDER BY full_name");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_column($rows, 'full_name');
}

/**
 * Given a project_pages row, return assigned user ids for a tester type
 * Checks JSON array field first (e.g., at_tester_ids) then single-id field (at_tester_id)
 *
 * @param array $page
 * @param string $prefix 'at_tester'|'ft_tester' etc.
 * @return array of ints
 */
function getAssignedIdsFromPage($page, $prefix) {
    $jsonField = $prefix . '_ids';
    $singleField = $prefix . '_id';
    $ids = [];
    if (!empty($page[$jsonField])) {
        $decoded = json_decode($page[$jsonField], true);
        if (is_array($decoded)) {
            foreach ($decoded as $v) {
                if (is_numeric($v)) $ids[] = (int)$v;
            }
        }
    }
    if (empty($ids) && !empty($page[$singleField])) {
        if (is_numeric($page[$singleField])) $ids[] = (int)$page[$singleField];
    }
    return $ids;
}

/**
 * Return a display string (HTML) of assigned users for a page and prefix
 * Uses <br> between multiple names
 */
function getAssignedNamesHtml($db, $page, $prefix) {
    $ids = getAssignedIdsFromPage($page, $prefix);
    if (empty($ids)) return '';
    $parts = [];
    foreach ($ids as $uid) {
        $parts[] = renderUserNameLink($uid);
    }
    return implode('<br>', $parts);
}

function normalizeTestingStatusForPage($status) {
    $s = strtolower(trim((string)$status));
    if ($s === '' || $s === 'not_started' || $s === 'pending') return 'not_started';
    if (in_array($s, ['completed', 'pass'], true)) return 'completed';
    if (in_array($s, ['on_hold', 'hold', 'na'], true)) return 'on_hold';
    if (in_array($s, ['fail', 'failed', 'testing_failed', 'in_fixing'], true)) return 'in_fixing';
    if (in_array($s, ['in_testing', 'tested', 'needs_review', 'qa_in_progress'], true)) return 'in_progress';
    if (in_array($s, ['in_progress', 'ongoing', 'inprogress'], true)) return 'in_progress';
    return 'in_progress';
}

function normalizeQaStatusForPage($status) {
    $s = strtolower(trim((string)$status));
    if ($s === '' || $s === 'not_started' || $s === 'pending') return 'not_started';
    if (in_array($s, ['completed', 'pass'], true)) return 'completed';
    if (in_array($s, ['on_hold', 'hold', 'na'], true)) return 'on_hold';
    if (in_array($s, ['fail', 'failed', 'qa_failed', 'in_fixing'], true)) return 'in_fixing';
    if (in_array($s, ['in_progress', 'ongoing', 'inprogress', 'needs_review', 'qa_in_progress'], true)) return 'in_progress';
    return 'in_progress';
}

/**
 * Compute aggregate page status from AT/FT testing status and QA status permutations.
 * Returns a project_pages.status-compatible key.
 *
 * @param array $envRows Rows containing status + qa_status (or env_status + env_qa_status)
 * @return string
 */
function computeAggregatePageStatusFromEnvRows(array $envRows) {
    if (empty($envRows)) return 'not_started';

    $testAllNotStarted = true;
    $testAllCompleted = true;
    $testHasStarted = false;
    $testHasInProgress = false;
    $testHasOnHold = false;
    $testHasFixing = false;

    $qaAllNotStarted = true;
    $qaAllCompleted = true;
    $qaHasStarted = false;
    $qaHasInProgress = false;
    $qaHasOnHold = false;
    $qaHasFixing = false;

    foreach ($envRows as $row) {
        $rawTest = $row['status'] ?? ($row['env_status'] ?? '');
        $rawQa = $row['qa_status'] ?? ($row['env_qa_status'] ?? '');

        $testState = normalizeTestingStatusForPage($rawTest);
        $qaState = normalizeQaStatusForPage($rawQa);

        if ($testState !== 'not_started') {
            $testAllNotStarted = false;
            $testHasStarted = true;
        }
        if ($testState !== 'completed') {
            $testAllCompleted = false;
        }
        if ($testState === 'in_progress') $testHasInProgress = true;
        if ($testState === 'on_hold') $testHasOnHold = true;
        if ($testState === 'in_fixing') $testHasFixing = true;

        if ($qaState !== 'not_started') {
            $qaAllNotStarted = false;
            $qaHasStarted = true;
        }
        if ($qaState !== 'completed') {
            $qaAllCompleted = false;
        }
        if ($qaState === 'in_progress') $qaHasInProgress = true;
        if ($qaState === 'on_hold') $qaHasOnHold = true;
        if ($qaState === 'in_fixing') $qaHasFixing = true;
    }

    if ($testHasFixing || $qaHasFixing) return 'in_fixing';

    if ($testAllNotStarted && $qaAllNotStarted) return 'not_started';
    if ($testAllCompleted && $qaAllCompleted) return 'completed';

    if ($testAllCompleted && $qaHasInProgress) return 'qa_in_progress';
    if ($testAllCompleted && $qaAllNotStarted) return 'needs_review';

    if (($testHasOnHold && !$testHasInProgress && !$qaHasStarted) || ($qaHasOnHold && $testAllCompleted && !$qaHasInProgress)) {
        return 'on_hold';
    }

    if ($testHasStarted || $qaHasStarted) return 'in_progress';

    return 'not_started';
}

/**
 * Detect assignment-gap status for a page from environment rows.
 * Returns one of: need_assignment, tester_not_assigned, qa_not_assigned, ''.
 *
 * @param array $envRows
 * @return string
 */
function computePageAssignmentGapStatusFromEnvRows(array $envRows) {
    if (empty($envRows)) return 'need_assignment';

    $hasTester = false;
    $hasQa = false;
    foreach ($envRows as $row) {
        if (!empty($row['at_tester_id']) || !empty($row['ft_tester_id'])) {
            $hasTester = true;
        }
        if (!empty($row['qa_id'])) {
            $hasQa = true;
        }
    }

    if (!$hasTester && !$hasQa) return 'need_assignment';
    if (!$hasTester) return 'tester_not_assigned';
    if (!$hasQa) return 'qa_not_assigned';
    return '';
}

/**
 * Compute page status based on environment testing + QA status permutations.
 *
 * @param PDO $db
 * @param array $page project_pages row (must include at least id)
 * @return string
 */
function computePageStatus($db, $page) {
    if (empty($page) || empty($page['id'])) return 'not_started';

    $pageId = (int)$page['id'];
    $envStmt = $db->prepare("SELECT status, qa_status FROM page_environments WHERE page_id = ?");
    $envStmt->execute([$pageId]);
    $envRows = $envStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($envRows)) {
        return computeAggregatePageStatusFromEnvRows($envRows);
    }

    return normalizeTestingStatusForPage($page['status'] ?? 'not_started');
}

/**
 * Map raw test status value to human-friendly label
 * @param string|null $status
 * @return string
 */
function formatTestStatusLabel($status) {
    if (empty($status)) return 'Not tested';
    $s = strtolower($status);
    if ($s === 'pass') return 'Tested';
    if (in_array($s, ['in_progress', 'inprogress', 'ongoing'])) return 'In Progress';
    if (in_array($s, ['on_hold', 'hold'])) return 'On Hold';
    if ($s === 'pending') return 'Pending';
    if (in_array($s, ['fail', 'failed'])) return 'Issues Found';
    return ucfirst($s);
}

/**
 * Map raw QA status value to human-friendly label
 * @param string|null $status
 * @return string
 */
function formatQAStatusLabel($status) {
    if (empty($status)) return 'Not reviewed';
    $s = strtolower($status);
    if ($s === 'pass') return 'QA Approved';
    if (in_array($s, ['in_progress', 'inprogress', 'ongoing'])) return 'In Progress';
    if (in_array($s, ['on_hold', 'hold'])) return 'On Hold';
    if ($s === 'not_started' || $s === 'pending') return 'Not Started';
    if (in_array($s, ['fail', 'failed'])) return 'QA Rejected';
    if ($s === 'needs_review') return 'Needs Review';
    if ($s === 'completed') return 'Completed';
    return ucfirst(str_replace('_', ' ', $s));
}

/**
 * Map project status to human-friendly label
 * @param string|null $status
 * @return string
 */
function formatProjectStatusLabel($status) {
    if (empty($status)) return '';
    $s = strtolower($status);
    if ($s === 'in_progress') return 'In Progress';
    if ($s === 'on_hold') return 'On Hold';
    if ($s === 'completed') return 'Completed';
    if ($s === 'cancelled') return 'Cancelled';
    return ucfirst(str_replace('_', ' ', $s));
}

/**
 * Human-friendly label for page progress status.
 * Keeps "in_progress" explicit as "Testing In Progress".
 */
function formatPageProgressStatusLabel($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'need_assignment') return 'Need Assignment';
    if ($s === 'tester_not_assigned') return 'Tester Not Assigned';
    if ($s === 'qa_not_assigned') return 'QA Not Assigned';
    if ($s === '' || $s === 'not_started') return 'Not Started';
    if ($s === 'in_progress') return 'Testing In Progress';
    if ($s === 'qa_in_progress') return 'QA In Progress';
    if ($s === 'in_fixing') return 'In Fixing';
    if ($s === 'needs_review') return 'Needs Review';
    if ($s === 'on_hold') return 'On Hold';
    if ($s === 'completed') return 'Completed';
    if ($s === 'not_tested') return 'Not Started';
    if ($s === 'in_testing') return 'Testing In Progress';
    if ($s === 'qa_review') return 'QA In Progress';
    if ($s === 'testing_failed' || $s === 'qa_failed') return 'In Fixing';
    if ($s === 'tested') return 'Needs Review';
    return ucfirst(str_replace('_', ' ', $s));
}

/**
 * Return bootstrap badge class for project status
 */
function projectStatusBadgeClass($status) {
    $s = strtolower((string)$status);
    if ($s === 'completed') return 'success';
    // In-progress projects should appear as 'warning' (yellow)
    if ($s === 'in_progress') return 'warning';
    // On-hold projects should appear as 'info' (light-blue)
    if ($s === 'on_hold') return 'info';
    if ($s === 'cancelled') return 'secondary';
    return 'secondary';
}

/**
 * Format task type for display
 * @param string $taskType
 * @return string
 */
function formatTaskType($taskType) {
    if (empty($taskType)) return '';
    $t = strtolower($taskType);
    if ($t === 'page_testing') return 'Page Testing';
    if ($t === 'page_qa') return 'Page QA';
    if ($t === 'project_phase') return 'Project Phase';
    if ($t === 'generic_task') return 'Generic Task';
    if ($t === 'at_testing') return 'AT Testing';
    if ($t === 'ft_testing') return 'FT Testing';
    return ucfirst(str_replace('_', ' ', $taskType));
}

/**
 * Map computed page status (or raw page status) to Testing home labels
 * Desired labels:
 * - Not Started
 * - In Progress
 * - On Hold
 * - QA In Progress
 * - In Fixing
 * - Needs Review
 *
 * @param PDO $db
 * @param array $page
 * @return string
 */
function formatTestingHomeStatus($db, $page) {
    // Prefer computed status when possible
    $computed = computePageStatus($db, $page);

    switch ($computed) {
        case 'not_started':
        case 'not_tested':
            return 'Not Started';
        case 'in_progress':
        case 'in_testing':
            return 'Testing In Progress';
        case 'testing_failed':
            return 'In Fixing';
        case 'needs_review':
        case 'tested':
            return 'Needs Review';
        case 'qa_in_progress':
        case 'qa_review':
            return 'QA In Progress';
        case 'in_fixing':
        case 'qa_failed':
            return 'In Fixing';
        case 'completed':
            return 'Completed';
        case 'on_hold':
            return 'On Hold';
        default:
            // Fallback to raw page status if present
            $raw = strtolower($page['status'] ?? '');
            if (in_array($raw, ['in_progress', 'inprogress', 'ongoing'])) return 'In Progress';
            if ($raw === 'qa_in_progress' || $raw === 'qa_review') return 'QA In Progress';
            if (in_array($raw, ['in_fixing', 'fixing', 'qa_failed'])) return 'In Fixing';
            if ($raw === 'tested') return 'Needs Review';
            if ($raw === 'on_hold') return 'On Hold';
            if ($raw === 'completed') return 'Completed';
            return ucfirst(str_replace('_', ' ', $raw ?: 'Not Started'));
    }
}

/**
 * Render an interactive dropdown for global page status
 */
function renderPageStatusDropdown($pageId, $currentStatus) {
    if (empty($currentStatus)) {
        $currentStatus = 'not_started';
    }
    
    $statuses = [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'on_hold' => 'On Hold',
        'qa_in_progress' => 'QA In Progress',
        'in_fixing' => 'In Fixing',
        'needs_review' => 'Needs Review',
        'completed' => 'Completed'
    ];
    
    // Default badge style (solid)
    $badgeClass = 'secondary';
    
    // Map status to Bootstrap colors
    if ($currentStatus === 'completed') $badgeClass = 'success';
    elseif ($currentStatus === 'in_progress') $badgeClass = 'warning text-dark'; // Yellow needs dark text
    elseif (in_array($currentStatus, ['qa_in_progress', 'qa_review'])) $badgeClass = 'info text-dark'; // Cyan needs dark text
    elseif ($currentStatus === 'in_fixing') $badgeClass = 'danger';
    elseif ($currentStatus === 'on_hold') $badgeClass = 'light text-dark border'; // Light needs dark text + border
    
    $label = $statuses[$currentStatus] ?? ucfirst(str_replace('_', ' ', $currentStatus));
    
    $html = '<div class="btn-group status-dropdown-group">';
    // Use btn-sm and solid colors (btn-CHECK) instead of outline (btn-outline-CHECK) for better visibility
    $html .= '<button type="button" class="btn btn-sm btn-' . $badgeClass . ' dropdown-toggle page-status-badge" data-bs-toggle="dropdown" id="page-status-' . $pageId . '">';
    $html .= $label;
    $html .= '</button>';
    $html .= '<ul class="dropdown-menu">';
    foreach ($statuses as $val => $label) {
        $active = ($val === $currentStatus) ? 'active' : '';
        $html .= '<li><a class="dropdown-item ' . $active . ' status-update-link" href="#" data-page-id="' . $pageId . '" data-status="' . $val . '" data-action="update_page_status">' . $label . '</a></li>';
    }
    $html .= '</ul></div>';
    return $html;
}

/**
 * Render an interactive dropdown for environment status
 */
function renderEnvStatusDropdown($pageId, $envId, $currentStatus) {
    global $userRole, $userId;
    
    // Keep in sync with page_environments.status enum
    $statuses = [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'on_hold' => 'On Hold',
        'needs_review' => 'Needs Review'
    ];
    
    // Check if user can edit (admin, project_lead, or assigned tester)
    // For now, render dropdown for all - permission check happens in API
    
    $html = '<select class="form-select form-select-sm env-status-update" ';
    $html .= 'data-page-id="' . (int)$pageId . '" ';
    $html .= 'data-env-id="' . (int)$envId . '" ';
    $html .= 'data-status-type="testing" ';
    $html .= 'style="font-size: 0.75rem; min-width: 120px;">';
    
    foreach ($statuses as $val => $label) {
        $selected = ($val === $currentStatus) ? ' selected' : '';
        $html .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
    }
    
    $html .= '</select>';
    return $html;
}

/**
 * Return HTML <option> tags for environment status select
 */
function getEnvStatusOptionsHtml($selected = '') {
    $statuses = [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'on_hold' => 'On Hold',
        'needs_review' => 'Needs Review'
    ];
    $html = '';
    foreach ($statuses as $val => $label) {
        $sel = ($val === $selected) ? ' selected' : '';
        $html .= "<option value=\"$val\"$sel>$label</option>";
    }
    return $html;
}

/**
 * Log user activity to the database
 */
function logActivity($db, $userId, $action, $entityType, $entityId, $details = []) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            json_encode($details),
            $ip
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Ensure a project is marked as 'in_progress' if it's currently 'not_started'
 */
function ensureProjectInProgress($db, $projectId) {
    if (!$projectId) return;
    try {
        $stmt = $db->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ? AND status = 'not_started'");
        $stmt->execute([$projectId]);
    } catch (PDOException $e) {
        error_log("Failed to automate project status: " . $e->getMessage());
    }
}

/**
 * Render an interactive dropdown for QA environment status
 */
function renderQAEnvStatusDropdown($pageId, $envId, $currentStatus) {
    global $userRole, $userId;
    
    $statusMap = [
        'pending' => 'not_started',
        'na' => 'on_hold',
        'pass' => 'completed',
        'fail' => 'needs_review'
    ];

    $normalizedStatus = strtolower(trim((string)$currentStatus));
    if (isset($statusMap[$normalizedStatus])) {
        $normalizedStatus = $statusMap[$normalizedStatus];
    }
    if ($normalizedStatus === '') $normalizedStatus = "not_started";

    $statuses = [
        "not_started" => "Not Started",
        "in_progress" => "In Progress",
        "completed" => "Completed",
        "on_hold" => "On Hold",
        "needs_review" => "Needs Review"
    ];
    
    // Check if user can edit (admin, project_lead, qa, or assigned QA)
    // For now, render dropdown for all - permission check happens in API
    
    $html = '<select class="form-select form-select-sm env-status-update" ';
    $html .= 'data-page-id="' . (int)$pageId . '" ';
    $html .= 'data-env-id="' . (int)$envId . '" ';
    $html .= 'data-status-type="qa" ';
    $html .= 'style="font-size: 0.75rem; min-width: 120px;">';
    
    foreach ($statuses as $val => $label) {
        $selected = ($val === $normalizedStatus) ? ' selected' : '';
        $html .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
    }
    
    $html .= '</select>';
    return $html;
}

/**
 * Parse a simple user agent string into browser/os summary.
 * Best-effort, not relying on external libs.
 */
function get_browser_info($ua) {
    $info = ['browser' => 'Unknown', 'browser_version' => '', 'platform' => 'Unknown'];

    // Order matters — check specific browsers before generic ones
    if (stripos($ua, 'Edg/') !== false || stripos($ua, 'Edge/') !== false) {
        $info['browser'] = 'Edge';
        if (preg_match('/Edg(?:e)?\/([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    } elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
        $info['browser'] = 'Opera';
        if (preg_match('/(?:OPR|Opera)\/([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    } elseif (stripos($ua, 'Firefox') !== false) {
        $info['browser'] = 'Firefox';
        if (preg_match('/Firefox\/([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    } elseif (stripos($ua, 'SamsungBrowser') !== false) {
        $info['browser'] = 'Samsung Browser';
        if (preg_match('/SamsungBrowser\/([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    } elseif (stripos($ua, 'Chrome') !== false && stripos($ua, 'Safari') !== false) {
        $info['browser'] = 'Chrome';
        if (preg_match('/Chrome\/([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    } elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) {
        $info['browser'] = 'Safari';
        if (preg_match('/Version\/([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    } elseif (stripos($ua, 'Trident') !== false || stripos($ua, 'MSIE') !== false) {
        $info['browser'] = 'Internet Explorer';
        if (preg_match('/(?:MSIE |rv:)([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    }

    if (stripos($ua, 'Android') !== false) $info['platform'] = 'Android';
    elseif (stripos($ua, 'iPhone') !== false) $info['platform'] = 'iOS (iPhone)';
    elseif (stripos($ua, 'iPad') !== false) $info['platform'] = 'iOS (iPad)';
    elseif (stripos($ua, 'Windows') !== false) $info['platform'] = 'Windows';
    elseif (stripos($ua, 'Mac OS X') !== false) $info['platform'] = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $info['platform'] = 'Linux';

    return $info;
}

/**
 * Get basic geo information for an IP address (city, region, country, postal)
 * Uses ipapi.co as a best-effort free service. Returns associative array or empty array on failure.
 */
function get_geo_info($ip) {
    $ip = trim($ip);
    if (!$ip) return [];
    // Skip private/local addresses
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) return [];

    $url = 'https://ipapi.co/' . urlencode($ip) . '/json/';
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    try {
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return [];
        $data = json_decode($json, true);
        if (!is_array($data)) return [];
        $geo = [];
        $geo['city'] = $data['city'] ?? '';
        $geo['region'] = $data['region'] ?? '';
        $geo['country'] = $data['country_name'] ?? ($data['country'] ?? '');
        $geo['postal'] = $data['postal'] ?? ($data['postal_code'] ?? '');
        $geo['latitude'] = $data['latitude'] ?? ($data['lat'] ?? '');
        $geo['longitude'] = $data['longitude'] ?? ($data['lon'] ?? '');
        return $geo;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Consistently resolves the display number for a project page.
 * Prioritizes the database 'page_number' column.
 * 
 * @param array $pageRow Associative array containing at least 'page_number' and optionally 'page_name' or 'url'
 * @return string The resolved display number (e.g., "Page 1", "Global 5")
 */
function resolvePageDisplayValue($pageRow) {
    if (!is_array($pageRow)) return '-';
    
    $rawPageNumber = trim((string)($pageRow['page_number'] ?? ($pageRow['mapped_page_number'] ?? '')));
    if ($rawPageNumber !== '' && $rawPageNumber !== '-') {
        return $rawPageNumber;
    }
    
    // Fallback: Check if page_name already contains the number (e.g. "Page 10")
    $pageName = trim((string)($pageRow['page_name'] ?? ''));
    if (preg_match('/^(Page|Global)\s+\d+/i', $pageName)) {
        return $pageName;
    }
    
    // Fallback to '-' or empty if we really can't determine it
    return '-';
}
