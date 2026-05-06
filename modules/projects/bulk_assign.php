<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Get all active projects for quick selection
$projects = [];
if (hasAdminPrivileges()) {
    $projects = $db->query("SELECT id, title, po_number FROM projects WHERE status != 'cancelled' ORDER BY title")->fetchAll();
} elseif ($userRole === 'project_lead') {
    $stmt = $db->prepare("SELECT id, title, po_number FROM projects WHERE project_lead_id = ? AND status != 'cancelled' ORDER BY title");
    $stmt->execute([$userId]);
    $projects = $stmt->fetchAll();
} else { // QA
    $stmt = $db->prepare("
        SELECT DISTINCT p.id, p.title, p.po_number 
        FROM projects p
        JOIN user_assignments ua ON p.id = ua.project_id
        WHERE ua.user_id = ? AND p.status != 'cancelled'
        AND (ua.is_removed IS NULL OR ua.is_removed = 0)
        ORDER BY p.title
    ");
    $stmt->execute([$userId]);
    $projects = $stmt->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-magic text-success"></i> Quick Bulk Assignment</h2>
        <a href="list.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back to Projects
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Select Project for Bulk Assignment</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">
                        Choose a project to quickly assign testers and QA to multiple pages and environments at once.
                        This is much faster than assigning each page individually.
                    </p>

                    <div class="row">
                        <?php foreach ($projects as $project): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card border-success">
                                <div class="card-body">
                                    <h6 class="card-title"><?php echo htmlspecialchars($project['title']); ?></h6>
                                    <p class="card-text">
                                        <small class="text-muted">Project Code: <?php echo htmlspecialchars($project['po_number']); ?></small>
                                    </p>
                                    <a href="manage_assignments.php?project_id=<?php echo $project['id']; ?>&tab=bulk" 
                                       class="btn btn-success btn-sm">
                                        <i class="fas fa-magic"></i> Bulk Assign
                                    </a>
                                    <a href="manage_assignments.php?project_id=<?php echo $project['id']; ?>&tab=pages" 
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-edit"></i> Individual
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($projects)): ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Projects Available</h5>
                                <p class="text-muted">You don't have access to any active projects for assignment.</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Tips -->
    <div class="row justify-content-center mt-4">
        <div class="col-md-8">
            <div class="card border-info">
                <div class="card-header bg-info text-dark">
                    <h6 class="mb-0"><i class="fas fa-lightbulb"></i> Quick Tips</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-magic text-success"></i> Bulk Assignment</h6>
                            <ul class="small">
                                <li>Select multiple pages at once</li>
                                <li>Choose testers/QA for all selected pages</li>
                                <li>Assign to multiple environments simultaneously</li>
                                <li>Perfect for new projects or major updates</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-bolt text-warning"></i> Quick Assign All</h6>
                            <ul class="small">
                                <li>Assign same tester/QA to ALL pages</li>
                                <li>Select environments for all pages at once</li>
                                <li>All environments pre-selected by default</li>
                                <li>One-click assignment for entire project</li>
                                <li>Available in Individual assignment tab</li>
                                <li>Great for initial project setup</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>