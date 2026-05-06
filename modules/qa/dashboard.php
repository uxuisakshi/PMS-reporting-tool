<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['qa', 'admin']);

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$db = Database::getInstance();
$baseDir = getBaseDir();
$myDevicesStmt = $db->prepare("
    SELECT d.device_name, d.device_type, d.model, d.version, da.assigned_at
    FROM device_assignments da
    JOIN devices d ON d.id = da.device_id
    WHERE da.user_id = ? AND da.status = 'Active'
    ORDER BY da.assigned_at DESC
");
$myDevicesStmt->execute([$userId]);
$myDevices = $myDevicesStmt->fetchAll(PDO::FETCH_ASSOC);

function mapComputedToPageStatus(string $status): string {
    $map = [
        'testing_failed' => 'in_fixing',
        'qa_failed' => 'in_fixing',
        'in_testing' => 'in_progress',
        'tested' => 'needs_review',
        'qa_review' => 'qa_in_progress',
        'not_tested' => 'not_started',
        'on_hold' => 'on_hold',
        'completed' => 'completed',
        'in_progress' => 'in_progress',
        'in_fixing' => 'in_fixing',
        'needs_review' => 'needs_review',
        'qa_in_progress' => 'qa_in_progress',
        'not_started' => 'not_started',
        'pass' => 'qa_in_progress',
        'fail' => 'in_fixing'
    ];
    return $map[$status] ?? 'in_progress';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_env_status'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: dashboard.php');
        exit;
    }
    $pageId = (int)($_POST['page_id'] ?? 0);
    $environmentId = (int)($_POST['environment_id'] ?? 0);
    $projectId = (int)($_POST['project_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    
    // Map old labels/values to new ENUM values supported by DB
    $qaStatusMap = [
        'pending' => 'not_started',
        'na' => 'on_hold',
        'not_started' => 'not_started',
        'on_hold' => 'on_hold',
        'completed' => 'completed',
        'pass' => 'completed'
    ];
    
    if (isset($qaStatusMap[$status])) {
        $status = $qaStatusMap[$status];
    }

    $allowedStatuses = ['not_started', 'in_progress', 'completed', 'on_hold', 'needs_review'];

    if ($pageId <= 0 || $environmentId <= 0 || $projectId <= 0 || !in_array($status, $allowedStatuses, true)) {
        $_SESSION['error'] = 'Invalid status update request.';
        header('Location: dashboard.php');
        exit;
    }

    try {
        $rowStmt = $db->prepare("
            SELECT pe.page_id, pe.environment_id, pe.qa_id, pp.qa_id AS page_qa_id, pp.project_id
            FROM page_environments pe
            JOIN project_pages pp ON pp.id = pe.page_id
            WHERE pe.page_id = ? AND pe.environment_id = ? AND pp.project_id = ?
            LIMIT 1
        ");
        $rowStmt->execute([$pageId, $environmentId, $projectId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $_SESSION['error'] = 'Assigned environment not found.';
            header('Location: dashboard.php');
            exit;
        }

        $canUpdate = false;
        if (in_array($userRole, ['admin'], true)) {
            $canUpdate = true;
        } else {
            $teamStmt = $db->prepare("
                SELECT 1
                FROM user_assignments
                WHERE project_id = ? AND user_id = ?
                  AND (is_removed IS NULL OR is_removed = 0)
                LIMIT 1
            ");
            $teamStmt->execute([$projectId, $userId]);
            $isProjectQa = (bool)$teamStmt->fetchColumn();

            if ($isProjectQa || (int)($row['qa_id'] ?? 0) === (int)$userId || (int)($row['page_qa_id'] ?? 0) === (int)$userId) {
                $canUpdate = true;
            }
        }

        if (!$canUpdate) {
            $_SESSION['error'] = 'Permission denied for this QA task.';
            header('Location: dashboard.php');
            exit;
        }

        $upd = $db->prepare('UPDATE page_environments SET qa_status = ?, last_updated_by = ?, last_updated_at = NOW() WHERE page_id = ? AND environment_id = ?');
        $upd->execute([$status, $userId, $pageId, $environmentId]);

        $pageStmt = $db->prepare('SELECT * FROM project_pages WHERE id = ? LIMIT 1');
        $pageStmt->execute([$pageId]);
        $pageData = $pageStmt->fetch(PDO::FETCH_ASSOC);
        if ($pageData) {
            $computed = computePageStatus($db, $pageData);
            $mappedStatus = mapComputedToPageStatus($computed);
            $db->prepare('UPDATE project_pages SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$mappedStatus, $pageId]);
        }

        logActivity($db, $userId, 'update_qa_env_status', 'project', $projectId, [
            'page_id' => $pageId,
            'environment_id' => $environmentId,
            'status' => $status
        ]);

        $_SESSION['success'] = 'QA environment status updated successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error updating status: ' . $e->getMessage();
    }

    header('Location: dashboard.php');
    exit;
}

// Get QA's assigned pages
if (hasAdminPrivileges()) {
    // Admin can see all pages that need QA
    $pages = $db->prepare("
        SELECT pp.*, p.title as project_title, p.priority,
               at_user.full_name as at_tester_name,
               ft_user.full_name as ft_tester_name,
               tr.status as test_status, tr.comments as test_comments
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        LEFT JOIN users at_user ON pp.at_tester_id = at_user.id
        LEFT JOIN users ft_user ON pp.ft_tester_id = ft_user.id
        LEFT JOIN testing_results tr ON pp.id = tr.page_id AND tr.tester_role IN ('at_tester', 'ft_tester')
        WHERE p.status NOT IN ('completed', 'cancelled')
        AND (pp.status IS NULL OR LOWER(pp.status) NOT IN ('on_hold', 'hold', 'completed'))
        ORDER BY 
            CASE p.priority 
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            pp.created_at
    ");
    $pages->execute();
    $pagesList = $pages->fetchAll();
} else {
    $pages = $db->prepare("
        SELECT pp.*, p.title as project_title, p.priority,
               at_user.full_name as at_tester_name,
               ft_user.full_name as ft_tester_name,
               tr.status as test_status, tr.comments as test_comments
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        LEFT JOIN users at_user ON pp.at_tester_id = at_user.id
        LEFT JOIN users ft_user ON pp.ft_tester_id = ft_user.id
        LEFT JOIN testing_results tr ON pp.id = tr.page_id AND tr.tester_role IN ('at_tester', 'ft_tester')
        WHERE (
            pp.qa_id = ? 
            OR EXISTS (
                SELECT 1 FROM page_environments pe 
                WHERE pe.page_id = pp.id AND pe.qa_id = ?
            )
            OR EXISTS (
                SELECT 1 FROM user_assignments ua 
                WHERE ua.project_id = pp.project_id 
                  AND ua.user_id = ? 
                  AND (ua.is_removed IS NULL OR ua.is_removed = 0)
            )
        )
        AND p.status NOT IN ('completed', 'cancelled')
        AND (pp.status IS NULL OR LOWER(pp.status) NOT IN ('on_hold', 'hold', 'completed'))
        ORDER BY 
            CASE p.priority 
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            pp.created_at
    ");
    $pages->execute([$userId, $userId, $userId]);
    $pagesList = $pages->fetchAll();
}

// Get QA stats
if (hasAdminPrivileges()) {
    // Admin sees stats for all QA work
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN pp.status IS NULL OR LOWER(pp.status) NOT IN ('on_hold', 'hold', 'completed') THEN pp.id END) as pending_pages,
            COUNT(DISTINCT CASE WHEN pp.status = 'completed' THEN pp.id END) as completed_pages,
            COUNT(DISTINCT CASE WHEN pp.status = 'in_fixing' THEN pp.id END) as fixing_pages,
            COUNT(DISTINCT pp.project_id) as total_assigned_projects
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE p.status NOT IN ('cancelled')
    ");
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get Active Projects Count for admin (all active projects)
    $projStats = $db->prepare("
        SELECT COUNT(*) 
        FROM projects p 
        WHERE p.status NOT IN ('completed', 'cancelled')
    ");
    $projStats->execute();
    $stats['active_projects'] = $projStats->fetchColumn();
} else {
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN pp.status IS NULL OR LOWER(pp.status) NOT IN ('on_hold', 'hold', 'completed') THEN pp.id END) as pending_pages,
            COUNT(DISTINCT CASE WHEN pp.status = 'completed' THEN pp.id END) as completed_pages,
            COUNT(DISTINCT CASE WHEN pp.status = 'in_fixing' THEN pp.id END) as fixing_pages,
            COUNT(DISTINCT pp.project_id) as total_assigned_projects
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        LEFT JOIN page_environments pe ON pp.id = pe.page_id
        WHERE (
            pp.qa_id = ? 
            OR pe.qa_id = ?
            OR EXISTS (
                SELECT 1 FROM user_assignments ua 
                WHERE ua.project_id = pp.project_id 
                  AND ua.user_id = ? 
                  AND (ua.is_removed IS NULL OR ua.is_removed = 0)
            )
        )
        AND p.status NOT IN ('cancelled')
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get Active Projects Count (from user_assignments) - refined to not include completed/cancelled
    $projStats = $db->prepare("
        SELECT COUNT(*) 
        FROM user_assignments ua 
        JOIN projects p ON ua.project_id = p.id 
        WHERE ua.user_id = ? 
        AND p.status NOT IN ('completed', 'cancelled')
        AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    ");
    $projStats->execute([$userId]);
    $stats['active_projects'] = $projStats->fetchColumn();
}

// Get Completed Projects Count
$compProjStats = $db->prepare("
    SELECT COUNT(*) 
    FROM user_assignments ua 
    JOIN projects p ON ua.project_id = p.id 
    WHERE ua.user_id = ? 
    AND p.status = 'completed'
    AND (ua.is_removed IS NULL OR ua.is_removed = 0)
");
$compProjStats->execute([$userId]);
$stats['completed_projects'] = $compProjStats->fetchColumn();


// Get Active Projects List (ONLY active/in-progress, limit 5)
$assignedProjectsQuery = "
    SELECT DISTINCT p.id, p.title, p.po_number, p.status, p.project_type,
           COUNT(DISTINCT pp.id) as total_pages,
           COUNT(DISTINCT CASE WHEN pp.qa_id = ? THEN pp.id END) as assigned_pages,
           COUNT(DISTINCT CASE WHEN pp.status = 'completed' AND pp.qa_id = ? THEN pp.id END) as completed_pages
    FROM projects p
    JOIN user_assignments ua ON p.id = ua.project_id
    LEFT JOIN project_pages pp ON p.id = pp.project_id
    WHERE ua.user_id = ?
    AND p.status IN ('in_progress', 'planning')
    AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    GROUP BY p.id, p.title, p.po_number, p.status, p.project_type
    ORDER BY p.created_at DESC
    LIMIT 5
";

$assignedProjects = $db->prepare($assignedProjectsQuery);
$assignedProjects->execute([$userId, $userId, $userId]);
$activeProjects = $assignedProjects->fetchAll();

// Pending QA rows (same layout intent as qa_tasks.php table)
$qaPendingWhere = [
    "p.status != 'cancelled'",
    "(pp.status IS NULL OR LOWER(pp.status) NOT IN ('completed', 'in_fixing', 'on_hold', 'hold'))"
];
$qaPendingParams = [];

if (!hasAdminPrivileges()) {
    $qaPendingWhere[] = "(
        pe.qa_id = ?
        OR pp.qa_id = ?
        OR EXISTS (
            SELECT 1 FROM user_assignments ua
            WHERE ua.project_id = pp.project_id
              AND ua.user_id = ?
              AND (ua.is_removed IS NULL OR ua.is_removed = 0)
        )
    )";
    $qaPendingParams[] = $userId;
    $qaPendingParams[] = $userId;
    $qaPendingParams[] = $userId;
}

// Pagination for Pending QA Review
$p_perPage = 10;
$p_page = max(1, (int)($_GET['p_page'] ?? 1));
$p_offset = ($p_page - 1) * $p_perPage;

$qaPendingCountSql = "
    SELECT COUNT(*)
    FROM project_pages pp
    JOIN projects p ON pp.project_id = p.id
    JOIN page_environments pe ON pp.id = pe.page_id
    WHERE " . implode(' AND ', $qaPendingWhere);
$qaPendingCountStmt = $db->prepare($qaPendingCountSql);
$qaPendingCountStmt->execute($qaPendingParams);
$qaPendingTotalCount = (int)$qaPendingCountStmt->fetchColumn();
$qaPendingTotalPages = ceil($qaPendingTotalCount / $p_perPage);

$qaPendingSql = "
    SELECT
        pp.id,
        pp.project_id,
        pp.page_name,
        pp.url,
        pp.screen_name,
        pp.status AS page_status,
        p.title AS project_title,
        pe.environment_id,
        pe.qa_status,
        te.name AS environment_name,
        te.browser,
        te.assistive_tech
    FROM project_pages pp
    JOIN projects p ON pp.project_id = p.id
    JOIN page_environments pe ON pp.id = pe.page_id
    JOIN testing_environments te ON pe.environment_id = te.id
    WHERE " . implode(' AND ', $qaPendingWhere) . "
    ORDER BY p.priority, pp.page_name, te.name
    LIMIT $p_perPage OFFSET $p_offset
";
$qaPendingStmt = $db->prepare($qaPendingSql);
$qaPendingStmt->execute($qaPendingParams);
$qaPendingRows = $qaPendingStmt->fetchAll(PDO::FETCH_ASSOC);

$qaPendingGroupedUrlsMap = [];
if (!empty($qaPendingRows)) {
    $qaPendingPageIds = array_values(array_unique(array_map(static function ($r) {
        return (int)($r['id'] ?? 0);
    }, $qaPendingRows)));
    $qaPendingPageIds = array_values(array_filter($qaPendingPageIds, static function ($v) { return $v > 0; }));

    if (!empty($qaPendingPageIds)) {
        $placeholders = implode(',', array_fill(0, count($qaPendingPageIds), '?'));
        $groupedUrlsSql = "
            SELECT
                pp.id AS page_id,
                GROUP_CONCAT(
                    DISTINCT COALESCE(NULLIF(gu.url, ''), gu.normalized_url)
                    ORDER BY COALESCE(NULLIF(gu.url, ''), gu.normalized_url)
                    SEPARATOR '\n'
                ) AS grouped_urls
            FROM project_pages pp
            LEFT JOIN grouped_urls gu
                ON gu.project_id = pp.project_id
               AND (
                    gu.url = pp.url
                    OR gu.normalized_url = pp.url
                    OR gu.unique_page_id = pp.id
               )
            WHERE pp.id IN ($placeholders)
            GROUP BY pp.id
        ";
        $groupedStmt = $db->prepare($groupedUrlsSql);
        $groupedStmt->execute($qaPendingPageIds);
        foreach ($groupedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)($row['page_id'] ?? 0);
            if ($pid > 0) $qaPendingGroupedUrlsMap[$pid] = (string)($row['grouped_urls'] ?? '');
        }
    }
}

?>

<style>
    .clickable-widget {
        transition: transform 0.2s, box-shadow 0.2s;
        cursor: pointer;
    }
    .clickable-widget:hover {
        transform: translateY(-5px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    }
</style>

<?php include __DIR__ . '/../../includes/header.php'; ?>
<style>
.dashboard-no-page-overflow {
    overflow-x: clip;
}
.dashboard-no-page-overflow .table-responsive {
    max-width: 100%;
    max-height: 420px;
    overflow-x: auto;
    overflow-y: auto;
}
.dashboard-no-page-overflow .list-group {
    max-height: 420px;
    overflow-y: auto;
}
</style>
<div class="container-fluid dashboard-no-page-overflow">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>QA Dashboard</h2>
        <a href="page_assignment.php" class="btn btn-primary">
            <i class="fas fa-users-cog"></i> Manage Page Assignments
        </a>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars((string)$_SESSION['success']); unset($_SESSION['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars((string)$_SESSION['error']); unset($_SESSION['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-laptop"></i> My Assigned Devices</h6>
            <a href="<?php echo $baseDir; ?>/modules/devices.php" class="btn btn-sm btn-outline-primary">View Devices</a>
        </div>
        <div class="card-body py-2">
            <?php if (empty($myDevices)): ?>
                <span class="text-muted">No office device assigned.</span>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($myDevices as $dev): ?>
                        <span class="badge bg-light text-dark border">
                            <?php echo htmlspecialchars((string)$dev['device_name']); ?>
                            (<?php echo htmlspecialchars((string)$dev['device_type']); ?>)
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Welcome Card -->
    <div class="card mb-3 bg-light">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <h4>Welcome, <?php echo $_SESSION['full_name']; ?>!</h4>
                    <p class="mb-0">You have <?php echo (int)$stats['pending_pages']; ?> pages pending review.</p>
                </div>
                <div class="col-md-8">
                    <div class="row g-2">
                        <div class="col-md-6 col-lg-3">
                            <a href="qa_tasks.php?tab=pending" class="text-decoration-none">
                                <div class="widget widget-primary clickable-widget h-100 p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="fs-4 mb-0"><?php echo (int)$stats['pending_pages']; ?></h3>
                                            <p class="small text-muted mb-0">Pending</p>
                                        </div>
                                        <i class="fas fa-clock fs-3 text-primary opacity-25 position-static"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <a href="qa_tasks.php?tab=fixing" class="text-decoration-none">
                                <div class="widget widget-danger clickable-widget h-100 p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="fs-4 mb-0"><?php echo (int)$stats['fixing_pages']; ?></h3>
                                            <p class="small text-muted mb-0">In Fixing</p>
                                        </div>
                                        <i class="fas fa-tools fs-3 text-danger opacity-25 position-static"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <a href="my_projects.php" class="text-decoration-none">
                                <div class="widget widget-success clickable-widget h-100 p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="fs-4 mb-0"><?php echo (int)$stats['completed_projects']; ?></h3>
                                            <p class="small text-muted mb-0">Completed</p>
                                        </div>
                                        <i class="fas fa-check-circle fs-3 text-success opacity-25 position-static"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <a href="my_projects.php" class="text-decoration-none">
                                <div class="widget widget-warning clickable-widget h-100 p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="fs-4 mb-0"><?php echo (int)$stats['active_projects']; ?></h3>
                                            <p class="small text-muted mb-0">Active</p>
                                        </div>
                                        <i class="fas fa-project-diagram fs-3 text-warning opacity-25 position-static"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
    
    <!-- Active Projects Table -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-project-diagram"></i> Active Projects</h5>
            <a href="<?php echo $baseDir; ?>/modules/qa/my_projects.php" class="btn btn-sm btn-primary">
                <i class="fas fa-list"></i> View All Projects
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($activeProjects)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No active projects assigned</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Project Title</th>
                                <th>Project Code</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Assigned Pages</th>
                                <th>Completed</th>
                                <th>Progress</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeProjects as $project): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($project['po_number']); ?></td>
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
                                <td><?php echo $project['assigned_pages']; ?></td>
                                <td><?php echo $project['completed_pages']; ?></td>
                                <td>
                                    <?php 
                                    $progress = $project['assigned_pages'] > 0 ? 
                                        round(($project['completed_pages'] / $project['assigned_pages']) * 100) : 0;
                                    ?>
                                    <div class="progress" style="height: 20px; min-width: 100px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%">
                                            <?php echo $progress; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                                       class="btn btn-sm btn-info me-1">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?php echo $baseDir; ?>/modules/qa/qa_tasks.php?project_id=<?php echo $project['id']; ?>" 
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-tasks"></i> Tasks
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    
    <!-- My Regression Tasks -->
    <?php
        // Get regression tasks assigned to this QA user (direct tasks + assignments)
        $regStmt = $db->prepare(
                "SELECT rt.id, rt.project_id, rt.page_id, rt.environment_id,
                    CAST(rt.title AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as title,
                    CAST(rt.description AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as description,
                    rt.assigned_user_id,
                    CAST(rt.assigned_role AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as assigned_role,
                    CAST(rt.phase AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as phase,
                    CAST(rt.status AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as status,
                    rt.created_at,
                    CAST(p.title AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as project_title,
                    CAST(pp.page_name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as page_name,
                    CAST(e.name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as env_name
            FROM regression_tasks rt
            LEFT JOIN projects p ON rt.project_id = p.id
            LEFT JOIN project_pages pp ON rt.page_id = pp.id
            LEFT JOIN testing_environments e ON rt.environment_id = e.id
            WHERE (rt.assigned_user_id = ? OR rt.assigned_role = ?
               OR EXISTS (SELECT 1 FROM assignments a WHERE a.task_type = 'regression' AND a.assigned_user_id = ? AND (
                   (a.page_id IS NOT NULL AND a.page_id = rt.page_id) OR
                   (a.environment_id IS NOT NULL AND a.environment_id = rt.environment_id) OR
                   (a.project_id IS NOT NULL AND a.project_id = rt.project_id)
               )))

            UNION ALL

                 SELECT CAST(CONCAT('assign-', a.id) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as id, a.project_id, a.page_id, a.environment_id,
                     CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.meta, '$.title')), 'Regression Assignment') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as title,
                       CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as description,
                       a.assigned_user_id,
                       CAST(a.assigned_role AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as assigned_role,
                       CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as phase,
                       CAST('assigned' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as status,
                       a.created_at,
                     CAST(p.title AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as project_title, CAST(pp.page_name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as page_name, CAST(e.name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as env_name
            FROM assignments a
            LEFT JOIN projects p ON a.project_id = p.id
            LEFT JOIN project_pages pp ON a.page_id = pp.id
            LEFT JOIN testing_environments e ON a.environment_id = e.id
            WHERE a.task_type = 'regression' AND a.assigned_user_id = ?

            ORDER BY created_at DESC"
        );
        $regStmt->execute([$userId, $userRole, $userId, $userId]);
        $regTasks = $regStmt->fetchAll();
    ?>
    <div class="card mb-3">
        <div class="card-header">
            <h5>My Regression Tasks</h5>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($regTasks)): ?>
            <table class="table table-sm mb-0">
                <thead><tr><th>Title</th><th>Project</th><th>Page / Env</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                <?php foreach ($regTasks as $rt): ?>
                <tr>
                    <?php
                        $projId = $rt['project_id'] ?? ''; 
                        $projLink = $baseDir . '/modules/projects/view.php?id=' . urlencode($projId) . '#regression';
                        $rawId = (string)($rt['id'] ?? '');
                        if (strpos($rawId, 'assign-') === 0) {
                            $assignId = intval(substr($rawId, 7));
                            $taskLink = $baseDir . '/modules/projects/view.php?id=' . urlencode($projId) . '&open_reg_assignment=' . $assignId . '#regression';
                        } else {
                            $taskLink = $baseDir . '/modules/projects/view.php?id=' . urlencode($projId) . '&open_reg_task=' . urlencode($rawId) . '#regression';
                        }
                    ?>
                    <td><a href="<?php echo htmlspecialchars($taskLink); ?>"><?php echo htmlspecialchars($rt['title']); ?></a></td>
                    <td><a href="<?php echo htmlspecialchars($projLink); ?>"><?php echo htmlspecialchars($rt['project_title'] ?? '—'); ?></a></td>
                    <td><?php echo htmlspecialchars($rt['page_name'] ?? $rt['env_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($rt['status']); ?></td>
                    <td><?php echo date('M d, H:i', strtotime($rt['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="p-2 text-muted">No regression tasks assigned.</div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Pages for QA Review -->
    <div class="card">
        <div class="card-header">
            <h5>Pages Pending QA Review</h5>
        </div>
        <div class="card-body">
            <?php if (!empty($qaPendingRows)): ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Page Name</th>
                            <th>Grouped URLs</th>
                            <th>Environment</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($qaPendingRows as $page): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$page['page_name']); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars((string)$page['project_title']); ?></small>
                                <?php if (!empty($page['url']) || !empty($page['screen_name'])): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars((string)($page['url'] ?: $page['screen_name'])); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $groupedRaw = trim((string)($qaPendingGroupedUrlsMap[(int)$page['id']] ?? ''));
                                if ($groupedRaw !== ''):
                                    $groupedList = array_values(array_filter(array_map('trim', explode("\n", $groupedRaw))));
                                    $groupedCount = count($groupedList);
                                else:
                                    $groupedList = [];
                                    $groupedCount = 0;
                                endif;
                                ?>
                                <?php if ($groupedCount > 0): ?>
                                    <details>
                                        <summary><?php echo $groupedCount; ?> URL<?php echo $groupedCount === 1 ? '' : 's'; ?></summary>
                                        <small class="text-muted d-block mt-1"><?php echo htmlspecialchars(implode("\n", $groupedList)); ?></small>
                                    </details>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$page['environment_name']); ?></strong>
                                <?php if (!empty($page['browser'])): ?>
                                    <br><small class="text-muted">Browser: <?php echo htmlspecialchars((string)$page['browser']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($page['assistive_tech'])): ?>
                                    <br><small class="text-muted">AT: <?php echo htmlspecialchars((string)$page['assistive_tech']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $qaStatusRaw = strtolower(trim((string)($page['qa_status'] ?? 'pending')));
                                $qaStatus = in_array($qaStatusRaw, ['pending', 'na', 'completed'], true) ? $qaStatusRaw : 'pending';
                                $statusClass = 'secondary';
                                $statusText = 'Pending';
                                if ($qaStatus === 'completed') {
                                    $statusClass = 'success';
                                    $statusText = 'Completed';
                                } elseif ($qaStatus === 'na') {
                                    $statusClass = 'secondary';
                                    $statusText = 'N/A';
                                }
                                ?>
                                <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                <br><small class="text-muted">Page: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($page['page_status'] ?? 'not_started')))); ?></small>
                            </td>
                            <td>
                                <form method="POST" action="dashboard.php" class="d-inline-flex align-items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <input type="hidden" name="page_id" value="<?php echo (int)$page['id']; ?>">
                                    <input type="hidden" name="environment_id" value="<?php echo (int)$page['environment_id']; ?>">
                                    <input type="hidden" name="project_id" value="<?php echo (int)$page['project_id']; ?>">
                                    <select name="status" class="form-select form-select-sm" style="min-width: 150px;" aria-label="Update QA environment status">
                                        <option value="not_started" <?php echo ($qaStatus === 'not_started' || $qaStatus === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                        <option value="on_hold" <?php echo ($qaStatus === 'on_hold' || $qaStatus === 'na') ? 'selected' : ''; ?>>N/A</option>
                                        <option value="completed" <?php echo ($qaStatus === 'completed' || $qaStatus === 'pass') ? 'selected' : ''; ?>>Completed</option>
                                    </select>
                                    <button type="submit" name="update_env_status" class="btn btn-sm btn-primary">Update</button>
                                </form>

                                <a href="<?php echo $baseDir; ?>/modules/projects/issues_page_detail.php?project_id=<?php echo (int)$page['project_id']; ?>&page_id=<?php echo (int)$page['id']; ?>"
                                   class="btn btn-sm btn-success">
                                    <i class="fas fa-vial"></i> Review
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($qaPendingTotalPages > 1): ?>
            <div class="mt-3">
                <nav aria-label="Pending QA review pagination">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <li class="page-item <?php echo $p_page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?p_page=<?php echo $p_page - 1; ?>">Previous</a>
                        </li>
                        <?php 
                        $startPage = max(1, $p_page - 2);
                        $endPage = min($qaPendingTotalPages, $p_page + 2);
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                        <li class="page-item <?php echo $p_page == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?p_page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $p_page >= $qaPendingTotalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?p_page=<?php echo $p_page + 1; ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <div class="text-center text-muted small mt-1">
                    Showing <?php echo $p_offset + 1; ?>-<?php echo min($p_offset + $p_perPage, $qaPendingTotalCount); ?> of <?php echo $qaPendingTotalCount; ?> tasks
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-inbox fa-3x mb-3"></i>
                <p>No QA assignments found.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recent QA Activity -->
    <div class="card mt-3">
        <div class="card-header">
            <h5>My Recent QA Activity</h5>
        </div>
        <div class="card-body">
            <?php
            $qaActivity = $db->prepare("
                SELECT qr.*, pp.page_name, p.title as project_title
                FROM qa_results qr
                JOIN project_pages pp ON qr.page_id = pp.id
                JOIN projects p ON pp.project_id = p.id
                WHERE qr.qa_id = ?
                ORDER BY qr.qa_date DESC
                LIMIT 10
            ");
            $qaActivity->execute([$userId]);
            
            if ($qaActivity->rowCount() > 0):
            ?>
            <div class="list-group">
                <?php while ($activity = $qaActivity->fetch()): ?>
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">
                            <i class="fas fa-check text-<?php echo $activity['status'] === 'pass' ? 'success' : 'danger'; ?>"></i>
                            <?php echo $activity['page_name']; ?> - <?php echo $activity['project_title']; ?>
                        </h6>
                        <small class="text-muted"><?php echo date('M d, H:i', strtotime($activity['qa_date'])); ?></small>
                    </div>
                    <p class="mb-1">Status: 
                        <span class="badge bg-<?php echo $activity['status'] === 'pass' ? 'success' : 'danger'; ?>">
                            <?php echo strtoupper($activity['status']); ?>
                        </span>
                        <?php if ($activity['issues_found'] > 0): ?>
                        | Issues: <span class="badge bg-warning"><?php echo $activity['issues_found']; ?></span>
                        <?php endif; ?>
                        <?php if ($activity['hours_spent'] > 0): ?>
                        | Hours: <span class="badge bg-info"><?php echo $activity['hours_spent']; ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if ($activity['comments']): ?>
                    <small class="text-muted"><?php echo substr($activity['comments'], 0, 100); ?>...</small>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="alert-info">
                <i class="fas fa-info-circle"></i> No QA activity recorded yet.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>



<?php include __DIR__ . '/../../includes/footer.php'; 