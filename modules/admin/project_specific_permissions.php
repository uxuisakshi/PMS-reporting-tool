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
        header('Location: project_specific_permissions.php');
        exit;
    }
    if (isset($_POST['grant_permissions'])) {
        $projectId = intval($_POST['project_id']);
        $clientId = intval($_POST['client_id'] ?? 0);
        $targetUserId = intval($_POST['user_id']);
        $permissions = $_POST['permissions'] ?? [];
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $notes = trim($_POST['notes'] ?? '');

        $createProjectPermissionKeys = ['create_project', 'project_create'];
        $hasCreateProjectPermission = count(array_intersect($createProjectPermissionKeys, $permissions)) > 0;
        $projectPermissions = array_values(array_filter($permissions, static function ($permission) {
            return !in_array($permission, ['create_project', 'project_create'], true);
        }));

        if ($targetUserId && !empty($permissions)) {
            if ($hasCreateProjectPermission && $clientId <= 0) {
                $_SESSION['error'] = "Please select a client for Create Project permission.";
                header('Location: project_specific_permissions.php');
                exit;
            }

            if (!empty($projectPermissions) && $projectId <= 0) {
                $_SESSION['error'] = "Please select a project for project-specific permissions.";
                header('Location: project_specific_permissions.php');
                exit;
            }

            try {
                $db->beginTransaction();
                
                // Get user and project details for logging
                $userStmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
                $userStmt->execute([$targetUserId]);
                $targetUser = $userStmt->fetch();
                
                $project = null;
                if ($projectId > 0) {
                    $projectStmt = $db->prepare("SELECT title FROM projects WHERE id = ?");
                    $projectStmt->execute([$projectId]);
                    $project = $projectStmt->fetch();
                }

                $client = null;
                if ($clientId > 0) {
                    $clientStmt = $db->prepare("SELECT name FROM clients WHERE id = ?");
                    $clientStmt->execute([$clientId]);
                    $client = $clientStmt->fetch();
                }
                
                $grantedPermissions = [];
                
                foreach ($projectPermissions as $permission) {
                    // Check if permission already exists
                    $checkStmt = $db->prepare("
                        SELECT id FROM project_permissions 
                        WHERE project_id = ? AND user_id = ? AND permission_type = ?
                    ");
                    $checkStmt->execute([$projectId, $targetUserId, $permission]);
                    
                    if (!$checkStmt->fetch()) {
                        // Grant new permission
                        $insertStmt = $db->prepare("
                            INSERT INTO project_permissions (project_id, user_id, permission_type, granted_by, expires_at, notes)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $insertStmt->execute([$projectId, $targetUserId, $permission, $userId, $expiresAt, $notes]);
                        $grantedPermissions[] = $permission;
                    } else {
                        // Update existing permission
                        $updateStmt = $db->prepare("
                            UPDATE project_permissions 
                            SET is_active = TRUE, granted_by = ?, expires_at = ?, notes = ?, updated_at = NOW()
                            WHERE project_id = ? AND user_id = ? AND permission_type = ?
                        ");
                        $updateStmt->execute([$userId, $expiresAt, $notes, $projectId, $targetUserId, $permission]);
                        $grantedPermissions[] = $permission;
                    }
                }

                if ($hasCreateProjectPermission) {
                    $checkClientStmt = $db->prepare("
                        SELECT id FROM client_permissions
                        WHERE client_id = ? AND user_id = ? AND permission_type = 'create_project' AND project_id IS NULL
                    ");
                    $checkClientStmt->execute([$clientId, $targetUserId]);

                    if (!$checkClientStmt->fetch()) {
                        $insertClientStmt = $db->prepare("
                            INSERT INTO client_permissions (client_id, project_id, user_id, permission_type, granted_by, expires_at, notes)
                            VALUES (?, NULL, ?, 'create_project', ?, ?, ?)
                        ");
                        $insertClientStmt->execute([$clientId, $targetUserId, $userId, $expiresAt, $notes]);
                    } else {
                        $updateClientStmt = $db->prepare("
                            UPDATE client_permissions
                            SET is_active = TRUE, granted_by = ?, expires_at = ?, notes = ?, updated_at = NOW()
                            WHERE client_id = ? AND user_id = ? AND permission_type = 'create_project' AND project_id IS NULL
                        ");
                        $updateClientStmt->execute([$userId, $expiresAt, $notes, $clientId, $targetUserId]);
                    }

                    $grantedPermissions[] = 'create_project';
                }
                
                // Log activity
                logActivity($db, $userId, 'grant_project_permissions', 'project', $projectId ?: $clientId, [
                    'target_user_id' => $targetUserId,
                    'target_user_name' => $targetUser['full_name'],
                    'target_user_email' => $targetUser['email'],
                    'permissions' => $grantedPermissions,
                    'permissions_count' => count($grantedPermissions),
                    'expires_at' => $expiresAt,
                    'project_title' => $project['title'] ?? null,
                    'client_name' => $client['name'] ?? null
                ]);
                
                $db->commit();
                $_SESSION['success'] = "Permissions granted successfully to " . htmlspecialchars($targetUser['full_name']) . "!";
                
            } catch (PDOException $e) {
                $db->rollBack();
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Please select a project, user, and at least one permission.";
        }
    }
    
    if (isset($_POST['revoke_permission'])) {
        $permissionId = intval($_POST['permission_id']);
        $permissionSource = ($_POST['permission_source'] ?? 'project') === 'client' ? 'client' : 'project';
        
        if ($permissionId) {
            try {
                if ($permissionSource === 'client') {
                    $permStmt = $db->prepare("
                        SELECT cp.*, u.full_name as user_name, c.name as client_name
                        FROM client_permissions cp
                        JOIN users u ON cp.user_id = u.id
                        JOIN clients c ON cp.client_id = c.id
                        WHERE cp.id = ? AND cp.permission_type = 'create_project' AND cp.project_id IS NULL
                    ");
                    $permStmt->execute([$permissionId]);
                    $permission = $permStmt->fetch();

                    if ($permission) {
                        $revokeStmt = $db->prepare("
                            UPDATE client_permissions
                            SET is_active = FALSE, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $revokeStmt->execute([$permissionId]);

                        logActivity($db, $userId, 'revoke_client_permission', 'client', $permission['client_id'], [
                            'target_user_id' => $permission['user_id'],
                            'target_user_name' => $permission['user_name'],
                            'permission_type' => $permission['permission_type'],
                            'client_name' => $permission['client_name']
                        ]);

                        $_SESSION['success'] = "Client permission revoked successfully!";
                    } else {
                        $_SESSION['error'] = "Client permission not found.";
                    }
                } else {
                    // Get permission details before revoking
                    $permStmt = $db->prepare("
                        SELECT pp.*, u.full_name as user_name, p.title as project_title
                        FROM project_permissions pp
                        JOIN users u ON pp.user_id = u.id
                        JOIN projects p ON pp.project_id = p.id
                        WHERE pp.id = ?
                    ");
                    $permStmt->execute([$permissionId]);
                    $permission = $permStmt->fetch();

                    if ($permission) {
                        // Revoke permission (soft delete)
                        $revokeStmt = $db->prepare("
                            UPDATE project_permissions
                            SET is_active = FALSE, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $revokeStmt->execute([$permissionId]);

                        // Log activity
                        logActivity($db, $userId, 'revoke_project_permission', 'project', $permission['project_id'], [
                            'target_user_id' => $permission['user_id'],
                            'target_user_name' => $permission['user_name'],
                            'permission_type' => $permission['permission_type'],
                            'project_title' => $permission['project_title']
                        ]);

                        $_SESSION['success'] = "Permission revoked successfully!";
                    } else {
                        $_SESSION['error'] = "Permission not found.";
                    }
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['bulk_grant'])) {
        $selectedUsers = $_POST['selected_users'] ?? [];
        $bulkProjectId = intval($_POST['bulk_project_id']);
        $bulkPermissions = $_POST['bulk_permissions'] ?? [];
        $bulkExpiresAt = !empty($_POST['bulk_expires_at']) ? $_POST['bulk_expires_at'] : null;
        $bulkNotes = trim($_POST['bulk_notes'] ?? '');
        
        if (!empty($selectedUsers) && $bulkProjectId && !empty($bulkPermissions)) {
            try {
                $db->beginTransaction();
                
                $successCount = 0;
                $projectStmt = $db->prepare("SELECT title FROM projects WHERE id = ?");
                $projectStmt->execute([$bulkProjectId]);
                $project = $projectStmt->fetch();
                
                foreach ($selectedUsers as $bulkUserId) {
                    $bulkUserId = intval($bulkUserId);
                    if ($bulkUserId) {
                        foreach ($bulkPermissions as $permission) {
                            // Check if permission already exists
                            $checkStmt = $db->prepare("
                                SELECT id FROM project_permissions 
                                WHERE project_id = ? AND user_id = ? AND permission_type = ?
                            ");
                            $checkStmt->execute([$bulkProjectId, $bulkUserId, $permission]);
                            
                            if (!$checkStmt->fetch()) {
                                // Grant new permission
                                $insertStmt = $db->prepare("
                                    INSERT INTO project_permissions (project_id, user_id, permission_type, granted_by, expires_at, notes)
                                    VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                $insertStmt->execute([$bulkProjectId, $bulkUserId, $permission, $userId, $bulkExpiresAt, $bulkNotes]);
                            } else {
                                // Update existing permission
                                $updateStmt = $db->prepare("
                                    UPDATE project_permissions 
                                    SET is_active = TRUE, granted_by = ?, expires_at = ?, notes = ?, updated_at = NOW()
                                    WHERE project_id = ? AND user_id = ? AND permission_type = ?
                                ");
                                $updateStmt->execute([$userId, $bulkExpiresAt, $bulkNotes, $bulkProjectId, $bulkUserId, $permission]);
                            }
                        }
                        $successCount++;
                    }
                }
                
                // Log bulk activity
                logActivity($db, $userId, 'bulk_grant_project_permissions', 'project', $bulkProjectId, [
                    'users_count' => $successCount,
                    'permissions' => $bulkPermissions,
                    'permissions_count' => count($bulkPermissions),
                    'expires_at' => $bulkExpiresAt,
                    'project_title' => $project['title']
                ]);
                
                $db->commit();
                $_SESSION['success'] = "Permissions granted to $successCount users successfully!";
                
            } catch (PDOException $e) {
                $db->rollBack();
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Please select users, project, and permissions for bulk operation.";
        }
    }
}

// Get all projects
$projects = $db->query("SELECT id, title, po_number, status FROM projects ORDER BY title")->fetchAll();

// Get all clients
$clients = $db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

// Get all users (excluding current admin)
$users = $db->prepare("SELECT id, full_name, email, role FROM users WHERE id != ? AND is_active = 1 ORDER BY full_name");
$users->execute([$userId]);
$users = $users->fetchAll();

// Get permission types
$permissionTypes = $db->query("
    SELECT permission_type, description, category 
    FROM project_permissions_types 
    WHERE is_active = 1 
    ORDER BY category, permission_type
")->fetchAll();

// Group permissions by category
$permissionsByCategory = [];
foreach ($permissionTypes as $perm) {
    $permissionsByCategory[$perm['category']][] = $perm;
}

// Get current permissions with filters
$selectedProject = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$selectedUser = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$projectWhereConditions = ["pp.is_active = 1"];
$projectParams = [];

if ($selectedProject) {
    $projectWhereConditions[] = "pp.project_id = ?";
    $projectParams[] = $selectedProject;
}

if ($selectedUser) {
    $projectWhereConditions[] = "pp.user_id = ?";
    $projectParams[] = $selectedUser;
}

// Get total count for project permissions
$projectCountStmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM project_permissions pp
    WHERE " . implode(" AND ", $projectWhereConditions) . "
");
$projectCountStmt->execute($projectParams);
$projectCount = $projectCountStmt->fetch()['total'];

$projectPermissionsStmt = $db->prepare("
    SELECT pp.*, u.full_name as user_name, u.email as user_email, u.role as user_role,
           p.title as project_title, p.po_number,
           gb.full_name as granted_by_name,
           pt.description as permission_description, pt.category,
           NULL as client_name,
           'project' as permission_source
    FROM project_permissions pp
    JOIN users u ON pp.user_id = u.id
    JOIN projects p ON pp.project_id = p.id
    LEFT JOIN users gb ON pp.granted_by = gb.id
    LEFT JOIN project_permissions_types pt ON pp.permission_type = pt.permission_type
    WHERE " . implode(" AND ", $projectWhereConditions) . "
");
$projectPermissionsStmt->execute($projectParams);
$projectPermissionRows = $projectPermissionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$clientWhereConditions = [
    "cp.is_active = 1",
    "cp.permission_type = 'create_project'",
    "cp.project_id IS NULL"
];
$clientParams = [];

if ($selectedProject) {
    // Client-level create permissions are not bound to a specific project.
    $clientWhereConditions[] = "1 = 0";
}

if ($selectedUser) {
    $clientWhereConditions[] = "cp.user_id = ?";
    $clientParams[] = $selectedUser;
}

// Get total count for client permissions
$clientCountStmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM client_permissions cp
    WHERE " . implode(" AND ", $clientWhereConditions) . "
");
$clientCountStmt->execute($clientParams);
$clientCount = $clientCountStmt->fetch()['total'];

$clientPermissionsStmt = $db->prepare("
    SELECT cp.id, cp.user_id, cp.permission_type, cp.granted_by, cp.granted_at, cp.expires_at, cp.notes,
           u.full_name as user_name, u.email as user_email, u.role as user_role,
           CONCAT('Client: ', c.name) as project_title, '' as po_number,
           gb.full_name as granted_by_name,
           cpt.description as permission_description,
           COALESCE(cpt.category, 'project_management') as category,
           c.name as client_name,
           'client' as permission_source
    FROM client_permissions cp
    JOIN users u ON cp.user_id = u.id
    JOIN clients c ON cp.client_id = c.id
    LEFT JOIN users gb ON cp.granted_by = gb.id
    LEFT JOIN client_permissions_types cpt ON cp.permission_type = cpt.permission_type
    WHERE " . implode(" AND ", $clientWhereConditions) . "
");
$clientPermissionsStmt->execute($clientParams);
$clientPermissionRows = $clientPermissionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Merge and sort all permissions
$allPermissions = array_merge($projectPermissionRows, $clientPermissionRows);
usort($allPermissions, static function ($left, $right) {
    $leftKey = strtolower((string)($left['project_title'] ?? '')) . '|' . strtolower((string)($left['user_name'] ?? '')) . '|' . strtolower((string)($left['permission_type'] ?? ''));
    $rightKey = strtolower((string)($right['project_title'] ?? '')) . '|' . strtolower((string)($right['user_name'] ?? '')) . '|' . strtolower((string)($right['permission_type'] ?? ''));
    return strcmp($leftKey, $rightKey);
});

// Apply pagination to merged results
$totalPermissions = count($allPermissions);
$totalPages = ceil($totalPermissions / $perPage);
$currentPermissions = array_slice($allPermissions, $offset, $perPage);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-user-shield"></i> Project-Specific Permissions</h2>
                <div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#grantPermissionModal">
                        <i class="fas fa-plus"></i> Grant Permissions
                    </button>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#bulkGrantModal">
                        <i class="fas fa-users"></i> Bulk Grant
                    </button>
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

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Filter by Project</label>
                            <select name="project_id" class="form-select">
                                <option value="">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>" <?php echo $selectedProject == $project['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['title']); ?> (<?php echo htmlspecialchars($project['po_number']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Filter by User</label>
                            <select name="user_id" class="form-select">
                                <option value="">All Users</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $selectedUser == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-outline-primary d-block w-100">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Current Permissions -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Current Project Permissions</h5>
                    <span class="badge bg-primary">Total: <?php echo $totalPermissions; ?></span>
                </div>
                <div class="card-body">
                    <?php if (!empty($currentPermissions)): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>User</th>
                                    <th>Permission</th>
                                    <th>Category</th>
                                    <th>Granted By</th>
                                    <th>Granted Date</th>
                                    <th>Expires</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currentPermissions as $perm): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($perm['project_title']); ?></strong>
                                        <?php if (!empty($perm['po_number'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($perm['po_number']); ?></small>
                                        <?php elseif (($perm['permission_source'] ?? 'project') === 'client'): ?>
                                            <br><small class="text-muted">Client-level access</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($perm['user_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($perm['user_email']); ?></small>
                                        <br><span class="badge bg-secondary"><?php echo ucfirst(str_replace('_', ' ', $perm['user_role'])); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo ucfirst(str_replace('_', ' ', $perm['permission_type'])); ?></strong>
                                        <?php if ($perm['permission_description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($perm['permission_description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo ucfirst($perm['category']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($perm['granted_by_name'] ?: 'System'); ?>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y H:i', strtotime($perm['granted_at'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($perm['expires_at']): ?>
                                            <?php 
                                            $isExpired = strtotime($perm['expires_at']) < time();
                                            $badgeClass = $isExpired ? 'bg-danger' : 'bg-warning';
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>">
                                                <?php echo date('M d, Y', strtotime($perm['expires_at'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form id="revokeForm_<?php echo ($perm['permission_source'] ?? 'project') . '_' . $perm['id']; ?>" method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                            <input type="hidden" name="permission_source" value="<?php echo htmlspecialchars($perm['permission_source'] ?? 'project'); ?>">
                                            <input type="hidden" name="revoke_permission" value="1">
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="confirmForm('revokeForm_<?php echo ($perm['permission_source'] ?? 'project') . '_' . $perm['id']; ?>', 'Are you sure you want to revoke this permission?')">
                                                <i class="fas fa-times"></i> Revoke
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <nav aria-label="Permissions pagination" class="mt-3">
                        <ul class="pagination justify-content-center mb-0">
                            <!-- Previous Button -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $selectedProject ? '&project_id=' . $selectedProject : ''; ?><?php echo $selectedUser ? '&user_id=' . $selectedUser : ''; ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            
                            <?php
                            // Show page numbers with ellipsis
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            if ($startPage > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1<?php echo $selectedProject ? '&project_id=' . $selectedProject : ''; ?><?php echo $selectedUser ? '&user_id=' . $selectedUser : ''; ?>">1</a>
                                </li>
                                <?php if ($startPage > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo $selectedProject ? '&project_id=' . $selectedProject : ''; ?><?php echo $selectedUser ? '&user_id=' . $selectedUser : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $totalPages; ?><?php echo $selectedProject ? '&project_id=' . $selectedProject : ''; ?><?php echo $selectedUser ? '&user_id=' . $selectedUser : ''; ?>"><?php echo $totalPages; ?></a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Next Button -->
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $selectedProject ? '&project_id=' . $selectedProject : ''; ?><?php echo $selectedUser ? '&user_id=' . $selectedUser : ''; ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <div class="text-center mt-2">
                        <small class="text-muted">
                            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $totalPermissions); ?> of <?php echo $totalPermissions; ?> permissions
                        </small>
                    </div>
                    <?php endif; ?>
                    
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No project-specific permissions found. Use the filters above or grant new permissions.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Grant Permission Modal -->
<div class="modal fade" id="grantPermissionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Grant Project-Specific Permissions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label" id="grantTargetLabel">Client *</label>
                                <select name="project_id" id="grantProjectSelect" class="form-select d-none">
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>">
                                        <?php echo htmlspecialchars($project['title']); ?> (<?php echo htmlspecialchars($project['po_number']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="client_id" id="grantClientSelect" class="form-select" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted d-block mt-1">For Create Project permission, select client. For other permissions, select project.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">User *</label>
                                <select name="user_id" class="form-select" required>
                                    <option value="">Select User</option>
                                    <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Permissions *</label>
                        <div class="row">
                            <?php foreach ($permissionsByCategory as $category => $perms): ?>
                            <div class="col-md-6 mb-3">
                                <h6 class="text-primary"><?php echo ucfirst($category); ?></h6>
                                <?php foreach ($perms as $perm): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="permissions[]" 
                                           value="<?php echo $perm['permission_type']; ?>" 
                                           id="perm_<?php echo $perm['permission_type']; ?>">
                                    <label class="form-check-label" for="perm_<?php echo $perm['permission_type']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $perm['permission_type'])); ?>
                                        <?php if ($perm['description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($perm['description']); ?></small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Expires At (Optional)</label>
                                <input type="datetime-local" name="expires_at" class="form-control">
                                <small class="text-muted">Leave empty for permanent access</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Notes (Optional)</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Reason for granting permissions..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="grant_permissions" class="btn btn-primary">Grant Permissions</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Grant Modal -->
<div class="modal fade" id="bulkGrantModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Grant Permissions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Project *</label>
                                <select name="bulk_project_id" class="form-select" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>">
                                        <?php echo htmlspecialchars($project['title']); ?> (<?php echo htmlspecialchars($project['po_number']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Users *</label>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="selectAllUsers" onchange="toggleAllUsers(this)">
                                        <label class="form-check-label fw-bold" for="selectAllUsers">Select All Users</label>
                                    </div>
                                    <hr>
                                    <?php foreach ($users as $user): ?>
                                    <div class="form-check">
                                        <input class="form-check-input user-checkbox" type="checkbox" name="selected_users[]" 
                                               value="<?php echo $user['id']; ?>" id="bulk_user_<?php echo $user['id']; ?>">
                                        <label class="form-check-label" for="bulk_user_<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                            <small class="text-muted">(<?php echo htmlspecialchars($user['role']); ?>)</small>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Permissions *</label>
                                <div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                    <?php foreach ($permissionsByCategory as $category => $perms): ?>
                                    <div class="mb-3">
                                        <h6 class="text-primary"><?php echo ucfirst($category); ?></h6>
                                        <?php foreach ($perms as $perm): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="bulk_permissions[]" 
                                                   value="<?php echo $perm['permission_type']; ?>" 
                                                   id="bulk_perm_<?php echo $perm['permission_type']; ?>">
                                            <label class="form-check-label" for="bulk_perm_<?php echo $perm['permission_type']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $perm['permission_type'])); ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Expires At (Optional)</label>
                                <input type="datetime-local" name="bulk_expires_at" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Notes (Optional)</label>
                                <textarea name="bulk_notes" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="bulk_grant" class="btn btn-success">Bulk Grant Permissions</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
function toggleAllUsers(checkbox) {
    const userCheckboxes = document.querySelectorAll('.user-checkbox');
    userCheckboxes.forEach(cb => cb.checked = checkbox.checked);
}

(function () {
    const targetLabel = document.getElementById('grantTargetLabel');
    const projectSelect = document.getElementById('grantProjectSelect');
    const clientSelect = document.getElementById('grantClientSelect');
    const permissionCheckboxes = document.querySelectorAll('#grantPermissionModal input[name="permissions[]"]');
    if (!targetLabel || !projectSelect || !clientSelect || !permissionCheckboxes.length) {
        return;
    }

    function hasCreateProjectSelected() {
        return Array.prototype.some.call(permissionCheckboxes, function (checkbox) {
            return (checkbox.value === 'create_project' || checkbox.value === 'project_create') && checkbox.checked;
        });
    }

    function isCreateProjectCheckbox(checkbox) {
        return checkbox.value === 'create_project' || checkbox.value === 'project_create';
    }

    function hasAnyProjectSpecificSelected() {
        return Array.prototype.some.call(permissionCheckboxes, function (checkbox) {
            if (!checkbox.checked) {
                return false;
            }
            return checkbox.value !== 'create_project' && checkbox.value !== 'project_create';
        });
    }

    function syncGrantTargetDropdown() {
        const createSelected = hasCreateProjectSelected();
        const hasOtherProjectPermissions = hasAnyProjectSpecificSelected();
        if (!hasOtherProjectPermissions) {
            targetLabel.textContent = 'Client *';
            projectSelect.classList.add('d-none');
            projectSelect.required = false;
            clientSelect.classList.remove('d-none');
            clientSelect.required = true;
            if (createSelected) {
                projectSelect.value = '';
            }
        } else {
            targetLabel.textContent = 'Project *';
            clientSelect.classList.add('d-none');
            clientSelect.required = false;
            projectSelect.classList.remove('d-none');
            projectSelect.required = true;
            clientSelect.value = '';
        }
    }

    function syncCreateProjectExclusiveMode() {
        const createSelected = hasCreateProjectSelected();
        permissionCheckboxes.forEach(function (checkbox) {
            if (isCreateProjectCheckbox(checkbox)) {
                return;
            }

            if (createSelected) {
                checkbox.checked = false;
                checkbox.disabled = true;
                const label = document.querySelector('label[for="' + checkbox.id + '"]');
                if (label) {
                    label.classList.add('text-muted');
                }
            } else {
                checkbox.disabled = false;
                const label = document.querySelector('label[for="' + checkbox.id + '"]');
                if (label) {
                    label.classList.remove('text-muted');
                }
            }
        });

        if (createSelected) {
            targetLabel.textContent = 'Client *';
            projectSelect.classList.add('d-none');
            projectSelect.required = false;
            projectSelect.value = '';
            clientSelect.classList.remove('d-none');
            clientSelect.required = true;
        }
    }

    permissionCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            syncCreateProjectExclusiveMode();
            syncGrantTargetDropdown();
        });
    });

    syncCreateProjectExclusiveMode();
    syncGrantTargetDropdown();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>