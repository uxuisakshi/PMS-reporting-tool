<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
$auth->requireLogin();

// CSRF protection
enforceApiCsrf();

$db = Database::getInstance();
$currentUserId = $_SESSION['user_id'];
$isAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','admin']);

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$date = isset($_GET['date']) ? trim($_GET['date']) : null; // expected YYYY-MM-DD

if (!$userId || !$date) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters (user_id and date required)']);
    exit;
}

// Validate date format strictly to prevent injection
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date,5,2), (int)substr($date,8,2), (int)substr($date,0,4))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

// Allow users to see their own hours, or admin to see anyone's hours
if (!$isAdmin && $userId != $currentUserId) {
    error_log("User hours API error: Access denied - User $currentUserId trying to access user $userId data");
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

try {
    // First, check if the enhanced columns exist
    $columnsExist = false;
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'task_type'");
        $columnsExist = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        $columnsExist = false;
    }
    
    if ($columnsExist) {
        // Use enhanced query with new columns
        $stmt = $db->prepare("
            SELECT ptl.id, ptl.project_id, ptl.page_id, ptl.environment_id, ptl.hours_spent, 
                   ptl.description as comments, ptl.log_date, ptl.is_utilized, ptl.task_type, ptl.testing_type,
                   pp.page_name, p.title as project_title, p.po_number,
                   te.name as environment_name,
                   ph.phase_name,
                   gtc.name as generic_category_name
            FROM project_time_logs ptl
            LEFT JOIN project_pages pp ON ptl.page_id = pp.id
            LEFT JOIN projects p ON ptl.project_id = p.id
            LEFT JOIN testing_environments te ON ptl.environment_id = te.id
            LEFT JOIN project_phases ph ON ptl.phase_id = ph.id
            LEFT JOIN generic_task_categories gtc ON ptl.generic_category_id = gtc.id
            WHERE ptl.user_id = ? AND ptl.log_date = ?
            ORDER BY ptl.is_utilized DESC, ptl.id ASC
        ");
    } else {
        // Use basic query for existing schema
        $stmt = $db->prepare("
            SELECT ptl.id, ptl.project_id, ptl.page_id, ptl.environment_id, ptl.hours_spent, 
                   ptl.description as comments, ptl.log_date, ptl.is_utilized,
                   pp.page_name, p.title as project_title, p.po_number,
                   te.name as environment_name
            FROM project_time_logs ptl
            LEFT JOIN project_pages pp ON ptl.page_id = pp.id
            LEFT JOIN projects p ON ptl.project_id = p.id
            LEFT JOIN testing_environments te ON ptl.environment_id = te.id
            WHERE ptl.user_id = ? AND ptl.log_date = ?
            ORDER BY ptl.is_utilized DESC, ptl.id ASC
        ");
    }
    
    $stmt->execute([$userId, $date]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute total hours
    $sumStmt = $db->prepare("SELECT COALESCE(SUM(hours_spent),0) as total_hours FROM project_time_logs WHERE user_id = ? AND log_date = ?");
    $sumStmt->execute([$userId, $date]);
    $total = $sumStmt->fetch();

    // Fetch availability status for the user on that date if present
    $statusStmt = $db->prepare("SELECT status, notes FROM user_daily_status WHERE user_id = ? AND status_date = ? LIMIT 1");
    $statusStmt->execute([$userId, $date]);
    $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'date' => $date,
        'user_id' => $userId,
        'total_hours' => floatval($total['total_hours']),
        'entries' => $entries,
        'availability' => $statusRow ? $statusRow['status'] : null,
        'availability_notes' => $statusRow ? $statusRow['notes'] : null
    ]);
} catch (Exception $e) {
    error_log("User hours API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An internal error occurred']);
}

