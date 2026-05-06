<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'admin', 'client']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    header('Location: ' . $baseDir . '/index.php');
    exit;
}
$canUpdateIssueQaStatus = hasIssueQaStatusUpdateAccess($db, $userId, $projectId);

// Get project details
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

// Fetch project users
$projectUsersStmt = $db->prepare("
    SELECT DISTINCT u.id, u.full_name, u.username, u.role
    FROM user_assignments ua 
    JOIN users u ON ua.user_id = u.id 
    WHERE ua.project_id = ? 
      AND u.is_active = 1
      AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    UNION
    SELECT u.id, u.full_name, u.username, u.role
    FROM users u
    WHERE u.is_active = 1 AND u.role IN ('admin')
    ORDER BY full_name
");
$projectUsersStmt->execute([$projectId]);
$projectUsers = $projectUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch QA statuses
$qaStatusesStmt = $db->query("SELECT id, status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1 ORDER BY display_order ASC, status_label ASC");
$qaStatuses = $qaStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Issue statuses
$issueStatusesStmt = $db->query("SELECT id, status_key, status_label, badge_color FROM issue_status_master WHERE is_active = 1 ORDER BY display_order ASC, status_label ASC");
$issueStatuses = $issueStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch project pages
$pagesStmt = $db->prepare("SELECT id, page_name, page_number, url FROM project_pages WHERE project_id = ? ORDER BY page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

$projectPageById = [];
$projectPageIdByUrl = [];
foreach ($projectPages as $projectPageRow) {
    $pageRowId = (int)($projectPageRow['id'] ?? 0);
    if ($pageRowId <= 0) {
        continue;
    }
    $projectPageById[$pageRowId] = $projectPageRow;

    $pageUrl = trim((string)($projectPageRow['url'] ?? ''));
    if ($pageUrl !== '') {
        $projectPageIdByUrl[$pageUrl] = $pageRowId;
    }
}

// Environments list for filters
$envList = [];
try {
    $envListStmt = $db->prepare("SELECT id, name FROM testing_environments ORDER BY name");
    $envListStmt->execute();
    $envList = $envListStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $envList = [];
}

// Issue page summaries - Count actual issues from issues table
$issuePageSummaries = [];
try {
    $clientFilter = ($userRole === 'client') ? 'AND i.client_ready = 1' : '';
    $issuePageStmt = $db->prepare("
        SELECT 
            pp.id,
            pp.page_name,
            pp.page_number,
            pp.status,
            (SELECT GROUP_CONCAT(DISTINCT te.name SEPARATOR ', ') 
             FROM page_environments pe2 
             JOIN testing_environments te ON pe2.environment_id = te.id 
             WHERE pe2.page_id = pp.id) AS envs,
            (SELECT GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') 
             FROM users u 
             JOIN page_environments pe3 ON u.id = pe3.at_tester_id OR u.id = pe3.ft_tester_id OR u.id = pe3.qa_id 
             WHERE pe3.page_id = pp.id) AS testers,
            (SELECT COUNT(DISTINCT i.id) 
             FROM issues i 
             WHERE i.project_id = pp.project_id AND (
                 EXISTS (SELECT 1 FROM issue_pages ip WHERE ip.issue_id = i.id AND ip.page_id = pp.id)
                 OR (i.page_id = pp.id AND NOT EXISTS (SELECT 1 FROM issue_pages ip2 WHERE ip2.issue_id = i.id))
             )
             $clientFilter) AS issues_count,
            (SELECT COALESCE(SUM(ptl.hours_spent), 0)
             FROM project_time_logs ptl
             WHERE ptl.page_id = pp.id) AS production_hours
        FROM project_pages pp
        WHERE pp.project_id = ?
        ORDER BY LENGTH(pp.page_number) ASC, CAST(pp.page_number AS UNSIGNED) ASC, pp.page_name ASC
    ");
    $issuePageStmt->execute([$projectId]);
    while ($row = $issuePageStmt->fetch(PDO::FETCH_ASSOC)) {
        $issuePageSummaries[(int)$row['id']] = $row;
    }
} catch (Exception $e) { 
    $issuePageSummaries = []; 
    error_log("Error loading issue summaries: " . $e->getMessage());
}

// Fetch grouped URLs - simplified
$groupedUrls = [];
$urlsByUniqueId = [];
try {
    $groupedStmt = $db->prepare("
        SELECT 
            gu.id AS grouped_id, 
            gu.url, 
            gu.normalized_url, 
            gu.unique_page_id,
            up.id AS unique_id,
            up.page_name AS unique_name,
            up.url AS canonical_url,
            NULL AS mapped_page_id
        FROM grouped_urls gu 
        LEFT JOIN project_pages up ON gu.unique_page_id = up.id
        WHERE gu.project_id = ? 
        ORDER BY gu.url
    ");
    $groupedStmt->execute([$projectId]);
    $groupedUrls = $groupedStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group grouped URLs by their resolved project page.
    foreach ($groupedUrls as $g) {
        $resolvedUniqueId = (int)($g['unique_page_id'] ?? 0);
        if ($resolvedUniqueId <= 0) {
            $resolvedUniqueId = (int)($g['mapped_page_id'] ?? 0);
        }

        if ($resolvedUniqueId <= 0) {
            $groupUrl = trim((string)($g['url'] ?? ''));
            $groupNormalizedUrl = trim((string)($g['normalized_url'] ?? ''));
            if ($groupUrl !== '' && isset($projectPageIdByUrl[$groupUrl])) {
                $resolvedUniqueId = (int)$projectPageIdByUrl[$groupUrl];
            } elseif ($groupNormalizedUrl !== '' && isset($projectPageIdByUrl[$groupNormalizedUrl])) {
                $resolvedUniqueId = (int)$projectPageIdByUrl[$groupNormalizedUrl];
            }
        }

        if ($resolvedUniqueId > 0) {
            $urlsByUniqueId[$resolvedUniqueId][] = $g;
        }
    }
} catch (Exception $e) {
    error_log("Error loading grouped URLs: " . $e->getMessage());
}

// Issues Pages view data - project_pages canonical URL mapping
$uniqueIssuePages = [];
try {
    $uniqueIssueStmt = $db->prepare("
        SELECT 
            up.id AS unique_id,
            up.page_name AS unique_name,
            up.url AS canonical_url,
            up.id AS mapped_page_id,
            up.page_number AS mapped_page_number,
            up.page_name AS mapped_page_name,
            COUNT(DISTINCT gu.id) AS grouped_count
        FROM project_pages up
        LEFT JOIN grouped_urls gu
            ON gu.project_id = up.project_id
           AND (
                gu.unique_page_id = up.id
                OR (
                    up.url IS NOT NULL AND up.url <> ''
                    AND (gu.url = up.url OR gu.normalized_url = up.url)
                )
           )
        WHERE up.project_id = ?
        GROUP BY up.id
        ORDER BY 
            CASE
                WHEN up.page_number LIKE 'Global%' THEN 0
                WHEN up.page_number LIKE 'Page%' THEN 1
                ELSE 2
            END ASC,
            CAST(SUBSTRING_INDEX(up.page_number, ' ', -1) AS UNSIGNED) ASC,
            up.page_number ASC,
            up.page_name ASC
    ");
    $uniqueIssueStmt->execute([$projectId]);
    $uniqueIssuePages = $uniqueIssueStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
    $uniqueIssuePages = []; 
    error_log("Error loading pages: " . $e->getMessage());
}

$displayPageNumberById = [];
foreach ($uniqueIssuePages as &$uniqueIssuePageRow) {
    $displayPageId = (int)($uniqueIssuePageRow['mapped_page_id'] ?? 0) ?: (int)($uniqueIssuePageRow['unique_id'] ?? 0);
    $displayPageNumber = resolvePageDisplayValue($uniqueIssuePageRow);

    if ($displayPageId > 0) {
        $displayPageNumberById[$displayPageId] = $displayPageNumber;
    }
    $uniqueIssuePageRow['display_page_number'] = $displayPageNumber;
}
unset($uniqueIssuePageRow);

// Aggregate totals
$issuesPagesCount = count($uniqueIssuePages);
$issuesTotalCount = 0;
foreach ($uniqueIssuePages as $u) {
    if (isset($u['mapped_page_id']) && isset($issuePageSummaries[$u['mapped_page_id']])) {
        $issuesTotalCount += (int)($issuePageSummaries[$u['mapped_page_id']]['issues_count'] ?? 0);
    }
}

$pageTitle = 'Issues - Pages - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
.issue-image-thumb { max-width: 100%; max-height: 220px; height: auto; object-fit: contain; border-radius: 8px; box-shadow: 0 6px 14px rgba(16, 24, 40, 0.15); cursor: zoom-in; transition: transform 0.2s ease; }
.issue-image-thumb:hover { transform: scale(1.02); }
.modal { z-index: 10550; }
.modal-backdrop { z-index: 10540; }
.select2-container--open .select2-dropdown { z-index: 10600; }
.select2-results__options { max-height: 250px !important; overflow-y: auto !important; }
.resizable-table { table-layout: fixed; width: 100%; min-width: 1200px; }
.resizable-table th { position: relative; overflow: visible; text-overflow: ellipsis; white-space: nowrap; }
.col-resizer { position: absolute; right: 0; top: 0; width: 8px; height: 100%; cursor: col-resize; z-index: 999; background: transparent; border-right: 1px solid rgba(0, 0, 0, 0.2); }
.col-resizer:hover { border-right-color: #007bff; border-right-width: 2px; background: rgba(0, 123, 255, 0.1); }
</style>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <?php if ($_SESSION['role'] !== 'client'): ?>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>">
                        <?php echo htmlspecialchars($project['title']); ?>
                    </a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>">Accessibility Report</a></li>
                    <li class="breadcrumb-item active">Pages</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1">
                        <i class="fas fa-file-alt text-primary me-2"></i>
                        Issues - Pages View
                    </h2>
                    <p class="text-muted mb-0">Pages-wise final issues</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_all.php?project_id=<?php echo $projectId; ?>" class="btn btn-primary btn-sm me-2">
                        <i class="fas fa-list me-1"></i> View All Issues
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_common.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-primary btn-sm me-2">
                        <i class="fas fa-layer-group me-1"></i> Common Issues
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/partials/regression_panel.php'; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Pages</h5>
                <div class="small text-muted"><?php echo count($uniqueIssuePages); ?> total pages</div>
            </div>
        </div>
        <div class="card-body border-bottom">
            <div class="d-flex flex-wrap gap-3">
                <div>
                    <div class="text-muted small">Total Pages</div>
                    <div class="fw-semibold"><?php echo (int)$issuesPagesCount; ?></div>
                </div>
                <div>
                    <div class="text-muted small">Total Issues</div>
                    <div class="fw-semibold"><?php echo (int)$issuesTotalCount; ?></div>
                </div>
            </div>
        </div>

        <div class="row g-3 p-3 border-bottom" id="issuesPagesFiltersRow">
            <div class="col-md-2">
                <label class="form-label small text-muted">Search</label>
                <input id="issuesPagesFilterSearch" class="form-control form-control-sm" placeholder="Search name or URL..." />
            </div>
            <?php if ($_SESSION['role'] !== 'client'): ?>
            <div class="col-md-2">
                <label class="form-label small text-muted">User Filter</label>
                <select id="issuesPagesFilterUser" class="form-select form-select-sm">
                    <option value="">All Users</option>
                    <?php foreach ($projectUsers as $pu): ?>
                        <option value="<?php echo htmlspecialchars($pu['full_name']); ?>"><?php echo htmlspecialchars($pu['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label small text-muted">Environment</label>
                <select id="issuesPagesFilterEnv" class="form-select form-select-sm">
                    <option value="">All Environments</option>
                    <?php foreach ($envList as $env): ?>
                        <option value="<?php echo htmlspecialchars($env['name']); ?>"><?php echo htmlspecialchars($env['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($_SESSION['role'] !== 'client'): ?>
            <div class="col-md-2">
                <label class="form-label small text-muted">QA Filter</label>
                <select id="issuesPagesFilterQa" class="form-select form-select-sm">
                    <option value="">All QA</option>
                    <?php foreach ($projectUsers as $pu): ?>
                        <option value="<?php echo htmlspecialchars($pu['full_name']); ?>"><?php echo htmlspecialchars($pu['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted">Page Status</label>
                <select id="issuesPagesFilterStatus" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="Need Assignment">Need Assignment</option>
                    <option value="Tester Not Assigned">Tester Not Assigned</option>
                    <option value="QA Not Assigned">QA Not Assigned</option>
                    <option value="Not Started">Not Started</option>
                    <option value="Testing In Progress">Testing In Progress</option>
                    <option value="QA In Progress">QA In Progress</option>
                    <option value="Needs Review">Needs Review</option>
                    <option value="In Fixing">In Fixing</option>
                    <option value="On Hold">On Hold</option>
                    <option value="Completed">Completed</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="row g-3 p-3" id="issuesPagesRow">
            <div class="col-lg-12" id="issuesPagesCol">
                <div class="table-responsive" id="issuesPageList">
                    <?php
                    $issuePageEnvStmt = $db->prepare("
                        SELECT pe.status AS env_status, pe.qa_status AS env_qa_status, pe.at_tester_id, pe.ft_tester_id, pe.qa_id,
                               te.name AS env_name,
                               at_u.full_name AS at_name, ft_u.full_name AS ft_name, qa_u.full_name AS qa_name
                        FROM page_environments pe
                        JOIN testing_environments te ON pe.environment_id = te.id
                        LEFT JOIN users at_u ON pe.at_tester_id = at_u.id
                        LEFT JOIN users ft_u ON pe.ft_tester_id = ft_u.id
                        LEFT JOIN users qa_u ON pe.qa_id = qa_u.id
                        WHERE pe.page_id = ?
                        ORDER BY te.name
                    ");
                    ?>
                    <table class="table table-hover table-sm align-middle mb-0 resizable-table">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;">#<div class="col-resizer"></div></th>
                                <th style="width: 100px;">Page No<div class="col-resizer"></div></th>
                                <th>Page Name<div class="col-resizer"></div></th>
                                <th style="width: 100px;">Issues<div class="col-resizer"></div></th>
                                <?php if ($_SESSION['role'] !== 'client'): ?>
                                <th style="width: 150px;">Members<div class="col-resizer"></div></th>
                                <?php endif; ?>
                                <th style="width: 120px;">Environment<div class="col-resizer"></div></th>
                                <?php if ($_SESSION['role'] !== 'client'): ?>
                                <th style="width: 120px;">Prod Hours<div class="col-resizer"></div></th>
                                <th style="width: 150px;">Page Status<div class="col-resizer"></div></th>
                                <?php endif; ?>
                                <th style="width: 120px;">Grouped URLs</th>
                            </tr>
                        </thead>
                        <tbody>
<?php if (!empty($uniqueIssuePages)): 
    $rowNum = 1;
    foreach ($uniqueIssuePages as $u):
    $mappedPageId = (int)($u['mapped_page_id'] ?? 0);
    $sum = $mappedPageId ? ($issuePageSummaries[$mappedPageId] ?? []) : [];
    $tester = trim($sum['testers'] ?? "");
    $envs = trim($sum['envs'] ?? "");
    $count = isset($sum['issues_count']) ? (int)$sum['issues_count'] : 0;
    $prodHours = isset($sum['production_hours']) ? (float)$sum['production_hours'] : 0;
    $uniqueLabel = $u['canonical_url'] ?: ($u['unique_name'] ?? "");
    $pageNoLabel = $u['display_page_number'] ?? ($displayPageNumberById[$mappedPageId] ?? ($u['mapped_page_number'] ?? ""));
    $displayName = $u['mapped_page_name'] ?? "";
    if (!$displayName) { $displayName = $u['unique_name'] ?? $uniqueLabel; }
    $pageUrls = $urlsByUniqueId[$mappedPageId] ?? ($urlsByUniqueId[(int)($u['unique_id'] ?? 0)] ?? []);
    $hasUrls = !empty($pageUrls);
    $urlCount = count($pageUrls);

    $envRows = [];
    if ($mappedPageId > 0) {
        try {
            $issuePageEnvStmt->execute([$mappedPageId]);
            $envRows = $issuePageEnvStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $envRows = [];
        }
    }

    $pageStatusKey = ($mappedPageId > 0 && !empty($sum['status'])) ? (string)$sum['status'] : 'not_started';
    $assignmentGapStatus = computePageAssignmentGapStatusFromEnvRows($envRows);
    if ($assignmentGapStatus !== '') {
        $pageStatusKey = $assignmentGapStatus;
    } elseif (!empty($envRows)) {
        $pageStatusKey = computeAggregatePageStatusFromEnvRows($envRows);
    }
    $pageStatusLabel = formatPageProgressStatusLabel($pageStatusKey);
    $pageStatusBadge = 'secondary';
    if ($pageStatusKey === 'completed') $pageStatusBadge = 'success';
    elseif ($pageStatusKey === 'in_progress') $pageStatusBadge = 'warning text-dark';
    elseif ($pageStatusKey === 'qa_in_progress') $pageStatusBadge = 'info text-dark';
    elseif ($pageStatusKey === 'needs_review') $pageStatusBadge = 'primary';
    elseif ($pageStatusKey === 'in_fixing') $pageStatusBadge = 'danger';
    elseif ($pageStatusKey === 'on_hold') $pageStatusBadge = 'light text-dark border';
    elseif ($pageStatusKey === 'need_assignment') $pageStatusBadge = 'dark';

    $atNames = [];
    $ftNames = [];
    $qaNames = [];
    foreach ($envRows as $er) {
        if (!empty($er['at_tester_id'])) {
            $atNames[] = trim((string)($er['at_name'] ?? ('User #' . (int)$er['at_tester_id'])));
        }
        if (!empty($er['ft_tester_id'])) {
            $ftNames[] = trim((string)($er['ft_name'] ?? ('User #' . (int)$er['ft_tester_id'])));
        }
        if (!empty($er['qa_id'])) {
            $qaNames[] = trim((string)($er['qa_name'] ?? ('User #' . (int)$er['qa_id'])));
        }
    }
    $atNames = array_values(array_unique(array_filter($atNames)));
    $ftNames = array_values(array_unique(array_filter($ftNames)));
    $qaNames = array_values(array_unique(array_filter($qaNames)));
    $testerFilterText = trim(implode(', ', array_unique(array_merge($atNames, $ftNames, $qaNames))));
    $qaFilterText = trim(implode(', ', $qaNames));
?>
                            <tr class="issues-page-row" 
                                data-unique-id="<?php echo (int)$u['unique_id']; ?>"
                                data-page-id="<?php echo (int)$mappedPageId; ?>"
                                data-page-name="<?php echo htmlspecialchars($displayName); ?>"
                                data-page-tester="<?php echo htmlspecialchars($testerFilterText ?: ($tester ?: '-')); ?>"
                                data-page-qa="<?php echo htmlspecialchars($qaFilterText ?: '-'); ?>"
                                data-page-env="<?php echo htmlspecialchars($envs ?: '-'); ?>"
                                data-page-status="<?php echo htmlspecialchars($pageStatusLabel); ?>"
                                data-page-issues="<?php echo $count; ?>"
                                style="cursor: pointer;">
                                <td class="text-muted"><?php echo $rowNum++; ?></td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary">
                                        <?php echo htmlspecialchars($pageNoLabel ?: '-'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-primary"><?php echo htmlspecialchars($displayName); ?></div>
                                    <div class="small text-muted text-truncate" style="max-width: 300px;" title="<?php echo htmlspecialchars($uniqueLabel); ?>">
                                        <?php echo htmlspecialchars($uniqueLabel ?: '-'); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $count > 0 ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary'; ?>">
                                        <?php echo $count; ?>
                                    </span>
                                </td>
                                <?php if ($_SESSION['role'] !== 'client'): ?>
                                <td class="small"><?php echo htmlspecialchars($tester ?: '-'); ?></td>
                                <?php endif; ?>
                                <td class="small"><?php echo htmlspecialchars($envs ?: '-'); ?></td>
                                <?php if ($_SESSION['role'] !== 'client'): ?>
                                <td class="small"><?php echo number_format($prodHours, 2); ?> hrs</td>
                                <td>
                                    <span class="badge bg-<?php echo htmlspecialchars($pageStatusBadge); ?>">
                                        <?php echo htmlspecialchars($pageStatusLabel); ?>
                                    </span>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($hasUrls): ?>
                                    <button class="btn btn-xs btn-outline-secondary" 
                                            type="button" 
                                            data-bs-toggle="collapse" 
                                            data-bs-target="#urls-<?php echo (int)$u['unique_id']; ?>" 
                                            aria-expanded="false">
                                        <i class="fas fa-link me-1"></i> <?php echo $urlCount; ?>
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($hasUrls): ?>
                            <tr class="collapse" id="urls-<?php echo (int)$u['unique_id']; ?>">
                                <td colspan="<?php echo ($_SESSION['role'] === 'client') ? '6' : '9'; ?>" class="p-0 border-0">
                                    <div class="bg-light p-3 border-top">
                                        <div class="small fw-bold text-muted mb-2">
                                            <i class="fas fa-link me-1"></i> Grouped URLs (<?php echo $urlCount; ?>)
                                        </div>
                                        <ul class="list-unstyled mb-0 small">
                                            <?php foreach ($pageUrls as $pUrl): ?>
                                            <li class="mb-1 text-break">
                                                <i class="fas fa-angle-right text-muted me-2"></i>
                                                <?php echo htmlspecialchars($pUrl['url']); ?>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
<?php endforeach; else: ?>
                            <tr>
                                <td colspan="<?php echo ($_SESSION['role'] === 'client') ? '6' : '9'; ?>" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                                    <div>No unique pages added yet.</div>
                                </td>
                            </tr>
<?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
// Include modals from the partial file
include __DIR__ . '/partials/issues_modals.php';
?>

<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
    window._csrfToken = <?php echo json_encode(generateCsrfToken()); ?>;
    
    window.ProjectConfig = {
        projectId: <?php echo json_encode($projectId); ?>,
        userId: <?php echo json_encode($userId); ?>,
        userRole: <?php echo json_encode($userRole); ?>,
        canUpdateIssueQaStatus: <?php echo $canUpdateIssueQaStatus ? 'true' : 'false'; ?>,
        baseDir: '<?php echo $baseDir; ?>',
        projectType: '<?php echo $project['type'] ?? 'web'; ?>',
        projectPages: <?php echo json_encode($projectPages ?? []); ?>,
        uniqueIssuePages: <?php echo json_encode($uniqueIssuePages ?? []); ?>,
        groupedUrls: <?php echo json_encode($groupedUrls ?? []); ?>,
        projectUsers: <?php echo json_encode($projectUsers ?? []); ?>,
        qaStatuses: <?php echo json_encode($qaStatuses ?? []); ?>,
        issueStatuses: <?php echo json_encode($issueStatuses ?? []); ?>
    };
</script>

<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/regression-panel.js?v=<?php echo time(); ?>"></script>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
// Row clicks and Filters for issues_pages.php
(function() {
    $(document).on('click', '.issues-page-row', function(e) {
        // Prevent action if clicking inside a button, link, or input
        if ($(e.target).closest('button, a, input, select').length) return;
        var pageId = $(this).data('page-id');
        var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : null;
        if (projectId && pageId) {
            window.location.href = window.ProjectConfig.baseDir + '/modules/projects/issues_page_detail.php?project_id=' + projectId + '&page_id=' + pageId;
        }
    });

    function updateIssuesPagesNoDataRow() {
        var $tbody = $('#issuesPageList table tbody');
        if (!$tbody.length) return;

        $tbody.find('tr#issuesPagesNoDataRow').remove();
        var visibleCount = $('#issuesPageList .issues-page-row:visible').length;
        if (visibleCount > 0) return;

        $tbody.append(
            '<tr id="issuesPagesNoDataRow">' +
                '<td colspan="9" class="text-center text-muted py-4">' +
                    '<i class="fas fa-search me-2"></i>No data found' +
                '</td>' +
            '</tr>'
        );
    }

    function applyIssuesPagesFilters() {
        var q = ($('#issuesPagesFilterSearch').val() || '').toLowerCase().trim();
        var user = ($('#issuesPagesFilterUser').val() || '').toLowerCase().trim();
        var env = ($('#issuesPagesFilterEnv').val() || '').toLowerCase().trim();
        var qa = ($('#issuesPagesFilterQa').val() || '').toLowerCase().trim();
        var status = ($('#issuesPagesFilterStatus').val() || '').toLowerCase().trim();

        $('#issuesPageList .issues-page-row').each(function() {
            var $row = $(this);
            var name = String($row.data('page-name') || '').toLowerCase();
            var tester = String($row.data('page-tester') || '').toLowerCase();
            var qaText = String($row.data('page-qa') || '').toLowerCase();
            var envText = String($row.data('page-env') || '').toLowerCase();
            var statusText = String($row.data('page-status') || '').toLowerCase();
            var urlText = $row.find('td').eq(2).find('.text-muted').text().toLowerCase();

            var show = true;
            if (q && name.indexOf(q) === -1 && urlText.indexOf(q) === -1) show = false;
            if (user && show && tester.indexOf(user) === -1) show = false;
            if (env && show && envText.indexOf(env) === -1) show = false;
            if (qa && show && qaText.indexOf(qa) === -1) show = false;
            if (status && show && statusText.indexOf(status) === -1) show = false;

            $row.toggle(show);
            var uniqueId = $row.data('unique-id');
            var $collapseRow = $('#urls-' + uniqueId).closest('tr');
            if ($collapseRow.length) $collapseRow.toggle(show);
        });

        updateIssuesPagesNoDataRow();
    }

    $(document).on('input', '#issuesPagesFilterSearch', applyIssuesPagesFilters);
    $(document).on('change', '#issuesPagesFilterUser, #issuesPagesFilterEnv, #issuesPagesFilterQa, #issuesPagesFilterStatus', applyIssuesPagesFilters);
    updateIssuesPagesNoDataRow();
})();

// Column Resizer for Issues Pages Table
(function() {
    var resizableTable = document.querySelector('.resizable-table');
    if (!resizableTable) return;
    
    var resizers = resizableTable.querySelectorAll('.col-resizer');
    var currentResizer = null;
    var currentTh = null;
    var startX = 0;
    var startWidth = 0;
    
    resizers.forEach(function(resizer) {
        resizer.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            currentResizer = this;
            currentTh = this.parentElement;
            startX = e.pageX;
            startWidth = currentTh.offsetWidth;
            
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
        });
    });
    
    function onMouseMove(e) {
        if (!currentTh) return;
        var diff = e.pageX - startX;
        var newWidth = startWidth + diff;
        if (newWidth > 50) {
            currentTh.style.width = newWidth + 'px';
        }
    }
    
    function onMouseUp() {
        currentResizer = null;
        currentTh = null;
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    }
})();
</script>

<?php if ($_SESSION['role'] !== 'client'): ?>
<!-- Floating Project Chat (bottom-right) -->
<style>
.chat-launcher { position: fixed; bottom: 20px; right: 20px; z-index: 1060; border-radius: 999px; box-shadow: 0 10px 24px rgba(0,0,0,0.18); padding: 12px 18px; display: flex; align-items: center; gap: 8px; }
.chat-launcher i { font-size: 1.1rem; }
.chat-widget { position: fixed; bottom: 86px; right: 20px; width: 360px; max-width: 92vw; height: 520px; max-height: 78vh; background: #fff; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,0.25); border: 1px solid #e5e7eb; overflow: hidden; z-index: 1060; display: none; }
.chat-widget.open { display: block; }
.chat-widget iframe { width: 100%; height: calc(100% - 48px); border: 0; }
.chat-widget .chat-widget-header { height: 48px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #0d6efd, #4dabf7); color: #fff; }
.chat-widget .chat-widget-header .btn { color: #fff; border-color: rgba(255,255,255,0.3); }
.chat-widget .chat-widget-header .btn:hover { background: rgba(255,255,255,0.12); }
@media (max-width: 576px) {
    .chat-widget { width: 94vw; height: 70vh; bottom: 76px; right: 3vw; }
    .chat-launcher { bottom: 14px; right: 14px; }
}
</style>

<div class="chat-widget" id="projectChatWidget" aria-label="Project Chat">
    <div class="chat-widget-header">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-comments"></i>
            <strong>Project Chat</strong>
        </div>
        <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetClose" aria-label="Close chat">
                <i class="fas fa-times"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetFullscreen" aria-label="Open full chat">
                <i class="fas fa-up-right-and-down-left-from-center"></i>
            </button>
        </div>
    </div>
    <iframe src="" data-src="<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo (int)$projectId; ?>&embed=1" title="Project Chat"></iframe>
</div>

<button type="button" class="btn btn-primary chat-launcher" id="chatLauncher">
    <i class="fas fa-comments"></i>
    <span>Project Chat</span>
</button>

<script src="<?php echo $baseDir; ?>/assets/js/chat-widget.js?v=<?php echo time(); ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; 