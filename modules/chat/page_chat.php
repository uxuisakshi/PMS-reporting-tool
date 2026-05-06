<?php
// modules/chat/page_chat.php

// Include configuration
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$baseDir = getBaseDir();
$viewerRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$viewerRole = preg_replace('/[^a-z0-9]+/', '_', $viewerRole);
$viewerRole = trim($viewerRole, '_');

if ($viewerRole === 'client') {
    http_response_code(403);
    $_SESSION['error'] = 'Project chat is not available for client accounts.';
    header('Location: ' . $baseDir . '/client/index.php');
    exit;
}

// Get page ID
$pageId = isset($_GET['page_id']) ? intval($_GET['page_id']) : 0;

if (!$pageId) {
    header("Location: " . $baseDir . "/modules/projects/view.php");
    exit;
}

// Connect to database
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get page details
try {
    $stmt = $db->prepare("
        SELECT pp.*, p.title as project_title, p.id as project_id
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE pp.id = ?
    ");
    $stmt->execute([$pageId]);
    $page = $stmt->fetch();
    
    if (!$page) {
        $_SESSION['error'] = "Page not found.";
        header("Location: " . $baseDir . "/modules/projects/view.php");
        exit;
    }
    
} catch (Exception $e) {
    die("Error loading page: " . $e->getMessage());
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Chat - PMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            border-radius: 8px;
            background-color: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .message-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        .message-sender {
            font-weight: bold;
            color: #333;
        }
        .message-time {
            font-size: 0.85em;
            color: #6c757d;
        }
        .message-content {
            word-wrap: break-word;
        }
        .mention {
            background-color: #fff3cd;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: bold;
        }
        .user-badge {
            font-size: 0.8em;
            padding: 2px 8px;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/header.php'; ?>

    <div class="container-fluid mt-3">
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
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Chat Messages -->
                        <div class="chat-container mb-3" id="chatMessages">
                            <?php if (empty($messages)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-comment-slash fa-3x mb-3"></i>
                                <p>No messages yet. Start the conversation!</p>
                            </div>
                            <?php else: ?>
                                <?php foreach (array_reverse($messages) as $msg): ?>
                                <div class="message">
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
                                        <div class="message-time">
                                            <?php echo date('M d, H:i', strtotime($msg['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="message-content">
                                        <?php
                                        $messageText = htmlspecialchars($msg['message']);
                                        // Highlight mentions
                                        $messageText = preg_replace(
                                            '/@([A-Za-z0-9._-]+)/',
                                            '<span class="mention">@$1</span>',
                                            $messageText
                                        );
                                        echo nl2br($messageText);
                                        ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Message Form -->
                        <form method="POST" id="chatForm">
                            <div class="mb-3">
                                <label for="message" class="form-label">Your Message</label>
                                <textarea 
                                    class="form-control" 
                                    id="message" 
                                    name="message" 
                                    rows="3" 
                                    placeholder="Type your message here... Use @username to mention someone."
                                    required
                                ></textarea>
                                <small class="text-muted">Mention users with @username</small>
                            </div>
                            <div class="d-flex justify-content-between">
                                <div>
                                    <button type="submit" name="send_message" class="btn btn-primary">
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
                            <span class="badge bg-success"><?php echo count($onlineUsers); ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
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
                                <a href="<?php echo $baseDir; ?>/modules/chat/project_chat.php" class="btn btn-outline-primary">
                                    <i class="fas fa-comments"></i> General Chat
                                </a>
                                <?php if ($project): ?>
                                <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to Project
                                </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-info" id="refreshChat">
                                    <i class="fas fa-sync-alt"></i> Refresh Chat
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/page-chat.js"></script>
</body>
</html>
