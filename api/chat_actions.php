<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// CSRF protection for state-changing requests
enforceApiCsrf();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$sessionRole = normalizeRole($_SESSION['role'] ?? '');

if ($sessionRole === 'client') {
    echo json_encode(['error' => 'Project chat is not available for client accounts.']);
    exit;
}

header('Content-Type: application/json');

function normalizeRole($role) {
    $r = strtolower(trim((string)$role));
    $r = preg_replace('/[^a-z0-9]+/', '_', $r);
    $r = trim($r, '_');
    return $r;
}

function isAdminRole($role) {
    $r = normalizeRole($role);
    return in_array($r, ['admin'], true);
}

function currentUserIsAdmin($db, $userId, $sessionRole) {
    if (isAdminRole($sessionRole)) return true;
    try {
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$userId]);
        $dbRole = (string)($stmt->fetchColumn() ?: '');
        return isAdminRole($dbRole);
    } catch (Exception $e) {
        return false;
    }
}

function chatMessagesHasReplyTo($db) {
    static $hasColumn = null;
    if ($hasColumn !== null) return $hasColumn;
    try {
        $stmt = $db->query("SHOW COLUMNS FROM chat_messages LIKE 'reply_to'");
        $hasColumn = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $hasColumn = false;
    }
    return $hasColumn;
}

function chatMessagesHasColumn($db, $name) {
    static $cache = [];
    if (array_key_exists($name, $cache)) return $cache[$name];
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM chat_messages LIKE ?");
        $stmt->execute([$name]);
        $cache[$name] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cache[$name] = false;
    }
    return $cache[$name];
}

function isChatMessageDeletedRow($row) {
    $deletedAt = trim((string)($row['deleted_at'] ?? ''));
    if ($deletedAt !== '') return true;
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string)($row['message'] ?? ''))));
    return strcasecmp($plain, 'Message deleted') === 0;
}

function ensureChatAuditSchema($db) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!chatMessagesHasReplyTo($db)) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN reply_to INT NULL");
        }
    } catch (Exception $e) {}
    try {
        if (!chatMessagesHasColumn($db, 'edited_at')) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN edited_at DATETIME NULL");
        }
    } catch (Exception $e) {}
    try {
        if (!chatMessagesHasColumn($db, 'deleted_at')) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN deleted_at DATETIME NULL");
        }
    } catch (Exception $e) {}
    try {
        if (!chatMessagesHasColumn($db, 'deleted_by')) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN deleted_by INT NULL");
        }
    } catch (Exception $e) {}
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_message_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                action_type ENUM('edit','delete') NOT NULL,
                old_message MEDIUMTEXT NULL,
                new_message MEDIUMTEXT NULL,
                acted_by INT NOT NULL,
                acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_message_id (message_id),
                INDEX idx_acted_by (acted_by),
                INDEX idx_acted_at (acted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {}
}

