<?php
// Force no caching
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['project_lead', 'admin']);

$userId = $_SESSION['user_id'];
$db = Database::getInstance();
$baseDir = getBaseDir();
$myDevicesStmt = $db->prepare("
    SELECT d.device_name, d.device_type, d.model, d.version, da.assigned_at
    FROM device_assignments da
    JOIN devices d ON d.id = da.device_id
    WHERE da.user_id = ? AND da.status = 'Active'
    ORDER BY da.assigned_at DESC
");
$myDevicesStmt->execute([$userId]);
$myDevices = $myDevicesStmt->fetchAll(PDO::FETCH_ASSOC);

// Check if user has resource workload access
$hasResourceWorkloadAccess = hasResourceWorkloadAccess($db, $userId);

// Get project lead's projects (both as actual lead AND as assigned team member)
$projectsQuery = "
    SELECT DISTINCT p.*, c.name as client_name,
           (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) as current_phase,
           COUNT(DISTINCT pp.id) as total_pages,
           SUM(CASE WHEN pp.status = 'completed' THEN 1 ELSE 0 END) as completed_pages,
           ROUND(COALESCE(SUM(CASE WHEN pp.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(pp.id), 0), 0), 2) as completion_percentage
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN project_pages pp ON p.id = pp.project_id
    WHERE p.status NOT IN ('completed', 'cancelled')
      AND (
          p.project_lead_id = ?
          OR p.id IN (SELECT project_id FROM user_assignments WHERE user_id = ? AND (is_removed IS NULL OR is_removed = 0))
      )
    GROUP BY p.id, p.title, p.description, p.project_type, p.client_id, p.priority, p.status, p.total_hours, p.project_lead_id, p.created_by, p.created_at, p.completed_at, c.name
    ORDER BY p.priority DESC, p.created_at DESC
";
$stmt = $db->prepare($projectsQuery);
$stmt->execute([$userId, $userId]);
$projects = $stmt->fetchAll();

// Get team members - simplified query
$teamQuery = "
    SELECT DISTINCT u.id, u.full_name, u.role, u.email,
           0 as assigned_pages,
           0 as completed_pages
    FROM users u
    JOIN user_assignments ua ON u.id = ua.user_id
    JOIN projects p ON ua.project_id = p.id
    WHERE p.project_lead_id = ? 
    AND u.is_active = 1 
    AND u.role IN ('qa', 'at_tester', 'ft_tester')
    ORDER BY u.full_name
";
$stmt = $db->prepare($teamQuery);
$stmt->execute([$userId]);
$teamMembers = $stmt->fetchAll();

// Get pages count for each team member
foreach ($teamMembers as &$member) {
    $pagesQuery = "
        SELECT COUNT(*) as assigned_pages,
               SUM(CASE WHEN pp.status = 'completed' THEN 1 ELSE 0 END) as completed_pages
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE p.project_lead_id = ?
        AND (
            pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ?
            OR (pp.at_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.at_tester_ids, JSON_ARRAY(?)))
            OR (pp.ft_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.ft_tester_ids, JSON_ARRAY(?)))
        )
    ";
    $stmt = $db->prepare($pagesQuery);
    $stmt->execute([$userId, $member['id'], $member['id'], $member['id'], $member['id'], $member['id']]);
    $pageData = $stmt->fetch();
    
    $member['assigned_pages'] = $pageData['assigned_pages'] ?? 0;
    $member['completed_pages'] = $pageData['completed_pages'] ?? 0;
}

include __DIR__ . '/../../includes/header.php';
?>
<style>
.dashboard-no-page-overflow {
    overflow-x: clip;
}
.dashboard-no-page-overflow .table-responsive {
    max-width: 100%;
    max-height: 420px;
    overflow-x: auto;
    overflow-y: auto;
}
.dashboard-no-page-overflow .list-group {
    max-height: 420px;
    overflow-y: auto;
}
</style>
<div class="container-fluid dashboard-no-page-overflow">
    <!-- <div class="alert alert-success">
        <strong>NEW VERSION LOADED!</strong> This is the updated dashboard (<?php echo date('Y-m-d H:i:s'); ?>)
    </div> -->
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>Project Lead Dashboard</h2>
            <p class="text-muted mb-0">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-laptop"></i> My Assigned Devices</h6>
            <a href="<?php echo $baseDir; ?>/modules/devices.php" class="btn btn-sm btn-outline-primary">View Devices</a>
        </div>
        <div class="card-body py-2">
            <?php if (empty($myDevices)): ?>
                <span class="text-muted">No office device assigned.</span>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($myDevices as $dev): ?>
                        <span class="badge bg-light text-dark border">
                            <?php echo htmlspecialchars((string)$dev['device_name']); ?>
                            (<?php echo htmlspecialchars((string)$dev['device_type']); ?>)
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <!-- Projects -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>My Projects (<?php echo count($projects); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($projects)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Client</th>
                                        <th>Progress</th>
                                        <th>Phase</th>
                                        <th>Priority</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projects as $project): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($project['po_number']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <div class="progress mb-1" style="height: 8px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo $project['completion_percentage']; ?>%"></div>
                                            </div>
                                            <small><?php echo $project['completed_pages']; ?>/<?php echo $project['total_pages']; ?> pages</small>
                                        </td>
                                        <td>
                                            <?php if (!empty($project['current_phase'])): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($project['current_phase']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo match($project['priority']) {
                                                    'critical' => 'danger',
                                                    'high' => 'warning',
                                                    'medium' => 'info',
                                                    'low' => 'secondary',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($project['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                                                   class="btn btn-outline-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($hasResourceWorkloadAccess): ?>
                                                <a href="<?php echo $baseDir; ?>/modules/admin/resource_workload.php?project_id=<?php echo $project['id']; ?>" 
                                                   class="btn btn-outline-warning">
                                                    <i class="fas fa-clock"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <p>No active projects assigned.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Team -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5>My Team (<?php echo count($teamMembers); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($teamMembers)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Team Member</th>
                                        <th>Role</th>
                                        <th>Pages</th>
                                        <th>Completed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($teamMembers as $member): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $member['id']; ?>">
                                                <?php echo htmlspecialchars($member['full_name']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $member['assigned_pages']; ?></td>
                                        <td>
                                            <span class="badge bg-success">
                                                <?php echo $member['completed_pages']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <p>No team members assigned.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5>Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="<?php echo $baseDir; ?>/modules/projects/view.php" class="btn btn-primary">
                            <i class="fas fa-list"></i> View All Projects
                        </a>
                        <?php if ($hasResourceWorkloadAccess): ?>
                        <a href="<?php echo $baseDir; ?>/modules/admin/resource_workload.php" class="btn btn-warning">
                            <i class="fas fa-users"></i> Team Workload
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo $baseDir; ?>/modules/admin/bulk_hours_management.php" class="btn btn-info">
                            <i class="fas fa-clock"></i> Manage Hours
                        </a>
                        <a href="<?php echo $baseDir; ?>/modules/chat/project_chat.php" class="btn btn-success">
                            <i class="fas fa-comments"></i> Project Chat
                        </a>
                        <a href="<?php echo $baseDir; ?>/modules/reports/dashboard.php" class="btn btn-info">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 