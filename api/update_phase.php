<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
ob_end_clean();

header('Content-Type: application/json');

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

$phaseId = $_POST['phase_id'] ?? 0;
$projectId = $_POST['project_id'] ?? 0;
$field = $_POST['field'] ?? ''; // 'status'
$value = $_POST['value'] ?? '';

if (!$phaseId || !$projectId || !$field) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Security: Only Admin, PL (if assigned), or QA can update phase status
$canUpdate = false;
if (in_array($userRole, ['admin', 'qa'])) {
    $canUpdate = true;
} elseif ($userRole === 'project_lead') {
    $stmt = $db->prepare("SELECT id FROM projects WHERE id = ? AND project_lead_id = ?");
    $stmt->execute([$projectId, $userId]);
    if ($stmt->fetch()) {
        $canUpdate = true;
    }
}

if (!$canUpdate) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

try {
    if ($field === 'status') {
        $stmt = $db->prepare("UPDATE project_phases SET status = ? WHERE id = ? AND project_id = ?");
        $stmt->execute([$value, $phaseId, $projectId]);
        
        logActivity($db, $userId, 'update_phase', 'project', $projectId, ['phase_id' => $phaseId, 'status' => $value]);
        
        // Automate project status
        ensureProjectInProgress($db, $projectId);
        
        echo json_encode(['success' => true, 'message' => 'Phase status updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid field']);
    }
} catch (Exception $e) {
    error_log("Phase Update API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
