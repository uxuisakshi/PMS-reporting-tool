<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole('admin');
$db = Database::getInstance();
try { $db->exec("ALTER TABLE user_edit_requests MODIFY COLUMN status ENUM('pending','approved','rejected','used') DEFAULT 'pending'"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_daily_status MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'not_updated'"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_changes MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'not_updated'"); } catch (Exception $e) {}

$pageTitle = 'Edit Requests Management';

try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_pending_log_deletions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        req_date DATE NOT NULL,
        log_id INT NOT NULL,
        reason TEXT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_log (user_id, log_id),
        INDEX idx_user_date_status (user_id, req_date, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_pending_log_edits (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        req_date DATE NOT NULL,
        log_id INT NOT NULL,
        new_hours DECIMAL(10,2) NOT NULL,
        new_description TEXT NOT NULL,
        new_project_id INT NULL,
        new_task_type VARCHAR(50) NULL,
        new_page_id INT NULL,
        new_environment_id INT NULL,
        new_issue_id INT NULL,
        new_phase_id INT NULL,
        new_generic_category_id INT NULL,
        new_testing_type VARCHAR(50) NULL,
        new_phase_activity VARCHAR(100) NULL,
        new_generic_task_detail TEXT NULL,
        new_is_utilized TINYINT(1) NULL,
        reason TEXT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_log_edit (user_id, log_id),
        INDEX idx_user_date_status (user_id, req_date, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_project_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_task_type VARCHAR(50) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_page_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_environment_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_issue_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_phase_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_generic_category_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_testing_type VARCHAR(50) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_phase_activity VARCHAR(100) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_generic_task_detail TEXT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_is_utilized TINYINT(1) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests ADD COLUMN request_type ENUM('edit','delete') NOT NULL DEFAULT 'edit'"); } catch (Exception $e) {}
try { $db->exec("UPDATE user_edit_requests SET request_type = 'delete' WHERE reason LIKE 'Deletion request for time log ID %'"); } catch (Exception $e) {}
try { $db->exec("UPDATE user_edit_requests SET request_type = 'edit' WHERE request_type IS NULL OR request_type = ''"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests DROP INDEX uq_user_date"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests ADD UNIQUE KEY uq_user_date_type (user_id, req_date, request_type)"); } catch (Exception $e) {}

// Function to apply pending changes when request is approved
function applyPendingChanges($db, $userId, $date) {
    try {
        // Get pending changes
        $pendingStmt = $db->prepare("SELECT * FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
        $pendingStmt->execute([$userId, $date]);
        $pendingData = $pendingStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pendingData) {
            // Apply to user_daily_status
            $statusStmt = $db->prepare("INSERT INTO user_daily_status (user_id, status_date, status, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status=VALUES(status), notes=VALUES(notes), updated_at=NOW()");
            $statusStmt->execute([$userId, $date, $pendingData['status'], $pendingData['notes']]);
            
            // Apply to user_calendar_notes if personal note exists
            if (!empty($pendingData['personal_note'])) {
                $noteStmt = $db->prepare("INSERT INTO user_calendar_notes (user_id, note_date, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content=VALUES(content), updated_at=NOW()");
                $noteStmt->execute([$userId, $date, $pendingData['personal_note']]);
            }
            
            // Remove pending changes after applying
            // Apply pending time logs if present
            if (!empty($pendingData['pending_time_logs'])) {
                $logs = json_decode($pendingData['pending_time_logs'], true);
                if (is_array($logs)) {
                    // Insert each pending log as a new project_time_logs entry
                    foreach ($logs as $pl) {
                        // basic validation
                        $projId = isset($pl['project_id']) ? intval($pl['project_id']) : null;
                        if (!$projId) continue;
                        $taskType = $pl['task_type'] ?? 'other';
                        $pageIds = is_array($pl['page_ids']) ? $pl['page_ids'] : [];
                        $envIds = is_array($pl['environment_ids']) ? $pl['environment_ids'] : [];
                        $testingType = $pl['testing_type'] ?? null;
                        $issueId = !empty($pl['issue_id']) ? intval($pl['issue_id']) : null;
                        $hours = isset($pl['hours']) ? floatval($pl['hours']) : 0;
                        $desc = $pl['description'] ?? '';
                        $isUtilized = isset($pl['is_utilized']) ? intval($pl['is_utilized']) : 1;

                        if (!empty($pageIds)) {
                            // create entry per page
                            $perHour = count($pageIds) > 1 ? ($hours / count($pageIds)) : $hours;
                            foreach ($pageIds as $pid) {
                                $pid = intval($pid);
                                $envId = !empty($envIds) ? intval($envIds[0]) : null;
                                // choose insert based on schema
                                $columnsExist = false;
                                try { $check = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'task_type'"); $columnsExist = $check->rowCount() > 0; } catch (Exception $_) { $columnsExist = false; }
                                if ($columnsExist) {
                                    $ist = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, issue_id, task_type, testing_type, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    $ist->execute([$userId, $projId, $pid, $envId, $issueId, $taskType, $testingType, $date, $perHour, $desc, $isUtilized]);
                                } else {
                                    $ist = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                    $ist->execute([$userId, $projId, $pid, $envId, $date, $perHour, $desc, $isUtilized]);
                                }
                            }
                        } else {
                            // single entry without page
                            $envId = !empty($envIds) ? intval($envIds[0]) : null;
                            $columnsExist = false;
                            try { $check = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'task_type'"); $columnsExist = $check->rowCount() > 0; } catch (Exception $_) { $columnsExist = false; }
                            if ($columnsExist) {
                                $ist = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, issue_id, task_type, testing_type, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $ist->execute([$userId, $projId, null, $envId, $issueId, $taskType, $testingType, $date, $hours, $desc, $isUtilized]);
                            } else {
                                $ist = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                $ist->execute([$userId, $projId, null, $envId, $date, $hours, $desc, $isUtilized]);
                            }
                        }
                    }
                }
            }

            $deleteStmt = $db->prepare("DELETE FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
            $deleteStmt->execute([$userId, $date]);
        }

        // Apply pending log deletions for this date
        try {
            $delStmt = $db->prepare("SELECT id, log_id FROM user_pending_log_deletions WHERE user_id = ? AND req_date = ? AND status = 'pending'");
            $delStmt->execute([$userId, $date]);
            $pendingDeletes = $delStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pendingDeletes as $pd) {
                $logId = (int)($pd['log_id'] ?? 0);
                if ($logId <= 0) continue;
                $logRowStmt = $db->prepare("SELECT * FROM project_time_logs WHERE id = ? AND user_id = ? AND log_date = ? LIMIT 1");
                $logRowStmt->execute([$logId, $userId, $date]);
                $row = $logRowStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $db->prepare("DELETE FROM project_time_logs WHERE id = ? AND user_id = ?")->execute([$logId, $userId]);
                }
                $db->prepare("UPDATE user_pending_log_deletions SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([(int)$pd['id']]);
            }
        } catch (Exception $e) {
            error_log("Failed applying pending deletions: " . $e->getMessage());
        }

        // Apply pending log edits for this date
        try {
            $editStmt = $db->prepare("SELECT * FROM user_pending_log_edits WHERE user_id = ? AND req_date = ? AND status = 'pending'");
            $editStmt->execute([$userId, $date]);
            $pendingEdits = $editStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pendingEdits as $pe) {
                $logId = (int)($pe['log_id'] ?? 0);
                if ($logId <= 0) continue;
                $columns = [];
                try {
                    $colRows = $db->query("SHOW COLUMNS FROM project_time_logs")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($colRows as $cr) {
                        $columns[(string)$cr['Field']] = true;
                    }
                } catch (Exception $_) {
                    $columns = [];
                }

                $set = [];
                $params = [];
                if (!empty($columns['project_id'])) { $set[] = "project_id = ?"; $params[] = (int)($pe['new_project_id'] ?? 0); }
                if (!empty($columns['page_id'])) { $set[] = "page_id = ?"; $params[] = ($pe['new_page_id'] !== null ? (int)$pe['new_page_id'] : null); }
                if (!empty($columns['environment_id'])) { $set[] = "environment_id = ?"; $params[] = ($pe['new_environment_id'] !== null ? (int)$pe['new_environment_id'] : null); }
                if (!empty($columns['issue_id'])) { $set[] = "issue_id = ?"; $params[] = ($pe['new_issue_id'] !== null ? (int)$pe['new_issue_id'] : null); }
                if (!empty($columns['task_type'])) { $set[] = "task_type = ?"; $params[] = ($pe['new_task_type'] !== null ? (string)$pe['new_task_type'] : null); }
                if (!empty($columns['phase_id'])) { $set[] = "phase_id = ?"; $params[] = ($pe['new_phase_id'] !== null ? (int)$pe['new_phase_id'] : null); }
                if (!empty($columns['generic_category_id'])) { $set[] = "generic_category_id = ?"; $params[] = ($pe['new_generic_category_id'] !== null ? (int)$pe['new_generic_category_id'] : null); }
                if (!empty($columns['testing_type'])) { $set[] = "testing_type = ?"; $params[] = ($pe['new_testing_type'] !== null ? (string)$pe['new_testing_type'] : null); }
                if (!empty($columns['hours_spent'])) { $set[] = "hours_spent = ?"; $params[] = (float)$pe['new_hours']; }
                if (!empty($columns['description'])) { $set[] = "description = ?"; $params[] = (string)$pe['new_description']; }
                if (!empty($columns['is_utilized'])) {
                    $set[] = "is_utilized = ?";
                    $params[] = ($pe['new_is_utilized'] !== null ? (int)$pe['new_is_utilized'] : 1);
                }
                if (!empty($columns['updated_at'])) { $set[] = "updated_at = NOW()"; }

                if (!empty($set)) {
                    $sql = "UPDATE project_time_logs SET " . implode(', ', $set) . " WHERE id = ? AND user_id = ? AND log_date = ?";
                    $params[] = $logId;
                    $params[] = $userId;
                    $params[] = $date;
                    $upd = $db->prepare($sql);
                    $upd->execute($params);
                }
                $db->prepare("UPDATE user_pending_log_edits SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([(int)$pe['id']]);
            }
        } catch (Exception $e) {
            error_log("Failed applying pending edits: " . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log("Failed to apply pending changes: " . $e->getMessage());
    }
}

function clearPendingChangesOnReject($db, $userId, $date) {
    try {
        $dropPending = $db->prepare("DELETE FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
        $dropPending->execute([$userId, $date]);
    } catch (Exception $e) {
        error_log("Failed clearing user_pending_changes on reject: " . $e->getMessage());
    }

    try {
        $rejDel = $db->prepare("UPDATE user_pending_log_deletions SET status = 'rejected', updated_at = NOW() WHERE user_id = ? AND req_date = ? AND status = 'pending'");
        $rejDel->execute([$userId, $date]);
    } catch (Exception $e) {
        error_log("Failed rejecting pending log deletions: " . $e->getMessage());
    }

    try {
        $rejEdit = $db->prepare("UPDATE user_pending_log_edits SET status = 'rejected', updated_at = NOW() WHERE user_id = ? AND req_date = ? AND status = 'pending'");
        $rejEdit->execute([$userId, $date]);
    } catch (Exception $e) {
        error_log("Failed rejecting pending log edits: " . $e->getMessage());
    }
}

function processEditRequestDecision($db, array $reqData, $decision, $adminName) {
    $requestId = (int)($reqData['id'] ?? 0);
    $userId = (int)($reqData['user_id'] ?? 0);
    $reqDate = (string)($reqData['req_date'] ?? '');
    $requestType = (string)($reqData['request_type'] ?? 'edit');
    $lockedAt = (string)($reqData['locked_at'] ?? '');
    $hasSubmittedPayload = ($lockedAt !== '');

    if ($decision === 'rejected') {
        $db->prepare("UPDATE user_edit_requests SET status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$requestId]);
        clearPendingChangesOnReject($db, $userId, $reqDate);
        return [
            'action_label' => 'rejected',
            'message' => "Your edit request for {$reqDate} has been rejected by {$adminName}",
            'status_message' => 'Edit request rejected successfully'
        ];
    }

    if ($requestType === 'edit' && !$hasSubmittedPayload) {
        $db->prepare("UPDATE user_edit_requests SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$requestId]);
        return [
            'action_label' => 'approved',
            'message' => "Your edit access request for {$reqDate} has been approved by {$adminName}. You can now add multiple entries and submit them for final review.",
            'status_message' => 'Edit access approved successfully'
        ];
    }

    $finalStatus = ($requestType === 'edit') ? 'used' : 'approved';
    $db->prepare("UPDATE user_edit_requests SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$finalStatus, $requestId]);
    applyPendingChanges($db, $userId, $reqDate);

    return [
        'action_label' => 'approved',
        'message' => "Your submitted changes for {$reqDate} have been approved by {$adminName}",
        'status_message' => 'Submitted changes approved successfully'
    ];
}

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjaxRequest = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        if ($isAjaxRequest) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid request token.']);
        } else {
            $_SESSION['error'] = 'Invalid request. Please try again.';
            header('Location: edit_requests.php');
        }
        exit;
    }
    $requestId = $_POST['request_id'] ?? null;
    $action = $_POST['action'] ?? null;
    $userId = $_POST['user_id'] ?? null;
    $date = $_POST['date'] ?? null;

    // Handle log edit approve/reject
    if ($action === 'approve_log_edit' || $action === 'reject_log_edit') {
        $logEditId = (int)($_POST['log_edit_id'] ?? 0);
        $leUserId = (int)($_POST['user_id'] ?? 0);
        $leDate = $_POST['req_date'] ?? '';
        try {
            if ($action === 'approve_log_edit') {
                // Apply the edit
                $leStmt = $db->prepare("SELECT * FROM user_pending_log_edits WHERE id = ? AND status = 'pending'");
                $leStmt->execute([$logEditId]);
                $pe = $leStmt->fetch(PDO::FETCH_ASSOC);
                if ($pe) {
                    $colRows = $db->query("SHOW COLUMNS FROM project_time_logs")->fetchAll(PDO::FETCH_ASSOC);
                    $columns = array_column($colRows, 'Field');
                    $set = []; $params = [];
                    if (in_array('hours_spent', $columns)) { $set[] = "hours_spent = ?"; $params[] = (float)$pe['new_hours']; }
                    if (in_array('description', $columns)) { $set[] = "description = ?"; $params[] = $pe['new_description']; }
                    if (in_array('project_id', $columns) && $pe['new_project_id']) { $set[] = "project_id = ?"; $params[] = (int)$pe['new_project_id']; }
                    if (!empty($set)) {
                        // Only add updated_at if column exists
                        if (in_array('updated_at', $columns)) {
                            $set[] = "updated_at = NOW()";
                        }
                        $sql = "UPDATE project_time_logs SET " . implode(', ', $set) . " WHERE id = ? AND user_id = ?";
                        $params[] = (int)$pe['log_id']; $params[] = $leUserId;
                        $db->prepare($sql)->execute($params);
                    }
                    $db->prepare("UPDATE user_pending_log_edits SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$logEditId]);
                    $_SESSION['success'] = 'Log edit approved and applied successfully.';
                }
            } else {
                $db->prepare("UPDATE user_pending_log_edits SET status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$logEditId]);
                $_SESSION['success'] = 'Log edit rejected.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Failed: ' . $e->getMessage();
        }
        header('Location: edit_requests.php');
        exit;
    }

    // Handle log deletion approve/reject
    if ($action === 'approve_log_deletion' || $action === 'reject_log_deletion') {
        $logDeletionId = (int)($_POST['log_deletion_id'] ?? 0);
        $delUserId = (int)($_POST['user_id'] ?? 0);
        $delDate = $_POST['req_date'] ?? '';
        try {
            if ($action === 'approve_log_deletion') {
                // Apply the deletion
                $delStmt = $db->prepare("SELECT * FROM user_pending_log_deletions WHERE id = ? AND status = 'pending'");
                $delStmt->execute([$logDeletionId]);
                $pd = $delStmt->fetch(PDO::FETCH_ASSOC);
                if ($pd) {
                    $logId = (int)($pd['log_id'] ?? 0);
                    if ($logId > 0) {
                        $db->prepare("DELETE FROM project_time_logs WHERE id = ? AND user_id = ?")->execute([$logId, $delUserId]);
                    }
                    $db->prepare("UPDATE user_pending_log_deletions SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$logDeletionId]);
                    $_SESSION['success'] = 'Log deletion approved and applied successfully.';
                }
            } else {
                $db->prepare("UPDATE user_pending_log_deletions SET status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$logDeletionId]);
                $_SESSION['success'] = 'Log deletion rejected.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Failed: ' . $e->getMessage();
        }
        header('Location: edit_requests.php');
        exit;
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['request_ids']) && is_array($_POST['request_ids'])) {
        $bulkAction = $_POST['bulk_action'];
        $requestIds = array_map('intval', $_POST['request_ids']);
        
        if (in_array($bulkAction, ['approved', 'rejected']) && !empty($requestIds)) {
            try {
                $successCount = 0;
                $adminName = $_SESSION['full_name'] ?? 'Admin';
                
                foreach ($requestIds as $reqId) {
                    // Get request details
                    $reqStmt = $db->prepare("SELECT id, user_id, req_date, request_type, locked_at FROM user_edit_requests WHERE id = ?");
                    $reqStmt->execute([$reqId]);
                    $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($reqData) {
                        $result = processEditRequestDecision($db, $reqData, $bulkAction, $adminName);
                        $link = "/modules/calendar.php?date={$reqData['req_date']}";
                        
                        createNotification($db, (int)$reqData['user_id'], 'edit_request_response', $result['message'], $link);
                        
                        $successCount++;
                    }
                }
                
                $_SESSION['success'] = "Successfully {$bulkAction} {$successCount} edit request(s)";
                
            } catch (Exception $e) {
                $_SESSION['error'] = "Failed to process bulk action: " . $e->getMessage();
            }
        }
    }
    // Handle single actions
    elseif ($requestId && $action && in_array($action, ['approved', 'rejected'])) {
        try {
            $adminName = $_SESSION['full_name'] ?? 'Admin';
            $reqStmt = $db->prepare("SELECT id, user_id, req_date, request_type, locked_at FROM user_edit_requests WHERE id = ? LIMIT 1");
            $reqStmt->execute([$requestId]);
            $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$reqData) {
                throw new Exception('Edit request not found.');
            }

            $result = processEditRequestDecision($db, $reqData, $action, $adminName);
            $link = "/modules/calendar.php?date={$date}";
            
            createNotification($db, (int)$reqData['user_id'], 'edit_request_response', $result['message'], $link);
            
            $_SESSION['success'] = $result['status_message'];
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to update request: " . $e->getMessage();
        }
    }
    
    $returnTo = trim((string)($_POST['return_to'] ?? ''));
    $baseDir = getBaseDir();
    $defaultRedirect = $_SERVER['PHP_SELF'];
    $redirectTarget = $defaultRedirect;
    if ($returnTo !== '') {
        if (strpos($returnTo, $baseDir . '/modules/admin/') === 0 || strpos($returnTo, '/modules/admin/') === 0) {
            $redirectTarget = $returnTo;
        }
    }

    if ($isAjaxRequest) {
        $successMsg = isset($_SESSION['success']) ? (string)$_SESSION['success'] : '';
        $errorMsg = isset($_SESSION['error']) ? (string)$_SESSION['error'] : '';
        header('Content-Type: application/json');
        echo json_encode([
            'success' => ($errorMsg === ''),
            'message' => ($errorMsg === '' ? $successMsg : $errorMsg),
            'redirect' => $redirectTarget
        ]);
        unset($_SESSION['success'], $_SESSION['error']);
        exit;
    }

    header("Location: " . $redirectTarget);
    exit;
}

// Fetch all pending edit requests
$stmt = $db->prepare("
    SELECT uer.*, u.full_name, u.username 
    FROM user_edit_requests uer 
    JOIN users u ON uer.user_id = u.id 
    WHERE uer.status = 'pending' 
    ORDER BY uer.created_at DESC
");
$stmt->execute();
$pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending log edits (from user_pending_log_edits table)
$pendingLogEdits = [];
try {
    $leStmt = $db->prepare("
        SELECT ple.*, u.full_name, u.username,
               ptl.hours_spent as old_hours, ptl.description as old_description,
               p.title as project_title
        FROM user_pending_log_edits ple
        JOIN users u ON ple.user_id = u.id
        LEFT JOIN project_time_logs ptl ON ple.log_id = ptl.id
        LEFT JOIN projects p ON ple.new_project_id = p.id
        WHERE ple.status = 'pending'
        ORDER BY ple.created_at DESC
    ");
    $leStmt->execute();
    $pendingLogEdits = $leStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Failed loading pending log edits: ' . $e->getMessage());
}

// Fetch pending log deletions
$pendingLogDeletions = [];
try {
    $delStmt = $db->prepare("
        SELECT pld.*, u.full_name, u.username,
               ptl.hours_spent, ptl.description, p.title as project_title
        FROM user_pending_log_deletions pld
        JOIN users u ON pld.user_id = u.id
        LEFT JOIN project_time_logs ptl ON pld.log_id = ptl.id
        LEFT JOIN projects p ON ptl.project_id = p.id
        WHERE pld.status = 'pending'
        ORDER BY pld.created_at DESC
    ");
    $delStmt->execute();
    $pendingLogDeletions = $delStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Failed loading pending log deletions: ' . $e->getMessage());
}

// Calculate total pending count
$totalPendingCount = count($pendingRequests) + count($pendingLogEdits) + count($pendingLogDeletions);

// Recent Processed Requests - Pagination and Filters
$recentUserFilter = $_GET['recent_user_filter'] ?? 'all';
$recentStatusFilter = $_GET['recent_status_filter'] ?? 'all';
$recentSearchUser = $_GET['recent_search_user'] ?? '';
$recentPage = isset($_GET['recent_page']) ? max(1, (int)$_GET['recent_page']) : 1;
$recentPerPage = isset($_GET['recent_per_page']) ? (int)$_GET['recent_per_page'] : 25;
$allowedRecentPerPage = [10, 25, 50, 100];
if (!in_array($recentPerPage, $allowedRecentPerPage, true)) {
    $recentPerPage = 25;
}

// Build query for recent processed requests
$recentWhere = "uer.status IN ('approved', 'rejected', 'used') AND uer.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$recentParams = [];

if ($recentUserFilter !== 'all') {
    $recentWhere .= " AND uer.user_id = ?";
    $recentParams[] = $recentUserFilter;
}

if ($recentStatusFilter !== 'all') {
    $recentWhere .= " AND uer.status = ?";
    $recentParams[] = $recentStatusFilter;
}

if (!empty($recentSearchUser)) {
    $recentWhere .= " AND (u.full_name LIKE ? OR u.username LIKE ?)";
    $searchTerm = '%' . $recentSearchUser . '%';
    $recentParams[] = $searchTerm;
    $recentParams[] = $searchTerm;
}

// Count total records
$countRecentSql = "SELECT COUNT(*) FROM user_edit_requests uer JOIN users u ON uer.user_id = u.id WHERE " . $recentWhere;
$countRecentStmt = $db->prepare($countRecentSql);
$countRecentStmt->execute($recentParams);
$recentTotalRecords = (int)$countRecentStmt->fetchColumn();
$recentTotalPages = max(1, (int)ceil($recentTotalRecords / $recentPerPage));
if ($recentPage > $recentTotalPages) {
    $recentPage = $recentTotalPages;
}
$recentOffset = ($recentPage - 1) * $recentPerPage;

// Fetch recent processed requests with pagination
$stmt = $db->prepare("
    SELECT uer.*, u.full_name, u.username 
    FROM user_edit_requests uer 
    JOIN users u ON uer.user_id = u.id 
    WHERE " . $recentWhere . "
    ORDER BY uer.updated_at DESC
    LIMIT ? OFFSET ?
");
$recentQueryParams = array_merge($recentParams, [$recentPerPage, $recentOffset]);
$stmt->execute($recentQueryParams);
$recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch users list for recent filter dropdown
$recentUsersStmt = $db->query("
    SELECT DISTINCT u.id, u.full_name 
    FROM users u 
    JOIN user_edit_requests uer ON u.id = uer.user_id 
    WHERE uer.status IN ('approved', 'rejected', 'used')
    ORDER BY u.full_name
");
$recentUsersList = $recentUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Check all processed requests (remove after debugging)
$debugStmt = $db->prepare("
    SELECT COUNT(*) as total, status 
    FROM user_edit_requests 
    WHERE status IN ('approved', 'rejected')
    GROUP BY status
");
$debugStmt->execute();
$debugCounts = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
// Uncomment below line to see debug info
// echo "<!-- Debug: "; print_r($debugCounts); echo " -->";

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Edit Requests Management</h2>
        <div>
            <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/calendar.php" class="btn btn-secondary">Back to Calendar</a>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Card -->
    <div class="card mb-4 border-warning">
        <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-inbox"></i> All Pending Requests</h5>
            <span class="badge bg-dark fs-6"><?php echo $totalPendingCount; ?> Total</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100 <?php echo count($pendingRequests) > 0 ? 'border-warning' : ''; ?>">
                        <div class="text-muted small">Edit Access Requests</div>
                        <div class="h4 mb-0"><?php echo count($pendingRequests); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100 <?php echo count($pendingLogEdits) > 0 ? 'border-info' : ''; ?>">
                        <div class="text-muted small">Pending Log Edits</div>
                        <div class="h4 mb-0"><?php echo count($pendingLogEdits); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100 <?php echo count($pendingLogDeletions) > 0 ? 'border-danger' : ''; ?>">
                        <div class="text-muted small">Pending Log Deletions</div>
                        <div class="h4 mb-0"><?php echo count($pendingLogDeletions); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Requests -->
    <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0">
                <i class="fas fa-clock"></i> Pending Edit Requests 
                <span class="badge bg-dark"><?php echo count($pendingRequests); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($pendingRequests)): ?>
                <p class="text-muted">No pending edit requests.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th>User</th>
                                <th>Date Requested</th>
                                <th>Request Date</th>
                                <th>Reason</th>
                                <th>Current Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingRequests as $req): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="request_ids[]" value="<?php echo $req['id']; ?>" class="form-check-input request-checkbox">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($req['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                        <small class="text-muted">@<?php echo htmlspecialchars($req['username'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($req['req_date'])); ?></strong><br>
                                        <small class="text-muted"><?php echo date('l', strtotime($req['req_date'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($req['reason'])): ?>
                                            <div class="text-muted small mb-1">
                                                <?php echo htmlspecialchars(substr($req['reason'], 0, 100), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if (strlen($req['reason']) > 100): ?>...<?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">No reason provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        // Get current status for this date
                                        $statusStmt = $db->prepare("SELECT status, notes FROM user_daily_status WHERE user_id = ? AND status_date = ?");
                                        $statusStmt->execute([$req['user_id'], $req['req_date']]);
                                        $currentStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);
                                        
                                        if ($currentStatus) {
                                            echo '<span class="badge bg-info">' . ucfirst($currentStatus['status']) . '</span>';
                                            if ($currentStatus['notes']) {
                                                echo '<br><small class="text-muted">' . htmlspecialchars(substr($currentStatus['notes'], 0, 50), ENT_QUOTES, 'UTF-8') . '...</small>';
                                            }
                                        } else {
                                            echo '<span class="badge bg-secondary">Not updated</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <form id="requestActionForm_<?php echo $req['id']; ?>" method="POST" class="d-inline">
                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $req['user_id']; ?>">
                                            <input type="hidden" name="date" value="<?php echo $req['req_date']; ?>">
                                            <input type="hidden" name="action" id="requestAction_<?php echo $req['id']; ?>" value="">
                                            <button type="button" class="btn btn-success btn-sm" onclick="document.getElementById('requestAction_<?php echo $req['id']; ?>').value='approved'; confirmModal('Approve this edit request?', function(){ var f=document.getElementById('requestActionForm_<?php echo $req['id']; ?>'); if(f){ f.submit(); } }, { title: 'Confirm Approval', confirmText: 'Approve', confirmClass: 'btn-success' });">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('requestAction_<?php echo $req['id']; ?>').value='rejected'; confirmModal('Reject this edit request?', function(){ var f=document.getElementById('requestActionForm_<?php echo $req['id']; ?>'); if(f){ f.submit(); } }, { title: 'Confirm Rejection', confirmText: 'Reject', confirmClass: 'btn-danger' });">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-info btn-sm" onclick="openAdminViewModal('<?php echo htmlspecialchars($req['req_date'], ENT_QUOTES, 'UTF-8'); ?>', <?php echo intval($req['user_id']); ?>, <?php echo intval($req['id']); ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bulk Actions -->
                <?php if (!empty($pendingRequests)): ?>
                <div class="mt-3 d-flex justify-content-between align-items-center">
                    <div>
                        <span id="selectedCount">0</span> requests selected
                    </div>
                    <div>
                        <button type="button" id="bulkApprove" class="btn btn-success" disabled>
                            <i class="fas fa-check"></i> Bulk Approve
                        </button>
                        <button type="button" id="bulkReject" class="btn btn-danger" disabled>
                            <i class="fas fa-times"></i> Bulk Reject
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pending Log Edit Items -->
    <?php if (!empty($pendingLogEdits)): ?>
    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">
                <i class="fas fa-edit"></i> Pending Log Edit Items
                <span class="badge bg-dark"><?php echo count($pendingLogEdits); ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 150px;">User</th>
                            <th style="min-width: 100px;">Date</th>
                            <th style="min-width: 150px;">Project</th>
                            <th style="min-width: 80px;">Old Hours</th>
                            <th style="min-width: 80px;">New Hours</th>
                            <th style="min-width: 200px;">New Description</th>
                            <th style="min-width: 150px;">Reason</th>
                            <th style="min-width: 200px; white-space: nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingLogEdits as $le): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($le['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                <small class="text-muted">@<?php echo htmlspecialchars($le['username'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($le['req_date'])); ?></td>
                            <td><?php echo htmlspecialchars($le['project_title'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo number_format((float)($le['old_hours'] ?? 0), 2); ?>h</td>
                            <td><strong><?php echo number_format((float)$le['new_hours'], 2); ?>h</strong></td>
                            <td><small><?php echo htmlspecialchars(substr($le['new_description'] ?? '', 0, 80), ENT_QUOTES, 'UTF-8'); ?><?php if (strlen($le['new_description'] ?? '') > 80): ?>...<?php endif; ?></small></td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($le['reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <td style="white-space: nowrap;">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="approve_log_edit">
                                    <input type="hidden" name="log_edit_id" value="<?php echo (int)$le['id']; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$le['user_id']; ?>">
                                    <input type="hidden" name="req_date" value="<?php echo htmlspecialchars($le['req_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-sm btn-success me-1" onclick="return confirm('Approve this log edit?')">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="reject_log_edit">
                                    <input type="hidden" name="log_edit_id" value="<?php echo (int)$le['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this log edit?')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pending Log Deletion Items -->
    <?php if (!empty($pendingLogDeletions)): ?>
    <div class="card mb-4">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0">
                <i class="fas fa-trash"></i> Pending Log Deletion Items
                <span class="badge bg-dark"><?php echo count($pendingLogDeletions); ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Date</th>
                            <th>Project</th>
                            <th>Hours</th>
                            <th>Description</th>
                            <th>Reason</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingLogDeletions as $del): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($del['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                <small class="text-muted">@<?php echo htmlspecialchars($del['username'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($del['req_date'])); ?></td>
                            <td><?php echo htmlspecialchars($del['project_title'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo number_format((float)($del['hours_spent'] ?? 0), 2); ?>h</td>
                            <td><small><?php echo htmlspecialchars(substr($del['description'] ?? '', 0, 80), ENT_QUOTES, 'UTF-8'); ?><?php if (strlen($del['description'] ?? '') > 80): ?>...<?php endif; ?></small></td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($del['reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="approve_log_deletion">
                                    <input type="hidden" name="log_deletion_id" value="<?php echo (int)$del['id']; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$del['user_id']; ?>">
                                    <input type="hidden" name="req_date" value="<?php echo htmlspecialchars($del['req_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-sm btn-success me-1" onclick="return confirm('Approve this log deletion?')">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="reject_log_deletion">
                                    <input type="hidden" name="log_deletion_id" value="<?php echo (int)$del['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this log deletion?')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Processed Requests -->
    <div class="card">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-history"></i> Recent Processed Requests (Last 30 days)
            </h5>
            <span class="badge bg-dark"><?php echo (int)$recentTotalRecords; ?> records</span>
        </div>
        <div class="card-body">
            <!-- Filters -->
            <form method="GET" class="row g-3 mb-4 p-3 bg-light rounded">
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-search"></i> Search User</label>
                    <input type="text" name="recent_search_user" class="form-control" 
                           placeholder="Search by name or username..." 
                           value="<?php echo htmlspecialchars($recentSearchUser ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-user"></i> User</label>
                    <select name="recent_user_filter" class="form-select">
                        <option value="all">All Users</option>
                        <?php foreach ($recentUsersList as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $recentUserFilter == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-filter"></i> Status</label>
                    <select name="recent_status_filter" class="form-select">
                        <option value="all" <?php echo ($recentStatusFilter ?? 'all') === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="approved" <?php echo ($recentStatusFilter ?? '') === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="used" <?php echo ($recentStatusFilter ?? '') === 'used' ? 'selected' : ''; ?>>Approved & Applied</option>
                        <option value="rejected" <?php echo ($recentStatusFilter ?? '') === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-list"></i> Per Page</label>
                    <select name="recent_per_page" class="form-select">
                        <?php foreach ([10, 25, 50, 100] as $rpp): ?>
                            <option value="<?php echo $rpp; ?>" <?php echo $recentPerPage === $rpp ? 'selected' : ''; ?>>
                                <?php echo $rpp; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="d-flex gap-2 w-100">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary" title="Clear Filters">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
            </form>

            <?php if (empty($recentRequests)): ?>
                <div class="text-muted text-center py-3">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>No recent processed requests found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Date Requested</th>
                                <th>Request Date</th>
                                <th>Status</th>
                                <th>Processed Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRequests as $req): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($req['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                        <small class="text-muted">@<?php echo htmlspecialchars($req['username'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($req['req_date'])); ?></strong><br>
                                        <small class="text-muted"><?php echo date('l', strtotime($req['req_date'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($req['status'] === 'approved'): ?>
                                            <span class="badge bg-success">Approved</span>
                                        <?php elseif ($req['status'] === 'used'): ?>
                                            <span class="badge bg-success">Approved & Applied</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($req['updated_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($recentTotalPages > 1): ?>
                    <?php
                        $recentBaseParams = $_GET;
                        unset($recentBaseParams['recent_page']);
                        $buildRecentPageUrl = function($p) use ($recentBaseParams) {
                            $params = $recentBaseParams;
                            $params['recent_page'] = $p;
                            return $_SERVER['PHP_SELF'] . '?' . http_build_query($params);
                        };
                        $recentStartPage = max(1, $recentPage - 2);
                        $recentEndPage = min($recentTotalPages, $recentPage + 2);
                    ?>
                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                        <small class="text-muted">
                            Showing <?php echo (int)($recentOffset + 1); ?> 
                            to <?php echo (int)min($recentOffset + $recentPerPage, $recentTotalRecords); ?> 
                            of <?php echo (int)$recentTotalRecords; ?> records
                        </small>
                        <nav aria-label="Recent requests pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $recentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $recentPage <= 1 ? '#' : htmlspecialchars($buildRecentPageUrl($recentPage - 1)); ?>">Previous</a>
                                </li>
                                <?php if ($recentStartPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildRecentPageUrl(1)); ?>">1</a>
                                    </li>
                                    <?php if ($recentStartPage > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($p = $recentStartPage; $p <= $recentEndPage; $p++): ?>
                                    <li class="page-item <?php echo $p === $recentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildRecentPageUrl($p)); ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($recentEndPage < $recentTotalPages): ?>
                                    <?php if ($recentEndPage < $recentTotalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildRecentPageUrl($recentTotalPages)); ?>"><?php echo $recentTotalPages; ?></a>
                                    </li>
                                <?php endif; ?>
                                <li class="page-item <?php echo $recentPage >= $recentTotalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $recentPage >= $recentTotalPages ? '#' : htmlspecialchars($buildRecentPageUrl($recentPage + 1)); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Admin View Modal (same as calendar modal but with admin actions) -->
<div class="modal fade" id="adminViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Review Edit Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="adminRequestId">
                <input type="hidden" id="adminUserId">
                <input type="hidden" id="adminDate">
                
                <!-- Edit Request Info -->
                <div class="alert alert-warning mb-3">
                    <h6><i class="fas fa-info-circle"></i> Edit Request Details</h6>
                    <div><strong>User:</strong> <span id="adminUserName"></span></div>
                    <div><strong>Date:</strong> <span id="adminRequestDate"></span></div>
                    <div><strong>Reason:</strong> <span id="adminRequestReason"></span></div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-secondary text-white">
                                <h6 class="mb-0">Current Data</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Availability Status</label>
                                    <input type="text" id="currentStatus" class="form-control" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Work Notes</label>
                                    <textarea id="currentNotes" class="form-control" rows="4" readonly></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Personal Notes</label>
                                    <textarea id="currentPersonalNote" class="form-control" rows="3" readonly></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0">Requested Changes</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Availability Status</label>
                                    <input type="text" id="requestedStatus" class="form-control" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Work Notes</label>
                                    <textarea id="requestedNotes" class="form-control" rows="4" readonly></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Personal Notes</label>
                                    <textarea id="requestedPersonalNote" class="form-control" rows="3" readonly></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mt-3" id="adminLogDiffCard" style="display:none;">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="fas fa-exchange-alt"></i> Time Log Change Details</h6>
                    </div>
                    <div class="card-body">
                        <div id="adminLogDiffContent"></div>
                    </div>
                </div>
                
                <!-- Production Hours -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-clock"></i> Production Hours <span id="adminHoursDate"></span></h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h4 id="adminTotalHours">0.00 hrs</h4>
                            <div class="progress mb-2">
                                <div id="adminUtilizedProgress" class="progress-bar bg-success" role="progressbar" style="width: 0%">
                                    Utilized
                                </div>
                                <div id="adminBenchProgress" class="progress-bar bg-secondary" role="progressbar" style="width: 100%">
                                    Bench
                                </div>
                            </div>
                            <small class="text-muted">
                                Utilized: <span id="adminUtilizedHours">0.00</span>h | 
                                Bench: <span id="adminBenchHours">0.00</span>h
                            </small>
                        </div>
                        
                        <div id="adminHoursEntries" style="max-height: 200px; overflow-y: auto;">
                            <p class="text-muted text-center">Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-danger" onclick="adminRejectRequest()">
                    <i class="fas fa-times"></i> Reject
                </button>
                <button type="button" class="btn btn-success" onclick="adminApproveRequest()">
                    <i class="fas fa-check"></i> Approve
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>window._editRequestsConfig = { baseDir: '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>' };</script>
<script src="<?php echo $baseDir; ?>/assets/js/admin-edit-requests.js"></script>
