<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$db = Database::getInstance();
$baseDir = getBaseDir();

// Check if qa_status_master table exists
$hasQaStatusMaster = false;
try {
    $db->query("SELECT 1 FROM qa_status_master LIMIT 1");
    $hasQaStatusMaster = true;
} catch (Exception $e) {
    $hasQaStatusMaster = false;
}

$hasReporterQaStatusTable = false;
try {
    $db->query("SELECT 1 FROM issue_reporter_qa_status LIMIT 1");
    $hasReporterQaStatusTable = true;
} catch (Exception $e) {
    $hasReporterQaStatusTable = false;
}

// Filters
$filters = [
    'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
    'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
    'project_id' => isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null,
    'user_id' => isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null,
    'severity_level' => $_GET['severity_level'] ?? '',
    'group_by' => $_GET['group_by'] ?? 'user', // 'user' or 'project'
    'grade_filter' => $_GET['grade_filter'] ?? '',
    'sort_by' => $_GET['sort_by'] ?? 'performance_score',
    'sort_order' => $_GET['sort_order'] ?? 'desc',
];

// Get projects and users for filters
$projects = $db->query('SELECT id, title FROM projects ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query("SELECT id, full_name, username FROM users WHERE is_active = 1 AND role NOT IN ('admin') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$messages = ['error' => null, 'info' => null];
$performanceData = [];
$projectStats = [
    'total_resources' => 0,
    'total_comments' => 0,
    'total_issues' => 0,
    'issues_reviewed' => 0,
    'avg_error_rate' => 0,
    'avg_error_rate_percent' => 0,
    'avg_performance_score' => 0
];

if (!$hasQaStatusMaster) {
    $messages['error'] = 'QA Status Master system not configured. Please run migration 052.';
} else {
    // Build WHERE clause for filters
    $whereConditions = [];
    $params = [];
    
    if (!empty($filters['start_date'])) {
        $whereConditions[] = 'DATE(COALESCE(irqs.updated_at, i.updated_at)) >= ?';
        $params[] = $filters['start_date'];
    }
    if (!empty($filters['end_date'])) {
        $whereConditions[] = 'DATE(COALESCE(irqs.updated_at, i.updated_at)) <= ?';
        $params[] = $filters['end_date'];
    }
    if (!empty($filters['project_id'])) {
        $whereConditions[] = 'i.project_id = ?';
        $params[] = $filters['project_id'];
    }
    if (!empty($filters['user_id'])) {
        $whereConditions[] = 'u.id = ?';
        $params[] = $filters['user_id'];
    }
    if (!empty($filters['severity_level'])) {
        $whereConditions[] = 'qsm.severity_level = ?';
        $params[] = $filters['severity_level'];
    }
    
    $whereClause = !empty($whereConditions) ? 'AND ' . implode(' AND ', $whereConditions) : '';
    $whereSql = !empty($whereConditions) ? implode(' AND ', $whereConditions) : '1=1';
    
    // Use the exact same logic as tab_performance.php for score calculation
    if ($hasReporterQaStatusTable) {
        if ($filters['group_by'] === 'project') {
            $perfSql = "SELECT
                            p.id AS project_id,
                            p.title AS project_title,
                            COUNT(DISTINCT i.user_id) AS total_resources,
                            COUNT(DISTINCT i.id) AS total_issues,
                            COUNT(DISTINCT CASE 
                                WHEN irqs.qa_status_key IS NOT NULL 
                                AND irqs.qa_status_key != '' 
                                AND TRIM(irqs.qa_status_key) != ''
                                AND COALESCE(qsm.error_points, 0) > 0 
                                THEN i.id 
                            END) AS issues_with_changes,
                            COUNT(DISTINCT CASE 
                                WHEN irqs.qa_status_key IS NULL 
                                OR irqs.qa_status_key = '' 
                                OR TRIM(irqs.qa_status_key) = '' 
                                THEN i.id 
                            END) AS issues_pending_qa,
                            COUNT(DISTINCT irqs.id) AS total_comments,
                            SUM(COALESCE(qsm.error_points, 0)) AS total_error_points,
                            AVG(COALESCE(qsm.error_points, 0)) AS avg_error_points,
                            MAX(COALESCE(irqs.updated_at, i.updated_at)) AS last_activity_date
                        FROM projects p
                        INNER JOIN (
                            SELECT DISTINCT i.id, i.project_id, i.updated_at, u_rel.user_id
                            FROM issues i
                            CROSS JOIN (
                                SELECT i2.id as issue_id, i2.reporter_id as user_id
                                FROM issues i2
                                UNION DISTINCT
                                SELECT irqs2.issue_id, irqs2.reporter_user_id as user_id
                                FROM issue_reporter_qa_status irqs2
                            ) u_rel ON u_rel.issue_id = i.id
                        ) i ON i.project_id = p.id
                        INNER JOIN users u ON u.id = i.user_id
                        LEFT JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id AND irqs.reporter_user_id = u.id
                        LEFT JOIN qa_status_master qsm
                            ON FIND_IN_SET(
                                LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_general_ci,
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            REPLACE(LOWER(TRIM(COALESCE(irqs.qa_status_key, ''))) COLLATE utf8mb4_general_ci, ' ', ''),
                                            '[', ''
                                        ),
                                        ']', ''
                                    ),
                                    CHAR(34), ''
                                )
                            ) > 0
                           AND qsm.is_active = 1
                        WHERE u.role NOT IN ('admin')
                          AND i.id IS NOT NULL 
                          " . (!empty($whereSql) ? " AND ($whereSql)" : "") . "
                        GROUP BY p.id, p.title
                        HAVING COUNT(DISTINCT i.id) > 0
                        ORDER BY total_error_points DESC, total_comments DESC";
        } else {
            $perfSql = "SELECT
                            u.id AS user_id,
                            u.full_name,
                            u.username,
                            u.role,
                            COUNT(DISTINCT i.id) AS total_issues,
                            COUNT(DISTINCT CASE 
                                WHEN irqs.qa_status_key IS NOT NULL 
                                AND irqs.qa_status_key != '' 
                                AND TRIM(irqs.qa_status_key) != ''
                                AND COALESCE(qsm.error_points, 0) > 0 
                                THEN i.id 
                            END) AS issues_with_changes,
                            COUNT(DISTINCT CASE 
                                WHEN irqs.qa_status_key IS NULL 
                                OR irqs.qa_status_key = '' 
                                OR TRIM(irqs.qa_status_key) = '' 
                                THEN i.id 
                            END) AS issues_pending_qa,
                            COUNT(DISTINCT irqs.id) AS total_comments,
                            COUNT(DISTINCT i.project_id) AS total_projects,
                            SUM(COALESCE(qsm.error_points, 0)) AS total_error_points,
                            AVG(COALESCE(qsm.error_points, 0)) AS avg_error_points,
                            MAX(COALESCE(irqs.updated_at, i.updated_at)) AS last_activity_date
                        FROM users u
                        LEFT JOIN (
                            -- Get all issues where user is involved (main reporter OR additional reporter)
                            SELECT DISTINCT i.id, i.project_id, i.updated_at, u_rel.user_id
                            FROM issues i
                            CROSS JOIN (
                                -- Get main reporters
                                SELECT i2.id as issue_id, i2.reporter_id as user_id
                                FROM issues i2
                                
                                UNION DISTINCT
                                
                                -- Get additional reporters
                                SELECT irqs2.issue_id, irqs2.reporter_user_id as user_id
                                FROM issue_reporter_qa_status irqs2
                            ) u_rel ON u_rel.issue_id = i.id
                        ) i ON i.user_id = u.id
                        LEFT JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id AND irqs.reporter_user_id = u.id
                        LEFT JOIN qa_status_master qsm
                            ON FIND_IN_SET(
                                LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_general_ci,
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            REPLACE(LOWER(TRIM(COALESCE(irqs.qa_status_key, ''))) COLLATE utf8mb4_general_ci, ' ', ''),
                                            '[', ''
                                        ),
                                        ']', ''
                                    ),
                                    CHAR(34), ''
                                )
                            ) > 0
                           AND qsm.is_active = 1
                        WHERE u.role NOT IN ('admin')
                          AND i.id IS NOT NULL 
                          " . (!empty($whereSql) ? " AND ($whereSql)" : "") . "
                        GROUP BY u.id, u.full_name, u.username, u.role
                        HAVING COUNT(DISTINCT i.id) > 0
                        ORDER BY total_error_points DESC, total_comments DESC";
        }
    
        try {
            $stmt = $db->prepare($perfSql);
            $stmt->execute($params);
            $performanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('admin/performance reporter-level query failed: ' . $e->getMessage());
            $performanceData = [];
            $messages['info'] = 'Reporter-level QA data query failed, showing fallback data if available.';
        }
    }

    // Fallback for legacy issue metadata
    if (empty($performanceData)) {
        $legacySql = "SELECT 
                        u.id as user_id,
                        u.full_name,
                        u.username,
                        u.role,
                        COUNT(DISTINCT im.id) as total_comments,
                        COUNT(DISTINCT i.id) as total_issues,
                        COUNT(DISTINCT i.project_id) as total_projects,
                        COUNT(DISTINCT CASE WHEN im.meta_value IS NOT NULL AND im.meta_value != '' AND COALESCE(qsm.error_points, 0) > 0 THEN i.id END) AS issues_with_changes,
                        COUNT(DISTINCT CASE WHEN (im.meta_value IS NULL OR im.meta_value = '') THEN i.id END) AS issues_pending_qa,
                        SUM(COALESCE(qsm.error_points, 0)) as total_error_points,
                        AVG(COALESCE(qsm.error_points, 0)) as avg_error_points,
                        MAX(i.updated_at) as last_activity_date
                    FROM issues i
                    JOIN users u ON i.reporter_id = u.id
                    LEFT JOIN issue_metadata im ON im.issue_id = i.id AND im.meta_key = 'qa_status'
                    LEFT JOIN qa_status_master qsm
                        ON (
                            LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                            OR LOWER(TRIM(qsm.status_label)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                        )
                       AND qsm.is_active = 1
                    WHERE u.role NOT IN ('admin') 
                      " . (!empty($whereSql) ? " AND ($whereSql)" : "") . "
                    GROUP BY u.id, u.full_name, u.username, u.role
                    ORDER BY total_error_points DESC, total_comments DESC";
        try {
            $legacyStmt = $db->prepare($legacySql);
            $legacyStmt->execute($params);
            $performanceData = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('admin/performance legacy query failed: ' . $e->getMessage());
            $performanceData = [];
        }
    }
    
    // Calculate performance score for each user (without error rate dependency)
    foreach ($performanceData as &$data) {
        $totalComments = (int)$data['total_comments'];
        $totalIssues = (int)$data['total_issues'];
        $issuesWithChanges = (int)($data['issues_with_changes'] ?? 0);
        $totalErrorPoints = (float)($data['total_error_points'] ?? 0);
        
        if (!isset($data['issues_with_changes'])) {
            $data['issues_with_changes'] = 0;
        }
        if (!isset($data['issues_pending_qa'])) {
            $data['issues_pending_qa'] = 0;
        }
        
        $evaluatedIssues = max(0, $totalIssues - (int)$data['issues_pending_qa']);
        
        // Error rate
        $data['error_rate'] = $evaluatedIssues > 0 ? round($totalErrorPoints / $evaluatedIssues, 2) : 0;
        
        // Performance score
        // We must exclude pending QA issues from the "total" when considering quality ratio,
        // otherwise a pending QA issue looks like a perfect 100% score issue.
        $qualityRatio = $evaluatedIssues > 0 ? (($evaluatedIssues - $issuesWithChanges) / $evaluatedIssues) : 1;
        
        // If they have NO evaluated issues (e.g. all 5 issues are pending QA), score defaults to N/A or 100
        if ($evaluatedIssues == 0) {
            $data['performance_score'] = 0;
            $data['grade'] = 'N/A';
            $data['grade_color'] = 'secondary';
            $data['performance_display'] = 'N/A';
        } else {
            $data['performance_score'] = max(0, round($qualityRatio * 100, 1));
            $data['performance_display'] = $data['performance_score'] . '%';
            
            // Performance grade
            if ($data['performance_score'] >= 90) {
                $data['grade'] = 'A+';
                $data['grade_color'] = 'success';
            } elseif ($data['performance_score'] >= 80) {
                $data['grade'] = 'A';
                $data['grade_color'] = 'success';
            } elseif ($data['performance_score'] >= 70) {
                $data['grade'] = 'B';
                $data['grade_color'] = 'info';
            } elseif ($data['performance_score'] >= 60) {
                $data['grade'] = 'C';
                $data['grade_color'] = 'warning';
            } else {
                $data['grade'] = 'D';
                $data['grade_color'] = 'danger';
            }
        }
    }
    unset($data);
    
    // Sort by performance score (best first)
    usort($performanceData, function($a, $b) {
        if ($a['performance_score'] == $b['performance_score']) {
            return $a['error_rate'] <=> $b['error_rate'];
        }
        return $b['performance_score'] <=> $a['performance_score'];
    });


    
    // Get recent activities (last 50) from reporter-level QA statuses
    $recentSql = "SELECT 
                    i.id as issue_id,
                    i.project_id,
                    u.full_name,
                    u.username,
                    qsm.status_label,
                    qsm.status_key,
                    qsm.severity_level,
                    qsm.badge_color,
                    qsm.error_points,
                    DATE(irqs.updated_at) as comment_date,
                    irqs.updated_at as created_at,
                    p.title as project_title
                FROM issues i
                JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id
                JOIN users u ON u.id = irqs.reporter_user_id
                JOIN qa_status_master qsm
                    ON FIND_IN_SET(
                        LOWER(TRIM(qsm.status_key)),
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(LOWER(TRIM(irqs.qa_status_key)), ' ', ''),
                                    '[', ''
                                ),
                                ']', ''
                            ),
                            CHAR(34), ''
                        )
                    ) > 0
                   AND qsm.is_active = 1
                LEFT JOIN projects p ON i.project_id = p.id
                WHERE " . (!empty($whereSql) ? "($whereSql) AND " : "") . "
                u.role NOT IN ('admin')
                ORDER BY irqs.updated_at DESC
                LIMIT 50";
    
    if ($hasReporterQaStatusTable) {
        try {
            $recentStmt = $db->prepare($recentSql);
            $recentStmt->execute($params);
            $recentActivities = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('admin/performance recent reporter query failed: ' . $e->getMessage());
            $recentActivities = [];
        }
    }

    if (empty($recentActivities)) {
        $recentLegacySql = "SELECT 
                            i.id as issue_id,
                            i.project_id,
                            u.full_name,
                            u.username,
                            qsm.status_label,
                            qsm.status_key,
                            qsm.severity_level,
                            qsm.badge_color,
                            qsm.error_points,
                            DATE(i.updated_at) as comment_date,
                            i.updated_at as created_at,
                            p.title as project_title
                        FROM issues i
                        JOIN users u ON i.reporter_id = u.id
                        JOIN issue_metadata im ON im.issue_id = i.id AND im.meta_key = 'qa_status'
                        JOIN qa_status_master qsm
                            ON (
                                LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                                OR LOWER(TRIM(qsm.status_label)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                            )
                           AND qsm.is_active = 1
                        LEFT JOIN projects p ON i.project_id = p.id
                        WHERE " . (!empty($whereSql) ? "($whereSql) AND " : "") . "
                        u.role NOT IN ('admin')
                        ORDER BY i.updated_at DESC
                        LIMIT 50";
        try {
            $recentLegacyStmt = $db->prepare($recentLegacySql);
            $recentLegacyStmt->execute($params);
            $recentActivities = $recentLegacyStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('admin/performance recent legacy query failed: ' . $e->getMessage());
            $recentActivities = [];
        }
    }
    
    // Calculate overall statistics
    $overallStats = [
        'total_users' => count($performanceData),
        'total_comments' => array_sum(array_column($performanceData, 'total_comments')),
        'total_issues' => array_sum(array_column($performanceData, 'total_issues')),
        'avg_error_rate' => count($performanceData) > 0 ? round(array_sum(array_column($performanceData, 'error_rate')) / count($performanceData), 2) : 0,
        'avg_performance_score' => count($performanceData) > 0 ? round(array_sum(array_column($performanceData, 'performance_score')) / count($performanceData), 1) : 0,
    ];

    // We will sum total_resources if grouped by user or project
    if ($filters['group_by'] === 'project') {
        $overallStats['total_users'] = count($performanceData); // representing Total rows
        $overallStats['total_resources'] = array_sum(array_column($performanceData, 'total_resources'));
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><i class="fas fa-chart-line text-primary"></i> Resource Performance</h3>
            <small class="text-muted">Track QA status comments and error rates for all resources</small>
        </div>
        <div>
            <a class="btn btn-outline-secondary" href="<?php echo $baseDir; ?>/modules/admin/qa_status_master.php">
                <i class="fas fa-cog"></i> Manage QA Statuses
            </a>
            <a class="btn btn-outline-secondary" href="<?php echo $baseDir; ?>/modules/admin/dashboard.php">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <?php if (!empty($messages['error'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($messages['error']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($messages['info'])): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($messages['info']); ?>
        </div>
    <?php endif; ?>

    <?php if ($hasQaStatusMaster): ?>
    
    <!-- Overall Statistics -->
    <?php if (!empty($performanceData)): ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-primary">
                <div class="card-body text-center">
                    <i class="fas fa-users fa-2x text-primary mb-2"></i>
                    <h4 class="mb-0"><?php echo $overallStats['total_users']; ?></h4>
                    <small class="text-muted">Total Users</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-info">
                <div class="card-body text-center">
                    <i class="fas fa-comments fa-2x text-info mb-2"></i>
                    <h4 class="mb-0"><?php echo $overallStats['total_comments']; ?></h4>
                    <small class="text-muted">Total QA Comments</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body text-center">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                    <h4 class="mb-0"><?php echo $overallStats['avg_error_rate']; ?></h4>
                    <small class="text-muted">Avg Error Rate (0-3)</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body text-center">
                    <i class="fas fa-trophy fa-2x text-success mb-2"></i>
                    <h4 class="mb-0"><?php echo $overallStats['avg_performance_score']; ?>%</h4>
                    <small class="text-muted">Avg Performance Score</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3" id="filterForm">
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filters['start_date']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filters['end_date']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Project</label>
                    <select name="project_id" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo $filters['project_id'] == $p['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">User</label>
                    <select name="user_id" class="form-select">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filters['user_id'] == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Severity Level</label>
                    <select name="severity_level" class="form-select">
                        <option value="">All Levels</option>
                        <option value="1" <?php echo $filters['severity_level'] === '1' ? 'selected' : ''; ?>>Level 1 - Minor</option>
                        <option value="2" <?php echo $filters['severity_level'] === '2' ? 'selected' : ''; ?>>Level 2 - Moderate</option>
                        <option value="3" <?php echo $filters['severity_level'] === '3' ? 'selected' : ''; ?>>Level 3 - Major</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <a href="<?php echo $baseDir; ?>/modules/admin/performance.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
                <div class="mt-3">
                    <small class="text-muted">
                        <strong>Scoring System:</strong> 
                        Level 1 (Minor): 0.25-0.75 points | 
                        Level 2 (Moderate): 0.75-1.50 points | 
                        Level 3 (Major): 2.00-3.00 points
                    </small>
                </div>
                
                <div class="row align-items-center mt-3 mb-1">
                    <div class="col-12 col-md-5">
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Search by name or project...">
                        </div>
                    </div>
                    <!-- Controls aligned properly -->
                    <div class="col-12 col-md-7 d-flex justify-content-end align-items-center gap-2 mt-3 mt-md-0">
                        <div class="btn-group shadow-sm me-2" role="group" aria-label="Group by toggle">
                            <input type="radio" class="btn-check" name="group_by" id="btnGroupUser" value="user" <?php echo $filters['group_by'] === 'user' ? 'checked' : ''; ?> onchange="document.getElementById('filterForm').submit();">
                            <label class="btn btn-outline-primary <?php echo $filters['group_by'] === 'user' ? 'active' : ''; ?>" for="btnGroupUser">
                                <i class="fas fa-user me-1"></i> User
                            </label>

                            <input type="radio" class="btn-check" name="group_by" id="btnGroupProject" value="project" <?php echo $filters['group_by'] === 'project' ? 'checked' : ''; ?> onchange="document.getElementById('filterForm').submit();">
                            <label class="btn btn-outline-primary <?php echo $filters['group_by'] === 'project' ? 'active' : ''; ?>" for="btnGroupProject">
                                <i class="fas fa-project-diagram me-1"></i> Project
                            </label>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Performance Table -->
    <div class="card mb-4 shadow-sm border-0">
        <div class="card-header bg-gradient-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-table me-2"></i> 
                <?php echo $filters['group_by'] === 'project' ? 'Project Performance Summary' : 'User Performance Summary'; ?>
            </h5>
            <span class="badge bg-light text-primary rounded-pill px-3 py-2">
                <?php echo count($performanceData); ?> <?php echo $filters['group_by'] === 'project' ? 'Projects' : 'Users'; ?> Found
            </span>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($performanceData)): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 performance-table">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3 border-0"><?php echo $filters['group_by'] === 'project' ? 'Project' : 'User Info'; ?></th>
                            <?php if ($filters['group_by'] === 'project'): ?>
                            <th class="text-center border-0">Users</th>
                            <?php endif; ?>
                            <th class="text-center border-0">Grade</th>
                            <th class="border-0" style="min-width: 150px;">Score</th>
                            <th class="text-center border-0">Error Rate</th>
                            <th class="text-center border-0">Total Points</th>
                            <th class="text-center border-0">Comments</th>
                            <th class="text-center border-0">Issues</th>
                            <th class="text-center border-0">Changes</th>
                            <th class="text-center border-0">Pending</th>
                            <?php if ($filters['group_by'] === 'user'): ?>
                            <th class="text-center border-0">Projects</th>
                            <?php endif; ?>
                            <th class="pe-3 text-end border-0">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($performanceData as $data): ?>
                        <?php $rowId = $filters['group_by'] === 'project' ? $data['project_id'] : $data['user_id']; ?>
                        <tr>
                            <td class="ps-3">
                                <?php if ($filters['group_by'] === 'project'): ?>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle bg-primary-light text-primary-dark border border-primary-subtle me-3 d-flex align-items-center justify-content-center">
                                            <i class="fas fa-project-diagram"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold">
                                                <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $data['project_id']; ?>" class="text-decoration-none text-dark" target="_blank">
                                                    <?php echo htmlspecialchars($data['project_title']); ?>
                                                </a>
                                            </h6>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle bg-<?php echo $data['grade_color']; ?>-light text-<?php echo $data['grade_color']; ?>-dark border border-<?php echo $data['grade_color']; ?>-subtle me-3">
                                            <?php echo strtoupper(substr($data['full_name'], 0, 1) . substr(explode(' ', $data['full_name'])[1] ?? '', 0, 1)); ?>
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($data['full_name']); ?></h6>
                                            <div class="d-flex align-items-center">
                                                <small class="text-muted me-2">@<?php echo htmlspecialchars($data['username']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php if ($filters['group_by'] === 'project'): ?>
                            <td class="text-center">
                                <span class="text-dark fw-bold fs-6"><i class="fas fa-users text-primary me-2"></i><?php echo $data['total_resources']; ?></span>
                            </td>
                            <?php endif; ?>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $data['grade_color']; ?> fs-6 rounded-circle p-2" style="width: 35px; height: 35px; display: inline-flex; align-items: center; justify-content: center;">
                                    <?php echo $data['grade']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="progress w-100 me-2" style="height: 8px;">
                                        <div class="progress-bar bg-<?php echo $data['grade_color']; ?>" 
                                             style="width: <?php echo $data['performance_score']; ?>%">
                                        </div>
                                    </div>
                                    <span class="fw-bold fs-6 text-<?php echo $data['grade_color']; ?>-dark ms-1"><?php echo $data['performance_display'] ?? $data['performance_score'] . '%'; ?></span>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border">
                                    <?php echo $data['error_rate']; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <strong class="text-danger"><?php echo number_format($data['total_error_points'], 2); ?></strong>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info bg-opacity-25 text-dark fw-bold border border-info rounded-pill px-3 py-2 fs-6 shadow-sm"><?php echo $data['total_comments']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary bg-opacity-25 text-dark fw-bold border border-primary rounded-pill px-3 py-2 fs-6 shadow-sm"><?php echo $data['total_issues']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-warning bg-opacity-25 text-dark fw-bold border border-warning rounded-pill px-3 py-2 fs-6 shadow-sm"><?php echo $data['issues_with_changes']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary bg-opacity-25 text-dark fw-bold border border-secondary rounded-pill px-3 py-2 fs-6 shadow-sm"><?php echo $data['issues_pending_qa']; ?></span>
                            </td>
                            <?php if ($filters['group_by'] === 'user'): ?>
                            <td class="text-center">
                                <span class="text-dark fw-bold fs-6"><i class="far fa-folder-open text-primary me-2"></i><?php echo $data['total_projects']; ?></span>
                            </td>
                            <?php endif; ?>
                            <td class="pe-3 text-end">
                                <button class="btn btn-sm btn-outline-primary" aria-expanded="false" onclick="toggleMainRow(<?php echo $rowId; ?>, this, '<?php echo $filters['group_by']; ?>')">
                                    <i class="fas fa-project-diagram me-1"></i> Breakdown
                                    <i class="fas fa-chevron-down ms-1 transition-icon"></i>
                                </button>
                            </td>
                        </tr>
                        <!-- Expandable Breakdown Row (Projects for User, or Users for Project) -->
                        <tr class="qa-breakdown-row bg-light" id="main-breakdown-container-<?php echo $rowId; ?>" style="display: none;">
                            <td colspan="<?php echo $filters['group_by'] === 'project' ? '11' : '11'; ?>" class="p-4 border-bottom shadow-inner">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0 text-primary">
                                            <i class="fas <?php echo $filters['group_by'] === 'project' ? 'fa-users' : 'fa-folder-open'; ?> me-2"></i>
                                            <?php echo $filters['group_by'] === 'project' ? 'Users in ' . htmlspecialchars($data['project_title']) : 'Projects for ' . htmlspecialchars($data['full_name']); ?>
                                        </h6>
                                    </div>
                                    <div class="card-body p-0">
                                        <div id="main-breakdown-data-<?php echo $rowId; ?>" class="sub-table-container">
                                            <div class="text-center text-muted py-4">
                                                <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                                                <div>Loading data...</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-5 bg-white border rounded">
                <i class="fas fa-inbox fa-3x text-muted mb-3 opacity-50"></i>
                <h5 class="text-muted">No Data Found</h5>
                <p class="text-muted mb-0">No performance data matches the selected filters.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Performance Insights -->
    <div class="row mt-4 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="fas fa-lightbulb"></i> Performance Insights</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6 class="text-success">Top Performers</h6>
                            <ul class="list-unstyled">
                                <?php
                                // Top performers: performance score >= 80% and not in other categories
                                $topPerformers = array_filter($performanceData, function($d) {
                                    return $d['performance_score'] >= 80 && (float)$d['error_rate'] <= 1.5;
                                });
                                foreach (array_slice($topPerformers, 0, 5) as $performer):
                                ?>
                                <li class="mb-1">
                                    <i class="fas fa-trophy text-warning"></i>
                                    <strong><?php echo htmlspecialchars($performer['full_name'] ?? $performer['project_title']); ?></strong>
                                    - <?php echo $performer['performance_display'] ?? $performer['performance_score'] . '%'; ?> (<?php echo $performer['grade']; ?>)
                                </li>
                                <?php endforeach; ?>
                                <?php if (empty($topPerformers)): ?>
                                <li class="text-muted"><i class="fas fa-info-circle text-info"></i> No top performers yet!</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-warning">Needs Attention</h6>
                            <ul class="list-unstyled">
                                <?php
                                // Needs attention: performance score < 80% but error rate <= 1.5
                                $needsAttention = array_filter($performanceData, function($d) {
                                    return $d['performance_score'] < 80 && (float)$d['error_rate'] <= 1.5;
                                });
                                foreach (array_slice($needsAttention, 0, 5) as $resource):
                                ?>
                                <li class="mb-1">
                                    <i class="fas fa-exclamation-circle text-warning"></i>
                                    <strong><?php echo htmlspecialchars($resource['full_name'] ?? $resource['project_title']); ?></strong>
                                    - <?php echo $resource['performance_display'] ?? $resource['performance_score'] . '%'; ?> (<?php echo $resource['grade']; ?>)
                                </li>
                                <?php endforeach; ?>
                                <?php if (empty($needsAttention)): ?>
                                <li class="text-muted"><i class="fas fa-check-circle text-success"></i> All entities performing well!</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-danger">High Error Rate</h6>
                            <ul class="list-unstyled">
                                <?php
                                $highErrors = array_filter($performanceData, function($d) {
                                    return (float)$d['error_rate'] > 1.5;
                                });
                                usort($highErrors, function($a, $b) {
                                    return (float)$b['error_rate'] <=> (float)$a['error_rate'];
                                });
                                foreach (array_slice($highErrors, 0, 3) as $resource):
                                ?>
                                <li class="mb-1">
                                    <i class="fas fa-times-circle text-danger"></i>
                                    <strong><?php echo htmlspecialchars($resource['full_name'] ?? $resource['project_title']); ?></strong>
                                    - Error Rate: <?php echo $resource['error_rate']; ?>
                                </li>
                                <?php endforeach; ?>
                                <?php if (empty($highErrors)): ?>
                                <li class="text-muted"><i class="fas fa-check-circle text-success"></i> No high error rates detected!</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window._performanceConfig = {
    baseDir: '<?php echo $baseDir; ?>',
    activeFilters: {
        startDate: '<?php echo $filters['start_date']; ?>',
        endDate: '<?php echo $filters['end_date']; ?>',
        projectId: '<?php echo $filters['project_id'] ?? ""; ?>',
        userId: '<?php echo $filters['user_id'] ?? ""; ?>',
        severityLevel: '<?php echo $filters['severity_level'] ?? ""; ?>'
    }
};
</script>
<script src="<?php echo $baseDir; ?>/assets/js/admin-performance.js"></script>


<style>
.sticky-top {
    position: sticky;
    top: 0;
    z-index: 10;
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #0056b3 0%, #007bff 50%, #0d6efd 100%);
    box-shadow: 0 3px 15px rgba(0,123,255,0.4);
    border-bottom: 3px solid rgba(255,255,255,0.2);
}

.avatar-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 1.1rem;
}

.bg-success-light { background-color: rgba(40, 167, 69, 0.15); }
.bg-info-light { background-color: rgba(23, 162, 184, 0.15); }
.bg-warning-light { background-color: rgba(255, 193, 7, 0.15); }
.bg-danger-light { background-color: rgba(220, 53, 69, 0.15); }

.performance-table th {
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #212529;
    border-bottom: 2px solid #dee2e6 !important;
}

.text-success-dark { color: #146c43 !important; }
.text-danger-dark { color: #b02a37 !important; }
.text-warning-dark { color: #997404 !important; }
.text-info-dark { color: #087990 !important; }
.text-primary-dark { color: #0a58ca !important; }

.table-hover > tbody > tr:hover {
    background-color: #f1f3f5;
}

.performance-table > tbody > tr {
    transition: background-color 0.2s ease;
}

.performance-table > tbody > tr:hover {
    background-color: #f8f9fa;
}

.qa-breakdown-row {
    box-shadow: inset 0 3px 6px rgba(0,0,0,0.04);
}

.qa-breakdown-row > td {
    background-color: #f8f9fa;
}

.qa-breakdown-content {
    min-height: 100px;
    max-height: 500px;
    overflow-y: auto;
    overflow-x: hidden;
}

/* Custom scrollbar for breakdown */
.qa-breakdown-content::-webkit-scrollbar,
.issues-container::-webkit-scrollbar {
    width: 6px;
}

.qa-breakdown-content::-webkit-scrollbar-track,
.issues-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.qa-breakdown-content::-webkit-scrollbar-thumb,
.issues-container::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.qa-breakdown-content::-webkit-scrollbar-thumb:hover,
.issues-container::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

.issues-container {
    max-height: 250px;
    overflow-y: auto;
    border-top: 1px solid #eee;
}

.issue-item-new {
    cursor: pointer;
    border-left: 3px solid transparent;
    transition: all 0.2s;
}

.issue-item-new:hover {
    background-color: #f8f9fa;
    border-left-color: #007bff;
}

.transition-icon {
    transition: transform 0.2s;
}

/* Enhanced badges with better contrast */
.badge.bg-danger {
    background-color: #dc3545 !important;
    color: white !important;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(220,53,69,0.3);
}

.badge.bg-success {
    background-color: #198754 !important;
    color: white !important;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(25,135,84,0.3);
}

.badge.bg-info {
    background-color: #0dcaf0 !important;
    color: #000 !important;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(13,202,240,0.3);
}

.badge.bg-warning {
    background-color: #ffc107 !important;
    color: #000 !important;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(255,193,7,0.3);
}

.badge.bg-secondary {
    background-color: #6c757d !important;
    color: white !important;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(108,117,125,0.3);
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; 