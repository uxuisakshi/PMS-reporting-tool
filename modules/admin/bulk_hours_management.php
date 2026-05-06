<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/hours_validation.php';

$baseDir = getBaseDir();

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead']); // Admin and Project Lead can manage hours

$db = Database::getInstance();

// Add cache-busting headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Handle bulk operations
if ($_POST) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    if (isset($_POST['bulk_update'])) {
        $updates = $_POST['updates'] ?? [];
        $reason = $_POST['bulk_reason'] ?? '';
        $successCount = 0;
        $errorCount = 0;
        
        // First pass: validate all updates and group by project
        $validatedUpdates = [];
        $projectUpdates = [];
        
        foreach ($updates as $assignmentId => $newHours) {
            if ($newHours === '' || !is_numeric($newHours)) {
                continue;
            }

            $assignmentId = (int)$assignmentId;
            $newHours = max(0, (float)$newHours);
            if ($assignmentId <= 0) {
                continue;
            }

            try {
                // Get current assignment details
                $getQuery = "
                    SELECT ua.*, u.full_name, p.title as project_title, p.id as project_id,
                           p.total_hours, p.project_lead_id,
                           (SELECT COALESCE(SUM(ua2.hours_allocated), 0)
                            FROM user_assignments ua2
                            WHERE ua2.project_id = ua.project_id
                              AND (ua2.is_removed IS NULL OR ua2.is_removed = 0)) AS total_allocated_hours,
                           COALESCE((
                               SELECT SUM(ptl.hours_spent)
                               FROM project_time_logs ptl
                               WHERE ptl.user_id = ua.user_id
                                 AND ptl.project_id = ua.project_id
                                 AND ptl.is_utilized = 1
                           ), 0) AS utilized_hours
                    FROM user_assignments ua 
                    JOIN users u ON ua.user_id = u.id 
                    JOIN projects p ON ua.project_id = p.id 
                    WHERE ua.id = ?
                ";
                $stmt = $db->prepare($getQuery);
                $stmt->execute([$assignmentId]);
                $assignment = $stmt->fetch();
                
                if (!$assignment) {
                    continue;
                }

                // IDOR check: project lead can only update their own projects
                if ($_SESSION['role'] === 'project_lead' && (int)$assignment['project_lead_id'] !== (int)$_SESSION['user_id']) {
                    error_log("IDOR attempt: User " . $_SESSION['user_id'] . " tried to update hours for project " . $assignment['project_id']);
                    $errorCount++;
                    continue;
                }

                $oldHours = (float)($assignment['hours_allocated'] ?? 0);
                $utilizedHours = (float)($assignment['utilized_hours'] ?? 0);

                // Skip unchanged rows
                if (abs($newHours - $oldHours) < 0.0001) {
                    continue;
                }

                // Do not allow allocation lower than already utilized hours
                if ($newHours < $utilizedHours) {
                    error_log("Bulk hours update failed for assignment {$assignmentId}: New hours ({$newHours}) < Utilized hours ({$utilizedHours})");
                    $errorCount++;
                    continue;
                }

                // Store for second pass validation
                $projectId = (int)$assignment['project_id'];
                if (!isset($projectUpdates[$projectId])) {
                    $projectUpdates[$projectId] = [
                        'total_hours' => (float)($assignment['total_hours'] ?? 0),
                        'current_allocated' => (float)($assignment['total_allocated_hours'] ?? 0),
                        'updates' => []
                    ];
                }
                
                $projectUpdates[$projectId]['updates'][] = [
                    'assignment_id' => $assignmentId,
                    'old_hours' => $oldHours,
                    'new_hours' => $newHours,
                    'assignment' => $assignment
                ];
                
            } catch (Exception $e) {
                error_log('Bulk hours validation error for assignment ' . $assignmentId . ': ' . $e->getMessage());
                $errorCount++;
            }
        }
        
        // Second pass: validate project totals and apply updates
        foreach ($projectUpdates as $projectId => $projectData) {
            $projectTotal = $projectData['total_hours'];
            
            // Recalculate current allocated from database to ensure accuracy
            $currentAllocatedQuery = "
                SELECT COALESCE(SUM(hours_allocated), 0) as total
                FROM user_assignments
                WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0)
            ";
            $stmt = $db->prepare($currentAllocatedQuery);
            $stmt->execute([$projectId]);
            $currentAllocated = (float)$stmt->fetchColumn();
            
            // Calculate new total allocation for this project
            $newTotalAllocated = $currentAllocated;
            $oldHoursSum = 0;
            $newHoursSum = 0;
            
            foreach ($projectData['updates'] as $update) {
                $oldHoursSum += $update['old_hours'];
                $newHoursSum += $update['new_hours'];
                $newTotalAllocated = $newTotalAllocated - $update['old_hours'] + $update['new_hours'];
            }
            
            // Check if new total exceeds project budget (with small tolerance for floating point)
            if ($newTotalAllocated > ($projectTotal + 0.01)) {
                $errorCount += count($projectData['updates']);
                continue; // Skip all updates for this project
            }
            
            // Apply all updates for this project
            foreach ($projectData['updates'] as $update) {
                try {
                    $updateQuery = "UPDATE user_assignments SET hours_allocated = ? WHERE id = ?";
                    $stmt = $db->prepare($updateQuery);
                    $stmt->execute([$update['new_hours'], $update['assignment_id']]);
                    
                    $assignment = $update['assignment'];
                    logHoursActivity($db, $_SESSION['user_id'], 'bulk_hours_updated', $update['assignment_id'], [
                        'target_user_id' => $assignment['user_id'],
                        'target_user_name' => $assignment['full_name'],
                        'project_id' => $assignment['project_id'],
                        'project_title' => $assignment['project_title'],
                        'old_hours' => $update['old_hours'],
                        'new_hours' => $update['new_hours'],
                        'reason' => $reason,
                        'updated_by' => $_SESSION['user_id'],
                        'utilized_hours' => $assignment['utilized_hours']
                    ]);
                    
                    $successCount++;
                } catch (Exception $e) {
                    error_log('Bulk hours update error for assignment ' . $update['assignment_id'] . ': ' . $e->getMessage());
                    $errorCount++;
                }
            }
        }
        
        if ($successCount > 0) {
            $_SESSION['success'] = "Successfully updated $successCount assignment(s).";
        }
        if ($errorCount > 0) {
            $_SESSION['error'] = "Failed to update $errorCount assignment(s). Check that new hours are between utilized hours and maximum allowed hours.";
        }
        if ($successCount === 0 && $errorCount === 0) {
            $_SESSION['error'] = "No changes were made. Please enter new hour values that are different from current hours.";
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Get filters
$projectFilter = $_GET['project_filter'] ?? '';
$userFilter = $_GET['user_filter'] ?? '';
$roleFilter = $_GET['role_filter'] ?? '';
$projectStatusFilter = $_GET['project_status_filter'] ?? '';
$searchUser = $_GET['search_user'] ?? '';
$searchProject = $_GET['search_project'] ?? '';
$showOverAllocated = isset($_GET['show_over_allocated']) && $_GET['show_over_allocated'] === '1';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$allowedPerPage = [10, 25, 50, 100, 200];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}

