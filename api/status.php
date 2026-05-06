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

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Handle GET requests for notifications
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'get_notifications') {
        try {
            $stmt = $db->prepare("
                SELECT id, type, message, link, is_read, created_at,
                       CASE 
                           WHEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) < 1 THEN 'Just now'
                           WHEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, created_at, NOW()), ' min ago')
                           WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, created_at, NOW()), ' hr ago')
                           ELSE CONCAT(TIMESTAMPDIFF(DAY, created_at, NOW()), ' day ago')
                       END as time_ago
                FROM notifications 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'notifications' => $notifications]);
            exit;
        } catch (Exception $e) {
            error_log("Notification API Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF protection for all state-changing POST requests
enforceApiCsrf();

$action = $_POST['action'] ?? '';
$pageId = $_POST['page_id'] ?? 0;
$status = $_POST['status'] ?? '';
$envId = $_POST['environment_id'] ?? null;

// Allowed statuses aligned to enums
$pageStatusOptions = ['not_started','in_progress','on_hold','qa_in_progress','in_fixing','needs_review','completed'];
$testerStatusOptions = ['not_started','in_progress','pass','fail','on_hold','needs_review'];
$qaStatusOptions = ['pending','pass','fail','na','completed'];

// Map computed statuses to project_pages.status enum to avoid SQL errors
function mapComputedToPageStatus(string $status): string {
    $map = [
        'testing_failed' => 'in_fixing',
        'qa_failed' => 'in_fixing',
        'in_testing' => 'in_progress',
        'tested' => 'needs_review',
        'qa_review' => 'qa_in_progress',
        'not_tested' => 'not_started',
        'on_hold' => 'on_hold',
        'completed' => 'completed',
        'in_progress' => 'in_progress',
        'in_fixing' => 'in_fixing',
        'needs_review' => 'needs_review',
        'qa_in_progress' => 'qa_in_progress',
        'not_started' => 'not_started',
        'pass' => 'qa_in_progress',
        'fail' => 'in_fixing'
    ];
    return $map[$status] ?? 'in_progress';
}

try {
    if ($action === 'mark_notification_read') {
        $notificationId = $_POST['notification_id'] ?? 0;
        if (!$notificationId) {
            echo json_encode(['success' => false, 'message' => 'Notification ID required']);
            exit;
        }
        $stmt = $db->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $userId]);
        echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
        
    } elseif ($action === 'mark_all_notifications_read') {
        $stmt = $db->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
        
    } elseif ($action === 'update_page_status') {
        if (!$pageId || !$status) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        if (!in_array($status, $pageStatusOptions, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit;
        }
        // Update global page status
        $stmt = $db->prepare("UPDATE project_pages SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $pageId]);
        
        logActivity($db, $userId, 'update_status', 'page', $pageId, ['status' => $status]);
        
        // Automate project status
        $pageProjectStmt = $db->prepare("SELECT project_id FROM project_pages WHERE id = ?");
        $pageProjectStmt->execute([$pageId]);
        $pageProject = $pageProjectStmt->fetch(PDO::FETCH_ASSOC);
        if ($pageProject) {
            ensureProjectInProgress($db, $pageProject['project_id']);
        }
        
        echo json_encode(['success' => true, 'message' => 'Page status updated']);
        
    } elseif ($action === 'update_env_status') {
        if (!$pageId || !$status) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        if (!$envId) {
            echo json_encode(['success' => false, 'message' => 'Environment ID required']);
            exit;
        }
        if (!in_array($status, $testerStatusOptions, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit;
        }
        
        // Permission check: only allow AT/FT assigned testers or admin/project_lead/admin
        $pe = $db->prepare("SELECT at_tester_id, ft_tester_id, qa_id, project_id FROM page_environments WHERE page_id = ? AND environment_id = ?");
        $pe->execute([$pageId, $envId]);
        $peRow = $pe->fetch(PDO::FETCH_ASSOC);
        $allowed = false;
        if ($peRow) {
            if (in_array($userRole, ['admin', 'project_lead', 'qa'])) {
                $allowed = true;
            }
            if ($userId && $peRow['at_tester_id'] && intval($peRow['at_tester_id']) === intval($userId)) {
                $allowed = true;
            }
            if ($userId && $peRow['ft_tester_id'] && intval($peRow['ft_tester_id']) === intval($userId)) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: not assigned to update this environment']);
            exit;
        }

        // Update environment-specific status
        $stmt = $db->prepare("
            UPDATE page_environments 
            SET status = ?, last_updated_by = ?, last_updated_at = NOW() 
            WHERE page_id = ? AND environment_id = ?
        ");
        $stmt->execute([$status, $userId, $pageId, $envId]);
        
        // Compute and update global page status
        $page = $db->prepare("SELECT * FROM project_pages WHERE id = ?");
        $page->execute([$pageId]);
        $pageData = $page->fetch();
        
        $newGlobalStatus = computePageStatus($db, $pageData);
        $mappedStatus = mapComputedToPageStatus($newGlobalStatus);
        $db->prepare("UPDATE project_pages SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$mappedStatus, $pageId]);
        
        // Automate project status
        ensureProjectInProgress($db, $pageData['project_id']);
        
        logActivity($db, $userId, 'update_status', 'page', $pageId, [
            'status' => $status, 
            'environment_id' => $envId,
            'new_global_status' => $mappedStatus
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Environment status updated',
            'global_status' => $mappedStatus,
            'global_status_label' => ucfirst(str_replace('_', ' ', $mappedStatus))
        ]);
    } elseif ($action === 'update_qa_env_status') {
        if (!$pageId || !$status) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        if (!$envId) {
            echo json_encode(['success' => false, 'message' => 'Environment ID required']);
            exit;
        }
        if (!in_array($status, $qaStatusOptions, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid QA status']);
            exit;
        }
        
        // Permission check: only allow assigned QA or admin/project_lead/admin
        $pe = $db->prepare("SELECT qa_id FROM page_environments WHERE page_id = ? AND environment_id = ?");
        $pe->execute([$pageId, $envId]);
        $peRow = $pe->fetch(PDO::FETCH_ASSOC);
        $allowed = false;
        if ($peRow) {
            if (in_array($userRole, ['admin', 'project_lead'])) {
                $allowed = true;
            }
            if ($userId && $peRow['qa_id'] && intval($peRow['qa_id']) === intval($userId)) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: not assigned as QA for this environment']);
            exit;
        }

        // Update QA-specific environment status
        $stmt = $db->prepare("
            UPDATE page_environments 
            SET qa_status = ?, last_updated_by = ?, last_updated_at = NOW() 
            WHERE page_id = ? AND environment_id = ?
        ");
        $stmt->execute([$status, $userId, $pageId, $envId]);
        
        // Compute and update global page status
        $page = $db->prepare("SELECT * FROM project_pages WHERE id = ?");
        $page->execute([$pageId]);
        $pageData = $page->fetch();
        
        $newGlobalStatus = computePageStatus($db, $pageData);
        $mappedStatus = mapComputedToPageStatus($newGlobalStatus);
        $db->prepare("UPDATE project_pages SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$mappedStatus, $pageId]);
        
        // Automate project status
        ensureProjectInProgress($db, $pageData['project_id']);
        
        logActivity($db, $userId, 'update_qa_status', 'page', $pageId, [
            'qa_status' => $status, 
            'environment_id' => $envId,
            'new_global_status' => $mappedStatus
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'QA Environment status updated',
            'global_status' => $mappedStatus,
            'global_status_label' => ucfirst(str_replace('_', ' ', $mappedStatus))
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Status API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
