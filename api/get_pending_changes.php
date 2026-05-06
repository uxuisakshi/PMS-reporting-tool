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

try {
    // Get pending changes
    $stmt = $db->prepare("SELECT * FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
    $stmt->execute([$userId, $date]);
    $pendingData = $stmt->fetch(PDO::FETCH_ASSOC);
    // decode JSON pending_time_logs if present
    if ($pendingData && !empty($pendingData['pending_time_logs'])) {
        $decoded = json_decode($pendingData['pending_time_logs'], true);
        $pendingData['pending_time_logs_decoded'] = $decoded === null ? [] : $decoded;
    } else {
        $pendingData['pending_time_logs_decoded'] = [];
    }

    // Get pending time-log edit requests with current/original log data
    $pendingLogEdits = [];
    try {
        $editStmt = $db->prepare("
            SELECT *
            FROM user_pending_log_edits
            WHERE user_id = ? AND req_date = ? AND status = 'pending'
            ORDER BY id DESC
        ");
        $editStmt->execute([$userId, $date]);
        $pendingLogEdits = $editStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $pendingLogEdits = [];
    }

    $pendingLogEditDiffs = [];
    foreach ($pendingLogEdits as $pe) {
        $logId = (int)($pe['log_id'] ?? 0);
        if ($logId <= 0) continue;

        $currentLog = null;
        try {
            $curStmt = $db->prepare("
                SELECT ptl.*, p.title AS project_title, p.po_number, pp.page_name, te.name AS environment_name
                FROM project_time_logs ptl
                LEFT JOIN projects p ON p.id = ptl.project_id
                LEFT JOIN project_pages pp ON pp.id = ptl.page_id
                LEFT JOIN testing_environments te ON te.id = ptl.environment_id
                WHERE ptl.id = ? AND ptl.user_id = ? LIMIT 1
            ");
            $curStmt->execute([$logId, $userId]);
            $currentLog = $curStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $currentLog = null;
        }

        $requestedProject = null;
        if (!empty($pe['new_project_id'])) {
            try {
                $pStmt = $db->prepare("SELECT id, title, po_number FROM projects WHERE id = ? LIMIT 1");
                $pStmt->execute([(int)$pe['new_project_id']]);
                $requestedProject = $pStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $requestedProject = null;
            }
        }

        $requestedPage = null;
        if (!empty($pe['new_page_id'])) {
            try {
                $pgStmt = $db->prepare("SELECT id, page_name FROM project_pages WHERE id = ? LIMIT 1");
                $pgStmt->execute([(int)$pe['new_page_id']]);
                $requestedPage = $pgStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $requestedPage = null;
            }
        }

        $requestedEnv = null;
        if (!empty($pe['new_environment_id'])) {
            try {
                $eStmt = $db->prepare("SELECT id, name FROM testing_environments WHERE id = ? LIMIT 1");
                $eStmt->execute([(int)$pe['new_environment_id']]);
                $requestedEnv = $eStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $requestedEnv = null;
            }
        }

        $pendingLogEditDiffs[] = [
            'request_id' => (int)$pe['id'],
            'log_id' => $logId,
            'reason' => (string)($pe['reason'] ?? ''),
            'current' => $currentLog,
            'requested' => [
                'project_id' => isset($pe['new_project_id']) ? (int)$pe['new_project_id'] : null,
                'project_title' => $requestedProject['title'] ?? null,
                'po_number' => $requestedProject['po_number'] ?? null,
                'task_type' => $pe['new_task_type'] ?? null,
                'page_id' => isset($pe['new_page_id']) ? (int)$pe['new_page_id'] : null,
                'page_name' => $requestedPage['page_name'] ?? null,
                'environment_id' => isset($pe['new_environment_id']) ? (int)$pe['new_environment_id'] : null,
                'environment_name' => $requestedEnv['name'] ?? null,
                'issue_id' => isset($pe['new_issue_id']) ? (int)$pe['new_issue_id'] : null,
                'phase_id' => isset($pe['new_phase_id']) ? (int)$pe['new_phase_id'] : null,
                'generic_category_id' => isset($pe['new_generic_category_id']) ? (int)$pe['new_generic_category_id'] : null,
                'testing_type' => $pe['new_testing_type'] ?? null,
                'phase_activity' => $pe['new_phase_activity'] ?? null,
                'generic_task_detail' => $pe['new_generic_task_detail'] ?? null,
                'is_utilized' => isset($pe['new_is_utilized']) ? (int)$pe['new_is_utilized'] : null,
                'hours_spent' => isset($pe['new_hours']) ? (float)$pe['new_hours'] : null,
                'description' => $pe['new_description'] ?? null
            ]
        ];
    }

    // Get pending delete requests with current log data for comparison
    $pendingLogDeleteDiffs = [];
    try {
        $delStmt = $db->prepare("
            SELECT *
            FROM user_pending_log_deletions
            WHERE user_id = ? AND req_date = ? AND status = 'pending'
            ORDER BY id DESC
        ");
        $delStmt->execute([$userId, $date]);
        $pendingDeletes = $delStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pendingDeletes as $pd) {
            $logId = (int)($pd['log_id'] ?? 0);
            if ($logId <= 0) continue;

            $currentLog = null;
            try {
                $curStmt = $db->prepare("
                    SELECT ptl.*, p.title AS project_title, p.po_number, pp.page_name, te.name AS environment_name
                    FROM project_time_logs ptl
                    LEFT JOIN projects p ON p.id = ptl.project_id
                    LEFT JOIN project_pages pp ON pp.id = ptl.page_id
                    LEFT JOIN testing_environments te ON te.id = ptl.environment_id
                    WHERE ptl.id = ? AND ptl.user_id = ? LIMIT 1
                ");
                $curStmt->execute([$logId, $userId]);
                $currentLog = $curStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $currentLog = null;
            }

            $pendingLogDeleteDiffs[] = [
                'request_id' => (int)$pd['id'],
                'log_id' => $logId,
                'reason' => (string)($pd['reason'] ?? ''),
                'current' => $currentLog,
                'requested' => [
                    'action' => 'delete',
                    'hours_spent' => null,
                    'description' => 'This log will be deleted'
                ]
            ];
        }
    } catch (Exception $e) {
        $pendingLogDeleteDiffs = [];
    }
    
    echo json_encode([
        'success' => true,
        'pending' => $pendingData,
        'pending_log_edit_diffs' => $pendingLogEditDiffs,
        'pending_log_delete_diffs' => $pendingLogDeleteDiffs
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
