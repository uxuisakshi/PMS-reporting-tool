<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF protection
enforceApiCsrf();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
$newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';

if (!$projectId || !$newStatus) {
    echo json_encode(['success' => false, 'message' => 'Project ID and status are required']);
    exit;
}

// Whitelist allowed status values
$allowedStatuses = ['in_progress', 'on_hold', 'completed', 'cancelled', 'not_started'];
if (!in_array($newStatus, $allowedStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit;
}

try {
    // Get project details
    $stmt = $db->prepare("SELECT project_lead_id, status, title FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    
    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit;
    }
    
    // Check permissions: only admin, admin, or project lead can update status
    $canUpdate = false;
    if (in_array($userRole, ['admin'])) {
        $canUpdate = true;
    } elseif ($userRole === 'project_lead' && $project['project_lead_id'] == $userId) {
        $canUpdate = true;
    }
    
    if (!$canUpdate) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to update project status']);
        exit;
    }
    
    $oldStatus = $project['status'];
    
    // Build the UPDATE query
    $sql = "UPDATE projects SET status = ?";
    $params = [$newStatus];
    
    // Add completed_at if status is being set to completed
    if ($newStatus === 'completed' && $oldStatus !== 'completed') {
        $sql .= ", completed_at = NOW()";
    }
    
    $sql .= " WHERE id = ?";
    $params[] = $projectId;
    
    // Update project status
    $updateStmt = $db->prepare($sql);
    
    if ($updateStmt->execute($params)) {
        // Log activity
        try {
            logActivity($db, $userId, 'update_project_status', 'project', $projectId, [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'project_title' => $project['title']
            ]);
        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Failed to log activity: " . $e->getMessage());
        }
        
        // Get formatted status label
        $statusLabel = formatProjectStatusLabel($newStatus);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Project status updated successfully',
            'status' => $newStatus,
            'status_label' => $statusLabel
        ]);
    } else {
        $errorInfo = $updateStmt->errorInfo();
        error_log("Update project status SQL error: " . print_r($errorInfo, true));
        echo json_encode(['success' => false, 'message' => 'Failed to update project status: ' . $errorInfo[2]]);
    }
    
} catch (PDOException $e) {
    error_log("Update project status error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred']);
} catch (Exception $e) {
    error_log("Update project status general error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
