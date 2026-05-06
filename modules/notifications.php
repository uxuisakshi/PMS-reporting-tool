<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

$userId = $_SESSION['user_id'];
$pageTitle = 'My Notifications';

// Handle mark as read
if (isset($_POST['mark_read'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $notificationId = $_POST['notification_id'];
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notificationId, $userId]);
    $_SESSION['success'] = "Notification marked as read";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $_SESSION['success'] = "All notifications marked as read";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle delete notification
if (isset($_POST['delete_notification'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $notificationId = $_POST['notification_id'];
    $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$notificationId, $userId]);
    $_SESSION['success'] = "Notification deleted";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get filter
$filter = $_GET['filter'] ?? 'all';
$whereClause = "WHERE user_id = ?";
$params = [$userId];

if ($filter === 'unread') {
    $whereClause .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $whereClause .= " AND is_read = 1";
} elseif ($filter !== 'all') {
    $whereClause .= " AND type = ?";
    $params[] = $filter;
}

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM notifications $whereClause");
$countStmt->execute($params);
$totalNotifications = $countStmt->fetchColumn();
$totalPages = ceil($totalNotifications / $limit);

// Get notifications
$stmt = $db->prepare("
    SELECT * FROM notifications 
    $whereClause 
    ORDER BY created_at DESC 
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread count
$unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadStmt->execute([$userId]);
$unreadCount = $unreadStmt->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>
            My Notifications 
            <?php if ($unreadCount > 0): ?>
                <span class="badge bg-danger"><?php echo $unreadCount; ?> unread</span>
            <?php endif; ?>
        </h2>
        <div>
            <?php if ($unreadCount > 0): ?>
                <form id="markAllReadForm" method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="mark_all_read" value="1">
                    <button type="button" class="btn btn-success" onclick="confirmForm('markAllReadForm', 'Mark all notifications as read?')">
                        <i class="fas fa-check-double"></i> Mark All Read
                    </button>
                </form>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/calendar.php" class="btn btn-secondary">Back to Calendar</a>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="?filter=all">
                All (<?php echo $totalNotifications; ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter === 'unread' ? 'active' : ''; ?>" href="?filter=unread">
                Unread (<?php echo $unreadCount; ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter === 'read' ? 'active' : ''; ?>" href="?filter=read">
                Read
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter === 'edit_request' ? 'active' : ''; ?>" href="?filter=edit_request">
                Edit Requests
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter === 'edit_request_response' ? 'active' : ''; ?>" href="?filter=edit_request_response">
                Edit Responses
            </a>
        </li>
    </ul>

    <!-- Notifications List -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($notifications)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No notifications found</h5>
                    <p class="text-muted">You're all caught up!</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="list-group-item <?php echo $notification['is_read'] ? '' : 'list-group-item-warning'; ?>">
                            <div class="d-flex w-100 justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center mb-2">
                                        <?php
                                        $icon = 'fas fa-bell';
                                        $badgeClass = 'bg-primary';
                                        
                                        switch ($notification['type']) {
                                            case 'edit_request':
                                                $icon = 'fas fa-edit';
                                                $badgeClass = 'bg-warning';
                                                break;
                                            case 'edit_request_response':
                                                $icon = 'fas fa-reply';
                                                $badgeClass = 'bg-info';
                                                break;
                                            case 'assignment':
                                                $icon = 'fas fa-tasks';
                                                $badgeClass = 'bg-success';
                                                break;
                                            case 'deadline':
                                                $icon = 'fas fa-clock';
                                                $badgeClass = 'bg-danger';
                                                break;
                                        }
                                        ?>
                                        <i class="<?php echo $icon; ?> me-2"></i>
                                        <span class="badge <?php echo $badgeClass; ?> me-2"><?php echo ucfirst(str_replace('_', ' ', $notification['type'])); ?></span>
                                        <?php if (!$notification['is_read']): ?>
                                            <span class="badge bg-danger">New</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mb-2"><?php echo htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <small class="text-muted">
                                        <i class="fas fa-clock"></i> <?php echo date('M d, Y H:i', strtotime($notification['created_at'])); ?>
                                    </small>
                                </div>
                                <div class="ms-3">
                                    <div class="btn-group-vertical btn-group-sm">
                                        <?php if ($notification['link']): ?>
                                            <?php
                                                $rawLink = $notification['link'];
                                                $href = $rawLink;
                                                if (!preg_match('/^https?:\/\//i', $rawLink)) {
                                                    if ($baseDir !== '' && strpos($rawLink, $baseDir . '/') === 0) {
                                                        $href = $rawLink;
                                                    } else {
                                                        $href = $baseDir . $rawLink;
                                                    }
                                                }
                                            ?>
                                            <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-external-link-alt"></i> View
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if (!$notification['is_read']): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                                <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                                <button type="submit" name="mark_read" class="btn btn-outline-success btn-sm">
                                                    <i class="fas fa-check"></i> Mark Read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <form id="deleteNotificationForm_<?php echo $notification['id']; ?>" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                            <input type="hidden" name="delete_notification" value="1">
                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmForm('deleteNotificationForm_<?php echo $notification['id']; ?>', 'Delete this notification?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>">Previous</a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>">Next</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; 