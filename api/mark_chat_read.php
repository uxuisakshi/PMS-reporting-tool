<?php
// API to mark chat messages as read
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chat_helpers.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$viewerRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$viewerRole = preg_replace('/[^a-z0-9]+/', '_', $viewerRole);
$viewerRole = trim($viewerRole, '_');
if ($viewerRole === 'client') {
    echo json_encode([
        'success' => true,
        'message' => 'No chat access for this account'
    ]);
    exit;
}

// Only allow POST to prevent CSRF via GET-based attacks (img tags, link prefetch, etc.)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

enforceApiCsrf();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : null;
$pageId = isset($_POST['page_id']) ? intval($_POST['page_id']) : null;

try {
    $success = markChatMessagesAsRead($db, $userId, $projectId, $pageId);
    
    echo json_encode([
        'success' => $success,
        'message' => 'Messages marked as read'
    ]);
} catch (Exception $e) {
    error_log('mark_chat_read error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An internal error occurred'
    ]);
}
