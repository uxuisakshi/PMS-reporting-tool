<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead']);

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
/** @var \PDO $db */
$db = Database::getInstance();
$baseDir = getBaseDir();
$cspNonce = generateCspNonce();

// Get report parameters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$projectId = (int)($_GET['project_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';

// IDOR check: project lead can only view their own projects
if ($projectId > 0 && $userRole === 'project_lead') {
    $checkLead = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ?");
    $checkLead->execute([$projectId]);
    if ((int)$checkLead->fetchColumn() !== (int)$userId) {
        $projectId = 0; // Silently unauthorized, default to all of lead's projects
    }
}

// Project statuses from status master (same source as project create/edit)
$projectStatusOptions = getStatusOptions('project');
if (empty($projectStatusOptions)) {
    $projectStatusOptions = [
        ['status_key' => 'planning', 'status_label' => 'Planning'],
        ['status_key' => 'in_progress', 'status_label' => 'In Progress'],
        ['status_key' => 'on_hold', 'status_label' => 'On Hold'],
        ['status_key' => 'completed', 'status_label' => 'Completed'],
        ['status_key' => 'cancelled', 'status_label' => 'Cancelled'],
    ];
}
$projectStatusLabelMap = [];
foreach ($projectStatusOptions as $opt) {
    $k = (string)($opt['status_key'] ?? '');
    if ($k !== '') $projectStatusLabelMap[$k] = (string)($opt['status_label'] ?? formatProjectStatusLabel($k));
}

// Base parameters for queries
$dateExtendedEnd = $endDate . ' 23:59:59';

// 1. Overall statistics
$statsWhere = "1=1";
$statsParams = [];

if ($userRole === 'project_lead') {
    $statsWhere .= " AND p.project_lead_id = ?";
    $statsParams[] = $userId;
}

