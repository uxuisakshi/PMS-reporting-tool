<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();

// Restrict admin access - they don't need daily status (except for AJAX requests)
$isAjaxRequest = isset($_GET['action']) && in_array($_GET['action'], ['get_personal_note', 'check_edit_request']);
$isPostRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']);
if (hasAdminPrivileges() && !$isAjaxRequest && !$isPostRequest) {
    header("Location: " . getBaseDir() . "/modules/admin/calendar.php");
    exit;
}

$userId = $_SESSION['user_id'];
$isAdmin = hasAdminPrivileges();
$db = Database::getInstance();
$baseDir = getBaseDir();
if (!function_exists('setMyDailyStatusToast')) {
    function setMyDailyStatusToast($type, $message) {
        $_SESSION['my_daily_status_toast'] = [
            'type' => ($type === 'success') ? 'success' : 'danger',
            'message' => (string)$message
        ];
    }
}
$date = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d');
try {
    $productionLogRequestToken = bin2hex(random_bytes(16));
    $benchLogRequestToken = bin2hex(random_bytes(16));
} catch (Exception $e) {
    $productionLogRequestToken = md5(uniqid('prod_log_', true));
    $benchLogRequestToken = md5(uniqid('bench_log_', true));
}
$availabilityStatuses = getAvailabilityStatusOptions(false);
$availabilityStatusKeys = array_values(array_unique(array_map(static function ($row) {
    return strtolower((string)($row['status_key'] ?? ''));
}, $availabilityStatuses)));
if (empty($availabilityStatusKeys)) {
    $availabilityStatusKeys = ['not_updated', 'available', 'working', 'busy', 'on_leave', 'sick_leave'];
}
try {
    $db->exec("ALTER TABLE user_daily_status MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'not_updated'");
} catch (Exception $e) {}

// Ensure edit requests table exists (safe to run if migration not applied)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_edit_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        req_date DATE NOT NULL,
        request_type ENUM('edit','delete') NOT NULL DEFAULT 'edit',
        reason TEXT,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_date_type (user_id, req_date, request_type),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests ADD COLUMN request_type ENUM('edit','delete') NOT NULL DEFAULT 'edit'"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests ADD COLUMN locked_at TIMESTAMP NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $db->exec("UPDATE user_edit_requests SET request_type = 'delete' WHERE reason LIKE 'Deletion request for time log ID %'"); } catch (Exception $e) {}
try { $db->exec("UPDATE user_edit_requests SET request_type = 'edit' WHERE request_type IS NULL OR request_type = ''"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests DROP INDEX uq_user_date"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests ADD UNIQUE KEY uq_user_date_type (user_id, req_date, request_type)"); } catch (Exception $e) {}
$hasRequestTypeColumn = false;
try {
    $colStmt = $db->query("SHOW COLUMNS FROM user_edit_requests LIKE 'request_type'");
    $hasRequestTypeColumn = ($colStmt && $colStmt->rowCount() > 0);
} catch (Exception $e) {
    $hasRequestTypeColumn = false;
}

// Ensure time log history table exists (for audit trail visible to clients)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS project_time_log_history (
        id INT PRIMARY KEY AUTO_INCREMENT,
        time_log_id INT NULL,
        project_id INT NOT NULL,
        user_id INT NOT NULL,
        action_type ENUM('created','deleted','updated') NOT NULL,
        old_log_date DATE NULL,
        new_log_date DATE NULL,
        old_hours DECIMAL(10,2) NULL,
        new_hours DECIMAL(10,2) NULL,
        old_description TEXT NULL,
        new_description TEXT NULL,
        changed_by INT NOT NULL,
        context_json LONGTEXT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_time_log_id (time_log_id),
        INDEX idx_project_id (project_id),
        INDEX idx_user_id (user_id),
        INDEX idx_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}

if (!function_exists('recordProjectTimeLogHistory')) {
    function recordProjectTimeLogHistory($db, array $data) {
        try {
            $stmt = $db->prepare("
                INSERT INTO project_time_log_history
                (time_log_id, project_id, user_id, action_type, old_log_date, new_log_date, old_hours, new_hours, old_description, new_description, changed_by, context_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['time_log_id'] ?? null,
                $data['project_id'] ?? 0,
                $data['user_id'] ?? 0,
                $data['action_type'] ?? 'updated',
                $data['old_log_date'] ?? null,
                $data['new_log_date'] ?? null,
                $data['old_hours'] ?? null,
                $data['new_hours'] ?? null,
                $data['old_description'] ?? null,
                $data['new_description'] ?? null,
                $data['changed_by'] ?? 0,
                $data['context_json'] ?? null
            ]);
        } catch (Exception $e) {
            // Keep hours logging resilient even if history insert fails.
        }
    }
}

