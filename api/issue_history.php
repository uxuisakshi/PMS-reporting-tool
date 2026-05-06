<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// Catch fatal errors and return JSON instead of empty body
register_shutdown_function(function () {
    $fatal = error_get_last();
    if (!$fatal) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (in_array((int)$fatal['type'], $fatalTypes, true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'Server error: ' . $fatal['message'] . ' in ' . basename($fatal['file']) . ':' . $fatal['line']]);
    }
});

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userRole = $auth->getUserRole() ?? '';
$userId   = (int)($auth->getUserId() ?? 0);
$isAdmin  = in_array($userRole, ['admin', 'superadmin'], true);

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// Auto-create issue_history table if it doesn't exist (handles production deployments)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `issue_history` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `issue_id` int(11) NOT NULL,
        `user_id` int(11) NOT NULL,
        `field_name` varchar(100) NOT NULL,
        `old_value` longtext DEFAULT NULL,
        `new_value` longtext DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `issue_id` (`issue_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Non-fatal: table may already exist or user lacks CREATE privilege
    error_log('issue_history table check failed: ' . $e->getMessage());
}

// ── GET: list history ────────────────────────────────────────────────────────
if ($method === 'GET') {
    $issueId = (int)($_GET['issue_id'] ?? 0);
    if (!$issueId) { echo json_encode(['error' => 'issue_id required']); exit; }

    try {
        $stmt = $db->prepare("
            SELECT h.*, COALESCE(u.full_name, 'Unknown User') as user_name
            FROM issue_history h
            LEFT JOIN users u ON h.user_id = u.id
            WHERE h.issue_id = ?
            ORDER BY h.created_at DESC
        ");
        $stmt->execute([$issueId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark each entry as rollback-able (admin only, and old_value must exist)
        foreach ($history as &$row) {
            $row['can_rollback'] = $isAdmin && $row['old_value'] !== null && $row['old_value'] !== '';
        }
        unset($row);

        echo json_encode(['success' => true, 'history' => $history]);
    } catch (Exception $e) {
        error_log('issue_history GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred']);
    }
    exit;
}

// ── POST: rollback ───────────────────────────────────────────────────────────
if ($method === 'POST') {
    enforceApiCsrf();
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }

    $historyId = (int)($_POST['history_id'] ?? 0);
    $issueId   = (int)($_POST['issue_id']   ?? 0);
    if (!$historyId || !$issueId) {
        echo json_encode(['error' => 'history_id and issue_id required']);
        exit;
    }

    try {
        // Fetch the history entry
        $hStmt = $db->prepare("SELECT * FROM issue_history WHERE id = ? AND issue_id = ?");
        $hStmt->execute([$historyId, $issueId]);
        $entry = $hStmt->fetch(PDO::FETCH_ASSOC);
        if (!$entry) {
            echo json_encode(['error' => 'History entry not found']);
            exit;
        }

        $fieldName  = $entry['field_name'];
        $restoreVal = $entry['old_value']; // value to restore

        $db->beginTransaction();

        if (strpos($fieldName, 'meta:') === 0) {
            // ── Meta field rollback ──────────────────────────────────────────
            $metaKey = substr($fieldName, 5);

            // Capture current value for history log
            $curStmt = $db->prepare("SELECT meta_value FROM issue_metadata WHERE issue_id = ? AND meta_key = ?");
            $curStmt->execute([$issueId, $metaKey]);
            $currentVals = $curStmt->fetchAll(PDO::FETCH_COLUMN);
            $currentValStr = implode(', ', $currentVals);

            // Delete existing meta rows for this key
            $db->prepare("DELETE FROM issue_metadata WHERE issue_id = ? AND meta_key = ?")->execute([$issueId, $metaKey]);

            // Re-insert old values (stored as comma-separated in history)
            if ($restoreVal !== null && $restoreVal !== '') {
                $parts = array_filter(array_map('trim', explode(',', $restoreVal)));
                $ins = $db->prepare("INSERT INTO issue_metadata (issue_id, meta_key, meta_value) VALUES (?, ?, ?)");
                foreach ($parts as $part) {
                    if ($part !== '') $ins->execute([$issueId, $metaKey, $part]);
                }
            }

            // Log the rollback
            $db->prepare("INSERT INTO issue_history (issue_id, user_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)")
               ->execute([$issueId, $userId, $fieldName, $currentValStr, $restoreVal]);

        } else {
            // ── Direct issue column rollback ─────────────────────────────────
            $allowed = ['title', 'description', 'severity', 'common_issue_title', 'client_ready', 'assignee_id'];
            if (!in_array($fieldName, $allowed, true)) {
                $db->rollBack();
                echo json_encode(['error' => 'Field cannot be rolled back']);
                exit;
            }

            // Capture current value
            $curStmt = $db->prepare("SELECT `$fieldName` FROM issues WHERE id = ?");
            $curStmt->execute([$issueId]);
            $currentVal = $curStmt->fetchColumn();

            // Apply rollback
            $db->prepare("UPDATE issues SET `$fieldName` = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$restoreVal ?: null, $issueId]);

            // Log the rollback
            $db->prepare("INSERT INTO issue_history (issue_id, user_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)")
               ->execute([$issueId, $userId, $fieldName, $currentVal, $restoreVal]);
        }

        // Invalidate APCu cache
        $projStmt = $db->prepare("SELECT project_id FROM issues WHERE id = ?");
        $projStmt->execute([$issueId]);
        $projectId = (int)$projStmt->fetchColumn();
        if ($projectId && function_exists('apcu_delete')) {
            apcu_delete("issues_all_{$projectId}_staff");
            apcu_delete("issues_all_{$projectId}_client");
        }

        $db->commit();
        echo json_encode(['success' => true, 'field' => $fieldName, 'restored_to' => $restoreVal]);

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('issue_history POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred']);
    }
    exit;
}

echo json_encode(['error' => 'Invalid request']);
