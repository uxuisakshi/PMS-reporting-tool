<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead']);

$db = Database::getInstance();

// For project leads, check if they have resource workload permission
if ($_SESSION['role'] === 'project_lead') {
    $userId = $_SESSION['user_id'];
    
    // Check if this project lead has resource workload access permission
    $permissionStmt = $db->prepare("
        SELECT COUNT(*) as has_permission
        FROM project_permissions pp
        WHERE pp.user_id = ? 
        AND pp.permission_type = 'resource_workload_access'
        AND pp.is_active = 1
        AND (pp.expires_at IS NULL OR pp.expires_at > NOW())
    ");
    $permissionStmt->execute([$userId]);
    $hasPermission = $permissionStmt->fetchColumn() > 0;
    
    if (!$hasPermission) {
        $_SESSION['error'] = 'Access denied. You do not have permission to view resource workload.';
        header('Location: ' . getBaseDir() . '/modules/project_lead/dashboard.php');
        exit;
    }
}

// Add cache-busting headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Handle filters
$roleFilter = $_GET['role_filter'] ?? 'all';
$workloadFilter = $_GET['workload_filter'] ?? 'all';
$sortBy = $_GET['sort_by'] ?? 'name';
$dateRange = $_GET['date_range'] ?? '30';

// Get all active users first
$userQuery = "SELECT id, full_name, role, email, created_at FROM users WHERE is_active = 1";
$userParams = [];

if ($roleFilter !== 'all') {
    $userQuery .= " AND role = ?";
    $userParams[] = $roleFilter;
} else {
    $userQuery .= " AND role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
}

$userQuery .= " ORDER BY full_name";

$stmt = $db->prepare($userQuery);
$stmt->execute($userParams);
$users = $stmt->fetchAll();

// Now get metrics for each user
$workload = [];
foreach ($users as $user) {
    $userId = $user['id'];
    
    // Get active projects count
    $activeProjectsQuery = "
        SELECT COUNT(DISTINCT p.id) as count
        FROM project_pages pp 
        JOIN projects p ON pp.project_id = p.id 
        WHERE (pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ? 
               OR JSON_CONTAINS(COALESCE(pp.at_tester_ids, '[]'), JSON_QUOTE(?))
               OR JSON_CONTAINS(COALESCE(pp.ft_tester_ids, '[]'), JSON_QUOTE(?)))
        AND p.status NOT IN ('completed', 'cancelled')
    ";
    $stmt = $db->prepare($activeProjectsQuery);
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
    $activeProjects = $stmt->fetch()['count'];
    
    // Get assigned pages count
    $assignedPagesQuery = "
        SELECT COUNT(DISTINCT pp.id) as count
        FROM project_pages pp 
        WHERE (pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ? 
               OR JSON_CONTAINS(COALESCE(pp.at_tester_ids, '[]'), JSON_QUOTE(?))
               OR JSON_CONTAINS(COALESCE(pp.ft_tester_ids, '[]'), JSON_QUOTE(?)))
    ";
    $stmt = $db->prepare($assignedPagesQuery);
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
    $assignedPages = $stmt->fetch()['count'];
    
    // Get completed pages count
    $completedPagesQuery = "
        SELECT COUNT(DISTINCT pp.id) as count
        FROM project_pages pp 
        WHERE (pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ? 
               OR JSON_CONTAINS(COALESCE(pp.at_tester_ids, '[]'), JSON_QUOTE(?))
               OR JSON_CONTAINS(COALESCE(pp.ft_tester_ids, '[]'), JSON_QUOTE(?)))
        AND pp.status = 'completed'
    ";
    $stmt = $db->prepare($completedPagesQuery);
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
    $completedPages = $stmt->fetch()['count'];
    
    // Get in progress projects count
    $inProgressQuery = "
        SELECT COUNT(DISTINCT p.id) as count
        FROM project_pages pp 
        JOIN projects p ON pp.project_id = p.id 
        WHERE (pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ? 
               OR JSON_CONTAINS(COALESCE(pp.at_tester_ids, '[]'), JSON_QUOTE(?))
               OR JSON_CONTAINS(COALESCE(pp.ft_tester_ids, '[]'), JSON_QUOTE(?)))
        AND p.status = 'in_progress'
    ";
    $stmt = $db->prepare($inProgressQuery);
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
    $inProgressProjects = $stmt->fetch()['count'];
    
    // Get critical projects count
    $criticalQuery = "
        SELECT COUNT(DISTINCT p.id) as count
        FROM project_pages pp 
        JOIN projects p ON pp.project_id = p.id 
        WHERE (pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ? 
               OR JSON_CONTAINS(COALESCE(pp.at_tester_ids, '[]'), JSON_QUOTE(?))
               OR JSON_CONTAINS(COALESCE(pp.ft_tester_ids, '[]'), JSON_QUOTE(?)))
        AND p.priority = 'critical'
    ";
    $stmt = $db->prepare($criticalQuery);
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
    $criticalProjects = $stmt->fetch()['count'];
    
    // Get hours from testing results (period)
    $hoursQuery = "
        SELECT COALESCE(SUM(hours_spent), 0) as total_hours
        FROM testing_results 
        WHERE tester_id = ? AND DATE(tested_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ";
    $stmt = $db->prepare($hoursQuery);
    $stmt->execute([$userId, $dateRange]);
    $hoursPeriod = $stmt->fetch()['total_hours'];
    
    // Get total hours from testing results
    $totalHoursQuery = "
        SELECT COALESCE(SUM(hours_spent), 0) as total_hours
        FROM testing_results 
        WHERE tester_id = ?
    ";
    $stmt = $db->prepare($totalHoursQuery);
    $stmt->execute([$userId]);
    $totalHours = $stmt->fetch()['total_hours'];
    
    // Get recent activity from project_time_logs
    $activityQuery = "
        SELECT COUNT(*) as count
        FROM project_time_logs 
        WHERE user_id = ? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ";
    $stmt = $db->prepare($activityQuery);
    $stmt->execute([$userId]);
    $recentActivity = $stmt->fetch()['count'];
    
    // Get allocated hours from user_assignments
    $allocatedHoursQuery = "
        SELECT COALESCE(SUM(hours_allocated), 0) as total_allocated
        FROM user_assignments ua
        JOIN projects p ON ua.project_id = p.id
        WHERE ua.user_id = ? AND p.status NOT IN ('completed', 'cancelled')
    ";
    $stmt = $db->prepare($allocatedHoursQuery);
    $stmt->execute([$userId]);
    $allocatedHours = $stmt->fetch()['total_allocated'];
    
    // Get utilized hours from project_time_logs
    $utilizedHoursQuery = "
        SELECT COALESCE(SUM(hours_spent), 0) as total_utilized
        FROM project_time_logs 
        WHERE user_id = ? AND is_utilized = 1
    ";
    $stmt = $db->prepare($utilizedHoursQuery);
    $stmt->execute([$userId]);
    $utilizedHours = $stmt->fetch()['total_utilized'];
    
    // Calculate pending hours
    $pendingHours = max(0, $allocatedHours - $utilizedHours);
    
    // Calculate efficiency score
    $completionRate = $assignedPages > 0 ? ($completedPages / $assignedPages) * 100 : 0;
    $hoursUtilization = $allocatedHours > 0 ? ($utilizedHours / $allocatedHours) * 100 : 0;
    $efficiencyScore = min(100, max(0, ($completionRate * 0.4) + ($hoursUtilization * 0.4) + ($recentActivity > 0 ? 20 : 0)));
    
    // Build workload array
    $workload[] = [
        'id' => $user['id'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'email' => $user['email'],
        'join_date' => $user['created_at'],
        'active_projects' => $activeProjects,
        'assigned_pages' => $assignedPages,
        'completed_pages' => $completedPages,
        'in_progress_projects' => $inProgressProjects,
        'critical_projects' => $criticalProjects,
        'hours_period' => $hoursPeriod,
        'total_hours' => $totalHours,
        'allocated_hours' => $allocatedHours,
        'utilized_hours' => $utilizedHours,
        'pending_hours' => $pendingHours,
        'recent_activity' => $recentActivity,
        'efficiency_score' => round($efficiencyScore)
    ];
}

// Apply workload filter
if ($workloadFilter !== 'all') {
    $workload = array_filter($workload, function($resource) use ($workloadFilter) {
        return match($workloadFilter) {
            'overloaded' => $resource['active_projects'] > 5,
            'busy' => $resource['active_projects'] >= 3 && $resource['active_projects'] <= 5,
            'available' => $resource['active_projects'] < 3,
            'inactive' => $resource['recent_activity'] == 0,
            'high_efficiency' => $resource['efficiency_score'] > 75,
            'low_efficiency' => $resource['efficiency_score'] < 50,
            'over_allocated' => $resource['pending_hours'] > 20,
            'under_utilized' => $resource['allocated_hours'] > 0 && ($resource['utilized_hours'] / $resource['allocated_hours']) < 0.5,
            default => true
        };
    });
}

// Apply sorting
usort($workload, function($a, $b) use ($sortBy) {
    return match($sortBy) {
        'role' => strcmp($a['role'], $b['role']) ?: strcmp($a['full_name'], $b['full_name']),
        'projects' => $b['active_projects'] <=> $a['active_projects'] ?: strcmp($a['full_name'], $b['full_name']),
        'hours' => $b['total_hours'] <=> $a['total_hours'] ?: strcmp($a['full_name'], $b['full_name']),
        'allocated' => $b['allocated_hours'] <=> $a['allocated_hours'] ?: strcmp($a['full_name'], $b['full_name']),
        'utilized' => $b['utilized_hours'] <=> $a['utilized_hours'] ?: strcmp($a['full_name'], $b['full_name']),
        'pending' => $b['pending_hours'] <=> $a['pending_hours'] ?: strcmp($a['full_name'], $b['full_name']),
        'pages' => $b['assigned_pages'] <=> $a['assigned_pages'] ?: strcmp($a['full_name'], $b['full_name']),
        'efficiency' => $b['efficiency_score'] <=> $a['efficiency_score'] ?: strcmp($a['full_name'], $b['full_name']),
        default => strcmp($a['full_name'], $b['full_name'])
    };
});

// Pagination
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
$page    = max(1, (int)($_GET['page'] ?? 1));
$totalResources = count($workload);
$totalPages     = max(1, (int)ceil($totalResources / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset  = ($page - 1) * $perPage;
$pagedWorkload = array_slice($workload, $offset, $perPage);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Resource Workload Analysis</h2>
        <div>
            <small class="text-muted">Fresh Version | Updated: <?php echo date('Y-m-d H:i:s'); ?></small>
            <a href="<?php echo $baseDir; ?>/modules/admin/dashboard.php" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-filter"></i> Filters & Options</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Role</label>
                    <select name="role_filter" class="form-select">
                        <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <option value="project_lead" <?php echo $roleFilter === 'project_lead' ? 'selected' : ''; ?>>Project Lead</option>
                        <option value="qa" <?php echo $roleFilter === 'qa' ? 'selected' : ''; ?>>QA</option>
                        <option value="at_tester" <?php echo $roleFilter === 'at_tester' ? 'selected' : ''; ?>>AT Tester</option>
                        <option value="ft_tester" <?php echo $roleFilter === 'ft_tester' ? 'selected' : ''; ?>>FT Tester</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Workload</label>
                    <select name="workload_filter" class="form-select">
                        <option value="all" <?php echo $workloadFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="overloaded" <?php echo $workloadFilter === 'overloaded' ? 'selected' : ''; ?>>Overloaded (5+ projects)</option>
                        <option value="busy" <?php echo $workloadFilter === 'busy' ? 'selected' : ''; ?>>Busy (3-5 projects)</option>
                        <option value="available" <?php echo $workloadFilter === 'available' ? 'selected' : ''; ?>>Available (&lt;3 projects)</option>
                        <option value="inactive" <?php echo $workloadFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="over_allocated" <?php echo $workloadFilter === 'over_allocated' ? 'selected' : ''; ?>>Over Allocated (20+ pending hours)</option>
                        <option value="under_utilized" <?php echo $workloadFilter === 'under_utilized' ? 'selected' : ''; ?>>Under Utilized (&lt;50% hours used)</option>
                        <option value="high_efficiency" <?php echo $workloadFilter === 'high_efficiency' ? 'selected' : ''; ?>>High Efficiency (75%+)</option>
                        <option value="low_efficiency" <?php echo $workloadFilter === 'low_efficiency' ? 'selected' : ''; ?>>Low Efficiency (&lt;50%)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sort By</label>
                    <select name="sort_by" class="form-select">
                        <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name</option>
                        <option value="role" <?php echo $sortBy === 'role' ? 'selected' : ''; ?>>Role</option>
                        <option value="projects" <?php echo $sortBy === 'projects' ? 'selected' : ''; ?>>Active Projects</option>
                        <option value="pages" <?php echo $sortBy === 'pages' ? 'selected' : ''; ?>>Assigned Pages</option>
                        <option value="allocated" <?php echo $sortBy === 'allocated' ? 'selected' : ''; ?>>Allocated Hours</option>
                        <option value="utilized" <?php echo $sortBy === 'utilized' ? 'selected' : ''; ?>>Utilized Hours</option>
                        <option value="pending" <?php echo $sortBy === 'pending' ? 'selected' : ''; ?>>Pending Hours</option>
                        <option value="hours" <?php echo $sortBy === 'hours' ? 'selected' : ''; ?>>Total Hours</option>
                        <option value="efficiency" <?php echo $sortBy === 'efficiency' ? 'selected' : ''; ?>>Efficiency Score</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Time Period</label>
                    <select name="date_range" class="form-select">
                        <option value="7" <?php echo $dateRange === '7' ? 'selected' : ''; ?>>Last 7 days</option>
                        <option value="30" <?php echo $dateRange === '30' ? 'selected' : ''; ?>>Last 30 days</option>
                        <option value="90" <?php echo $dateRange === '90' ? 'selected' : ''; ?>>Last 90 days</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <select name="per_page" class="form-select" style="width:auto;">
                            <?php foreach ([10, 25, 50, 100] as $pp): ?>
                                <option value="<?php echo $pp; ?>"<?php if ($perPage == $pp) echo ' selected'; ?>><?php echo $pp; ?>/page</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row mb-4">
        <?php
        $totalResources = count($workload);
        $avgProjects = $totalResources > 0 ? round(array_sum(array_column($workload, 'active_projects')) / $totalResources, 1) : 0;
        $avgHours = $totalResources > 0 ? round(array_sum(array_column($workload, 'hours_period')) / $totalResources, 1) : 0;
        $avgEfficiency = $totalResources > 0 ? round(array_sum(array_column($workload, 'efficiency_score')) / $totalResources, 1) : 0;
        $totalAllocated = array_sum(array_column($workload, 'allocated_hours'));
        $totalUtilized = array_sum(array_column($workload, 'utilized_hours'));
        $totalPending = array_sum(array_column($workload, 'pending_hours'));
        ?>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-primary"><?php echo $totalResources; ?></h3>
                    <p class="mb-0">Total Resources</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-info"><?php echo $avgProjects; ?></h3>
                    <p class="mb-0">Avg Projects</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-primary"><?php echo number_format($totalAllocated, 1); ?>h</h3>
                    <p class="mb-0">Total Allocated</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-success"><?php echo number_format($totalUtilized, 1); ?>h</h3>
                    <p class="mb-0">Total Utilized</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-warning"><?php echo number_format($totalPending, 1); ?>h</h3>
                    <p class="mb-0">Total Pending</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-success"><?php echo $avgEfficiency; ?>%</h3>
                    <p class="mb-0">Avg Efficiency</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Resource Table -->
    <div class="card">
        <div class="card-header">
            <h5>Resource Details (<?php echo count($workload); ?> resources)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Resource</th>
                            <th>Role</th>
                            <th>Active Projects</th>
                            <th>Pages</th>
                            <th>Hours Allocation</th>
                            <th>Hours (<?php echo $dateRange; ?>d)</th>
                            <th>Efficiency</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagedWorkload as $resource): ?>
                        <tr>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($resource['full_name']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($resource['email']); ?></small>
                                    <?php if ($resource['critical_projects'] > 0): ?>
                                        <br><span class="badge bg-danger badge-sm">
                                            <?php echo $resource['critical_projects']; ?> Critical
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo match($resource['role']) {
                                        'project_lead' => 'primary',
                                        'qa' => 'success',
                                        'at_tester' => 'info',
                                        'ft_tester' => 'warning',
                                        default => 'secondary'
                                    };
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $resource['role'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $resource['active_projects'] > 5 ? 'danger' : 
                                         ($resource['active_projects'] >= 3 ? 'warning' : 'success'); 
                                ?>">
                                    <?php echo $resource['active_projects']; ?>
                                </span>
                                <?php if ($resource['in_progress_projects'] > 0): ?>
                                    <br><small class="text-muted"><?php echo $resource['in_progress_projects']; ?> in progress</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo $resource['assigned_pages']; ?></strong> assigned
                                    <br>
                                    <small class="text-success"><?php echo $resource['completed_pages']; ?> completed</small>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <div class="mb-1">
                                        <span class="badge bg-primary"><?php echo number_format($resource['allocated_hours'], 1); ?>h</span>
                                        <small class="text-muted">allocated</small>
                                    </div>
                                    <div class="mb-1">
                                        <span class="badge bg-success"><?php echo number_format($resource['utilized_hours'], 1); ?>h</span>
                                        <small class="text-muted">utilized</small>
                                    </div>
                                    <div>
                                        <span class="badge bg-<?php echo $resource['pending_hours'] > 0 ? 'warning' : 'secondary'; ?>">
                                            <?php echo number_format($resource['pending_hours'], 1); ?>h
                                        </span>
                                        <small class="text-muted">pending</small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo $resource['hours_period']; ?>h</strong>
                                    <br>
                                    <small class="text-muted"><?php echo $resource['total_hours']; ?>h total</small>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="progress me-2" style="width: 60px; height: 8px;">
                                        <div class="progress-bar bg-<?php 
                                            echo $resource['efficiency_score'] > 75 ? 'success' : 
                                                 ($resource['efficiency_score'] > 50 ? 'warning' : 'danger'); 
                                        ?>" style="width: <?php echo $resource['efficiency_score']; ?>%"></div>
                                    </div>
                                    <span class="badge bg-<?php 
                                        echo $resource['efficiency_score'] > 75 ? 'success' : 
                                             ($resource['efficiency_score'] > 50 ? 'warning' : 'danger'); 
                                    ?>">
                                        <?php echo $resource['efficiency_score']; ?>%
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if ($resource['recent_activity'] > 0): ?>
                                    <span class="badge bg-success">Active</span>
                                    <br><small class="text-muted"><?php echo $resource['recent_activity']; ?> logs (7d)</small>
                                <?php else: ?>
                                    <span class="badge bg-warning">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $resource['id']; ?>" 
                                       class="btn btn-outline-primary btn-sm" title="View Profile">
                                        <i class="fas fa-user"></i>
                                    </a>
                                    <a href="<?php echo $baseDir; ?>/modules/admin/performance.php?user_id=<?php echo $resource['id']; ?>" 
                                       class="btn btn-outline-info btn-sm" title="View Performance Report">
                                        <i class="fas fa-chart-bar"></i>
                                    </a>
                                    <a href="<?php echo $baseDir; ?>/modules/admin/manage_hours.php?user_id=<?php echo $resource['id']; ?>" 
                                       class="btn btn-outline-warning btn-sm" title="Manage Hours">
                                        <i class="fas fa-clock"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($pagedWorkload)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No resources found matching the selected filters.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1 || $totalResources > 10): ?>
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="text-muted small">
            Showing <?php echo min($offset + 1, $totalResources); ?>–<?php echo min($offset + $perPage, $totalResources); ?>
            of <?php echo $totalResources; ?> resource<?php echo $totalResources !== 1 ? 's' : ''; ?>
        </div>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $qs = $_GET; unset($qs['page']);
                $baseQs = http_build_query($qs);
                $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
                $buildLink = function(int $p) use ($baseUrl, $baseQs): string {
                    return $baseUrl . '?' . ($baseQs ? $baseQs . '&' : '') . 'page=' . $p;
                };

                // Prev
                if ($page > 1) {
                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildLink($page - 1)) . '">&laquo;</a></li>';
                } else {
                    echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
                }

                // Smart ellipsis
                $pagesToShow = [];
                if ($totalPages <= 9) {
                    for ($i = 1; $i <= $totalPages; $i++) $pagesToShow[] = $i;
                } else {
                    $pagesToShow[] = 1;
                    if ($page > 4) $pagesToShow[] = '...';
                    for ($i = max(2, $page - 2); $i <= min($totalPages - 1, $page + 2); $i++) $pagesToShow[] = $i;
                    if ($page < $totalPages - 3) $pagesToShow[] = '...';
                    $pagesToShow[] = $totalPages;
                }
                foreach ($pagesToShow as $p) {
                    if ($p === '...') {
                        echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    } else {
                        $cls = ($p == $page) ? ' active' : '';
                        echo '<li class="page-item' . $cls . '"><a class="page-link" href="' . htmlspecialchars($buildLink((int)$p)) . '">' . $p . '</a></li>';
                    }
                }

                // Next
                if ($page < $totalPages) {
                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildLink($page + 1)) . '">&raquo;</a></li>';
                } else {
                    echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
                }
                ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<style>
.badge-sm { font-size: 0.7em; }
.progress { border-radius: 4px; }
.card { box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.table th { font-weight: 600; background-color: #f8f9fa; }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>