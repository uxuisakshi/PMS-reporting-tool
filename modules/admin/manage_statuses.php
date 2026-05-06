<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: manage_statuses.php');
        exit;
    }
    if (isset($_POST['update_status'])) {
        $statusId = (int)$_POST['status_id'];
        $statusLabel = sanitizeInput($_POST['status_label']);
        $statusDescription = sanitizeInput($_POST['status_description']);
        $isActiveStatus = isset($_POST['is_active_status']) ? 1 : 0;
        $displayOrder = (int)$_POST['display_order'];
        $badgeColor = sanitizeInput($_POST['badge_color']);
        
        $stmt = $db->prepare("
            UPDATE project_statuses 
            SET status_label = ?, 
                status_description = ?, 
                is_active_status = ?, 
                display_order = ?,
                badge_color = ?
            WHERE id = ?
        ");
        
        if ($stmt->execute([$statusLabel, $statusDescription, $isActiveStatus, $displayOrder, $badgeColor, $statusId])) {
            $_SESSION['success'] = "Status updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update status.";
        }
        
        header("Location: manage_statuses.php");
        exit;
    }

    if (isset($_POST['add_status'])) {
        $statusKey         = strtolower(trim(preg_replace('/[^a-z0-9_]/i', '_', sanitizeInput($_POST['status_key'] ?? ''))));
        $statusLabel       = sanitizeInput($_POST['status_label'] ?? '');
        $statusDescription = sanitizeInput($_POST['status_description'] ?? '');
        $isActiveStatus    = isset($_POST['is_active_status']) ? 1 : 0;
        $displayOrder      = (int)($_POST['display_order'] ?? 0);
        $badgeColor        = sanitizeInput($_POST['badge_color'] ?? 'secondary');

        if ($statusKey === '' || $statusLabel === '') {
            $_SESSION['error'] = 'Status key and label are required.';
            header('Location: manage_statuses.php');
            exit;
        }

        // Check duplicate key
        $dup = $db->prepare("SELECT id FROM project_statuses WHERE status_key = ? LIMIT 1");
        $dup->execute([$statusKey]);
        if ($dup->fetch()) {
            $_SESSION['error'] = "Status key '{$statusKey}' already exists. Please choose a unique key.";
            header('Location: manage_statuses.php');
            exit;
        }

        $ins = $db->prepare("
            INSERT INTO project_statuses (status_key, status_label, status_description, is_active_status, display_order, badge_color)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($ins->execute([$statusKey, $statusLabel, $statusDescription, $isActiveStatus, $displayOrder, $badgeColor])) {
            $_SESSION['success'] = "Status '{$statusLabel}' added successfully!";
        } else {
            $_SESSION['error'] = 'Failed to add status.';
        }
        header('Location: manage_statuses.php');
        exit;
    }

    if (isset($_POST['delete_status'])) {
        $statusId = (int)($_POST['status_id'] ?? 0);
        // Get status key first
        $row = $db->prepare("SELECT status_key, status_label FROM project_statuses WHERE id = ? LIMIT 1");
        $row->execute([$statusId]);
        $statusRow = $row->fetch(PDO::FETCH_ASSOC);
        if (!$statusRow) {
            $_SESSION['error'] = 'Status not found.';
            header('Location: manage_statuses.php');
            exit;
        }
        // Check usage
        $usage = $db->prepare("SELECT COUNT(*) FROM projects WHERE status = ?");
        $usage->execute([$statusRow['status_key']]);
        if ((int)$usage->fetchColumn() > 0) {
            $_SESSION['error'] = "Cannot delete '{$statusRow['status_label']}' — it is currently assigned to projects.";
            header('Location: manage_statuses.php');
            exit;
        }
        $db->prepare("DELETE FROM project_statuses WHERE id = ?")->execute([$statusId]);
        $_SESSION['success'] = "Status '{$statusRow['status_label']}' deleted.";
        header('Location: manage_statuses.php');
        exit;
    }
}

// Get all statuses
$statuses = $db->query("SELECT * FROM project_statuses ORDER BY display_order ASC")->fetchAll();

// Get status usage count
$statusUsage = [];
foreach ($statuses as $status) {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects WHERE status = ?");
    $stmt->execute([$status['status_key']]);
    $statusUsage[$status['status_key']] = $stmt->fetch()['count'];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-tags text-primary"></i> Project Status Master</h2>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStatusModal">
                <i class="fas fa-plus"></i> Add Status
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

    <!-- Status Information -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-check-circle"></i> Active Statuses</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Projects with these statuses will appear in "Active Projects" lists:</p>
                    <ul class="list-unstyled">
                        <?php foreach ($statuses as $status): ?>
                            <?php if ($status['is_active_status']): ?>
                                <li class="mb-2">
                                    <span class="badge bg-<?php echo $status['badge_color']; ?> me-2">
                                        <?php echo htmlspecialchars($status['status_label']); ?>
                                    </span>
                                    <small class="text-muted">(<?php echo $statusUsage[$status['status_key']]; ?> projects)</small>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="fas fa-archive"></i> Inactive Statuses</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Projects with these statuses will NOT appear in "Active Projects" lists:</p>
                    <ul class="list-unstyled">
                        <?php foreach ($statuses as $status): ?>
                            <?php if (!$status['is_active_status']): ?>
                                <li class="mb-2">
                                    <span class="badge bg-<?php echo $status['badge_color']; ?> me-2">
                                        <?php echo htmlspecialchars($status['status_label']); ?>
                                    </span>
                                    <small class="text-muted">(<?php echo $statusUsage[$status['status_key']]; ?> projects)</small>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Statuses Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list"></i> Manage Statuses</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Status Key</th>
                            <th>Label</th>
                            <th>Description</th>
                            <th>Badge Color</th>
                            <th>Active Status</th>
                            <th>Usage</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statuses as $status): ?>
                        <tr>
                            <td><?php echo $status['display_order']; ?></td>
                            <td><code><?php echo htmlspecialchars($status['status_key']); ?></code></td>
                            <td>
                                <span class="badge bg-<?php echo $status['badge_color']; ?>">
                                    <?php echo htmlspecialchars($status['status_label']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($status['status_description']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $status['badge_color']; ?>">
                                    <?php echo $status['badge_color']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($status['is_active_status']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo $statusUsage[$status['status_key']]; ?> projects</span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editModal<?php echo $status['id']; ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if ($statusUsage[$status['status_key']] == 0): ?>
                                <button type="button" class="btn btn-sm btn-danger ms-1"
                                        onclick="confirmDeleteStatus(<?php echo $status['id']; ?>, '<?php echo htmlspecialchars(addslashes($status['status_label']), ENT_QUOTES); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-1" disabled
                                        title="Cannot delete — used by <?php echo $statusUsage[$status['status_key']]; ?> project(s)">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editModal<?php echo $status['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Status: <?php echo htmlspecialchars($status['status_label']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="status_id" value="<?php echo $status['id']; ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Status Key (Read-only)</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($status['status_key']); ?>" readonly>
                                                <small class="text-muted">This cannot be changed as it's used in the database</small>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Status Label *</label>
                                                <input type="text" name="status_label" class="form-control" 
                                                       value="<?php echo htmlspecialchars($status['status_label']); ?>" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Description</label>
                                                <textarea name="status_description" class="form-control" rows="2"><?php echo htmlspecialchars($status['status_description']); ?></textarea>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Display Order</label>
                                                <input type="number" name="display_order" class="form-control" 
                                                       value="<?php echo $status['display_order']; ?>" min="0" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Badge Color</label>
                                                <select name="badge_color" class="form-select">
                                                    <option value="primary" <?php echo $status['badge_color'] === 'primary' ? 'selected' : ''; ?>>Primary (Blue)</option>
                                                    <option value="secondary" <?php echo $status['badge_color'] === 'secondary' ? 'selected' : ''; ?>>Secondary (Gray)</option>
                                                    <option value="success" <?php echo $status['badge_color'] === 'success' ? 'selected' : ''; ?>>Success (Green)</option>
                                                    <option value="danger" <?php echo $status['badge_color'] === 'danger' ? 'selected' : ''; ?>>Danger (Red)</option>
                                                    <option value="warning" <?php echo $status['badge_color'] === 'warning' ? 'selected' : ''; ?>>Warning (Yellow)</option>
                                                    <option value="info" <?php echo $status['badge_color'] === 'info' ? 'selected' : ''; ?>>Info (Cyan)</option>
                                                    <option value="dark" <?php echo $status['badge_color'] === 'dark' ? 'selected' : ''; ?>>Dark (Black)</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <div class="form-check">
                                                    <input type="checkbox" name="is_active_status" class="form-check-input" 
                                                           id="active<?php echo $status['id']; ?>"
                                                           <?php echo $status['is_active_status'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="active<?php echo $status['id']; ?>">
                                                        Show in Active Projects List
                                                    </label>
                                                    <small class="form-text text-muted d-block">
                                                        If checked, projects with this status will appear in "Active Projects" lists
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_status" class="btn btn-primary">
                                                <i class="fas fa-save"></i> Save Changes
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Usage Guide -->
    <div class="card mt-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="fas fa-info-circle"></i> Status Usage Guide</h5>
        </div>
        <div class="card-body">
            <h6>Active Statuses (Show in Active Projects):</h6>
            <ul>
                <li><strong>Planning:</strong> PO received, scoping and planning phase</li>
                <li><strong>In Progress:</strong> Active testing and development work</li>
                <li><strong>Awaiting Client:</strong> Waiting for client feedback or approval</li>
                <li><strong>On Hold:</strong> Temporarily paused or blocked</li>
            </ul>

            <h6 class="mt-3">Inactive Statuses (Hidden from Active Projects):</h6>
            <ul>
                <li><strong>Completed:</strong> Project successfully finished</li>
                <li><strong>Cancelled:</strong> Project cancelled or terminated</li>
                <li><strong>Archived:</strong> Old completed projects moved to archive</li>
            </ul>

            <div class="alert alert-warning mt-3">
                <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> 
                Status keys cannot be changed as they are used in the database. You can only modify the label, description, order, color, and active/inactive flag.
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
                    <h5 class="modal-title"><i class="fas fa-plus-circle text-success me-2"></i>Add New Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Status Key <span class="text-danger">*</span></label>
                        <input type="text" name="status_key" class="form-control" required
                               placeholder="e.g. in_review" pattern="[a-zA-Z0-9_]+"
                               title="Only letters, numbers and underscores allowed">
                        <small class="text-muted">Unique identifier — only letters, numbers, underscores. Cannot be changed later.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status Label <span class="text-danger">*</span></label>
                        <input type="text" name="status_label" class="form-control" required placeholder="e.g. In Review" id="addStatusLabelInput">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="status_description" class="form-control" rows="2" placeholder="Optional description"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-control" value="<?php echo count($statuses) + 1; ?>" min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Badge Color</label>
                        <select name="badge_color" class="form-select" id="addBadgeColor">
                            <option value="primary">Primary (Blue)</option>
                            <option value="secondary" selected>Secondary (Gray)</option>
                            <option value="success">Success (Green)</option>
                            <option value="danger">Danger (Red)</option>
                            <option value="warning">Warning (Yellow)</option>
                            <option value="info">Info (Cyan)</option>
                            <option value="dark">Dark (Black)</option>
                        </select>
                        <div class="mt-2">Preview: <span id="addBadgePreview" class="badge bg-secondary">New Status</span></div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active_status" class="form-check-input" id="addIsActive" checked>
                            <label class="form-check-label" for="addIsActive">Show in Active Projects List</label>
                            <small class="form-text text-muted d-block">If checked, projects with this status appear in Active Projects lists</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_status" class="btn btn-success"><i class="fas fa-plus"></i> Add Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteStatusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="deleteStatusForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="delete_status" value="1">
                <input type="hidden" name="status_id" id="deleteStatusId">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="fas fa-trash me-2"></i>Delete Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><strong>This action cannot be undone.</strong></div>
                    <p>Are you sure you want to delete status: <strong id="deleteStatusName"></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>Yes, Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDeleteStatus(id, name) {
    document.getElementById('deleteStatusId').value = id;
    document.getElementById('deleteStatusName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteStatusModal')).show();
}
(function() {
    var sel = document.getElementById('addBadgeColor');
    var preview = document.getElementById('addBadgePreview');
    var labelInp = document.getElementById('addStatusLabelInput');
    if (!sel || !preview) return;
    function upd() {
        preview.className = 'badge bg-' + sel.value;
        preview.textContent = (labelInp && labelInp.value.trim()) ? labelInp.value.trim() : 'New Status';
    }
    sel.addEventListener('change', upd);
    if (labelInp) labelInp.addEventListener('input', upd);
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; 