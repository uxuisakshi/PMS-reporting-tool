<?php
/**
 * Force Logout Session API
 * Allows admins to forcefully terminate a user session
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$db = Database::getInstance();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['session_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session ID is required']);
    exit;
}

// CSRF protection
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token']);
    exit;
}

$sessionId = trim($input['session_id']);

try {
    // Check if session exists and is active
    $stmt = $db->prepare("SELECT user_id, active FROM user_sessions WHERE session_id = ? LIMIT 1");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit;
    }
    
    if (!$session['active']) {
        echo json_encode(['success' => false, 'error' => 'Session is already logged out']);
        exit;
    }
    
    // Force logout the session
    $stmt = $db->prepare("UPDATE user_sessions SET logout_at = NOW(), active = 0, last_activity = NOW(), logout_type = 'forced_by_admin' WHERE session_id = ?");
    $result = $stmt->execute([$sessionId]);
    
    if ($result && $stmt->rowCount() > 0) {
        // Log the admin action
        try {
            $details = [
                'session_id' => $sessionId,
                'target_user_id' => $session['user_id'],
                'admin_user_id' => $_SESSION['user_id'],
                'admin_name' => $_SESSION['full_name'] ?? 'Admin',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
            ];
            logActivity($db, $_SESSION['user_id'], 'force_logout', 'user_session', $session['user_id'], $details);
        } catch (Exception $e) {
            // Non-fatal
        }
        
        $resultStmt = $db->prepare("SELECT logout_at, logout_type, active FROM user_sessions WHERE session_id = ? LIMIT 1");
        $resultStmt->execute([$sessionId]);
        $updatedSession = $resultStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'success' => true,
            'message' => 'Session terminated successfully',
            'logout_at' => $updatedSession['logout_at'] ?? null,
            'logout_type' => $updatedSession['logout_type'] ?? 'forced_by_admin',
            'active' => isset($updatedSession['active']) ? (int)$updatedSession['active'] : 0
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to terminate session']);
    }
    
} catch (PDOException $e) {
    error_log('Force logout error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
