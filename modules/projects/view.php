<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';
require_once __DIR__ . '/../../includes/chat_helpers.php';
require_once __DIR__ . '/../../includes/client_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'admin', 'client']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['id'] ?? 0);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Redirect client users directly to issues page
if ($userRole === 'client' && $projectId) {
    header('Location: ' . $baseDir . '/modules/projects/issues.php?project_id=' . $projectId);
    exit;
}

if (!$projectId) {
    // Redirect to role-specific projects page
    if ($userRole === 'admin') {
        header('Location: ' . $baseDir . '/modules/admin/projects.php');
    } elseif ($userRole === 'project_lead') {
        header('Location: ' . $baseDir . '/modules/project_lead/my_projects.php');
    } elseif ($userRole === 'at_tester') {
        header('Location: ' . $baseDir . '/modules/at_tester/my_projects.php');
    } elseif ($userRole === 'ft_tester') {
        header('Location: ' . $baseDir . '/modules/ft_tester/my_projects.php');
    } elseif ($userRole === 'qa') {
        header('Location: ' . $baseDir . '/modules/qa/my_projects.php');
    } else {
        header('Location: ' . $baseDir . '/index.php');
    }
    exit;
}

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    // Redirect to role-specific projects page
    if ($userRole === 'admin') {
        header('Location: ' . $baseDir . '/modules/admin/projects.php');
    } elseif ($userRole === 'project_lead') {
        header('Location: ' . $baseDir . '/modules/project_lead/my_projects.php');
    } elseif ($userRole === 'at_tester') {
        header('Location: ' . $baseDir . '/modules/at_tester/my_projects.php');
    } elseif ($userRole === 'ft_tester') {
        header('Location: ' . $baseDir . '/modules/ft_tester/my_projects.php');
    } elseif ($userRole === 'qa') {
        header('Location: ' . $baseDir . '/modules/qa/my_projects.php');
    } else {
        header('Location: ' . $baseDir . '/index.php');
    }
    exit;
}
$canUpdateIssueQaStatus = hasIssueQaStatusUpdateAccess($db, $userId, $projectId);

$stmt = $db->prepare(" 
    SELECT 
        p.*,
        c.name as client_name,
        pl.full_name as project_lead_name,
        creator.full_name as created_by_name,
        COUNT(DISTINCT pp.id) as total_pages,
        COUNT(DISTINCT CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN pp.id END) as completed_pages,
        ROUND(COUNT(DISTINCT CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN pp.id END) * 100.0 / NULLIF(COUNT(DISTINCT pp.id), 0), 2) as completion_percentage,
        COUNT(DISTINCT tr.page_id) as total_tests,
        COUNT(DISTINCT qr.page_id) as total_qa
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN users pl ON p.project_lead_id = pl.id
    LEFT JOIN users creator ON p.created_by = creator.id
    LEFT JOIN project_pages pp ON p.id = pp.project_id
    LEFT JOIN testing_results tr ON pp.id = tr.page_id
    LEFT JOIN qa_results qr ON pp.id = qr.page_id
    WHERE p.id = ?
    GROUP BY p.id
");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    // Redirect to role-specific projects page
    if ($userRole === 'admin') {
        header('Location: ' . $baseDir . '/modules/admin/projects.php');
    } elseif ($userRole === 'project_lead') {
        header('Location: ' . $baseDir . '/modules/project_lead/my_projects.php');
    } elseif ($userRole === 'at_tester') {
        header('Location: ' . $baseDir . '/modules/at_tester/my_projects.php');
    } elseif ($userRole === 'ft_tester') {
        header('Location: ' . $baseDir . '/modules/ft_tester/my_projects.php');
    } elseif ($userRole === 'qa') {
        header('Location: ' . $baseDir . '/modules/qa/my_projects.php');
    } else {
        header('Location: ' . $baseDir . '/index.php');
    }
    exit;
}

// Get project hours summary using the same function as manage_assignments
require_once __DIR__ . '/../../includes/hours_validation.php';
$hoursData = getProjectHoursSummary($db, $projectId);

// Calculate hours metrics
$totalHours = $project['total_hours'] ?: 0;
// Budget hours should ALWAYS be from projects.total_hours (fixed budget)
$budgetHours = $totalHours;
// Allocated hours is sum of hours assigned to team members
$allocatedHours = $hoursData['allocated_hours'] ?: 0;
// Utilized hours is sum of actual logged hours
$utilizedHours = $hoursData['utilized_hours'] ?: 0;
// Calculate remaining budget
$availableHours = max(0, $budgetHours - $utilizedHours);
$overshootHours = max(0, ($utilizedHours - $budgetHours));
$utilizationPercentage = $allocatedHours > 0 ? ($utilizedHours / $allocatedHours) * 100 : 0;
$allocationPercentage = $totalHours > 0 ? ($allocatedHours / $totalHours) * 100 : 0;