// Build query
$whereConditions = ["p.status NOT IN ('completed', 'cancelled')"];
$params = [];

if ($_SESSION['role'] === 'project_lead') {
    $whereConditions[] = "p.project_lead_id = ?";
    $params[] = $_SESSION['user_id'];
}

if ($projectFilter) {
    $whereConditions[] = "ua.project_id = ?";
    $params[] = $projectFilter;
}

if ($userFilter) {
    $whereConditions[] = "ua.user_id = ?";
    $params[] = $userFilter;
}

if ($roleFilter) {
    $whereConditions[] = "ua.role = ?";
    $params[] = $roleFilter;
}

if ($projectStatusFilter) {
    $whereConditions[] = "p.status = ?";
    $params[] = $projectStatusFilter;
}

if (!empty($searchUser)) {
    $whereConditions[] = "(u.full_name LIKE ? OR u.username LIKE ?)";
    $searchTerm = '%' . $searchUser . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($searchProject)) {
    $whereConditions[] = "(p.title LIKE ? OR p.po_number LIKE ?)";
    $searchProjectTerm = '%' . $searchProject . '%';
    $params[] = $searchProjectTerm;
    $params[] = $searchProjectTerm;
}

$whereClause = implode(' AND ', $whereConditions);

// Count total records for pagination
$countQuery = "
    SELECT COUNT(*)
    FROM user_assignments ua
    JOIN users u ON ua.user_id = u.id
    JOIN projects p ON ua.project_id = p.id
    WHERE $whereClause AND (ua.is_removed IS NULL OR ua.is_removed = 0)
