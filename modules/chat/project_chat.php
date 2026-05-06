<?php
// modules/chat/project_chat.php

// Include configuration
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/chat_helpers.php';

$auth = new Auth();
$auth->requireLogin();
$baseDir = getBaseDir();
$viewerRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$viewerRole = preg_replace('/[^a-z0-9]+/', '_', $viewerRole);
$viewerRole = trim($viewerRole, '_');
$isClientViewer = ($viewerRole === 'client');
$isAdminChatViewer = in_array($viewerRole, ['admin'], true);

if ($isClientViewer) {
    http_response_code(403);
    $_SESSION['error'] = 'Project chat is not available for client accounts.';
    header('Location: ' . $baseDir . '/client/index.php');
    exit;
}

$embed = isset($_GET['embed']) && $_GET['embed'] === '1';

// When loaded in an iframe (embed mode), allow same-origin framing.
// DENY is set globally in .htaccess; we must remove it first then set SAMEORIGIN.
if ($embed) {
    header_remove('X-Frame-Options');
    header('X-Frame-Options: SAMEORIGIN', true);
}

// Get project and page IDs
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$pageId = isset($_GET['page_id']) ? intval($_GET['page_id']) : 0;

// Connect to database
$db = Database::getInstance();

function chatColExistsLocal($db, $name) {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM chat_messages LIKE ?");
        $stmt->execute([$name]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function ensureReplyColumnLocal($db) {
    try {
        if (!chatColExistsLocal($db, 'reply_to')) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN reply_to INT NULL");
        }
    } catch (Exception $e) {
    }
}

function fetchChatMessageRowLocal($db, $messageId) {
    $mStmt = $db->prepare("
        SELECT cm.*, u.username, u.full_name, u.role
        FROM chat_messages cm
        JOIN users u ON cm.user_id = u.id
        WHERE cm.id = ?
        LIMIT 1
    ");
    $mStmt->execute([(int)$messageId]);
    return $mStmt->fetch(PDO::FETCH_ASSOC);
}

function isChatMessageDeletedLocal($row) {
    $deletedAt = trim((string)($row['deleted_at'] ?? ''));
    if ($deletedAt !== '') return true;
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string)($row['message'] ?? ''))));
    return strcasecmp($plain, 'Message deleted') === 0;
}

