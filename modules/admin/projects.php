<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');

$projectManager = new ProjectManager();
$db = Database::getInstance();

// Preload project leads for selection
$projectLeads = $db->query("SELECT id, full_name FROM users WHERE role IN ('project_lead','admin','admin') ORDER BY full_name")->fetchAll();

// Handle project creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: projects.php');
        exit;
    }
    $projectMode = $_POST['project_mode'] ?? 'standalone';
    $hasSubprojects = $projectMode === 'parent';
    $projectData = [
        'po_number' => sanitizeInput($_POST['po_number'] ?? ''),
        'title' => sanitizeInput($_POST['title']),
        'description' => $hasSubprojects ? null : sanitizeInput($_POST['description']),
        'project_type' => $hasSubprojects ? null : sanitizeInput($_POST['project_type']),
        'client_id' => sanitizeInput($_POST['client_id']),
        'priority' => $hasSubprojects ? null : sanitizeInput($_POST['priority']),
        'created_by' => $_SESSION['user_id'],
        'parent_project_id' => isset($_POST['parent_project_id']) && $_POST['parent_project_id'] !== '' ? intval($_POST['parent_project_id']) : null,
        'project_lead_id' => $hasSubprojects ? null : (isset($_POST['project_lead_id']) && $_POST['project_lead_id'] !== '' ? intval($_POST['project_lead_id']) : null),
        'total_hours' => $hasSubprojects ? null : (isset($_POST['total_hours']) && $_POST['total_hours'] !== '' ? floatval($_POST['total_hours']) : null)
    ];

    $childTitles = $hasSubprojects ? ($_POST['child_title'] ?? []) : [];
    $childTypes = $hasSubprojects ? ($_POST['child_type'] ?? []) : [];
    $childPriorities = $hasSubprojects ? ($_POST['child_priority'] ?? []) : [];
    $childLeads = $hasSubprojects ? ($_POST['child_lead_id'] ?? []) : [];
    $childHours = $hasSubprojects ? ($_POST['child_total_hours'] ?? []) : [];
    
    if ($projectManager->createProject($projectData)) {
        $projectId = $db->lastInsertId();

        // Track projects that should behave as actual projects (children only unless no subprojects)
        $childProjectIds = [];

        // Create optional sub-projects inheriting parent meta
        if ($hasSubprojects && !empty($childTitles)) {
            foreach ($childTitles as $idx => $subTitle) {
                $titleClean = sanitizeInput($subTitle);
                if (!$titleClean) continue;
                $childData = [
                    'po_number' => '',
                    'title' => $titleClean,
                    'description' => null,
                    'project_type' => sanitizeInput($childTypes[$idx] ?? 'web'),
                    'client_id' => $projectData['client_id'],
                    'priority' => sanitizeInput($childPriorities[$idx] ?? 'medium'),
                    'created_by' => $_SESSION['user_id'],
                    'parent_project_id' => $projectId,
                    'project_lead_id' => isset($childLeads[$idx]) && $childLeads[$idx] !== '' ? intval($childLeads[$idx]) : null,
                    'total_hours' => isset($childHours[$idx]) && $childHours[$idx] !== '' ? floatval($childHours[$idx]) : null
                ];
                if ($projectManager->createProject($childData)) {
                    $childProjectIds[] = $db->lastInsertId();
                }
            }
        }

        // If this project itself is a child (parent provided) or no subprojects were created, treat it as an actual project
        if ($projectData['parent_project_id'] || (!$hasSubprojects)) {
            $childProjectIds[] = $projectId;
        }

        // Add phases only to child/actual projects, not the container-only parent when subprojects exist
        if (!empty($childProjectIds)) {
            $phases = ['po_received', 'scoping_confirmation', 'testing', 'regression'];
            foreach ($childProjectIds as $cpId) {
                foreach ($phases as $phase) {
                    $stmt = $db->prepare("
                        INSERT INTO project_phases (project_id, phase_name)
                        VALUES (?, ?)
                    ");
                    $stmt->execute([$cpId, $phase]);
                }
            }
        }

        $_SESSION['success'] = "Project created successfully!";
        redirect("/modules/projects/view.php?id=$projectId");
    }
}
?>
<style>
#projectsTable_wrapper .dataTables_length select {
    min-width: 100px;
    padding-right: 2.5rem !important;
    background-position: right 0.8rem center;
    text-overflow: clip;
}
.expand-btn { cursor: pointer; transition: transform 0.2s; display: inline-block; }
.expand-btn.expanded { transform: rotate(90deg); }
.sub-projects-wrapper { padding: 10px 15px; background: #f8f9fa; border-radius: 4px; }
</style>
<?php
include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid">
    <h2>Projects Management</h2>
    
    <!-- Create Project Modal -->
    <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#createProjectModal">
        <i class="fas fa-plus"></i> Create New Project
    </button>
    
    <!-- Filters Section -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select id="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="planning">Planning</option>
                        <option value="in_progress">In Progress</option>
                        <option value="on_hold">On Hold</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Project Type</label>
                    <select id="typeFilter" class="form-select">
                        <option value="">All Types</option>
                        <option value="website">Website</option>
                        <option value="mobile_app">Mobile App</option>
                        <option value="web_app">Web App</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Priority</label>
                    <select id="priorityFilter" class="form-select">
                        <option value="">All Priorities</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Client</label>
                    <select id="clientFilter" class="form-select">
                        <option value="">All Clients</option>
                        <?php
                        $filterClients = $db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
                        foreach ($filterClients as $fc) {
                            echo '<option value="' . $fc['id'] . '">' . htmlspecialchars($fc['name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" id="startDate" class="form-control" value="<?php echo date('Y-m-d', strtotime('-1 month')); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" id="endDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Search</label>
                    <input type="text" id="searchProject" class="form-control" placeholder="Search projects...">
                </div>
            </div>
        </div>

    </div>
    
    <!-- Projects Table -->
    <div class="card">
        <div class="card-body">
            <table id="projectsTable" class="table table-striped">
                <thead>
                    <tr>
                        <th style="width:40px;"></th>
                        <th>Project Code</th>
                        <th>Title</th>
                        <th>Client</th>
                        <th>Type</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Actions</th>

                    </tr>
                </thead>
                <tbody>
                    <?php
                    $projects = $db->query("
                        SELECT p.*, c.name as client_name
                        FROM projects p
                        LEFT JOIN clients c ON p.client_id = c.id
                        ORDER BY p.created_at DESC
                    ")->fetchAll(PDO::FETCH_ASSOC);
                    $parents = [];
                    $children = [];
                    foreach ($projects as $p) {
                        if ($p['parent_project_id']) {
                            $children[$p['parent_project_id']][] = $p;
                        } else {
                            $parents[] = $p;
                        }
                    }

                    foreach ($parents as $project):
                        $subs = $children[$project['id']] ?? [];
                    ?>
                    <tr id="project-row-<?php echo $project['id']; ?>" 
                        data-status="<?php echo htmlspecialchars($project['status']); ?>" 
                        data-type="<?php echo htmlspecialchars($project['project_type'] ?? ''); ?>"
                        data-priority="<?php echo htmlspecialchars($project['priority'] ?? ''); ?>"
                        data-client-id="<?php echo htmlspecialchars($project['client_id']); ?>"
                        data-created-at="<?php echo date('Y-m-d', strtotime($project['created_at'])); ?>"
                        data-subprojects='<?php echo !empty($subs) ? json_encode($subs, JSON_HEX_APOS | JSON_HEX_QUOT) : ""; ?>'>

                        <td>
                            <?php if (!empty($subs)): ?>
                            <i class="fas fa-chevron-right expand-btn text-primary" onclick="toggleSubprojects(<?php echo $project['id']; ?>, this)"></i>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($project['project_code'] ?: $project['po_number']); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                            <?php if (!empty($subs)): ?>
                                <span class="badge bg-secondary ms-1"><?php echo count($subs); ?> sub</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($project['client_name']); ?></td>
                        <td>
                            <span class="badge bg-info">
                                <?php echo ucfirst($project['project_type'] ?: 'N/A'); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?php 
                                echo $project['priority'] === 'critical' ? 'danger' : 
                                     ($project['priority'] === 'high' ? 'warning' : 'secondary');
                            ?>">
                                <?php echo ucfirst($project['priority']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?php 
                                echo $project['status'] === 'completed' ? 'success' : 
                                     ($project['status'] === 'in_progress' ? 'primary' : 'secondary');
                            ?>">
                                <?php echo formatProjectStatusLabel($project['status']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-muted small">
                                <?php echo date('d-M-Y', strtotime($project['created_at'])); ?>
                            </span>
                        </td>
                        <td>

                            <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
                            <a href="<?php echo $baseDir; ?>/modules/projects/edit.php?id=<?php echo $project['id']; ?>" class="btn btn-sm btn-outline-warning" title="Edit"><i class="fas fa-edit"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Project Modal -->
<div class="modal fade" id="createProjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Project Code (optional)</label>
                            <input type="text" name="po_number" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Parent Project (optional)</label>
                            <select name="parent_project_id" class="form-select">
                                <option value="">None</option>
                                <?php
                                $allProjects = $db->query("SELECT id, title, po_number FROM projects ORDER BY created_at DESC")->fetchAll();
                                foreach ($allProjects as $ap) {
                                ?>
                                <option value="<?php echo $ap['id']; ?>"><?php echo htmlspecialchars($ap['title'] . ' (' . $ap['po_number'] . ')'); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label d-block">Project Mode</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="project_mode" id="modeStandalone" value="standalone" checked>
                                <label class="form-check-label" for="modeStandalone">Standalone</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="project_mode" id="modeParent" value="parent">
                                <label class="form-check-label" for="modeParent">Parent with sub-projects</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Project Title *</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3 single-project-fields">
                            <label>Project Type *</label>
                            <select name="project_type" class="form-select" required>
                                <option value="web">Web Project</option>
                                <option value="app">App Project</option>
                                <option value="pdf">PDF Remediation</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Client *</label>
                            <select name="client_id" class="form-select" required>
                                <?php
                                $clients = $db->query("SELECT * FROM clients ORDER BY name");
                                while ($client = $clients->fetch()):
                                ?>
                                <option value="<?php echo $client['id']; ?>">
                                    <?php echo $client['name']; ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Priority</label>
                            <select name="priority" class="form-select single-project-fields">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3 single-project-fields">
                            <label>Project Lead</label>
                            <select name="project_lead_id" class="form-select">
                                <option value="">Select Project Lead</option>
                                <?php foreach ($projectLeads as $lead): ?>
                                    <option value="<?php echo $lead['id']; ?>"><?php echo htmlspecialchars($lead['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3 single-project-fields">
                            <label>Total Hours (optional)</label>
                            <input type="number" name="total_hours" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-12 mb-3 single-project-fields">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="col-12 mb-3 d-none" id="subprojectsContainer">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Sub-Projects</h6>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="addSubprojectBtn">
                                    <i class="fas fa-plus"></i> Add Sub-Project
                                </button>
                            </div>
                            <div id="subprojectList" class="d-grid gap-3"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="create_project" class="btn btn-primary">Create Project</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window.AdminProjectsConfig = {
    projectLeads: <?php echo json_encode(array_map(function($l){ return ['id'=>(int)$l['id'],'full_name'=>$l['full_name']]; }, $projectLeads), JSON_HEX_TAG | JSON_HEX_AMP); ?>
};
</script>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/admin-projects.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; 