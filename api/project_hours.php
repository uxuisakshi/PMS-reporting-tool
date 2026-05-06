<?php
/**
 * Project Hours API
 * Provides real-time project hours information for validation
 */

ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/hours_validation.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// CSRF protection
enforceApiCsrf();

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $projectId = $_GET['project_id'] ?? null;
        $action = $_GET['action'] ?? 'summary';
        
        if (!$projectId) {
            throw new Exception('Project ID is required');
        }
        
        switch ($action) {
            case 'summary':
                $summary = getProjectHoursSummary($db, $projectId);
                echo json_encode([
                    'success' => true,
                    'data' => $summary
                ]);
                break;
                
            case 'validate':
                $hours = $_GET['hours'] ?? 0;
                $excludeAssignmentId = $_GET['exclude_assignment_id'] ?? null;
                
                $validation = validateHoursAllocation($db, $projectId, $hours, $excludeAssignmentId);
                echo json_encode([
                    'success' => true,
                    'validation' => $validation
                ]);
                break;
                
            case 'available_projects':
                // Get projects with available hours for assignment
                $query = "
                    SELECT p.id, p.title, p.po_number, p.status, p.total_hours,
                           COALESCE(SUM(ua.hours_allocated), 0) as allocated_hours,
                           (p.total_hours - COALESCE(SUM(ua.hours_allocated), 0)) as available_hours
                    FROM projects p
                    LEFT JOIN user_assignments ua ON p.id = ua.project_id
                    WHERE p.status NOT IN ('completed', 'cancelled') AND p.total_hours > 0
                    GROUP BY p.id, p.title, p.po_number, p.status, p.total_hours
                    HAVING available_hours > 0
                    ORDER BY p.title
                ";
                
                $projects = $db->query($query)->fetchAll();
                echo json_encode([
                    'success' => true,
                    'data' => $projects
                ]);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Quick log endpoint for project view
        $postAction = $_POST['action'] ?? '';
        if ($postAction === 'log') {
            // Always use session user_id — never trust POST for user identity
            $userId = $_SESSION['user_id'];
            $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : null;
            $pageId = isset($_POST['page_id']) && $_POST['page_id'] !== '' ? intval($_POST['page_id']) : null;
            $envId = isset($_POST['environment_id']) && $_POST['environment_id'] !== '' ? intval($_POST['environment_id']) : null;
            $issueId = isset($_POST['issue_id']) && $_POST['issue_id'] !== '' ? intval($_POST['issue_id']) : null;
            $hours = isset($_POST['hours']) ? floatval($_POST['hours']) : 0;
            $desc = $_POST['description'] ?? '';
            $taskType = $_POST['task_type'] ?? 'other';
            $issueCount = isset($_POST['issue_count']) && $_POST['issue_count'] !== '' ? max(0, intval($_POST['issue_count'])) : null;
            $logDate = isset($_POST['log_date']) && $_POST['log_date'] !== '' ? $_POST['log_date'] : date('Y-m-d');

            if (!$projectId || $hours <= 0) {
                throw new Exception('Missing project_id or invalid hours');
            }
            if ($hours > 24) {
                throw new Exception('Cannot log more than 24 hours in a single entry');
            }

            if ($taskType === 'regression_testing' && $issueCount !== null && $issueCount > 0) {
                $desc = trim($desc);
                $prefix = 'Regression issue count: ' . $issueCount;
                $desc = $desc !== '' ? ($prefix . ' | ' . $desc) : $prefix;
            }

            // check project access
            $access = $db->prepare("SELECT id, po_number FROM projects WHERE id = ?");
            $access->execute([$projectId]);
            $proj = $access->fetch(PDO::FETCH_ASSOC);
            if (!$proj) throw new Exception('Project not found');

            $isUtilized = ($proj['po_number'] === 'OFF-PROD-001') ? 0 : 1;

            // Server-side: enforce allowed log date window for non-admins
            $role = $_SESSION['role'] ?? '';
            $isAdmin = in_array($role, ['admin']);
            $todayStr = date('Y-m-d');
            $hasApprovedPastEdit = false;
            try {
                $today = new DateTime('today');
                $dow = intval($today->format('N')); // 1 (Mon) .. 7 (Sun)
                $prev = clone $today;
                if ($dow === 1) {
                    // Monday -> previous business day is Saturday
                    $prev->modify('-2 days');
                } else {
                    $prev->modify('-1 day');
                }
                $minDate = $prev->format('Y-m-d');
                $maxDate = $today->format('Y-m-d');
                $ld = (new DateTime($logDate))->format('Y-m-d');
                if (!$isAdmin && ($ld < $minDate || $ld > $maxDate)) {
                    // Allow older past dates only when user has an approved edit request for that date.
                    if ($ld < $todayStr) {
                        $req = $db->prepare("
                            SELECT id
                            FROM user_edit_requests
                            WHERE user_id = ?
                              AND req_date = ?
                              AND status = 'approved'
                              AND request_type = 'edit'
                            LIMIT 1
                        ");
                        $req->execute([$_SESSION['user_id'], $ld]);
                        $hasApprovedPastEdit = (bool)$req->fetch(PDO::FETCH_ASSOC);
                    }
                    if (!$hasApprovedPastEdit) {
                        throw new Exception('Log date not allowed. Only today and the previous business day (' . $minDate . ' to ' . $maxDate . ') can be logged. To edit older dates, please send an edit request to admin.');
                    }
                }
            } catch (Exception $e) {
                throw $e;
            }

            // Insert into project_time_logs — keep it simple and compatible with enhanced columns if present
            $columnsExist = false;
            try { $checkStmt = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'task_type'"); $columnsExist = $checkStmt->rowCount() > 0; } catch (Exception $e) { $columnsExist = false; }

            if ($columnsExist) {
                $stmt = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, issue_id, task_type, testing_type, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $testingType = $_POST['testing_type'] ?? null;
                $stmt->execute([$userId, $projectId, $pageId, $envId, $issueId, $taskType, $testingType, $logDate, $hours, $desc, $isUtilized]);
            } else {
                $stmt = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $projectId, $pageId, $envId, $logDate, $hours, $desc, $isUtilized]);
            }

            $summary = getProjectHoursSummary($db, $projectId);
            
            // SAFEGUARD: Ensure budget (total_hours) was not accidentally modified
            // This is a safety check to prevent any rogue code from changing the budget
            $verifyBudget = $db->prepare("SELECT total_hours FROM projects WHERE id = ?");
            $verifyBudget->execute([$projectId]);
            $currentBudget = $verifyBudget->fetch(PDO::FETCH_ASSOC);
            
            // If budget in summary doesn't match database, use database value (the source of truth)
            if (isset($summary['total_hours']) && $currentBudget && $currentBudget['total_hours'] != $summary['total_hours']) {
                error_log("WARNING: Budget mismatch detected for project $projectId. DB: " . $currentBudget['total_hours'] . ", Summary: " . $summary['total_hours']);
                $summary['total_hours'] = $currentBudget['total_hours'];
            }

            // One-time approval usage: once a past-date approved edit is used, mark it as used.
            if (!$isAdmin && $hasApprovedPastEdit) {
                $markUsed = $db->prepare("
                    UPDATE user_edit_requests
                    SET status = 'used', updated_at = NOW()
                    WHERE user_id = ?
                      AND req_date = ?
                      AND status = 'approved'
                      AND request_type = 'edit'
                ");
                $markUsed->execute([$_SESSION['user_id'], $logDate]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Logged successfully',
                'summary' => $summary
            ]);
            exit;
        }
    } else {
        throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    error_log('project_hours API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An internal error occurred'
    ]);
}