if (!function_exists('getEditRequestState')) {
    function getEditRequestState($db, $userId, $reqDate) {
        $stmt = $db->prepare("
            SELECT *
            FROM user_edit_requests
            WHERE user_id = ?
              AND req_date = ?
              AND request_type = 'edit'
            LIMIT 1
        ");
        $stmt->execute([(int)$userId, (string)$reqDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $status = (string)($row['status'] ?? '');
        $locked = !empty($row['locked_at']);

        return [
            'row' => $row,
            'status' => $status,
            'locked' => $locked,
            'pending' => ($status === 'pending'),
            'approved' => ($status === 'approved' && !$locked),
            'waiting_approval' => ($status === 'pending' && !$locked),
            'submitted' => ($status === 'pending' && $locked),
            'rejected' => ($status === 'rejected'),
            'used' => ($status === 'used')
        ];
    }
}

if (!function_exists('notifyEditRequestAdmins')) {
    function notifyEditRequestAdmins($db, $userId, $adminMessage, $userMessage) {
        $adminStmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin') AND is_active = 1");
        $adminStmt->execute();
        $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            createNotification($db, (int)$admin['id'], 'edit_request', (string)$adminMessage, '/modules/admin/edit_requests.php');
        }

        if ((string)$userMessage !== '') {
            createNotification($db, (int)$userId, 'edit_request', (string)$userMessage, '/modules/notifications.php?filter=edit_request');
        }

        return count($admins);
    }
}

if (!function_exists('hasPendingEditPayload')) {
    function hasPendingEditPayload($db, $userId, $reqDate) {
        try {
            $pendingStmt = $db->prepare("SELECT id FROM user_pending_changes WHERE user_id = ? AND req_date = ? LIMIT 1");
            $pendingStmt->execute([(int)$userId, (string)$reqDate]);
            if ($pendingStmt->fetchColumn()) {
                return true;
            }
        } catch (Exception $e) {}

        try {
            $editStmt = $db->prepare("SELECT id FROM user_pending_log_edits WHERE user_id = ? AND req_date = ? AND status = 'pending' LIMIT 1");
            $editStmt->execute([(int)$userId, (string)$reqDate]);
            if ($editStmt->fetchColumn()) {
                return true;
            }
        } catch (Exception $e) {}

        try {
            $deleteStmt = $db->prepare("SELECT id FROM user_pending_log_deletions WHERE user_id = ? AND req_date = ? AND status = 'pending' LIMIT 1");
            $deleteStmt->execute([(int)$userId, (string)$reqDate]);
            if ($deleteStmt->fetchColumn()) {
                return true;
            }
        } catch (Exception $e) {}

        return false;
    }
}

// Handle AJAX: check if edit request is pending for this date
if (isset($_GET['action']) && $_GET['action'] === 'check_edit_request') {
    $reqDate = $_GET['date'] ?? $date;
    $reqState = getEditRequestState($db, $userId, $reqDate);
    header('Content-Type: application/json');
    echo json_encode([
        'pending' => $reqState['pending'],
        'pending_locked' => $reqState['submitted'],
        'approved' => $reqState['approved'],
        'waiting_approval' => $reqState['waiting_approval'],
        'rejected' => $reqState['rejected'],
        'used' => $reqState['used'],
        'status' => $reqState['status']
    ]);
    exit;
}

// Handle AJAX: user requests edit for a past date
if (isset($_POST['action']) && $_POST['action'] === 'request_edit') {
    $reqDate = $_POST['date'] ?? $date;
    $reason = $_POST['reason'] ?? '';
    
    try {
        $reqState = getEditRequestState($db, $userId, $reqDate);
        if ($reqState['approved']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'already_approved' => true, 'message' => 'Edit access is already approved for this date.']);
            exit;
        }

        if ($reqState['waiting_approval']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'already_requested' => true, 'message' => 'Edit access request is already pending admin approval.']);
            exit;
        }

        if ($reqState['submitted']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Changes for this date are already submitted and awaiting admin review.']);
            exit;
        }

        // Insert or update request to pending
        $stmt = $db->prepare("INSERT INTO user_edit_requests (user_id, req_date, request_type, status, reason, locked_at) VALUES (?, ?, 'edit', 'pending', ?, NULL) ON DUPLICATE KEY UPDATE request_type='edit', status='pending', reason=VALUES(reason), locked_at=NULL, updated_at=NOW()");
        $stmt->execute([$userId, $reqDate, $reason]);

        $userName = $_SESSION['full_name'] ?? 'User';
        $msg = $userName . " requested edit access for " . $reqDate;
        if ($reason) {
            $msg .= " - Reason: " . substr($reason, 0, 100) . (strlen($reason) > 100 ? '...' : '');
        }
        $adminCount = notifyEditRequestAdmins(
            $db,
            (int)$userId,
            $msg,
            "Your edit access request for {$reqDate} was submitted to admin."
        );

        if ($adminCount === 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No active admins found']);
            exit;
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Edit request sent to ' . $adminCount . ' admin(s)']);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle AJAX: finalize pending changes so user cannot modify further
if (isset($_POST['action']) && $_POST['action'] === 'submit_pending') {
    $reqDate = $_POST['date'] ?? $date;
    try {
        $reqState = getEditRequestState($db, $userId, $reqDate);
        $reqRow = $reqState['row'];

        if (!$reqRow) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No approved edit access found for this date']);
            exit;
        }

        if ($reqState['submitted']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'already_submitted' => true]);
            exit;
        }

        if ($reqState['waiting_approval']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Edit access is still waiting for admin approval.']);
            exit;
        }

        if (!$reqState['approved']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Edit access is not active for this date. Please send a new request.']);
            exit;
        }

        if (!hasPendingEditPayload($db, $userId, $reqDate)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No pending changes found to submit.']);
            exit;
        }

        $updStmt = $db->prepare("
            UPDATE user_edit_requests
            SET status = 'pending', locked_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $updStmt->execute([(int)$reqRow['id']]);

        $userName = $_SESSION['full_name'] ?? 'User';
        notifyEditRequestAdmins(
            $db,
            (int)$userId,
            $userName . " submitted final pending changes for " . $reqDate,
            "Your pending changes for {$reqDate} were submitted for admin review."
        );

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Pending changes submitted']);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle AJAX: save pending changes for edit request
if (isset($_POST['action']) && $_POST['action'] === 'save_pending') {
    $reqDate = $_POST['date'] ?? $date;
    $status = normalizeAvailabilityStatusKey($_POST['status'] ?? 'not_updated', $availabilityStatusKeys, 'not_updated');
    $notes = $_POST['notes'] ?? '';
    $personal_note = $_POST['personal_note'] ?? '';

    try {
        $reqState = getEditRequestState($db, $userId, $reqDate);
        if (!$reqState['row']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Please request edit access first.']);
            exit;
        }

        if ($reqState['submitted']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Request already submitted. You can no longer modify pending changes.']);
            exit;
        }

        if (!$reqState['approved']) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Edit access is not approved yet for this date.']);
            exit;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS user_pending_changes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            req_date DATE NOT NULL,
            status ENUM('not_updated','available','working','busy','on_leave','sick_leave') DEFAULT 'not_updated',
            notes TEXT,
            personal_note TEXT,
            pending_time_logs TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_date (user_id, req_date),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->exec("ALTER TABLE user_pending_changes MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'not_updated'");

        $hasPendingLogsInput = array_key_exists('pending_time_logs', $_POST);
        $appendPendingLogs = (isset($_POST['pending_time_logs_append']) && (string)$_POST['pending_time_logs_append'] === '1');
        $existingPendingLogsRaw = '[]';
        try {
            $existingStmt = $db->prepare("SELECT pending_time_logs FROM user_pending_changes WHERE user_id = ? AND req_date = ? LIMIT 1");
            $existingStmt->execute([$userId, $reqDate]);
            $existingPendingLogsRaw = (string)($existingStmt->fetchColumn() ?: '[]');
        } catch (Exception $e) {
            $existingPendingLogsRaw = '[]';
        }
        $existingPendingLogs = json_decode($existingPendingLogsRaw, true);
        if (!is_array($existingPendingLogs)) {
            $existingPendingLogs = [];
        }

        $resolvedPendingLogs = $existingPendingLogs;
        if ($hasPendingLogsInput) {
            $incomingPendingLogs = json_decode((string)$_POST['pending_time_logs'], true);
            if (!is_array($incomingPendingLogs)) {
                $incomingPendingLogs = [];
            }
            $resolvedPendingLogs = $appendPendingLogs
                ? array_values(array_merge($existingPendingLogs, $incomingPendingLogs))
                : $incomingPendingLogs;
        }
        $pendingLogs = json_encode($resolvedPendingLogs, JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare("INSERT INTO user_pending_changes (user_id, req_date, status, notes, personal_note, pending_time_logs) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status=VALUES(status), notes=VALUES(notes), personal_note=VALUES(personal_note), pending_time_logs=VALUES(pending_time_logs), updated_at=NOW()");
        $stmt->execute([$userId, $reqDate, $status, $notes, $personal_note, $pendingLogs]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Pending changes saved successfully']);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Ensure personal notes table exists (safe to run if migration not applied)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_calendar_notes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        note_date DATE NOT NULL,
        content TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_date (user_id, note_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    // If creation fails (permissions), we'll surface real errors later when used.
}

if (!function_exists('ensureOffProdProjectId')) {
    function ensureOffProdProjectId($db, $userId) {
        try {
            $findByPo = $db->prepare("SELECT id, status FROM projects WHERE po_number = 'OFF-PROD-001' ORDER BY id DESC LIMIT 1");
            $findByPo->execute();
            $row = $findByPo->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['id'])) {
                $id = (int)$row['id'];
                $status = (string)($row['status'] ?? '');
                if (in_array($status, ['cancelled', 'archived'], true)) {
                    $upd = $db->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ?");
                    $upd->execute([$id]);
                }
                return $id;
            }

            $findByTitle = $db->prepare("SELECT id, status FROM projects WHERE UPPER(title) LIKE '%OFF%PROD%' ORDER BY id DESC LIMIT 1");
            $findByTitle->execute();
            $row2 = $findByTitle->fetch(PDO::FETCH_ASSOC);
            if ($row2 && !empty($row2['id'])) {
                $id2 = (int)$row2['id'];
                $status2 = (string)($row2['status'] ?? '');
                if (in_array($status2, ['cancelled', 'archived'], true)) {
                    $upd2 = $db->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ?");
                    $upd2->execute([$id2]);
                }
                return $id2;
            }

            $ins = $db->prepare("
                INSERT INTO projects (po_number, title, description, project_type, priority, status, created_by)
                VALUES ('OFF-PROD-001', 'Off-Production / Bench', 'System project for off-production and bench hour logging.', 'web', 'medium', 'in_progress', ?)
            ");
            $ins->execute([(int)$userId]);
            return (int)$db->lastInsertId();
        } catch (Exception $e) {
            return 0;
        }
    }
}

// Ensure pending log deletion request table exists
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

// Ensure pending log edit request table exists
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
try { $db->exec("ALTER TABLE user_pending_changes ADD COLUMN pending_time_logs TEXT NULL"); } catch (Exception $e) {}

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $isAdmin = hasAdminPrivileges();
    $targetUser = $userId;
    
    $status = normalizeAvailabilityStatusKey($_POST['status'] ?? 'not_updated', $availabilityStatusKeys, 'not_updated');
    $notes = $_POST['notes'];
    $personal_note = isset($_POST['personal_note']) ? trim($_POST['personal_note']) : null;
    
    // Allow admin to update another user's status by providing user_id
    if ($isAdmin && isset($_POST['user_id']) && $_POST['user_id'] !== '') {
        $targetUser = intval($_POST['user_id']);
        
        // For admin updates, we don't need to check edit request approval
        $stmt = $db->prepare("
            INSERT INTO user_daily_status (user_id, status_date, status, notes)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes)
        ");
        
        $stmt->execute([$targetUser, $date, $status, $notes]);

        // Save personal note (visible only to this user)
        if ($personal_note !== null) {
            $trimmedNote = trim($personal_note);
            if ($trimmedNote !== '') {
                $noteStmt = $db->prepare("INSERT INTO user_calendar_notes (user_id, note_date, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)");
                $noteStmt->execute([$targetUser, $date, $trimmedNote]);
            } else {
                // Delete empty personal note if it exists
                $deleteStmt = $db->prepare("DELETE FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
                $deleteStmt->execute([$targetUser, $date]);
            }
        }

        // If AJAX request, return JSON instead of redirecting
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }

        setMyDailyStatusToast('success', 'Status updated successfully.');
        header("Location: " . getBaseDir() . "/modules/admin/calendar.php");
        exit;
    }

    // Prevent non-admin users from updating past dates unless approved
    $today = date('Y-m-d');
    if (!$isAdmin && $date < $today) {
        // Check if user has approved edit request for this date
        $requestStmt = $db->prepare("
            SELECT status
            FROM user_edit_requests
            WHERE user_id = ?
              AND req_date = ?
              AND status = 'approved'
              AND request_type = 'edit'
        ");
        $requestStmt->execute([$userId, $date]);
        $approvedRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$approvedRequest) {
            $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Cannot update past dates without admin approval']);
                exit;
            }
            $_SESSION['error'] = 'Cannot update past dates without admin approval.';
            setMyDailyStatusToast('danger', 'Cannot update past dates without admin approval.');
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
            exit;
        }
    }
    
    $stmt = $db->prepare("
        INSERT INTO user_daily_status (user_id, status_date, status, notes)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes)
    ");
    
    $stmt->execute([$targetUser, $date, $status, $notes]);

    // Save personal note (visible only to this user)
    if ($personal_note !== null) {
        $trimmedNote = trim($personal_note);
        if ($trimmedNote !== '') {
            $noteStmt = $db->prepare("INSERT INTO user_calendar_notes (user_id, note_date, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)");
            $noteStmt->execute([$targetUser, $date, $trimmedNote]);
        } else {
            // Delete empty personal note if it exists
            $deleteStmt = $db->prepare("DELETE FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
            $deleteStmt->execute([$targetUser, $date]);
        }
    }

    // If this was an approved edit request, mark it as used
    if (!$isAdmin && $date < $today) {
        $updateRequestStmt = $db->prepare("
            UPDATE user_edit_requests
            SET status = 'used', updated_at = NOW()
            WHERE user_id = ?
              AND req_date = ?
              AND status = 'approved'
              AND request_type = 'edit'
        ");
        $updateRequestStmt->execute([$userId, $date]);
    }

    // If AJAX request, return JSON instead of redirecting
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    setMyDailyStatusToast('success', 'Status updated successfully.');
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

// Handle Time Log
$isTimeLogPost = (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    (
        isset($_POST['log_time']) ||
        (
            !isset($_POST['update_status']) &&
            isset($_POST['hours_spent']) &&
            isset($_POST['description']) &&
            (isset($_POST['project_id']) || isset($_POST['bench_activity']))
        )
    )
);
if ($isTimeLogPost) {

    
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $hours = floatval($_POST['hours_spent']);
    $desc = $_POST['description'];
    $logRequestToken = trim((string)($_POST['log_request_token'] ?? ''));
    if (!isset($_SESSION['my_daily_status_log_tokens']) || !is_array($_SESSION['my_daily_status_log_tokens'])) {
        $_SESSION['my_daily_status_log_tokens'] = [];
    }
    $logTokenStore = $_SESSION['my_daily_status_log_tokens'];
    $tokenTtlSeconds = 20 * 60;
    $nowTs = time();
    foreach ($logTokenStore as $tokenKey => $tokenTs) {
        if (!is_string($tokenKey) || !is_numeric($tokenTs) || ((int)$tokenTs + $tokenTtlSeconds) < $nowTs) {
            unset($logTokenStore[$tokenKey]);
        }
    }
    if ($logRequestToken !== '' && isset($logTokenStore[$logRequestToken])) {
        setMyDailyStatusToast('success', 'Hours already logged. Duplicate request ignored.');
        $_SESSION['my_daily_status_log_tokens'] = $logTokenStore;
        header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
        exit;
    }
    $isBenchRequest = (isset($_POST['bench_activity']) && trim((string)$_POST['bench_activity']) !== '');
    $taskTypeInput = trim((string)($_POST['task_type'] ?? ''));
    $taskTypeMap = [
        'regression_testing' => 'regression',
        'page_qa' => 'page_testing'
    ];
    $taskType = $taskTypeMap[$taskTypeInput] ?? $taskTypeInput;
    $allowedTaskTypes = ['page_testing', 'project_phase', 'generic_task', 'regression', 'other'];
    if (!in_array($taskType, $allowedTaskTypes, true)) {
        $taskType = 'other';
    }
    

    
    // Initialize variables
    $pageIds = [];
    $envId = null;
    $phaseId = null;
    $genericCategoryId = null;
    $taskDetails = '';
    
    // Process based on task type
    switch ($taskTypeInput) {
        case 'page_testing':
            // Handle multiple pages
            if (isset($_POST['page_ids']) && is_array($_POST['page_ids'])) {
                $pageIds = array_filter($_POST['page_ids'], function($id) { return !empty($id); });
                $pageIds = array_map('intval', $pageIds);
            }
            
            // Handle multiple environments
            if (isset($_POST['environment_ids']) && is_array($_POST['environment_ids'])) {
                $envIds = array_filter($_POST['environment_ids'], function($id) { return !empty($id); });
                if (!empty($envIds)) {
                    // For now, store the first environment ID (we can enhance this later)
                    $envId = intval($envIds[0]);
                    // Add environment info to description
                    if (count($envIds) > 1) {
                        $desc .= ' (Multiple environments: ' . count($envIds) . ')';
                    }
                }
            }
            $testingType = $_POST['testing_type'] ?? '';
            if ($testingType) {
                $desc = ucfirst(str_replace('_', ' ', $testingType)) . ': ' . $desc;
            }
            
            // Add page info to description
            if (!empty($pageIds)) {
                if (count($pageIds) > 1) {
                    $desc .= ' (Multiple pages: ' . count($pageIds) . ')';
                }
            }
            break;
            
        case 'project_phase':
            $phaseId = isset($_POST['phase_id']) && $_POST['phase_id'] !== '' ? intval($_POST['phase_id']) : null;
            $phaseActivity = $_POST['phase_activity'] ?? '';
            if ($phaseActivity) {
                $desc = ucfirst($phaseActivity) . ': ' . $desc;
            }
            break;
            
        case 'generic_task':
            $genericCategoryId = isset($_POST['generic_category_id']) && $_POST['generic_category_id'] !== '' ? intval($_POST['generic_category_id']) : null;
            $taskDetails = $_POST['generic_task_detail'] ?? '';
            if ($taskDetails) {
                $desc .= ' - ' . $taskDetails;
            }
            break;
    }
    
    // Handle bench activity description enhancement
    if ($isBenchRequest) {
        $benchActivity = $_POST['bench_activity'];
        if ($projectId <= 0) {
            $projectId = ensureOffProdProjectId($db, $userId);
        }
        $desc = ucfirst($benchActivity) . ': ' . $desc;
    }
    // Issue link (optional) for regression hours
    $issueId = isset($_POST['issue_id']) && $_POST['issue_id'] !== '' ? intval($_POST['issue_id']) : null;
    
    if ($projectId <= 0) {
        $projectErr = $isBenchRequest
            ? "Unable to log off-production hours: OFF-PROD project was not found. Create or activate an OFF-PROD project first."
            : "Unable to log hours: project is not selected.";
        $_SESSION['error'] = $projectErr;
        setMyDailyStatusToast('danger', $projectErr);
        header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
        exit;
    }

    // Check if off-production
    $isUtilized = $isBenchRequest ? 0 : 1;
    if (!$isBenchRequest) {
        $stmt = $db->prepare("SELECT po_number FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $po = $stmt->fetchColumn();
        if (strcasecmp(trim((string)$po), 'OFF-PROD-001') === 0) {
            $isUtilized = 0;
        }
    }
    

    
    try {
        // Prevent logging for past dates unless admin or approved edit request
        $today = date('Y-m-d');
        if (!$isAdmin && $date < $today) {
            $reqState = $hasRequestTypeColumn
                ? getEditRequestState($db, $userId, $date)
                : ['approved' => false, 'waiting_approval' => false, 'submitted' => false];
            if (!$reqState['approved']) {
                if ($reqState['waiting_approval']) {
                    setMyDailyStatusToast('info', 'Edit access request is already pending admin approval for this date.');
                    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                    exit;
                }

                if ($reqState['submitted']) {
                    setMyDailyStatusToast('warning', 'Pending changes for this date are already submitted and awaiting admin review.');
                    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                    exit;
                }

                // Capture current date values so admin can review and apply pending logs on approval.
                $statusRowStmt = $db->prepare("SELECT status, notes FROM user_daily_status WHERE user_id = ? AND status_date = ?");
                $statusRowStmt->execute([$userId, $date]);
                $statusRow = $statusRowStmt->fetch(PDO::FETCH_ASSOC);
                $currStatus = $statusRow['status'] ?? 'not_updated';
                $currNotes = $statusRow['notes'] ?? '';
                $noteRowStmt = $db->prepare("SELECT content FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
                $noteRowStmt->execute([$userId, $date]);
                $currPersonal = (string)($noteRowStmt->fetchColumn() ?: '');

                $pendingEntry = [
                    'project_id' => $projectId,
                    'task_type' => $taskType,
                    'page_ids' => array_values(array_map('intval', $pageIds)),
                    'environment_ids' => $envId ? [(int)$envId] : [],
                    'testing_type' => $_POST['testing_type'] ?? null,
                    'issue_id' => $issueId ?: null,
                    'hours' => $hours,
                    'description' => $desc,
                    'is_utilized' => (int)$isUtilized
                ];

                $pendingStmt = $db->prepare("SELECT pending_time_logs FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
                $pendingStmt->execute([$userId, $date]);
                $existingPendingRaw = $pendingStmt->fetchColumn();
                $existingPending = json_decode((string)$existingPendingRaw, true);
                if (!is_array($existingPending)) {
                    $existingPending = [];
                }
                $existingPending[] = $pendingEntry;

                $savePendingStmt = $db->prepare("
                    INSERT INTO user_pending_changes (user_id, req_date, status, notes, personal_note, pending_time_logs)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        notes = VALUES(notes),
                        personal_note = VALUES(personal_note),
                        pending_time_logs = VALUES(pending_time_logs),
                        updated_at = NOW()
                ");
                $savePendingStmt->execute([$userId, $date, $currStatus, $currNotes, $currPersonal, json_encode($existingPending, JSON_UNESCAPED_UNICODE)]);

                $reason = 'Time log edit request for date ' . $date;
                if ($hasRequestTypeColumn) {
                    $reqStmt = $db->prepare("
                        INSERT INTO user_edit_requests (user_id, req_date, request_type, status, reason)
                        VALUES (?, ?, 'edit', 'pending', ?)
                        ON DUPLICATE KEY UPDATE
                            request_type = 'edit',
                            status = 'pending',
                            reason = VALUES(reason),
                            updated_at = NOW()
                    ");
                } else {
                    $reqStmt = $db->prepare("
                        INSERT INTO user_edit_requests (user_id, req_date, status, reason)
                        VALUES (?, ?, 'pending', ?)
                        ON DUPLICATE KEY UPDATE
                            status = 'pending',
                            reason = VALUES(reason),
                            updated_at = NOW()
                    ");
                }
                $reqStmt->execute([$userId, $date, $reason]);
                $userName = $_SESSION['full_name'] ?? 'User';
                notifyEditRequestAdmins(
                    $db,
                    (int)$userId,
                    $userName . " requested edit access for " . $date,
                    "Your edit access request for {$date} was submitted to admin."
                );

                setMyDailyStatusToast('success', 'Edit request sent to admin for approval. Your log will be applied after approval.');
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;
            }
        }
        $db->beginTransaction();
        
        // Check if enhanced columns exist
        $columnsExist = false;
        try {
            $checkStmt = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'task_type'");
            $columnsExist = $checkStmt->rowCount() > 0;
        } catch (Exception $e) {
            $columnsExist = false;
        }
        

        
        // If multiple pages are selected, create separate entries for each page
        if ($taskType === 'page_testing' && !empty($pageIds)) {

            foreach ($pageIds as $pageId) {

                if ($columnsExist) {
                    // Insert with enhanced columns
                    $stmt = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, issue_id, task_type, phase_id, generic_category_id, testing_type, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $testingType = isset($_POST['testing_type']) ? $_POST['testing_type'] : null;

                    // Adjust hours for multiple pages (divide equally)
                    $adjustedHours = count($pageIds) > 1 ? $hours / count($pageIds) : $hours;

                    $stmt->execute([$userId, $projectId, $pageId, $envId, $issueId, $taskType, $phaseId, $genericCategoryId, $testingType, $date, $adjustedHours, $desc, $isUtilized]);
                    $newLogId = (int)$db->lastInsertId();
                    recordProjectTimeLogHistory($db, [
                        'time_log_id' => $newLogId,
                        'project_id' => $projectId,
                        'user_id' => $userId,
                        'action_type' => 'created',
                        'new_log_date' => $date,
                        'new_hours' => $adjustedHours,
                        'new_description' => $desc,
                        'changed_by' => $userId,
                        'context_json' => json_encode([
                            'task_type' => $taskType,
                            'environment_id' => $envId,
                            'page_id' => $pageId,
                            'issue_id' => $issueId,
                            'testing_type' => $testingType
                        ], JSON_UNESCAPED_UNICODE)
                    ]);

                } else {
                    // Insert with basic columns
                    $stmt = $db->prepare("
                        INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, log_date, hours_spent, description, is_utilized)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    // Adjust hours for multiple pages (divide equally)
                    $adjustedHours = count($pageIds) > 1 ? $hours / count($pageIds) : $hours;
                    
                    $stmt->execute([$userId, $projectId, $pageId, $envId, $date, $adjustedHours, $desc, $isUtilized]);
                    $newLogId = (int)$db->lastInsertId();
                    recordProjectTimeLogHistory($db, [
                        'time_log_id' => $newLogId,
                        'project_id' => $projectId,
                        'user_id' => $userId,
                        'action_type' => 'created',
                        'new_log_date' => $date,
                        'new_hours' => $adjustedHours,
                        'new_description' => $desc,
                        'changed_by' => $userId,
                        'context_json' => json_encode([
                            'task_type' => $taskType,
                            'environment_id' => $envId,
                            'page_id' => $pageId
                        ], JSON_UNESCAPED_UNICODE)
                    ]);

                }
            }
        } else {

            // Single entry for non-page tasks or single page
            $pageId = !empty($pageIds) ? $pageIds[0] : null;
            

            if ($columnsExist) {
                // Insert with enhanced columns
                $stmt = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, issue_id, task_type, phase_id, generic_category_id, testing_type, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $testingType = isset($_POST['testing_type']) ? $_POST['testing_type'] : null;
                $stmt->execute([$userId, $projectId, $pageId, $envId, $issueId, $taskType, $phaseId, $genericCategoryId, $testingType, $date, $hours, $desc, $isUtilized]);
                $newLogId = (int)$db->lastInsertId();
                recordProjectTimeLogHistory($db, [
                    'time_log_id' => $newLogId,
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'action_type' => 'created',
                    'new_log_date' => $date,
                    'new_hours' => $hours,
                    'new_description' => $desc,
                    'changed_by' => $userId,
                    'context_json' => json_encode([
                        'task_type' => $taskType,
                        'environment_id' => $envId,
                        'page_id' => $pageId,
                        'issue_id' => $issueId,
                        'testing_type' => $testingType
                    ], JSON_UNESCAPED_UNICODE)
                ]);

            } else {
                // Insert with basic columns
                $stmt = $db->prepare("
                    INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, log_date, hours_spent, description, is_utilized)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $projectId, $pageId, $envId, $date, $hours, $desc, $isUtilized]);
                $newLogId = (int)$db->lastInsertId();
                recordProjectTimeLogHistory($db, [
                    'time_log_id' => $newLogId,
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'action_type' => 'created',
                    'new_log_date' => $date,
                    'new_hours' => $hours,
                    'new_description' => $desc,
                    'changed_by' => $userId,
                    'context_json' => json_encode([
                        'task_type' => $taskType,
                        'environment_id' => $envId,
                        'page_id' => $pageId
                    ], JSON_UNESCAPED_UNICODE)
                ]);

            }
        }
        
        // Update project phase actual hours if phase is specified
        if ($phaseId) {
            $updatePhaseStmt = $db->prepare("
                UPDATE project_phases 
                SET actual_hours = actual_hours + ? 
                WHERE id = ? AND project_id = ?
            ");
            $updatePhaseStmt->execute([$hours, $phaseId, $projectId]);
        }
        
        // Update project total actual hours (for utilized hours only)
        if ($isUtilized) {
            // Get current total utilized hours for this project
            $totalStmt = $db->prepare("
                SELECT COALESCE(SUM(hours_spent), 0) 
                FROM project_time_logs 
                WHERE project_id = ? AND is_utilized = 1
            ");
            $totalStmt->execute([$projectId]);
            $totalUtilizedHours = $totalStmt->fetchColumn();
            
            // NOTE: DO NOT update projects.total_hours here!
            // total_hours is the BUDGET (fixed value set by admin)
            // It should NEVER be auto-updated based on logged hours
            // Logged hours are tracked separately in project_time_logs table
        }
        
        // Log generic task if applicable
        if ($taskType === 'generic_task' && $genericCategoryId) {
            $genericStmt = $db->prepare("
                INSERT INTO user_generic_tasks (user_id, category_id, task_description, hours_spent, task_date)
                VALUES (?, ?, ?, ?, ?)
            ");
            $genericStmt->execute([$userId, $genericCategoryId, $desc, $hours, $date]);
        }
        
        $db->commit();
        if ($logRequestToken !== '') {
            $logTokenStore[$logRequestToken] = $nowTs;
            $_SESSION['my_daily_status_log_tokens'] = $logTokenStore;
        }
        setMyDailyStatusToast('success', 'Time logged successfully and project hours updated.');
        
    } catch (Exception $e) {
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $rollbackError) {
            // Ignore rollback errors and keep original exception message for user feedback.
        }
        $timeLogErr = "Error logging time: " . $e->getMessage();
        $_SESSION['error'] = $timeLogErr;
        setMyDailyStatusToast('danger', $timeLogErr);
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

// Handle Delete Log
if (isset($_GET['delete_log_request'])) {
    $logId = (int)$_GET['delete_log_request'];
    if ($logId > 0) {
        if ($isAdmin) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date&delete_log=$logId");
            exit;
        }
        $today = date('Y-m-d');
        if ($date === $today) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date&delete_log=$logId");
            exit;
        }
        try {
            $logStmt = $db->prepare("SELECT id FROM project_time_logs WHERE id = ? AND user_id = ? AND log_date = ? LIMIT 1");
            $logStmt->execute([$logId, $userId, $date]);
            $existing = $logStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $_SESSION['error'] = "Log not found for selected date.";
                setMyDailyStatusToast('danger', 'Log not found for selected date.');
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;
            }

            $reason = "Deletion request for time log ID {$logId}";
            $reqStmt = $db->prepare("
                INSERT INTO user_edit_requests (user_id, req_date, request_type, status, reason)
                VALUES (?, ?, 'delete', 'pending', ?)
                ON DUPLICATE KEY UPDATE request_type = 'delete', status = 'pending', reason = VALUES(reason), updated_at = NOW()
            ");
            $reqStmt->execute([$userId, $date, $reason]);

            $delReqStmt = $db->prepare("
                INSERT INTO user_pending_log_deletions (user_id, req_date, log_id, reason, status)
                VALUES (?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE req_date = VALUES(req_date), reason = VALUES(reason), status = 'pending', updated_at = NOW()
            ");
            $delReqStmt->execute([$userId, $date, $logId, $reason]);

            $adminStmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin') AND is_active = 1");
            $adminStmt->execute();
            $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
            $userName = $_SESSION['full_name'] ?? 'User';
            $msg = $userName . " requested deletion approval for time log on " . $date . " (Log ID: " . $logId . ")";
            $link = "/modules/admin/edit_requests.php";
            foreach ($admins as $admin) {
                createNotification($db, (int)$admin['id'], 'edit_request', $msg, $link);
            }
            createNotification($db, (int)$userId, 'edit_request', "Your deletion request for {$date} (Log ID: {$logId}) was submitted to admin.", "/modules/notifications.php?filter=edit_request");

            setMyDailyStatusToast('success', 'Deletion request sent to admin for approval.');
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to send deletion request: " . $e->getMessage();
            setMyDailyStatusToast('danger', "Failed to send deletion request: " . $e->getMessage());
        }
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

// Handle direct log edit (same day only for non-admins)
if (isset($_GET['edit_log'])) {
    $logId = (int)$_GET['edit_log'];
    $date = $_GET['date'] ?? date('Y-m-d');
    $isAdmin = hasAdminPrivileges();
    
    // Safety check: must be today OR admin
    if (!$isAdmin && $date !== date('Y-m-d')) {
        $_SESSION['toast_message'] = "Direct editing is only allowed for today's logs.";
        $_SESSION['toast_type'] = "warning";
        header("Location: ?date=" . urlencode($date));
        exit;
    }

    $newProjectId = (int)$_GET['new_project_id'];
    $newTaskTypeInput = trim((string)($_GET['new_task_type'] ?? ''));
    $taskTypeMap = ['regression_testing' => 'regression', 'page_qa' => 'page_testing'];
    $newTaskType = $taskTypeMap[$newTaskTypeInput] ?? $newTaskTypeInput;

    $newHours = (float)$_GET['new_hours'];
    $newDescription = $_GET['new_description'];
    $newPageId = !empty($_GET['new_page_id']) ? (int)$_GET['new_page_id'] : null;
    $newEnvironmentId = !empty($_GET['new_environment_id']) ? (int)$_GET['new_environment_id'] : null;
    $newIssueId = !empty($_GET['new_issue_id']) ? (int)$_GET['new_issue_id'] : null;
    $newPhaseId = !empty($_GET['new_phase_id']) ? (int)$_GET['new_phase_id'] : null;
    $newGenericCategoryId = !empty($_GET['new_generic_category_id']) ? (int)$_GET['new_generic_category_id'] : null;
    $newTestingType = $_GET['new_testing_type'] ?? '';
    $newPhaseActivity = $_GET['new_phase_activity'] ?? '';
    $newGenericTaskDetail = $_GET['new_generic_task_detail'] ?? '';

    try {
        $db->beginTransaction();
        
        // Verify log belongs to user and is on the correct date
        $checkStmt = $db->prepare("SELECT * FROM project_time_logs WHERE id = ? AND user_id = ? AND log_date = ? LIMIT 1");
        $checkStmt->execute([$logId, $userId, $date]);
        $oldLog = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldLog) {
            throw new Exception("Log not found or access denied.");
        }

        $updateStmt = $db->prepare("
            UPDATE project_time_logs 
            SET project_id = ?, task_type = ?, hours_spent = ?, description = ?, 
                page_id = ?, environment_id = ?, issue_id = ?, phase_id = ?, 
                generic_category_id = ?, testing_type = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $newProjectId, $newTaskType, $newHours, $newDescription,
            $newPageId, $newEnvironmentId, $newIssueId, $newPhaseId,
            $newGenericCategoryId, $newTestingType,
            $logId
        ]);

        // Log the action using the correct function
        recordProjectTimeLogHistory($db, [
            'time_log_id' => $logId,
            'project_id' => $newProjectId,
            'user_id' => $userId,
            'action_type' => 'updated',
            'old_log_date' => $oldLog['log_date'],
            'new_log_date' => $date,
            'old_hours' => $oldLog['hours_spent'],
            'new_hours' => $newHours,
            'old_description' => $oldLog['description'],
            'new_description' => $newDescription,
            'changed_by' => $userId,
            'context_json' => json_encode([
                'task_type' => $newTaskType,
                'page_id' => $newPageId,
                'environment_id' => $newEnvironmentId,
                'issue_id' => $newIssueId,
                'phase_id' => $newPhaseId,
                'testing_type' => $newTestingType
            ], JSON_UNESCAPED_UNICODE)
        ]);

        $db->commit();
        setMyDailyStatusToast('success', 'Log updated successfully.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        setMyDailyStatusToast('danger', 'Error updating log: ' . $e->getMessage());
    }
    header("Location: ?date=" . urlencode($date));
    exit;
}

if (isset($_GET['edit_log_request'])) {
    $logId = (int)$_GET['edit_log_request'];
    $newHours = isset($_REQUEST['new_hours']) ? (float)$_REQUEST['new_hours'] : 0;
    $newDescription = trim((string)($_REQUEST['new_description'] ?? ''));
    $newProjectId = isset($_REQUEST['new_project_id']) ? (int)$_REQUEST['new_project_id'] : 0;
    $newTaskTypeInput = trim((string)($_REQUEST['new_task_type'] ?? ''));
    $taskTypeMap = ['regression_testing' => 'regression', 'page_qa' => 'page_testing'];
    $newTaskType = $taskTypeMap[$newTaskTypeInput] ?? $newTaskTypeInput;
    $allowedTaskTypes = ['page_testing', 'project_phase', 'generic_task', 'regression', 'other'];
    if (!in_array($newTaskType, $allowedTaskTypes, true)) {
        $newTaskType = 'other';
    }
    $newPageId = (isset($_REQUEST['new_page_id']) && $_REQUEST['new_page_id'] !== '') ? (int)$_REQUEST['new_page_id'] : null;
    $newEnvironmentId = (isset($_REQUEST['new_environment_id']) && $_REQUEST['new_environment_id'] !== '') ? (int)$_REQUEST['new_environment_id'] : null;
    $newIssueId = (isset($_REQUEST['new_issue_id']) && $_REQUEST['new_issue_id'] !== '') ? (int)$_REQUEST['new_issue_id'] : null;
    $newPhaseId = (isset($_REQUEST['new_phase_id']) && $_REQUEST['new_phase_id'] !== '') ? (int)$_REQUEST['new_phase_id'] : null;
    $newGenericCategoryId = (isset($_REQUEST['new_generic_category_id']) && $_REQUEST['new_generic_category_id'] !== '') ? (int)$_REQUEST['new_generic_category_id'] : null;
    $newTestingType = trim((string)($_REQUEST['new_testing_type'] ?? ''));
    $newPhaseActivity = trim((string)($_REQUEST['new_phase_activity'] ?? ''));
    $newGenericTaskDetail = trim((string)($_REQUEST['new_generic_task_detail'] ?? ''));
    $newIsUtilized = isset($_REQUEST['new_is_utilized']) ? (int)$_REQUEST['new_is_utilized'] : null;
    if ($logId > 0) {
        if ($isAdmin) {
            $_SESSION['error'] = 'Admins can edit logs directly from admin tools.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
            exit;
        }
        if ($newHours <= 0 || $newDescription === '') {
            $_SESSION['error'] = 'Invalid edit request. Hours and description are required.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
            exit;
        }
        try {
            $logStmt = $db->prepare("SELECT * FROM project_time_logs WHERE id = ? AND user_id = ? AND log_date = ? LIMIT 1");
            $logStmt->execute([$logId, $userId, $date]);
            $existingLog = $logStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingLog) {
                $_SESSION['error'] = "Log not found for selected date.";
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;
            }
            if ($newProjectId <= 0) {
                $newProjectId = (int)($existingLog['project_id'] ?? 0);
            }
            if ($newHours <= 0) {
                $newHours = (float)($existingLog['hours_spent'] ?? 0);
            }
            if ($newDescription === '') {
                $newDescription = (string)($existingLog['description'] ?? '');
            }
            if ($newTaskType === 'other' && !empty($existingLog['task_type'])) {
                $newTaskType = (string)$existingLog['task_type'];
            }
            if ($newPageId === null && isset($existingLog['page_id'])) {
                $newPageId = $existingLog['page_id'] !== null ? (int)$existingLog['page_id'] : null;
            }
            if ($newEnvironmentId === null && isset($existingLog['environment_id'])) {
                $newEnvironmentId = $existingLog['environment_id'] !== null ? (int)$existingLog['environment_id'] : null;
            }
            if ($newIssueId === null && isset($existingLog['issue_id'])) {
                $newIssueId = $existingLog['issue_id'] !== null ? (int)$existingLog['issue_id'] : null;
            }
            if ($newPhaseId === null && isset($existingLog['phase_id'])) {
                $newPhaseId = $existingLog['phase_id'] !== null ? (int)$existingLog['phase_id'] : null;
            }
            if ($newGenericCategoryId === null && isset($existingLog['generic_category_id'])) {
                $newGenericCategoryId = $existingLog['generic_category_id'] !== null ? (int)$existingLog['generic_category_id'] : null;
            }
            if ($newTestingType === '' && isset($existingLog['testing_type'])) {
                $newTestingType = (string)$existingLog['testing_type'];
            }
            if ($newIsUtilized === null) {
                $newIsUtilized = isset($existingLog['is_utilized']) ? (int)$existingLog['is_utilized'] : 1;
            }
            if ($newProjectId <= 0 || $newHours <= 0 || $newDescription === '') {
                $_SESSION['error'] = 'Invalid edit request. Required fields are missing.';
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;
            }

            $reqState = getEditRequestState($db, $userId, $date);
            if (!$reqState['approved']) {
                if ($reqState['waiting_approval']) {
                    $_SESSION['error'] = 'Edit access for this date is still pending admin approval.';
                    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                    exit;
                }

                if ($reqState['submitted']) {
                    $_SESSION['error'] = 'Pending changes for this date are already submitted and awaiting admin review.';
                    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                    exit;
                }

                $reason = 'Time log edit access request for date ' . $date;
                $reqStmt = $db->prepare("
                    INSERT INTO user_edit_requests (user_id, req_date, request_type, status, reason, locked_at)
                    VALUES (?, ?, 'edit', 'pending', ?, NULL)
                    ON DUPLICATE KEY UPDATE request_type = 'edit', status = 'pending', reason = VALUES(reason), locked_at = NULL, updated_at = NOW()
                ");
                $reqStmt->execute([$userId, $date, $reason]);

                $userName = $_SESSION['full_name'] ?? 'User';
                notifyEditRequestAdmins(
                    $db,
                    (int)$userId,
                    $userName . " requested edit access for time log date " . $date,
                    "Your edit access request for {$date} was submitted to admin."
                );

                $_SESSION['success'] = 'Edit access request sent. After admin approval, resubmit your log changes.';
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;
            }

            $reason = "Pending edit for time log ID {$logId}";

            $editReqStmt = $db->prepare("
                INSERT INTO user_pending_log_edits (
                    user_id, req_date, log_id, new_hours, new_description, new_project_id, new_task_type,
                    new_page_id, new_environment_id, new_issue_id, new_phase_id, new_generic_category_id,
                    new_testing_type, new_phase_activity, new_generic_task_detail, new_is_utilized, reason, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE
                    req_date = VALUES(req_date),
                    new_hours = VALUES(new_hours),
                    new_description = VALUES(new_description),
                    new_project_id = VALUES(new_project_id),
                    new_task_type = VALUES(new_task_type),
                    new_page_id = VALUES(new_page_id),
                    new_environment_id = VALUES(new_environment_id),
                    new_issue_id = VALUES(new_issue_id),
                    new_phase_id = VALUES(new_phase_id),
                    new_generic_category_id = VALUES(new_generic_category_id),
                    new_testing_type = VALUES(new_testing_type),
                    new_phase_activity = VALUES(new_phase_activity),
                    new_generic_task_detail = VALUES(new_generic_task_detail),
                    new_is_utilized = VALUES(new_is_utilized),
                    reason = VALUES(reason),
                    status = 'pending',
                    updated_at = NOW()
            ");
            $editReqStmt->execute([
                $userId, $date, $logId, $newHours, $newDescription, $newProjectId, $newTaskType,
                $newPageId, $newEnvironmentId, $newIssueId, $newPhaseId, $newGenericCategoryId,
                ($newTestingType !== '' ? $newTestingType : null),
                ($newPhaseActivity !== '' ? $newPhaseActivity : null),
                ($newGenericTaskDetail !== '' ? $newGenericTaskDetail : null),
                $newIsUtilized,
                $reason
            ]);

            $_SESSION['success'] = 'Pending log edit saved. Submit pending changes when you finish all edits for this date.';
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to send edit request: " . $e->getMessage();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

if (isset($_GET['delete_log'])) {
    $logId = (int)$_GET['delete_log'];
    if ($logId <= 0) {
        $_SESSION['error'] = 'Invalid log selected for deletion.';
        setMyDailyStatusToast('danger', 'Invalid log selected for deletion.');
        header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
        exit;
    }
    if (!$isAdmin) {
        $today = date('Y-m-d');
        if ($date !== $today) {
            $_SESSION['error'] = 'Only today\'s logs can be deleted directly. Please send a deletion request for past dates.';
            setMyDailyStatusToast('danger', 'Only today\'s logs can be deleted directly. Please send a deletion request for past dates.');
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
            exit;
        }
    }

    try {
        $db->beginTransaction();
        $logStmt = $db->prepare("SELECT * FROM project_time_logs WHERE id = ? AND user_id = ? LIMIT 1");
        $logStmt->execute([$logId, $userId]);
        $existingLog = $logStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingLog) {
            $db->prepare("DELETE FROM project_time_logs WHERE id = ? AND user_id = ?")->execute([$logId, $userId]);
            recordProjectTimeLogHistory($db, [
                'time_log_id' => (int)$existingLog['id'],
                'project_id' => (int)$existingLog['project_id'],
                'user_id' => (int)$existingLog['user_id'],
                'action_type' => 'deleted',
                'old_log_date' => $existingLog['log_date'] ?? null,
                'old_hours' => $existingLog['hours_spent'] ?? null,
                'old_description' => $existingLog['description'] ?? null,
                'changed_by' => $userId,
                'context_json' => json_encode([
                    'page_id' => $existingLog['page_id'] ?? null,
                    'environment_id' => $existingLog['environment_id'] ?? null,
                    'issue_id' => $existingLog['issue_id'] ?? null,
                    'task_type' => $existingLog['task_type'] ?? null
                ], JSON_UNESCAPED_UNICODE)
            ]);
            setMyDailyStatusToast('success', 'Log deleted.');
        } else {
            setMyDailyStatusToast('danger', 'Log not found.');
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "Error deleting log: " . $e->getMessage();
        setMyDailyStatusToast('danger', "Error deleting log: " . $e->getMessage());
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

// AJAX: return status and personal note for given date (supports admin querying other users via user_id)
if (isset($_GET['action']) && $_GET['action'] === 'get_personal_note') {
    $queriedDate = $_GET['date'] ?? $date;
    $targetUser = $userId;
    if ($isAdmin && isset($_GET['user_id']) && $_GET['user_id'] !== '') {
        $targetUser = intval($_GET['user_id']);
    }



    // Check if there's a draft-capable edit request for this date
    $editRequestStmt = $db->prepare("SELECT status, locked_at FROM user_edit_requests WHERE user_id = ? AND req_date = ? AND request_type = 'edit'");
    $editRequestStmt->execute([$targetUser, $queriedDate]);
    $editRequest = $editRequestStmt->fetch(PDO::FETCH_ASSOC);
    $hasPendingRequest = ($editRequest && in_array((string)$editRequest['status'], ['approved', 'pending'], true));



    // If there's a pending request, load pending changes instead of current data
    if ($hasPendingRequest) {
        $pendingData = null;
        try {
            $pendingStmt = $db->prepare("SELECT * FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
            $pendingStmt->execute([$targetUser, $queriedDate]);
            $pendingData = $pendingStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pendingData = null;
        }
        
        if ($pendingData) {
            // Get user role
            $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $roleStmt->execute([$targetUser]);
            $userRole = $roleStmt->fetchColumn();
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'status' => $pendingData['status'],
                'notes' => $pendingData['notes'],
                'personal_note' => $pendingData['personal_note'],
                'role' => $userRole,
                'is_pending' => true,
                'edit_request_status' => (string)($editRequest['status'] ?? '')
            ]);
            exit;
        }
    }

    // Load current/approved data
    $statusStmt = $db->prepare("SELECT uds.*, u.role FROM user_daily_status uds JOIN users u ON uds.user_id = u.id WHERE uds.user_id = ? AND uds.status_date = ?");
    $statusStmt->execute([$targetUser, $queriedDate]);
    $currentStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);

    $noteStmt = $db->prepare("SELECT content FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
    $noteStmt->execute([$targetUser, $queriedDate]);
    $personalNoteRow = $noteStmt->fetch(PDO::FETCH_ASSOC);
    $personalNote = $personalNoteRow ? $personalNoteRow['content'] : '';



    // Get user role if status doesn't exist
    $userRole = $currentStatus['role'] ?? null;
    if (!$userRole) {
        $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $roleStmt->execute([$targetUser]);
        $userRole = $roleStmt->fetchColumn();
    }

    $response = [
        'success' => true,
        'status' => $currentStatus['status'] ?? null,
        'notes' => $currentStatus['notes'] ?? null,
        'personal_note' => $personalNote,
        'role' => $userRole,
        'is_pending' => false,
        'debug_info' => [
            'queried_date' => $queriedDate,
            'target_user' => $targetUser,
            'current_user' => $userId,
            'has_pending_request' => $hasPendingRequest,
            'edit_request_status' => (string)($editRequest['status'] ?? ''),
            'status_found' => !empty($currentStatus)
        ]
    ];



    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get Current Status
$statusStmt = $db->prepare("SELECT * FROM user_daily_status WHERE user_id = ? AND status_date = ?");
$statusStmt->execute([$userId, $date]);
$currentStatus = $statusStmt->fetch();

// Get personal note for this date (if any)
$noteStmt = $db->prepare("SELECT content FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
$noteStmt->execute([$userId, $date]);
$personalNoteRow = $noteStmt->fetch();
$personalNote = $personalNoteRow ? $personalNoteRow['content'] : '';

// Check if there's a pending edit request for this user/date to adjust UI
$editReqStmt = $db->prepare("SELECT * FROM user_edit_requests WHERE user_id = ? AND req_date = ? AND request_type = 'edit'");
$editReqStmt->execute([$userId, $date]);
$editReq = $editReqStmt->fetch(PDO::FETCH_ASSOC);
$hasPendingRequest = ($editReq && $editReq['status'] === 'pending');
$hasSubmittedPendingRequest = ($hasPendingRequest && !empty($editReq['locked_at']));
$hasWaitingApprovalRequest = ($hasPendingRequest && empty($editReq['locked_at']));
$hasApprovedEditAccess = ($editReq && $editReq['status'] === 'approved' && empty($editReq['locked_at']));
$pendingData = null;
if ($editReq && in_array((string)$editReq['status'], ['approved', 'pending'], true)) {
    try {
        $pendingStmt = $db->prepare("SELECT * FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
        $pendingStmt->execute([$userId, $date]);
        $pendingData = $pendingStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $pendingData = null;
    }
}

// Is this a past date (and non-admin)? used to make fields readonly initially
$isPastDateReadonly = (!$isAdmin && $date < date('Y-m-d'));
$selectedDateLabel = date('M d, Y', strtotime($date));
$isViewingToday = ($date === date('Y-m-d'));

// Get Time Logs
$logsStmt = $db->prepare("
    SELECT ptl.*, p.title, p.po_number
    FROM project_time_logs ptl
    LEFT JOIN projects p ON ptl.project_id = p.id
    WHERE ptl.user_id = ? AND ptl.log_date = ?
");
$logsStmt->execute([$userId, $date]);
$logs = $logsStmt->fetchAll();

$pendingDeletionLogIds = [];
try {
    $pendingDelStmt = $db->prepare("SELECT log_id FROM user_pending_log_deletions WHERE user_id = ? AND req_date = ? AND status = 'pending'");
    $pendingDelStmt->execute([$userId, $date]);
    $pendingDeletionLogIds = array_map('intval', $pendingDelStmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {
    $pendingDeletionLogIds = [];
}

$pendingEditLogIds = [];
try {
    $pendingEditStmt = $db->prepare("SELECT log_id FROM user_pending_log_edits WHERE user_id = ? AND req_date = ? AND status = 'pending'");
    $pendingEditStmt->execute([$userId, $date]);
    $pendingEditLogIds = array_map('intval', $pendingEditStmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {
    $pendingEditLogIds = [];
}

// Get assigned projects for this user.
// Include all active project statuses (exclude cancelled/archived),
// and include assignment paths used across the app (team/page/env/unique page mappings).
$hasProjectPageAtIdsJson = false;
$hasProjectPageFtIdsJson = false;
try {
    $colStmt = $db->query("SHOW COLUMNS FROM project_pages LIKE 'at_tester_ids'");
    $hasProjectPageAtIdsJson = ($colStmt && $colStmt->rowCount() > 0);
} catch (Exception $e) {
    $hasProjectPageAtIdsJson = false;
}
try {
    $colStmt = $db->query("SHOW COLUMNS FROM project_pages LIKE 'ft_tester_ids'");
    $hasProjectPageFtIdsJson = ($colStmt && $colStmt->rowCount() > 0);
} catch (Exception $e) {
    $hasProjectPageFtIdsJson = false;
}

$jsonUniqueMembershipSql = '';
$jsonUniqueMembershipParams = [];
if ($hasProjectPageAtIdsJson) {
    $jsonUniqueMembershipSql .= " OR JSON_CONTAINS(COALESCE(up.at_tester_ids, JSON_ARRAY()), JSON_ARRAY(CAST(? AS UNSIGNED)))";
    $jsonUniqueMembershipParams[] = $userId;
}
if ($hasProjectPageFtIdsJson) {
    $jsonUniqueMembershipSql .= " OR JSON_CONTAINS(COALESCE(up.ft_tester_ids, JSON_ARRAY()), JSON_ARRAY(CAST(? AS UNSIGNED)))";
    $jsonUniqueMembershipParams[] = $userId;
}

$projectsSql = "
    SELECT DISTINCT
        p.id,
        p.title,
        p.po_number,
        ua.role
    FROM projects p
    LEFT JOIN user_assignments ua
        ON p.id = ua.project_id
       AND ua.user_id = ?
       AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    WHERE p.status NOT IN ('cancelled', 'archived')
      AND (
            ? = 1 -- Admin bypass
            OR ua.id IS NOT NULL
            OR p.project_lead_id = ?
            OR EXISTS (
                SELECT 1 FROM project_pages pp 
                WHERE pp.project_id = p.id 
                  AND (pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ?)
            )
            OR EXISTS (
                SELECT 1 FROM project_pages up 
                WHERE up.project_id = p.id 
                  AND (
                    up.at_tester_id = ? OR up.ft_tester_id = ? OR up.qa_id = ?
                    {$jsonUniqueMembershipSql}
                  )
            )
            OR EXISTS (
                SELECT 1 FROM project_pages pp2
                JOIN page_environments pe ON pe.page_id = pp2.id
                WHERE pp2.project_id = p.id
                  AND (pe.at_tester_id = ? OR pe.ft_tester_id = ? OR pe.qa_id = ?)
            )
            OR p.po_number = 'OFF-PROD-001'
      )
    ORDER BY (p.po_number = 'OFF-PROD-001') DESC, p.title
";

$projectsStmt = $db->prepare($projectsSql);
$projectParams = [
    $userId,
    $isAdmin ? 1 : 0, // Admin bypass
    $userId,
    $userId, $userId, $userId,
    $userId, $userId, $userId
];
if (!empty($jsonUniqueMembershipParams)) {
    $projectParams = array_merge($projectParams, $jsonUniqueMembershipParams);
}
$projectParams = array_merge($projectParams, [
    $userId, $userId, $userId
]);

$projectsStmt->execute($projectParams);
$assignedProjects = $projectsStmt->fetchAll();

$offProdProjectId = 0;
foreach ($assignedProjects as $p) {
    if (strcasecmp(trim((string)($p['po_number'] ?? '')), 'OFF-PROD-001') === 0) {
        $offProdProjectId = (int)$p['id'];
        break;
    }
}
if ($offProdProjectId <= 0) {
    $offProdProjectId = ensureOffProdProjectId($db, $userId);
}

// Note: If no projects assigned, ensure OFF-PROD is available.
// The SQL above handles it if OFF-PROD is 'in_progress'. 
// If OFF-PROD is not showing up for some reason (e.g. status), check the DB. 
// We inserted OFF-PROD with 'in_progress'.

$myDailyToast = $_SESSION['my_daily_status_toast'] ?? null;
if (isset($_SESSION['my_daily_status_toast'])) {
    unset($_SESSION['my_daily_status_toast']);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <script>
    window._dailyStatusConfig = {
        toastData: <?php echo json_encode($myDailyToast, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        isPast: <?php echo $isPastDateReadonly ? 'true' : 'false'; ?>,
        hasPending: <?php echo $hasPendingRequest ? 'true' : 'false'; ?>,
        hasApprovedAccess: <?php echo $hasApprovedEditAccess ? 'true' : 'false'; ?>,
        hasSubmittedPending: <?php echo $hasSubmittedPendingRequest ? 'true' : 'false'; ?>,
        isWaitingApproval: <?php echo $hasWaitingApprovalRequest ? 'true' : 'false'; ?>,
        editRequestStatus: <?php echo json_encode((string)($editReq['status'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        date: <?php echo json_encode($date, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        baseDir: <?php echo json_encode($baseDir, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        isAdmin: <?php echo $isAdmin ? 'true' : 'false'; ?>,
        today: <?php echo json_encode(date('Y-m-d'), JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        assignedProjects: <?php echo json_encode(array_values(array_map(static function ($p) { return ['id' => (int)($p['id'] ?? 0), 'title' => (string)($p['title'] ?? '')]; }, $assignedProjects ?? [])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>
    };
    </script>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Daily Status & Time Log</h2>
        <div>
            <input type="date" class="form-control" value="<?php echo $date; ?>" 
                   onchange="window.location.href='?date='+this.value">
        </div>
    </div>

    <div class="row">
        <!-- Status Section -->
        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-header bg-info text-dark">
                    <h5 class="mb-0">My Status (<?php echo date('M d', strtotime($date)); ?>)</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="statusForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <div class="mb-3">
                            <label>Availability</label>
                            <select name="status" class="form-select" id="statusSelect" <?php echo $isPastDateReadonly ? 'disabled' : ''; ?>>
                                <?php foreach ($availabilityStatuses as $st): ?>
                                    <?php $stKey = (string)($st['status_key'] ?? ''); ?>
                                    <?php if ($stKey === '') continue; ?>
                                    <option value="<?php echo htmlspecialchars($stKey); ?>" <?php echo (($currentStatus['status'] ?? '') === $stKey) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string)($st['status_label'] ?? ucfirst(str_replace('_', ' ', $stKey)))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2" id="notesField" <?php echo $isPastDateReadonly ? 'disabled' : ''; ?>><?php echo htmlspecialchars($currentStatus['notes'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3" id="personalNoteContainer" style="display: <?php echo $isPastDateReadonly ? 'none' : 'block'; ?>;">
                            <label>Personal Note (private)</label>
                            <textarea name="personal_note" id="personal_note" class="form-control" rows="2"><?php echo htmlspecialchars($personalNote); ?></textarea>
                        </div>

                        <?php if ($isPastDateReadonly): ?>
                            <?php if ($hasSubmittedPendingRequest): ?>
                                <div class="alert alert-warning">Pending changes for this date are submitted and waiting for admin review.</div>
                            <?php elseif ($hasApprovedEditAccess): ?>
                                <div class="alert alert-success">Edit access is approved for this date. You can add multiple changes and then submit them together for final review.</div>
                            <?php elseif ($hasWaitingApprovalRequest): ?>
                                <div class="alert alert-info">Edit access request is pending admin approval for this date.</div>
                            <?php else: ?>
                                <div class="alert alert-secondary">This date is read-only. Click <button type="button" id="editToggleBtn" class="btn btn-sm btn-outline-primary">Request Edit Access</button> to ask admin for access.</div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <button type="submit" name="update_status" id="updateStatusBtn" class="btn btn-info text-dark w-100" style="<?php echo $isPastDateReadonly ? 'display:none;' : ''; ?>">Update Status</button>
                        <button type="button" id="saveRequestBtn" class="btn btn-warning text-dark w-100" style="display:<?php echo ($isPastDateReadonly && $hasApprovedEditAccess) ? 'block' : 'none'; ?>;">Save Pending Changes</button>
                        <button type="button" id="submitPendingBtn" class="btn btn-primary w-100 mt-2" style="display:<?php echo ($isPastDateReadonly && $hasApprovedEditAccess) ? 'block' : 'none'; ?>;">Submit Pending Changes</button>
                    </form>
                </div>
            </div>
    
            <?php if ($personalNote): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0">Your Personal Note for <?php echo date('M d, Y', strtotime($date)); ?></h6>
                </div>
                <div class="card-body">
                    <p><?php echo nl2br(htmlspecialchars($personalNote)); ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Time Summary</h5>
                </div>
                <div class="card-body">
                    <?php
                    $total = 0;
                    $utilized = 0;
                    foreach ($logs as $l) {
                        $total += $l['hours_spent'];
                        if ($l['is_utilized']) $utilized += $l['hours_spent'];
                    }
                    ?>
                    <h3 class="text-center"><?php echo $total; ?> hrs</h3>
                    <div class="progress mb-2">
                        <?php 
                        $utilPct = $total > 0 ? ($utilized / $total) * 100 : 0;
                        ?>
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $utilPct; ?>%">
                            Utilized
                        </div>
                        <div class="progress-bar bg-secondary" role="progressbar" style="width: <?php echo 100 - $utilPct; ?>%">
                            Bench/Off
                        </div>
                    </div>
                    <p class="text-center small text-muted">
                        Utilized: <?php echo $utilized; ?>h | Off-Prod: <?php echo $total - $utilized; ?>h
                    </p>
                </div>
            </div>
        </div>

        <!-- Time Logs Section -->
        <div class="col-md-8">
            <!-- Production Hours Section -->
            <div class="card mb-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Log Production Hours</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row align-items-end mb-4" id="logProductionHoursForm">
                        <input type="hidden" name="log_request_token" value="<?php echo htmlspecialchars($productionLogRequestToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="col-md-3">
                            <label>Project</label>
                            <select name="project_id" class="form-select" required>
                                <option value="">Select Project</option>
                                <?php foreach ($assignedProjects as $p): ?>
                                    <?php if ($p['po_number'] !== 'OFF-PROD-001'): ?>
                                    <option value="<?php echo $p['id']; ?>">
                                        <?php echo $p['title']; ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Task Type</label>
                            <select name="task_type" id="taskTypeSelect" class="form-select" required>
                                <option value="">Select Task Type</option>
                                <option value="page_testing">Page Testing</option>
                                <option value="page_qa">Page QA</option>
                                <option value="regression_testing">Regression Testing</option>
                                <option value="project_phase">Project Phase</option>
                                <option value="generic_task">Generic Task</option>
                            </select>
                        </div>
                        
                        <!-- Page Testing Options -->
                        <div class="col-md-12 mt-2" id="pageTestingContainer" style="display:none;">
                            <div class="row">
                                <div class="col-md-4">
                                    <label>Page/Screen (Multiple)</label>
                                    <select name="page_ids[]" id="productionPageSelect" class="form-select" multiple size="4">
                                        <option value="">Select pages</option>
                                    </select>
                                    <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                                </div>
                                <div class="col-md-4">
                                    <label>Environments (Multiple)</label>
                                    <select name="environment_ids[]" id="productionEnvSelect" class="form-select" multiple size="3">
                                        <option value="">Select environments</option>
                                    </select>
                                    <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                                </div>
                                <div class="col-md-4">
                                    <label>Testing Type</label>
                                    <select name="testing_type" id="testingTypeSelect" class="form-select">
                                        <option value="at_testing">AT Testing</option>
                                        <option value="ft_testing">FT Testing</option>
                                    </select>
                                </div>
                                <div class="col-md-4" id="productionIssueContainer" style="display:none;">
                                    <label>Issue (optional)</label>
                                    <select name="issue_id" id="productionIssueSelect" class="form-select">
                                        <option value="">Select issue (optional)</option>
                                    </select>
                                    <small class="text-muted">Select an issue when logging regression hours</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Regression Options -->
                        <div class="col-md-12 mt-2" id="regressionContainer" style="display:none;">
                            <div class="row">
                                <div class="col-md-12">
                                    <label>Regression Summary</label>
                                    <div id="regressionSummary" class="border rounded p-2">
                                        Loading…
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Project Phase Options -->
                        <div class="col-md-12 mt-2" id="projectPhaseContainer" style="display:none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <label>Project Phase</label>
                                    <select name="phase_id" id="projectPhaseSelect" class="form-select">
                                        <option value="">Select project phase</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label>Phase Activity</label>
                                    <select name="phase_activity" class="form-select">
                                        <option value="scoping">Scoping & Analysis</option>
                                        <option value="setup">Setup & Configuration</option>
                                        <option value="testing">Testing Activities</option>
                                        <option value="review">Review & Documentation</option>
                                        <option value="training">Training & Knowledge Transfer</option>
                                        <option value="reporting">Reporting & VPAT</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Generic Task Options -->
                        <div class="col-md-12 mt-2" id="genericTaskContainer" style="display:none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <label>Task Category</label>
                                    <select name="generic_category_id" id="genericCategorySelect" class="form-select">
                                        <option value="">Select category</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label>Task Details</label>
                                    <input type="text" name="generic_task_detail" class="form-control" placeholder="Specific task details">
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-2 mt-2">
                            <label>Hours</label>
                            <input type="number" id="logHoursInput" name="hours_spent" class="form-control" step="0.01" min="0.01" max="24" required <?php echo $isPastDateReadonly ? 'disabled' : ''; ?> >
                        </div>
                        <div class="col-md-4 mt-2">
                            <label>Description</label>
                            <input type="text" id="logDescriptionInput" name="description" class="form-control" placeholder="What did you work on?" required <?php echo $isPastDateReadonly ? 'disabled' : ''; ?> >
                        </div>
                        <div class="col-md-2 mt-2 d-grid">
                            <button type="submit" id="logTimeBtn" name="log_time" class="btn btn-success w-100" <?php echo $isPastDateReadonly ? 'disabled' : ''; ?>>Log Hours</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Off-Production/Bench Hours Section -->
            <div class="card mb-3">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Log Off-Production/Bench Hours</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row align-items-end mb-4" id="logBenchHoursForm">
                        <input type="hidden" name="log_request_token" value="<?php echo htmlspecialchars($benchLogRequestToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="project_id" value="<?php echo (int)$offProdProjectId; ?>">
                        <div class="col-md-4">
                            <label>Activity Type</label>
                            <select name="bench_activity" class="form-select" required>
                                <option value="">Select Activity</option>
                                <option value="training">Training</option>
                                <option value="learning">Learning/Research</option>
                                <option value="documentation">Documentation</option>
                                <option value="meetings">Meetings</option>
                                <option value="admin">Administrative Tasks</option>
                                <option value="waiting">Waiting for Assignment</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Hours</label>
                            <input type="number" name="hours_spent" class="form-control" step="0.01" min="0.01" max="24" required>
                        </div>
                        <div class="col-md-4">
                            <label>Description</label>
                            <input type="text" name="description" class="form-control" placeholder="Describe the activity" required>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" name="log_time" class="btn btn-secondary w-100">Log</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Logged Hours Display -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        Logged <?php echo $isViewingToday ? 'Today' : 'for ' . htmlspecialchars($selectedDateLabel); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Production Hours -->
                    <?php 
                    $productionLogs = array_filter($logs, function($log) { 
                        return (int)($log['is_utilized'] ?? 1) === 1;
                    });
                    $benchLogs = array_filter($logs, function($log) { 
                        return (int)($log['is_utilized'] ?? 1) === 0;
                    });
                    ?>
                    
                    <?php if (!empty($productionLogs)): ?>
                    <h6 class="text-success">Production Hours</h6>
                    <table class="table table-striped table-sm mb-4">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Page/Task</th>
                                <th>Environment</th>
                                <th>Description</th>
                                <th>Hours</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productionLogs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['title']); ?></td>
                                <td>
                                    <?php 
                                    if ($log['page_id']) {
                                        // Get page name
                                        $pageStmt = $db->prepare("SELECT page_name FROM project_pages WHERE id = ?");
                                        $pageStmt->execute([$log['page_id']]);
                                        $pageName = $pageStmt->fetchColumn();
                                        echo htmlspecialchars($pageName ?: 'Page #' . $log['page_id']);
                                        
                                        // Check if this is part of multiple pages (same description and time)
                                        $multiPageStmt = $db->prepare("
                                            SELECT COUNT(*) as count, GROUP_CONCAT(pp.page_name SEPARATOR ', ') as page_names
                                            FROM project_time_logs ptl 
                                            JOIN project_pages pp ON ptl.page_id = pp.id
                                            WHERE ptl.user_id = ? AND ptl.log_date = ? AND ptl.description = ? AND ptl.hours_spent = ?
                                        ");
                                        $multiPageStmt->execute([$userId, $date, $log['description'], $log['hours_spent']]);
                                        $multiPageResult = $multiPageStmt->fetch();
                                        
                                        if ($multiPageResult['count'] > 1) {
                                            echo '<br><small class="text-muted">+ ' . ($multiPageResult['count'] - 1) . ' more pages</small>';
                                        }
                                    } else {
                                        // Check if it's a project phase or generic task
                                        $desc = $log['description'];
                                        if (strpos($desc, 'Phase:') !== false || strpos($desc, 'Scoping') !== false || strpos($desc, 'Training') !== false) {
                                            echo '<em>Project Phase</em>';
                                        } elseif (strpos($desc, 'Generic:') !== false) {
                                            echo '<em>Generic Task</em>';
                                        } else {
                                            echo '<em>General</em>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($log['environment_id']) {
                                        $envStmt = $db->prepare("SELECT name FROM testing_environments WHERE id = ?");
                                        $envStmt->execute([$log['environment_id']]);
                                        $envName = $envStmt->fetchColumn();
                                        echo htmlspecialchars($envName ?: 'Env #' . $log['environment_id']);
                                    } else {
                                        echo '<em>N/A</em>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $desc = htmlspecialchars($log['description']);
                                    // Truncate long descriptions
                                    if (strlen($desc) > 50) {
                                        echo substr($desc, 0, 50) . '...';
                                    } else {
                                        echo $desc;
                                    }
                                    ?>
                                </td>
                                <td><span class="badge bg-success"><?php echo $log['hours_spent']; ?>h</span></td>
                                <td>
                                    <?php if (in_array((int)$log['id'], $pendingEditLogIds, true)): ?>
                                    <span class="text-info small fw-semibold">Waiting for edit approval</span>
                                    <?php elseif (in_array((int)$log['id'], $pendingDeletionLogIds, true)): ?>
                                    <span class="text-warning small fw-semibold">Waiting for deletion approval</span>
                                    <?php else: ?>
                                      <a href="javascript:void(0)"
                                       class="text-primary me-2" onclick="return handleEditLogRequest(<?php echo (int)$log['id']; ?>, '<?php echo $date; ?>', <?php echo htmlspecialchars(json_encode([
                                           'project_id' => (int)($log['project_id'] ?? 0),
                                           'task_type' => (string)($log['task_type'] ?? 'other'),
                                           'page_id' => isset($log['page_id']) ? (int)$log['page_id'] : null,
                                           'environment_id' => isset($log['environment_id']) ? (int)$log['environment_id'] : null,
                                           'issue_id' => isset($log['issue_id']) ? (int)$log['issue_id'] : null,
                                           'phase_id' => isset($log['phase_id']) ? (int)$log['phase_id'] : null,
                                           'generic_category_id' => isset($log['generic_category_id']) ? (int)$log['generic_category_id'] : null,
                                           'testing_type' => (string)($log['testing_type'] ?? ''),
                                           'hours_spent' => (float)($log['hours_spent'] ?? 0),
                                           'description' => (string)($log['description'] ?? ''),
                                           'is_utilized' => isset($log['is_utilized']) ? (int)$log['is_utilized'] : 1
                                       ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="javascript:void(0)" 
                                       class="text-danger" onclick="return handleDeleteLog(<?php echo $log['id']; ?>, '<?php echo $date; ?>')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if (!empty($benchLogs)): ?>
                    <h6 class="text-secondary">Off-Production/Bench Hours</h6>
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Activity</th>
                                <th>Description</th>
                                <th>Hours</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($benchLogs as $log): ?>
                            <tr>
                                <td>
                                    <?php 
                                    // Extract activity type from description or show generic
                                    $desc = $log['description'];
                                    $activityTypes = ['training', 'learning', 'documentation', 'meetings', 'admin', 'waiting', 'other'];
                                    $activity = 'General';
                                    foreach ($activityTypes as $type) {
                                        if (stripos($desc, $type) !== false) {
                                            $activity = ucfirst($type);
                                            break;
                                        }
                                    }
                                    echo htmlspecialchars($activity);
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['description']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo $log['hours_spent']; ?>h</span></td>
                                <td>
                                    <?php if (in_array((int)$log['id'], $pendingEditLogIds, true)): ?>
                                    <span class="text-info small fw-semibold">Waiting for edit approval</span>
                                    <?php elseif (in_array((int)$log['id'], $pendingDeletionLogIds, true)): ?>
                                    <span class="text-warning small fw-semibold">Waiting for deletion approval</span>
                                    <?php else: ?>
                                    <a href="javascript:void(0)"
                                       class="text-primary me-2" onclick="return handleEditLogRequest(<?php echo (int)$log['id']; ?>, '<?php echo $date; ?>', <?php echo htmlspecialchars(json_encode([
                                           'project_id' => (int)($log['project_id'] ?? 0),
                                           'task_type' => (string)($log['task_type'] ?? 'other'),
                                           'page_id' => isset($log['page_id']) ? (int)$log['page_id'] : null,
                                           'environment_id' => isset($log['environment_id']) ? (int)$log['environment_id'] : null,
                                           'issue_id' => isset($log['issue_id']) ? (int)$log['issue_id'] : null,
                                           'phase_id' => isset($log['phase_id']) ? (int)$log['phase_id'] : null,
                                           'generic_category_id' => isset($log['generic_category_id']) ? (int)$log['generic_category_id'] : null,
                                           'testing_type' => (string)($log['testing_type'] ?? ''),
                                           'hours_spent' => (float)($log['hours_spent'] ?? 0),
                                           'description' => (string)($log['description'] ?? ''),
                                           'is_utilized' => isset($log['is_utilized']) ? (int)$log['is_utilized'] : 1
                                       ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="javascript:void(0)" 
                                       class="text-danger" onclick="return handleDeleteLog(<?php echo $log['id']; ?>, '<?php echo $date; ?>')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if (empty($logs)): ?>
                    <p class="text-muted text-center">
                        No hours logged <?php echo $isViewingToday ? 'for today' : 'for ' . htmlspecialchars($selectedDateLabel); ?>.
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/my-daily-status.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/my-daily-status.js'); ?>"></script>

