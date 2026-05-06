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

// Pre-fetch project pages
$pagesStmt = $db->prepare("SELECT id, page_name, page_number, url FROM project_pages WHERE project_id = ? ORDER BY page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch grouped URLs for the project
$groupedUrls = [];
try {
    $groupedStmt = $db->prepare("
        SELECT gu.id, gu.url, gu.normalized_url, gu.unique_page_id
        FROM grouped_urls gu 
        WHERE gu.project_id = ?
        ORDER BY gu.url
    ");
$groupedStmt->execute([$projectId]);
$groupedUrls = $groupedStmt->fetchAll(PDO::FETCH_ASSOC);

// Unique page mapping for canonical URL fallback when grouped URLs are missing
$uniqueIssuePages = [];
try {
    $uniqueIssueStmt = $db->prepare("
        SELECT 
            up.id AS unique_id,
            up.page_name AS unique_name,
            up.url AS canonical_url,
            MIN(pp.id) AS mapped_page_id
        FROM project_pages up
        LEFT JOIN grouped_urls gu ON gu.project_id = up.project_id AND gu.unique_page_id = up.id
        LEFT JOIN project_pages pp ON pp.project_id = up.project_id
            AND (
                pp.url = gu.url
                OR pp.url = gu.normalized_url
                OR pp.url = up.url
                OR pp.page_name = up.page_name
                OR pp.page_number = up.page_name
            )
        WHERE up.project_id = ?
        GROUP BY up.id
    ");
    $uniqueIssueStmt->execute([$projectId]);
    $uniqueIssuePages = $uniqueIssueStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $uniqueIssuePages = [];
}
} catch (Exception $e) {
    $groupedUrls = [];
}

// Fetch QA statuses
$qaStatuses = [];
try {
    $qaStmt = $db->prepare("SELECT id, status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1 ORDER BY display_order");
    $qaStmt->execute();
    $qaStatuses = $qaStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $qaStatuses = [];
}

// Fetch issue statuses from issue_statuses table (admin managed)
$issueStatuses = [];
try {
    $issueStatuses = getIssueStatusesForRole($db, $userRole);
} catch (Exception $e) {
    $issueStatuses = [];
}

// Fetch project users (project team) for reporters dropdown
$projectUsers = [];
try {
    $usersStmt = $db->prepare("
        SELECT DISTINCT u.id, u.full_name, u.username, u.role 
        FROM users u
        INNER JOIN user_assignments ua ON u.id = ua.user_id
        WHERE ua.project_id = ? AND u.is_active = 1 AND (ua.is_removed IS NULL OR ua.is_removed = 0)
        UNION
        SELECT u.id, u.full_name, u.username, u.role
        FROM users u
        WHERE u.is_active = 1 AND u.role IN ('admin')
        ORDER BY full_name
    ");
    $usersStmt->execute([$projectId]);
    $projectUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $projectUsers = [];
}

// Fetch metadata fields for this project type
$metadataFields = [];
try {
    $metaStmt = $db->prepare("
        SELECT id, field_key, field_label, options_json
        FROM issue_metadata_fields
        WHERE is_active = 1
        ORDER BY sort_order ASC, field_label ASC
    ");
    $metaStmt->execute();
    $metadataFields = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse options_json for each field
    foreach ($metadataFields as &$field) {
        if (!empty($field['options_json'])) {
            $field['options'] = json_decode($field['options_json'], true);
        } else {
            $field['options'] = [];
        }
    }
} catch (Exception $e) {
    $metadataFields = [];
}

$pageTitle = 'Issues - Common - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
.modal { z-index: 10550; }
.modal-backdrop { z-index: 10540; }
.select2-container--open .select2-dropdown { z-index: 10600; }
.select2-results__options { max-height: 250px !important; overflow-y: auto !important; }
/* Make all badges consistent size */
.badge {
    padding: 3px 10px !important;
    font-size: 10px !important;
    font-weight: 500 !important;
    border-radius: 10px !important;
}
.qa-status-badge {
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 500;
}
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
                    <li class="breadcrumb-item active">Common Issues</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1">
                        <i class="fas fa-layer-group text-primary me-2"></i>
                        Common Issues
                    </h2>
                    <p class="text-muted mb-0">Manage issues that apply to multiple pages</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_all.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-info btn-sm me-2">
                        <i class="fas fa-list me-1"></i> View All Issues
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_pages.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-file-alt me-1"></i> Pages View
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/partials/regression_panel.php'; ?>

    <!-- Filters Section -->
    <div class="filter-section">
        <div class="row align-items-end g-3">
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-search me-1"></i> Search</label>
                <input type="text" class="form-control" id="searchInput" placeholder="Search by title, key, or description...">
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-flag me-1"></i> Status</label>
                <select class="form-select" id="filterStatus" multiple>
                    <option value="">All Statuses</option>
                    <?php foreach ($issueStatuses as $status): ?>
                        <option value="<?php echo (int)$status['id']; ?>"><?php echo htmlspecialchars($status['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($_SESSION['role'] !== 'client'): ?>
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-check-circle me-1"></i> QA Status</label>
                <select class="form-select" id="filterQAStatus" multiple>
                    <option value="">All QA Statuses</option>
                    <?php foreach ($qaStatuses as $qaStatus): ?>
                        <option value="<?php echo htmlspecialchars($qaStatus['status_key']); ?>">
                            <?php echo htmlspecialchars($qaStatus['status_label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-user me-1"></i> Reporter</label>
                <select class="form-select" id="filterReporter" multiple>
                    <option value="">All Reporters</option>
                    <?php foreach ($projectUsers as $reporter): ?>
                        <option value="<?php echo (int)$reporter['id']; ?>"><?php echo htmlspecialchars($reporter['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-1">
                <button class="btn btn-secondary w-100" id="clearFilters">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <!-- Toolbar: per page + info + pagination + actions -->
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="d-flex align-items-center gap-1">
                        <label class="text-muted small mb-0">Per page:</label>
                        <select id="perPageSelect" class="form-select form-select-sm" style="width:auto; min-width:75px; padding-right:1.75rem;">
                            <option value="25">25</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                            <option value="250">250</option>
                            <option value="500">500</option>
                            <option value="1000">1000</option>
                        </select>
                    </div>
                    <span class="text-muted small" id="paginationInfoTop"></span>
                    <nav aria-label="Issues pagination top">
                        <ul class="pagination pagination-sm mb-0" id="paginationControlsTop"></ul>
                    </nav>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($_SESSION['role'] !== 'client'): ?>
                    <button class="btn btn-sm btn-primary" id="addIssueBtn">
                        <i class="fas fa-plus me-1"></i> Add Issue
                    </button>
                    <button class="btn btn-sm btn-outline-success" id="allIssuesMarkClientReadyBtn" disabled>
                        <i class="fas fa-check me-1"></i> Mark Client Ready
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-primary" id="commonIssuesRefreshBtn">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="kbShortcutsBtn" title="Keyboard Shortcuts">
                        <i class="fas fa-keyboard me-1"></i><span class="d-none d-md-inline">Shortcuts</span>
                    </button>
                </div>
            </div>

            <?php if ($_SESSION['role'] === 'client'): ?>
            <div class="alert alert-info d-flex align-items-start gap-2">
                <i class="fas fa-circle-info mt-1"></i>
                <div>
                    Shared issues are shown here as a read-only summary so your team can quickly review patterns repeated across multiple pages.
                </div>
            </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle fixed-issue-table resizable-table" id="commonIssuesTable">
                    <?php if ($_SESSION['role'] !== 'client'): ?>
                    <colgroup>
                        <col style="width:36px;">
                        <col style="width:115px;">
                        <col><!-- Title -->
                        <col style="width:125px;">
                        <col style="width:105px;">
                    </colgroup>
                    <?php else: ?>
                    <colgroup>
                        <col style="width:115px;">
                        <col><!-- Title -->
                        <col style="width:125px;">
                    </colgroup>
                    <?php endif; ?>
                    <thead class="table-light">
                        <tr>
                            <?php if ($_SESSION['role'] !== 'client'): ?>
                            <th style="position:relative;"><input type="checkbox" id="commonSelectAll" aria-label="Select all issues"><div class="col-resizer"></div></th>
                            <?php endif; ?>
                            <th style="position:relative;">Issue Key<div class="col-resizer"></div></th>
                            <th style="position:relative;">Common Issue Title<div class="col-resizer"></div></th>
                            <th style="position:relative;">Page(s)<div class="col-resizer"></div></th>
                            <?php if ($_SESSION['role'] !== 'client'): ?>
                            <th style="position:relative;">Actions<div class="col-resizer"></div></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="commonIssuesBody">
                        <tr>
                            <td colspan="<?php echo ($_SESSION['role'] === 'client') ? '3' : '5'; ?>" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading common issues...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bottom -->
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2" id="paginationBar">
                <div class="text-muted small" id="paginationInfo"></div>
                <nav aria-label="Issues pagination">
                    <ul class="pagination pagination-sm mb-0" id="paginationControls"></ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<?php 
// Include the final issue modal from issues_modals.php
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
        projectCode: <?php echo json_encode($project['project_code'] ?? 'ISS'); ?>,
        userId: <?php echo json_encode($userId); ?>,
        userRole: <?php echo json_encode($userRole); ?>,
        canUpdateIssueQaStatus: <?php echo $canUpdateIssueQaStatus ? 'true' : 'false'; ?>,
        baseDir: <?php echo json_encode($baseDir); ?>,
        projectType: <?php echo json_encode(strtolower($project['project_type'] ?? 'web')); ?>,
        projectPages: <?php echo json_encode($projectPages ?? []); ?>,
        uniqueIssuePages: <?php echo json_encode($uniqueIssuePages ?? []); ?>,
        groupedUrls: <?php echo json_encode($groupedUrls ?? []); ?>,
        projectUsers: <?php echo json_encode($projectUsers ?? []); ?>,
        qaStatuses: <?php echo json_encode($qaStatuses ?? []); ?>,
        issueStatuses: <?php echo json_encode($issueStatuses ?? []); ?>
    };
    
    // Define issueMetadataFields globally for view_issues.js
    window.issueMetadataFields = <?php echo json_encode($metadataFields ?? []); ?>;
</script>

<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js?v=<?php echo time(); ?>"></script>

<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/regression-panel.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_navigation.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/assets/js/issues-common-aligned.js?v=<?php echo time(); ?>"></script>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
document.addEventListener('DOMContentLoaded', function() {
    if (window.IssueNavigation) {
        window.IssueNavigation.init({
            rowSelector: '.issue-expandable-row',
            editBtnSelector: '.common-edit, .issue-open'
        });
    }
});
</script>

<?php if ($_SESSION['role'] !== 'client'): ?>
<!-- Floating Project Chat -->
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