// Send message (non-AJAX fallback; AJAX handled via api/chat_actions, but keep safe here)
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// CSRF check for all POST requests on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid request token.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_message'])) {
    $messageId = (int)($_POST['message_id'] ?? 0);
    $newMessage = trim((string)($_POST['message'] ?? ''));
    if ($messageId <= 0 || $newMessage === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $row = fetchChatMessageRowLocal($db, $messageId);
    if (!$row) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Message not found']);
        exit;
    }
    $isOwn = ((int)$row['user_id'] === $userId);
    if (!$isAdminChatViewer && !$isOwn) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    if (isChatMessageDeletedLocal($row)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Deleted message cannot be edited']);
        exit;
    }

    $updated = false;
    try {
        if (chatColExistsLocal($db, 'edited_at')) {
            $u = $db->prepare("UPDATE chat_messages SET message = ?, edited_at = NOW() WHERE id = ?");
            $updated = $u->execute([$newMessage, $messageId]);
        } else {
            $u = $db->prepare("UPDATE chat_messages SET message = ? WHERE id = ?");
            $updated = $u->execute([$newMessage, $messageId]);
        }
    } catch (Exception $e) {
        $updated = false;
    }
    if (!$updated) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to edit message']);
        exit;
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_message_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                action_type ENUM('edit','delete') NOT NULL,
                old_message MEDIUMTEXT NULL,
                new_message MEDIUMTEXT NULL,
                acted_by INT NOT NULL,
                acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $h = $db->prepare("INSERT INTO chat_message_history (message_id, action_type, old_message, new_message, acted_by) VALUES (?, 'edit', ?, ?, ?)");
        $h->execute([$messageId, $row['message'] ?? '', $newMessage, $userId]);
    } catch (Exception $e) {
    }

    $msgRow = fetchChatMessageRowLocal($db, $messageId);
    if ($msgRow) {
        $msgRow['message'] = sanitize_chat_html($msgRow['message'] ?? '');
        if (function_exists('rewrite_upload_urls_to_secure')) {
            $msgRow['message'] = rewrite_upload_urls_to_secure($msgRow['message']);
        }
        $isOwnRow = ((int)($msgRow['user_id'] ?? 0) === $userId);
        $isDeletedRow = isChatMessageDeletedLocal($msgRow);
        $msgRow['can_edit'] = (!$isDeletedRow && ($isOwnRow || $isAdminChatViewer));
        $msgRow['can_delete'] = (!$isDeletedRow && ($isOwnRow || $isAdminChatViewer));
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $msgRow]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
    $messageId = (int)($_POST['message_id'] ?? 0);
    if ($messageId <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $row = fetchChatMessageRowLocal($db, $messageId);
    if (!$row) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Message not found']);
        exit;
    }
    $isOwn = ((int)$row['user_id'] === $userId);
    if (!$isAdminChatViewer && !$isOwn) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    if (isChatMessageDeletedLocal($row)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    $oldMessageHtml = (string)($row['message'] ?? '');
    $deletedMsg = '<p><em>Message deleted</em></p>';
    $updated = false;
    try {
        if (chatColExistsLocal($db, 'deleted_at') && chatColExistsLocal($db, 'deleted_by')) {
            $u = $db->prepare("UPDATE chat_messages SET message = ?, deleted_at = NOW(), deleted_by = ? WHERE id = ?");
            $updated = $u->execute([$deletedMsg, $userId, $messageId]);
        } else {
            $u = $db->prepare("UPDATE chat_messages SET message = ? WHERE id = ?");
            $updated = $u->execute([$deletedMsg, $messageId]);
        }
    } catch (Exception $e) {
        $updated = false;
    }
    if (!$updated) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to delete message']);
        exit;
    }
    if (function_exists('delete_local_upload_files_from_html') && trim($oldMessageHtml) !== '') {
        delete_local_upload_files_from_html($oldMessageHtml, ['uploads/chat/', 'uploads/issues/']);
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_message_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                action_type ENUM('edit','delete') NOT NULL,
                old_message MEDIUMTEXT NULL,
                new_message MEDIUMTEXT NULL,
                acted_by INT NOT NULL,
                acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $h = $db->prepare("INSERT INTO chat_message_history (message_id, action_type, old_message, new_message, acted_by) VALUES (?, 'delete', ?, ?, ?)");
        $h->execute([$messageId, $row['message'] ?? '', $deletedMsg, $userId]);
    } catch (Exception $e) {
    }

    $msgRow = fetchChatMessageRowLocal($db, $messageId);
    if ($msgRow) {
        $msgRow['message'] = sanitize_chat_html($msgRow['message'] ?? '');
        if (function_exists('rewrite_upload_urls_to_secure')) {
            $msgRow['message'] = rewrite_upload_urls_to_secure($msgRow['message']);
        }
        $msgRow['can_edit'] = false;
        $msgRow['can_delete'] = false;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $msgRow]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_message_history')) {
    header('Content-Type: application/json');
    if (!$isAdminChatViewer) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $messageId = (int)($_GET['message_id'] ?? 0);
    if ($messageId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid message id']);
        exit;
    }
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_message_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                action_type ENUM('edit','delete') NOT NULL,
                old_message MEDIUMTEXT NULL,
                new_message MEDIUMTEXT NULL,
                acted_by INT NOT NULL,
                acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $stmt = $db->prepare("
            SELECT h.*, u.full_name AS acted_by_name
            FROM chat_message_history h
            LEFT JOIN users u ON u.id = h.acted_by
            WHERE h.message_id = ?
            ORDER BY h.acted_at DESC, h.id DESC
        ");
        $stmt->execute([$messageId]);
        echo json_encode(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'history' => []]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    ensureReplyColumnLocal($db);
    $replyTo = isset($_POST['reply_to']) && is_numeric($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
    if ($replyTo === null) {
        $replyToken = trim((string)($_POST['reply_token'] ?? ''));
        if (preg_match('/^r:(\d+)$/', $replyToken, $m)) {
            $replyTo = (int)$m[1];
        }
    }
    
    if (!empty($message)) {
        $userId = $_SESSION['user_id'];
        $mentions = [];
        
        // Parse mentions
        preg_match_all('/@([A-Za-z0-9._-]+)/', $message, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $username) {
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($user = $stmt->fetch()) {
                    $mentions[] = $user['id'];
                }
            }
        }
        
        try {
            try {
                $stmt = $db->prepare("
                    INSERT INTO chat_messages (project_id, page_id, user_id, message, mentions, reply_to)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $projectId ?: null,
                    $pageId ?: null,
                    $userId,
                    $message,
                    json_encode($mentions),
                    $replyTo ?: null
                ]);
            } catch (Exception $innerInsert) {
                $stmt = $db->prepare("
                    INSERT INTO chat_messages (project_id, page_id, user_id, message, mentions)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $projectId ?: null,
                    $pageId ?: null,
                    $userId,
                    $message,
                    json_encode($mentions)
                ]);
            }
            
            // If embed or ajax, return JSON to stay within widget
            if ($embed || $isAjax) {
                $lastId = (int)$db->lastInsertId();
                $msgRow = null;
                if ($lastId > 0) {
                    try {
                        $mStmt = $db->prepare("
                            SELECT cm.*, u.username, u.full_name, u.role
                            FROM chat_messages cm
                            JOIN users u ON cm.user_id = u.id
                            WHERE cm.id = ?
                            LIMIT 1
                        ");
                        $mStmt->execute([$lastId]);
                        $msgRow = $mStmt->fetch(PDO::FETCH_ASSOC);
                        if ($msgRow) {
                            $msgRow['message'] = sanitize_chat_html($msgRow['message'] ?? '');
                            if (function_exists('rewrite_upload_urls_to_secure')) {
                                $msgRow['message'] = rewrite_upload_urls_to_secure($msgRow['message']);
                            }
                            if (!empty($msgRow['reply_to'])) {
                                try {
                                    $pStmt = $db->prepare("
                                        SELECT cm.id, cm.user_id, cm.message, cm.created_at, u.username, u.full_name
                                        FROM chat_messages cm
                                        JOIN users u ON cm.user_id = u.id
                                        WHERE cm.id = ?
                                        LIMIT 1
                                    ");
                                    $pStmt->execute([(int)$msgRow['reply_to']]);
                                    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($pRow) {
                                        $pRow['message'] = sanitize_chat_html($pRow['message'] ?? '');
                                        if (function_exists('rewrite_upload_urls_to_secure')) {
                                            $pRow['message'] = rewrite_upload_urls_to_secure($pRow['message']);
                                        }
                                        $msgRow['reply_preview'] = [
                                            'id' => $pRow['id'],
                                            'user_id' => $pRow['user_id'],
                                            'username' => $pRow['username'],
                                            'full_name' => $pRow['full_name'],
                                            'message' => $pRow['message'],
                                            'created_at' => $pRow['created_at'] ?? null
                                        ];
                                    }
                                } catch (Exception $inner2) {
                                }
                            }
                            $isOwnRow = ((int)($msgRow['user_id'] ?? 0) === (int)$userId);
                            $isDeletedRow = isChatMessageDeletedLocal($msgRow);
                            $msgRow['can_edit'] = (!$isDeletedRow && ($isOwnRow || $isAdminChatViewer));
                            $msgRow['can_delete'] = (!$isDeletedRow && ($isOwnRow || $isAdminChatViewer));
                        }
                    } catch (Exception $inner) {
                        $msgRow = null;
                    }
                }
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'id' => $lastId, 'message' => $msgRow]);
                exit;
            }
            // Redirect to avoid resubmission (full page only)
            $redirect_url = $baseDir . "/modules/chat/project_chat.php";
            if ($projectId) {
                $redirect_url .= "?project_id=" . $projectId;
            }
            if ($pageId) {
                $redirect_url .= ($projectId ? "&" : "?") . "page_id=" . $pageId;
            }
            header("Location: " . $redirect_url);
            exit;
            
        } catch (Exception $e) {
            if ($embed || $isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error = "Failed to send message: " . $e->getMessage();
        }
    }
}

