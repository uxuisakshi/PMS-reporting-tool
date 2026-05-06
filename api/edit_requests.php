<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF protection for state-changing requests
enforceApiCsrf();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    if ($action === 'get_pending') {
            // Only admin can view edit requests
            if (!in_array($userRole, ['admin'])) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }

            $filterUserId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int) $_GET['user_id'] : null;
        
            $sql = "
                SELECT uer.*, u.full_name as user_name, u.username 
                FROM user_edit_requests uer 
                JOIN users u ON uer.user_id = u.id 
                WHERE uer.status = 'pending'";

            $params = [];
            if ($filterUserId) {
                $sql .= " AND uer.user_id = ?";
                $params[] = $filterUserId;
            }

            $sql .= " ORDER BY uer.created_at DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
            echo json_encode(['success' => true, 'requests' => $requests]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Edit Requests API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
