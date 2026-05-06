<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/hours_validation.php';

$baseDir = getBaseDir();

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead']); // Admin and Project Lead can manage hours

$db = Database::getInstance();

$userId = $_GET['user_id'] ?? null;
if (!$userId) {
    $_SESSION['error'] = "User ID is required.";
    header("Location: " . $baseDir . "/modules/admin/resource_workload.php");
    exit;
}

// Get user details
$userQuery = "SELECT id, full_name, role, email FROM users WHERE id = ? AND is_active = 1";
$stmt = $db->prepare($userQuery);
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header("Location: " . $baseDir . "/modules/admin/resource_workload.php");
    exit;
}

// Handle form submissions
if ($_POST) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?user_id=' . $userId);
        exit;
    }
    if (isset($_POST['update_hours'])) {
        $assignmentId = $_POST['assignment_id'];
        $newHours = $_POST['new_hours'];
        $reason = $_POST['reason'] ?? '';
        
        try {
            // Get current assignment details
            $assignmentQuery = "SELECT ua.*, p.title as project_title, p.project_lead_id FROM user_assignments ua JOIN projects p ON ua.project_id = p.id WHERE ua.id = ?";
            $stmt = $db->prepare($assignmentQuery);
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch();
            
            if (!$assignment) {
                throw new Exception("Assignment not found.");
            }

            // IDOR check: project lead can only update their own projects
            if ($_SESSION['role'] === 'project_lead' && (int)$assignment['project_lead_id'] !== (int)$_SESSION['user_id']) {
                throw new Exception("Unauthorized access to this project.");
            }
            
            // Validate hours allocation
            $validation = validateHoursAllocation($db, $assignment['project_id'], $newHours, $assignmentId);
            
            if (!$validation['valid']) {
                throw new Exception($validation['message']);
            }
            
            // Update the assignment
            $updateQuery = "UPDATE user_assignments SET hours_allocated = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $db->prepare($updateQuery);
            $stmt->execute([$newHours, $assignmentId]);
            
            // Log the change
            logHoursActivity($db, $_SESSION['user_id'], 'hours_updated', $assignmentId, [
                'target_user_id' => $userId,
                'target_user_name' => $user['full_name'],
                'project_id' => $assignment['project_id'],
                'project_title' => $assignment['project_title'],
                'old_hours' => $assignment['hours_allocated'],
                'new_hours' => $newHours,
                'reason' => $reason,
                'updated_by' => $_SESSION['user_id']
            ]);
            
            $_SESSION['success'] = "Hours updated successfully for " . $user['full_name'];
        } catch (Exception $e) {
            $_SESSION['error'] = "Error updating hours: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_assignment'])) {
        $projectId = $_POST['project_id'];
        $role = $_POST['role'];
        $hours = $_POST['hours'];
        $reason = $_POST['reason'] ?? '';
        
        try {
            // IDOR check: project lead can only add to their own projects
            if ($_SESSION['role'] === 'project_lead') {
                $checkStmt = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ?");
                $checkStmt->execute([$projectId]);
                $projectLeadId = $checkStmt->fetchColumn();
                if ((int)$projectLeadId !== (int)$_SESSION['user_id']) {
                    throw new Exception("Unauthorized access to this project.");
                }
            }

            // Validate hours allocation
            $validation = validateHoursAllocation($db, $projectId, $hours);
            
            if (!$validation['valid']) {
                throw new Exception($validation['message']);
            }
            
            // Add new assignment
            $insertQuery = "INSERT INTO user_assignments (project_id, user_id, role, assigned_by, hours_allocated, assigned_at) VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt = $db->prepare($insertQuery);
            $stmt->execute([$projectId, $userId, $role, $_SESSION['user_id'], $hours]);
            
            $assignmentId = $db->lastInsertId();
            
            // Get project title for logging
            $projectStmt = $db->prepare("SELECT title FROM projects WHERE id = ?");
            $projectStmt->execute([$projectId]);
            $project = $projectStmt->fetch();
            
            // Log the change
            logHoursActivity($db, $_SESSION['user_id'], 'assignment_added', $assignmentId, [
                'target_user_id' => $userId,
                'target_user_name' => $user['full_name'],
                'project_id' => $projectId,
                'project_title' => $project['title'] ?? 'Unknown',
                'role' => $role,
                'hours' => $hours,
                'reason' => $reason,
                'assigned_by' => $_SESSION['user_id']
            ]);
            
            $_SESSION['success'] = "New assignment added successfully for " . $user['full_name'];
        } catch (Exception $e) {
            $_SESSION['error'] = "Error adding assignment: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['remove_assignment'])) {
        $assignmentId = $_POST['assignment_id'];
        $reason = $_POST['reason'] ?? '';
        
        try {
            // Get assignment details before deletion
            $getQuery = "SELECT ua.*, p.title as project_title, p.project_lead_id FROM user_assignments ua JOIN projects p ON ua.project_id = p.id WHERE ua.id = ?";
            $stmt = $db->prepare($getQuery);
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch();

            if (!$assignment) {
                throw new Exception("Assignment not found.");
            }

            // IDOR check: project lead can only remove from their own projects
            if ($_SESSION['role'] === 'project_lead' && (int)$assignment['project_lead_id'] !== (int)$_SESSION['user_id']) {
                throw new Exception("Unauthorized access to this project.");
            }
            
            // Remove assignment
            $deleteQuery = "DELETE FROM user_assignments WHERE id = ?";
            $stmt = $db->prepare($deleteQuery);
            $stmt->execute([$assignmentId]);
            
            // Log the change
            $logQuery = "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at) VALUES (?, 'assignment_removed', 'user_assignment', ?, ?, NOW())";
            $logDetails = json_encode([
                'target_user_id' => $userId,
                'target_user_name' => $user['full_name'],
                'project_id' => $assignment['project_id'],
                'project_title' => $assignment['project_title'],
                'role' => $assignment['role'],
                'hours' => $assignment['hours_allocated'],
                'reason' => $reason,
                'removed_by' => $_SESSION['user_id']
            ]);
            $stmt = $db->prepare($logQuery);
            $stmt->execute([$_SESSION['user_id'], $assignmentId, $logDetails]);
            
            $_SESSION['success'] = "Assignment removed successfully for " . $user['full_name'];
        } catch (Exception $e) {
            $_SESSION['error'] = "Error removing assignment: " . $e->getMessage();
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Check if is_utilized column exists in project_time_logs
$hasIsUtilized = false;
try {
    $colCheck = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'is_utilized'");
    $hasIsUtilized = $colCheck && $colCheck->rowCount() > 0;
} catch (Exception $e) { $hasIsUtilized = false; }

$utilizedFilter = $hasIsUtilized ? "WHERE is_utilized = 1" : "";

// Get current assignments
$pLeadAnd = ($_SESSION['role'] === 'project_lead') ? " AND p.project_lead_id = " . (int)$_SESSION['user_id'] : "";
$assignmentsQuery = "
    SELECT ua.*, p.title as project_title, p.po_number, p.status as project_status,
           COALESCE(ptl.utilized_hours, 0) as utilized_hours
    FROM user_assignments ua
    JOIN projects p ON ua.project_id = p.id
    LEFT JOIN (
        SELECT user_id, project_id, SUM(hours_spent) as utilized_hours
        FROM project_time_logs
        $utilizedFilter
        GROUP BY user_id, project_id
    ) ptl ON ua.user_id = ptl.user_id AND ua.project_id = ptl.project_id
    WHERE ua.user_id = ? $pLeadAnd
    ORDER BY p.status, p.title
";
$stmt = $db->prepare($assignmentsQuery);
$stmt->execute([$userId]);
$assignments = $stmt->fetchAll();

// Get available projects for new assignments with hours info
$projectsQuery = "
    SELECT p.id, p.title, p.po_number, p.status, p.total_hours,
           COALESCE(SUM(ua.hours_allocated), 0) as allocated_hours,
           (p.total_hours - COALESCE(SUM(ua.hours_allocated), 0)) as available_hours
    FROM projects p
    LEFT JOIN user_assignments ua ON p.id = ua.project_id
    WHERE p.status NOT IN ('completed', 'cancelled') $pLeadAnd
    GROUP BY p.id, p.title, p.po_number, p.status, p.total_hours
    HAVING p.total_hours > 0
    ORDER BY p.title
";
$projects = $db->query($projectsQuery)->fetchAll();

// Get recent activity logs for this user
$logsQuery = "
    SELECT al.*, u.full_name as updated_by_name
    FROM activity_log al
    JOIN users u ON al.user_id = u.id
    WHERE al.entity_type IN ('user_assignment') 
    AND JSON_EXTRACT(al.details, '$.target_user_id') = ?
    ORDER BY al.created_at DESC
    LIMIT 10
";
$stmt = $db->prepare($logsQuery);
$stmt->execute([$userId]);
$activityLogs = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<script src="<?php echo $baseDir; ?>/assets/js/hours-validation.js"></script>
<script>
window._manageHoursConfig = { baseDir: "<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>" };
</script>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Manage Hours - <?php echo htmlspecialchars($user['full_name']); ?></h2>
        <div>
            <span class="badge bg-<?php 
                echo match($user['role']) {
                    'project_lead' => 'primary',
                    'qa' => 'success',
                    'at_tester' => 'info',
                    'ft_tester' => 'warning',
                    default => 'secondary'
                };
            ?> me-2">
                <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
            </span>
            <a href="<?php echo $baseDir; ?>/modules/admin/resource_workload.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to Resources
            </a>
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

    <div class="row">
        <!-- Current Assignments -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>Current Assignments</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAssignmentModal">
                        <i class="fas fa-plus"></i> Add Assignment
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($assignments)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-inbox fa-3x mb-3"></i>
                            <p>No assignments found for this user.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Role</th>
                                        <th>Allocated Hours</th>
                                        <th>Utilized Hours</th>
                                        <th>Remaining</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($assignment['project_title']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($assignment['po_number']); ?></small>
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
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" 
                                                        onclick="editHours(<?php echo $assignment['id']; ?>, <?php echo $assignment['hours_allocated']; ?>, '<?php echo htmlspecialchars($assignment['project_title']); ?>')">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-outline-danger" 
                                                        onclick="removeAssignment(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars($assignment['project_title']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
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

        <!-- Summary & Activity -->
        <div class="col-md-4">
            <!-- Summary -->
            <div class="card mb-3">
                <div class="card-header">
                    <h5>Hours Summary</h5>
                </div>
                <div class="card-body">
                    <?php
                    $totalAllocated = array_sum(array_column($assignments, 'hours_allocated'));
                    $totalUtilized = array_sum(array_column($assignments, 'utilized_hours'));
                    $totalRemaining = $totalAllocated - $totalUtilized;
                    $utilizationRate = $totalAllocated > 0 ? ($totalUtilized / $totalAllocated) * 100 : 0;
                    ?>
                    <div class="row text-center">
                        <div class="col-6">
                            <h4 class="text-primary"><?php echo number_format($totalAllocated, 1); ?>h</h4>
                            <small class="text-muted">Total Allocated</small>
                        </div>
                        <div class="col-6">
                            <h4 class="text-success"><?php echo number_format($totalUtilized, 1); ?>h</h4>
                            <small class="text-muted">Total Utilized</small>
                        </div>
                        <div class="col-6 mt-3">
                            <h4 class="text-warning"><?php echo number_format($totalRemaining, 1); ?>h</h4>
                            <small class="text-muted">Remaining</small>
                        </div>
                        <div class="col-6 mt-3">
                            <h4 class="text-info"><?php echo number_format($utilizationRate, 1); ?>%</h4>
                            <small class="text-muted">Utilization</small>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="progress">
                            <div class="progress-bar bg-success" style="width: <?php echo min(100, $utilizationRate); ?>%"></div>
                        </div>
                        <small class="text-muted">Utilization Rate</small>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <h5>Recent Activity</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($activityLogs)): ?>
                        <p class="text-muted">No recent activity.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($activityLogs as $log): ?>
                            <div class="timeline-item mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-<?php 
                                            echo match($log['action']) {
                                                'hours_updated' => 'edit text-primary',
                                                'assignment_added' => 'plus text-success',
                                                'assignment_removed' => 'trash text-danger',
                                                default => 'info-circle text-info'
                                            };
                                        ?>"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-2">
                                        <div class="small">
                                            <strong><?php echo htmlspecialchars($log['updated_by_name']); ?></strong>
                                            <?php
                                            $details = json_decode($log['details'], true);
                                            echo match($log['action']) {
                                                'hours_updated' => 'updated hours allocation',
                                                'assignment_added' => 'added new assignment',
                                                'assignment_removed' => 'removed assignment',
                                                default => 'performed action'
                                            };
                                            ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                        </div>
                                        <?php if (!empty($details['reason'])): ?>
                                            <div class="small text-info">
                                                <i class="fas fa-comment"></i> <?php echo htmlspecialchars($details['reason']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Assignment Modal -->
<div class="modal fade" id="addAssignmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Project</label>
                        <select name="project_id" id="project_id" class="form-select" required onchange="updateAvailableHours(this)">
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>">
                                    <?php echo htmlspecialchars($project['title'] . ' (' . $project['po_number'] . ')'); ?>
                                    - <?php echo number_format($project['available_hours'], 1); ?>h available
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="project-hours-info" class="mt-2" style="display: none;">
                            <small class="text-muted">
                                <strong>Project Hours:</strong> 
                                <span id="total-hours">0</span>h total, 
                                <span id="allocated-hours">0</span>h allocated, 
                                <span id="available-hours" class="text-success">0</span>h available
                            </small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="">Select Role</option>
                            <option value="project_lead">Project Lead</option>
                            <option value="qa">QA</option>
                            <option value="at_tester">AT Tester</option>
                            <option value="ft_tester">FT Tester</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Hours to Allocate</label>
                        <input type="number" name="hours" id="hours-input" class="form-control" step="0.01" min="0" max="0" required>
                        <div class="form-text">
                            <span id="hours-validation" class="text-muted">Select a project first</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason (Optional)</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="Reason for this assignment..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_assignment" class="btn btn-primary">Add Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Hours Modal -->
<div class="modal fade" id="editHoursModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Hours Allocation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="assignment_id" id="edit_assignment_id">
                    <div class="mb-3">
                        <label class="form-label">Project</label>
                        <input type="text" id="edit_project_title" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Hours</label>
                        <input type="text" id="edit_current_hours" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Hours</label>
                        <input type="number" name="new_hours" id="edit_new_hours" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason for Change</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="Reason for changing hours allocation..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_hours" class="btn btn-primary">Update Hours</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove Assignment Modal -->
<div class="modal fade" id="removeAssignmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Remove Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="assignment_id" id="remove_assignment_id">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Are you sure you want to remove the assignment for <strong id="remove_project_title"></strong>?
                        This action cannot be undone.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason for Removal</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="Reason for removing this assignment..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="remove_assignment" class="btn btn-danger">Remove Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/manage-hours.js"></script>

<style>
.timeline-item {
    border-left: 2px solid #e9ecef;
    padding-left: 1rem;
    position: relative;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -5px;
    top: 0;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background-color: #6c757d;
}

.card {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>