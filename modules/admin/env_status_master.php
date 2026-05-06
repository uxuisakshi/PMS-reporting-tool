<?php
/**
 * Environment Status Master Management
 * Allows admin to manage environment testing status options
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $statusKey = trim($_POST['status_key'] ?? '');
        $statusLabel = trim($_POST['status_label'] ?? '');
        $badgeColor = trim($_POST['badge_color'] ?? 'secondary');
        $description = trim($_POST['description'] ?? '');
        $displayOrder = (int)($_POST['display_order'] ?? 0);
        
        if ($statusKey && $statusLabel) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO env_status_master (status_key, status_label, badge_color, description, display_order)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$statusKey, $statusLabel, $badgeColor, $description, $displayOrder]);
                $_SESSION['success'] = 'Environment status added successfully!';
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Error adding status: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Status key and label are required.';
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $statusLabel = trim($_POST['status_label'] ?? '');
        $badgeColor = trim($_POST['badge_color'] ?? 'secondary');
        $description = trim($_POST['description'] ?? '');
        $displayOrder = (int)($_POST['display_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id && $statusLabel) {
            try {
                $stmt = $db->prepare("
                    UPDATE env_status_master 
                    SET status_label = ?, badge_color = ?, description = ?, display_order = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$statusLabel, $badgeColor, $description, $displayOrder, $isActive, $id]);
                $_SESSION['success'] = 'Environment status updated successfully!';
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Error updating status: ' . $e->getMessage();
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                // Check if status is in use
                $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM page_environments WHERE BINARY status = (SELECT BINARY status_key FROM env_status_master WHERE id = ?)");
                $checkStmt->execute([$id]);
                $usage = $checkStmt->fetch();
                
                if ($usage['count'] > 0) {
                    $_SESSION['error'] = 'Cannot delete status: It is currently in use by ' . $usage['count'] . ' environment(s).';
                } else {
                    $stmt = $db->prepare("DELETE FROM env_status_master WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['success'] = 'Environment status deleted successfully!';
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Error deleting status: ' . $e->getMessage();
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Fetch all statuses
$stmt = $db->query("SELECT * FROM env_status_master ORDER BY display_order ASC, status_label ASC");
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Environment Status Master';
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Environment Status Master</li>
                </ol>
            </nav>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-0"><i class="fas fa-tasks me-2"></i>Environment Status Master</h4>
                <small class="text-muted">Manage environment testing status options</small>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStatusModal">
                <i class="fas fa-plus me-1"></i> Add Status
            </button>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Note:</strong> These statuses are used for environment testing (AT/FT testers). 
                Changes here will affect all environment status dropdowns across the system.
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">Order</th>
                            <th>Status Key</th>
                            <th>Status Label</th>
                            <th>Badge Preview</th>
                            <th>Description</th>
                            <th style="width: 80px;">Active</th>
                            <th style="width: 100px;">Usage</th>
                            <th style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statuses as $status): 
                            // Count usage
                            $usageStmt = $db->prepare("SELECT COUNT(*) as count FROM page_environments WHERE BINARY status = BINARY ?");
                            $usageStmt->execute([$status['status_key']]);
                            $usage = $usageStmt->fetch();
                            $usageCount = $usage['count'];
                        ?>
                        <tr>
                            <td><?php echo $status['display_order']; ?></td>
                            <td><code><?php echo htmlspecialchars($status['status_key']); ?></code></td>
                            <td><?php echo htmlspecialchars($status['status_label']); ?></td>
                            <td>
                                <?php
                                // Calculate text color based on background brightness
                                $bgColor = $status['badge_color'];
                                $textColor = '#ffffff'; // Default white
                                
                                // Remove # if present
                                $hex = ltrim($bgColor, '#');
                                
                                // Convert hex to RGB
                                if (strlen($hex) == 6) {
                                    $r = hexdec(substr($hex, 0, 2));
                                    $g = hexdec(substr($hex, 2, 2));
                                    $b = hexdec(substr($hex, 4, 2));
                                    
                                    // Calculate brightness (0-255)
                                    $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
                                    
                                    // If bright background, use dark text
                                    if ($brightness > 155) {
                                        $textColor = '#000000';
                                    }
                                }
                                ?>
                                <span class="badge" style="background-color: <?php echo htmlspecialchars($bgColor); ?>; color: <?php echo $textColor; ?>;">
                                    <?php echo htmlspecialchars($status['status_label']); ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars($status['description'] ?: '-'); ?></td>
                            <td>
                                <?php if ($status['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($usageCount > 0): ?>
                                    <span class="badge bg-info"><?php echo $usageCount; ?> envs</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="editStatus(<?php echo htmlspecialchars(json_encode($status)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" 
                                        onclick="deleteStatus(<?php echo $status['id']; ?>, '<?php echo htmlspecialchars($status['status_label']); ?>', <?php echo $usageCount; ?>)"
                                        <?php echo $usageCount > 0 ? 'disabled title="Cannot delete: In use"' : ''; ?>>
                                    <i class="fas fa-trash"></i>
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
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add Environment Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Status Key <span class="text-danger">*</span></label>
                        <input type="text" name="status_key" class="form-control" required 
                               placeholder="e.g., blocked" pattern="[a-z_]+" 
                               title="Lowercase letters and underscores only">
                        <small class="text-muted">Internal identifier (lowercase, underscores only)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status Label <span class="text-danger">*</span></label>
                        <input type="text" name="status_label" class="form-control" required 
                               placeholder="e.g., Blocked">
                        <small class="text-muted">Display name shown to users</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Badge Color</label>
                        <select name="badge_color" class="form-select">
                            <option value="primary">Primary (Blue)</option>
                            <option value="secondary" selected>Secondary (Gray)</option>
                            <option value="success">Success (Green)</option>
                            <option value="danger">Danger (Red)</option>
                            <option value="warning">Warning (Yellow)</option>
                            <option value="info">Info (Cyan)</option>
                            <option value="light">Light</option>
                            <option value="dark">Dark</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" 
                                  placeholder="Optional description of what this status means"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-control" value="0" min="0">
                        <small class="text-muted">Lower numbers appear first</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Status</button>
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
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Environment Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Status Key</label>
                        <input type="text" id="edit_status_key" class="form-control" readonly>
                        <small class="text-muted">Cannot be changed (used in database)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status Label <span class="text-danger">*</span></label>
                        <input type="text" name="status_label" id="edit_status_label" class="form-control" required>
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
                            <option value="light">Light</option>
                            <option value="dark">Dark</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" id="edit_display_order" class="form-control" min="0">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input" value="1">
                            <label class="form-check-label" for="edit_is_active">Active</label>
                        </div>
                        <small class="text-muted">Inactive statuses won't appear in dropdowns</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Form -->
<form method="POST" id="deleteForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete_id">
</form>

<div class="modal fade" id="deleteEnvStatusConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="deleteEnvStatusConfirmText">Are you sure you want to delete this status?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="deleteEnvStatusConfirmBtn">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo getBaseDir(); ?>/assets/js/env-status-master.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/../../includes/footer.php'; 