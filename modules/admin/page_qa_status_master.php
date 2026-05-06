<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();
$baseDir = getBaseDir();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: page_qa_status_master.php');
        exit;
    }
    if (isset($_POST['update_status'])) {
        $statusId = (int)$_POST['status_id'];
        $statusLabel = sanitizeInput($_POST['status_label']);
        $statusDescription = sanitizeInput($_POST['status_description']);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $displayOrder = (int)$_POST['display_order'];
        $badgeColor = sanitizeInput($_POST['badge_color']);
        
        $stmt = $db->prepare("
            UPDATE page_qa_status_master 
            SET status_label = ?, 
                status_description = ?, 
                is_active = ?, 
                display_order = ?,
                badge_color = ?
            WHERE id = ?
        ");
        
        if ($stmt->execute([$statusLabel, $statusDescription, $isActive, $displayOrder, $badgeColor, $statusId])) {
            $_SESSION['success'] = "QA Status updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update QA status.";
        }
        
        header("Location: page_qa_status_master.php");
        exit;
    }
    
    if (isset($_POST['add_status'])) {
        $statusKey = sanitizeInput($_POST['status_key']);
        $statusLabel = sanitizeInput($_POST['status_label']);
        $statusDescription = sanitizeInput($_POST['status_description']);
        $displayOrder = (int)$_POST['display_order'];
        $badgeColor = sanitizeInput($_POST['badge_color']);
        
        $stmt = $db->prepare("
            INSERT INTO page_qa_status_master (status_key, status_label, status_description, display_order, badge_color)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$statusKey, $statusLabel, $statusDescription, $displayOrder, $badgeColor])) {
            $_SESSION['success'] = "QA Status added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add QA status. Status key might already exist.";
        }
        
        header("Location: page_qa_status_master.php");
        exit;
    }
}

// Get all QA statuses
$statuses = $db->query("SELECT * FROM page_qa_status_master ORDER BY display_order ASC")->fetchAll();

// Get usage statistics
$usageStats = [];
foreach ($statuses as $status) {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM page_environments WHERE qa_status = ?");
    $stmt->execute([$status['status_key']]);
    $usageStats[$status['id']] = $stmt->fetch()['count'];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-check-circle text-primary"></i> Page QA Status Master</h2>
        <div>
            <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addStatusModal">
                <i class="fas fa-plus"></i> Add New Status
            </button>
            <a href="<?php echo $baseDir; ?>/modules/admin/dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Status Key</th>
                            <th>Status Label</th>
                            <th>Description</th>
                            <th>Badge</th>
                            <th>Display Order</th>
                            <th>Active</th>
                            <th>Usage</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statuses as $status): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($status['status_key']); ?></code></td>
                            <td><?php echo htmlspecialchars($status['status_label']); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($status['status_description'] ?? '-'); ?></td>
                            <td>
                                <span class="badge bg-<?php echo htmlspecialchars($status['badge_color']); ?>">
                                    <?php echo htmlspecialchars($status['status_label']); ?>
                                </span>
                            </td>
                            <td><?php echo $status['display_order']; ?></td>
                            <td>
                                <?php if ($status['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo $usageStats[$status['id']] ?? 0; ?> pages</span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-primary" 
                                        onclick="editStatus(<?php echo htmlspecialchars(json_encode($status)); ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Status Modal -->
<div class="modal fade" id="addStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add New QA Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Status Key <span class="text-danger">*</span></label>
                        <input type="text" name="status_key" class="form-control" required 
                               placeholder="e.g., in_review">
                        <small class="text-muted">Unique identifier (lowercase, no spaces)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status Label <span class="text-danger">*</span></label>
                        <input type="text" name="status_label" class="form-control" required 
                               placeholder="e.g., In Review">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="status_description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Badge Color</label>
                        <select name="badge_color" class="form-select">
                            <option value="primary">Primary (Blue)</option>
                            <option value="secondary">Secondary (Gray)</option>
                            <option value="success">Success (Green)</option>
                            <option value="danger">Danger (Red)</option>
                            <option value="warning">Warning (Yellow)</option>
                            <option value="info">Info (Cyan)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-control" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_status" class="btn btn-primary">Add Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Status Modal -->
<div class="modal fade" id="editStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="status_id" id="edit_status_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit QA Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Status Key</label>
                        <input type="text" id="edit_status_key" class="form-control" disabled>
                        <small class="text-muted">Cannot be changed</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status Label <span class="text-danger">*</span></label>
                        <input type="text" name="status_label" id="edit_status_label" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="status_description" id="edit_status_description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Badge Color</label>
                        <select name="badge_color" id="edit_badge_color" class="form-select">
                            <option value="primary">Primary (Blue)</option>
                            <option value="secondary">Secondary (Gray)</option>
                            <option value="success">Success (Green)</option>
                            <option value="danger">Danger (Red)</option>
                            <option value="warning">Warning (Yellow)</option>
                            <option value="info">Info (Cyan)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" id="edit_display_order" class="form-control">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input">
                            <label class="form-check-label" for="edit_is_active">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/admin-page-qa-status.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; 