if ($projectId > 0) {
    $statsWhere .= " AND p.id = ?";
    $statsParams[] = $projectId;
}
$statsStmt = $db->prepare("
    SELECT 
        COUNT(*) as total_projects,
        COALESCE(AVG(total_hours), 0) as avg_hours_per_project
    FROM projects p
    WHERE $statsWhere
");
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch();

$statusCountStmt = $db->prepare("
    SELECT COALESCE(NULLIF(TRIM(p.status), ''), 'not_started') AS status_key, COUNT(*) AS total
    FROM projects p
    WHERE $statsWhere
    GROUP BY COALESCE(NULLIF(TRIM(p.status), ''), 'not_started')
");
$statusCountStmt->execute($statsParams);
$statusCountRows = $statusCountStmt->fetchAll(PDO::FETCH_ASSOC);
$statusCounts = [];
foreach ($statusCountRows as $row) {
    $statusCounts[(string)$row['status_key']] = (int)$row['total'];
}

// 1b. Filtered Projects List (if status is clicked OR total projects clicked)
$showProjectList = !empty($filterStatus) || isset($_GET['show_all']);
$filteredProjects = [];
$fpTotalCount = 0;
$fpPage = max(1, (int)($_GET['fp_page'] ?? 1));
$fpPerPage = 15;
$fpOffset = ($fpPage - 1) * $fpPerPage;

if ($showProjectList) {
    if (!empty($filterStatus)) {
        $fpWhere = "(p.status = ? OR (? = 'not_started' AND (p.status IS NULL OR p.status = '' OR p.status = 'not_started')))";
        $fpParams = [$filterStatus, $filterStatus];
        $fpCountParams = [$filterStatus, $filterStatus];
    } else {
        // show_all: all projects
        $fpWhere = "1=1";
        $fpParams = [];
        $fpCountParams = [];
    }

    if ($userRole === 'project_lead') {
        $fpWhere .= " AND p.project_lead_id = ?";
        $fpParams[] = $userId;
        $fpCountParams[] = $userId;
    }

    if ($projectId > 0) {
        $fpWhere .= " AND p.id = ?";
        $fpParams[] = $projectId;
        $fpCountParams[] = $projectId;
    }

    try {
        $fpCountStmt = $db->prepare("SELECT COUNT(*) FROM projects p WHERE $fpWhere");
        $fpCountStmt->execute($fpCountParams);
        $fpTotalCount = (int)$fpCountStmt->fetchColumn();

        $fpStmt = $db->prepare("
            SELECT p.*, c.name as client_name, u.full_name as lead_name,
                (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) as current_phase
            FROM projects p
            LEFT JOIN clients c ON p.client_id = c.id
            LEFT JOIN users u ON p.project_lead_id = u.id
            WHERE $fpWhere
            ORDER BY p.title ASC
            LIMIT $fpPerPage OFFSET $fpOffset
        ");
        $fpStmt->execute($fpParams);
        $filteredProjects = $fpStmt->fetchAll();
    } catch (Throwable $e) {
        error_log("Filtered Projects Error: " . $e->getMessage());
    }
}


// 2. Project completion by type — no date filter so it matches stat card counts
$whereType = "1=1";
$paramsType = [];

if ($userRole === 'project_lead') {
    $whereType .= " AND p.project_lead_id = ?";
    $paramsType[] = $userId;
}

if ($projectId > 0) {
    $whereType .= " AND p.id = ?";
    $paramsType[] = $projectId;
}
// Apply status filter if set
if (!empty($filterStatus)) {
    $whereType .= " AND (p.status = ? OR (? = 'not_started' AND (p.status IS NULL OR p.status = '' OR p.status = 'not_started')))";
    $paramsType[] = $filterStatus;
    $paramsType[] = $filterStatus;
}

$completionByType = [];
try {
    $completionByTypeStmt = $db->prepare("
        SELECT 
            p.id, 
            p.project_type, 
            p.title, 
            p.po_number as code, 
            p.status, 
            c.name as client
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        WHERE $whereType
    ");
    $completionByTypeStmt->execute($paramsType);
    $allProjects = $completionByTypeStmt->fetchAll(PDO::FETCH_ASSOC);

    $completionMap = [];
    foreach ($allProjects as $p) {
        $type = $p['project_type'] ?: 'N/A';
        if (!isset($completionMap[$type])) {
            $completionMap[$type] = [
                'project_type' => $type,
                'total' => 0,
                'completed' => 0,
                'completion_rate' => 0,
                'projects_list' => []
            ];
        }
        $completionMap[$type]['total']++;
        // Only count completed for completion_rate when no status filter is active
        if ($p['status'] === 'completed') {
            $completionMap[$type]['completed']++;
        }
        $completionMap[$type]['projects_list'][] = [
            'id' => $p['id'],
            'title' => $p['title'],
            'code' => $p['code'],
            'status' => $p['status'],
            'client' => $p['client']
        ];
    }
    foreach ($completionMap as &$typeData) {
        if ($typeData['total'] > 0 && empty($filterStatus)) {
            $typeData['completion_rate'] = round(($typeData['completed'] * 100.0) / $typeData['total'], 2);
        }
    }
    unset($typeData);
    $completionByType = array_values($completionMap);
} catch (PDOException $e) {
    error_log("Project Completion Type Error: " . $e->getMessage());
    $completionByType = [];
}

// 3. Tester performance
$testerPage = (int)(isset($_GET['t_page']) ? $_GET['t_page'] : 1);
if ($testerPage < 1) $testerPage = 1;
$perPage = 10;
$testerOffset = ($testerPage - 1) * $perPage;

$testerParams = [$startDate, $dateExtendedEnd, $startDate, $dateExtendedEnd];
$testerProjectFilter = "";

if ($userRole === 'project_lead') {
    $testerProjectFilter .= " AND ptl.project_id IN (SELECT id FROM projects WHERE project_lead_id = ?) ";
    $testerParams[] = $userId;
}

if ($projectId > 0) {
    $testerProjectFilter .= " AND ptl.project_id = ? ";
    $testerParams[] = $projectId;
}
$testerPerformance = [];
$totalTesters = 0;
try {
    $testerCountStmt = $db->prepare("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $testerProjectFilter
        WHERE u.role IN ('at_tester', 'ft_tester') AND u.is_active = 1
    ");
    $testerCountStmt->execute(array_slice($testerParams, 0, count($testerParams) - 2)); 
    $totalTesters = $testerCountStmt->fetchColumn();

    $testerPerformanceStmt = $db->prepare("
        SELECT 
            u.id, u.full_name, u.role,
            COUNT(DISTINCT ptl.project_id) as pages_tested,
            COALESCE(SUM(ptl.hours_spent), 0) as total_hours,
            (SELECT COUNT(*) FROM issues i WHERE i.reporter_id = u.id AND i.created_at BETWEEN ? AND ?) as total_issues
        FROM users u
        LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $testerProjectFilter
        WHERE u.role IN ('at_tester', 'ft_tester') AND u.is_active = 1
        GROUP BY u.id
        ORDER BY total_hours DESC
        LIMIT $perPage OFFSET $testerOffset
    ");
    $testerPerformanceStmt->execute($testerParams);
    $testerPerformance = $testerPerformanceStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Tester Performance Error: " . $e->getMessage());
}

// 4. QA performance
$qaPage = (int)(isset($_GET['q_page']) ? $_GET['q_page'] : 1);
if ($qaPage < 1) $qaPage = 1;
$qaOffset = ($qaPage - 1) * $perPage;

$qaParams = [$startDate, $dateExtendedEnd, $startDate, $dateExtendedEnd];
$qaProjectFilter = "";

if ($userRole === 'project_lead') {
    $qaProjectFilter .= " AND ptl.project_id IN (SELECT id FROM projects WHERE project_lead_id = ?) ";
    $qaParams[] = $userId;
}

if ($projectId > 0) {
    $qaProjectFilter .= " AND ptl.project_id = ? ";
    $qaParams[] = $projectId;
}
$qaPerformance = [];
$totalQAs = 0;
try {
    $qaCountStmt = $db->prepare("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $qaProjectFilter
        WHERE u.role = 'qa' AND u.is_active = 1
    ");
    $qaCountStmt->execute(array_slice($qaParams, 0, count($qaParams) - 2));
    $totalQAs = $qaCountStmt->fetchColumn();

    $qaPerformanceStmt = $db->prepare("
        SELECT 
            u.id, u.full_name,
            COUNT(DISTINCT ptl.project_id) as pages_reviewed,
            COALESCE(SUM(ptl.hours_spent), 0) as total_hours,
            (SELECT COUNT(*) FROM issues i WHERE i.reporter_id = u.id AND i.created_at BETWEEN ? AND ?) as total_issues
        FROM users u
        LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $qaProjectFilter
        WHERE u.role = 'qa' AND u.is_active = 1
        GROUP BY u.id
        ORDER BY total_hours DESC
        LIMIT $perPage OFFSET $qaOffset
    ");
    $qaPerformanceStmt->execute($qaParams);
    $qaPerformance = $qaPerformanceStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("QA Performance Error: " . $e->getMessage());
}

// 5. Recent project completions
$whereRecent = "p.status = 'completed' AND (p.completed_at BETWEEN ? AND ? OR (p.completed_at IS NULL AND p.updated_at BETWEEN ? AND ?))";
$paramsRecent = [$startDate, $dateExtendedEnd, $startDate, $dateExtendedEnd];
if ($projectId > 0) {
    $whereRecent .= " AND p.id = ?";
    $paramsRecent[] = $projectId;
}
$recentCompletions = [];
try {
    $recentCompletionsStmt = $db->prepare("
        SELECT 
            p.title,
            p.po_number,
            c.name as client_name,
            p.project_type,
            p.completed_at,
            DATEDIFF(p.completed_at, p.created_at) as days_taken,
            p.total_hours
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        WHERE $whereRecent
        ORDER BY p.completed_at DESC
        LIMIT 10
    ");
    $recentCompletionsStmt->execute($paramsRecent);
    $recentCompletions = $recentCompletionsStmt->fetchAll();
} catch (Throwable $e) {
    error_log("Recent Completions Error: " . $e->getMessage());
}

// Get projects for filter
$projects = [];
try {
    $pLeadAndDropdown = ($userRole === 'project_lead') ? " WHERE project_lead_id = " . (int)$userId : "";
    $projects = $db->query("SELECT id, title, po_number FROM projects $pLeadAndDropdown ORDER BY title")->fetchAll();
} catch (Throwable $e) {
    error_log("Filter Projects Error: " . $e->getMessage());
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid">
    <h2>Reports & Analytics</h2>
    
    <!-- Report Filter -->
    <div class="card mb-3">
        <div class="card-header">
            <h5>Report Filter</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label>Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($startDate); ?>">
                </div>
                <div class="col-md-3">
                    <label>End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($endDate); ?>">
                </div>
                <div class="col-md-3">
                    <label>Project</label>
                    <select name="project_id" class="form-select">
                        <option value="0">All Projects</option>
                        <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>" 
                            <?php echo $projectId == $project['id'] ? 'selected' : ''; ?>>
                            <?php echo $project['title']; ?> (<?php echo $project['po_number']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <?php foreach ($projectStatusOptions as $opt): ?>
                            <?php $statusKey = (string)($opt['status_key'] ?? ''); if ($statusKey === '') continue; ?>
                            <option value="<?php echo htmlspecialchars($statusKey); ?>" <?php echo $filterStatus === $statusKey ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)($opt['status_label'] ?? formatProjectStatusLabel($statusKey))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row mb-3" id="stat-cards-row">
        <div class="col-md-3">
            <div class="card text-center bg-primary text-white dashboard-stat-card stat-filter-card <?php echo (empty($filterStatus) && isset($_GET['show_all'])) ? 'active-filter' : ''; ?>"
                 data-status="" data-show-all="1" style="cursor:pointer">
                <div class="card-body">
                    <h3><?php echo $stats['total_projects']; ?></h3>
                    <p class="mb-0">Total Projects</p>
                </div>
            </div>
        </div>
        <?php foreach ($projectStatusOptions as $opt): ?>
            <?php
                $statusKey = (string)($opt['status_key'] ?? '');
                if ($statusKey === '') continue;
                $statusLabel = (string)($opt['status_label'] ?? formatProjectStatusLabel($statusKey));
                $statusCount = (int)($statusCounts[$statusKey] ?? 0);
                $badgeClass = projectStatusBadgeClass($statusKey);
                $textClass = in_array($badgeClass, ['warning', 'info', 'light'], true) ? 'text-dark' : 'text-white';
            ?>
            <div class="col-md-3">
                <div class="card text-center bg-<?php echo $badgeClass; ?> <?php echo $textClass; ?> dashboard-stat-card stat-filter-card <?php echo $filterStatus === $statusKey ? 'active-filter' : ''; ?>"
                     data-status="<?php echo htmlspecialchars($statusKey); ?>" data-show-all="" style="cursor:pointer">
                    <div class="card-body">
                        <h3><?php echo $statusCount; ?></h3>
                        <p class="mb-0"><?php echo htmlspecialchars($statusLabel); ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <style>
        .dashboard-stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .dashboard-stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        .active-filter {
            border: 3px solid #fff;
            box-shadow: 0 0 10px rgba(255,255,255,0.5);
        }
    </style>

    <!-- Project List Container (AJAX updated) -->
    <div id="project-list-container">
    <?php if ($showProjectList): 
        $fpTotalPages = $fpTotalCount > 0 ? ceil($fpTotalCount / $fpPerPage) : 1;
        $fpListTitle = !empty($filterStatus) 
            ? 'Projects: ' . htmlspecialchars($projectStatusLabelMap[$filterStatus] ?? formatProjectStatusLabel($filterStatus))
            : 'All Projects';
        $fpHeaderClass = !empty($filterStatus) ? projectStatusBadgeClass($filterStatus) : 'primary';
        $fpClearUrl = '?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&project_id=' . (int)$projectId;
        $fpPaginationBase = '?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&project_id=' . (int)$projectId . '&status=' . urlencode($filterStatus) . (isset($_GET['show_all']) ? '&show_all=1' : '');
    ?>
    <!-- Filtered / All Project List -->
    <div class="card mb-4 border-<?php echo $fpHeaderClass; ?>">
        <div class="card-header bg-<?php echo $fpHeaderClass; ?> text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?php echo $fpListTitle; ?> <span class="badge bg-light text-dark ms-2"><?php echo $fpTotalCount; ?></span></h5>
            <a href="<?php echo $fpClearUrl; ?>" class="btn btn-sm btn-light">Clear Filter</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Project Title</th>
                            <th>Project Code</th>
                            <th>Client</th>
                            <th>Lead</th>
                            <th>Created</th>
                            <th>Phase</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($filteredProjects)): ?>
                        <tr><td colspan="7" class="text-center py-4">No projects found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($filteredProjects as $fp): ?>
                            <tr>
                                <td><strong><?php echo e($fp['title']); ?></strong></td>
                                <td><?php echo e($fp['po_number']); ?></td>
                                <td><?php echo e($fp['client_name'] ?? 'N/A'); ?></td>
                                <td><?php echo e($fp['lead_name'] ?? 'Unassigned'); ?></td>
                                <td><?php echo date('M d, Y', strtotime($fp['created_at'])); ?></td>
                                <td>
                                    <?php if (!empty($fp['current_phase'])): ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($fp['current_phase']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $fp['id']; ?>" class="btn btn-xs btn-outline-primary">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($fpTotalPages > 1): ?>
            <div class="px-3 py-2">
                <nav aria-label="Projects pagination">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <li class="page-item <?php echo $fpPage <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $fpPaginationBase; ?>&fp_page=<?php echo $fpPage - 1; ?>">Prev</a>
                        </li>
                        <?php for ($i = max(1, $fpPage - 2); $i <= min($fpTotalPages, $fpPage + 2); $i++): ?>
                        <li class="page-item <?php echo $fpPage == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $fpPaginationBase; ?>&fp_page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $fpPage >= $fpTotalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $fpPaginationBase; ?>&fp_page=<?php echo $fpPage + 1; ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <p class="text-center text-muted small mt-1 mb-0">
                    Showing <?php echo $fpOffset + 1; ?>–<?php echo min($fpOffset + $fpPerPage, $fpTotalCount); ?> of <?php echo $fpTotalCount; ?> projects
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    </div><!-- /#project-list-container -->

    
    <div class="row">
        <!-- Project Completion by Type -->
        <div class="col-md-6">
            <div class="card" id="projects-by-type-card">
                <div class="card-header">
                    <h5>
                        <?php if (!empty($filterStatus)): ?>
                            Projects by Type
                            <span class="badge bg-<?php echo projectStatusBadgeClass($filterStatus); ?> ms-2" style="font-size:0.75rem">
                                <?php echo htmlspecialchars($projectStatusLabelMap[$filterStatus] ?? formatProjectStatusLabel($filterStatus)); ?>
                            </span>
                        <?php else: ?>
                            Project Completion by Type
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Project Type</th>
                                    <th>Total</th>
                                    <?php if (empty($filterStatus)): ?>
                                    <th>Completed</th>
                                    <th>Completion Rate</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($completionByType)): ?>
                                <tr><td colspan="<?php echo empty($filterStatus) ? 4 : 2; ?>" class="text-center text-muted py-3"><i class="fas fa-inbox"></i> No data found</td></tr>
                                <?php else: foreach ($completionByType as $type): 
                                    $typeProjects = isset($type['projects_list']) ? $type['projects_list'] : [];
                                    $colspan = empty($filterStatus) ? 4 : 2;
                                ?>
                                <tr class="type-row toggle-type-row js-toggle-type-row" style="cursor:pointer">
                                    <td><i class="fas fa-chevron-right expand-icon" style="transition:transform 0.2s"></i> <strong><?php echo strtoupper($type['project_type'] ?: 'N/A'); ?></strong></td>
                                    <td><?php echo $type['total']; ?></td>
                                    <?php if (empty($filterStatus)): ?>
                                    <td><?php echo $type['completed']; ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" role="progressbar" 
                                                 style="width: <?php echo $type['completion_rate']; ?>%;"
                                                 aria-valuenow="<?php echo $type['completion_rate']; ?>" 
                                                 aria-valuemin="0" aria-valuemax="100">
                                                <?php echo $type['completion_rate']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <tr class="type-detail-row d-none">
                                    <td colspan="<?php echo $colspan; ?>" class="p-0 bg-light">
                                        <div class="p-3">
                                            <?php if (empty($typeProjects)): ?>
                                                <p class="text-muted mb-0">No project details available.</p>
                                            <?php else: ?>
                                                <table class="table table-sm mb-0">
                                                    <thead><tr><th>Code</th><th>Title</th><th>Client</th><th>Status</th><th></th></tr></thead>
                                                    <tbody>
                                                    <?php foreach ($typeProjects as $tp): ?>
                                                    <tr>
                                                         <td><code><?php echo e(isset($tp['code']) ? $tp['code'] : ''); ?></code></td>
                                                         <td><?php echo e(isset($tp['title']) ? $tp['title'] : ''); ?></td>
                                                         <td><?php echo e(isset($tp['client']) ? $tp['client'] : 'N/A'); ?></td>
                                                         <td><span class="badge bg-<?php echo projectStatusBadgeClass(isset($tp['status']) ? $tp['status'] : ''); ?>"><?php echo e(isset($projectStatusLabelMap[isset($tp['status']) ? $tp['status'] : '']) ? $projectStatusLabelMap[isset($tp['status']) ? $tp['status'] : ''] : formatProjectStatusLabel(isset($tp['status']) ? $tp['status'] : '')); ?></span></td>
                                                         <td><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $tp['id']; ?>" class="btn btn-xs btn-outline-primary" target="_blank"><i class="fas fa-eye"></i></a></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Project Completions -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Recent Project Completions</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Client</th>
                                    <th>Type</th>
                                    <th>Days Taken</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentCompletions)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-check-circle fa-2x d-block mb-2"></i>No completed projects found.</td></tr>
                                <?php else: foreach ($recentCompletions as $project): ?>
                                <tr>
                                    <td><?php echo e($project['title']); ?></td>
                                    <td><?php echo e($project['client_name']); ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo e(strtoupper($project['project_type'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo e($project['days_taken']); ?> days</td>
                                    <td><?php echo e($project['total_hours'] ?: 'N/A'); ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <!-- Tester Performance -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Tester Performance</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="tester-table">
                            <thead>
                                <tr>
                                    <th>Tester</th>
                                    <th>Role</th>
                                    <th>Pages Tested</th>
                                    <th>Total Hours</th>
                                    <th>Issues Found</th>
                                </tr>
                            </thead>
                            <tbody id="tester-tbody">
                                <?php if (empty($testerPerformance)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-user-check fa-2x d-block mb-2"></i>No tester activity found.</td></tr>
                                <?php else: foreach ($testerPerformance as $tester): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo (int)$tester['id']; ?>">
                                            <?php echo e($tester['full_name']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo e(strtoupper($tester['role'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo e($tester['pages_tested']); ?></td>
                                    <td><?php echo e($tester['total_hours'] ?: '0'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $tester['total_issues'] > 20 ? 'danger' : 
                                                 ($tester['total_issues'] > 10 ? 'warning' : 'success');
                                        ?>">
                                            <?php echo e($tester['total_issues']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="tester-pagination">
                    <?php if (isset($totalTesters) && isset($perPage) && $totalTesters > $perPage): 
                        $totalPages = ceil($totalTesters / $perPage);
                        $tPage = isset($testerPage) ? $testerPage : 1;
                        $qPage = isset($qaPage) ? $qaPage : 1;
                    ?>
                    <nav aria-label="Tester pagination" class="mt-3">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?php echo $tPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link perf-page-btn" href="#" data-type="tester" data-page="<?php echo $tPage - 1; ?>">Prev</a>
                            </li>
                            <?php for ($i = max(1, $tPage - 2); $i <= min($totalPages, $tPage + 2); $i++): ?>
                            <li class="page-item <?php echo $tPage == $i ? 'active' : ''; ?>">
                                <a class="page-link perf-page-btn" href="#" data-type="tester" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $tPage >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link perf-page-btn" href="#" data-type="tester" data-page="<?php echo $tPage + 1; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- QA Performance -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>QA Performance</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="qa-table">
                            <thead>
                                <tr>
                                    <th>QA</th>
                                    <th>Pages Reviewed</th>
                                    <th>Total Hours</th>
                                    <th>Issues Found</th>
                                </tr>
                            </thead>
                            <tbody id="qa-tbody">
                                <?php if (empty($qaPerformance)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3"><i class="fas fa-clipboard-check fa-2x d-block mb-2"></i>No QA activity found.</td></tr>
                                <?php else: foreach ($qaPerformance as $qa): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo (int)$qa['id']; ?>">
                                            <?php echo e($qa['full_name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo e($qa['pages_reviewed']); ?></td>
                                    <td><?php echo e($qa['total_hours'] ?: '0'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $qa['total_issues'] > 20 ? 'danger' : 
                                                 ($qa['total_issues'] > 10 ? 'warning' : 'success');
                                        ?>">
                                            <?php echo e($qa['total_issues']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="qa-pagination">
                    <?php if (isset($totalQAs) && isset($perPage) && $totalQAs > $perPage): 
                        $totalPagesQA = ceil($totalQAs / $perPage);
                        $tPage = isset($testerPage) ? $testerPage : 1;
                        $qPage = isset($qaPage) ? $qaPage : 1;
                    ?>
                    <nav aria-label="QA pagination" class="mt-3">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?php echo $qPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link perf-page-btn" href="#" data-type="qa" data-page="<?php echo $qPage - 1; ?>">Prev</a>
                            </li>
                            <?php for ($i = max(1, $qPage - 2); $i <= min($totalPagesQA, $qPage + 2); $i++): ?>
                            <li class="page-item <?php echo $qPage == $i ? 'active' : ''; ?>">
                                <a class="page-link perf-page-btn" href="#" data-type="qa" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $qPage >= $totalPagesQA ? 'disabled' : ''; ?>">
                                <a class="page-link perf-page-btn" href="#" data-type="qa" data-page="<?php echo $qPage + 1; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Export Options -->
    <div class="card mt-3">
        <div class="card-header">
            <h5>Export Reports</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=projects&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-primary w-100">
                        <i class="fas fa-file-excel"></i> Export Projects
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=tester&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-success w-100">
                        <i class="fas fa-file-excel"></i> Export Tester Stats
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=qa&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-info w-100">
                        <i class="fas fa-file-excel"></i> Export QA Stats
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=all&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-warning w-100">
                        <i class="fas fa-file-pdf"></i> Export Full Report
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<script nonce="<?php echo $cspNonce; ?>">
function toggleTypeRow(row) {
    const detailRow = row.nextElementSibling;
    const icon = row.querySelector('.expand-icon');
    if (detailRow && detailRow.classList.contains('type-detail-row')) {
        detailRow.classList.toggle('d-none');
        if (icon) icon.style.transform = detailRow.classList.contains('d-none') ? 'rotate(0deg)' : 'rotate(90deg)';
    }
}

// ── Strict CSP Event Listener ──────────────────────────────────────
document.addEventListener('click', function(e) {
    const toggleRow = e.target.closest('.toggle-type-row');
    if (toggleRow) toggleTypeRow(toggleRow);
});

// ── AJAX Project List ──────────────────────────────────────────────
(function () {
    const API_BASE   = '<?php echo $baseDir; ?>/api/report_projects.php';
    const VIEW_BASE  = '<?php echo $baseDir; ?>/modules/projects/view.php';
    const START_DATE = '<?php echo addslashes($startDate); ?>';
    const END_DATE   = '<?php echo addslashes($endDate); ?>';
    const PROJECT_ID = '<?php echo (int)$projectId; ?>';

    let currentStatus  = <?php echo json_encode($filterStatus); ?>;
    let currentShowAll = <?php echo isset($_GET['show_all']) ? 'true' : 'false'; ?>;
    let currentPage    = 1;

    const container = document.getElementById('project-list-container');

    function statusBadgeClass(status) {
        const map = {
            planning: 'secondary', in_progress: 'primary', on_hold: 'warning',
            completed: 'success', cancelled: 'danger', not_started: 'light'
        };
        return map[status] || 'secondary';
    }

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
    }

    function renderList(data, status, showAll) {
        if (!data.success) {
            container.innerHTML = '<div class="alert alert-danger">Failed to load projects.</div>';
            return;
        }

        const headerClass = status ? statusBadgeClass(status) : 'primary';
        const title       = status
            ? 'Projects: ' + (status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()))
            : 'All Projects';

        let rows = '';
        if (!data.projects || data.projects.length === 0) {
            rows = '<tr><td colspan="7" class="text-center py-4">No projects found.</td></tr>';
        } else {
            data.projects.forEach(p => {
                const phase = p.current_phase
                    ? `<span class="badge bg-secondary">${escHtml(p.current_phase)}</span>`
                    : '<span class="text-muted">—</span>';
                rows += `<tr>
                    <td><strong>${escHtml(p.title)}</strong></td>
                    <td>${escHtml(p.po_number || '')}</td>
                    <td>${escHtml(p.client_name || 'N/A')}</td>
                    <td>${escHtml(p.lead_name || 'Unassigned')}</td>
                    <td>${formatDate(p.created_at)}</td>
                    <td>${phase}</td>
                    <td><a href="${VIEW_BASE}?id=${p.id}" class="btn btn-xs btn-outline-primary">View</a></td>
                </tr>`;
            });
        }

        let pagination = '';
        if (data.total_pages > 1) {
            let pages = '';
            const cur = data.page, total = data.total_pages;
            for (let i = Math.max(1, cur - 2); i <= Math.min(total, cur + 2); i++) {
                pages += `<li class="page-item ${i === cur ? 'active' : ''}">
                    <a class="page-link fp-page-btn" href="#" data-page="${i}">${i}</a></li>`;
            }
            const from = (cur - 1) * data.per_page + 1;
            const to   = Math.min(cur * data.per_page, data.total);
            pagination = `
            <div class="px-3 py-2">
                <nav aria-label="Projects pagination">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <li class="page-item ${cur <= 1 ? 'disabled' : ''}">
                            <a class="page-link fp-page-btn" href="#" data-page="${cur - 1}">Prev</a></li>
                        ${pages}
                        <li class="page-item ${cur >= total ? 'disabled' : ''}">
                            <a class="page-link fp-page-btn" href="#" data-page="${cur + 1}">Next</a></li>
                    </ul>
                </nav>
                <p class="text-center text-muted small mt-1 mb-0">Showing ${from}–${to} of ${data.total} projects</p>
            </div>`;
        }

        container.innerHTML = `
        <div class="card mb-4 border-${headerClass}">
            <div class="card-header bg-${headerClass} text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">${title} <span class="badge bg-light text-dark ms-2">${data.total}</span></h5>
                <button class="btn btn-sm btn-light" id="fp-clear-btn">Clear Filter</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Project Title</th><th>Project Code</th><th>Client</th>
                                <th>Lead</th><th>Created</th><th>Phase</th><th>Actions</th></tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                ${pagination}
            </div>
        </div>`;

        // Bind pagination clicks
        container.querySelectorAll('.fp-page-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const pg = parseInt(this.dataset.page);
                if (!isNaN(pg) && pg >= 1) loadProjects(currentStatus, currentShowAll, pg);
            });
        });

        // Bind clear button
        const clearBtn = document.getElementById('fp-clear-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                container.innerHTML = '';
                // deactivate all stat cards
                document.querySelectorAll('.stat-filter-card').forEach(c => c.classList.remove('active-filter'));
                currentStatus  = '';
                currentShowAll = false;
                currentPage    = 1;
            });
        }
    }

    function loadProjects(status, showAll, page) {
        currentStatus  = status;
        currentShowAll = showAll;
        currentPage    = page;

        container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';

        const params = new URLSearchParams({
            start_date: START_DATE,
            end_date:   END_DATE,
            project_id: PROJECT_ID,
            status:     status,
            fp_page:    page,
        });
        if (showAll) params.set('show_all', '1');

        fetch(`${API_BASE}?${params.toString()}`)
            .then(r => r.json())
            .then(data => renderList(data, status, showAll))
            .catch(() => {
                container.innerHTML = '<div class="alert alert-danger">Failed to load projects.</div>';
            });
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Stat card click handlers
    document.querySelectorAll('.stat-filter-card').forEach(card => {
        card.addEventListener('click', function () {
            document.querySelectorAll('.stat-filter-card').forEach(c => c.classList.remove('active-filter'));
            this.classList.add('active-filter');
            const status  = this.dataset.status || '';
            const showAll = !!this.dataset.showAll;
            loadProjects(status, showAll, 1);
            loadProjectsByType(status);
            // Smooth scroll to list
            setTimeout(() => container.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
        });
    });

    // If page loaded with a filter already active, list is already rendered server-side — just bind pagination
    container.querySelectorAll('.fp-page-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const pg = parseInt(this.dataset.page);
            if (!isNaN(pg) && pg >= 1) loadProjects(currentStatus, currentShowAll, pg);
        });
    });
    const clearBtn = document.getElementById('fp-clear-btn');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            container.innerHTML = '';
            document.querySelectorAll('.stat-filter-card').forEach(c => c.classList.remove('active-filter'));
            currentStatus = ''; currentShowAll = false; currentPage = 1;
            loadProjectsByType('');
        });
    }
})();

// ── AJAX Projects by Type ─────────────────────────────────────────
(function () {
    const PBT_API    = '<?php echo $baseDir; ?>/api/report_projects_by_type.php';
    const VIEW_BASE  = '<?php echo $baseDir; ?>/modules/projects/view.php';
    const START_DATE = '<?php echo addslashes($startDate); ?>';
    const END_DATE   = '<?php echo addslashes($endDate); ?>';
    const PROJECT_ID = '<?php echo (int)$projectId; ?>';

    // Status label map from PHP
    const STATUS_LABEL_MAP = <?php echo json_encode($projectStatusLabelMap); ?>;
    const STATUS_BADGE_MAP = {
        planning: 'secondary', in_progress: 'primary', on_hold: 'warning',
        completed: 'success', cancelled: 'danger', not_started: 'light'
    };

    function badgeClass(status) {
        return STATUS_BADGE_MAP[status] || 'secondary';
    }
    function statusLabel(status) {
        return STATUS_LABEL_MAP[status] || status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }
    function escHtml(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderCard(data) {
        const card = document.getElementById('projects-by-type-card');
        if (!card) return;

        const filterStatus = data.filter_status || '';
        const hasFilter    = filterStatus !== '';
        const colCount     = hasFilter ? 2 : 4;

        let badgeHtml = '';
        if (hasFilter) {
            const bc = badgeClass(filterStatus);
            const tc = ['warning','info','light'].includes(bc) ? 'text-dark' : 'text-white';
            badgeHtml = `<span class="badge bg-${bc} ${tc} ms-2" style="font-size:0.75rem">${escHtml(statusLabel(filterStatus))}</span>`;
        }

        const title = hasFilter ? `Projects by Type ${badgeHtml}` : 'Project Completion by Type';

        let extraHeaders = hasFilter ? '' : '<th>Completed</th><th>Completion Rate</th>';

        let rows = '';
        if (!data.data || data.data.length === 0) {
            rows = `<tr><td colspan="${colCount}" class="text-center text-muted py-3"><i class="fas fa-inbox"></i> No data found</td></tr>`;
        } else {
            data.data.forEach((type, idx) => {
                const projects = type.projects_list || [];
                let extraCols = '';
                if (!hasFilter) {
                    const rate = type.completion_rate ?? 0;
                    extraCols = `<td>${type.completed}</td>
                    <td><div class="progress" style="height:20px">
                        <div class="progress-bar" role="progressbar" style="width:${rate}%" aria-valuenow="${rate}" aria-valuemin="0" aria-valuemax="100">${rate}%</div>
                    </div></td>`;
                }
                rows += `<tr class="type-row toggle-type-row js-toggle-type-row" style="cursor:pointer">
                    <td><i class="fas fa-chevron-right expand-icon" style="transition:transform 0.2s"></i> <strong>${escHtml((type.project_type||'N/A').toUpperCase())}</strong></td>
                    <td>${type.total}</td>${extraCols}
                </tr>
                <tr class="type-detail-row d-none">
                    <td colspan="${colCount}" class="p-0 bg-light"><div class="p-3">`;

                if (projects.length === 0) {
                    rows += '<p class="text-muted mb-0">No project details available.</p>';
                } else {
                    rows += `<table class="table table-sm mb-0">
                        <thead><tr><th>Code</th><th>Title</th><th>Client</th><th>Status</th><th></th></tr></thead><tbody>`;
                    projects.forEach(p => {
                        const bc = badgeClass(p.status);
                        rows += `<tr>
                            <td><code>${escHtml(p.code||'')}</code></td>
                            <td>${escHtml(p.title||'')}</td>
                            <td>${escHtml(p.client||'N/A')}</td>
                            <td><span class="badge bg-${bc}">${escHtml(statusLabel(p.status||''))}</span></td>
                            <td><a href="${VIEW_BASE}?id=${p.id}" class="btn btn-xs btn-outline-primary" target="_blank"><i class="fas fa-eye"></i></a></td>
                        </tr>`;
                    });
                    rows += '</tbody></table>';
                }
                rows += '</div></td></tr>';
            });
        }

        card.innerHTML = `
        <div class="card-header"><h5>${title}</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead><tr><th>Project Type</th><th>Total</th>${extraHeaders}</tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </div>`;
    }

    window.loadProjectsByType = function (status) {
        const card = document.getElementById('projects-by-type-card');
        if (!card) return;
        card.innerHTML = '<div class="card-body text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';

        const params = new URLSearchParams({
            start_date: START_DATE, end_date: END_DATE,
            project_id: PROJECT_ID, status: status || ''
        });

        fetch(`${PBT_API}?${params}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { card.innerHTML = '<div class="card-body text-danger">Failed to load.</div>'; return; }
                renderCard(data);
            })
            .catch(() => { card.innerHTML = '<div class="card-body text-danger">Failed to load.</div>'; });
    };
})();

// ── AJAX Tester / QA Performance Pagination ───────────────────────
(function () {
    const PERF_API  = '<?php echo $baseDir; ?>/api/report_performance.php';
    const PROF_BASE = '<?php echo $baseDir; ?>/modules/profile.php';
    const START_DATE = '<?php echo addslashes($startDate); ?>';
    const END_DATE   = '<?php echo addslashes($endDate); ?>';
    const PROJECT_ID = '<?php echo (int)$projectId; ?>';

    const state = { tester: <?php echo $testerPage; ?>, qa: <?php echo $qaPage; ?> };

    function issueBadge(n) {
        const cls = n > 20 ? 'danger' : (n > 10 ? 'warning' : 'success');
        return `<span class="badge bg-${cls}">${n}</span>`;
    }

    function renderTesterRows(rows) {
        if (!rows || rows.length === 0)
            return '<tr><td colspan="5" class="text-center text-muted py-3">No tester activity found.</td></tr>';
        return rows.map(r => `<tr>
            <td><a href="${PROF_BASE}?id=${r.id}">${escHtml(r.full_name)}</a></td>
            <td><span class="badge bg-info">${escHtml(r.role.toUpperCase())}</span></td>
            <td>${r.pages_tested}</td>
            <td>${r.total_hours || '0'}</td>
            <td>${issueBadge(parseInt(r.total_issues))}</td>
        </tr>`).join('');
    }

    function renderQaRows(rows) {
        if (!rows || rows.length === 0)
            return '<tr><td colspan="4" class="text-center text-muted py-3">No QA activity found.</td></tr>';
        return rows.map(r => `<tr>
            <td><a href="${PROF_BASE}?id=${r.id}">${escHtml(r.full_name)}</a></td>
            <td>${r.pages_reviewed}</td>
            <td>${r.total_hours || '0'}</td>
            <td>${issueBadge(parseInt(r.total_issues))}</td>
        </tr>`).join('');
    }

    function renderPagination(type, cur, total) {
        if (total <= 1) return '';
        let items = '';
        for (let i = Math.max(1, cur - 2); i <= Math.min(total, cur + 2); i++) {
            items += `<li class="page-item ${i === cur ? 'active' : ''}">
                <a class="page-link perf-page-btn" href="#" data-type="${type}" data-page="${i}">${i}</a></li>`;
        }
        return `<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">
            <li class="page-item ${cur <= 1 ? 'disabled' : ''}">
                <a class="page-link perf-page-btn" href="#" data-type="${type}" data-page="${cur - 1}">Prev</a></li>
            ${items}
            <li class="page-item ${cur >= total ? 'disabled' : ''}">
                <a class="page-link perf-page-btn" href="#" data-type="${type}" data-page="${cur + 1}">Next</a></li>
        </ul></nav>`;
    }

    function loadPerf(type, page) {
        state[type] = page;
        const tbody  = document.getElementById(type + '-tbody');
        const pagDiv = document.getElementById(type + '-pagination');
        if (!tbody) return;

        tbody.innerHTML = `<tr><td colspan="${type === 'tester' ? 5 : 4}" class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary" role="status"></div></td></tr>`;

        const params = new URLSearchParams({
            type, start_date: START_DATE, end_date: END_DATE,
            project_id: PROJECT_ID, page
        });

        fetch(`${PERF_API}?${params}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error loading data.</td></tr>'; return; }
                tbody.innerHTML = type === 'tester' ? renderTesterRows(data.rows) : renderQaRows(data.rows);
                if (pagDiv) pagDiv.innerHTML = renderPagination(type, data.page, data.total_pages);
                bindPerfPagination(pagDiv);
            })
            .catch(() => { tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Failed to load.</td></tr>'; });
    }

    function bindPerfPagination(container) {
        if (!container) return;
        container.querySelectorAll('.perf-page-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const pg = parseInt(this.dataset.page);
                const tp = this.dataset.type;
                if (!isNaN(pg) && pg >= 1) loadPerf(tp, pg);
            });
        });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Bind initial server-rendered pagination buttons
    bindPerfPagination(document.getElementById('tester-pagination'));
    bindPerfPagination(document.getElementById('qa-pagination'));
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; 