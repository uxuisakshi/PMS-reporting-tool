<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/client_permissions.php';

$auth = new Auth();
$auth->requireLogin();

$base_url = getBaseDir();

// Get project ID
$projectId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$projectId) {
    header("Location: " . $base_url . "/modules/admin/projects.php");
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Check permission
if (!canEditProjectById($db, $userId, $projectId)) {
    $_SESSION['error'] = "You do not have permission to edit this project.";
    header("Location: " . $base_url . "/modules/projects/view.php?id=" . $projectId);
    exit;
}

// Get project details
try {
    $stmt = $db->prepare("
        SELECT p.*, c.name as client_name 
        FROM projects p 
        LEFT JOIN clients c ON p.client_id = c.id 
        WHERE p.id = ?
    ");
    
    if (!$stmt->execute([$projectId])) {
        throw new Exception("Failed to execute query");
    }
    
    $project = $stmt->fetch();
    
    if (!$project) {
        $_SESSION['error'] = "Project not found.";
        header("Location: " . $base_url . "/modules/admin/projects.php");
        exit;
    }
    
} catch (Exception $e) {
    die("Error loading project: " . $e->getMessage());
}

$duplicateCodeDefault = 'COPY-' . ($project['po_number'] ?? ('PRJ-' . $projectId)) . '-' . date('Y-m-d');

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ' . $base_url . '/modules/projects/edit.php?id=' . $projectId);
        exit;
    }
    if (isset($_POST['update_project'])) {
        // Sanitize inputs
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $projectType = isset($_POST['project_type']) ? $_POST['project_type'] : 'web';
        $clientId = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        $priority = isset($_POST['priority']) ? $_POST['priority'] : 'medium';
        $status = isset($_POST['status']) ? $_POST['status'] : 'not_started';
        $totalHours = isset($_POST['total_hours']) ? floatval($_POST['total_hours']) : 0;
        $projectLeadId = isset($_POST['project_lead_id']) && $_POST['project_lead_id'] !== '' ? intval($_POST['project_lead_id']) : null;
        // Ensure project_lead_id is NULL if it's 0 (invalid user ID)
        if ($projectLeadId === 0) {
            $projectLeadId = null;
        }
        
        // Validate required fields
        if (empty($title) || empty($projectType) || $clientId <= 0) {
            $_SESSION['error'] = "Please fill in all required fields.";
        } else {
            try {
                $allocatedStmt = $db->prepare("
                    SELECT COALESCE(SUM(hours_allocated), 0)
                    FROM user_assignments
                    WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0)
                ");
                $allocatedStmt->execute([$projectId]);
                $currentlyAllocated = (float)$allocatedStmt->fetchColumn();
                if ($totalHours > 0 && $currentlyAllocated > $totalHours) {
                    $_SESSION['error'] = "Project total hours cannot be less than currently allocated hours (" . number_format($currentlyAllocated, 2) . "h).";
                    header("Location: " . $base_url . "/modules/projects/edit.php?id=" . $projectId);
                    exit;
                }

                // Check current status to see if we need to set completed_at
                $currentStatusStmt = $db->prepare("SELECT status FROM projects WHERE id = ?");
                $currentStatusStmt->execute([$projectId]);
                $currentProject = $currentStatusStmt->fetch();
                
                $setCompletedAt = ($status === 'completed' && $currentProject['status'] !== 'completed');
                
                $stmt = $db->prepare("
                    UPDATE projects 
                    SET title = ?, description = ?, project_type = ?, client_id = ?, 
                        priority = ?, status = ?, total_hours = ?, project_lead_id = ?" . 
                        ($setCompletedAt ? ", completed_at = NOW()" : "") . "
                    WHERE id = ?
                ");
                
                $success = $stmt->execute([
                    $title, $description, $projectType, $clientId, 
                    $priority, $status, $totalHours, $projectLeadId, $projectId
                ]);
                
                if ($success) {
                    $_SESSION['success'] = "Project updated successfully!";
                    header("Location: " . $base_url . "/modules/projects/view.php?id=" . $projectId);
                    exit;
                } else {
                    $_SESSION['error'] = "Failed to update project.";
                }
                
            } catch (Exception $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Get clients for dropdown
$clients = [];
try {
    $stmt = $db->query("SELECT * FROM clients ORDER BY name");
    $clients = $stmt->fetchAll();
} catch (Exception $e) {
    $_SESSION['error'] = "Failed to load clients: " . $e->getMessage();
}

// Get project leads for dropdown
$projectLeads = [];
try {
    $stmt = $db->query("SELECT * FROM users WHERE role = 'project_lead' AND is_active = 1 ORDER BY full_name");
    $projectLeads = $stmt->fetchAll();
} catch (Exception $e) {
    $_SESSION['error'] = "Failed to load project leads: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Project - PMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .required::after {
            content: " *";
            color: #dc3545;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
        <?php include __DIR__ . '/../../includes/header.php'; ?>
    
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo $base_url; ?>/">
                <i class="fas fa-tasks"></i> PMS
            </a>
            <div class="navbar-nav">
                <a class="nav-link" href="<?php echo $base_url; ?>/modules/admin/projects.php">
                    <i class="fas fa-arrow-left"></i> Back to Projects
                </a>
                <a class="nav-link" href="<?php echo $base_url; ?>/modules/auth/logout.php?csrf_token=<?php echo urlencode(generateCsrfToken()); ?>">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-edit"></i> Edit Project: <?php echo htmlspecialchars($project['title']); ?>
                        </h4>
                    </div>
                    
                    <div class="card-body">
                        <!-- Error/Success Messages -->
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                            <?php unset($_SESSION['error']); ?>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                            <?php unset($_SESSION['success']); ?>
                        <?php endif; ?>
                        
                        <!-- Edit Form -->
                        <form method="POST" id="editProjectForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                            <div class="row">
                                <!-- Read-only fields -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Project Code</label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo htmlspecialchars($project['po_number']); ?>" 
                                           readonly disabled>
                                    <small class="text-muted">Project Code cannot be changed</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="title" class="form-label required">Project Title</label>
                                    <input type="text" id="title" name="title" class="form-control" 
                                           value="<?php echo htmlspecialchars($project['title']); ?>" 
                                           required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="project_type" class="form-label required">Project Type</label>
                                    <select id="project_type" name="project_type" class="form-select" required>
                                        <option value="web" <?php echo $project['project_type'] === 'web' ? 'selected' : ''; ?>>Web Project</option>
                                        <option value="app" <?php echo $project['project_type'] === 'app' ? 'selected' : ''; ?>>App Project</option>
                                        <option value="pdf" <?php echo $project['project_type'] === 'pdf' ? 'selected' : ''; ?>>PDF Remediation</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="client_id" class="form-label required">Client</label>
                                    <select id="client_id" name="client_id" class="form-select" required>
                                        <option value="">Select Client</option>
                                        <?php foreach ($clients as $client): ?>
                                            <option value="<?php echo $client['id']; ?>" 
                                                <?php echo $project['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($client['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="project_lead_id" class="form-label">Project Lead</label>
                                    <select id="project_lead_id" name="project_lead_id" class="form-select">
                                        <option value="">Select Project Lead</option>
                                        <?php foreach ($projectLeads as $lead): ?>
                                            <option value="<?php echo $lead['id']; ?>" 
                                                <?php echo $project['project_lead_id'] == $lead['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($lead['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="priority" class="form-label">Priority</label>
                                    <select id="priority" name="priority" class="form-select">
                                        <option value="low" <?php echo $project['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="medium" <?php echo $project['priority'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="high" <?php echo $project['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="critical" <?php echo $project['priority'] === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select id="status" name="status" class="form-select">
                                        <?php 
                                        $projectStatuses = getStatusOptions('project');
                                        foreach ($projectStatuses as $status): 
                                        ?>
                                            <option value="<?php echo $status['status_key']; ?>" 
                                                <?php echo $project['status'] === $status['status_key'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($status['status_label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="total_hours" class="form-label">Total Hours</label>
                                    <input type="number" id="total_hours" name="total_hours" 
                                           class="form-control" step="0.01" min="0"
                                           value="<?php echo htmlspecialchars($project['total_hours'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea id="description" name="description" class="form-control" rows="4"><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <button type="submit" name="update_project" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Project
                                </button>
                                <a href="<?php echo $base_url; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>" 
                                   class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <a href="<?php echo $base_url; ?>/modules/admin/projects.php" 
                                   class="btn btn-outline-secondary">
                                    <i class="fas fa-list"></i> Back to Projects List
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Danger Zone -->
                <?php if (hasAdminPrivileges()): ?>
                <div class="card mt-4 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-triangle"></i> Danger Zone
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-danger">
                            <i class="fas fa-warning"></i>
                            These actions are irreversible. Use with extreme caution.
                        </p>
                        
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <button type="button" class="btn btn-outline-danger w-100" 
                                        data-bs-toggle="modal" data-bs-target="#deleteModal">
                                    <i class="fas fa-trash"></i> Delete Project
                                </button>
                            </div>
                            <div class="col-md-4 mb-2">
                                <button type="button" class="btn btn-outline-warning w-100"
                                        data-bs-toggle="modal" data-bs-target="#archiveModal">
                                    <i class="fas fa-archive"></i> Archive Project
                                </button>
                            </div>
                            <div class="col-md-4 mb-2">
                                <button type="button" class="btn btn-outline-info w-100"
                                        data-bs-toggle="modal" data-bs-target="#duplicateModal">
                                    <i class="fas fa-copy"></i> Duplicate Project
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="<?php echo $base_url; ?>/modules/projects/delete.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                    <input type="hidden" name="delete_project" value="1">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-exclamation-triangle"></i> Delete Project
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <strong>Warning:</strong> This action cannot be undone!
                        </div>
                        <p>Are you sure you want to delete the project 
                           <strong>"<?php echo htmlspecialchars($project['title']); ?>"</strong>?</p>
                        <p>This will permanently delete:</p>
                        <ul>
                            <li>All project pages and assignments</li>
                            <li>All testing and QA results</li>
                            <li>All chat messages related to this project</li>
                            <li>All project assets and files</li>
                        </ul>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="confirmDelete">
                            <label class="form-check-label" for="confirmDelete">
                                I understand this action is permanent and cannot be undone
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Project
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Archive Modal -->
    <div class="modal fade" id="archiveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="<?php echo $base_url; ?>/modules/projects/archive.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                    <input type="hidden" name="archive_project" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title">Archive Project</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Archiving will mark the project as completed and remove it from active project lists.</p>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="archiveConfirm">
                            <label class="form-check-label" for="archiveConfirm">
                                Confirm archiving project
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-archive"></i> Archive Project
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Duplicate Modal -->
    <div class="modal fade" id="duplicateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="<?php echo $base_url; ?>/modules/projects/duplicate.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                    <input type="hidden" name="duplicate_project" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title">Duplicate Project</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="new_po_number" class="form-label required">New Project Code</label>
                            <input type="text" id="new_po_number" name="new_po_number" class="form-control" value="<?php echo htmlspecialchars($duplicateCodeDefault, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_title" class="form-label required">New Project Title</label>
                            <input type="text" id="new_title" name="new_title" class="form-control" 
                                   value="Copy of <?php echo htmlspecialchars($project['title']); ?>" required>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="copy_pages" id="copyPages" checked>
                            <label class="form-check-label" for="copyPages">
                                Copy all pages and structure
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="copy_team" id="copyTeam">
                            <label class="form-check-label" for="copyTeam">
                                Copy team assignments
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-copy"></i> Duplicate Project
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // Form validation
        $('#editProjectForm').on('submit', function(e) {
            let valid = true;
            
            // Check required fields
            $('.required').each(function() {
                const field = $(this).find('input, select, textarea').first();
                if (field.length && !field.val().trim()) {
                    field.addClass('is-invalid');
                    valid = false;
                } else {
                    field.removeClass('is-invalid');
                }
            });
            
            if (!valid) {
                e.preventDefault();
                showToast('Please fill in all required fields.', 'warning');
            }
        });
        
        // Clear delete confirmation checkbox
        $('#deleteModal').on('show.bs.modal', function() {
            $('#confirmDelete').prop('checked', false);
        });
        
        // Keep duplicate code input prefilled from server-side default.
    });
    </script>
</body>
</html>
