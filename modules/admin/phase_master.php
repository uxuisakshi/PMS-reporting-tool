<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Handle Add Phase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_phase'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: phase_master.php');
        exit;
    }
    $phaseName = trim($_POST['phase_name']);
    $description = trim($_POST['phase_description']);
    $duration = !empty($_POST['typical_duration_days']) ? (int)$_POST['typical_duration_days'] : null;
    $displayOrder = !empty($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
    
    if (!empty($phaseName)) {
        try {
            $stmt = $db->prepare("INSERT INTO phase_master (phase_name, phase_description, typical_duration_days, display_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$phaseName, $description, $duration, $displayOrder]);
            $_SESSION['success'] = "Phase added successfully!";
            logActivity($db, $userId, 'add_phase_master', 'system', $db->lastInsertId(), ['phase_name' => $phaseName]);
        } catch (PDOException $e) {
            $_SESSION['error'] = "Failed to add phase. It may already exist.";
        }
    } else {
        $_SESSION['error'] = "Phase name is required!";
    }
    header("Location: phase_master.php");
    exit;
}

// Handle Update Phase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_phase'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: phase_master.php');
        exit;
    }
    $phaseId = (int)$_POST['phase_id'];
    $phaseName = trim($_POST['phase_name']);
    $description = trim($_POST['phase_description']);
    $duration = !empty($_POST['typical_duration_days']) ? (int)$_POST['typical_duration_days'] : null;
    $displayOrder = !empty($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    if (!empty($phaseName)) {
        try {
            $stmt = $db->prepare("UPDATE phase_master SET phase_name = ?, phase_description = ?, typical_duration_days = ?, display_order = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$phaseName, $description, $duration, $displayOrder, $isActive, $phaseId]);
            $_SESSION['success'] = "Phase updated successfully!";
            logActivity($db, $userId, 'update_phase_master', 'system', $phaseId, ['phase_name' => $phaseName]);
        } catch (PDOException $e) {
            $_SESSION['error'] = "Failed to update phase.";
        }
    } else {
        $_SESSION['error'] = "Phase name is required!";
    }
    header("Location: phase_master.php");
    exit;
}

// Handle Delete Phase
if (isset($_GET['delete'])) {
    $phaseId = (int)$_GET['delete'];
    
    // Check if phase is being used in any projects
    $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM project_phases WHERE phase_master_id = ?");
    $checkStmt->execute([$phaseId]);
    $usage = $checkStmt->fetch();
    
    if ($usage['count'] > 0) {
        $_SESSION['error'] = "Cannot delete phase. It is being used in {$usage['count']} project(s).";
    } else {
        $stmt = $db->prepare("DELETE FROM phase_master WHERE id = ?");
        $stmt->execute([$phaseId]);
        $_SESSION['success'] = "Phase deleted successfully!";
        logActivity($db, $userId, 'delete_phase_master', 'system', $phaseId, []);
    }
    header("Location: phase_master.php");
    exit;
}

// Handle Toggle Active Status
if (isset($_GET['toggle'])) {
    $phaseId = (int)$_GET['toggle'];
    $stmt = $db->prepare("UPDATE phase_master SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$phaseId]);
    $_SESSION['success'] = "Phase status updated!";
    header("Location: phase_master.php");
    exit;
}

// Fetch all phases
$phases = $db->query("SELECT * FROM phase_master ORDER BY display_order ASC, phase_name ASC")->fetchAll();

// Get usage statistics
$usageStats = [];
foreach ($phases as $phase) {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM project_phases WHERE phase_master_id = ?");
    $stmt->execute([$phase['id']]);
    $usageStats[$phase['id']] = $stmt->fetchColumn();
}

// Add cache-busting headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-layer-group text-primary"></i> Manage Phase Master</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPhaseModal">
            <i class="fas fa-plus"></i> Add New Phase
        </button>
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
        <div class="card-header">
            <h5 class="mb-0">Phase Names</h5>
            <small class="text-muted">Manage standard phase names used across all projects</small>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 50px;">Order</th>
                            <th>Phase Name</th>
                            <th>Description</th>
                            <th style="width: 120px;">Duration (Days)</th>
                            <th style="width: 100px;">Usage</th>
                            <th style="width: 80px;">Status</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($phases as $phase): ?>
                        <tr>
                            <td class="text-center"><?php echo $phase['display_order']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($phase['phase_name']); ?></strong>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars(substr($phase['phase_description'], 0, 80)); ?>
                                    <?php if (strlen($phase['phase_description']) > 80) echo '...'; ?>
                                </small>
                            </td>
                            <td class="text-center">
                                <?php echo $phase['typical_duration_days'] ?: '-'; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info">
                                    <?php echo $usageStats[$phase['id']] ?? 0; ?> projects
                                </span>
                            </td>
                            <td>
                                <?php if ($phase['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editPhaseModal<?php echo $phase['id']; ?>"
                                        title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" 
                                   class="btn btn-sm btn-outline-warning toggle-phase-btn"
                                   title="Toggle Status"
                                   data-phase-id="<?php echo $phase['id']; ?>"
                                   data-phase-name="<?php echo htmlspecialchars($phase['phase_name']); ?>"
                                   data-is-active="<?php echo $phase['is_active'] ? '1' : '0'; ?>">
                                    <i class="fas fa-toggle-on"></i>
                                </button>
                                <?php if (($usageStats[$phase['id']] ?? 0) == 0): ?>
                                <button type="button" 
                                   class="btn btn-sm btn-outline-danger delete-phase-btn"
                                   title="Delete"
                                   data-phase-id="<?php echo $phase['id']; ?>"
                                   data-phase-name="<?php echo htmlspecialchars($phase['phase_name']); ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled title="Cannot delete - in use">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Phase Modal -->
<div class="modal fade" id="addPhaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Phase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Phase Name <span class="text-danger">*</span></label>
                        <input type="text" name="phase_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="phase_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Typical Duration (Days)</label>
                                <input type="number" name="typical_duration_days" class="form-control" min="1">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Display Order</label>
                                <input type="number" name="display_order" class="form-control" value="0" min="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_phase" class="btn btn-primary">Add Phase</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Phase Modals -->
<?php foreach ($phases as $phase): ?>
<div class="modal fade" id="editPhaseModal<?php echo $phase['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="phase_id" value="<?php echo $phase['id']; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Phase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Phase Name <span class="text-danger">*</span></label>
                        <input type="text" name="phase_name" class="form-control" value="<?php echo htmlspecialchars($phase['phase_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="phase_description" class="form-control" rows="3"><?php echo htmlspecialchars($phase['phase_description']); ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Typical Duration (Days)</label>
                                <input type="number" name="typical_duration_days" class="form-control" value="<?php echo $phase['typical_duration_days']; ?>" min="1">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Display Order</label>
                                <input type="number" name="display_order" class="form-control" value="<?php echo $phase['display_order']; ?>" min="0">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="active<?php echo $phase['id']; ?>" <?php echo $phase['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="active<?php echo $phase['id']; ?>">
                                Active
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_phase" class="btn btn-primary">Update Phase</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deletePhaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to delete the phase <strong id="deletePhaseNameDisplay"></strong>?</p>
                <p class="text-muted small mt-2 mb-0">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete Phase
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Toggle Status Confirmation Modal -->
<div class="modal fade" id="togglePhaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-toggle-on"></i> Confirm Status Change</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to <strong id="toggleActionText"></strong> the phase <strong id="togglePhaseNameDisplay"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmToggleBtn" class="btn btn-warning">
                    <i class="fas fa-toggle-on"></i> Change Status
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Delete phase button click handler
document.addEventListener('DOMContentLoaded', function() {
    // Delete buttons
    document.querySelectorAll('.delete-phase-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const phaseId = this.getAttribute('data-phase-id');
            const phaseName = this.getAttribute('data-phase-name');
            
            document.getElementById('deletePhaseNameDisplay').textContent = phaseName;
            document.getElementById('confirmDeleteBtn').href = '?delete=' + phaseId;
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deletePhaseModal'));
            deleteModal.show();
        });
    });
    
    // Toggle buttons
    document.querySelectorAll('.toggle-phase-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const phaseId = this.getAttribute('data-phase-id');
            const phaseName = this.getAttribute('data-phase-name');
            const isActive = this.getAttribute('data-is-active') === '1';
            
            document.getElementById('togglePhaseNameDisplay').textContent = phaseName;
            document.getElementById('toggleActionText').textContent = isActive ? 'deactivate' : 'activate';
            document.getElementById('confirmToggleBtn').href = '?toggle=' + phaseId;
            
            var toggleModal = new bootstrap.Modal(document.getElementById('togglePhaseModal'));
            toggleModal.show();
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; 