<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$db = Database::getInstance();

// Get date filter
$dateFilter = $_GET['date'] ?? date('Y-m-d');

// Fetch user's generic tasks
$tasksStmt = $db->prepare("
    SELECT ugt.*, gtc.name as category_name, gtc.description as category_desc
    FROM user_generic_tasks ugt
    JOIN generic_task_categories gtc ON ugt.category_id = gtc.id
    WHERE ugt.user_id = ? AND ugt.task_date = ?
    ORDER BY ugt.created_at DESC
");
$tasksStmt->execute([$userId, $dateFilter]);

// Fetch total hours for the day
$totalHoursStmt = $db->prepare("
    SELECT COALESCE(SUM(hours_spent), 0) 
    FROM user_generic_tasks 
    WHERE user_id = ? AND task_date = ?
");
$totalHoursStmt->execute([$userId, $dateFilter]);
$totalHours = $totalHoursStmt->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Generic Tasks Log</h2>
        <div>
            <?php if (in_array($userRole, ['admin', 'project_lead'])): ?>
            <a href="manage_categories.php" class="btn btn-secondary me-2">
                <i class="fas fa-tags"></i> Manage Categories
            </a>
            <?php endif; ?>
            <a href="add_task.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Log Task
            </a>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Select Date</label>
                    <input type="date" name="date" class="form-control" value="<?php echo $dateFilter; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="index.php" class="btn btn-outline-secondary">Today</a>
                </div>
                <div class="col-md-6 text-end">
                    <h5>Total Hours: <span class="badge bg-info"><?php echo $totalHours; ?></span></h5>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h5>Tasks for <?php echo date('M d, Y', strtotime($dateFilter)); ?></h5>
        </div>
        <div class="card-body">
            <?php if ($tasksStmt->rowCount() > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Hours Spent</th>
                            <th>Time Logged</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($task = $tasksStmt->fetch()): ?>
                        <tr>
                            <td>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($task['category_name']); ?></span>
                            </td>
                            <td><?php echo nl2br(htmlspecialchars($task['task_description'])); ?></td>
                            <td><?php echo $task['hours_spent']; ?></td>
                            <td><?php echo date('H:i', strtotime($task['created_at'])); ?></td>
                            <td>
                                <!-- Edit/Delete could be added here -->
                                <button class="btn btn-sm btn-outline-danger" disabled title="Delete not implemented yet">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No generic tasks logged for this date.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 