<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead']);

/** @var PDO $db */
$db = Database::getInstance();
$baseDir = getBaseDir();

// Add cache-busting headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// --- 1. Handle Filters ---
$roleFilter = $_GET['role_filter'] ?? 'all';
$userFilter = $_GET['user_filter'] ?? 'all';
$projectFilter = $_GET['project_filter'] ?? 'all';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$allowedPerPage = [10, 25, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

// --- 2. Fetch Helper Data (Users, Projects) ---

// Fetch Users for Dropdown
$usersQuery = "SELECT id, full_name, role FROM users WHERE is_active = 1";
$paramsUsers = [];
if ($roleFilter !== 'all') {
    $usersQuery .= " AND role = ?";
    $paramsUsers[] = $roleFilter;
} else {
    // Show relevants roles
     $usersQuery .= " AND role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
}
$usersQuery .= " ORDER BY full_name";
$stmtUsers = $db->prepare($usersQuery);
$stmtUsers->execute($paramsUsers);
$usersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Fetch Projects
$projectsList = $db->query("SELECT id, title, po_number FROM projects WHERE status NOT IN ('completed', 'cancelled') ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);


// --- 3. Build Main Query Parts for Logs ---
$fromAndWhere = "
    FROM project_time_logs ptl
    JOIN users u ON ptl.user_id = u.id
    LEFT JOIN projects p ON ptl.project_id = p.id
    WHERE ptl.log_date BETWEEN ? AND ?
";

$params = [$startDate, $endDate];

// Apply User Filter
if ($userFilter !== 'all') {
    $fromAndWhere .= " AND ptl.user_id = ?";
    $params[] = $userFilter;
} else if ($roleFilter !== 'all') {
    $fromAndWhere .= " AND u.role = ?";
    $params[] = $roleFilter;
} else {
    // If no specific user/role selected, limit to relevant roles to avoid clutter (e.g. dont show admin logs unless requested)
    $fromAndWhere .= " AND u.role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
}

// Apply Project Filter
if ($projectFilter !== 'all') {
    $fromAndWhere .= " AND ptl.project_id = ?";
    $params[] = $projectFilter;
}

// Total count for pagination
$countSql = "SELECT COUNT(*) " . $fromAndWhere;
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Summary metrics from all filtered logs (not just current page)
$summarySql = "
    SELECT
        COALESCE(SUM(ptl.hours_spent), 0) AS total_hours,
        COALESCE(SUM(CASE WHEN (ptl.is_utilized = 1 OR (p.po_number <> 'OFF-PROD-001' AND ptl.project_id IS NOT NULL)) THEN ptl.hours_spent ELSE 0 END), 0) AS utilized_hours,
        COALESCE(SUM(CASE WHEN (ptl.is_utilized = 1 OR (p.po_number <> 'OFF-PROD-001' AND ptl.project_id IS NOT NULL)) THEN 0 ELSE ptl.hours_spent END), 0) AS bench_hours
    " . $fromAndWhere;
$summaryStmt = $db->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_hours' => 0, 'utilized_hours' => 0, 'bench_hours' => 0];

// Paginated logs query
$sql = "
    SELECT
        ptl.*,
        u.full_name as user_name,
        u.role as user_role,
        p.title as project_title,
        p.po_number,
        p.status as project_status,
        pp.page_name,
        te.name as environment_name,
        ph.phase_name,
        gtc.name as generic_category_name
    FROM project_time_logs ptl
    JOIN users u ON ptl.user_id = u.id
    LEFT JOIN projects p ON ptl.project_id = p.id
    LEFT JOIN project_pages pp ON ptl.page_id = pp.id
    LEFT JOIN testing_environments te ON ptl.environment_id = te.id
    LEFT JOIN project_phases ph ON ptl.phase_id = ph.id
    LEFT JOIN generic_task_categories gtc ON ptl.generic_category_id = gtc.id
    WHERE ptl.log_date BETWEEN ? AND ?
";

// Apply User Filter
if ($userFilter !== 'all') {
    $sql .= " AND ptl.user_id = ?";
} else if ($roleFilter !== 'all') {
    $sql .= " AND u.role = ?";
} else {
    $sql .= " AND u.role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
}

// Apply Project Filter
if ($projectFilter !== 'all') {
    $sql .= " AND ptl.project_id = ?";
}

$sql .= " ORDER BY ptl.log_date DESC, u.full_name ASC LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
$queryParams = array_merge($params, [$perPage, $offset]);
$stmt->execute($queryParams);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3b. Time Log History (Admin only) ---
$isAdminViewer = in_array($_SESSION['role'] ?? '', ['admin'], true);
$historyByUser = [];
$historyTotalRecords = 0;
$historyTotalPages = 1;
$historyPage = 1;
$historyPerPage = 50;

if ($isAdminViewer) {
    // History filters
    $historySearchUser = $_GET['history_search_user'] ?? '';
    $historyActionFilter = $_GET['history_action_filter'] ?? 'all';
    $historyPage = isset($_GET['history_page']) ? max(1, (int)$_GET['history_page']) : 1;
    $historyPerPage = isset($_GET['history_per_page']) ? (int)$_GET['history_per_page'] : 50;
    $allowedHistoryPerPage = [25, 50, 100, 200];
    if (!in_array($historyPerPage, $allowedHistoryPerPage, true)) {
        $historyPerPage = 50;
    }

    try {
        $historySql = "
            SELECT
                h.*,
                u.full_name AS target_user_name,
                cb.full_name AS changed_by_name,
                p.title AS project_title
            FROM project_time_log_history h
            LEFT JOIN users u ON h.user_id = u.id
            LEFT JOIN users cb ON h.changed_by = cb.id
            LEFT JOIN projects p ON h.project_id = p.id
            WHERE DATE(COALESCE(h.new_log_date, h.old_log_date, h.changed_at)) BETWEEN ? AND ?
        ";
        $historyParams = [$startDate, $endDate];

        if ($userFilter !== 'all') {
            $historySql .= " AND h.user_id = ?";
            $historyParams[] = $userFilter;
        } else if ($roleFilter !== 'all') {
            $historySql .= " AND u.role = ?";
            $historyParams[] = $roleFilter;
        } else {
            $historySql .= " AND u.role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
        }

        if ($projectFilter !== 'all') {
            $historySql .= " AND h.project_id = ?";
            $historyParams[] = $projectFilter;
        }

        // Additional history-specific filters
        if (!empty($historySearchUser)) {
            $historySql .= " AND (u.full_name LIKE ? OR cb.full_name LIKE ?)";
            $searchTerm = '%' . $historySearchUser . '%';
            $historyParams[] = $searchTerm;
            $historyParams[] = $searchTerm;
        }

        if ($historyActionFilter !== 'all') {
            $historySql .= " AND h.action_type = ?";
            $historyParams[] = $historyActionFilter;
        }

        // Count total records for pagination
        $countHistorySql = "SELECT COUNT(*) FROM (" . $historySql . ") AS history_count";
        $countHistoryStmt = $db->prepare($countHistorySql);
        $countHistoryStmt->execute($historyParams);
        $historyTotalRecords = (int)$countHistoryStmt->fetchColumn();
        $historyTotalPages = max(1, (int)ceil($historyTotalRecords / $historyPerPage));
        if ($historyPage > $historyTotalPages) {
            $historyPage = $historyTotalPages;
        }
        $historyOffset = ($historyPage - 1) * $historyPerPage;

        $historySql .= " ORDER BY h.changed_at DESC LIMIT ? OFFSET ?";
        $historyParams[] = $historyPerPage;
        $historyParams[] = $historyOffset;

        $historyStmt = $db->prepare($historySql);
        $historyStmt->execute($historyParams);
        $historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($historyRows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if (!isset($historyByUser[$uid])) {
                $historyByUser[$uid] = [
                    'user_name' => $row['target_user_name'] ?: ('User #' . $uid),
                    'rows' => []
                ];
            }
            $historyByUser[$uid]['rows'][] = $row;
        }
    } catch (Exception $e) {
        $historyByUser = [];
        error_log('History query error: ' . $e->getMessage());
    }
}

// --- 4. Summary Metrics ---
$totalHours = (float)($summary['total_hours'] ?? 0);
$utilizedHours = (float)($summary['utilized_hours'] ?? 0);
$benchHours = (float)($summary['bench_hours'] ?? 0);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Production Logs View</h2>
        <div>
            <a href="<?php echo $baseDir; ?>/modules/admin/calendar.php" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-calendar-alt"></i> Back to Calendar
            </a>
            <a href="<?php echo $baseDir; ?>/modules/admin/resource_workload.php" class="btn btn-outline-info ms-2">
                <i class="fas fa-users"></i> Resource Workload
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Role</label>
                    <select name="role_filter" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <option value="project_lead" <?php echo $roleFilter === 'project_lead' ? 'selected' : ''; ?>>Project Lead</option>
                        <option value="qa" <?php echo $roleFilter === 'qa' ? 'selected' : ''; ?>>QA</option>
                        <option value="at_tester" <?php echo $roleFilter === 'at_tester' ? 'selected' : ''; ?>>AT Tester</option>
                        <option value="ft_tester" <?php echo $roleFilter === 'ft_tester' ? 'selected' : ''; ?>>FT Tester</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">User</label>
                    <select name="user_filter" class="form-select">
                        <option value="all">All Users</option>
                        <?php foreach ($usersList as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $userFilter == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Project</label>
                    <select name="project_filter" class="form-select">
                        <option value="all">All Projects</option>
                        <?php foreach ($projectsList as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo $projectFilter == $p['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Per Page</label>
                    <select name="per_page" class="form-select">
                        <?php foreach ([10, 25, 50, 100] as $pp): ?>
                            <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>>
                                <?php echo $pp; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="d-flex gap-2 w-100">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <button type="button" class="btn btn-success" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Export</button>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    function exportToExcel() {
        const form = document.querySelector('form');
        const formData = new FormData(form);
        const params = new URLSearchParams(formData).toString();
        window.open('<?php echo $baseDir; ?>/api/export_production_hours.php?' + params, '_blank');
    }
    </script>

    <!-- Summary Stats -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-primary"><?php echo number_format($totalHours, 2); ?>h</h3>
                    <p class="mb-0 text-muted">Total Hours Logged</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-success"><?php echo number_format($utilizedHours, 2); ?>h</h3>
                    <p class="mb-0 text-muted">Utilized Hours (Billable/Project)</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-secondary"><?php echo number_format($benchHours, 2); ?>h</h3>
                    <p class="mb-0 text-muted">Bench/Off-Prod Hours</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Production Logs (<?php echo (int)$totalLogs; ?> entries)</h5>
            <small class="text-muted">
                Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?>
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Resource</th>
                            <th>Project / Task Info</th>
                            <th>Details</th>
                            <th class="text-end">Hours</th>
                            <th class="text-center">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No logs found for the selected criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <?php 
                                    $isUtilized = $log['is_utilized'] == 1 || ($log['po_number'] !== 'OFF-PROD-001' && $log['project_id'] !== null);
                                ?>
                                <tr>
                                    <td class="text-nowrap"><?php echo date('M d, Y', strtotime($log['log_date'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($log['user_name']); ?></strong><br>
                                        <span class="badge bg-secondary badge-sm" style="font-size: 0.7em;">
                                            <?php echo ucfirst(str_replace('_', ' ', $log['user_role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['project_title']): ?>
                                            <strong><?php echo htmlspecialchars($log['project_title']); ?></strong>
                                            <?php if ($log['po_number']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($log['po_number']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No Project</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            // Construct details based on what's available
                                            $details = [];
                                            
                                            // Task Type
                                            if (!empty($log['task_type'])) {
                                                $taskTypeLabel = ucfirst(str_replace('_', ' ', $log['task_type']));
                                                $details[] = '<span class="badge bg-info">' . htmlspecialchars($taskTypeLabel) . '</span>';
                                            }
                                            
                                            // Page Name
                                            if (!empty($log['page_name'])) {
                                                $details[] = '<i class="fas fa-file-alt text-primary me-1"></i><strong>Page:</strong> ' . htmlspecialchars($log['page_name']);
                                            }
                                            
                                            // Environment
                                            if (!empty($log['environment_name'])) {
                                                $details[] = '<i class="fas fa-server text-success me-1"></i><strong>Env:</strong> ' . htmlspecialchars($log['environment_name']);
                                            }
                                            
                                            // Phase
                                            if (!empty($log['phase_name'])) {
                                                $details[] = '<i class="fas fa-tasks text-warning me-1"></i><strong>Phase:</strong> ' . htmlspecialchars($log['phase_name']);
                                            }
                                            
                                            // Generic Category
                                            if (!empty($log['generic_category_name'])) {
                                                $details[] = '<i class="fas fa-tag text-info me-1"></i><strong>Category:</strong> ' . htmlspecialchars($log['generic_category_name']);
                                            }
                                            
                                            // Testing Type
                                            if (!empty($log['testing_type'])) {
                                                $details[] = '<i class="fas fa-vial text-secondary me-1"></i><strong>Testing:</strong> ' . htmlspecialchars($log['testing_type']);
                                            }
                                            
                                            // Description/Comments
                                            if (!empty($log['description'])) {
                                                $details[] = '<i class="fas fa-comment text-muted me-1"></i>' . htmlspecialchars($log['description']);
                                            }
                                            
                                            if (!empty($details)) {
                                                echo implode('<br>', $details);
                                            } else {
                                                echo '<span class="text-muted fst-italic">No details provided</span>';
                                            }
                                        ?>
                                    </td>
                                    <td class="text-end font-weight-bold">
                                        <?php echo number_format($log['hours_spent'], 2); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($isUtilized): ?>
                                            <span class="badge bg-success">Utilized</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Bench</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($totalLogs > 0): ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    Showing <?php echo (int)($offset + 1); ?> to <?php echo (int)min($offset + $perPage, $totalLogs); ?> of <?php echo (int)$totalLogs; ?> logs
                </small>
                <?php if ($totalPages > 1): ?>
                    <?php
                        $baseParams = $_GET;
                        unset($baseParams['page']);
                        $buildPageUrl = function($p) use ($baseParams) {
                            $params = $baseParams;
                            $params['page'] = $p;
                            return $_SERVER['PHP_SELF'] . '?' . http_build_query($params);
                        };
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                    ?>
                    <nav aria-label="Production logs pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $page <= 1 ? '#' : htmlspecialchars($buildPageUrl($page - 1)); ?>">Previous</a>
                            </li>
                            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($buildPageUrl($p)); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $page >= $totalPages ? '#' : htmlspecialchars($buildPageUrl($page + 1)); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($isAdminViewer): ?>
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Daily Hours Change History (User-wise)</h5>
            <span class="badge bg-info"><?php echo (int)$historyTotalRecords; ?> events</span>
        </div>
        <div class="card-body">
            <!-- History Filters -->
            <form method="GET" class="row g-3 mb-4 p-3 bg-light rounded">
                <!-- Preserve main filters -->
                <input type="hidden" name="role_filter" value="<?php echo htmlspecialchars($roleFilter); ?>">
                <input type="hidden" name="user_filter" value="<?php echo htmlspecialchars($userFilter); ?>">
                <input type="hidden" name="project_filter" value="<?php echo htmlspecialchars($projectFilter); ?>">
                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                <input type="hidden" name="per_page" value="<?php echo (int)$perPage; ?>">
                <input type="hidden" name="page" value="<?php echo (int)$page; ?>">
                
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-search"></i> Search User/Changed By</label>
                    <input type="text" name="history_search_user" class="form-control" 
                           placeholder="Search by name..." 
                           value="<?php echo htmlspecialchars($historySearchUser ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-filter"></i> Action Type</label>
                    <select name="history_action_filter" class="form-select">
                        <option value="all" <?php echo ($historyActionFilter ?? 'all') === 'all' ? 'selected' : ''; ?>>All Actions</option>
                        <option value="created" <?php echo ($historyActionFilter ?? '') === 'created' ? 'selected' : ''; ?>>Created</option>
                        <option value="updated" <?php echo ($historyActionFilter ?? '') === 'updated' ? 'selected' : ''; ?>>Updated</option>
                        <option value="deleted" <?php echo ($historyActionFilter ?? '') === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-list"></i> Per Page</label>
                    <select name="history_per_page" class="form-select">
                        <?php foreach ([25, 50, 100, 200] as $hpp): ?>
                            <option value="<?php echo $hpp; ?>" <?php echo $historyPerPage === $hpp ? 'selected' : ''; ?>>
                                <?php echo $hpp; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="d-flex gap-2 w-100">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="clearHistoryFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                </div>
            </form>

            <?php if (empty($historyByUser)): ?>
                <div class="text-muted text-center py-3">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>No history records found for selected filters.</p>
                </div>
            <?php else: ?>
                <div class="accordion" id="hoursHistoryAccordion">
                    <?php $hIdx = 0; foreach ($historyByUser as $uid => $group): ?>
                        <?php $collapseId = 'historyUser' . (int)$uid; ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading-<?php echo $collapseId; ?>">
                                <button class="accordion-button <?php echo $hIdx > 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="<?php echo $hIdx === 0 ? 'true' : 'false'; ?>" aria-controls="<?php echo $collapseId; ?>">
                                    <strong><?php echo htmlspecialchars($group['user_name']); ?></strong>
                                    <span class="badge bg-secondary ms-2"><?php echo count($group['rows']); ?> events</span>
                                </button>
                            </h2>
                            <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse <?php echo $hIdx === 0 ? 'show' : ''; ?>" aria-labelledby="heading-<?php echo $collapseId; ?>" data-bs-parent="#hoursHistoryAccordion">
                                <div class="accordion-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Changed At</th>
                                                    <th>Project</th>
                                                    <th>Action</th>
                                                    <th>Date (Old → New)</th>
                                                    <th>Hours (Old → New)</th>
                                                    <th>Changed By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($group['rows'] as $row): ?>
                                                    <tr>
                                                        <td class="text-nowrap">
                                                            <small><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($row['changed_at']))); ?></small>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($row['project_title'] ?: '-'); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $row['action_type'] === 'deleted' ? 'danger' : ($row['action_type'] === 'created' ? 'success' : 'warning'); ?>">
                                                                <?php echo htmlspecialchars(ucfirst($row['action_type'])); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-nowrap">
                                                            <small>
                                                                <?php echo htmlspecialchars($row['old_log_date'] ?: '-'); ?>
                                                                <i class="fas fa-arrow-right text-muted mx-1"></i>
                                                                <?php echo htmlspecialchars($row['new_log_date'] ?: '-'); ?>
                                                            </small>
                                                        </td>
                                                        <td class="text-nowrap">
                                                            <small>
                                                                <?php echo htmlspecialchars($row['old_hours'] !== null ? number_format((float)$row['old_hours'], 2) : '-'); ?>
                                                                <i class="fas fa-arrow-right text-muted mx-1"></i>
                                                                <?php echo htmlspecialchars($row['new_hours'] !== null ? number_format((float)$row['new_hours'], 2) : '-'); ?>
                                                            </small>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($row['changed_by_name'] ?: 'Unknown'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php $hIdx++; endforeach; ?>
                </div>

                <!-- History Pagination -->
                <?php if ($historyTotalPages > 1): ?>
                    <?php
                        $historyBaseParams = $_GET;
                        unset($historyBaseParams['history_page']);
                        $buildHistoryPageUrl = function($p) use ($historyBaseParams) {
                            $params = $historyBaseParams;
                            $params['history_page'] = $p;
                            return $_SERVER['PHP_SELF'] . '?' . http_build_query($params);
                        };
                        $historyStartPage = max(1, $historyPage - 2);
                        $historyEndPage = min($historyTotalPages, $historyPage + 2);
                    ?>
                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                        <small class="text-muted">
                            Showing <?php echo (int)(($historyPage - 1) * $historyPerPage + 1); ?> 
                            to <?php echo (int)min($historyPage * $historyPerPage, $historyTotalRecords); ?> 
                            of <?php echo (int)$historyTotalRecords; ?> history records
                        </small>
                        <nav aria-label="History pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $historyPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $historyPage <= 1 ? '#' : htmlspecialchars($buildHistoryPageUrl($historyPage - 1)); ?>">Previous</a>
                                </li>
                                <?php if ($historyStartPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildHistoryPageUrl(1)); ?>">1</a>
                                    </li>
                                    <?php if ($historyStartPage > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($p = $historyStartPage; $p <= $historyEndPage; $p++): ?>
                                    <li class="page-item <?php echo $p === $historyPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildHistoryPageUrl($p)); ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($historyEndPage < $historyTotalPages): ?>
                                    <?php if ($historyEndPage < $historyTotalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($buildHistoryPageUrl($historyTotalPages)); ?>"><?php echo $historyTotalPages; ?></a>
                                    </li>
                                <?php endif; ?>
                                <li class="page-item <?php echo $historyPage >= $historyTotalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $historyPage >= $historyTotalPages ? '#' : htmlspecialchars($buildHistoryPageUrl($historyPage + 1)); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function clearHistoryFilters() {
        const form = document.querySelector('form[method="GET"]');
        const inputs = form.querySelectorAll('input[name^="history_"], select[name^="history_"]');
        inputs.forEach(input => {
            if (input.type === 'text') {
                input.value = '';
            } else if (input.tagName === 'SELECT') {
                input.value = input.querySelector('option').value;
            }
        });
        form.submit();
    }
    </script>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 