// Get chat messages
try {
    if ($pageId > 0) {
        // Page-level chat
        $stmt = $db->prepare("
            SELECT cm.*, u.username, u.full_name, u.role
            FROM chat_messages cm
            JOIN users u ON cm.user_id = u.id
            WHERE cm.page_id = ?
            ORDER BY cm.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$pageId]);
    } elseif ($projectId > 0) {
        // Project-level chat
        $stmt = $db->prepare("
            SELECT cm.*, u.username, u.full_name, u.role
            FROM chat_messages cm
            JOIN users u ON cm.user_id = u.id
            WHERE cm.project_id = ? AND cm.page_id IS NULL
            ORDER BY cm.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$projectId]);
    } else {
        // General chat
        $stmt = $db->prepare("
            SELECT cm.*, u.username, u.full_name, u.role
            FROM chat_messages cm
            JOIN users u ON cm.user_id = u.id
            WHERE cm.project_id IS NULL AND cm.page_id IS NULL
            ORDER BY cm.created_at DESC
            LIMIT 50
        ");
        $stmt->execute();
    }
    
    $messages = $stmt->fetchAll();
    
    // Mark messages as read when chat is opened
    markChatMessagesAsRead($db, $_SESSION['user_id'], $projectId, $pageId);
    
} catch (Exception $e) {
    $error = "Failed to load messages: " . $e->getMessage();
    $messages = [];
}

// Get project info if project ID is provided
$project = null;
if ($projectId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, title, po_number FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
    } catch (Exception $e) {
        // Silently fail, project will be null
    }
}

// Get page info if page ID is provided
$page = null;
if ($pageId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, page_name, project_id FROM project_pages WHERE id = ?");
        $stmt->execute([$pageId]);
        $page = $stmt->fetch();
        
        // If we have page but not project, get project from page
        if ($page && !$project && $page['project_id']) {
            $stmt = $db->prepare("SELECT id, title, po_number FROM projects WHERE id = ?");
            $stmt->execute([$page['project_id']]);
            $project = $stmt->fetch();
        }
    } catch (Exception $e) {
        // Silently fail, page will be null
    }
}

