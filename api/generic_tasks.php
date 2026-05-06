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

// CSRF protection
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
        handleGetRequest();
        break;
    case 'POST':
        handlePostRequest();
        break;
    default:
        jsonError('Method not allowed', 405);
}

function handleGetRequest() {
    global $db;
    
    try {
        $action = $_GET['action'] ?? '';
        $userId = $_SESSION['user_id'];
        
        if ($action === 'get_categories') {
            // Get active generic task categories
            
            $stmt = $db->prepare("
                SELECT id, name, description 
                FROM generic_task_categories 
                WHERE is_active = 1 
                ORDER BY name ASC
            ");
            $stmt->execute();
            $categories = $stmt->fetchAll();
            
            jsonResponse($categories);
            return;
        }
        
        // Default: Get user's generic tasks
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $db->prepare("
            SELECT ugt.*, gtc.name as category_name, gtc.description as category_desc
            FROM user_generic_tasks ugt
            JOIN generic_task_categories gtc ON ugt.category_id = gtc.id
            WHERE ugt.user_id = ? AND ugt.task_date = ?
            ORDER BY ugt.created_at DESC
        ");
        $stmt->execute([$userId, $date]);
        $tasks = $stmt->fetchAll();
        jsonResponse($tasks);
        
    } catch (PDOException $e) {
        error_log("Get generic tasks error: " . $e->getMessage());
        jsonError('Database error occurred', 500);
    } catch (Exception $e) {
        error_log("Get generic tasks general error: " . $e->getMessage());
        jsonError('An error occurred', 500);
    }
}

function handlePostRequest() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON input', 400);
        return;
    }
    
    $action = $input['action'] ?? '';
    $userId = $_SESSION['user_id'];
    
    try {
        if ($action === 'log_task') {
            // Log a generic task
            $categoryId = validateInt($input['category_id'] ?? 0);
            $description = sanitizeInput($input['description'] ?? '');
            $hours = floatval($input['hours_spent'] ?? 0);
            $taskDate = $input['task_date'] ?? date('Y-m-d');
            
            if (!$categoryId || !$description || $hours <= 0) {
                jsonError('Category, description, and hours are required', 400);
                return;
            }
            
            $stmt = $db->prepare("
                INSERT INTO user_generic_tasks (user_id, category_id, task_description, hours_spent, task_date)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $categoryId, $description, $hours, $taskDate]);
            
            jsonResponse(['success' => true, 'message' => 'Generic task logged successfully']);
            
        } else {
            jsonError('Invalid action', 400);
        }
        
    } catch (PDOException $e) {
        error_log("Post generic tasks error: " . $e->getMessage());
        jsonError('Database error occurred', 500);
    }
}
?>
