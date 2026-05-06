<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['at_tester', 'admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Get test history with filters
$projectFilter = $_GET['project'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$whereConditions = ["tr.tester_id = ? AND tr.tester_role = 'at_tester'"];
$params = [$userId];

if ($projectFilter) {
    $whereConditions[] = "p.id = ?";
    $params[] = $projectFilter;
}

if ($statusFilter) {
    $whereConditions[] = "tr.status = ?";
    $params[] = $statusFilter;
}

if ($dateFrom) {
    $whereConditions[] = "DATE(tr.tested_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = "DATE(tr.tested_at) <= ?";
    $params[] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// Get test history
$historyQuery = "
    SELECT tr.*, pp.page_name, p.title as project_title, p.po_number,
           te.name as environment_name, te.browser, te.assistive_tech
    FROM testing_results tr
    JOIN project_pages pp ON tr.page_id = pp.id
    JOIN projects p ON pp.project_id = p.id
    JOIN testing_environments te ON tr.environment_id = te.id
    WHERE $whereClause
    ORDER BY tr.tested_at DESC
    LIMIT 100
";

$historyStmt = $db->prepare($historyQuery);
$historyStmt->execute($params);
$history = $historyStmt->fetchAll();

// Get projects for filter
$projectsQuery = "
    SELECT DISTINCT p.id, p.title, p.po_number
    FROM projects p
    JOIN project_pages pp ON p.id = pp.project_id
    JOIN page_environments pe ON pp.id = pe.page_id
    WHERE pe.at_tester_id = ?
    ORDER BY p.title
";
$projectsStmt = $db->prepare($projectsQuery);
$projectsStmt->execute([$userId]);
$projects = $projectsStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-history text-primary"></i> AT Testing History</h2>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Project</label>
                    <select name="project" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo $project['id']; ?>" <?php echo $projectFilter == $project['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="pass" <?php echo $statusFilter === 'pass' ? 'selected' : ''; ?>>Pass</option>
                        <option value="fail" <?php echo $statusFilter === 'fail' ? 'selected' : ''; ?>>Fail</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="test_history.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Test History -->
    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> Test Results (<?php echo count($history); ?> records)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($history)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-history fa-3x mb-3"></i>
                    <p>No test history found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Project</th>
                                <th>Page</th>
                                <th>Environment</th>
                                <th>Status</th>
                                <th>Issues</th>
                                <th>Hours</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $record): ?>
                            <tr>
                                <td>
                                    <?php echo date('M j, Y', strtotime($record['tested_at'])); ?><br>
                                    <small class="text-muted"><?php echo date('g:i A', strtotime($record['tested_at'])); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($record['project_title']); ?></strong><br>
                                    <small class="text-muted"><?php echo $record['po_number']; ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($record['page_name']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($record['environment_name']); ?></strong>
                                    <?php if ($record['browser']): ?>
                                        <br><small class="text-muted">Browser: <?php echo htmlspecialchars($record['browser']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($record['assistive_tech']): ?>
                                        <br><small class="text-muted">AT: <?php echo htmlspecialchars($record['assistive_tech']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $record['status'] === 'pass' ? 'success' : 'danger'; ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($record['issues_found'] > 0): ?>
                                        <span class="badge bg-warning"><?php echo $record['issues_found']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $record['hours_spent']; ?>h</td>
                                <td>
                                    <?php if ($record['comments']): ?>
                                        <button class="btn btn-sm btn-outline-info" 
                                                data-bs-toggle="tooltip" 
                                                title="<?php echo htmlspecialchars($record['comments']); ?>">
                                            <i class="fas fa-comment"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>