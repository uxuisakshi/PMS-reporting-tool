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
        handleGetRequest();
        break;
    case 'POST':
        handlePostRequest();
        break;
    case 'PUT':
        handlePutRequest();
        break;
    case 'DELETE':
        handleDeleteRequest();
        break;
    default:
        jsonError('Method not allowed', 405);
}

function handleGetRequest() {
    global $db;
    
    try {
        $action = $_GET['action'] ?? '';
        $projectId = validateInt($_GET['project_id'] ?? 0) ?: 0;
        $userId = $_SESSION['user_id'];
        $userRole = $_SESSION['role'];
        
        // Handle specific actions
        if ($action === 'get_phases' && $projectId) {
            // Get project phases
            error_log("Getting phases for project ID: " . $projectId);
            
            $stmt = $db->prepare("
                SELECT id, phase_name, planned_hours, actual_hours, completion_percentage, start_date, end_date
                FROM project_phases 
                WHERE project_id = ? 
                ORDER BY id ASC
            ");
            $stmt->execute([$projectId]);
            $phases = $stmt->fetchAll();
            
            error_log("Found " . count($phases) . " phases for project " . $projectId);
            
            jsonResponse($phases);
            return;
        }
        
        $projectId = validateInt($_GET['id'] ?? 0) ?: 0;
        
        if ($projectId) {
            // Get single project
            $stmt = $db->prepare("
                SELECT p.*, c.name as client_name, 
                       u.full_name as project_lead_name,
                       COUNT(pp.id) as total_pages,
                       AVG(pp.completion) as avg_completion
                FROM projects p
                LEFT JOIN clients c ON p.client_id = c.id
                LEFT JOIN users u ON p.project_lead_id = u.id
                LEFT JOIN project_pages pp ON p.id = pp.project_id
                WHERE p.id = ?
                GROUP BY p.id
            ");
            $stmt->execute([$projectId]);
            $project = $stmt->fetch();
            
            if (!$project) {
                jsonError('Project not found', 404);
                return;
            }
            
            // Check permissions
            if ($userRole !== 'admin' && $userRole !== 'admin') {
                if ($userRole === 'project_lead' && $project['project_lead_id'] != $userId) {
                    jsonError('Permission denied', 403);
                    return;
                }
            }
            
            jsonResponse($project);
        } else {
            // Get projects based on user role
            $sql = "SELECT p.*, c.name as client_name 
                    FROM projects p 
                    LEFT JOIN clients c ON p.client_id = c.id 
                    WHERE 1=1";
            $params = [];
            
            if ($userRole === 'project_lead') {
                $sql .= " AND p.project_lead_id = ?";
                $params[] = $userId;
            } elseif ($userRole === 'qa') {
                $sql .= " AND p.id IN (SELECT project_id FROM user_assignments WHERE user_id = ? AND role = 'qa')";
                $params[] = $userId;
            } elseif (in_array($userRole, ['at_tester', 'ft_tester'])) {
                // Use prepared statement for field name to prevent SQL injection
                $field = $userRole === 'at_tester' ? 'at_tester_id' : 'ft_tester_id';
                $sql .= " AND p.id IN (SELECT project_id FROM project_pages WHERE $field = ?)";
                $params[] = $userId;
            }
            
            $sql .= " ORDER BY p.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $projects = $stmt->fetchAll();
            
            jsonResponse($projects);
        }
    } catch (PDOException $e) {
        error_log("Get projects error: " . $e->getMessage());
        jsonError('Database error occurred', 500);
    } catch (Exception $e) {
        error_log("Get projects general error: " . $e->getMessage());
        jsonError('An error occurred', 500);
    }
}

function handlePostRequest() {
    global $db;
    
    // Only admin/admin can create projects via API
    if (!in_array($_SESSION['role'], ['admin'])) {
        jsonError('Permission denied', 403);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON input', 400);
        return;
    }
    
    // Validate required fields
    $required = ['po_number', 'title', 'project_type', 'client_id', 'priority'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonError("Field '$field' is required", 400);
            return;
        }
    }
    
    try {
        $stmt = $db->prepare("
            INSERT INTO projects (po_number, title, description, project_type, client_id, priority, created_by, project_lead_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            sanitizeInput($input['po_number']),
            sanitizeInput($input['title']),
            sanitizeInput($input['description'] ?? ''),
            sanitizeInput($input['project_type']),
            validateInt($input['client_id']),
            sanitizeInput($input['priority']),
            $_SESSION['user_id'],
            validateInt($input['project_lead_id'] ?? null)
        ]);
        
        $projectId = $db->lastInsertId();
        
        // Log activity
        logActivity($db, $_SESSION['user_id'], 'created_project', 'project', $projectId, [
            'title' => sanitizeInput($input['title']),
            'po_number' => sanitizeInput($input['po_number']),
            'project_type' => sanitizeInput($input['project_type']),
            'priority' => sanitizeInput($input['priority'])
        ]);
        
        jsonResponse(['success' => true, 'message' => 'Project created', 'project_id' => $projectId], 201);
        
    } catch (PDOException $e) {
        error_log("Create project error: " . $e->getMessage());
        jsonError('Failed to create project', 500);
    }
}

function handlePutRequest() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON input', 400);
        return;
    }
    
    $projectId = validateInt($input['id'] ?? 0);
    if (!$projectId) {
        jsonError('Project ID required', 400);
        return;
    }
    
    // Check permissions
    $check = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ?");
    $check->execute([$projectId]);
    $project = $check->fetch();
    
    if (!$project) {
        jsonError('Project not found', 404);
        return;
    }
    
    if (!in_array($_SESSION['role'], ['admin']) && 
        $project['project_lead_id'] != $_SESSION['user_id']) {
        jsonError('Permission denied', 403);
        return;
    }
    
    // Build update query dynamically
    $fields = [];
    $values = [];
    
    $allowedFields = ['title', 'description', 'project_type', 'client_id', 'priority', 'status', 'project_lead_id'];
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            if (in_array($field, ['client_id', 'project_lead_id'])) {
                $values[] = validateInt($input[$field]);
            } else {
                $values[] = sanitizeInput($input[$field]);
            }
        }
    }
    
    if (empty($fields)) {
        jsonError('No fields to update', 400);
        return;
    }
    
    $values[] = $projectId;
    $sql = "UPDATE projects SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
    
    try {
        $stmt = $db->prepare($sql);
        if ($stmt->execute($values)) {
            // Log activity
            $updatedFields = [];
            $allowedFields = ['title', 'description', 'project_type', 'client_id', 'priority', 'status', 'total_hours', 'project_lead_id'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updatedFields[$field] = $input[$field];
                }
            }
            
            logActivity($db, $_SESSION['user_id'], 'updated_project', 'project', $projectId, [
                'updated_fields' => $updatedFields
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Project updated']);
        } else {
            jsonError('Failed to update project', 500);
        }
    } catch (PDOException $e) {
        error_log("Update project error: " . $e->getMessage());
        jsonError('Database error occurred', 500);
    }
}

function handleDeleteRequest() {
    global $db;
    
    $projectId = validateInt($_GET['id'] ?? 0);
    if (!$projectId) {
        jsonError('Project ID required', 400);
        return;
    }
    
    // Only admin/admin can cancel projects
    if (!in_array($_SESSION['role'], ['admin'])) {
        jsonError('Permission denied', 403);
        return;
    }
    
    try {
        $stmt = $db->prepare("UPDATE projects SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([$projectId])) {
            // Log activity
            logActivity($db, $_SESSION['user_id'], 'cancelled_project', 'project', $projectId, [
                'status' => 'cancelled'
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Project cancelled']);
        } else {
            jsonError('Failed to cancel project', 500);
        }
    } catch (PDOException $e) {
        error_log("Cancel project error: " . $e->getMessage());
        jsonError('Database error occurred', 500);
    }
}
?>