";
$countStmt = $db->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Get assignments with project hours info
$assignmentsQuery = "
    SELECT ua.*, u.full_name, u.role as user_role, p.title as project_title, p.po_number, p.status as project_status, p.total_hours,
           COALESCE(ptl.utilized_hours, 0) as utilized_hours,
           (SELECT COALESCE(SUM(hours_allocated), 0) FROM user_assignments WHERE project_id = ua.project_id AND (is_removed IS NULL OR is_removed = 0)) as total_allocated_hours
    FROM user_assignments ua
    JOIN users u ON ua.user_id = u.id
    JOIN projects p ON ua.project_id = p.id
    LEFT JOIN (
        SELECT user_id, project_id, SUM(hours_spent) as utilized_hours
        FROM project_time_logs
        WHERE is_utilized = 1
        GROUP BY user_id, project_id
    ) ptl ON ua.user_id = ptl.user_id AND ua.project_id = ptl.project_id
    WHERE $whereClause AND (ua.is_removed IS NULL OR ua.is_removed = 0)
";

// Add over-allocated filter
if ($showOverAllocated) {
    $assignmentsQuery .= " HAVING total_allocated_hours > p.total_hours";
}

$assignmentsQuery .= " ORDER BY u.full_name, p.title LIMIT ? OFFSET ?";

$stmt = $db->prepare($assignmentsQuery);
$queryParams = array_merge($params, [$perPage, $offset]);
$stmt->execute($queryParams);
$assignments = $stmt->fetchAll();

// Get filter options
$projects = $db->query("SELECT id, title, po_number FROM projects WHERE status NOT IN ('completed', 'cancelled') ORDER BY title")->fetchAll();
$users = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 AND role IN ('project_lead', 'qa', 'at_tester', 'ft_tester') ORDER BY full_name")->fetchAll();

// Calculate summary statistics for filtered results
$summaryQuery = "
    SELECT 
        COUNT(DISTINCT ua.user_id) as total_users,
        COUNT(DISTINCT ua.project_id) as total_projects,
        COALESCE(SUM(ua.hours_allocated), 0) as total_allocated,
        COALESCE(SUM(ptl.utilized_hours), 0) as total_utilized,
        COALESCE(SUM(ua.hours_allocated) - SUM(ptl.utilized_hours), 0) as total_remaining
    FROM user_assignments ua
    JOIN users u ON ua.user_id = u.id
    JOIN projects p ON ua.project_id = p.id
    LEFT JOIN (
        SELECT user_id, project_id, SUM(hours_spent) as utilized_hours
        FROM project_time_logs
        WHERE is_utilized = 1
        GROUP BY user_id, project_id
    ) ptl ON ua.user_id = ptl.user_id AND ua.project_id = ptl.project_id
    WHERE $whereClause AND (ua.is_removed IS NULL OR ua.is_removed = 0)
