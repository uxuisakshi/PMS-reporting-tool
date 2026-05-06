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
$normalizedUserRole = strtolower(str_replace(' ', '_', trim((string)$userRole)));

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    header('Location: ' . $baseDir . '/index.php');
    exit;
}
$canUpdateIssueQaStatus = hasIssueQaStatusUpdateAccess($db, $userId, $projectId);
// Note: hasIssueQaStatusUpdateAccess already handles tester role permissions properly
// Don't override it here as testers may have explicit QA permissions granted

// Get project details
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

// Get filter options
$pagesStmt = $db->prepare("SELECT id, page_name, page_number, url FROM project_pages WHERE project_id = ? ORDER BY page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Natural sort by page_number (Page 1, Page 2, Page 10... not Page 1, Page 10, Page 2)
usort($projectPages, function($a, $b) {
    $an = $a['page_number'] ?? '';
    $bn = $b['page_number'] ?? '';
    return strnatcasecmp((string)$an, (string)$bn);
});

$issueStatuses = getIssueStatusesForRole($db, $userRole);

$qaStatusesStmt = $db->query("SELECT status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1 ORDER BY display_order");
$qaStatuses = $qaStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

$reportersStmt = $db->prepare("
    SELECT DISTINCT u.id, u.full_name, u.username, u.role
    FROM users u
    INNER JOIN user_assignments ua ON u.id = ua.user_id
    WHERE ua.project_id = ? AND u.is_active = 1 AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    UNION
    SELECT DISTINCT u.id, u.full_name, u.username, u.role
    FROM users u
    INNER JOIN projects p ON p.project_lead_id = u.id
    WHERE p.id = ? AND u.is_active = 1
    ORDER BY full_name
");
$reportersStmt->execute([$projectId, $projectId]);
$projectUsers = $reportersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch grouped URLs
$groupedStmt = $db->prepare("
    SELECT 
        gu.id AS grouped_id, 
        gu.url, 
        gu.normalized_url, 
        gu.unique_page_id,
        COALESCE(gu.unique_page_id, pp_match.id) AS mapped_page_id,
        up.id AS unique_id, 
        up.page_name AS unique_name,
        up.url AS canonical_url
    FROM grouped_urls gu 
    LEFT JOIN project_pages up ON gu.unique_page_id = up.id
    LEFT JOIN project_pages pp_match ON pp_match.project_id = gu.project_id
        AND (pp_match.url = gu.url OR pp_match.url = gu.normalized_url)
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

// Fetch issue metadata fields
$metadataFieldsStmt = $db->query("SELECT id, field_key, field_label, options_json FROM issue_metadata_fields WHERE is_active = 1 ORDER BY sort_order ASC");
$metadataFields = $metadataFieldsStmt->fetchAll(PDO::FETCH_ASSOC);

// Parse options_json for each field
foreach ($metadataFields as &$field) {
    if (!empty($field['options_json'])) {
        $field['options'] = json_decode($field['options_json'], true);
    } else {
        $field['options'] = [];
    }
}

$pageTitle = 'All Issues - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
.filter-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
.issue-row {
    cursor: pointer;
    transition: all 0.2s;
}
.issue-row:hover {
    background-color: #f8f9fa;
}
/* Make all badges consistent size */
.badge,
.status-badge {
    padding: 3px 10px !important;
    font-size: 10px !important;
    font-weight: 500 !important;
    border-radius: 10px !important;
    white-space: nowrap;
    display: inline-block;
}
.qa-status-badge {
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 500;
    display: inline-block;
    margin: 2px;
    white-space: nowrap;
}
/* Reduce Summernote paragraph spacing */
.note-editable p {
    margin: 0 !important;
    line-height: 1.5 !important;
}
<?php if ($_SESSION['role'] === 'client'): ?>
#finalIssueModal.client-issue-sidebar-shell {
    position: fixed;
    inset: 0;
    z-index: 1045;
    pointer-events: none;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-panel {
    position: fixed;
    top: var(--client-issue-sidebar-top-offset, 64px);
    bottom: 0;
    right: 0;
    width: min(310px, 100vw);
    height: auto;
    max-height: calc(100vh - var(--client-issue-sidebar-top-offset, 64px));
    display: flex;
    flex-direction: column;
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 14%);
    border-left: 1px solid #dce8f8;
    box-shadow: -24px 0 60px rgba(15, 23, 42, .18);
    transform: translateX(100%);
    transition: transform .28s ease;
    pointer-events: auto;
    overflow: hidden;
}
#finalIssueModal.client-issue-sidebar-shell.show .client-issue-sidebar-panel,
#finalIssueModal.client-issue-sidebar-shell.is-open .client-issue-sidebar-panel {
    transform: translateX(0);
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .75rem;
    padding: .6rem .7rem .4rem;
    border-bottom: 1px solid #e5edf7;
    background: rgba(255, 255, 255, .96);
    backdrop-filter: blur(10px);
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-title-stack {
    min-width: 0;
}
#finalIssueModal.client-issue-sidebar-shell #finalEditorTitle {
    font-size: 1.02rem;
    line-height: 1.2;
    color: #0f172a;
    word-break: break-word;
}
#finalIssueModal.client-issue-sidebar-shell #finalIssuePresenceIndicator:empty {
    display: none;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-body {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    flex-direction: column;
    padding: .35rem .55rem 0;
    overflow: hidden;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-status-row {
    display: flex;
    align-items: center;
    gap: .4rem;
    background: #f3f7fd;
    border: 1px solid #dce8f8;
    border-radius: 14px;
    padding: .32rem .38rem;
    margin-bottom: .3rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-status-row .form-label {
    margin-bottom: .1rem !important;
    font-size: .74rem;
    line-height: 1.1;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-status-row .form-select {
    min-height: 32px;
    padding-top: .18rem;
    padding-bottom: .18rem;
    padding-left: .55rem;
    font-size: .84rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-status-row #finalIssueSaveBtn {
    flex: 0 0 auto;
    min-width: 68px;
    height: 32px;
    border-radius: 999px;
    padding-inline: .65rem;
    font-size: .82rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-conversation {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    padding: 0;
    border: 1px solid #e6eef8;
    border-radius: 18px 18px 0 0;
    border-bottom: 0;
    background: #fff;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
    overflow: hidden;
}
#finalIssueModal.client-issue-sidebar-shell #finalIssueCommentsList {
    flex: 1 1 auto;
    min-height: 0;
    max-height: none !important;
    overflow-y: auto;
    padding: .65rem !important;
    background: linear-gradient(180deg, #f8fbff 0%, #f3f7fd 100%) !important;
    border: 0 !important;
    border-radius: 18px 18px 0 0 !important;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-footer {
    padding: .28rem .55rem .42rem;
    background: #fff;
    border-top: 1px solid #e6eef8;
    box-shadow: 0 -12px 24px rgba(15, 23, 42, .04);
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-composer {
    margin-top: 0;
    padding-top: 0;
    border-top: 0;
    background: transparent;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap {
    border: 1px solid #d7e5f7;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
    overflow: hidden;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-editor {
    border: 0 !important;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-toolbar {
    display: flex;
    flex-wrap: nowrap;
    gap: .2rem;
    overflow-x: auto;
    overflow-y: hidden;
    white-space: nowrap;
    border-bottom: 1px solid #edf3fb;
    background: #f8fbff;
    padding: .25rem .35rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-toolbar .note-btn-group {
    display: inline-flex;
    flex-wrap: nowrap;
    margin-right: .2rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-editing-area {
    background: #fff;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-editable {
    min-height: 54px;
    max-height: 92px;
    padding: .55rem .75rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-composer #finalIssueAddCommentBtn {
    min-width: 110px;
    border-radius: 999px;
    padding-inline: 1rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-composer .mt-2 {
    margin-top: .45rem !important;
}
#finalIssueModal.client-issue-sidebar-shell .message {
    display: flex;
    flex-direction: column;
}
#finalIssueModal.client-issue-sidebar-shell .message.own-message {
    align-items: flex-end;
}
#finalIssueModal.client-issue-sidebar-shell .message.other-message {
    align-items: flex-start;
}
#finalIssueModal.client-issue-sidebar-shell .message .d-flex.justify-content-between.align-items-start.mb-1,
#finalIssueModal.client-issue-sidebar-shell .message .d-flex.flex-wrap.gap-2.mb-2 {
    width: min(100%, 94%);
}
#finalIssueModal.client-issue-sidebar-shell .message-content {
    width: min(100%, 94%);
    padding: .75rem .9rem !important;
    border-radius: 18px !important;
    word-break: break-word;
}
#finalIssueModal.client-issue-sidebar-shell .other-message .message-content {
    background: #ffffff !important;
    border: 1px solid #dce8f8;
}
#finalIssueModal.client-issue-sidebar-shell .own-message .message-content {
    background: linear-gradient(180deg, #dceeff 0%, #cfe5ff 100%) !important;
    border: 1px solid #bad8ff;
}
#finalIssueModal.client-issue-sidebar-shell .reply-preview {
    width: min(100%, 94%);
}
body.client-issue-sidebar-open {
    overflow: auto !important;
    padding-right: 0 !important;
}
@media (max-width: 767.98px) {
    #finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-panel {
        width: 100vw;
    }
    #finalIssueModal.client-issue-sidebar-shell .client-issue-status-row {
        flex-direction: column;
        align-items: stretch;
    }
}
<?php endif; ?>
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
                    <li class="breadcrumb-item active">All Issues</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-list text-primary me-2"></i>
                        All Issues
                    </h2>
                    <p class="text-muted mb-0">Complete list of all accessibility issues in this project</p>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <a href="<?php echo $baseDir; ?>/api/download_screenshots.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-download me-1"></i> Download Screenshots
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_common.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-info">
                        <i class="fas fa-layer-group me-1"></i> Common Issues
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_pages.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-file-alt me-1"></i> Page View
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back
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
                <label class="form-label"><i class="fas fa-file-alt me-1"></i> Page</label>
                <select class="form-select" id="filterPage" multiple>
                    <option value="">All Pages</option>
                    <?php foreach ($projectPages as $page): ?>
                        <option value="<?php echo $page['id']; ?>">
                            <?php echo htmlspecialchars($page['page_number'] . ' - ' . $page['page_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-flag me-1"></i> Status</label>
                <select class="form-select" id="filterStatus" multiple>
                    <option value="">All Statuses</option>
                    <?php foreach ($issueStatuses as $status): ?>
                        <option value="<?php echo $status['id']; ?>"><?php echo htmlspecialchars($status['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($_SESSION['role'] !== 'client'): ?>
            <div class="col-md-2">
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
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-user me-1"></i> Reporter</label>
                <select class="form-select" id="filterReporter" multiple>
                    <option value="">All Reporters</option>
                    <?php foreach ($projectUsers as $reporter): ?>
                        <option value="<?php echo $reporter['id']; ?>"><?php echo htmlspecialchars($reporter['full_name']); ?></option>
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

    <!-- Issues Table -->
    <div class="card">
        <div class="card-body">
            <!-- Single toolbar row: per page + showing info + pagination + actions -->
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
                    <button class="btn btn-sm btn-outline-primary" id="refreshBtn">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="kbShortcutsBtn" title="Keyboard Shortcuts" aria-label="Show keyboard shortcuts">
                        <i class="fas fa-keyboard me-1"></i><span class="d-none d-md-inline">Shortcuts</span>
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover fixed-issue-table resizable-table" id="issuesTable">
                    <?php if ($_SESSION['role'] !== 'client'): ?>
                    <colgroup>
                        <col style="width:36px;">
                        <col style="width:105px;">
                        <col><!-- Title: takes remaining space -->
                        <col style="width:130px;">
                        <col style="width:110px;">
                        <col style="width:100px;">
                        <col style="width:120px;">
                        <col style="width:105px;">
                        <col style="width:95px;">
                    </colgroup>
                    <?php else: ?>
                    <colgroup>
                        <col style="width:105px;">
                        <col><!-- Title -->
                        <col style="width:130px;">
                        <col style="width:110px;">
                        <col style="width:95px;">
                    </colgroup>
                    <?php endif; ?>
                    <thead class="table-light">
                        <tr>
                            <?php if ($_SESSION['role'] !== 'client'): ?>
                            <th style="position:relative;"><input type="checkbox" id="issuesSelectAll" aria-label="Select all issues"><div class="col-resizer"></div></th>
                            <?php endif; ?>
                            <th style="position:relative;">Issue Key<div class="col-resizer"></div></th>
                            <th style="position:relative;">Title<div class="col-resizer"></div></th>
                            <th style="position:relative;">Page(s)<div class="col-resizer"></div></th>
                            <th style="position:relative;">Status<div class="col-resizer"></div></th>
                            <?php if ($_SESSION['role'] !== 'client'): ?>
                            <th style="position:relative;">Client Ready<div class="col-resizer"></div></th>
                            <th style="position:relative;">QA Status<div class="col-resizer"></div></th>
                            <th style="position:relative;">Reporter<div class="col-resizer"></div></th>
                            <?php endif; ?>
                            <th style="position:relative;">Actions<div class="col-resizer"></div></th>
                        </tr>
                    </thead>
                    <tbody id="issuesTableBody">
                        <tr>
                            <td colspan="<?php echo ($_SESSION['role'] === 'client') ? '5' : '9'; ?>" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading issues...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2" id="paginationBar">
                <div class="text-muted small" id="paginationInfo"></div>
                <nav aria-label="Issues pagination">
                    <ul class="pagination pagination-sm mb-0" id="paginationControls"></ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Include modals from partials -->
<?php include __DIR__ . '/partials/issues_modals.php'; ?>

<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Project Configuration for view_issues.js -->
<script nonce="<?php echo $cspNonce ?? ''; ?>">
window._csrfToken = <?php echo json_encode(generateCsrfToken()); ?>;

// Global configuration object required by view_issues.js
window.ProjectConfig = {
    projectId: <?php echo $projectId; ?>,
    projectCode: <?php echo json_encode($project['project_code'] ?? 'ISS'); ?>,
    projectType: <?php echo json_encode(strtolower($project['project_type'] ?? 'web')); ?>,
    userRole: <?php echo json_encode((string)($normalizedUserRole ?? '')); ?>,
    canUpdateIssueQaStatus: <?php echo $canUpdateIssueQaStatus ? 'true' : 'false'; ?>,
    projectPages: <?php echo json_encode($projectPages); ?>,
    uniqueIssuePages: <?php echo json_encode($uniqueIssuePages ?? []); ?>,
    groupedUrls: <?php echo json_encode($groupedUrls); ?>,
    baseDir: <?php echo json_encode($baseDir); ?>,
    projectUsers: <?php echo json_encode($projectUsers); ?>,
    qaStatuses: <?php echo json_encode($qaStatuses); ?>,
    issueStatuses: <?php echo json_encode($issueStatuses); ?>,
    metadataFields: <?php echo json_encode($metadataFields); ?>
};

// Define issueMetadataFields globally for view_issues.js
window.issueMetadataFields = <?php echo json_encode($metadataFields ?? []); ?>;

<?php if ($normalizedUserRole === 'client'): ?>
(function () {
    function syncClientIssueSidebarOffset() {
        var headerNav = document.querySelector('header .navbar.sticky-top');
        var headerHeight = headerNav ? Math.ceil(headerNav.getBoundingClientRect().height) : 64;
        document.documentElement.style.setProperty('--client-issue-sidebar-top-offset', headerHeight + 'px');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncClientIssueSidebarOffset, { once: true });
    } else {
        syncClientIssueSidebarOffset();
    }

    window.addEventListener('resize', syncClientIssueSidebarOffset);
})();
<?php endif; ?>
</script>

<!-- Include issue management JavaScript -->
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_core.js"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/regression-panel.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_navigation.js?v=<?php echo time(); ?>"></script>

<script src="<?php echo $baseDir; ?>/assets/js/issues-all.js?v=<?php echo time(); ?>"></script>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
document.addEventListener('DOMContentLoaded', function() {
    if (window.IssueNavigation) {
        window.IssueNavigation.init({
            rowSelector: '.issue-row',
            editBtnSelector: '.edit-btn, .issue-open'
        });
    }
});
</script>


<?php if ($normalizedUserRole !== 'client'): ?>
<!-- Floating Project Chat -->
<style>
.chat-launcher { position: fixed; bottom: 20px; right: 20px; z-index: 1040; border-radius: 999px; box-shadow: 0 10px 24px rgba(0,0,0,0.18); padding: 12px 18px; display: flex; align-items: center; gap: 8px; }
.chat-launcher i { font-size: 1.1rem; }
.chat-widget { position: fixed; bottom: 86px; right: 20px; width: 360px; max-width: 92vw; height: 520px; max-height: 78vh; background: #fff; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,0.25); border: 1px solid #e5e7eb; overflow: hidden; z-index: 1040; display: none; }
.chat-widget.open { display: block; }
.chat-widget iframe { width: 100%; height: calc(100% - 48px); border: 0; }
.chat-widget .chat-widget-header { height: 48px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #0d6efd, #4dabf7); color: #fff; }
.chat-widget .chat-widget-header .btn { color: #fff; border-color: rgba(255,255,255,0.3); }
.chat-widget .chat-widget-header .btn:hover { background: rgba(255,255,255,0.12); }
body.chat-modal-open .chat-launcher,
body.chat-modal-open .chat-widget { visibility: hidden !important; pointer-events: none !important; }
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