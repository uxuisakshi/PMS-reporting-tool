<?php
/**
 * Availability Status Master Management
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();

ensureAvailabilityStatusMaster($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $statusKey = strtolower(trim((string)($_POST['status_key'] ?? '')));
        $statusLabel = trim((string)($_POST['status_label'] ?? ''));
        $badgeColor = trim((string)($_POST['badge_color'] ?? 'secondary'));
        $description = trim((string)($_POST['description'] ?? ''));
        $displayOrder = (int)($_POST['display_order'] ?? 0);

        if ($statusKey === '' || $statusLabel === '') {
            $_SESSION['error'] = 'Status key and label are required.';
        } elseif (!preg_match('/^[a-z_]+$/', $statusKey)) {
            $_SESSION['error'] = 'Status key must contain lowercase letters and underscores only.';
        } else {
            try {
                $stmt = $db->prepare("\n                    INSERT INTO availability_status_master\n                        (status_key, status_label, badge_color, description, display_order, is_active)\n                    VALUES (?, ?, ?, ?, ?, 1)\n                ");
                $stmt->execute([$statusKey, $statusLabel, $badgeColor, $description, $displayOrder]);
                $_SESSION['success'] = 'Availability status added successfully.';
            } catch (Exception $e) {
                $_SESSION['error'] = 'Unable to add status. It may already exist.';
            }
        }

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $statusLabel = trim((string)($_POST['status_label'] ?? ''));
        $badgeColor = trim((string)($_POST['badge_color'] ?? 'secondary'));
        $description = trim((string)($_POST['description'] ?? ''));
        $displayOrder = (int)($_POST['display_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0 || $statusLabel === '') {
            $_SESSION['error'] = 'Invalid status update request.';
        } else {
            try {
                $keyStmt = $db->prepare('SELECT status_key FROM availability_status_master WHERE id = ?');
                $keyStmt->execute([$id]);
                $statusKey = (string)$keyStmt->fetchColumn();
                if ($statusKey === 'not_updated') {
                    $isActive = 1;
                }

                $stmt = $db->prepare("\n                    UPDATE availability_status_master\n                    SET status_label = ?, badge_color = ?, description = ?, display_order = ?, is_active = ?\n                    WHERE id = ?\n                ");
                $stmt->execute([$statusLabel, $badgeColor, $description, $displayOrder, $isActive, $id]);
                $_SESSION['success'] = 'Availability status updated successfully.';
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to update status.';
            }
        }

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $keyStmt = $db->prepare('SELECT status_key FROM availability_status_master WHERE id = ?');
                $keyStmt->execute([$id]);
                $statusKey = (string)$keyStmt->fetchColumn();

                if ($statusKey === '') {
                    $_SESSION['error'] = 'Status not found.';
                } elseif ($statusKey === 'not_updated') {
                    $_SESSION['error'] = 'Not Updated status cannot be deleted.';
                } else {
                    $db->beginTransaction();
                    $remappedRows = 0;

                    // Remap existing daily-status data to a safe fallback before deletion.
                    $mapDailyStmt = $db->prepare("UPDATE user_daily_status SET status = 'not_updated' WHERE status = ?");
                    $mapDailyStmt->execute([$statusKey]);
                    $remappedRows += (int)$mapDailyStmt->rowCount();

                    // Optional table in some deployments; ignore if unavailable.
                    try {
                        $mapPendingStmt = $db->prepare("UPDATE user_pending_changes SET status = 'not_updated' WHERE status = ?");
                        $mapPendingStmt->execute([$statusKey]);
                        $remappedRows += (int)$mapPendingStmt->rowCount();
                    } catch (Exception $e) {
                        // user_pending_changes may not exist in older setups.
                    }

                    $delStmt = $db->prepare('DELETE FROM availability_status_master WHERE id = ?');
                    $delStmt->execute([$id]);

                    if ((int)$delStmt->rowCount() > 0) {
                        $db->commit();
                        $suffix = $remappedRows > 0 ? (" (" . $remappedRows . " record(s) remapped to Not Updated).") : '';
                        $_SESSION['success'] = 'Availability status deleted successfully.' . $suffix;
                    } else {
                        if ($db->inTransaction()) $db->rollBack();
                        $_SESSION['error'] = 'Status not found or already deleted.';
                    }
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error'] = 'Failed to delete status.';
            }
        }

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$stmt = $db->query("SELECT * FROM availability_status_master ORDER BY display_order ASC, status_label ASC");
$statuses = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$pageTitle = 'Availability Status Master';
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Availability Status Master</h4>
                <small class="text-muted">Manage user availability statuses used in calendar and daily status pages</small>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStatusModal">
                <i class="fas fa-plus me-1"></i> Add Status
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px;">Order</th>
                            <th>Key</th>
                            <th>Label</th>
                            <th>Badge</th>
                            <th>Description</th>
                            <th style="width:100px;">Active</th>
                            <th style="width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statuses as $status): ?>
                        <tr>
                            <td><?php echo (int)($status['display_order'] ?? 0); ?></td>
                            <td><code><?php echo htmlspecialchars($status['status_key']); ?></code></td>
                            <td><?php echo htmlspecialchars($status['status_label']); ?></td>
                            <td><span class="badge bg-<?php echo htmlspecialchars($status['badge_color'] ?: 'secondary'); ?>"><?php echo htmlspecialchars($status['status_label']); ?></span></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($status['description'] ?? '-'); ?></td>
                            <td>
                                <?php if (!empty($status['is_active'])): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick='editStatus(<?php echo htmlspecialchars(json_encode($status), ENT_QUOTES, 'UTF-8'); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteStatus(<?php echo (int)($status['id'] ?? 0); ?>, '<?php echo htmlspecialchars($status['status_label'], ENT_QUOTES, 'UTF-8'); ?>')" <?php echo (($status['status_key'] ?? '') === 'not_updated') ? 'disabled title="Cannot delete Not Updated"' : ''; ?>>
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

<div class="modal fade" id="addStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add Availability Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Status Key</label>
                        <input type="text" name="status_key" class="form-control" required pattern="[a-z_]+" placeholder="e.g. half_day">
                        <small class="text-muted">Lowercase letters and underscore only.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status Label</label>
                        <input type="text" name="status_label" class="form-control" required placeholder="e.g. Half Day">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Badge Color</label>
                        <select name="badge_color" class="form-select">
                            <option value="secondary">Secondary</option>
                            <option value="success">Success</option>
                            <option value="primary">Primary</option>
                            <option value="warning">Warning</option>
                            <option value="danger">Danger</option>
                            <option value="info">Info</option>
                            <option value="dark">Dark</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-control" value="0" min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Optional description"></textarea>
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

<div class="modal fade" id="editStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Availability Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Status Key</label>
                        <input type="text" id="edit_status_key" class="form-control" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status Label</label>
                        <input type="text" name="status_label" id="edit_status_label" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Badge Color</label>
                        <select name="badge_color" id="edit_badge_color" class="form-select">
                            <option value="secondary">Secondary</option>
                            <option value="success">Success</option>
                            <option value="primary">Primary</option>
                            <option value="warning">Warning</option>
                            <option value="danger">Danger</option>
                            <option value="info">Info</option>
                            <option value="dark">Dark</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" id="edit_display_order" class="form-control" min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                        <label class="form-check-label" for="edit_is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteStatusForm" method="POST" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete_id">
</form>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/admin-availability-status.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; 