";
$summaryStmt = $db->prepare($summaryQuery);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$flashSuccess = isset($_SESSION['success']) ? (string)$_SESSION['success'] : '';
$flashError = isset($_SESSION['error']) ? (string)$_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-users-cog"></i> Bulk Hours Management</h2>
        <a href="<?php echo $baseDir; ?>/modules/admin/dashboard.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <!-- Summary Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-primary mb-1"><?php echo number_format($summary['total_allocated'] ?? 0, 1); ?>h</h3>
                    <p class="mb-0 text-muted small">Total Allocated Hours</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-success mb-1"><?php echo number_format($summary['total_utilized'] ?? 0, 1); ?>h</h3>
                    <p class="mb-0 text-muted small">Total Utilized Hours</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-warning mb-1"><?php echo number_format($summary['total_remaining'] ?? 0, 1); ?>h</h3>
                    <p class="mb-0 text-muted small">Total Remaining Hours</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-info mb-1"><?php echo (int)($summary['total_users'] ?? 0); ?> / <?php echo (int)($summary['total_projects'] ?? 0); ?></h3>
                    <p class="mb-0 text-muted small">Users / Projects</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-filter"></i> Filters & Search</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-search"></i> Search User</label>
                    <input type="text" name="search_user" class="form-control" 
                           placeholder="Search by name or username..." 
                           value="<?php echo htmlspecialchars($searchUser ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-search"></i> Search Project</label>
                    <input type="text" name="search_project" class="form-control" 
                           placeholder="Search by title or PO number..." 
                           value="<?php echo htmlspecialchars($searchProject ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-project-diagram"></i> Project</label>
                    <select name="project_filter" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo $project['id']; ?>" <?php echo $projectFilter == $project['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['title'] . ' (' . $project['po_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-user"></i> User</label>
                    <select name="user_filter" class="form-select">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $userFilter == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-user-tag"></i> Role</label>
                    <select name="role_filter" class="form-select">
                        <option value="">All Roles</option>
                        <option value="project_lead" <?php echo $roleFilter === 'project_lead' ? 'selected' : ''; ?>>Project Lead</option>
                        <option value="qa" <?php echo $roleFilter === 'qa' ? 'selected' : ''; ?>>QA</option>
                        <option value="at_tester" <?php echo $roleFilter === 'at_tester' ? 'selected' : ''; ?>>AT Tester</option>
                        <option value="ft_tester" <?php echo $roleFilter === 'ft_tester' ? 'selected' : ''; ?>>FT Tester</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-flag"></i> Project Status</label>
                    <select name="project_status_filter" class="form-select">
                        <option value="">All Status</option>
                        <option value="not_started" <?php echo $projectStatusFilter === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                        <option value="in_progress" <?php echo $projectStatusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="on_hold" <?php echo $projectStatusFilter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-list"></i> Per Page</label>
                    <select name="per_page" class="form-select">
                        <?php foreach ([10, 25, 50, 100, 200] as $pp): ?>
                            <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>>
                                <?php echo $pp; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-exclamation-triangle"></i> Special Filters</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_over_allocated" value="1" 
                               id="showOverAllocated" <?php echo $showOverAllocated ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="showOverAllocated">
                            Show Over-allocated Projects Only
                        </label>
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="d-flex gap-2 w-100">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary" title="Clear Filters">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Update Form -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-users-cog"></i> Hours Assignments 
                <span class="badge bg-primary"><?php echo (int)$totalRecords; ?> total</span>
            </h5>
            <div>
                <button type="button" class="btn btn-success btn-sm" onclick="applyBulkUpdate()">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="resetChanges()">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
        </div>
        <div class="card-body">
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

            <?php if (empty($assignments)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No assignments found matching the selected filters.</p>
                </div>
            <?php else: ?>
                <form id="bulkUpdateForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="bulk_update" value="1">
                    
                    <!-- Reason and Quick Actions - Above Table -->
                    <div class="row mb-3 p-3 bg-light rounded">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-comment"></i> Reason for Bulk Update (Optional)</label>
                            <textarea name="bulk_reason" class="form-control" rows="2" placeholder="Optional: Provide reason for these changes..."></textarea>
                            <small class="text-muted">You can provide a reason for audit trail purposes</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-bolt"></i> Quick Actions</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="increaseAll(5)">
                                    <i class="fas fa-plus"></i> +5h All
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="increaseAll(10)">
                                    <i class="fas fa-plus"></i> +10h All
                                </button>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="decreaseAll(5)">
                                    <i class="fas fa-minus"></i> -5h All
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearAll()">
                                    <i class="fas fa-eraser"></i> Clear All
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>User</th>
                                    <th>Project</th>
                                    <th>Role</th>
                                    <th>Current Hours</th>
                                    <th>Utilized Hours</th>
                                    <th>Remaining</th>
                                    <th>New Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                <?php
                                    $minAllowed = max(0, (float)$assignment['utilized_hours']);
                                    $projectTotal = (float)($assignment['total_hours'] ?? 0);
                                    $projectAllocated = (float)($assignment['total_allocated_hours'] ?? 0);
                                    $currentHours = (float)($assignment['hours_allocated'] ?? 0);
                                    $freeHours = max(0.0, $projectTotal - $projectAllocated);
                                    $maxAllowed = $currentHours + $freeHours;
                                    $isOverAllocated = $projectAllocated > $projectTotal;
                                ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($assignment['full_name']); ?></strong>
                                            <br>
                                            <span class="badge bg-<?php 
                                                echo match($assignment['user_role']) {
                                                    'project_lead' => 'primary',
                                                    'qa' => 'success',
                                                    'at_tester' => 'info',
                                                    'ft_tester' => 'warning',
                                                    default => 'secondary'
                                                };
                                            ?> badge-sm">
                                                <?php echo ucfirst(str_replace('_', ' ', $assignment['user_role'])); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($assignment['project_title']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($assignment['po_number']); ?></small>
                                            <br>
                                            <small class="text-info">
                                                <?php echo number_format($assignment['total_hours'], 1); ?>h total, 
                                                <?php echo number_format($assignment['total_allocated_hours'], 1); ?>h allocated
                                            </small>
                                            <?php if ($isOverAllocated): ?>
                                                <br>
                                                <small class="text-danger">
                                                    <i class="fas fa-exclamation-triangle"></i> Over-allocated by <?php echo number_format($projectAllocated - $projectTotal, 1); ?>h
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo ucfirst(str_replace('_', ' ', $assignment['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo number_format($assignment['hours_allocated'], 1); ?>h
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success">
                                            <?php echo number_format($assignment['utilized_hours'], 1); ?>h
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $remaining = $assignment['hours_allocated'] - $assignment['utilized_hours'];
                                        $badgeClass = $remaining > 0 ? 'warning' : 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $badgeClass; ?>">
                                            <?php echo number_format($remaining, 1); ?>h
                                        </span>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="updates[<?php echo $assignment['id']; ?>]" 
                                               class="form-control form-control-sm hours-input" 
                                               step="0.01" 
                                               min="<?php echo $minAllowed; ?>" 
                                               max="<?php echo $maxAllowed; ?>"
                                               placeholder="<?php echo $assignment['hours_allocated']; ?>"
                                               data-original="<?php echo $assignment['hours_allocated']; ?>"
                                               data-min="<?php echo $minAllowed; ?>"
                                               data-project-total="<?php echo $assignment['total_hours']; ?>"
                                               data-project-allocated="<?php echo $assignment['total_allocated_hours']; ?>"
                                               data-max-allowed="<?php echo $maxAllowed; ?>"
                                               data-over-allocated="<?php echo $isOverAllocated ? '1' : '0'; ?>"
                                               data-assignment-id="<?php echo $assignment['id']; ?>"
                                               style="width: 100px;"
                                               onchange="validateHours(this)">
                                        <small class="text-muted hours-info" style="font-size: 0.7em;">
                                            Min: <?php echo number_format($minAllowed, 1); ?>h, Max: <?php echo number_format($maxAllowed, 1); ?>h
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($assignment['project_status']) {
                                                'in_progress' => 'success',
                                                'on_hold' => 'warning',
                                                'not_started' => 'secondary',
                                                default => 'info'
                                            };
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $assignment['project_status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <?php
                        $baseParams = $_GET;
                        unset($baseParams['page']);
                        $buildPageUrl = function($p) use ($baseParams) {
                            $params = $baseParams;
                            $params['page'] = $p;
                            return $_SERVER['PHP_SELF'] . '?' . http_build_query($params);
                        };
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                    ?>
                    <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                        <small class="text-muted">
                            Showing <?php echo (int)($offset + 1); ?> 
                            to <?php echo (int)min($offset + $perPage, $totalRecords); ?> 
                            of <?php echo (int)$totalRecords; ?> assignments
                        </small>
                        <nav aria-label="Assignments pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $page <= 1 ? '#' : htmlspecialchars($buildPageUrl($page - 1)); ?>">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                </li>
                                <?php if ($startPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildPageUrl(1)); ?>">1</a>
                                    </li>
                                    <?php if ($startPage > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildPageUrl($p)); ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildPageUrl($totalPages)); ?>"><?php echo $totalPages; ?></a>
                                    </li>
                                <?php endif; ?>
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $page >= $totalPages ? '#' : htmlspecialchars($buildPageUrl($page + 1)); ?>">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window.BulkHoursConfig = {
    flashSuccess: <?php echo json_encode($flashSuccess, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    flashError: <?php echo json_encode($flashError, JSON_HEX_TAG | JSON_HEX_AMP); ?>
};
</script>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/bulk-hours.js?v=<?php echo time(); ?>"></script>


<style>
.badge-sm {
    font-size: 0.7em;
}

.card {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.hours-input:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.hours-input[value]:not([value=""]) {
    background-color: #fff3cd;
    border-color: #ffc107;
}

.hours-info {
    font-size: 0.7em;
    display: block;
    margin-top: 2px;
}

.project-hours-summary {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 4px;
    padding: 4px 8px;
    margin-top: 4px;
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; 