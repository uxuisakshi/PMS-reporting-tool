<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) {
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$db = Database::getInstance();
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$stmt = $db->prepare("SELECT id, title, project_type FROM projects WHERE id = ? LIMIT 1");
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$projectType = strtolower(trim((string)($project['project_type'] ?? 'web')));
if (!in_array($projectType, ['web', 'app', 'pdf'], true)) {
    $projectType = 'web';
}

$pageTitle = 'Import Issues - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>">Accessibility Report</a></li>
                    <li class="breadcrumb-item active">Import Issues</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h4 class="mb-1"><i class="fas fa-file-import text-primary me-2"></i>Import Issues From Excel/CSV</h4>
                <div class="text-muted">Project: <strong><?php echo htmlspecialchars($project['title']); ?></strong></div>
            </div>
            <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <form id="issueImportForm" enctype="multipart/form-data" class="mb-3">
                        <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Upload File</label>
                            <input type="file" name="file" id="issueImportFile" class="form-control" required
                                accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                            <div class="form-text">This importer reads three fixed sheet names: Final Report, URL details, and All URLs.</div>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="skipDuplicates" name="skip_duplicates" value="1" checked>
                            <label class="form-check-label" for="skipDuplicates">
                                Skip duplicate issues (same title + page)
                            </label>
                        </div>

                        <button type="button" id="loadHeadersBtn" class="btn btn-outline-primary">
                            <i class="fas fa-table me-1"></i> Load Sheet Headers
                        </button>
                        <button type="submit" id="issueImportBtn" class="btn btn-primary ms-2" disabled>
                            <i class="fas fa-upload me-1"></i> Import Issues
                        </button>
                    </form>

                    <div id="mappingSection" style="display:none;">
                        <div class="alert alert-info py-2 small mb-3">
                            Auto-mapping is disabled. Select column mappings manually.
                        </div>

                        <div class="card mb-3">
                            <div class="card-header fw-semibold">1) Issues Sheet Mapping</div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Sheet</label>
                                        <input type="text" class="form-control" value="Final Report" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Title (required)</label>
                                        <select id="mapIssueTitle" class="form-select"></select>
                                    </div>
                                    <div class="col-md-6"><label class="form-label">Status</label><select id="mapIssueStatus" class="form-select"></select></div>
                                    <div class="col-md-6"><label class="form-label">Priority</label><select id="mapIssuePriority" class="form-select"></select></div>
                                    <div class="col-md-6"><label class="form-label">Severity</label><select id="mapIssueSeverity" class="form-select"></select></div>
                                    <div class="col-md-6"><label class="form-label">Common Issue Title</label><select id="mapIssueCommonTitle" class="form-select"></select></div>
                                    <div id="issueSectionMappingFields" class="contents"></div>
                                    <div id="issueMetadataMappingFields" class="contents"></div>
                                    <div class="col-md-6"><label class="form-label">Pages</label><select id="mapIssuePages" class="form-select"></select></div>
                                    <div class="col-md-6"><label class="form-label">Page Numbers</label><select id="mapIssuePageNumbers" class="form-select"></select></div>
                                    <div class="col-md-6"><label class="form-label">QA Status</label><select id="mapIssueQaStatus" class="form-select"></select></div>
                                    <div class="col-md-6"><label class="form-label">Grouped URLs</label><select id="mapIssueGroupedUrls" class="form-select"></select></div>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header fw-semibold">2) Project Pages Sheet Mapping (optional)</div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Sheet</label>
                                        <input type="text" class="form-control" value="URL details" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Page Name</label>
                                        <select id="mapPageName" class="form-select"></select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Unique URL</label>
                                        <select id="mapUniqueUrl" class="form-select"></select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Grouped URLs</label>
                                        <select id="mapPagesGroupedUrls" class="form-select"></select>
                                    </div>
                                    <div class="col-md-6"><label class="form-label">Page Number</label><select id="mapPageNumber" class="form-select"></select></div>
                                    <div class="col-md-6"><label class="form-label">Screen Name</label><select id="mapScreenName" class="form-select"></select></div>
                                    <div class="col-md-6"><label class="form-label">Notes</label><select id="mapNotes" class="form-select"></select></div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header fw-semibold">3) All URLs Sheet Mapping (optional)</div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Sheet</label>
                                        <input type="text" class="form-control" value="All URLs" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">URLs Column (header e.g. URLS)</label>
                                        <select id="mapAllUrls" class="form-select"></select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-light fw-semibold">Header Mapping (Manual)</div>
                <div class="card-body small text-muted">
                    <div>Manual mapping required fields:</div>
                    <ul class="mb-0 mt-2">
                        <li>Issues: Title</li>
                        <li>Project Pages: Page Name + Unique URL (if selected)</li>
                        <li>All URLs: URLS column (if selected)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-3" id="importResultCard" style="display:none;">
        <div class="card-body">
            <h6 class="mb-3">Import Result</h6>
            <div id="importResultBody" class="small"></div>
        </div>
    </div>
</div>

<script>
window.IssueImportConfig = {
    baseDir: <?php echo json_encode($baseDir); ?>,
    projectType: <?php echo json_encode($projectType); ?>
};
</script>
<script src="<?php echo $baseDir; ?>/assets/js/import-issues.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/../../includes/footer.php';
