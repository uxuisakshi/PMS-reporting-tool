<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$baseDir = getBaseDir();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: resource_workload_permissions.php');
        exit;
    }
    if (isset($_POST['grant_access'])) {
        $projectLeadId = intval($_POST['project_lead_id']);
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $notes = trim($_POST['notes'] ?? '');
        
        if ($projectLeadId) {
            try {
                // Get project lead details
                $userStmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ? AND role = 'project_lead'");
                $userStmt->execute([$projectLeadId]);
                $projectLead = $userStmt->fetch();
                
                if ($projectLead) {
                    // Check if permission already exists
                    $checkStmt = $db->prepare("
                        SELECT id FROM project_permissions 
                        WHERE user_id = ? AND permission_type = 'resource_workload_access'
                    ");
                    $checkStmt->execute([$projectLeadId]);
                    
                    if (!$checkStmt->fetch()) {
                        // Grant new permission
                        $insertStmt = $db->prepare("
                            INSERT INTO project_permissions (project_id, user_id, permission_type, granted_by, expires_at, notes)
                            VALUES (NULL, ?, 'resource_workload_access', ?, ?, ?)
                        ");
                        $insertStmt->execute([$projectLeadId, $userId, $expiresAt, $notes]);
                    } else {
                        // Update existing permission
                        $updateStmt = $db->prepare("
                            UPDATE project_permissions 
                            SET is_active = TRUE, granted_by = ?, expires_at = ?, notes = ?, updated_at = NOW()
                            WHERE user_id = ? AND permission_type = 'resource_workload_access'
                        ");
                        $updateStmt->execute([$userId, $expiresAt, $notes, $projectLeadId]);
                    }
                    
                    // Log activity
                    logActivity($db, $userId, 'grant_resource_workload_access', 'user', $projectLeadId, [
                        'target_user_name' => $projectLead['full_name'],
                        'target_user_email' => $projectLead['email'],
                        'expires_at' => $expiresAt
                    ]);

                    // Notify the target user
                    try {
                        $notifMsg = "You have been granted access to Resource Workload.";
                        if (!empty($expiresAt)) {
                            $notifMsg .= " Expires: " . date('M d, Y H:i', strtotime($expiresAt));
                        }
                        $notifLink = "/modules/admin/resource_workload.php";
                        createNotification($db, (int)$projectLeadId, 'permission_update', $notifMsg, $notifLink);
                    } catch (Exception $e) {
                        // Do not block permission grant if notification fails.
                    }

                    // Notify project lead about granted access
                    try {
                        $notifMsg = "Resource workload access has been granted to you.";
                        if (!empty($expiresAt)) {
                            $notifMsg .= " Access expires on " . date('M d, Y H:i', strtotime($expiresAt)) . ".";
                        }
                        $notifLink = "/modules/admin/resource_workload.php";
                        createNotification($db, (int)$projectLeadId, 'permission_update', $notifMsg, $notifLink);
                    } catch (Exception $e) {
                        // Keep permission grant successful even if notification insert fails.
                    }
                    
                    $_SESSION['success'] = "Resource workload access granted to " . htmlspecialchars($projectLead['full_name']) . "!";
                } else {
                    $_SESSION['error'] = "Project lead not found.";
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Please select a project lead.";
        }
    }
    
    if (isset($_POST['revoke_access'])) {
        $permissionId = intval($_POST['permission_id']);
        
        if ($permissionId) {
            try {
                // Get permission details before revoking
                $permStmt = $db->prepare("
                    SELECT pp.*, u.full_name as user_name 
                    FROM project_permissions pp
                    JOIN users u ON pp.user_id = u.id
                    WHERE pp.id = ? AND pp.permission_type = 'resource_workload_access'
                ");
                $permStmt->execute([$permissionId]);
                $permission = $permStmt->fetch();
                
                if ($permission) {
                    // Revoke permission
                    $revokeStmt = $db->prepare("
                        UPDATE project_permissions 
                        SET is_active = FALSE, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $revokeStmt->execute([$permissionId]);
                    
                    // Log activity
                    logActivity($db, $userId, 'revoke_resource_workload_access', 'user', $permission['user_id'], [
                        'target_user_name' => $permission['user_name']
                    ]);

                    // Notify the target user
                    try {
                        $notifMsg = "Your access to Resource Workload has been revoked.";
                        $notifLink = "/modules/admin/resource_workload.php";
                        createNotification($db, (int)$permission['user_id'], 'permission_update', $notifMsg, $notifLink);
                    } catch (Exception $e) {
                        // Do not block revoke if notification fails.
                    }

                    // Notify project lead about revoked access
                    try {
                        $notifMsg = "Your resource workload access has been revoked.";
                        $notifLink = "/modules/admin/resource_workload.php";
                        createNotification($db, (int)$permission['user_id'], 'permission_update', $notifMsg, $notifLink);
                    } catch (Exception $e) {
                        // Keep revoke successful even if notification insert fails.
                    }
                    
                    $_SESSION['success'] = "Resource workload access revoked from " . htmlspecialchars($permission['user_name']) . "!";
                } else {
                    $_SESSION['error'] = "Permission not found.";
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Get all project leads
$projectLeads = $db->query("
    SELECT id, full_name, email, created_at 
    FROM users 
    WHERE role = 'project_lead' AND is_active = 1 
    ORDER BY full_name
")->fetchAll();

// Get current resource workload permissions
$currentPermissions = $db->query("
    SELECT pp.*, u.full_name, u.email,
           admin.full_name as granted_by_name
    FROM project_permissions pp
    JOIN users u ON pp.user_id = u.id
    LEFT JOIN users admin ON pp.granted_by = admin.id
    WHERE pp.permission_type = 'resource_workload_access'
    AND pp.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

$pageTitle = 'Resource Workload Permissions';
include __DIR__ . '/../../includes/header.php';

$flashSuccess = $_SESSION['success'] ?? '';
$flashError = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-user-shield text-primary"></i> Resource Workload Permissions</h2>
            <p class="text-muted mb-0">Manage which project leads can access the resource workload page</p>
        </div>
        <a href="<?php echo $baseDir; ?>/modules/admin/dashboard.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back to Admin
        </a>
    </div>

    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($flashSuccess); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($flashError); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Grant Access Form -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Grant Resource Workload Access</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="grantWorkloadAccessForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="grant_access" value="1">
                        <div class="mb-3">
                            <label class="form-label">Project Lead *</label>
                            <select name="project_lead_id" class="form-select" required>
                                <option value="">-- Select Project Lead --</option>
                                <?php foreach ($projectLeads as $lead): ?>
                                    <?php
                                    // Check if this lead already has access
                                    $hasAccess = false;
                                    foreach ($currentPermissions as $perm) {
                                        if ($perm['user_id'] == $lead['id']) {
                                            $hasAccess = true;
                                            break;
                                        }
                                    }
                                    ?>
                                    <option value="<?php echo $lead['id']; ?>" <?php echo $hasAccess ? 'disabled' : ''; ?>>
                                        <?php echo htmlspecialchars($lead['full_name']); ?>
                                        <?php echo $hasAccess ? ' (Already has access)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Expires At (Optional)</label>
                            <input type="datetime-local" name="expires_at" class="form-control">
                            <small class="text-muted">Leave empty for permanent access</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Reason for granting access..."></textarea>
                        </div>
                        
                        <button type="button" class="btn btn-success w-100"
                                onclick="confirmForm('grantWorkloadAccessForm', 'Grant resource workload access to the selected project lead?')">
                            <i class="fas fa-check"></i> Grant Access
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Current Permissions -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Current Permissions</h5>
                    <span class="badge bg-primary"><?php echo count($currentPermissions); ?> Active</span>
                </div>
                <div class="card-body">
                    <?php if (empty($currentPermissions)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-info-circle fa-2x mb-3"></i>
                            <h6>No Resource Workload Permissions Granted</h6>
                            <p>No project leads currently have access to the resource workload page.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Project Lead</th>
                                        <th>Granted By</th>
                                        <th>Granted Date</th>
                                        <th>Expires</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($currentPermissions as $permission): ?>
                                        <?php
                                        $isExpired = $permission['expires_at'] && strtotime($permission['expires_at']) < time();
                                        $statusClass = $isExpired ? 'warning' : 'success';
                                        $statusText = $isExpired ? 'Expired' : 'Active';
                                        ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($permission['full_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($permission['email']); ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($permission['granted_by_name'] ?: 'System'); ?>
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($permission['created_at'])); ?>
                                                <br><small class="text-muted"><?php echo date('H:i', strtotime($permission['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($permission['expires_at']): ?>
                                                    <?php echo date('M d, Y', strtotime($permission['expires_at'])); ?>
                                                    <br><small class="text-muted"><?php echo date('H:i', strtotime($permission['expires_at'])); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                            </td>
                                            <td>
                                                <form id="revokeForm_<?php echo $permission['id']; ?>" method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                                    <input type="hidden" name="permission_id" value="<?php echo $permission['id']; ?>">
                                                    <input type="hidden" name="revoke_access" value="1">
                                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Revoke Access"
                                                            onclick="confirmForm('revokeForm_<?php echo $permission['id']; ?>', 'Are you sure you want to revoke access for <?php echo htmlspecialchars($permission['full_name'], ENT_QUOTES); ?>?')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php if ($permission['notes']): ?>
                                        <tr>
                                            <td colspan="6" class="border-top-0 pt-0">
                                                <small class="text-muted">
                                                    <i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars($permission['notes']); ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Information Card -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card border-info">
                <div class="card-header bg-info text-dark">
                    <h6 class="mb-0"><i class="fas fa-info-circle"></i> About Resource Workload Access</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>What is Resource Workload Access?</h6>
                            <p class="small text-muted">
                                Resource workload access allows project leads to view the team workload page, which shows:
                            </p>
                            <ul class="small text-muted">
                                <li>Team member capacity and utilization</li>
                                <li>Active project assignments</li>
                                <li>Workload distribution across team</li>
                                <li>Resource availability insights</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Permission Management</h6>
                            <ul class="small text-muted">
                                <li><strong>Grant Access:</strong> Select a project lead and optionally set an expiration date</li>
                                <li><strong>Revoke Access:</strong> Remove access immediately</li>
                                <li><strong>Expiration:</strong> Permissions can be set to expire automatically</li>
                                <li><strong>Notes:</strong> Add context for why access was granted</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
document.addEventListener('DOMContentLoaded', function () {
    <?php if (!empty($flashSuccess)): ?>
    if (typeof showToast === 'function') {
        showToast(<?php echo json_encode($flashSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, 'success');
    }
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
    if (typeof showToast === 'function') {
        showToast(<?php echo json_encode($flashError, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, 'danger');
    }
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; 