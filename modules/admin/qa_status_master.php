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
        header('Location: qa_status_master.php');
        exit;
    }
    if (isset($_POST['update_status'])) {
        $statusId = (int)$_POST['status_id'];
        $statusLabel = sanitizeInput($_POST['status_label']);
        $statusDescription = sanitizeInput($_POST['status_description']);
        $severityLevel = sanitizeInput($_POST['severity_level']);
        $errorPoints = floatval($_POST['error_points']);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $displayOrder = (int)$_POST['display_order'];
        $badgeColor = sanitizeInput($_POST['badge_color']);
        
        $stmt = $db->prepare("
            UPDATE qa_status_master 
            SET status_label = ?, 
                status_description = ?, 
                severity_level = ?,
                error_points = ?,
                is_active = ?, 
                display_order = ?,
                badge_color = ?
            WHERE id = ?
        ");
        
        if ($stmt->execute([$statusLabel, $statusDescription, $severityLevel, $errorPoints, $isActive, $displayOrder, $badgeColor, $statusId])) {
            $_SESSION['success'] = "QA Status updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update QA status.";
        }
        
        header("Location: qa_status_master.php");
        exit;
    }
    
    if (isset($_POST['add_status'])) {
        $statusKey = sanitizeInput($_POST['status_key']);
        $statusLabel = sanitizeInput($_POST['status_label']);
        $statusDescription = sanitizeInput($_POST['status_description']);
        $severityLevel = sanitizeInput($_POST['severity_level']);
        $errorPoints = floatval($_POST['error_points']);
        $displayOrder = (int)$_POST['display_order'];
        $badgeColor = sanitizeInput($_POST['badge_color']);
        
        $stmt = $db->prepare("
            INSERT INTO qa_status_master (status_key, status_label, status_description, severity_level, error_points, display_order, badge_color)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$statusKey, $statusLabel, $statusDescription, $severityLevel, $errorPoints, $displayOrder, $badgeColor])) {
            $_SESSION['success'] = "QA Status added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add QA status. Status key might already exist.";
        }
        
        header("Location: qa_status_master.php");
        exit;
    }

    if (isset($_POST['delete_status'])) {
        $statusId = (int)($_POST['status_id'] ?? 0);
        if ($statusId <= 0) {
            $_SESSION['error'] = "Invalid status selected.";
            header("Location: qa_status_master.php");
            exit;
        }

        // Prevent delete when status is in use.
        $inUseStmt = $db->prepare("SELECT COUNT(*) as count FROM user_qa_performance WHERE qa_status_id = ?");
        $inUseStmt->execute([$statusId]);
        $inUse = (int)($inUseStmt->fetch()['count'] ?? 0);

        if ($inUse > 0) {
            $_SESSION['error'] = "Cannot delete QA status: it is currently used in {$inUse} record(s).";
            header("Location: qa_status_master.php");
            exit;
        }

        $delStmt = $db->prepare("DELETE FROM qa_status_master WHERE id = ?");
        if ($delStmt->execute([$statusId])) {
            $_SESSION['success'] = "QA Status deleted successfully!";
        } else {
            $_SESSION['error'] = "Failed to delete QA status.";
        }

        header("Location: qa_status_master.php");
        exit;
    }
}

// Get all QA statuses
$statuses = $db->query("SELECT * FROM qa_status_master ORDER BY severity_level, display_order ASC")->fetchAll();

