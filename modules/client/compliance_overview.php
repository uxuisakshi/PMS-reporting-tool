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

$assignedProjects = $accessControl->getAssignedProjects($clientUserId);
$pageTitle = 'Compliance Overview';

$rows = [];
foreach ($assignedProjects as $project) {
    $stats = $accessControl->getProjectStatistics($clientUserId, $project['id']);
    $rows[] = [
        'id' => (int) $project['id'],
        'title' => $project['title'] ?? 'Digital Asset',
        'compliance' => round((float) ($stats['compliance_score'] ?? 0), 1),
        'open_issues' => (int) ($stats['open_issues'] ?? 0),
        'resolved_issues' => (int) ($stats['resolved_issues'] ?? 0),
        'total_issues' => (int) ($stats['client_ready_issues'] ?? 0)
    ];
}

usort($rows, function ($left, $right) {
    return $right['compliance'] <=> $left['compliance'];
});

$averageCompliance = 0;
if (!empty($rows)) {
    $averageCompliance = round(array_sum(array_column($rows, 'compliance')) / count($rows), 1);
}
$highestCompliance = !empty($rows) ? $rows[0] : null;
$lowestCompliance = !empty($rows) ? $rows[count($rows) - 1] : null;

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid" id="main-content" tabindex="-1">
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-header">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/client/dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><i class="fas fa-percentage"></i> Compliance Overview</li>
                    </ol>
                </nav>
                <div class="header-content">
                    <h1 class="page-title"><i class="fas fa-percentage text-info"></i> Compliance Overview</h1>
                    <p class="page-subtitle">Compliance health across your digital assets</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="metric-card border-info-subtle bg-info-subtle">
                <div class="metric-value text-info"><?php echo number_format($averageCompliance, 1); ?>%</div>
                <div class="metric-label">Average Compliance</div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="metric-card border-success-subtle bg-success-subtle">
                <div class="metric-value text-success"><?php echo $highestCompliance ? number_format($highestCompliance['compliance'], 1) . '%' : '0%'; ?></div>
                <div class="metric-label">Best Asset</div>
                <small class="text-muted"><?php echo htmlspecialchars($highestCompliance['title'] ?? 'N/A'); ?></small>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="metric-card border-warning-subtle bg-warning-subtle">
                <div class="metric-value text-warning"><?php echo $lowestCompliance ? number_format($lowestCompliance['compliance'], 1) . '%' : '0%'; ?></div>
                <div class="metric-label">Needs Attention</div>
                <small class="text-muted"><?php echo htmlspecialchars($lowestCompliance['title'] ?? 'N/A'); ?></small>
            </div>
        </div>
    </div>

    <div class="table-responsive data-table-wrap">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Digital Asset</th>
                    <th class="text-end">Compliance</th>
                    <th class="text-end">Open Issues</th>
                    <th class="text-end">Resolved</th>
                    <th class="text-end">Total Issues</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['title']); ?></strong></td>
                    <td class="text-end fw-semibold text-info"><?php echo number_format($row['compliance'], 1); ?>%</td>
                    <td class="text-end text-warning fw-semibold"><?php echo number_format($row['open_issues']); ?></td>
                    <td class="text-end text-success fw-semibold"><?php echo number_format($row['resolved_issues']); ?></td>
                    <td class="text-end fw-semibold"><?php echo number_format($row['total_issues']); ?></td>
                    <td>
                        <div class="table-actions">
                            <a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $row['id'], (string) ($row['title'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary btn-sm"><i class="fas fa-chart-line"></i> Analytics</a>
                            <a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $row['id'], (string) ($row['title'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-eye"></i> Overview</a>
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