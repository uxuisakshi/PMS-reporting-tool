<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/email.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$baseDir = getBaseDir();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: client_permissions.php');
        exit;
    }
    if (isset($_POST['grant_permissions'])) {
        $projectIds = $_POST['project_ids'] ?? [];
        $targetUserId = intval($_POST['user_id']);
        // For client users, always grant view_project permission only
        $permissions = ['view_project'];
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $notes = trim($_POST['notes'] ?? '');
        
        if (!empty($projectIds) && $targetUserId) {
            try {
                $db->beginTransaction();
                
                // Get user details for logging
                $userStmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
                $userStmt->execute([$targetUserId]);
                $targetUser = $userStmt->fetch();
                
                if (!$targetUser) {
                    throw new Exception("User not found");
                }
                
                $grantedPermissions = [];
                $projectNames = [];
                
                foreach ($projectIds as $projectId) {
                    $projectId = intval($projectId);
                    
                    // Get project and client details
                    $projectStmt = $db->prepare("SELECT p.title, p.client_id, c.name as client_name FROM projects p JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
                    $projectStmt->execute([$projectId]);
                    $project = $projectStmt->fetch();
                    
                    if (!$project) continue;
                    
                    $projectNames[] = $project['title'];
                    
                    foreach ($permissions as $permission) {
                        // Check if permission already exists
                        $checkStmt = $db->prepare("
                            SELECT id FROM client_permissions 
                            WHERE project_id = ? AND user_id = ? AND permission_type = ?
                        ");
                        $checkStmt->execute([$projectId, $targetUserId, $permission]);
                        
                        if (!$checkStmt->fetch()) {
                            // Grant new permission
                            $insertStmt = $db->prepare("
                                INSERT INTO client_permissions (client_id, project_id, user_id, permission_type, granted_by, expires_at, notes)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insertStmt->execute([$project['client_id'], $projectId, $targetUserId, $permission, $userId, $expiresAt, $notes]);
                            $grantedPermissions[] = $permission . ' for ' . $project['title'];
                        } else {
                            // Update existing permission
                            $updateStmt = $db->prepare("
                                UPDATE client_permissions 
                                SET is_active = TRUE, granted_by = ?, expires_at = ?, notes = ?, updated_at = NOW()
                                WHERE project_id = ? AND user_id = ? AND permission_type = ?
                            ");
                            $updateStmt->execute([$userId, $expiresAt, $notes, $projectId, $targetUserId, $permission]);
                            $grantedPermissions[] = $permission . ' for ' . $project['title'];
                        }
                    }
                }
                
                // Commit transaction first
                $db->commit();
                
                // Log activity (after commit to avoid transaction issues)
                try {
                    logActivity($db, $userId, 'grant_project_permissions', 'user', $targetUserId, [
                        'target_user_name' => $targetUser['full_name'],
                        'target_user_email' => $targetUser['email'],
                        'permissions' => $grantedPermissions,
                        'permissions_count' => count($grantedPermissions),
                        'projects_count' => count($projectIds),
                        'expires_at' => $expiresAt
                    ]);
                } catch (Exception $e) {
                    error_log("Failed to log activity: " . $e->getMessage());
                }
                
                $_SESSION['success'] = "Project access granted successfully to " . htmlspecialchars($targetUser['full_name']) . " for " . count($projectIds) . " project(s)!";
                redirect("/modules/admin/client_permissions.php?user_id=" . $targetUserId);
                exit;
                
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error'] = "Database error: " . $e->getMessage();
                redirect("/modules/admin/client_permissions.php?user_id=" . $targetUserId);
                exit;
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error'] = "Error: " . $e->getMessage();
                redirect("/modules/admin/client_permissions.php?user_id=" . $targetUserId);
                exit;
            }
        } else {
            $_SESSION['error'] = "Please select at least one project and user.";
            redirect("/modules/admin/client_permissions.php?user_id=" . intval($_POST['user_id'] ?? 0));
            exit;
        }
    }
    
    if (isset($_POST['revoke_permission'])) {
        $permissionId = intval($_POST['permission_id']);
        
        if ($permissionId) {
            try {
                // Get permission details before revoking
                $permStmt = $db->prepare("
                    SELECT cp.*, u.full_name as user_name, c.name as client_name 
                    FROM client_permissions cp
                    JOIN users u ON cp.user_id = u.id
                    JOIN clients c ON cp.client_id = c.id
                    WHERE cp.id = ?
                ");
                $permStmt->execute([$permissionId]);
                $permission = $permStmt->fetch();
                
                if ($permission) {
                    // Get user email for notification
                    $userEmailStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
                    $userEmailStmt->execute([$permission['user_id']]);
                    $userEmail = $userEmailStmt->fetchColumn();
                    
                    // Get project name
                    $projectStmt = $db->prepare("SELECT title FROM projects WHERE id = ?");
                    $projectStmt->execute([$permission['project_id']]);
                    $projectName = $projectStmt->fetchColumn();
                    
                    // Revoke permission (soft delete)
                    $revokeStmt = $db->prepare("
                        UPDATE client_permissions 
                        SET is_active = FALSE, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $revokeStmt->execute([$permissionId]);
                    
                    // Log activity
                    logActivity($db, $userId, 'revoke_project_permission', 'user', $permission['user_id'], [
                        'target_user_name' => $permission['user_name'],
                        'permission_type' => $permission['permission_type'],
                        'project_name' => $projectName,
                        'client_name' => $permission['client_name']
                    ]);
                    
                    // Create in-app notification
                    $permissionLabel = ucfirst(str_replace('_', ' ', $permission['permission_type']));
                    $notificationMessage = "Your " . $permissionLabel . " permission for project '" . $projectName . "' has been revoked.";
                    createNotification($db, $permission['user_id'], 'permission_update', $notificationMessage, '/modules/projects/my_client_projects.php');
                    
                    // Send email notification
                    try {
                        $emailSender = new EmailSender();
                        $permissionLabel = ucfirst(str_replace('_', ' ', $permission['permission_type']));
                        $emailSubject = "Project Permission Revoked";
                        $emailBody = "
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <style>
                                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                    .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; }
                                    .content { padding: 20px; background-color: #f8f9fa; }
                                </style>
                            </head>
                            <body>
                                <div class='container'>
                                    <div class='header'>
                                        <h1>Project Permission Revoked</h1>
                                    </div>
                                    <div class='content'>
                                        <h2>Hello " . htmlspecialchars($permission['user_name']) . ",</h2>
                                        <p>Your <strong>" . htmlspecialchars($permissionLabel) . "</strong> permission for project <strong>" . htmlspecialchars($projectName) . "</strong> (" . htmlspecialchars($permission['client_name']) . ") has been revoked.</p>
                                        <p>If you have any questions, please contact your administrator.</p>
                                    </div>
                                </div>
                            </body>
                            </html>
                        ";
                        
                        if ($userEmail) {
                            $emailSender->send($userEmail, $emailSubject, $emailBody, true);
                        }
                    } catch (Exception $e) {
                        error_log("Failed to send revoke notification email: " . $e->getMessage());
                    }
                    
                    $_SESSION['success'] = "Permission revoked successfully! Email notification sent.";
                    redirect("/modules/admin/client_permissions.php?user_id=" . $permission['user_id']);
                    exit;
                } else {
                    $_SESSION['error'] = "Permission not found.";
                    redirect("/modules/admin/client_permissions.php?user_id=" . intval($_GET['user_id'] ?? 0));
                    exit;
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
                redirect("/modules/admin/client_permissions.php?user_id=" . intval($_GET['user_id'] ?? 0));
                exit;
            }
        }
    }
}

// Get all clients
$clients = $db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

// Get all projects grouped by client
$projects = $db->query("
    SELECT p.id, p.title, p.po_number, c.id as client_id, c.name as client_name 
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    ORDER BY c.name, p.title
")->fetchAll();

// Get all client users only (excluding current admin)
$users = $db->prepare("SELECT id, full_name, email, role FROM users WHERE id != ? AND is_active = 1 AND role = 'client' ORDER BY full_name");
$users->execute([$userId]);
$users = $users->fetchAll();

// Get permission types
$permissionTypes = $db->query("
    SELECT permission_type, description, category 
    FROM client_permissions_types 
    WHERE is_active = 1 
    ORDER BY category, permission_type
")->fetchAll();

// Group permissions by category
$permissionsByCategory = [];
foreach ($permissionTypes as $perm) {
    $permissionsByCategory[$perm['category']][] = $perm;
}

// Get current permissions with filters, search, and pagination
$selectedClient = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$selectedUser = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$selectedProject = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$whereConditions = ["cp.is_active = 1", "cp.project_id IS NOT NULL"];
$params = [];

if ($selectedClient) {
    $whereConditions[] = "p.client_id = ?";
    $params[] = $selectedClient;
}

if ($selectedUser) {
    $whereConditions[] = "cp.user_id = ?";
    $params[] = $selectedUser;
}

if ($selectedProject) {
    $whereConditions[] = "cp.project_id = ?";
    $params[] = $selectedProject;
}

// Add search functionality
if ($searchTerm) {
    $whereConditions[] = "(
        u.full_name LIKE ? OR 
        u.email LIKE ? OR 
        c.name LIKE ? OR 
        p.title LIKE ? OR 
        p.po_number LIKE ? OR 
        cp.permission_type LIKE ? OR
        pt.description LIKE ?
    )";
    $searchParam = '%' . $searchTerm . '%';
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

// Get total count for pagination
$countQuery = "
    SELECT COUNT(*) 
    FROM client_permissions cp
    JOIN users u ON cp.user_id = u.id
    JOIN projects p ON cp.project_id = p.id
    JOIN clients c ON p.client_id = c.id
    LEFT JOIN users gb ON cp.granted_by = gb.id
    LEFT JOIN client_permissions_types pt ON cp.permission_type = pt.permission_type
    WHERE " . implode(" AND ", $whereConditions);

$countStmt = $db->prepare($countQuery);
$countStmt->execute($params);
$totalPermissions = $countStmt->fetchColumn();
$totalPages = ceil($totalPermissions / $perPage);

// Get permissions for current page
$currentPermissions = $db->prepare("
    SELECT cp.*, u.full_name as user_name, u.email as user_email, u.role as user_role,
           c.name as client_name,
           p.title as project_title, p.po_number as project_code,
           gb.full_name as granted_by_name,
           pt.description as permission_description, pt.category
    FROM client_permissions cp
    JOIN users u ON cp.user_id = u.id
    JOIN projects p ON cp.project_id = p.id
    JOIN clients c ON p.client_id = c.id
    LEFT JOIN users gb ON cp.granted_by = gb.id
    LEFT JOIN client_permissions_types pt ON cp.permission_type = pt.permission_type
    WHERE " . implode(" AND ", $whereConditions) . "
    ORDER BY c.name, p.title, u.full_name, pt.category, cp.permission_type
    LIMIT ? OFFSET ?
");
$currentPermissions->execute(array_merge($params, [$perPage, $offset]));

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="fas fa-user-lock"></i> Client User Project Permissions</h2>
                    <p class="text-muted mb-0">Manage project access for client portal users</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#grantPermissionModal">
                    <i class="fas fa-plus"></i> Grant Project Access
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

            <!-- Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                <strong>Project-Specific Permissions:</strong> Grant users permission to view, edit, or manage specific projects. 
                This allows you to delegate project management responsibilities without giving full admin access.
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Search Permissions</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Search by user name, email, client, project, or permission type..." 
                                       value="<?php echo htmlspecialchars($searchTerm); ?>">
                                <?php if ($searchTerm): ?>
                                <button type="button" class="btn btn-outline-secondary" onclick="clearSearch()">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($searchTerm): ?>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                Searching for: <strong>"<?php echo htmlspecialchars($searchTerm); ?>"</strong>
                                <a href="?" class="text-decoration-none ms-2">Clear search</a>
                            </small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Filter by Client</label>
                            <select name="client_id" class="form-select">
                                <option value="">All Clients</option>
                                <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>" <?php echo $selectedClient == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Filter by Project</label>
                            <select name="project_id" class="form-select">
                                <option value="">All Projects</option>
                                <?php 
                                $currentClient = '';
                                foreach ($projects as $proj): 
                                    if ($currentClient != $proj['client_name']) {
                                        if ($currentClient != '') echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars($proj['client_name']) . '">';
                                        $currentClient = $proj['client_name'];
                                    }
                                ?>
                                <option value="<?php echo $proj['id']; ?>" <?php echo $selectedProject == $proj['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($proj['po_number'] . ' - ' . $proj['title']); ?>
                                </option>
                                <?php endforeach; 
                                if ($currentClient != '') echo '</optgroup>';
                                ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Filter by Client User</label>
                            <select name="user_id" class="form-select">
                                <option value="">All Client Users</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $selectedUser == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block w-100">
                                <i class="fas fa-search"></i> Search & Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Current Permissions -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0"><i class="fas fa-list"></i> Current Project Permissions</h5>
                        <?php if ($searchTerm): ?>
                        <small class="text-muted">
                            <i class="fas fa-search"></i> Search results for: <strong>"<?php echo htmlspecialchars($searchTerm); ?>"</strong>
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php if ($totalPermissions > 0): ?>
                    <small class="text-muted">
                        Showing <?php echo number_format($offset + 1); ?>-<?php echo number_format(min($offset + $perPage, $totalPermissions)); ?> 
                        of <?php echo number_format($totalPermissions); ?> permissions
                        <?php if ($searchTerm || $selectedClient || $selectedUser || $selectedProject): ?>
                        <br><span class="badge bg-info">Filtered Results</span>
                        <?php endif; ?>
                    </small>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($currentPermissions->rowCount() > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Client</th>
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
                                <?php while ($perm = $currentPermissions->fetch()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($perm['client_name']); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($perm['project_code']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($perm['project_title']); ?></small>
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
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                            <input type="hidden" name="revoke_permission" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                    onclick="return confirm('Are you sure you want to revoke this permission?')">
                                                <i class="fas fa-times"></i> Revoke
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($totalPages > 1): ?>
                    <!-- Pagination -->
                    <nav aria-label="Permissions pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php
                            // Build query string for pagination links
                            $queryParams = [];
                            if ($selectedClient) $queryParams['client_id'] = $selectedClient;
                            if ($selectedUser) $queryParams['user_id'] = $selectedUser;
                            if ($selectedProject) $queryParams['project_id'] = $selectedProject;
                            if ($searchTerm) $queryParams['search'] = $searchTerm;
                            
                            // Previous page
                            if ($page > 1):
                                $prevParams = array_merge($queryParams, ['page' => $page - 1]);
                            ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query($prevParams); ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <?php
                            // Page numbers
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            if ($startPage > 1):
                            ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($queryParams, ['page' => 1])); ?>">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                            <li class="page-item disabled">
                                <span class="page-link">...</span>
                            </li>
                            <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($queryParams, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                            <li class="page-item disabled">
                                <span class="page-link">...</span>
                            </li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($queryParams, ['page' => $totalPages])); ?>">
                                    <?php echo $totalPages; ?>
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <?php
                            // Next page
                            if ($page < $totalPages):
                                $nextParams = array_merge($queryParams, ['page' => $page + 1]);
                            ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query($nextParams); ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    
                    <!-- Page size info -->
                    <div class="text-center text-muted small mt-3">
                        Showing <?php echo $perPage; ?> permissions per page
                    </div>
                    <?php endif; ?>
                    
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <?php if ($searchTerm): ?>
                            No permissions found matching your search criteria "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>". 
                            <a href="?" class="text-decoration-none">Clear search</a> to see all permissions.
                        <?php elseif ($selectedClient || $selectedUser || $selectedProject): ?>
                            No permissions found with the selected filters. Try adjusting your filter criteria.
                        <?php else: ?>
                            No project-specific permissions found. Use the search and filters above or grant new permissions.
                        <?php endif; ?>
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
                    <h5 class="modal-title">Grant Project Access to Client User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Client User *</label>
                                <select name="user_id" class="form-select" required>
                                    <option value="">Select Client User</option>
                                    <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['email']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Only client portal users are shown</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Filter by Client (Optional)</label>
                                <select id="clientFilter" class="form-select">
                                    <option value="">All Clients</option>
                                    <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>">
                                        <?php echo htmlspecialchars($client['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Projects * <small class="text-muted">(Select one or more)</small></label>
                        <div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px;">
                            <?php 
                            $currentClient = '';
                            foreach ($projects as $proj): 
                                if ($currentClient != $proj['client_name']) {
                                    if ($currentClient != '') echo '</div>';
                                    echo '<div class="mb-3"><h6 class="text-primary border-bottom pb-2">' . htmlspecialchars($proj['client_name']) . '</h6>';
                                    $currentClient = $proj['client_name'];
                                }
                            ?>
                                <div class="form-check" data-client-id="<?php echo $proj['client_id']; ?>">
                                    <input class="form-check-input project-checkbox" type="checkbox" name="project_ids[]" 
                                           value="<?php echo $proj['id']; ?>" 
                                           id="proj_<?php echo $proj['id']; ?>">
                                    <label class="form-check-label" for="proj_<?php echo $proj['id']; ?>">
                                        <strong><?php echo htmlspecialchars($proj['po_number']); ?></strong> - 
                                        <?php echo htmlspecialchars($proj['title']); ?>
                                    </label>
                                </div>
                            <?php 
                            endforeach; 
                            if ($currentClient != '') echo '</div>';
                            ?>
                        </div>
                        <small class="text-muted">
                            <span id="selectedCount">0</span> project(s) selected
                        </small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Note:</strong> Client users will automatically get view access to selected projects. They can view issues, pages, and project details.
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
                    <button type="submit" name="grant_permissions" class="btn btn-primary">Grant Access</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/admin-client-permissions.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; 