<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Only admins can access this
if (!hasAdminPrivileges()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$db = Database::getInstance();
$userId = $_GET['user_id'] ?? null;
$date = $_GET['date'] ?? null;

if (!$userId || !$date) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Validate date format to prevent unexpected behavior
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date,5,2), (int)substr($date,8,2), (int)substr($date,0,4))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

try {
    // Get ORIGINAL data (not pending changes) - this is what was in the system before edit request
    $statusStmt = $db->prepare("SELECT uds.*, u.role FROM user_daily_status uds JOIN users u ON uds.user_id = u.id WHERE uds.user_id = ? AND uds.status_date = ?");
    $statusStmt->execute([$userId, $date]);
    $currentStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);

    $noteStmt = $db->prepare("SELECT content FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
    $noteStmt->execute([$userId, $date]);
    $personalNoteRow = $noteStmt->fetch(PDO::FETCH_ASSOC);
    $personalNote = $personalNoteRow ? $personalNoteRow['content'] : '';

    // Get user role if status doesn't exist
    $userRole = $currentStatus['role'] ?? null;
    if (!$userRole) {
        $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $roleStmt->execute([$userId]);
        $userRole = $roleStmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'status' => $currentStatus['status'] ?? null,
        'notes' => $currentStatus['notes'] ?? null,
        'personal_note' => $personalNote,
        'role' => $userRole
    ]);
    
} catch (Exception $e) {
    error_log('get_original_data error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An internal error occurred'
    ]);
}
?>
