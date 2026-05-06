<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Please login to access this resource']);
    exit;
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
        handleGetTasks();
        break;
    case 'POST':
        handlePostTask();
        break;
    case 'PUT':
        handlePutTask();
        break;
    case 'DELETE':
        handleDeleteTask();
        break;
    default:
        jsonError('Method not allowed', 405);
}

function handleGetTasks() {
    global $db;
    
    try {
        $userId = $_SESSION['user_id'];
        $userRole = $_SESSION['role'];
        $projectId = validateInt($_GET['project_id'] ?? 0) ?: 0;
        $pageId = validateInt($_GET['page_id'] ?? 0) ?: 0;
        
        if ($pageId) {
            // Get specific page details
            $stmt = $db->prepare("
                SELECT pp.*, p.title as project_title, p.priority,
                       at_user.full_name as at_tester_name,
                       ft_user.full_name as ft_tester_name,
                       qa_user.full_name as qa_name
                FROM project_pages pp
                JOIN projects p ON pp.project_id = p.id
                LEFT JOIN users at_user ON pp.at_tester_id = at_user.id
                LEFT JOIN users ft_user ON pp.ft_tester_id = ft_user.id
                LEFT JOIN users qa_user ON pp.qa_id = qa_user.id
                WHERE pp.id = ?
            ");
            $stmt->execute([$pageId]);
            $page = $stmt->fetch();
            
            if (!$page) {
                jsonResponse(['error' => 'Page not found'], 404);
                return;
            }

            // IDOR prevention: verify user has access to this page's project
            if (!hasProjectAccess($db, $userId, (int)$page['project_id'])) {
                jsonError('Permission denied', 403);
            }
            
            // Get testing environments
            $envStmt = $db->prepare("
                SELECT te.* FROM testing_environments te
                JOIN page_environments pe ON te.id = pe.environment_id
                WHERE pe.page_id = ?
            ");
            $envStmt->execute([$pageId]);
            $environments = $envStmt->fetchAll();
            
            $page['environments'] = $environments;
            
            jsonResponse($page);
            
        } elseif ($projectId) {
            // Get all pages for a project — verify access first (IDOR prevention)
            if (!hasProjectAccess($db, $userId, $projectId)) {
                jsonError('Permission denied', 403);
            }
            
            $stmt = $db->prepare("
                SELECT pp.*, 
                       at_user.full_name as at_tester_name,
                       ft_user.full_name as ft_tester_name,
                       qa_user.full_name as qa_name
                FROM project_pages pp
                LEFT JOIN users at_user ON pp.at_tester_id = at_user.id
                LEFT JOIN users ft_user ON pp.ft_tester_id = ft_user.id
                LEFT JOIN users qa_user ON pp.qa_id = qa_user.id
                WHERE pp.project_id = ?
                ORDER BY pp.page_number, pp.page_name
            ");
            $stmt->execute([$projectId]);
            $pages = $stmt->fetchAll();
            
            jsonResponse($pages);
            
        } else {
            // Get user's tasks based on role
            $sql = "";
            $params = [];
            
            if ($userRole === 'project_lead') {
                $sql = "
                    SELECT pp.*, p.title as project_title, p.priority
                    FROM project_pages pp
                    JOIN projects p ON pp.project_id = p.id
                    WHERE p.project_lead_id = ?
                    AND p.status NOT IN ('completed', 'cancelled')
                    ORDER BY p.priority, pp.created_at
                ";
                $params = [$userId];
            } elseif ($userRole === 'qa') {
                $sql = "
                    SELECT pp.*, p.title as project_title, p.priority
                    FROM project_pages pp
                    JOIN projects p ON pp.project_id = p.id
                    WHERE pp.qa_id = ?
                    AND p.status NOT IN ('completed', 'cancelled')
                    ORDER BY p.priority, pp.created_at
                ";
                $params = [$userId];
            } elseif (in_array($userRole, ['at_tester', 'ft_tester'])) {
                $field = $userRole === 'at_tester' ? 'at_tester_id' : 'ft_tester_id';
                $sql = "
                    SELECT pp.*, p.title as project_title, p.priority
                    FROM project_pages pp
                    JOIN projects p ON pp.project_id = p.id
                    WHERE pp.$field = ?
                    AND p.status NOT IN ('completed', 'cancelled')
                    ORDER BY p.priority, pp.created_at
                ";
                $params = [$userId];
            } else {
                // Admin - get all active pages
                $sql = "
                    SELECT pp.*, p.title as project_title, p.priority
                    FROM project_pages pp
                    JOIN projects p ON pp.project_id = p.id
                    WHERE p.status NOT IN ('completed', 'cancelled')
                    ORDER BY p.priority, pp.created_at
                    LIMIT 100
                ";
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $tasks = $stmt->fetchAll();
            
            jsonResponse($tasks);
        }
        
    } catch (Exception $e) {
        error_log("handleGetTasks error: " . $e->getMessage());
        jsonError('An internal error occurred', 500);
    }
}

function handlePostTask() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'];
    
    if (isset($input['action'])) {
        switch ($input['action']) {
            case 'update_status':
                updatePageStatus($input);
                break;
            case 'assign_page':
                assignPage($input);
                break;
            case 'add_comment':
                addComment($input);
                break;
            default:
                jsonError('Invalid action', 400);
        }
    } else {
        jsonError('No action specified', 400);
    }
}

function updatePageStatus($data) {
    global $db;
    
    $pageId = validateInt($data['page_id'] ?? 0);
    if (!$pageId) {
        jsonError('Invalid page ID', 400);
        return;
    }
    
    $status = sanitizeInput($data['status'] ?? '');
    if (empty($status)) {
        jsonError('Status is required', 400);
        return;
    }
    
    // Validate status value
    $validStatuses = ['not_started', 'in_progress', 'on_hold', 'qa_in_progress', 'in_fixing', 'needs_review', 'completed'];
    if (!in_array($status, $validStatuses)) {
        jsonError('Invalid status value', 400);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'];
    
    // Validate user has permission to update this page
    $check = $db->prepare("
        SELECT pp.* FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE pp.id = ? AND (
            pp.at_tester_id = ? OR 
            pp.ft_tester_id = ? OR 
            pp.qa_id = ? OR 
            p.project_lead_id = ? OR
            ? IN ('admin')
        )
    ");
    $check->execute([$pageId, $userId, $userId, $userId, $userId, $userRole]);
    
    if (!$check->fetch()) {
        jsonError('Permission denied', 403);
        return;
    }
    
    try {
        $stmt = $db->prepare("UPDATE project_pages SET status = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([$status, $pageId])) {
            // Log activity
            try {
                $db->prepare("
                    INSERT INTO activity_log (user_id, action, entity_type, entity_id, details)
                    VALUES (?, 'update_status', 'page', ?, ?)
                ")->execute([
                    $userId,
                    $pageId,
                    json_encode(['status' => $status])
                ]);
            } catch (PDOException $e) {
                // Log error but don't fail the request
                error_log("Failed to log activity: " . $e->getMessage());
            }
            
            jsonResponse(['success' => true, 'message' => 'Status updated']);
        } else {
            jsonError('Failed to update status', 500);
        }
    } catch (PDOException $e) {
        error_log("Update status error: " . $e->getMessage());
        jsonError('Database error occurred', 500);
    }
}

function assignPage($data) {
    global $db;
    
    $pageId = validateInt($data['page_id'] ?? 0);
    if (!$pageId) {
        jsonError('Invalid page ID', 400);
        return;
    }
    
    $atTesterId = !empty($data['at_tester_id']) ? validateInt($data['at_tester_id']) : null;
    $ftTesterId = !empty($data['ft_tester_id']) ? validateInt($data['ft_tester_id']) : null;
    $qaId = !empty($data['qa_id']) ? validateInt($data['qa_id']) : null;
    $environments = isset($data['environments']) && is_array($data['environments']) 
        ? array_filter(array_map('validateInt', $data['environments']))
        : [];
    $userId = $_SESSION['user_id'];
    
    // Check if user has permission (project lead or admin)
    $check = $db->prepare("
        SELECT p.project_lead_id FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE pp.id = ? AND (
            p.project_lead_id = ? OR 
            ? IN ('admin')
        )
    ");
    $check->execute([$pageId, $userId, $_SESSION['role']]);
    
    if (!$check->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    try {
        $db->beginTransaction();
        
        // Update page assignment
        $stmt = $db->prepare("
            UPDATE project_pages 
            SET at_tester_id = ?, ft_tester_id = ?, qa_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$atTesterId, $ftTesterId, $qaId, $pageId]);
        
        // Update environments
        $db->prepare("DELETE FROM page_environments WHERE page_id = ?")->execute([$pageId]);
        
        foreach ($environments as $envId) {
            $db->prepare("
                INSERT INTO page_environments (page_id, environment_id)
                VALUES (?, ?)
            ")->execute([$pageId, $envId]);
        }
        
        $db->commit();
        
        // Log activity
        $db->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details)
            VALUES (?, 'assign_page', 'page', ?, ?)
        ")->execute([
            $userId,
            $pageId,
            json_encode([
                'at_tester_id' => $atTesterId,
                'ft_tester_id' => $ftTesterId,
                'qa_id' => $qaId,
                'environments' => $environments
            ])
        ]);
        
        jsonResponse(['success' => true, 'message' => 'Page assigned']);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Assign page error: " . $e->getMessage());
        jsonError('Failed to assign page', 500);
    }
}

function addComment($data) {
    global $db;
    
    $pageId = validateInt($data['page_id'] ?? 0);
    if (!$pageId) {
        jsonError('Invalid page ID', 400);
        return;
    }
    
    $comment = sanitizeInput($data['comment'] ?? '');
    if (empty(trim($comment))) {
        jsonError('Comment cannot be empty', 400);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Insert comment as chat message
    $stmt = $db->prepare("
        INSERT INTO chat_messages (project_id, page_id, user_id, message)
        SELECT p.id, ?, ?, ?
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE pp.id = ?
    ");
    
    try {
        if ($stmt->execute([$pageId, $userId, $comment, $pageId])) {
            jsonResponse(['success' => true, 'message' => 'Comment added']);
        } else {
            jsonError('Failed to add comment', 500);
        }
    } catch (PDOException $e) {
        error_log("Add comment error: " . $e->getMessage());
        jsonError('Database error occurred', 500);
    }
}

function handlePutTask() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON input', 400);
        return;
    }
    
    $pageId = validateInt($input['page_id'] ?? 0);
    if (!$pageId) {
        jsonError('Page ID required and must be a valid integer', 400);
        return;
    }
    
    // Update page details
    $fields = [];
    $values = [];
    
    if (isset($input['page_name'])) {
        $fields[] = 'page_name = ?';
        $values[] = sanitizeInput($input['page_name']);
    }
    
    if (isset($input['url'])) {
        $fields[] = 'url = ?';
        $values[] = sanitizeInput($input['url']);
    }
    
    if (isset($input['screen_name'])) {
        $fields[] = 'screen_name = ?';
        $values[] = sanitizeInput($input['screen_name']);
    }
    
    if (isset($input['page_number'])) {
        $fields[] = 'page_number = ?';
        $values[] = sanitizeInput($input['page_number']);
    }
    
    if (empty($fields)) {
        jsonError('No fields to update', 400);
        return;
    }
    
    // Check permissions before updating
    $check = $db->prepare("
        SELECT p.project_lead_id FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE pp.id = ? AND (
            p.project_lead_id = ? OR 
            ? IN ('admin')
        )
    ");
    $check->execute([$pageId, $_SESSION['user_id'], $_SESSION['role']]);
    
    if (!$check->fetch()) {
        jsonError('Permission denied', 403);
        return;
    }
    
    $values[] = $pageId;
    $fields[] = 'updated_at = NOW()';
    $sql = "UPDATE project_pages SET " . implode(', ', $fields) . " WHERE id = ?";
    
    try {
        $stmt = $db->prepare($sql);
        if ($stmt->execute($values)) {
            jsonResponse(['success' => true, 'message' => 'Page updated']);
        } else {
            jsonError('Failed to update page', 500);
        }
    } catch (PDOException $e) {
        error_log("Update page error: " . $e->getMessage());
        jsonError('Database error occurred', 500);
    }
}

function handleDeleteTask() {
    global $db;
    
    $pageId = validateInt($_GET['page_id'] ?? 0);
    if (!$pageId) {
        jsonError('Page ID required and must be a valid integer', 400);
        return;
    }
    
    // Check permissions
    $check = $db->prepare("
        SELECT p.project_lead_id FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE pp.id = ? AND ? IN ('admin')
    ");
    $check->execute([$pageId, $_SESSION['role']]);
    
    if (!$check->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    try {
        $db->beginTransaction();
        
        // Delete related data
        $db->prepare("DELETE FROM testing_results WHERE page_id = ?")->execute([$pageId]);
        $db->prepare("DELETE FROM qa_results WHERE page_id = ?")->execute([$pageId]);
        $db->prepare("DELETE FROM page_environments WHERE page_id = ?")->execute([$pageId]);
        $db->prepare("DELETE FROM chat_messages WHERE page_id = ?")->execute([$pageId]);
        
        // Delete page
        $db->prepare("DELETE FROM project_pages WHERE id = ?")->execute([$pageId]);
        
        $db->commit();
        jsonResponse(['success' => true, 'message' => 'Page deleted']);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Delete page error: " . $e->getMessage());
        jsonError('Failed to delete page', 500);
    }
}
?>
