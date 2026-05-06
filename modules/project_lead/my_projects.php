<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['project_lead', 'admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Get total count first
$countQuery = "
    SELECT COUNT(DISTINCT p.id) as total
    FROM projects p
    WHERE (p.project_lead_id = ? OR p.created_by = ?)
       OR p.id IN (SELECT project_id FROM user_assignments WHERE user_id = ? AND (is_removed IS NULL OR is_removed = 0))
";

$countStmt = $db->prepare($countQuery);
$countStmt->execute([$userId, $userId, $userId]);
$totalProjects = $countStmt->fetch()['total'];
$totalPages = ceil($totalProjects / $perPage);

// Get projects for this project lead with pagination
$assignedProjectsQuery = "
    SELECT DISTINCT p.id, p.title, p.po_number, p.project_code, p.status, p.project_type, p.priority,
           c.name as client_name,
           (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) as current_phase,
           COUNT(DISTINCT pp.id) as total_pages,
           SUM(CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN 1 ELSE 0 END) as completed_pages
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN project_pages pp ON p.id = pp.project_id
    WHERE (p.project_lead_id = ? OR p.created_by = ?)
       OR p.id IN (SELECT project_id FROM user_assignments WHERE user_id = ? AND (is_removed IS NULL OR is_removed = 0))
    GROUP BY p.id, p.title, p.po_number, p.project_code, p.status, p.project_type, p.priority, c.name
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";

$assignedProjects = $db->prepare($assignedProjectsQuery);
$assignedProjects->execute([$userId, $userId, $userId, $perPage, $offset]);
$projects = $assignedProjects->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-project-diagram text-primary"></i> My Projects</h2>
                <a href="<?php echo $baseDir; ?>/modules/project_lead/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- All Projects Table with Filters -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0"><i class="fas fa-list"></i> All My Projects</h5>
                <small class="text-muted">Total: <?php echo $totalProjects; ?> projects</small>
            </div>
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
                <select id="priorityFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All Priorities</option>
                    <option value="critical">Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
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
                                <th>Client</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Phase</th>
                                <th>Progress</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                            <tr data-status="<?php echo htmlspecialchars($project['status']); ?>" 
                                data-type="<?php echo htmlspecialchars($project['project_type']); ?>"
                                data-priority="<?php echo htmlspecialchars($project['priority']); ?>"
                                data-title="<?php echo htmlspecialchars(strtolower($project['title'])); ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($project['project_code'] ?: $project['po_number']); ?></td>
                                <td><?php echo htmlspecialchars($project['client_name'] ?? '—'); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo ucfirst(str_replace('_', ' ', $project['project_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'planning' => 'secondary',
                                        'in_progress' => 'primary',
                                        'on_hold' => 'warning',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                    $statusColor = $statusColors[$project['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $statusColor; ?>">
                                        <?php echo formatProjectStatusLabel($project['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $priorityColors = [
                                        'critical' => 'danger',
                                        'high' => 'warning',
                                        'medium' => 'info',
                                        'low' => 'secondary'
                                    ];
                                    $priorityColor = $priorityColors[$project['priority']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $priorityColor; ?>">
                                        <?php echo ucfirst($project['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($project['current_phase'])): ?>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($project['current_phase']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $progress = $project['total_pages'] > 0 ? 
                                        round(($project['completed_pages'] / $project['total_pages']) * 100) : 0;
                                    ?>
                                    <div class="progress" style="height: 20px; min-width: 100px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%">
                                            <?php echo $progress; ?>%
                                        </div>
                                    </div>
                                    <small class="text-muted"><?php echo $project['completed_pages']; ?>/<?php echo $project['total_pages']; ?></small>
                                </td>
                                <td>
                                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                                       class="btn btn-sm btn-info me-1">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?php echo $baseDir; ?>/modules/projects/manage_assignments.php?project_id=<?php echo $project['id']; ?>" 
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-tasks"></i> Assign
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <nav aria-label="Projects pagination" class="mt-3">
                    <ul class="pagination justify-content-center mb-0">
                        <!-- Previous Button -->
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <?php
                        // Show page numbers with ellipsis
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=1">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Next Button -->
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
                <div class="text-center mt-2">
                    <small class="text-muted">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $totalProjects); ?> of <?php echo $totalProjects; ?> projects
                    </small>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/my-projects-filter.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; 