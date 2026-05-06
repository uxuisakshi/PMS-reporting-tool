<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Please login to access this resource'], JSON_UNESCAPED_UNICODE);
    exit;
}

$viewerRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$viewerRole = preg_replace('/[^a-z0-9]+/', '_', $viewerRole);
$viewerRole = trim($viewerRole, '_');
if ($viewerRole === 'client') {
    jsonError('Project chat is not available for client accounts.', 403);
}

// CSRF protection for state-changing requests
enforceApiCsrf();

// Set JSON response headers
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Handle errors consistently
function jsonError($message, $statusCode = 400, $code = null) {
    $response = ['error' => $message];
    if ($code !== null) {
        $response['code'] = $code;
    }
    jsonResponse($response, $statusCode);
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetChat();
        break;
    case 'POST':
        handlePostChat();
        break;
    default:
        jsonError('Method not allowed', 405);
}

function handleGetChat() {
    global $db;
    
    $projectId = validateInt($_GET['project_id'] ?? 0) ?: 0;
    $pageId = validateInt($_GET['page_id'] ?? 0) ?: 0;
    $since = !empty($_GET['since']) ? sanitizeInput($_GET['since']) : null;
    $limit = validateInt($_GET['limit'] ?? 50, 1, 100) ?: 50;
    
    $where = [];
    $params = [];
    
    if ($projectId) {
        $where[] = 'cm.project_id = ?';
        $params[] = $projectId;
    }
    
    if ($pageId) {
        $where[] = 'cm.page_id = ?';
        $params[] = $pageId;
    }
    
    if ($since) {
        $where[] = 'cm.created_at > ?';
        $params[] = $since;
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "
        SELECT 
            cm.*,
            u.username,
            u.full_name,
            u.role,
            p.title as project_title,
            pp.page_name
        FROM chat_messages cm
        JOIN users u ON cm.user_id = u.id
        LEFT JOIN projects p ON cm.project_id = p.id
        LEFT JOIN project_pages pp ON cm.page_id = pp.id
        $whereClause
        ORDER BY cm.created_at DESC
        LIMIT ?
    ";
    
    $params[] = $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();
    
    // Format response
    $formatted = [];
    foreach ($messages as $msg) {
        $formatted[] = [
            'id' => $msg['id'],
            'message' => $msg['message'],
            'user' => [
                'id' => $msg['user_id'],
                'name' => $msg['full_name'],
                'username' => $msg['username'],
                'role' => $msg['role']
            ],
            'project' => $msg['project_title'],
            'page' => $msg['page_name'],
            'mentions' => json_decode($msg['mentions'] ?? '[]', true),
            'created_at' => $msg['created_at'],
            'timestamp' => strtotime($msg['created_at'])
        ];
    }
    
    jsonResponse([
        'messages' => array_reverse($formatted), // Return in chronological order
        'count' => count($messages),
        'timestamp' => time()
    ]);
}

function handlePostChat() {
    global $db;
    
    // Rate limiting: max 30 messages per minute per user
    $userId = $_SESSION['user_id'];
    $rateLimitKey = 'chat_rate_' . (int)$userId;
    if (!isset($_SESSION[$rateLimitKey])) {
        $_SESSION[$rateLimitKey] = ['count' => 0, 'window_start' => time()];
    }
    $rateData = &$_SESSION[$rateLimitKey];
    if (time() - $rateData['window_start'] > 60) {
        $rateData = ['count' => 0, 'window_start' => time()];
    }
    $rateData['count']++;
    if ($rateData['count'] > 30) {
        jsonError('Too many messages. Please slow down.', 429);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON input', 400);
        return;
    }
    
    if (!isset($input['message']) || empty(trim($input['message']))) {
        jsonError('Message is required', 400);
        return;
    }
    
    $projectId = !empty($input['project_id']) ? validateInt($input['project_id']) : null;
    $pageId = !empty($input['page_id']) ? validateInt($input['page_id']) : null;
    $message = sanitizeInput($input['message']);
    
    // Limit message length
    if (strlen($message) > 5000) {
        jsonError('Message is too long (max 5000 characters)', 400);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Parse mentions
    $mentions = [];
    preg_match_all('/@([A-Za-z0-9._-]+)/', $message, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $username) {
            $user = $db->prepare("SELECT id FROM users WHERE username = ?");
            $user->execute([$username]);
            if ($user = $user->fetch()) {
                $mentions[] = $user['id'];
            }
        }
    }
    
    // Validate permissions
    if ($projectId) {
        $check = $db->prepare("
            SELECT p.id FROM projects p
            LEFT JOIN user_assignments ua ON p.id = ua.project_id
            WHERE p.id = ? AND (
                p.project_lead_id = ? OR
                ua.user_id = ? OR
                ? IN ('admin')
            )
            LIMIT 1
        ");
        $check->execute([$projectId, $userId, $userId, $_SESSION['role']]);
        
        if (!$check->fetch()) {
            jsonError('No access to this project', 403);
            return;
        }
    }
    
    if ($pageId) {
        $check = $db->prepare("
            SELECT pp.id FROM project_pages pp
            JOIN projects p ON pp.project_id = p.id
            LEFT JOIN user_assignments ua ON p.id = ua.project_id
            WHERE pp.id = ? AND (
                pp.at_tester_id = ? OR
                pp.ft_tester_id = ? OR
                pp.qa_id = ? OR
                p.project_lead_id = ? OR
                ua.user_id = ? OR
                ? IN ('admin')
            )
            LIMIT 1
        ");
        $check->execute([$pageId, $userId, $userId, $userId, $userId, $userId, $_SESSION['role']]);
        
        if (!$check->fetch()) {
            jsonError('No access to this page', 403);
            return;
        }
    }
    
    // Insert message
    $stmt = $db->prepare("
        INSERT INTO chat_messages (project_id, page_id, user_id, message, mentions)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    try {
        $stmt->execute([
            $projectId ?: null,
            $pageId ?: null,
            $userId,
            $message,
            json_encode($mentions)
        ]);
        
        $messageId = $db->lastInsertId();
        
        // Get the inserted message
        $msgStmt = $db->prepare("
            SELECT 
                cm.*,
                u.username,
                u.full_name,
                u.role,
                p.title as project_title,
                pp.page_name
            FROM chat_messages cm
            JOIN users u ON cm.user_id = u.id
            LEFT JOIN projects p ON cm.project_id = p.id
            LEFT JOIN project_pages pp ON cm.page_id = pp.id
            WHERE cm.id = ?
        ");
        $msgStmt->execute([$messageId]);
        $msg = $msgStmt->fetch();
        
        if (!$msg) {
            jsonError('Failed to retrieve message', 500);
            return;
        }
        
        // Format response
        $response = [
            'id' => $msg['id'],
            'message' => $msg['message'],
            'user' => [
                'id' => $msg['user_id'],
                'name' => $msg['full_name'],
                'username' => $msg['username'],
                'role' => $msg['role']
            ],
            'project' => $msg['project_title'],
            'page' => $msg['page_name'],
            'mentions' => json_decode($msg['mentions'] ?? '[]', true),
            'created_at' => $msg['created_at'],
            'timestamp' => strtotime($msg['created_at'])
        ];
        
        // Log activity (don't fail if logging fails)
        try {
            $db->prepare("
                INSERT INTO activity_log (user_id, action, entity_type, entity_id, details)
                VALUES (?, 'chat_message', 'chat', ?, ?)
            ")->execute([
                $userId,
                $messageId,
                json_encode(['project_id' => $projectId, 'page_id' => $pageId])
            ]);
        } catch (PDOException $e) {
            error_log("Failed to log chat activity: " . $e->getMessage());
        }
        
        jsonResponse(['success' => true, 'message' => $response], 201);
        
    } catch (PDOException $e) {
        error_log("Chat message error: " . $e->getMessage());
        jsonError('Failed to send message', 500);
    } catch (Exception $e) {
        error_log("Chat message error: " . $e->getMessage());
        jsonError('An error occurred while sending message', 500);
    }
}
