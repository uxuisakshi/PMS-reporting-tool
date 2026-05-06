<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/ClientAccessControlManager.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$baseDir = getBaseDir();

$accessControl = new ClientAccessControlManager();
$clientUserId = $userId;
if (in_array($userRole, ['admin']) && isset($_GET['client_id'])) {
    $clientUserId = intval($_GET['client_id']);
}

$selectedProjectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;

$assignedProjects = $accessControl->getAssignedProjects($clientUserId);
$assignedProjectIds = array_map(static function ($project) {
    return (int) ($project['id'] ?? 0);
}, $assignedProjects);

if ($selectedProjectId > 0) {
    if (!in_array($selectedProjectId, $assignedProjectIds, true)) {
        http_response_code(403);
        exit('Unauthorized project access');
    }

    $assignedProjects = array_values(array_filter($assignedProjects, static function ($project) use ($selectedProjectId) {
        return (int) ($project['id'] ?? 0) === $selectedProjectId;
    }));
}

$pageTitle = 'Issue Overview';

$issueRows = [];
$totals = ['issues' => 0, 'open' => 0, 'resolved' => 0];

foreach ($assignedProjects as $project) {
    $stats = $accessControl->getProjectStatistics($clientUserId, $project['id']);
    $row = [
        'id' => (int) $project['id'],
        'title' => $project['title'] ?? 'Digital Asset',
        'total_issues' => (int) ($stats['client_ready_issues'] ?? 0),
        'open_issues' => (int) ($stats['open_issues'] ?? 0),
        'resolved_issues' => (int) ($stats['resolved_issues'] ?? 0),
        'compliance' => round((float) ($stats['compliance_score'] ?? 0), 1)
    ];

    $totals['issues'] += $row['total_issues'];
    $totals['open'] += $row['open_issues'];
    $totals['resolved'] += $row['resolved_issues'];
    $issueRows[] = $row;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid" id="main-content" tabindex="-1">
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-header">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/client/dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <?php if ($selectedProjectId > 0 && !empty($assignedProjects)): ?>
                        <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $assignedProjects[0]['id'], (string) ($assignedProjects[0]['title'] ?? ''), (string) ($assignedProjects[0]['project_code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-chart-line"></i> Analytics</a></li>
                        <?php endif; ?>
                        <li class="breadcrumb-item active" aria-current="page"><i class="fas fa-exclamation-triangle"></i> Issue Overview</li>
                    </ol>
                </nav>
                <div class="header-content">
                    <h1 class="page-title"><i class="fas fa-exclamation-triangle text-warning"></i> Issue Overview</h1>
                    <p class="page-subtitle"><?php echo $selectedProjectId > 0 && !empty($assignedProjects) ? 'Issue totals for ' . htmlspecialchars($assignedProjects[0]['title']) : 'Issue totals across all digital assets'; ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="metric-card border-warning-subtle bg-warning-subtle">
                <div class="metric-value text-warning"><?php echo number_format($totals['issues']); ?></div>
                <div class="metric-label">Total Issues</div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="metric-card border-danger-subtle bg-danger-subtle">
                <div class="metric-value text-danger"><?php echo number_format($totals['open']); ?></div>
                <div class="metric-label">Open Issues</div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="metric-card border-success-subtle bg-success-subtle">
                <div class="metric-value text-success"><?php echo number_format($totals['resolved']); ?></div>
                <div class="metric-label">Resolved Issues</div>
            </div>
        </div>
    </div>

    <div class="table-responsive data-table-wrap">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Digital Asset</th>
                    <th class="text-end">Total Issues</th>
                    <th class="text-end">Open Issues</th>
                    <th class="text-end">Resolved</th>
                    <th class="text-end">Compliance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($issueRows as $row): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['title']); ?></strong></td>
                    <td class="text-end fw-semibold"><?php echo number_format($row['total_issues']); ?></td>
                    <td class="text-end text-warning fw-semibold"><?php echo number_format($row['open_issues']); ?></td>
                    <td class="text-end text-success fw-semibold"><?php echo number_format($row['resolved_issues']); ?></td>
                    <td class="text-end text-info fw-semibold"><?php echo number_format($row['compliance'], 1); ?>%</td>
                    <td>
                        <div class="table-actions">
                            <a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $row['id'], (string) ($row['title'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary btn-sm"><i class="fas fa-chart-line"></i> Analytics</a>
                            <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/projects/issues_all.php?project_id=<?php echo (int) $row['id']; ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-list"></i> Full Issue List</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.page-header{background:linear-gradient(135deg,#f8f9fa 0%,#e9ecef 100%);border-radius:12px;padding:24px;border:1px solid #e9ecef;margin-bottom:2rem}.breadcrumb{background:none;padding:0;margin-bottom:16px}.breadcrumb-item a{color:#2563eb;text-decoration:none}.page-title{font-size:2rem;font-weight:700;color:#2c3e50;margin-bottom:8px}.page-subtitle{color:#6c757d;font-size:1.05rem;margin:0}.metric-card{border:1px solid #e9ecef;border-radius:12px;padding:20px;text-align:center}.metric-value{font-size:2rem;font-weight:700;line-height:1}.metric-label{margin-top:8px;color:#495057;font-weight:600}.data-table-wrap{background:#fff;border:1px solid #e9ecef;border-radius:12px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,.05)}.data-table-wrap thead th{background:#f8f9fa;padding:14px 16px;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em}.data-table-wrap tbody td{padding:16px;border-color:#eef2f7}.table-actions{display:flex;gap:8px;flex-wrap:wrap}@media (max-width:768px){.page-title{font-size:1.5rem}.table-actions{flex-direction:column}}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>