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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$pageId = (int)($input['page_id'] ?? 0);
$environmentId = (int)($input['environment_id'] ?? 0);
$statusType = $input['status_type'] ?? ''; // 'testing' or 'qa'
$status = $input['status'] ?? '';

if (!$pageId || !$environmentId || !$statusType || !$status) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate status type
if (!in_array($statusType, ['testing', 'qa'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid status type']);
    exit;
}

// Validate status based on type
if ($statusType === 'testing') {
    $validStatuses = ['not_started', 'in_progress', 'completed', 'on_hold', 'needs_review'];
} else {
    $qaAliases = [
        'pending' => 'not_started',
        'na' => 'on_hold',
        'pass' => 'completed',
        'fail' => 'needs_review'
    ];
    $status = strtolower(trim((string)$status));
    if (isset($qaAliases[$status])) {
        $status = $qaAliases[$status];
    }
    $validStatuses = ['not_started', 'in_progress', 'completed', 'on_hold', 'needs_review'];
}

if (!in_array($status, $validStatuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit;
}

function mapComputedToPageStatus(string $status): string {
    $allowed = ['not_started', 'in_progress', 'on_hold', 'qa_in_progress', 'in_fixing', 'needs_review', 'completed'];
    $normalized = strtolower(trim($status));
    if (in_array($normalized, $allowed, true)) {
        return $normalized;
    }

    $legacyMap = [
        'not_tested' => 'not_started',
        'in_testing' => 'in_progress',
        'testing_failed' => 'in_fixing',
        'tested' => 'needs_review',
        'qa_review' => 'qa_in_progress',
        'qa_failed' => 'in_fixing'
    ];
    return $legacyMap[$normalized] ?? 'in_progress';
}

try {
    // Check if page_environment record exists
    $checkStmt = $db->prepare("
        SELECT pe.*, pp.page_name, pp.project_id, p.project_lead_id
        FROM page_environments pe
        JOIN project_pages pp ON pe.page_id = pp.id
        JOIN projects p ON pp.project_id = p.id
        WHERE pe.page_id = ? AND pe.environment_id = ?
    ");
    $checkStmt->execute([$pageId, $environmentId]);
    $pageEnv = $checkStmt->fetch();
    
    if (!$pageEnv) {
        echo json_encode(['success' => false, 'message' => 'Page environment not found']);
        exit;
    }
    
    // Check permissions
    $canUpdate = false;
    if (in_array($userRole, ['admin'])) {
        $canUpdate = true;
    } elseif ($userRole === 'project_lead' && $pageEnv['project_lead_id'] == $userId) {
        $canUpdate = true;
    } elseif ($statusType === 'testing' && ($pageEnv['at_tester_id'] == $userId || $pageEnv['ft_tester_id'] == $userId)) {
        $canUpdate = true;
    } elseif ($statusType === 'qa' && ($userRole === 'qa' || $pageEnv['qa_id'] == $userId)) {
        $canUpdate = true;
    }
    
    if (!$canUpdate) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }
    
    // Update the appropriate status column
    $columnName = ($statusType === 'testing') ? 'status' : 'qa_status';
    $updateStmt = $db->prepare("
        UPDATE page_environments 
        SET $columnName = ?, last_updated_by = ?, last_updated_at = NOW() 
        WHERE page_id = ? AND environment_id = ?
    ");
    $updateStmt->execute([$status, $userId, $pageId, $environmentId]);

    // Recompute and persist global page status from AT/FT/QA environment status mix
    $pageStmt = $db->prepare("SELECT * FROM project_pages WHERE id = ?");
    $pageStmt->execute([$pageId]);
    $pageData = $pageStmt->fetch(PDO::FETCH_ASSOC);
    $computedStatus = computePageStatus($db, $pageData ?: ['id' => $pageId]);
    $mappedStatus = mapComputedToPageStatus($computedStatus);
    $db->prepare("UPDATE project_pages SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$mappedStatus, $pageId]);
    if (!empty($pageEnv['project_id'])) {
        ensureProjectInProgress($db, $pageEnv['project_id']);
    }
    
    // Log activity
    logActivity($db, $userId, 'update_page_env_status', 'project', $pageEnv['project_id'], [
        'page_id' => $pageId,
        'page_name' => $pageEnv['page_name'],
        'environment_id' => $environmentId,
        'status_type' => $statusType,
        'status' => $status
    ]);
    
    echo json_encode([
        'success' => true, 
        'message' => ucfirst($statusType) . ' status updated successfully',
        'global_status' => $mappedStatus,
        'global_status_label' => formatPageProgressStatusLabel($mappedStatus)
    ]);
    
} catch (Exception $e) {
    error_log("Page Status Update API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred']);
}
