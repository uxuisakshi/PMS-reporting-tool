<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['ft_tester', 'admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

// Fetch all projects where user is assigned via user_assignments table
$assignedProjects = $db->prepare("
    SELECT DISTINCT p.id, p.title, p.po_number, p.status, p.project_type,
           COUNT(DISTINCT pp.id) as total_pages,
           COUNT(DISTINCT CASE WHEN pp.ft_tester_id = ? THEN pp.id END) as assigned_pages,
           0 as completed_pages
    FROM projects p
    INNER JOIN user_assignments ua ON ua.project_id = p.id AND ua.user_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    LEFT JOIN project_pages pp ON pp.project_id = p.id
    GROUP BY p.id, p.title, p.po_number, p.status, p.project_type
    ORDER BY p.created_at DESC
");
$assignedProjects->execute([$userId, $userId]);
$projects = $assignedProjects->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-mobile-alt text-success"></i> My Projects</h2>
                <a href="<?php echo $baseDir; ?>/modules/ft_tester/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list"></i> All Assigned Projects</h5>
            <div class="d-flex gap-2">
                <select id="statusFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All Status</option>
                    <option value="planning">Planning</option>
                    <option value="in_progress">In Progress</option>
                    <option value="on_hold">On Hold</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select id="typeFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All Types</option>
                    <option value="website">Website</option>
                    <option value="mobile_app">Mobile App</option>
                    <option value="web_app">Web App</option>
                    <option value="other">Other</option>
                </select>
                <input type="text" id="searchProject" class="form-control form-control-sm" placeholder="Search projects..." style="width: 200px;">
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($projects)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No projects assigned yet</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="projectsTable">
                        <thead>
                            <tr>
                                <th>Project Title</th>
                                <th>Project Code</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Assigned Pages</th>
                                <th>Progress</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                            <tr data-status="<?php echo htmlspecialchars($project['status']); ?>"
                                data-type="<?php echo htmlspecialchars($project['project_type']); ?>"
                                data-title="<?php echo htmlspecialchars(strtolower($project['title'])); ?>">
                                <td><strong><?php echo htmlspecialchars($project['title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($project['po_number']); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo ucfirst(str_replace('_', ' ', $project['project_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $statusColors = ['planning'=>'secondary','in_progress'=>'primary','on_hold'=>'warning','completed'=>'success','cancelled'=>'danger'];
                                    $statusColor = $statusColors[$project['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $statusColor; ?>">
                                        <?php echo formatProjectStatusLabel($project['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo (int)$project['assigned_pages']; ?></td>
                                <td>
                                    <?php $progress = $project['assigned_pages'] > 0 ? round(($project['completed_pages'] / $project['assigned_pages']) * 100) : 0; ?>
                                    <div class="progress" style="height: 20px; min-width: 100px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%">
                                            <?php echo $progress; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>"
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/my-projects-filter.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; 