// Fetch child projects (only if this is a parent)
$childProjects = [];
$isParent = empty($project['parent_project_id']);
if ($isParent) {
    $childStmt = $db->prepare("
        SELECT 
            p.*,
            c.name as client_name,
            pl.full_name as project_lead_name,
            COUNT(DISTINCT pp.id) as total_pages,
            COUNT(DISTINCT CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN pp.id END) as completed_pages,
            ROUND(COUNT(DISTINCT CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN pp.id END) * 100.0 / NULLIF(COUNT(DISTINCT pp.id), 0), 2) as completion_percentage
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        LEFT JOIN users pl ON p.project_lead_id = pl.id
        LEFT JOIN project_pages pp ON p.id = pp.project_id
        WHERE p.parent_project_id = ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $childStmt->execute([$projectId]);
    $childProjects = $childStmt->fetchAll();
}

// Fetch project users (assignments + project lead)
$projectUsersStmt = $db->prepare("SELECT u.id, u.full_name FROM user_assignments ua JOIN users u ON ua.user_id = u.id WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0) UNION SELECT pl.id, pl.full_name FROM projects p JOIN users pl ON p.project_lead_id = pl.id WHERE p.id = ? AND p.project_lead_id IS NOT NULL AND p.project_lead_id NOT IN (SELECT user_id FROM user_assignments WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0))");
$projectUsersStmt->execute([$projectId, $projectId, $projectId]);
$projectUsers = $projectUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread chat count for this project
$unreadChatCount = 0;
try {
    $unreadChatCount = getUnreadChatCount($db, $_SESSION['user_id'], $projectId);
} catch (Exception $e) {
    $unreadChatCount = 0;
}

// Fetch QA statuses from master table
$qaStatusesStmt = $db->query("SELECT id, status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1 ORDER BY display_order ASC, status_label ASC");
$qaStatuses = $qaStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Issue statuses from master table
$issueStatusesStmt = $db->query("SELECT id, status_key, status_label, badge_color FROM issue_status_master WHERE is_active = 1 ORDER BY display_order ASC, status_label ASC");
$issueStatuses = $issueStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

// Team members with roles (used for inline assignment modals)
$teamMemberStmt = $db->prepare("
    SELECT u.id, u.full_name, u.role 
    FROM user_assignments ua 
    JOIN users u ON ua.user_id = u.id 
    WHERE ua.project_id = ? 
      AND ua.role IN ('qa','at_tester','ft_tester','project_lead')
      AND u.is_active = 1
      AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    ORDER BY u.full_name
");
$teamMemberStmt->execute([$projectId]);
$teamMembers = $teamMemberStmt->fetchAll(PDO::FETCH_ASSOC);

// Environment list for assignment modal
$allEnvironments = $db->query("SELECT id, name FROM testing_environments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch project pages
$pagesStmt = $db->prepare("SELECT id, page_name, page_number, url FROM project_pages WHERE project_id = ? ORDER BY page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);
// Natural sort by page_number
usort($projectPages, function($a, $b) {
    $an = $a['page_number'] ?? '';
    $bn = $b['page_number'] ?? '';
    return strnatcasecmp((string)$an, (string)$bn);
});

// Issue page summaries for Issues tab
$issuePageSummaries = [];
try {
    $issuePageStmt = $db->prepare("
        SELECT 
            pp.id,
            pp.page_name,
            (SELECT GROUP_CONCAT(DISTINCT te.name SEPARATOR ', ') FROM page_environments pe2 JOIN testing_environments te ON pe2.environment_id = te.id WHERE pe2.page_id = pp.id) AS envs,
            (SELECT GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') FROM users u JOIN page_environments pe3 ON u.id = pe3.at_tester_id OR u.id = pe3.ft_tester_id OR u.id = pe3.qa_id WHERE pe3.page_id = pp.id) AS testers,
            (SELECT COUNT(DISTINCT i.id) 
             FROM issues i 
             WHERE i.project_id = pp.project_id AND (
                 EXISTS (SELECT 1 FROM issue_pages ip WHERE ip.issue_id = i.id AND ip.page_id = pp.id)
                 OR (i.page_id = pp.id AND NOT EXISTS (SELECT 1 FROM issue_pages ip2 WHERE ip2.issue_id = i.id))
             )
            ) AS issues_count
        FROM project_pages pp
        WHERE pp.project_id = ?
        ORDER BY pp.page_name
    ");
    $issuePageStmt->execute([$projectId]);
    while ($row = $issuePageStmt->fetch(PDO::FETCH_ASSOC)) {
        $issuePageSummaries[(int)$row['id']] = $row;
    }
} catch (Exception $e) { $issuePageSummaries = []; }

// Fetch unique pages (project_pages) and grouped URLs for the project
$uniqueStmt = $db->prepare("SELECT up.id, up.project_id, up.page_name AS name, up.page_number, up.url AS canonical_url, up.screen_name, up.notes, up.created_at, up.status, up.at_tester_id, up.ft_tester_id, up.qa_id, COUNT(gu.id) as url_count FROM project_pages up LEFT JOIN grouped_urls gu ON up.id = gu.unique_page_id WHERE up.project_id = ? GROUP BY up.id ORDER BY up.created_at ASC");
$uniqueStmt->execute([$projectId]);
$uniquePages = $uniqueStmt->fetchAll(PDO::FETCH_ASSOC);

$groupedStmt = $db->prepare("
    SELECT gu.id AS grouped_id, gu.url, gu.normalized_url, gu.unique_page_id,
           COALESCE(gu.unique_page_id, pp_match.id) AS mapped_page_id,
           up.id AS unique_id, up.page_name AS unique_name, up.url AS canonical_url
    FROM grouped_urls gu
    LEFT JOIN project_pages up ON gu.unique_page_id = up.id
    LEFT JOIN project_pages pp_match ON pp_match.project_id = gu.project_id
        AND (pp_match.url = gu.url OR pp_match.url = gu.normalized_url)
    WHERE gu.project_id = ?
    ORDER BY gu.url
");
$groupedStmt->execute([$projectId]);
$groupedUrls = $groupedStmt->fetchAll(PDO::FETCH_ASSOC);

// URLs by unique ID
$urlsByUniqueId = [];
if (!empty($groupedUrls)) {
    foreach ($groupedUrls as $g) {
        if (!empty($g['unique_page_id'])) {
            $urlsByUniqueId[(int)$g['unique_page_id']][] = $g;
        }
    }
}

// Issues Pages view data
$uniqueIssuePages = [];
try {
    $uniqueIssueStmt = $db->prepare("
        SELECT
            up.id AS unique_id,
            up.page_name AS unique_name,
            up.url AS canonical_url,
            COUNT(gu.id) AS grouped_count,
            MIN(pp.id) AS mapped_page_id,
            MIN(pp.page_number) AS mapped_page_number,
            MIN(pp.page_name) AS mapped_page_name
        FROM project_pages up
        LEFT JOIN grouped_urls gu ON gu.project_id = up.project_id AND gu.unique_page_id = up.id
        LEFT JOIN project_pages pp ON pp.project_id = up.project_id AND (pp.url = gu.url OR pp.url = gu.normalized_url OR pp.url = up.url OR pp.page_name = up.page_name OR pp.page_number = up.page_name)
        WHERE up.project_id = ?
        GROUP BY up.id
        ORDER BY up.created_at ASC
    ");
    $uniqueIssueStmt->execute([$projectId]);
    $uniqueIssuePages = $uniqueIssueStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $uniqueIssuePages = []; }

// Aggregate totals for Issues > Pages view
$issuesPagesCount = count($uniqueIssuePages);
$issuesTotalCount = 0;
foreach ($uniqueIssuePages as $u) {
    if (isset($u['mapped_page_id']) && isset($issuePageSummaries[$u['mapped_page_id']])) {
        $issuesTotalCount += (int)($issuePageSummaries[$u['mapped_page_id']]['issues_count'] ?? 0);
    }
}

// Fetch issue metadata fields
$metadataFieldsStmt = $db->query("SELECT id, field_key, field_label, options_json FROM issue_metadata_fields WHERE is_active = 1 ORDER BY sort_order ASC");
$metadataFields = $metadataFieldsStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($metadataFields as &$field) {
    if (!empty($field['options_json'])) {
        $field['options'] = json_decode($field['options_json'], true);
    } else {
        $field['options'] = [];
    }
}

// Load issue statuses for issue modal
$issueStatuses = getIssueStatusesForRole($db, $normalizedUserRole);

// Load project users for issue modal (includes assigned users, project lead, admins, and any existing reporters)
// Load all users for name resolution (ensures historical reporters/QA always resolve correctly)
$projectUsersStmt = $db->prepare("
    SELECT id, full_name, username, role
    FROM users
    ORDER BY full_name
");
$projectUsersStmt->execute();
$projectUsers = $projectUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get current running phase
$currentPhaseStmt = $db->prepare("
    SELECT phase_name, start_date, end_date, status 
    FROM project_phases 
    WHERE project_id = ? AND status = 'in_progress' 
    ORDER BY start_date DESC 
    LIMIT 1
");
$currentPhaseStmt->execute([$projectId]);
$currentPhase = $currentPhaseStmt->fetch(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<!-- Select2 CSS/JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- SheetJS for Excel file reading -->
<script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>

<style>
/* Core Styles */
.timeline-marker { width: 40px; height: 40px; border-radius: 50%; background: #f8f9fa; border: 2px solid #dee2e6; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.timeline-content { padding: 8px 16px; background: #f8f9fa; border-radius: 8px; border-left: 3px solid #007bff; }
.page-toggle-btn { transition: all 0.3s ease; border: 1px solid #dee2e6; background: transparent; }
.page-toggle-btn:hover { background-color: #e9ecef; }
#projectTabsContent { scrollbar-width: thin; scrollbar-color: #6c757d #f8f9fa; }
#projectTabsContent::-webkit-scrollbar { width: 8px; }
#projectTabsContent .table-responsive { 
    max-height: none; 
    overflow-x: auto; 
    overflow-y: visible;
    min-width: 100%;
}
#projectTabsContent .table-responsive thead th { position: sticky; top: 0; z-index: 5; background: #fff; }
.col-resizer { position: absolute; right: 0; top: 0; width: 8px; height: 100%; cursor: col-resize; z-index: 9999; }
.issues-page-list .list-group-item { cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; background: #ffffff; border: 1px solid #e6e9f2; border-left: 5px solid #6c8cff; border-radius: 12px; margin: 10px 0; box-shadow: 0 6px 16px rgba(16, 24, 40, 0.06); }
.issues-page-list .list-group-item:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(16, 24, 40, 0.10); }
.issues-page-list .list-group-item.active { background: #f2f6ff; color: #1b3a8a; border-color: #c6d3ff; border-left-color: #3d6bff; }
.issue-image-thumb { max-width: 100%; max-height: 220px; height: auto; object-fit: contain; border-radius: 8px; box-shadow: 0 6px 14px rgba(16, 24, 40, 0.15); cursor: zoom-in; transition: transform 0.2s ease; }
.issue-image-thumb:hover { transform: scale(1.02); }
.modal { z-index: 10550; }
.modal-backdrop { z-index: 10540; }
.select2-container--open .select2-dropdown { z-index: 10600; }
.select2-results__options { max-height: 250px !important; overflow-y: auto !important; }

/* Grouped URLs collapse styling */
.unique-grouped-list .grouped-url-item {
    font-size: 0.85rem;
    padding: 2px 0;
    word-break: break-all;
}
.unique-grouped-list .btn-link {
    font-size: 0.8rem;
    padding: 0;
}
.unique-grouped-list .when-expanded {
    display: none;
}
.unique-grouped-list .collapse.show ~ button .when-collapsed {
    display: none;
}
.unique-grouped-list .collapse.show ~ button .when-expanded {
    display: inline;
}

/* MAIN PROJECT TABS - keep Bootstrap defaults */
#projectTabsContent {
    min-height: 200px;
}
#projectTabsContent > .tab-pane {
    padding: 1rem;
}

/* Ensure tabs are always reachable (wrap/scroll on smaller widths) */
#projectTabs {
    flex-wrap: wrap;
    row-gap: 0.25rem;
}
#projectTabs .nav-link {
    white-space: nowrap;
}
@media (max-width: 1200px) {
    #projectTabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
    }
}

/* Remove any potential spacing from child elements of hidden tabs */
/* (Bootstrap already handles visibility; keep this block empty on purpose) */

/* Specifically target pages sub-tabs to avoid conflicts */
#pagesSubTabs + .tab-content > .tab-pane {
    display: none !important;
    height: 0;
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
}
#pagesSubTabs + .tab-content > .tab-pane.active {
    display: block !important;
    height: auto;
    overflow: visible;
    opacity: 1;
    visibility: visible;
}

/* Ensure proper Bootstrap tab behavior */
#pagesSubTabs .nav-link.active {
    background-color: #fff;
    border-color: #dee2e6 #dee2e6 #fff;
}

/* Enhanced styling for unique pages filters and buttons */
.btn-group .btn {
    border-radius: 0.375rem;
}
.btn-group .btn:not(:last-child) {
    margin-right: 0.5rem;
}

/* Column resizer functionality */
.resizable-table {
    table-layout: fixed;
    width: 100%;
    min-width: 1200px; /* Ensure table has enough width for all columns */
}
.resizable-table th {
    position: relative;
    overflow: visible;
    text-overflow: ellipsis;
    white-space: nowrap;
}



/* Dropdown column styling */
.resizable-table td.dropdown-cell {
    overflow: visible !important;
    white-space: nowrap;
    width: auto !important;
    min-width: 180px; /* More space for dropdown */
}

.resizable-table td.dropdown-cell select {
    width: 100%;
    min-width: 160px; /* Proper dropdown width */
    font-size: 0.875rem;
    display: block; /* Force dropdown to new line */
    margin-top: 0.25rem;
}


.col-resizer {
    position: absolute;
    right: 0;
    top: 0;
    width: 8px;
    height: 100%;
    cursor: col-resize;
    z-index: 999;
    background: transparent;
    border-right: 1px solid rgba(0, 0, 0, 0.2); /* Subtle black line */
    outline: none;
}
.col-resizer:hover {
    border-right-color: #007bff;
    border-right-width: 2px;
    background: rgba(0, 123, 255, 0.1);
}
.col-resizer.resizing {
    border-right-color: #007bff;
    border-right-width: 2px;
    background: rgba(0, 123, 255, 0.2);
}
.col-resizer.focused {
    border-right-color: #28a745;
    border-right-width: 2px;
    background: rgba(40, 167, 69, 0.1);
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.25);
}
.col-resizer.selected {
    border-right-color: #ffc107;
    border-right-width: 2px;
    background: rgba(255, 193, 7, 0.2);
    box-shadow: 0 0 0 2px rgba(255, 193, 7, 0.5);
}
.col-resizer.focused.selected {
    border-right-color: #fd7e14;
    border-right-width: 2px;
    background: rgba(253, 126, 20, 0.2);
    box-shadow: 0 0 0 2px rgba(253, 126, 20, 0.5);
}


/* Better spacing for filter labels */
.form-label.small {
    font-weight: 500;
    margin-bottom: 0.25rem;
}

/* Improved table styling */
.resizable-table td {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding: 0.5rem 0.75rem;
}

/* Special handling for dropdown columns - keep dropdowns visible */
.resizable-table td.dropdown-cell {
    overflow: visible !important;
    white-space: nowrap;
}

.resizable-table td.dropdown-cell select {
    width: 100%;
    min-width: 120px;
    font-size: 0.875rem;
}
</style>

<?php if ($isParent && count($childProjects) > 0): ?>
    <!-- Sub-Projects Card (collapsed view recommended for huge lists, but showing here) -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Sub-Projects</h5>
            <span class="text-muted">Total <?php echo count($childProjects); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead><tr><th>Code</th><th>Title</th><th>Lead</th><th>Status</th><th>Progress</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($childProjects as $child): 
                            $progress = $child['completion_percentage'] ?? 0;
                            $statusBadge = ($child['status'] === 'completed') ? 'success' : (($child['status'] === 'in_progress') ? 'primary' : 'secondary');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($child['project_code'] ?: $child['po_number']); ?></td>
                            <td><?php echo htmlspecialchars($child['title']); ?></td>
                            <td><?php echo htmlspecialchars($child['project_lead_name'] ?: 'N/A'); ?></td>
                            <td><span class="badge bg-<?php echo $statusBadge; ?>"><?php echo formatProjectStatusLabel($child['status']); ?></span></td>
                            <td>
                                <div class="progress" style="height: 10px; width: 100px;">
                                    <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                                <small><?php echo $progress; ?>%</small>
                            </td>
                            <td><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $child['id']; ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Project Overview Card -->
<div class="card mb-3 shadow-sm">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-lg-8 col-md-7">
                <h2 class="mb-1"><?php echo htmlspecialchars($project['title']); ?> <small class="text-muted">(<?php echo htmlspecialchars($project['po_number'] ?? ''); ?>)</small></h2>
                <div class="mb-2 text-muted small"><?php echo htmlspecialchars($project['client_name'] ?? ''); ?></div>
                <div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
                    <span class="badge bg-light text-dark border">Lead: <?php echo htmlspecialchars($project['project_lead_name'] ?? 'N/A'); ?></span>
                    
                    <?php 
                    // Check if user can update project status (admin, admin, project lead, or has project edit permission)
                    $canUpdateStatus = in_array($userRole, ['admin']) || 
                                      ($userRole === 'project_lead' && $project['project_lead_id'] == $userId) ||
                                      canEditProjectById($db, $userId, $projectId);
                    ?>
                    
                    <?php if ($canUpdateStatus): ?>
                    <!-- Status Dropdown for Admin/Project Lead -->
                    <div class="d-inline-block">
                        <select id="projectStatusDropdown" class="form-select form-select-sm" style="min-width: 150px;" data-project-id="<?php echo $projectId; ?>">
                            <?php 
                            $projectStatuses = getStatusOptions('project');
                            foreach ($projectStatuses as $status): 
                            ?>
                                <option value="<?php echo $status['status_key']; ?>" 
                                    <?php echo $project['status'] === $status['status_key'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status['status_label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <!-- Status Badge for other users -->
                    <span class="badge bg-secondary text-white"><?php echo formatProjectStatusLabel($project['status'] ?? 'Draft'); ?></span>
                    <?php endif; ?>
                    <?php 
                    // Priority badge with appropriate color
                    $priority = $project['priority'] ?? 'medium';
                    $priorityColors = [
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'secondary'
                    ];
                    $priorityIcons = [
                        'critical' => 'fa-exclamation-circle',
                        'high' => 'fa-arrow-up',
                        'medium' => 'fa-minus',
                        'low' => 'fa-arrow-down'
                    ];
                    $priorityColor = $priorityColors[$priority] ?? 'secondary';
                    $priorityIcon = $priorityIcons[$priority] ?? 'fa-flag';
                    ?>
                    <span class="badge bg-<?php echo $priorityColor; ?> text-white">
                        <i class="fas <?php echo $priorityIcon; ?> me-1"></i>
                        Priority: <?php echo ucfirst($priority); ?>
                    </span>
                    <?php if ($currentPhase): ?>
                        <span class="badge bg-primary text-white">
                            <i class="fas fa-play-circle me-1"></i>
                            Current Phase: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $currentPhase['phase_name']))); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($currentPhase && ($currentPhase['start_date'] || $currentPhase['end_date'])): ?>
                <div class="small text-muted mb-2">
                    <i class="fas fa-calendar-alt me-1"></i>
                    <?php if ($currentPhase['start_date']): ?>
                        <strong>Start:</strong> <?php echo date('M d, Y', strtotime($currentPhase['start_date'])); ?>
                    <?php endif; ?>
                    <?php if ($currentPhase['start_date'] && $currentPhase['end_date']): ?>
                        <span class="mx-2">|</span>
                    <?php endif; ?>
                    <?php if ($currentPhase['end_date']): ?>
                        <strong>End:</strong> <?php echo date('M d, Y', strtotime($currentPhase['end_date'])); ?>
                        <?php 
                        $daysRemaining = ceil((strtotime($currentPhase['end_date']) - time()) / 86400);
                        if ($daysRemaining > 0): ?>
                            <span class="badge bg-info text-dark ms-2"><?php echo $daysRemaining; ?> days remaining</span>
                        <?php elseif ($daysRemaining < 0): ?>
                            <span class="badge bg-danger ms-2"><?php echo abs($daysRemaining); ?> days overdue</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark ms-2">Due today</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($project['description'])): ?>
                <div class="mb-2">
                    <div class="small text-muted mb-1"><strong><i class="fas fa-info-circle me-1"></i>Description:</strong></div>
                    <div class="text-muted" style="font-size: 0.95rem; line-height: 1.5;">
                        <?php 
                        // Decode HTML entities and display safely
                        $description = html_entity_decode($project['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        echo nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')); 
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="small text-muted">Created by <strong><?php echo htmlspecialchars($project['created_by_name'] ?? ''); ?></strong> on <?php echo date('M d, Y', strtotime($project['created_at'])); ?></div>
            </div>
            <div class="col-lg-4 col-md-5 mt-3 mt-md-0">
                <?php 
                // Check if user can edit project (admin, admin, or has client permission)
                $canEditProject = in_array($userRole, ['admin'], true) || 
                                 canEditProject($db, $userId, $projectId);
                ?>
                <?php if ($canEditProject): ?>
                <div class="d-flex justify-content-md-end mb-2">
                    <a href="<?php echo $baseDir; ?>/modules/projects/edit.php?id=<?php echo $projectId; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</a>
                </div>
                <?php endif; ?>
                
                <?php 
                $isOvershoot = $overshootHours > 0;
                $remainingHours = $budgetHours - $utilizedHours;
                $usagePercentage = $budgetHours > 0 ? ($utilizedHours / $budgetHours) * 100 : 0;
                ?>
                
                <div class="d-flex justify-content-md-end gap-3" id="projectHoursSummary">
                    <div style="min-width:220px;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="text-center flex-fill">
                                <div class="fw-bold text-primary" id="hoursSummaryBudget"><?php echo number_format($budgetHours, 1); ?></div>
                                <small class="text-muted">Budget</small>
                            </div>
                            <div class="text-center flex-fill">
                                <div class="fw-bold <?php echo $isOvershoot ? 'text-danger' : 'text-success'; ?>" id="hoursSummaryUsed">
                                    <?php echo number_format($utilizedHours, 1); ?>
                                </div>
                                <small class="text-muted">Used <span id="hoursSummaryPercentText">(<?php echo number_format($usagePercentage, 1); ?>%)</span></small>
                            </div>
                            <div class="text-center flex-fill">
                                <div class="fw-bold <?php echo $isOvershoot ? 'text-danger' : 'text-warning'; ?>" id="hoursSummaryRemaining">
                                    <?php echo $isOvershoot ? number_format($overshootHours, 1) : number_format($remainingHours, 1); ?>
                                </div>
                                <small class="text-muted" id="hoursSummaryRemainingLabel"><?php echo $isOvershoot ? 'Overshoot' : 'Remaining'; ?></small>
                            </div>
                        </div>
                        
                        <?php if ($allocatedHours > 0): ?>
                        <div class="progress" style="height: 8px;">
                            <?php if ($isOvershoot): ?>
                                <!-- Green bar for budget (100% of container) -->
                                <div class="progress-bar bg-success" id="hoursSummaryBudgetBar" style="width: 100%;" title="Budget: <?php echo number_format($budgetHours, 1); ?> hours"></div>
                                <!-- Red bar for overshoot hours -->
                                <div class="progress-bar bg-danger" id="hoursSummaryOverBar" style="width: <?php echo ($overshootHours / $budgetHours) * 100; ?>%;" title="Overshoot: <?php echo number_format($overshootHours, 1); ?> hours"></div>
                            <?php else: ?>
                                <!-- Normal green bar for used hours within budget -->
                                <div class="progress-bar bg-success" id="hoursSummaryUsedBar" style="width: <?php echo ($budgetHours > 0 ? ($utilizedHours / $budgetHours) * 100 : 0); ?>%;" title="Used: <?php echo number_format($utilizedHours, 1); ?> hours"></div>
                            <?php endif; ?>
                        </div>
                        <div class="text-center mt-1">
                            <small class="text-muted" id="hoursSummaryPercentText">
                                <?php echo round(($utilizedHours / $allocatedHours) * 100, 1); ?>% used
                                <?php if ($isOvershoot): ?>
                                    <span class="text-danger" id="hoursSummaryOverText">(<?php echo number_format($overshootHours, 1); ?>h over!)</span>
                                <?php else: ?>
                                    <span class="text-danger d-none" id="hoursSummaryOverText"></span>
                                <?php endif; ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$isParent): ?>
<div class="row mb-3">
    <div class="col-md-2"><div class="card text-center"><div class="card-body"><h1 class="display-6"><?php echo $project['completion_percentage'] ?? 0; ?>%</h1><p class="text-muted mb-0">Overall Progress</p></div></div></div>
    <div class="col-md-2"><div class="card text-center"><div class="card-body"><h1 class="display-6"><?php echo count($uniquePages); ?></h1><p class="text-muted mb-0">Total Pages</p></div></div></div>
</div>
<?php endif; ?>

<!-- Quick Access Card for Accessibility Report -->
<div class="card mb-3 border-primary">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h5 class="mb-1">
                    <i class="fas fa-universal-access text-primary me-2"></i>
                    Accessibility Report
                </h5>
                <p class="text-muted small mb-0">View detailed accessibility issues, findings, and compliance reports</p>
            </div>
            <div class="col-md-4 text-md-end">
                <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" 
                   class="btn btn-primary">
                    <i class="fas fa-file-alt me-1"></i> View Report
                </a>
                <a href="<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo $projectId; ?>"
                   class="btn btn-outline-primary ms-2">
                    <i class="fas fa-comments me-1"></i> Project Chat
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mt-3" id="projectTabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" id="phases-tab" data-bs-toggle="tab" data-bs-target="#phases" type="button"><i class="fas fa-layer-group"></i> Phases</button></li>
    <li class="nav-item"><button class="nav-link" id="pages-tab" data-bs-toggle="tab" data-bs-target="#pages" type="button"><i class="fas fa-file-alt"></i> Pages/Screens</button></li>
    <?php if ($userRole !== 'client'): ?>
    <li class="nav-item"><button class="nav-link" id="team-tab" data-bs-toggle="tab" data-bs-target="#team" type="button"><i class="fas fa-users"></i> Team</button></li>
    <?php endif; ?>
    <li class="nav-item"><button class="nav-link" id="performance-tab" data-bs-toggle="tab" data-bs-target="#performance" type="button"><i class="fas fa-chart-line"></i> Performance</button></li>
    <li class="nav-item"><button class="nav-link" id="assets-tab" data-bs-toggle="tab" data-bs-target="#assets" type="button"><i class="fas fa-paperclip"></i> Assets</button></li>
    <li class="nav-item"><button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button"><i class="fas fa-history"></i> Activity</button></li>
    <li class="nav-item"><button class="nav-link" id="feedback-tab" data-bs-toggle="tab" data-bs-target="#feedback" type="button"><i class="fas fa-comment-dots"></i> Feedback</button></li>
    <li class="nav-item"><button class="nav-link" id="production-hours-tab" data-bs-toggle="tab" data-bs-target="#production-hours" type="button"><i class="fas fa-clock"></i> Hours</button></li>
</ul>

<div class="tab-content border border-top-0" id="projectTabsContent">
    <span id="project_tabs_probe" data-file="view.php" style="display:none;"></span>
    <?php include 'partials/tab_phases.php'; ?>
    <?php include 'partials/tab_pages.php'; ?>
    <?php if ($userRole !== 'client'): ?>
    <?php include 'partials/tab_team.php'; ?>
    <?php endif; ?>
    <?php include 'partials/tab_performance.php'; ?>
    <?php include 'partials/tab_assets.php'; ?>
    <?php include 'partials/tab_activity.php'; ?>
    <?php include 'partials/tab_feedback.php'; ?>
    <?php include 'partials/tab_production_hours.php'; ?>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
    window.ProjectConfig = {
        projectId: <?php echo json_encode($projectId); ?>,
        userId: <?php echo json_encode($userId); ?>,
        userRole: <?php echo json_encode($userRole); ?>,
        canUpdateIssueQaStatus: <?php echo $canUpdateIssueQaStatus ? 'true' : 'false'; ?>,
        baseDir: <?php echo json_encode($baseDir, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        projectType: <?php echo json_encode($project['type'] ?? 'web', JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        projectPages: <?php echo json_encode($projectPages ?? [], JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        uniqueIssuePages: <?php echo json_encode($uniqueIssuePages ?? [], JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        groupedUrls: <?php echo json_encode($groupedUrls ?? [], JSON_HEX_TAG); ?>,
        projectUsers: <?php echo json_encode($projectUsers ?? [], JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        qaStatuses: <?php echo json_encode($qaStatuses ?? [], JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        issueStatuses: <?php echo json_encode($issueStatuses ?? [], JSON_HEX_TAG | JSON_HEX_AMP); ?>
    };

    // Define issueMetadataFields globally for view_issues.js
    window.issueMetadataFields = <?php echo json_encode($metadataFields ?? []); ?>;
</script>

<?php include 'partials/modals.php'; ?>
<script src="<?php echo $baseDir; ?>/assets/js/chat-widget.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/chat-widget.js'); ?>"></script>
<?php
    $viewJsBase = __DIR__ . '/js/';
    $viewJsVersion = function ($file) use ($viewJsBase) {
        $path = $viewJsBase . $file;
        return file_exists($path) ? filemtime($path) : time();
    };
    $assetsJsBase = __DIR__ . '/../../assets/js/';
    $assetsJsVersion = function ($file) use ($assetsJsBase) {
        $path = $assetsJsBase . $file;
        return file_exists($path) ? filemtime($path) : time();
    };
?>
<script src="<?php echo $baseDir; ?>/assets/js/view-init.js?v=<?php echo $assetsJsVersion('view-init.js'); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_core.js?v=<?php echo $viewJsVersion('view_core.js'); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_pages.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_pages_enhanced.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_feedback.js?v=<?php echo $viewJsVersion('view_feedback.js'); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_production.js?v=<?php echo time(); ?>"></script>



<?php include __DIR__ . '/../../includes/footer.php'; 