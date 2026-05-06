<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$db = Database::getInstance();
$userId = (int)($_SESSION['user_id'] ?? 0);
$baseDir = getBaseDir();

ensureQaStatusPermissionsTable($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: qa_status_permissions.php');
        exit;
    }
    try {
        if (isset($_POST['grant_project_scope'])) {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $projectId = (int)($_POST['project_id'] ?? 0);
            $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($targetUserId > 0 && $projectId > 0) {
                if (grantQaStatusPermission($db, $targetUserId, 'project', $projectId, $userId, $expiresAt, $notes)) {
                    createNotification($db, $targetUserId, 'permission_update', 'QA status update access granted for one project.', '/modules/projects/view.php?id=' . $projectId);
                    $_SESSION['success'] = 'Project-level QA status permission granted.';
                } else {
                    $_SESSION['error'] = 'Failed to grant project-level permission.';
                }
            } else {
                $_SESSION['error'] = 'Please select user and project.';
            }
        }

        if (isset($_POST['grant_client_scope'])) {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $clientId = (int)($_POST['client_id'] ?? 0);
            $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($targetUserId > 0 && $clientId > 0) {
                if (grantQaStatusPermission($db, $targetUserId, 'client', $clientId, $userId, $expiresAt, $notes)) {
                    createNotification($db, $targetUserId, 'permission_update', 'QA status update access granted for all projects under one client.', '/modules/project_lead/my_projects.php');
                    $_SESSION['success'] = 'Client-level QA status permission granted.';
                } else {
                    $_SESSION['error'] = 'Failed to grant client-level permission.';
                }
            } else {
                $_SESSION['error'] = 'Please select user and client.';
            }
        }

        if (isset($_POST['revoke_permission'])) {
            $permissionId = (int)($_POST['permission_id'] ?? 0);
            if ($permissionId > 0 && revokeQaStatusPermission($db, $permissionId)) {
                $_SESSION['success'] = 'Permission revoked successfully.';
            } else {
                $_SESSION['error'] = 'Failed to revoke permission.';
            }
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Operation failed: ' . $e->getMessage();
    }

    header('Location: ' . $baseDir . '/modules/admin/qa_status_permissions.php');
    exit;
}

$usersStmt = $db->query("
    SELECT id, full_name, role
    FROM users
    WHERE is_active = 1
      AND role IN ('qa','project_lead','at_tester','ft_tester')
    ORDER BY full_name
");
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$projectsStmt = $db->query("
    SELECT p.id, p.title, c.name AS client_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    ORDER BY p.title
");
$projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

$clientsStmt = $db->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

$rowsStmt = $db->query("
    SELECT qsp.*, u.full_name AS user_name, u.role AS user_role, gb.full_name AS granted_by_name,
           p.title AS project_title, c.name AS client_name
    FROM qa_status_permissions qsp
    JOIN users u ON u.id = qsp.user_id
    LEFT JOIN users gb ON gb.id = qsp.granted_by
    LEFT JOIN projects p ON p.id = qsp.project_id
    LEFT JOIN clients c ON c.id = qsp.client_id
    WHERE qsp.is_active = 1
    ORDER BY qsp.updated_at DESC, qsp.id DESC
");
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Issue QA Status Permissions</h2>
        <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/dashboard.php">Back</a>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><strong>Grant Project Scope</strong></div>
                <div class="card-body">
                    <form method="post" id="grantProjectQaPermissionForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="grant_project_scope" value="1">
                        <div class="mb-2">
                            <label class="form-label">User</label>
                            <select class="form-select" name="user_id" required>
                                <option value="">Select user</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['full_name'] . ' (' . $u['role'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Project</label>
                            <select class="form-select" name="project_id" required>
                                <option value="">Select project</option>
                                <?php foreach ($projects as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['title'] . ($p['client_name'] ? ' | ' . $p['client_name'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Expires At (optional)</label>
                            <input type="datetime-local" class="form-control" name="expires_at">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                        <button class="btn btn-primary" type="button"
                                onclick="confirmForm('grantProjectQaPermissionForm', 'Grant project-level QA status update permission to the selected user?')">
                            Grant Project Scope
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><strong>Grant Client Scope</strong></div>
                <div class="card-body">
                    <form method="post" id="grantClientQaPermissionForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="grant_client_scope" value="1">
                        <div class="mb-2">
                            <label class="form-label">User</label>
                            <select class="form-select" name="user_id" required>
                                <option value="">Select user</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['full_name'] . ' (' . $u['role'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Client</label>
                            <select class="form-select" name="client_id" required>
                                <option value="">Select client</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Expires At (optional)</label>
                            <input type="datetime-local" class="form-control" name="expires_at">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                        <button class="btn btn-primary" type="button"
                                onclick="confirmForm('grantClientQaPermissionForm', 'Grant client-level QA status update permission to the selected user?')">
                            Grant Client Scope
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>Active QA Status Permissions</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Scope</th>
                            <th>Target</th>
                            <th>Expires</th>
                            <th>Granted By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No active permissions.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['user_name'] . ' (' . $r['user_role'] . ')'); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($r['scope'])); ?></td>
                                    <td>
                                        <?php echo $r['scope'] === 'project'
                                            ? htmlspecialchars((string)$r['project_title'])
                                            : htmlspecialchars((string)$r['client_name']); ?>
                                    </td>
                                    <td><?php echo $r['expires_at'] ? htmlspecialchars(date('M d, Y H:i', strtotime((string)$r['expires_at']))) : 'Never'; ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['granted_by_name'] ?: 'System')); ?></td>
                                    <td>
                                        <form method="post" class="d-inline" id="revokeQaPermissionForm_<?php echo (int)$r['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <input type="hidden" name="permission_id" value="<?php echo (int)$r['id']; ?>">
                                            <input type="hidden" name="revoke_permission" value="1">
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="confirmForm('revokeQaPermissionForm_<?php echo (int)$r['id']; ?>', 'Revoke this QA status update permission?')">
                                                Revoke
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; 