switch ($action) {
    case 'send_message':
        // Removed rate limiting as per user request to allow unlimited messaging.


        ensureChatAuditSchema($db);
        $projectId = (isset($_POST['project_id']) && $_POST['project_id'] !== '' && $_POST['project_id'] !== 'null' && $_POST['project_id'] !== '0') ? intval($_POST['project_id']) : null;
        $pageId = (isset($_POST['page_id']) && $_POST['page_id'] !== '' && $_POST['page_id'] !== 'null' && $_POST['page_id'] !== '0') ? intval($_POST['page_id']) : null;

        // IDOR prevention: verify project access before sending
        if ($projectId && !hasProjectAccess($db, $userId, $projectId)) {
            echo json_encode(['error' => 'Permission denied']);
            exit;
        }
        $replyTo = (isset($_POST['reply_to']) && is_numeric($_POST['reply_to'])) ? intval($_POST['reply_to']) : null;
        if ($replyTo === null) {
            $replyToken = trim((string)($_POST['reply_token'] ?? ''));
            if (preg_match('/^r:(\d+)$/', $replyToken, $m)) {
                $replyTo = (int)$m[1];
            }
        }
        $message = trim($_POST['message'] ?? '');
        
        if (empty($message)) {
            echo json_encode(['error' => 'Message cannot be empty']);
            exit;
        }
        
        $mentions = [];
        preg_match_all('/@([A-Za-z0-9._-]+)/', $message, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $username) {
                $stmt = $db->prepare("SELECT id, full_name FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($user = $stmt->fetch()) {
                    $mentions[] = $user['id'];
                    
                    // Create notification for mentioned user
                    if ($user['id'] != $userId) {
                        $notifyMsg = $_SESSION['full_name'] . " mentioned you in a chat.";
                        $link = "/modules/chat/project_chat.php";
                        $params = [];
                        if ($projectId) $params[] = "project_id=" . $projectId;
                        if ($pageId) $params[] = "page_id=" . $pageId;
                        if (!empty($params)) $link .= "?" . implode("&", $params);
                        
                        createNotification($db, (int)$user['id'], 'mention', $notifyMsg, $link);
                    }
                }
            }
        }
        
        $stmt = null;
        $executed = false;
        try {
            $stmt = $db->prepare("INSERT INTO chat_messages (project_id, page_id, user_id, message, mentions, reply_to) VALUES (?, ?, ?, ?, ?, ?)");
            $executed = $stmt->execute([$projectId, $pageId, $userId, $message, json_encode($mentions), $replyTo]);
        } catch (Exception $e) {
            // Backward compatibility if reply_to column is unavailable for any reason.
            $stmt = $db->prepare("INSERT INTO chat_messages (project_id, page_id, user_id, message, mentions) VALUES (?, ?, ?, ?, ?)");
            $executed = $stmt->execute([$projectId, $pageId, $userId, $message, json_encode($mentions)]);
        }
        if ($executed) {
            $lastId = $db->lastInsertId();
            $msgStmt = $db->prepare("
                SELECT cm.*, u.username, u.full_name, u.role
                FROM chat_messages cm
                JOIN users u ON cm.user_id = u.id
                WHERE cm.id = ?
                LIMIT 1
            ");
            $msgStmt->execute([$lastId]);
            $created = $msgStmt->fetch(PDO::FETCH_ASSOC);
            if ($created) {
                $created['message'] = sanitize_chat_html($created['message'] ?? '');
                $created['message'] = rewrite_upload_urls_to_secure($created['message']);
                if (!empty($created['reply_to'])) {
                    try {
                        $pr = $db->prepare("SELECT cm.id, cm.user_id, cm.message, cm.created_at, u.username, u.full_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                        $pr->execute([(int)$created['reply_to']]);
                        $pRow = $pr->fetch(PDO::FETCH_ASSOC);
                        if ($pRow) {
                            $pRow['message'] = sanitize_chat_html($pRow['message'] ?? '');
                            $pRow['message'] = rewrite_upload_urls_to_secure($pRow['message']);
                            $created['reply_preview'] = [
                                'id' => $pRow['id'],
                                'user_id' => $pRow['user_id'],
                                'username' => $pRow['username'],
                                'full_name' => $pRow['full_name'],
                                'message' => $pRow['message'],
                                'created_at' => $pRow['created_at'] ?? null
                            ];
                        }
                    } catch (Exception $e) {
                    }
                }
                $isDeletedCreated = isChatMessageDeletedRow($created);
                $created['can_edit'] = !$isDeletedCreated;
                $created['can_delete'] = !$isDeletedCreated;
            }
            echo json_encode(['success' => true, 'id' => $lastId, 'message' => $created]);
        } else {
            $errorInfo = $stmt->errorInfo();
            $err = $errorInfo[2] ?? 'Unknown error';
            echo json_encode(['error' => 'Failed to save message: ' . $err]);
        }
        break;

    case 'fetch_messages':
        ensureChatAuditSchema($db);
        $isAdmin = currentUserIsAdmin($db, $userId, $_SESSION['role'] ?? '');
        $projectId = (isset($_GET['project_id']) && $_GET['project_id'] !== '' && $_GET['project_id'] !== 'null') ? intval($_GET['project_id']) : null;
        $pageId = (isset($_GET['page_id']) && $_GET['page_id'] !== '' && $_GET['page_id'] !== 'null') ? intval($_GET['page_id']) : null;
        $lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

        // IDOR prevention: verify project access
        if ($projectId && !hasProjectAccess($db, $userId, $projectId)) {
            echo json_encode(['error' => 'Permission denied']);
            exit;
        }
        
        $sql = "SELECT cm.*, u.username, u.full_name, u.role FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id > ? ";
        $params = [$lastId];
        
        if ($pageId) {
            $sql .= "AND cm.page_id = ? ";
            $params[] = $pageId;
        } elseif ($projectId) {
            $sql .= "AND cm.project_id = ? AND cm.page_id IS NULL ";
            $params[] = $projectId;
        } else {
            $sql .= "AND cm.project_id IS NULL AND cm.page_id IS NULL ";
        }
        
        $sql .= "ORDER BY cm.created_at ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Sanitize message HTML for each row and include reply preview if available
        require_once __DIR__ . '/../includes/functions.php';
        foreach ($rows as &$r) {
            $r['message'] = sanitize_chat_html($r['message'] ?? '');
            $r['message'] = rewrite_upload_urls_to_secure($r['message']);
            $isDeleted = isChatMessageDeletedRow($r);
            $isOwn = ((int)($r['user_id'] ?? 0) === (int)$userId);
            $r['can_edit'] = (!$isDeleted && ($isOwn || $isAdmin));
            $r['can_delete'] = (!$isDeleted && ($isOwn || $isAdmin));
            if (!empty($r['reply_to'])) {
                try {
                    $pr = $db->prepare("SELECT cm.id, cm.user_id, cm.message, cm.created_at, u.username, u.full_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                    $pr->execute([$r['reply_to']]);
                    $pRow = $pr->fetch(PDO::FETCH_ASSOC);
                    if ($pRow) {
                        $pRow['message'] = sanitize_chat_html($pRow['message'] ?? '');
                        $pRow['message'] = rewrite_upload_urls_to_secure($pRow['message']);
                        $r['reply_preview'] = [
                            'id' => $pRow['id'],
                            'user_id' => $pRow['user_id'],
                            'username' => $pRow['username'],
                            'full_name' => $pRow['full_name'],
                            'message' => $pRow['message'],
                            'created_at' => $pRow['created_at'] ?? null
                        ];
                    }
                } catch (Exception $e) {
                    // ignore preview errors
                }
            }
        }
        echo json_encode($rows);
        break;

    case 'edit_message':
        ensureChatAuditSchema($db);
        $messageId = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
        $newMessage = trim($_POST['message'] ?? '');
        if ($messageId <= 0) {
            echo json_encode(['error' => 'Invalid message id']);
            exit;
        }
        if ($newMessage === '') {
            echo json_encode(['error' => 'Message cannot be empty']);
            exit;
        }
        $isAdmin = currentUserIsAdmin($db, $userId, $_SESSION['role'] ?? '');
        $stmt = $db->prepare("SELECT * FROM chat_messages WHERE id = ? LIMIT 1");
        $stmt->execute([$messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['error' => 'Message not found']);
            exit;
        }
        // IDOR fix: verify user has access to the project this message belongs to
        if (!empty($row['project_id']) && !hasProjectAccess($db, $userId, (int)$row['project_id'])) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        if (!empty($row['deleted_at'])) {
            echo json_encode(['error' => 'Deleted message cannot be edited']);
            exit;
        }
        if (!$isAdmin && (int)$row['user_id'] !== (int)$userId) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $upd = $db->prepare("UPDATE chat_messages SET message = ?, edited_at = NOW() WHERE id = ?");
        $ok = $upd->execute([$newMessage, $messageId]);
        if ($ok) {
            try {
                $h = $db->prepare("INSERT INTO chat_message_history (message_id, action_type, old_message, new_message, acted_by) VALUES (?, 'edit', ?, ?, ?)");
                $h->execute([$messageId, $row['message'] ?? '', $newMessage, $userId]);
            } catch (Exception $e) {}
            $re = $db->prepare("SELECT cm.*, u.username, u.full_name, u.role FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
            $re->execute([$messageId]);
            $msg = $re->fetch(PDO::FETCH_ASSOC);
            if ($msg) {
                $msg['message'] = rewrite_upload_urls_to_secure(sanitize_chat_html($msg['message'] ?? ''));
                $isDeletedMsg = isChatMessageDeletedRow($msg);
                $isOwnMsg = ((int)($msg['user_id'] ?? 0) === (int)$userId);
                $msg['can_edit'] = (!$isDeletedMsg && ($isOwnMsg || $isAdmin));
                $msg['can_delete'] = (!$isDeletedMsg && ($isOwnMsg || $isAdmin));
            }
            echo json_encode(['success' => true, 'message' => $msg]);
        } else {
            echo json_encode(['error' => 'Failed to edit message']);
        }
        break;

    case 'delete_message':
        ensureChatAuditSchema($db);
        $messageId = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
        if ($messageId <= 0) {
            echo json_encode(['error' => 'Invalid message id']);
            exit;
        }
        $isAdmin = currentUserIsAdmin($db, $userId, $_SESSION['role'] ?? '');
        $stmt = $db->prepare("SELECT * FROM chat_messages WHERE id = ? LIMIT 1");
        $stmt->execute([$messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['error' => 'Message not found']);
            exit;
        }
        // IDOR fix: verify user has access to the project this message belongs to
        if (!empty($row['project_id']) && !hasProjectAccess($db, $userId, (int)$row['project_id'])) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        if (!$isAdmin && (int)$row['user_id'] !== (int)$userId) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        if (!empty($row['deleted_at'])) {
            echo json_encode(['success' => true]);
            exit;
        }
        $oldMessageHtml = (string)($row['message'] ?? '');
        $deletedMsg = '<p><em>Message deleted</em></p>';
        $upd = $db->prepare("UPDATE chat_messages SET message = ?, deleted_at = NOW(), deleted_by = ? WHERE id = ?");
        $ok = $upd->execute([$deletedMsg, $userId, $messageId]);
        if ($ok) {
            if (function_exists('delete_local_upload_files_from_html') && trim($oldMessageHtml) !== '') {
                delete_local_upload_files_from_html($oldMessageHtml, ['uploads/chat/', 'uploads/issues/']);
            }
            try {
                $h = $db->prepare("INSERT INTO chat_message_history (message_id, action_type, old_message, new_message, acted_by) VALUES (?, 'delete', ?, ?, ?)");
                $h->execute([$messageId, $row['message'] ?? '', $deletedMsg, $userId]);
            } catch (Exception $e) {}
            $re = $db->prepare("SELECT cm.*, u.username, u.full_name, u.role FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
            $re->execute([$messageId]);
            $msg = $re->fetch(PDO::FETCH_ASSOC);
            if ($msg) {
                $msg['message'] = rewrite_upload_urls_to_secure(sanitize_chat_html($msg['message'] ?? ''));
                $msg['can_edit'] = false;
                $msg['can_delete'] = false;
            }
            echo json_encode(['success' => true, 'message' => $msg]);
        } else {
            echo json_encode(['error' => 'Failed to delete message']);
        }
        break;

    case 'get_message_history':
        ensureChatAuditSchema($db);
        $isAdmin = currentUserIsAdmin($db, $userId, $_SESSION['role'] ?? '');
        if (!$isAdmin) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $messageId = isset($_GET['message_id']) ? intval($_GET['message_id']) : 0;
        if ($messageId <= 0) {
            echo json_encode(['error' => 'Invalid message id']);
            exit;
        }
        $stmt = $db->prepare("
            SELECT h.*, u.full_name AS acted_by_name
            FROM chat_message_history h
            LEFT JOIN users u ON u.id = h.acted_by
            WHERE h.message_id = ?
            ORDER BY h.acted_at DESC, h.id DESC
        ");
        $stmt->execute([$messageId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'history' => $rows]);
        break;
        
    case 'get_notifications':
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$userId]);
        $unread = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $countStmt->execute([$userId]);
        $count = $countStmt->fetchColumn();
        
        echo json_encode(['notifications' => $unread, 'unread_count' => $count]);
        break;
        
    case 'mark_read':
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
        } else {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}