// Get online users (users active in last 5 minutes)
$onlineUsers = [];
try {
    $stmt = $db->prepare("
        SELECT DISTINCT u.id, u.username, u.full_name, u.role
        FROM users u
        JOIN activity_log al ON u.id = al.user_id
        WHERE al.created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        AND u.is_active = 1
        ORDER BY u.role, u.full_name
    ");
    $stmt->execute();
    $onlineUsers = $stmt->fetchAll();
} catch (Exception $e) {
    // Silently fail, onlineUsers will be empty
}

// Build mention list (project members + project lead) or all active users as fallback
$mentionUsers = [];
try {
    if ($projectId > 0) {
        $mentionStmt = $db->prepare("
            SELECT DISTINCT u.id, u.username, u.full_name
            FROM user_assignments ua
            JOIN users u ON ua.user_id = u.id
            WHERE ua.project_id = ? AND u.is_active = 1
            UNION
            SELECT u.id, u.username, u.full_name
            FROM projects p
            JOIN users u ON p.project_lead_id = u.id
            WHERE p.id = ? AND p.project_lead_id IS NOT NULL AND u.is_active = 1
            UNION
            SELECT u.id, u.username, u.full_name
            FROM users u
            WHERE u.is_active = 1 AND u.role IN ('admin')
        ");
        $mentionStmt->execute([$projectId, $projectId]);
    } elseif ($page && !empty($page['project_id'])) {
        $mentionStmt = $db->prepare("
            SELECT DISTINCT u.id, u.username, u.full_name
            FROM user_assignments ua
            JOIN users u ON ua.user_id = u.id
            WHERE ua.project_id = ? AND u.is_active = 1
            UNION
            SELECT u.id, u.username, u.full_name
            FROM users u
            WHERE u.is_active = 1 AND u.role IN ('admin')
        ");
        $mentionStmt->execute([$page['project_id']]);
    } else {
        $mentionStmt = $db->prepare("SELECT id, username, full_name FROM users WHERE is_active = 1 LIMIT 50");
        $mentionStmt->execute();
    }
    $mentionUsers = $mentionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    usort($mentionUsers, function($a, $b) {
        $aU = strtolower((string)($a['username'] ?? ''));
        $bU = strtolower((string)($b['username'] ?? ''));
        $aIsAdmin = in_array($aU, ['admin', 'superadmin'], true);
        $bIsAdmin = in_array($bU, ['admin', 'superadmin'], true);
        if ($aIsAdmin !== $bIsAdmin) {
            return $aIsAdmin ? -1 : 1;
        }
        return strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
    });
} catch (Exception $e) {
    $mentionUsers = [];
}

// Ensure baseDir available
if (!isset($baseDir)) {
    require_once __DIR__ . '/../../includes/helpers.php';
    $baseDir = getBaseDir();
}

// Set page title and output head
$pageTitle = 'Chat - Project Management System';

if (!$embed) {
    include __DIR__ . '/../../includes/header.php';
    ?>
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <?php $summernoteHelperPath = __DIR__ . '/../../assets/js/summernote_image_helper.js'; ?>
    <?php if (file_exists($summernoteHelperPath)): ?>
    <script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/summernote_image_helper.js?v=20260202v3"></script>
    <?php endif; ?>
    <?php
} else {
    ?><!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Project Chat - PMS</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
        <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js" crossorigin="anonymous"></script>
        <?php $summernoteHelperPath = __DIR__ . '/../../assets/js/summernote_image_helper.js'; ?>
        <?php if (file_exists($summernoteHelperPath)): ?>
        <script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/summernote_image_helper.js?v=20260202v3"></script>
        <?php endif; ?>
        <script>
            // Basic CDN fallback for jQuery/Summernote in embed mode
            (function() {
                if (!window.jQuery) {
                    var jq = document.createElement('script');
                    jq.src = 'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js';
                    document.head.appendChild(jq);
                }
            })();
        </script>
        <style>html,body{height:100%;margin:0;} body{background:#f8f9fa;overflow:hidden;} .container-embed{padding:8px;height:100%;}</style>
        <meta name="csrf-token" content="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <script>
            // Expose CSRF token for all AJAX/fetch calls in embed mode
            window._csrfToken = document.querySelector('meta[name="csrf-token"]')
                ? document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                : '';
        </script>
    </head>
    <body class="chat-embed">
    <div class="container-fluid container-embed chat-shell">
    <?php
}

?>

<style>
    .chat-container {
        height: 500px;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        padding: 15px;
        background-color: #f8f9fa;
    }
    .message {
        margin-bottom: 15px;
        padding: 10px;
        border-radius: 12px;
        background-color: white;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        max-width: 90%;
    }
    .message-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 8px;
        margin-bottom: 5px;
    }
    .message-header-right { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; flex-shrink: 0; }
    .message-actions { display: inline-flex; align-items: center; gap: 4px; }
    .chat-action-btn {
        border: 0;
        background: transparent;
        color: #0d6efd;
        width: 24px;
        height: 24px;
        padding: 0;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
    }
    .chat-action-btn:hover, .chat-action-btn:focus { background: rgba(13, 110, 253, 0.12); color: #0a58ca; }
    .chat-action-btn.chat-delete { color: #dc3545; }
    .chat-action-btn.chat-delete:hover, .chat-action-btn.chat-delete:focus { background: rgba(220, 53, 69, 0.12); color: #b02a37; }
    .message-sender { font-weight: bold; color: #333; }
    .message-time { font-size: 0.85em; color: #6c757d; }
    .message-content { word-wrap: break-word; }
    .reply-preview { border-left: 3px solid #e9ecef; background: #f8f9fa; padding:6px; margin-bottom:8px; }
    .mention { background-color: #fff3cd; padding: 2px 4px; border-radius: 3px; font-weight: bold; }
    .user-badge { font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
    .message-meta { font-size: 0.8em; color: #6c757d; margin-top: 4px; text-align: right; }
    .message:focus,
    .message:focus-visible {
        outline: 3px solid #0d6efd;
        outline-offset: 2px;
    }

    /* Embed chat layout */
    .chat-embed { height: 100%; overflow: hidden; }
    .chat-embed body { background: #ece5dd; margin: 0; overflow: hidden; }
    .chat-embed .chat-shell { background: #ece5dd; border-radius: 16px; padding: 8px; height: 100%; overflow: hidden; }
    .chat-embed .chat-embed-wrapper { display: flex; flex-direction: column; height: 100%; min-height: 0; background: #ece5dd; position: relative; overflow: hidden; }
    .chat-embed .chat-container { background: transparent; border: 0; box-shadow: none; padding: 6px; flex: 1; overflow-y: auto; }
    .chat-embed .message { box-shadow: none; border: 0; padding: 8px 12px; position: relative; }
    .chat-embed .message.other-message { background: #fff; margin-right: auto; }
    .chat-embed .message.own-message { background: #dcf8c6; margin-left: auto; }
    .chat-embed .message .message-content { font-size: 0.95rem; }
    .chat-embed .message .message-meta { font-size: 0.78rem; color: #6c757d; text-align: right; margin-top: 4px; }
    .chat-embed .message-header .user-badge { display: none; }
    .chat-embed .chat-embed-form { background: #f0f2f5; border-radius: 14px 14px 0 0; padding: 8px; box-shadow: 0 -4px 14px rgba(0,0,0,0.10); position: sticky; bottom: 0; z-index: 20; }
    .chat-embed .chat-embed-form.collapsed { padding: 6px 8px; }
    .chat-embed #chatForm { position: relative; }
    #chatForm { position: relative; }
    .chat-embed .note-editor.note-frame { background: transparent; }
    .chat-embed .note-statusbar { display: none; }
    .chat-embed .note-toolbar { border: 0; background: transparent; padding: 4px 0 0 0; display: flex; flex-wrap: nowrap; overflow-x: auto; gap: 4px; }
    .chat-embed .note-toolbar .note-btn-group { float: none; display: inline-flex; flex-wrap: nowrap; }
    .chat-embed .note-editor { border: 0; box-shadow: none; resize: vertical; overflow: auto; }
    .chat-embed .note-editable { min-height: 40px; background: #fff; border-radius: 10px; }
    .chat-embed .btn { border-radius: 999px; }
    .chat-embed .chat-compose-toggle {
        width: 100%;
        border-radius: 12px;
        border: 1px solid #ced4da;
        background: #ffffff;
        color: #0d6efd;
        font-weight: 600;
        margin: 0;
        padding: 8px 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        visibility: visible;
        opacity: 1;
    }
    .chat-embed .chat-compose-toggle:focus,
    .chat-embed .chat-compose-toggle:focus-visible {
        outline: 3px solid #0d6efd !important;
        outline-offset: 2px;
        box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.25);
    }
    .chat-embed .chat-compose-toggle.expanded { margin-bottom: 8px; }
    .chat-embed .chat-container { padding-bottom: 10px; }
    .chat-compose-body { display: none; }
    .chat-compose-body.open { display: block; padding-bottom: 24px; }
    .chat-compose-toggle { width: 100%; }
    .chat-message img, .message-content img { max-width: 100%; height: auto; }
    .message-content img {
        max-width: min(100%, 320px) !important;
        width: 100% !important;
        min-width: 0;
        height: auto !important;
        max-height: 240px !important;
        object-fit: contain;
        border-radius: 10px;
    }
    .chat-image-wrap { position: relative; display: inline-block; max-width: 100%; }
    .chat-image-thumb { max-width: min(100%, 320px) !important; width: 100% !important; max-height: 240px !important; object-fit: contain; display: block; }
    .chat-image-full-btn { position: absolute; top: 6px; right: 6px; padding: 6px; width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.92); box-shadow: 0 1px 4px rgba(0,0,0,0.2); border: 1px solid rgba(0,0,0,0.05); display: inline-flex; align-items: center; justify-content: center; }
    .chat-image-full-btn i { font-size: 0.85rem; }
    #chatModalImg { width: 100%; height: auto; }
</style>
<?php $lastMessageId = 0; ?>

<?php if ($embed): ?>
    <div class="chat-embed-wrapper">
        <div class="chat-container mb-2" id="chatMessages">
            <?php if (empty($messages)): ?>
            <div class="text-center text-muted py-4 no-messages">
                <i class="fas fa-comment-slash fa-2x mb-2"></i>
                <div>No messages yet. Start chatting!</div>
            </div>
            <?php else: ?>
                <?php foreach (array_reverse($messages) as $msg):
                    if ($msg['id'] > $lastMessageId) { $lastMessageId = $msg['id']; }
                    $isMentioned = false;
                    if ($msg['mentions']) {
                        $mentionIds = json_decode($msg['mentions'], true);
                        if (is_array($mentionIds) && in_array($_SESSION['user_id'], $mentionIds)) {
                            $isMentioned = true;
                        }
                    }
                    $isOwn = ((int)$msg['user_id'] === (int)$_SESSION['user_id']);
                ?>
                <div class="message <?php echo $isOwn ? 'own-message' : 'other-message'; ?> <?php echo $isMentioned ? 'border-start border-warning border-4 bg-light' : ''; ?>" data-id="<?php echo $msg['id']; ?>">
                    <div class="message-header">
                        <div>
                            <span class="message-sender text-muted small"><?php echo htmlspecialchars($msg['full_name']); ?></span>
                            <small class="text-muted">@<?php echo htmlspecialchars($msg['username']); ?></small>
                        </div>
                        <div class="message-header-right">
                            <div class="message-time"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                            <?php
                                $isOwn = ((int)$msg['user_id'] === (int)$_SESSION['user_id']);
                                $isDeleted = isChatMessageDeletedLocal($msg);
                                $canManage = (!$isDeleted && ($isOwn || $isAdminChatViewer));
                            ?>
                            <div class="message-actions">
                                <button type="button" class="chat-action-btn chat-reply" title="Reply" aria-label="Reply to message" data-mid="<?php echo (int)$msg['id']; ?>" data-username="<?php echo htmlspecialchars($msg['username']); ?>" data-message="<?php echo htmlspecialchars($msg['message']); ?>"><i class="fas fa-reply"></i></button>
                                <?php if ($canManage): ?>
                                    <button type="button" class="chat-action-btn chat-edit" title="Edit" aria-label="Edit message" data-mid="<?php echo (int)$msg['id']; ?>" data-message="<?php echo htmlspecialchars($msg['message']); ?>"><i class="fas fa-pen"></i></button>
                                    <button type="button" class="chat-action-btn chat-delete" title="Delete" aria-label="Delete message" data-mid="<?php echo (int)$msg['id']; ?>"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                                <?php if ($isAdminChatViewer): ?>
                                    <button type="button" class="chat-action-btn chat-history" title="History" aria-label="View message history" data-mid="<?php echo (int)$msg['id']; ?>"><i class="fas fa-history"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="message-content">
                        <?php
                        $messageHtml = sanitize_chat_html($msg['message']);
                        $messageHtml = rewrite_upload_urls_to_secure($messageHtml);
                        $messageHtml = preg_replace('/@([A-Za-z0-9._-]+)/', '<span class="mention">@$1</span>', $messageHtml);
                        $messageHtml = preg_replace_callback('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', function($m) {
                            $src = $m[1];
                            $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                            $imgTag = $m[0];
                            if (stripos($imgTag, 'class=') === false) {
                                $imgTag = preg_replace('/<img/i', '<img loading="lazy" class="chat-image-thumb"', $imgTag, 1);
                            } else {
                                $imgTag = preg_replace('/class=["\']([^"\']*)["\']/', 'class="$1 chat-image-thumb"', $imgTag, 1);
                            }
                            return '<span class="chat-image-wrap">' . $imgTag . '<button type="button" class="btn btn-light btn-sm chat-image-full-btn" data-src="' . $safeSrc . '" aria-label="View full image"><i class="fas fa-up-right-from-square"></i></button></span>';
                        }, $messageHtml);
                        if (!empty($msg['reply_to'])) {
                            $pr = null;
                            try {
                                $pstmt = $db->prepare("SELECT cm.id, cm.user_id, cm.message, cm.created_at, u.username, u.full_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                                $pstmt->execute([$msg['reply_to']]);
                                $pr = $pstmt->fetch();
                            } catch (Exception $e) { $pr = null; }
                            if ($pr) {
                                $pmsg = sanitize_chat_html($pr['message']);
                                $pmsg = rewrite_upload_urls_to_secure($pmsg);
                                $ptime = !empty($pr['created_at']) ? date('M d, H:i', strtotime($pr['created_at'])) : '';
                                echo '<div class="reply-preview"><strong>' . htmlspecialchars($pr['full_name']) . '</strong>' . ($ptime ? ' <small class="text-muted ms-2">' . htmlspecialchars($ptime) . '</small>' : '') . ': ' . $pmsg . '</div>';
                            }
                        }
                        echo $messageHtml;
                        ?>
                        <div class="message-meta small text-muted"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form id="chatForm" class="chat-embed-form" method="POST">
            <input type="hidden" name="send_message" value="1">
            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
            <input type="hidden" name="page_id" value="<?php echo $pageId; ?>">
            <button type="button" class="btn btn-sm chat-compose-toggle" id="composeToggle">
                <i class="fas fa-comment-dots"></i> Compose
            </button>
            <div class="chat-compose-body" id="composeBody">
                <div class="mb-2">
                    <textarea 
                        class="form-control" 
                        id="message" 
                        name="message"
                        placeholder="Type a message"
                    ></textarea>
                </div>
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <button type="submit" class="btn btn-success btn-sm" id="sendBtn">
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                    <span class="text-muted small" id="charCount">0/1000</span>
                </div>
            </div>
            <div id="mentionDropdown" class="dropdown-menu" style="display:none; position:absolute; z-index: 1050; max-height:180px; overflow-y:auto;"></div>
        </form>
    </div>
<?php else: ?>
    <div class="container-fluid">
        <div class="row">
            <!-- Main Chat Area -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-comments"></i>
                            <?php
                            if ($page) {
                                echo "Chat: " . htmlspecialchars($page['page_name']);
                            } elseif ($project) {
                                echo "Project Chat: " . htmlspecialchars($project['title']);
                            } else {
                                echo "General Chat";
                            }
                            ?>
                        </h5>
                        <?php if ($project): ?>
                        <small class="text-light">
                            Project: <?php echo htmlspecialchars($project['title']); ?>
                            (<?php echo htmlspecialchars($project['po_number']); ?>)
                        </small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body">
                        <!-- Error Message -->
                        <?php if (isset($error)): ?>
                        <div class="alert alert-danger" id="chatError" style="display:none;">
                            <i class="fas fa-exclamation-triangle"></i> <span class="error-text"></span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Chat Messages -->
                        <div class="chat-container mb-3" id="chatMessages">
                            <?php if (empty($messages)): ?>
                            <div class="text-center text-muted py-5 no-messages">
                                <i class="fas fa-comment-slash fa-3x mb-3"></i>
                                <p>No messages yet. Start the conversation!</p>
                            </div>
                            <?php else: ?>
                                <?php 
                                foreach (array_reverse($messages) as $msg): 
                                    if ($msg['id'] > $lastMessageId) { $lastMessageId = $msg['id']; }
                                    $isMentioned = false;
                                    if ($msg['mentions']) {
                                        $mentionIds = json_decode($msg['mentions'], true);
                                        if (is_array($mentionIds) && in_array($_SESSION['user_id'], $mentionIds)) {
                                            $isMentioned = true;
                                        }
                                    }
                                    $isOwn = ((int)$msg['user_id'] === (int)$_SESSION['user_id']);
                                ?>
                                <div class="message <?php echo $isOwn ? 'own-message' : 'other-message'; ?> <?php echo $isMentioned ? 'border-start border-warning border-4 bg-light' : ''; ?>" data-id="<?php echo $msg['id']; ?>">
                                    <div class="message-header">
                                        <div>
                                            <span class="message-sender">
                                                <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $msg['user_id']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($msg['full_name']); ?>
                                                </a>
                                            </span>
                                            <span class="badge user-badge bg-<?php
                                                echo $msg['role'] == 'admin' ? 'danger' :
                                                     ($msg['role'] == 'project_lead' ? 'warning' : 'info');
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $msg['role'])); ?>
                                            </span>
                                            <small class="text-muted">@<?php echo htmlspecialchars($msg['username']); ?></small>
                                        </div>
                                        <div class="message-header-right">
                                            <div class="message-time"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                                            <?php
                                                $isOwn = ((int)$msg['user_id'] === (int)$_SESSION['user_id']);
                                                $isDeleted = isChatMessageDeletedLocal($msg);
                                                $canManage = (!$isDeleted && ($isOwn || $isAdminChatViewer));
                                            ?>
                                            <div class="message-actions">
                                                <button type="button" class="chat-action-btn chat-reply" title="Reply" aria-label="Reply to message" data-mid="<?php echo (int)$msg['id']; ?>" data-username="<?php echo htmlspecialchars($msg['username']); ?>" data-message="<?php echo htmlspecialchars($msg['message']); ?>"><i class="fas fa-reply"></i></button>
                                                <?php if ($canManage): ?>
                                                    <button type="button" class="chat-action-btn chat-edit" title="Edit" aria-label="Edit message" data-mid="<?php echo (int)$msg['id']; ?>" data-message="<?php echo htmlspecialchars($msg['message']); ?>"><i class="fas fa-pen"></i></button>
                                                    <button type="button" class="chat-action-btn chat-delete" title="Delete" aria-label="Delete message" data-mid="<?php echo (int)$msg['id']; ?>"><i class="fas fa-trash"></i></button>
                                                <?php endif; ?>
                                                <?php if ($isAdminChatViewer): ?>
                                                    <button type="button" class="chat-action-btn chat-history" title="History" aria-label="View message history" data-mid="<?php echo (int)$msg['id']; ?>"><i class="fas fa-history"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="message-content">
                                        <?php
                                        $messageHtml = sanitize_chat_html($msg['message']);
                                        $messageHtml = rewrite_upload_urls_to_secure($messageHtml);
                                        // Highlight mentions server-side
                                        $messageHtml = preg_replace('/@([A-Za-z0-9._-]+)/', '<span class="mention">@$1</span>', $messageHtml);
                                        $messageHtml = preg_replace_callback('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', function($m) {
                                            $src = $m[1];
                                            $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                                            $imgTag = $m[0];
                                            if (stripos($imgTag, 'class=') === false) {
                                                $imgTag = preg_replace('/<img/i', '<img loading="lazy" class="chat-image-thumb"', $imgTag, 1);
                                            } else {
                                                $imgTag = preg_replace('/class=["\']([^"\']*)["\']/', 'class="$1 chat-image-thumb"', $imgTag, 1);
                                            }
                                            return '<span class="chat-image-wrap">' . $imgTag . '<button type="button" class="btn btn-light btn-sm chat-image-full-btn" data-src="' . $safeSrc . '" aria-label="View full image"><i class="fas fa-up-right-from-square"></i></button></span>';
                                        }, $messageHtml);
                                        // If this message is a reply, include preview
                                        if (!empty($msg['reply_to'])) {
                                            // fetch preview if available
                                            $pr = null;
                                            try {
                                                $pstmt = $db->prepare("SELECT cm.id, cm.user_id, cm.message, cm.created_at, u.username, u.full_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                                                $pstmt->execute([$msg['reply_to']]);
                                                $pr = $pstmt->fetch();
                                            } catch (Exception $e) { $pr = null; }
                                            if ($pr) {
                                                $pmsg = sanitize_chat_html($pr['message']);
                                                $pmsg = rewrite_upload_urls_to_secure($pmsg);
                                                $ptime = !empty($pr['created_at']) ? date('M d, H:i', strtotime($pr['created_at'])) : '';
                                                echo '<div class="reply-preview"><strong>' . htmlspecialchars($pr['full_name']) . '</strong>' . ($ptime ? ' <small class="text-muted ms-2">' . htmlspecialchars($ptime) . '</small>' : '') . ': ' . $pmsg . '</div>';
                                            }
                                        }
                                        echo $messageHtml;
                                        ?>
                                        <div class="message-meta small text-muted"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Message Form -->
                        <form id="chatForm" class="mt-3" method="POST">
                            <input type="hidden" name="send_message" value="1">
                            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                            <input type="hidden" name="page_id" value="<?php echo $pageId; ?>">
                            <div class="mb-3">
                                    <label for="message" class="form-label">Your Message</label>
                                    <textarea 
                                        class="form-control" 
                                        id="message" 
                                        name="message"
                                        placeholder="Type your message here... Use @username to mention someone."
                                    ></textarea>
                                    <small class="text-muted">Mention users with @username. You can paste images.</small>
                                </div>
                            <div class="d-flex justify-content-between">
                                <div>
                                    <button type="submit" class="btn btn-primary" id="sendBtn">
                                        <i class="fas fa-paper-plane"></i> Send Message
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="clearMessage">
                                        <i class="fas fa-times"></i> Clear
                                    </button>
                                </div>
                                <div>
                                    <span class="text-muted" id="charCount">0/1000</span>
                                </div>
                            </div>
                            <div id="mentionDropdown" class="dropdown-menu" style="display:none; position:absolute; z-index: 1050; max-height:180px; overflow-y:auto;"></div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="col-md-4">
                <!-- Online Users -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-users"></i> Online Users
                            <span class="badge bg-success" id="onlineCount"><?php echo count($onlineUsers); ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div id="onlineUsersList">
                        <?php if (empty($onlineUsers)): ?>
                        <p class="text-muted">No users online</p>
                        <?php else: ?>
                            <?php foreach ($onlineUsers as $user): ?>
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-success me-2">●</span>
                                <div>
                                    <strong>
                                        <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $user['id']; ?>" class="text-decoration-none text-dark">
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </a>
                                    </strong>
                                    <small class="text-muted d-block">
                                        @<?php echo htmlspecialchars($user['username']); ?> • 
                                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                    </small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary ms-auto mention-user" 
                                        data-username="@<?php echo htmlspecialchars($user['username']); ?>">
                                    @
                                </button>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Chat Info -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Chat Information</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($project): ?>
                        <p><strong>Project:</strong> <?php echo htmlspecialchars($project['title']); ?></p>
                        <p><strong>Project Code:</strong> <?php echo htmlspecialchars($project['po_number']); ?></p>
                        <?php endif; ?>
                        
                        <?php if ($page): ?>
                        <p><strong>Page:</strong> <?php echo htmlspecialchars($page['page_name']); ?></p>
                        <?php endif; ?>
                        
                        <p><strong>Your Role:</strong> 
                            <span class="badge bg-<?php
                                echo $_SESSION['role'] == 'admin' ? 'danger' :
                                     ($_SESSION['role'] == 'project_lead' ? 'warning' : 'info');
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>
                            </span>
                        </p>
                        
                        <div class="mt-3">
                            <h6>Quick Actions:</h6>
                            <div class="d-grid gap-2">
                                <?php if ($project): ?>
                                <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to Project
                                </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-info" id="refreshChat">
                                    <i class="fas fa-sync-alt"></i> Force Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($embed): ?>
    </div> <!-- /.container-embed -->
<?php endif; ?>

<!-- Image modal for full view -->
<div class="modal fade" id="chatImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img id="chatModalImg" src="" alt="Full image" />
            </div>
        </div>
    </div>
</div>

<?php if ($isAdminChatViewer): ?>
<div class="modal fade" id="chatHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Message History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="chatHistoryBody">
                <p class="text-muted mb-0">Loading...</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="chatEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="chatEditMessageId" value="">
                <label for="chatEditMessageInput" class="form-label">Message</label>
                <textarea id="chatEditMessageInput" class="form-control" rows="4"></textarea>
                <div class="small text-muted mt-1" id="chatEditCharCount">0/1000</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="chatEditSaveBtn">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="chatDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="chatDeleteMessageId" value="">
                <p class="mb-0">Are you sure you want to delete this message?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="chatDeleteConfirmBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="chatActionStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="chatActionStatusTitle">Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="chatActionStatusText">Unable to complete this action.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

    <script>
    window._projectChatConfig = {
        currentUserId: <?php echo (int)$_SESSION['user_id']; ?>,
        currentUserRole: <?php echo json_encode($_SESSION['role'] ?? ''); ?>,
        canViewHistoryAdmin: <?php echo $isAdminChatViewer ? 'true' : 'false'; ?>,
        projectId: <?php echo $projectId ?: 'null'; ?>,
        pageId: <?php echo $pageId ?: 'null'; ?>,
        lastMessageId: <?php echo (int)($lastMessageId ?? 0); ?>,
        mentionUsers: <?php echo json_encode($mentionUsers); ?>,
        baseDir: <?php echo json_encode($baseDir); ?>
    };
    </script>
    <script src="<?php echo $baseDir; ?>/assets/js/project-chat.js"></script>


<?php if (!$embed): ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<?php else: ?>
    </body>
    </html>
<?php endif; 