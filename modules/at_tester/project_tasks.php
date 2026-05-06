<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['at_tester', 'admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$projectId = (int)($_GET['project_id'] ?? 0);

if (!$projectId) {
    header('Location: dashboard.php');
    exit;
}

// Get project details
$projectQuery = "SELECT * FROM projects WHERE id = ?";
$projectStmt = $db->prepare($projectQuery);
$projectStmt->execute([$projectId]);
$project = $projectStmt->fetch();

if (!$project) {
    $_SESSION['error'] = "Project not found.";
    header('Location: dashboard.php');
    exit;
}

// Handle test result submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_env_status'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }
    $pageId = (int)($_POST['page_id'] ?? 0);
    $environmentId = (int)($_POST['environment_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $allowedStatuses = ['not_started', 'in_progress', 'pass', 'fail', 'on_hold', 'needs_review', 'tested', 'testing_failed', 'in_testing'];

    if ($pageId > 0 && $environmentId > 0 && in_array($status, $allowedStatuses, true)) {
        try {
            $updateStatus = $db->prepare("
                UPDATE page_environments
                SET status = ?
                WHERE page_id = ? AND environment_id = ? AND at_tester_id = ?
            ");
            $updateStatus->execute([$status, $pageId, $environmentId, $userId]);
            $_SESSION['success'] = "Environment status updated successfully.";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error updating status: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Invalid status update request.";
    }

    header("Location: project_tasks.php?project_id=$projectId");
    exit;
}

// Get assigned pages for this project
$pagesQuery = "
    SELECT pp.id, pp.page_name, pp.url, pe.page_id, pe.environment_id, pe.status,
           te.name as environment_name, te.browser, te.assistive_tech
    FROM project_pages pp
    JOIN page_environments pe ON pp.id = pe.page_id
    JOIN testing_environments te ON pe.environment_id = te.id
    WHERE pp.project_id = ? AND pe.at_tester_id = ?
    ORDER BY pp.page_name, te.name
";

$pagesStmt = $db->prepare($pagesQuery);
$pagesStmt->execute([$projectId, $userId]);
$pages = $pagesStmt->fetchAll();

$pageGroupedUrlsMap = [];
if (!empty($pages)) {
    $pageIds = array_values(array_unique(array_map(static function ($r) {
        return (int)($r['id'] ?? 0);
    }, $pages)));
    $pageIds = array_values(array_filter($pageIds, static function ($v) { return $v > 0; }));

    if (!empty($pageIds)) {
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
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
            WHERE pp.project_id = ?
              AND pp.id IN ($placeholders)
            GROUP BY pp.id
        ";
        $groupedStmt = $db->prepare($groupedUrlsSql);
        $groupedStmt->execute(array_merge([$projectId], $pageIds));
        foreach ($groupedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)($row['page_id'] ?? 0);
            if ($pid > 0) $pageGroupedUrlsMap[$pid] = (string)($row['grouped_urls'] ?? '');
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="fas fa-tasks text-primary"></i> AT Testing Tasks</h2>
                    <p class="text-muted mb-0">
                        Project: <strong><?php echo htmlspecialchars($project['title']); ?></strong> 
                        (<?php echo $project['po_number']; ?>)
                    </p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
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

    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> Assigned Pages</h5>
        </div>
        <div class="card-body">
            <?php if (empty($pages)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No pages assigned for AT testing in this project</p>
                </div>
            <?php else: ?>
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
                            <?php foreach ($pages as $page): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($page['page_name']); ?></strong>
                                    <?php if ($page['url']): ?>
                                        <?php
                                        $rawPageUrl = trim((string)$page['url']);
                                        $openUrl = $rawPageUrl;
                                        if ($openUrl !== '' && !preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $openUrl)) {
                                            $openUrl = 'https://' . ltrim($openUrl, '/');
                                        }
                                        ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($openUrl); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $groupedRaw = trim((string)($pageGroupedUrlsMap[(int)$page['id']] ?? ''));
                                    if ($groupedRaw !== ''):
                                        $groupedList = array_values(array_filter(array_map('trim', explode("\n", $groupedRaw))));
                                        $groupedCount = count($groupedList);
                                    ?>
                                        <details>
                                            <summary><?php echo $groupedCount; ?> URL<?php echo $groupedCount === 1 ? '' : 's'; ?></summary>
                                            <small class="text-muted d-block mt-1">
                                                <?php echo htmlspecialchars(implode("\n", $groupedList)); ?>
                                            </small>
                                        </details>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($page['environment_name']); ?></strong>
                                    <?php if ($page['browser']): ?>
                                        <br><small class="text-muted">Browser: <?php echo htmlspecialchars($page['browser']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($page['assistive_tech']): ?>
                                        <br><small class="text-muted">AT: <?php echo htmlspecialchars($page['assistive_tech']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = 'secondary';
                                    $statusText = 'Not Tested';
                                    
                                    if ($page['status'] === 'tested' || $page['status'] === 'pass') {
                                        $statusClass = 'success';
                                        $statusText = ($page['status'] === 'pass') ? 'Pass' : 'Tested';
                                    } elseif ($page['status'] === 'testing_failed' || $page['status'] === 'fail') {
                                        $statusClass = 'danger';
                                        $statusText = 'Failed';
                                    } elseif ($page['status'] === 'in_testing' || $page['status'] === 'in_progress') {
                                        $statusClass = 'warning';
                                        $statusText = 'In Progress';
                                    } elseif ($page['status'] === 'on_hold') {
                                        $statusClass = 'secondary';
                                        $statusText = 'On Hold';
                                    } elseif ($page['status'] === 'needs_review') {
                                        $statusClass = 'info';
                                        $statusText = 'Needs Review';
                                    }
                                    ?>
                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline-flex align-items-center gap-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="page_id" value="<?php echo (int)$page['id']; ?>">
                                        <input type="hidden" name="environment_id" value="<?php echo (int)$page['environment_id']; ?>">
                                        <select name="status" class="form-select form-select-sm" style="min-width: 150px;" aria-label="Update environment status">
                                            <option value="not_started" <?php echo ($page['status'] ?? '') === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                            <option value="in_progress" <?php echo ($page['status'] ?? '') === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="pass" <?php echo ($page['status'] ?? '') === 'pass' ? 'selected' : ''; ?>>Pass</option>
                                            <option value="fail" <?php echo ($page['status'] ?? '') === 'fail' ? 'selected' : ''; ?>>Fail</option>
                                            <option value="on_hold" <?php echo ($page['status'] ?? '') === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                            <option value="needs_review" <?php echo ($page['status'] ?? '') === 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                                        </select>
                                        <button type="submit" name="update_env_status" class="btn btn-sm btn-primary">
                                            Update
                                        </button>
                                    </form>

                                    <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/projects/issues_page_detail.php?project_id=<?php echo (int)$projectId; ?>&page_id=<?php echo (int)$page['id']; ?>"
                                       class="btn btn-sm btn-success">
                                        <i class="fas fa-vial"></i> Test
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
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 