// Get usage statistics
$usageStats = [];
foreach ($statuses as $status) {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM user_qa_performance WHERE qa_status_id = ?");
    $stmt->execute([$status['id']]);
    $usageStats[$status['id']] = $stmt->fetch()['count'];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-clipboard-check text-primary"></i> QA Status Master</h2>
        <div>
            <a href="<?php echo $baseDir; ?>/modules/admin/user_performance.php" class="btn btn-info me-2">
                <i class="fas fa-chart-line"></i> View Performance Reports
            </a>
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

    <!-- Severity Level Summary -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Level 1 - Minor Issues</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Low error rate impact (0.25 - 0.75 points)</p>
                    <ul class="list-unstyled">
                        <?php foreach ($statuses as $status): ?>
                            <?php if ($status['severity_level'] == '1' && $status['error_points'] > 0): ?>
                                <li class="mb-2">
                                    <span class="badge bg-<?php echo $status['badge_color']; ?> me-2">
                                        <?php echo htmlspecialchars($status['status_label']); ?>
                                    </span>
                                    <small class="text-muted"><?php echo $status['error_points']; ?> pts</small>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Level 2 - Moderate Issues</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Medium error rate impact (0.75 - 1.50 points)</p>
                    <ul class="list-unstyled">
                        <?php foreach ($statuses as $status): ?>
                            <?php if ($status['severity_level'] == '2'): ?>
                                <li class="mb-2">
                                    <span class="badge bg-<?php echo $status['badge_color']; ?> me-2">
                                        <?php echo htmlspecialchars($status['status_label']); ?>
                                    </span>
                                    <small class="text-muted"><?php echo $status['error_points']; ?> pts</small>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-times-circle"></i> Level 3 - Major Issues</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">High error rate impact (2.00 - 3.00 points)</p>
                    <ul class="list-unstyled">
                        <?php foreach ($statuses as $status): ?>
                            <?php if ($status['severity_level'] == '3'): ?>
                                <li class="mb-2">
                                    <span class="badge bg-<?php echo $status['badge_color']; ?> me-2">
                                        <?php echo htmlspecialchars($status['status_label']); ?>
                                    </span>
                                    <small class="text-muted"><?php echo $status['error_points']; ?> pts</small>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Add New Status Button -->
    <div class="mb-3">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStatusModal">
            <i class="fas fa-plus"></i> Add New QA Status
        </button>
    </div>

    <!-- QA Statuses Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list"></i> Manage QA Statuses</h5>
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
                            <th>Severity</th>
                            <th>Error Points</th>
                            <th>Badge</th>
                            <th>Active</th>
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
                            <td><small><?php echo htmlspecialchars($status['status_description']); ?></small></td>
                            <td>
                                <?php
                                $severityColors = ['1' => 'info', '2' => 'warning', '3' => 'danger'];
                                $severityLabels = ['1' => 'Minor', '2' => 'Moderate', '3' => 'Major'];
                                ?>
                                <span class="badge bg-<?php echo $severityColors[$status['severity_level']]; ?>">
                                    Level <?php echo $status['severity_level']; ?> - <?php echo $severityLabels[$status['severity_level']]; ?>
                                </span>
                            </td>
                            <td><strong><?php echo number_format($status['error_points'], 2); ?></strong> pts</td>
                            <td>
                                <span class="badge bg-<?php echo $status['badge_color']; ?>">
                                    <?php echo $status['badge_color']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($status['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo $usageStats[$status['id']]; ?></span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editModal<?php echo $status['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger"
                                        onclick="deleteQaStatus(<?php echo (int)$status['id']; ?>, '<?php echo htmlspecialchars($status['status_label'], ENT_QUOTES, 'UTF-8'); ?>', <?php echo (int)$usageStats[$status['id']]; ?>)"
                                        <?php echo ((int)$usageStats[$status['id']] > 0) ? 'disabled title="Cannot delete: In use"' : ''; ?>>
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editModal<?php echo $status['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit QA Status</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="status_id" value="<?php echo $status['id']; ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Status Key (Read-only)</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($status['status_key']); ?>" readonly>
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

                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Severity Level *</label>
                                                    <select name="severity_level" class="form-select" required>
                                                        <option value="1" <?php echo $status['severity_level'] == '1' ? 'selected' : ''; ?>>Level 1 - Minor</option>
                                                        <option value="2" <?php echo $status['severity_level'] == '2' ? 'selected' : ''; ?>>Level 2 - Moderate</option>
                                                        <option value="3" <?php echo $status['severity_level'] == '3' ? 'selected' : ''; ?>>Level 3 - Major</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Error Points *</label>
                                                    <input type="number" name="error_points" class="form-control" 
                                                           value="<?php echo $status['error_points']; ?>" 
                                                           step="0.25" min="0" max="10" required>
                                                    <small class="text-muted">0 = No error impact</small>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Display Order</label>
                                                    <input type="number" name="display_order" class="form-control" 
                                                           value="<?php echo $status['display_order']; ?>" min="0">
                                                </div>

                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Badge Color</label>
                                                    <select name="badge_color" class="form-select">
                                                        <option value="primary" <?php echo $status['badge_color'] === 'primary' ? 'selected' : ''; ?>>Primary</option>
                                                        <option value="secondary" <?php echo $status['badge_color'] === 'secondary' ? 'selected' : ''; ?>>Secondary</option>
                                                        <option value="success" <?php echo $status['badge_color'] === 'success' ? 'selected' : ''; ?>>Success</option>
                                                        <option value="danger" <?php echo $status['badge_color'] === 'danger' ? 'selected' : ''; ?>>Danger</option>
                                                        <option value="warning" <?php echo $status['badge_color'] === 'warning' ? 'selected' : ''; ?>>Warning</option>
                                                        <option value="info" <?php echo $status['badge_color'] === 'info' ? 'selected' : ''; ?>>Info</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <div class="form-check">
                                                    <input type="checkbox" name="is_active" class="form-check-input" 
                                                           id="active<?php echo $status['id']; ?>"
                                                           <?php echo $status['is_active'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="active<?php echo $status['id']; ?>">
                                                        Active Status
                                                    </label>
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
            <h5 class="mb-0"><i class="fas fa-info-circle"></i> Error Rate Calculation Guide</h5>
        </div>
        <div class="card-body">
            <h6>How Error Rate is Calculated:</h6>
            <p>Error Rate = (Total Error Points / Total Issues) Ã— 100</p>
            
            <h6 class="mt-3">Severity Levels:</h6>
            <ul>
                <li><strong>Level 1 (Minor):</strong> 0.25 - 0.75 points - Small mistakes, easy to fix</li>
                <li><strong>Level 2 (Moderate):</strong> 0.75 - 1.50 points - Significant issues requiring rework</li>
                <li><strong>Level 3 (Major):</strong> 2.00 - 3.00 points - Critical errors, missed issues</li>
            </ul>

            <h6 class="mt-3">Example:</h6>
            <p>User reported 10 issues:</p>
            <ul>
                <li>2 Ã— Typo (0.25 pts each) = 0.50 pts</li>
                <li>3 Ã— Change in Severity (1.00 pts each) = 3.00 pts</li>
                <li>1 Ã— Missed Issue (3.00 pts) = 3.00 pts</li>
                <li>4 Ã— Perfect Issue (0.00 pts) = 0.00 pts</li>
            </ul>
            <p><strong>Error Rate = (6.50 / 10) Ã— 100 = 65%</strong></p>
        </div>
    </div>
</div>

<form method="POST" id="deleteQaStatusForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
    <input type="hidden" name="delete_status" value="1">
    <input type="hidden" name="status_id" id="deleteQaStatusId">
</form>

<div class="modal fade" id="deleteQaStatusConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="deleteQaStatusConfirmText">Are you sure you want to delete this QA status?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteQaStatusBtn">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo getBaseDir(); ?>/assets/js/qa-status-master.js?v=<?php echo time(); ?>"></script>

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
                        <label class="form-label">Status Key * (lowercase_with_underscores)</label>
                        <input type="text" name="status_key" class="form-control" 
                               placeholder="e.g., missing_wcag_reference" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status Label *</label>
                        <input type="text" name="status_label" class="form-control" 
                               placeholder="e.g., Missing WCAG Reference" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="status_description" class="form-control" rows="2" 
                                  placeholder="Brief description of this status"></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Severity Level *</label>
                            <select name="severity_level" class="form-select" required>
                                <option value="1">Level 1 - Minor</option>
                                <option value="2" selected>Level 2 - Moderate</option>
                                <option value="3">Level 3 - Major</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Error Points *</label>
                            <input type="number" name="error_points" class="form-control" 
                                   value="1.00" step="0.25" min="0" max="10" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Display Order</label>
                            <input type="number" name="display_order" class="form-control" 
                                   value="100" min="0">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Badge Color</label>
                            <select name="badge_color" class="form-select">
                                <option value="info">Info</option>
                                <option value="warning" selected>Warning</option>
                                <option value="danger">Danger</option>
                                <option value="success">Success</option>
                                <option value="secondary">Secondary</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_status" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 