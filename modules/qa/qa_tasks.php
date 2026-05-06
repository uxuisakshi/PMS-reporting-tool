<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['qa', 'admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$projectId = (int)($_GET['project_id'] ?? 0);
$filterQaId = (int)($_GET['filter_qa'] ?? ($_POST['filter_qa'] ?? 0));
$filterFtId = (int)($_GET['filter_ft'] ?? ($_POST['filter_ft'] ?? 0));
$filterAtId = (int)($_GET['filter_at'] ?? ($_POST['filter_at'] ?? 0));

if ($projectId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$projectStmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
$projectStmt->execute([$projectId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    header('Location: dashboard.php');
    exit;
}

$redirectParams = ['project_id' => $projectId];
if ($filterQaId > 0) {
    $redirectParams['filter_qa'] = $filterQaId;
}
if ($filterFtId > 0) {
    $redirectParams['filter_ft'] = $filterFtId;
}
if ($filterAtId > 0) {
    $redirectParams['filter_at'] = $filterAtId;
}
$tasksRedirectUrl = 'qa_tasks.php?' . http_build_query($redirectParams);

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

// Handle QA env status update from this page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_env_status'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ' . $tasksRedirectUrl);
        exit;
    }
    $pageId = (int)($_POST['page_id'] ?? 0);
    $environmentId = (int)($_POST['environment_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $allowedStatuses = ['pending', 'na', 'completed'];

    if ($pageId <= 0 || $environmentId <= 0 || !in_array($status, $allowedStatuses, true)) {
        $_SESSION['error'] = 'Invalid status update request.';
        header('Location: ' . $tasksRedirectUrl);
        exit;
    }

    try {
        $rowStmt = $db->prepare("\n            SELECT pe.page_id, pe.environment_id, pe.qa_id, pp.qa_id AS page_qa_id, pp.project_id\n            FROM page_environments pe\n            JOIN project_pages pp ON pp.id = pe.page_id\n            WHERE pe.page_id = ? AND pe.environment_id = ? AND pp.project_id = ?\n            LIMIT 1\n        ");
        $rowStmt->execute([$pageId, $environmentId, $projectId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $_SESSION['error'] = 'Assigned environment not found.';
            header('Location: ' . $tasksRedirectUrl);
            exit;
        }

        $canUpdate = false;
        if (in_array($userRole, ['admin'], true)) {
            $canUpdate = true;
        } else {
            $teamStmt = $db->prepare("\n                SELECT 1\n                FROM user_assignments\n                WHERE project_id = ? AND user_id = ?\n                  AND (is_removed IS NULL OR is_removed = 0)\n                LIMIT 1\n            ");
            $teamStmt->execute([$projectId, $userId]);
            $isProjectQa = (bool)$teamStmt->fetchColumn();

            if ($isProjectQa || (int)($row['qa_id'] ?? 0) === $userId || (int)($row['page_qa_id'] ?? 0) === $userId) {
                $canUpdate = true;
            }
        }

        if (!$canUpdate) {
            $_SESSION['error'] = 'Permission denied for this QA task.';
            header('Location: ' . $tasksRedirectUrl);
            exit;
        }

        $upd = $db->prepare('UPDATE page_environments SET qa_status = ?, last_updated_by = ?, last_updated_at = NOW() WHERE page_id = ? AND environment_id = ?');
        $upd->execute([$status, $userId, $pageId, $environmentId]);

        // Keep page status in sync with environment-level progress.
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

    header('Location: ' . $tasksRedirectUrl);
    exit;
}

$where = ['pp.project_id = ?'];
$params = [$projectId];

if ($userRole === 'qa') {
    $where[] = "(\n        pe.qa_id = ?\n        OR pp.qa_id = ?\n        OR EXISTS (\n            SELECT 1 FROM user_assignments ua\n            WHERE ua.project_id = pp.project_id\n              AND ua.user_id = ?\n              AND (ua.is_removed IS NULL OR ua.is_removed = 0)\n        )\n    )";
    $params[] = $userId;
    $params[] = $userId;
    $params[] = $userId;
}

if ($filterQaId > 0) {
    $where[] = 'COALESCE(pe.qa_id, pp.qa_id) = ?';
    $params[] = $filterQaId;
}
if ($filterFtId > 0) {
    $where[] = 'COALESCE(pe.ft_tester_id, pp.ft_tester_id) = ?';
    $params[] = $filterFtId;
}
if ($filterAtId > 0) {
    $where[] = 'COALESCE(pe.at_tester_id, pp.at_tester_id) = ?';
    $params[] = $filterAtId;
}

$pagesSql = "\n    SELECT\n        pp.id, pp.page_name, pp.url, pp.screen_name, pp.status AS page_status,\n        pe.environment_id, pe.qa_status,\n        te.name AS environment_name, te.browser, te.assistive_tech\n    FROM project_pages pp\n    JOIN page_environments pe ON pp.id = pe.page_id\n    JOIN testing_environments te ON pe.environment_id = te.id\n    WHERE " . implode(' AND ', $where) . "\n    ORDER BY pp.page_name, te.name\n";

$pagesStmt = $db->prepare($pagesSql);
$pagesStmt->execute($params);
$pages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

$filterUsersStmt = $db->prepare("
    SELECT u.id, u.full_name, u.role
    FROM users u
    JOIN (
        SELECT pp.at_tester_id AS uid FROM project_pages pp WHERE pp.project_id = ? AND pp.at_tester_id IS NOT NULL
        UNION
        SELECT pp.ft_tester_id AS uid FROM project_pages pp WHERE pp.project_id = ? AND pp.ft_tester_id IS NOT NULL
        UNION
        SELECT pp.qa_id AS uid FROM project_pages pp WHERE pp.project_id = ? AND pp.qa_id IS NOT NULL
        UNION
        SELECT pe.at_tester_id AS uid
        FROM page_environments pe
        JOIN project_pages pp ON pp.id = pe.page_id
        WHERE pp.project_id = ? AND pe.at_tester_id IS NOT NULL
        UNION
        SELECT pe.ft_tester_id AS uid
        FROM page_environments pe
        JOIN project_pages pp ON pp.id = pe.page_id
        WHERE pp.project_id = ? AND pe.ft_tester_id IS NOT NULL
        UNION
        SELECT pe.qa_id AS uid
        FROM page_environments pe
        JOIN project_pages pp ON pp.id = pe.page_id
        WHERE pp.project_id = ? AND pe.qa_id IS NOT NULL
    ) x ON x.uid = u.id
    WHERE u.role IN ('qa', 'at_tester', 'ft_tester')
    ORDER BY u.full_name
");
$filterUsersStmt->execute([$projectId, $projectId, $projectId, $projectId, $projectId, $projectId]);
$filterUsers = $filterUsersStmt->fetchAll(PDO::FETCH_ASSOC);
$qaFilterUsers = array_values(array_filter($filterUsers, static function ($u) { return ($u['role'] ?? '') === 'qa'; }));
$ftFilterUsers = array_values(array_filter($filterUsers, static function ($u) { return ($u['role'] ?? '') === 'ft_tester'; }));
$atFilterUsers = array_values(array_filter($filterUsers, static function ($u) { return ($u['role'] ?? '') === 'at_tester'; }));

$pageGroupedUrlsMap = [];
if (!empty($pages)) {
    $pageIds = array_values(array_unique(array_map(static function ($r) {
        return (int)($r['id'] ?? 0);
    }, $pages)));
    $pageIds = array_values(array_filter($pageIds, static function ($v) { return $v > 0; }));

    if (!empty($pageIds)) {
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $groupedUrlsSql = "\n            SELECT\n                pp.id AS page_id,\n                GROUP_CONCAT(\n                    DISTINCT COALESCE(NULLIF(gu.url, ''), gu.normalized_url)\n                    ORDER BY COALESCE(NULLIF(gu.url, ''), gu.normalized_url)\n                    SEPARATOR '\\n'\n                ) AS grouped_urls\n            FROM project_pages pp\n            LEFT JOIN grouped_urls gu\n                ON gu.project_id = pp.project_id\n               AND (\n                    gu.url = pp.url\n                    OR gu.normalized_url = pp.url\n                    OR gu.unique_page_id = pp.id\n               )\n            WHERE pp.project_id = ?\n              AND pp.id IN ($placeholders)\n            GROUP BY pp.id\n        ";
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
                    <h2><i class="fas fa-tasks text-info"></i> QA Tasks</h2>
                    <p class="text-muted mb-0">
                        Project: <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                        (<?php echo htmlspecialchars($project['po_number'] ?? ''); ?>)
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
            <?php echo e($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo e($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> Assigned Pages</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                <div class="col-md-3">
                    <label for="filterQa" class="form-label form-label-sm mb-1">QA</label>
                    <select id="filterQa" name="filter_qa" class="form-select form-select-sm">
                        <option value="">All QA</option>
                        <?php foreach ($qaFilterUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>" <?php echo $filterQaId === (int)$u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$u['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="filterFt" class="form-label form-label-sm mb-1">FT Tester</label>
                    <select id="filterFt" name="filter_ft" class="form-select form-select-sm">
                        <option value="">All FT Testers</option>
                        <?php foreach ($ftFilterUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>" <?php echo $filterFtId === (int)$u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$u['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="filterAt" class="form-label form-label-sm mb-1">AT Tester</label>
                    <select id="filterAt" name="filter_at" class="form-select form-select-sm">
                        <option value="">All AT Testers</option>
                        <?php foreach ($atFilterUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>" <?php echo $filterAtId === (int)$u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$u['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Apply Filters</button>
                    <a href="qa_tasks.php?project_id=<?php echo (int)$projectId; ?>" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
                </div>
            </form>

            <?php if (empty($pages)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No QA assignments found for this project.</p>
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
                                    <strong><?php echo htmlspecialchars((string)$page['page_name']); ?></strong>
                                    <?php if (!empty($page['url']) || !empty($page['screen_name'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars((string)($page['url'] ?: $page['screen_name'])); ?></small>
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

                                    $pageStatus = (string)($page['page_status'] ?? 'not_started');
                                    ?>
                                    <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                    <br><small class="text-muted">Page: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $pageStatus))); ?></small>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline-flex align-items-center gap-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="page_id" value="<?php echo (int)$page['id']; ?>">
                                        <input type="hidden" name="environment_id" value="<?php echo (int)$page['environment_id']; ?>">
                                        <input type="hidden" name="filter_qa" value="<?php echo (int)$filterQaId; ?>">
                                        <input type="hidden" name="filter_ft" value="<?php echo (int)$filterFtId; ?>">
                                        <input type="hidden" name="filter_at" value="<?php echo (int)$filterAtId; ?>">
                                        <select name="status" class="form-select form-select-sm" style="min-width: 150px;" aria-label="Update QA environment status">
                                            <option value="pending" <?php echo $qaStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="na" <?php echo $qaStatus === 'na' ? 'selected' : ''; ?>>N/A</option>
                                            <option value="completed" <?php echo $qaStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        </select>
                                        <button type="submit" name="update_env_status" class="btn btn-sm btn-primary">Update</button>
                                    </form>

                                    <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/projects/issues_page_detail.php?project_id=<?php echo (int)$projectId; ?>&page_id=<?php echo (int)$page['id']; ?>"
                                       class="btn btn-sm btn-success">
                                        <i class="fas fa-vial"></i> Review
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