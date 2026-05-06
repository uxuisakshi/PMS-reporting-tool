<?php
// API to get unread chat count
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chat_helpers.php';
require_once __DIR__ . '/../config/database.php';
ob_end_clean();

header('Content-Type: application/json');
header('Cache-Control: private, max-age=5, stale-while-revalidate=10');

$auth = new Auth();
$auth->requireLogin();

$viewerRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$viewerRole = preg_replace('/[^a-z0-9]+/', '_', $viewerRole);
$viewerRole = trim($viewerRole, '_');

if ($viewerRole === 'client') {
    echo json_encode([
        'success' => true,
        'unread_count' => 0
    ]);
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$pageId = isset($_GET['page_id']) ? intval($_GET['page_id']) : null;

$cacheTtl = 8;
$cacheKey = '';
if (function_exists('apcu_fetch')) {
    $cacheKey = 'chat_unread:' . md5(json_encode([
        'uid' => (int)$userId,
        'pid' => (int)($projectId ?? 0),
        'pg' => (int)($pageId ?? 0),
    ]));
    $cached = apcu_fetch($cacheKey, $hit);
    if ($hit && is_string($cached)) {
        echo $cached;
        exit;
    }
}

try {
    $unreadCount = getUnreadChatCount($db, $userId, $projectId, $pageId);
    
    $response = json_encode([
        'success' => true,
        'unread_count' => $unreadCount
    ]);

    if ($cacheKey !== '' && function_exists('apcu_store') && is_string($response)) {
        apcu_store($cacheKey, $response, $cacheTtl);
    }

    echo $response;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
