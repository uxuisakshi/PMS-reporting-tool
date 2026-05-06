<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';
require_once __DIR__ . '/../includes/client_issue_snapshots.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Please login to access this resource']);
    exit;
}

// CSRF protection for state-changing requests
enforceApiCsrf();

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError($message, $statusCode = 400) {
    jsonResponse(['error' => $message], $statusCode);
}

function getStatusId($db, $name) {
    if (!$name) return null;
    $map = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed'
    ];
    $target = $map[strtolower($name)] ?? $name;
    $stmt = $db->prepare("SELECT id FROM issue_statuses WHERE name = ? LIMIT 1");
    $stmt->execute([$target]);
    $id = $stmt->fetchColumn();
    return $id ?: null;
}

function parseMentionsInput($value) {
    if ($value === null || $value === '') return [];
    if (is_array($value)) {
        return array_values(array_unique(array_filter(array_map('intval', $value), function ($v) { return $v > 0; })));
    }
    $raw = trim((string)$value);
    if ($raw === '') return [];
    if ($raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_unique(array_filter(array_map('intval', $decoded), function ($v) { return $v > 0; })));
        }
    }
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), function ($v) { return $v > 0; })));
}

function issueCommentsHasColumn($db, $name) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM issue_comments LIKE " . $db->quote($name));
        return $stmt && $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function ensureIssueCommentAuditSchema($db) {
    try {
        if (!issueCommentsHasColumn($db, 'edited_at')) {
            $db->exec("ALTER TABLE issue_comments ADD COLUMN edited_at DATETIME NULL");
        }
        if (!issueCommentsHasColumn($db, 'deleted_at')) {
            $db->exec("ALTER TABLE issue_comments ADD COLUMN deleted_at DATETIME NULL");
        }
        if (!issueCommentsHasColumn($db, 'deleted_by')) {
            $db->exec("ALTER TABLE issue_comments ADD COLUMN deleted_by INT NULL");
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS issue_comment_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                comment_id INT NOT NULL,
                action_type ENUM('edit','delete') NOT NULL,
                old_comment_html MEDIUMTEXT NULL,
                new_comment_html MEDIUMTEXT NULL,
                acted_by INT NOT NULL,
                acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_comment_id (comment_id),
                INDEX idx_acted_at (acted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
    }
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$role = (string)($_SESSION['role'] ?? '');
$isAdmin = in_array($role, ['admin'], true);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$issueId = (int)($_GET['issue_id'] ?? $_POST['issue_id'] ?? 0);

if (!$projectId) jsonError('project_id required', 400);
if (!$issueId) jsonError('issue_id required', 400);
if (!hasProjectAccess($db, $userId, $projectId)) jsonError('Permission denied', 403);

$chk = $db->prepare("SELECT client_ready FROM issues WHERE id = ? AND project_id = ? LIMIT 1");
$chk->execute([$issueId, $projectId]);
$issueRow = $chk->fetch(PDO::FETCH_ASSOC);
if (!$issueRow) jsonError('Invalid issue for project', 404);
$isClientLiveVisible = (int)($issueRow['client_ready'] ?? 0) === 1;
$clientSnapshot = null;
if ($role === 'client' && !$isClientLiveVisible) {
    $clientSnapshot = getIssueClientSnapshot($db, $issueId);
    if (!$clientSnapshot || (int)($clientSnapshot['project_id'] ?? 0) !== $projectId) {
        jsonError('Permission denied', 403);
    }
}

try {
    if ($method === 'GET' && $action === 'list') {
        ensureIssueCommentAuditSchema($db);

        $hasCommentType = issueCommentsHasColumn($db, 'comment_type');
        $hasEditedAt = issueCommentsHasColumn($db, 'edited_at');
        $hasDeletedAt = issueCommentsHasColumn($db, 'deleted_at');

        $typeField = $hasCommentType ? 'ic.comment_type' : "'normal' AS comment_type";
        $editedAtField = $hasEditedAt ? 'ic.edited_at' : 'NULL AS edited_at';
        $deletedAtField = $hasDeletedAt ? 'ic.deleted_at' : 'NULL AS deleted_at';

        $sql = "SELECT ic.*, $typeField, $editedAtField, $deletedAtField,
                       u.full_name AS user_name,
                       r.full_name AS recipient_name,
                       s.name AS qa_status_name
                FROM issue_comments ic
                JOIN users u ON ic.user_id = u.id
                LEFT JOIN users r ON ic.recipient_id = r.id
                LEFT JOIN issue_statuses s ON ic.qa_status_id = s.id
            WHERE ic.issue_id = ?";

        $params = [$issueId];
        if ($role === 'client' && !$isClientLiveVisible && !empty($clientSnapshot['published_at'])) {
            $sql .= " AND ic.created_at <= ?";
            $params[] = (string) $clientSnapshot['published_at'];
        }
        $sql .= " ORDER BY ic.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $isOwn = ((int)$row['user_id'] === (int)$userId);
            $isDeleted = !empty($row['deleted_at']);
            $row['can_edit'] = (!$isDeleted && ($isOwn || $isAdmin));
            $row['can_delete'] = (!$isDeleted && ($isOwn || $isAdmin));
            $row['can_view_history'] = $isAdmin;

            if ($role === 'client' && function_exists('rewrite_html_public_image_urls')) {
                $row['comment_html'] = rewrite_html_public_image_urls((string)($row['comment_html'] ?? ''));
            }

            if (!empty($row['reply_to'])) {
                $replyStmt = $db->prepare("SELECT ic.id, ic.comment_html, u.full_name AS user_name
                                          FROM issue_comments ic
                                          JOIN users u ON ic.user_id = u.id
                                          WHERE ic.id = ? LIMIT 1");
                $replyStmt->execute([$row['reply_to']]);
                $replyData = $replyStmt->fetch(PDO::FETCH_ASSOC);
                if ($replyData) {
                    $replyPreviewHtml = (string)($replyData['comment_html'] ?? '');
                    if ($role === 'client' && function_exists('rewrite_html_public_image_urls')) {
                        $replyPreviewHtml = rewrite_html_public_image_urls($replyPreviewHtml);
                    }
                    $row['reply_preview'] = [
                        'id' => $replyData['id'],
                        'user_name' => $replyData['user_name'],
                        'text' => $replyPreviewHtml
                    ];
                }
            }
        }

        if ($role === 'client') {
            $rows = array_values(array_filter($rows, static function ($row) {
                return (string)($row['comment_type'] ?? 'normal') === 'regression';
            }));
        }

        jsonResponse(['success' => true, 'comments' => $rows]);
    }

    if ($method === 'POST' && $action === 'create') {
        if ($role === 'client' && !$isClientLiveVisible) jsonError('Issue is under internal review. Client comments are temporarily read-only until the updated issue is approved.', 403);
        ensureIssueCommentAuditSchema($db);

        $commentHtml = $_POST['comment_html'] ?? '';
        $commentType = $_POST['comment_type'] ?? 'normal';
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $replyTo = (int)($_POST['reply_to'] ?? 0);
        $mentions = parseMentionsInput($_POST['mentions'] ?? []);
        $qaStatusRaw = $_POST['qa_status_id'] ?? '';
        $qaStatusId = is_numeric($qaStatusRaw) ? (int)$qaStatusRaw : (int)(getStatusId($db, $qaStatusRaw) ?: 0);
        if (!$commentHtml) jsonError('comment_html required', 400);

        if ($role === 'client') {
            $commentType = 'regression';
            $qaStatusId = 0;
        }

        if (!in_array($commentType, ['normal', 'regression'], true)) {
            $commentType = 'normal';
        }

        if (!function_exists('sanitize_chat_html')) {
            jsonError('Server configuration error: sanitizer unavailable', 500);
        }
        $clean = sanitize_chat_html($commentHtml);

        try {
            $stmt = $db->prepare("INSERT INTO issue_comments (issue_id, user_id, recipient_id, qa_status_id, comment_html, comment_type, reply_to) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$issueId, $userId, $recipientId ?: null, $qaStatusId ?: null, $clean, $commentType, $replyTo ?: null]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'comment_type') !== false) {
                $stmt = $db->prepare("INSERT INTO issue_comments (issue_id, user_id, recipient_id, qa_status_id, comment_html, reply_to) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$issueId, $userId, $recipientId ?: null, $qaStatusId ?: null, $clean, $replyTo ?: null]);
            } else {
                throw $e;
            }
        }

        $notifyUserIds = $mentions;
        if ($recipientId > 0) $notifyUserIds[] = $recipientId;
        $notifyUserIds = array_values(array_unique(array_filter(array_map('intval', $notifyUserIds), function ($id) use ($userId) {
            return $id > 0 && $id !== (int)$userId;
        })));

        if (!empty($notifyUserIds)) {
            $senderName = trim((string)($_SESSION['full_name'] ?? 'A user'));
            $baseDir = getBaseDir();
            $pageIdForIssue = 0;
            $issueKey = '';
            try {
                $pageStmt = $db->prepare("SELECT page_id, issue_key FROM issues WHERE id = ? AND project_id = ? LIMIT 1");
                $pageStmt->execute([(int)$issueId, (int)$projectId]);
                $issueData = $pageStmt->fetch(PDO::FETCH_ASSOC);
                if ($issueData) {
                    $pageIdForIssue = (int)($issueData['page_id'] ?: 0);
                    $issueKey = $issueData['issue_key'] ?: '';
                }
            } catch (Exception $e) {
                $pageIdForIssue = 0;
                $issueKey = '';
            }
            if ($pageIdForIssue > 0) {
                $link = $baseDir . '/modules/projects/issues_page_detail.php?project_id=' . (int)$projectId . '&page_id=' . $pageIdForIssue . '&issue_id=' . (int)$issueId;
            } else {
                $link = $baseDir . '/modules/projects/issues.php?project_id=' . (int)$projectId . '&issue_id=' . (int)$issueId;
            }
            
            $notificationMsg = $senderName . ' mentioned you in an issue comment';
            if ($issueKey) {
                $notificationMsg .= ' (' . $issueKey . ')';
            }
            $notificationMsg .= '.';
            
            foreach ($notifyUserIds as $targetUserId) {
                createNotification(
                    $db,
                    (int)$targetUserId,
                    'mention',
                    $notificationMsg,
                    $link
                );
            }
        }

        $newId = (int)$db->lastInsertId();
        $stmt = $db->prepare("SELECT ic.*, u.full_name AS user_name, r.full_name AS recipient_name, s.name AS qa_status_name
                              FROM issue_comments ic
                              JOIN users u ON ic.user_id = u.id
                              LEFT JOIN users r ON ic.recipient_id = r.id
                              LEFT JOIN issue_statuses s ON ic.qa_status_id = s.id
                              WHERE ic.id = ?
                              LIMIT 1");
        $stmt->execute([$newId]);
        $created = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($created) {
            $created['can_edit'] = true;
            $created['can_delete'] = true;
            $created['can_view_history'] = $isAdmin;
        }

        jsonResponse(['success' => true, 'id' => $newId, 'comment' => $created]);
    }

    if ($method === 'POST' && $action === 'edit') {
        if ($role === 'client' && !$isClientLiveVisible) jsonError('Issue is under internal review. Client comments are temporarily read-only until the updated issue is approved.', 403);
        ensureIssueCommentAuditSchema($db);

        $commentId = (int)($_POST['comment_id'] ?? 0);
        $commentHtml = trim((string)($_POST['comment_html'] ?? ''));
        if ($commentId <= 0) jsonError('comment_id required', 400);
        if ($commentHtml === '') jsonError('comment_html required', 400);

        $stmt = $db->prepare("SELECT * FROM issue_comments WHERE id = ? AND issue_id = ? LIMIT 1");
        $stmt->execute([$commentId, $issueId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonError('Comment not found', 404);

        $isOwn = ((int)$row['user_id'] === (int)$userId);
        if (!$isOwn && !$isAdmin) jsonError('Permission denied', 403);
        if (!empty($row['deleted_at'])) jsonError('Deleted comment cannot be edited', 400);

        if (!function_exists('sanitize_chat_html')) {
            jsonError('Server configuration error: sanitizer unavailable', 500);
        }
        $clean = sanitize_chat_html($commentHtml);
        $upd = $db->prepare("UPDATE issue_comments SET comment_html = ?, edited_at = NOW() WHERE id = ?");
        if (!$upd->execute([$clean, $commentId])) jsonError('Failed to edit comment', 500);

        try {
            $h = $db->prepare("INSERT INTO issue_comment_history (comment_id, action_type, old_comment_html, new_comment_html, acted_by) VALUES (?, 'edit', ?, ?, ?)");
            $h->execute([$commentId, $row['comment_html'] ?? '', $clean, $userId]);
        } catch (Exception $e) {
        }

        $stmt = $db->prepare("SELECT ic.*, u.full_name AS user_name, r.full_name AS recipient_name, s.name AS qa_status_name
                              FROM issue_comments ic
                              JOIN users u ON ic.user_id = u.id
                              LEFT JOIN users r ON ic.recipient_id = r.id
                              LEFT JOIN issue_statuses s ON ic.qa_status_id = s.id
                              WHERE ic.id = ?
                              LIMIT 1");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($comment) {
            $comment['can_edit'] = true;
            $comment['can_delete'] = true;
            $comment['can_view_history'] = $isAdmin;
        }

        jsonResponse(['success' => true, 'comment' => $comment]);
    }

    if ($method === 'POST' && $action === 'delete') {
        if ($role === 'client' && !$isClientLiveVisible) jsonError('Issue is under internal review. Client comments are temporarily read-only until the updated issue is approved.', 403);
        ensureIssueCommentAuditSchema($db);

        $commentId = (int)($_POST['comment_id'] ?? 0);
        if ($commentId <= 0) jsonError('comment_id required', 400);

        $stmt = $db->prepare("SELECT * FROM issue_comments WHERE id = ? AND issue_id = ? LIMIT 1");
        $stmt->execute([$commentId, $issueId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonError('Comment not found', 404);

        $isOwn = ((int)$row['user_id'] === (int)$userId);
        if (!$isOwn && !$isAdmin) jsonError('Permission denied', 403);
        if (!empty($row['deleted_at'])) {
            jsonResponse(['success' => true]);
        }

        $oldCommentHtml = (string)($row['comment_html'] ?? '');
        $deletedHtml = '<p><em>Comment deleted</em></p>';
        $upd = $db->prepare("UPDATE issue_comments SET comment_html = ?, deleted_at = NOW(), deleted_by = ? WHERE id = ?");
        if (!$upd->execute([$deletedHtml, $userId, $commentId])) jsonError('Failed to delete comment', 500);
        if (function_exists('delete_local_upload_files_from_html') && trim($oldCommentHtml) !== '') {
            delete_local_upload_files_from_html($oldCommentHtml, ['uploads/issues/', 'uploads/chat/']);
        }

        try {
            $h = $db->prepare("INSERT INTO issue_comment_history (comment_id, action_type, old_comment_html, new_comment_html, acted_by) VALUES (?, 'delete', ?, ?, ?)");
            $h->execute([$commentId, $row['comment_html'] ?? '', $deletedHtml, $userId]);
        } catch (Exception $e) {
        }

        $stmt = $db->prepare("SELECT ic.*, u.full_name AS user_name, r.full_name AS recipient_name, s.name AS qa_status_name
                              FROM issue_comments ic
                              JOIN users u ON ic.user_id = u.id
                              LEFT JOIN users r ON ic.recipient_id = r.id
                              LEFT JOIN issue_statuses s ON ic.qa_status_id = s.id
                              WHERE ic.id = ?
                              LIMIT 1");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($comment) {
            $comment['can_edit'] = false;
            $comment['can_delete'] = false;
            $comment['can_view_history'] = $isAdmin;
        }

        jsonResponse(['success' => true, 'comment' => $comment]);
    }

    if ($method === 'GET' && $action === 'history') {
        ensureIssueCommentAuditSchema($db);
        if (!$isAdmin) jsonError('Permission denied', 403);

        $commentId = (int)($_GET['comment_id'] ?? 0);
        if ($commentId <= 0) jsonError('comment_id required', 400);

        $stmt = $db->prepare("SELECT id FROM issue_comments WHERE id = ? AND issue_id = ? LIMIT 1");
        $stmt->execute([$commentId, $issueId]);
        if (!$stmt->fetchColumn()) jsonError('Comment not found', 404);

        $h = $db->prepare("SELECT h.*, u.full_name AS acted_by_name
                           FROM issue_comment_history h
                           LEFT JOIN users u ON u.id = h.acted_by
                           WHERE h.comment_id = ?
                           ORDER BY h.acted_at DESC, h.id DESC");
        $h->execute([$commentId]);
        $rows = $h->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['success' => true, 'history' => $rows]);
    }

    jsonError('Unsupported action', 400);
} catch (Exception $e) {
    error_log('issue_comments error: ' . $e->getMessage());
    jsonError('An internal error occurred', 500);
}
