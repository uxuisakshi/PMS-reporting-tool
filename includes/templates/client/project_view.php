<?php
/**
 * Dashboard Detail View Template
 * Displays detailed analytics for a single digital asset
 */

// Ensure we have the required data
$projectAnalytics = $projectAnalytics ?? [];
$projectId = $projectId ?? 0;
$clientUser = $clientUser ?? [];
$csrfToken = $csrfToken ?? '';
$baseDir = $baseDir ?? '';

$projectName = $projectAnalytics['project_name'] ?? 'Digital Asset';
$projectDescription = $projectAnalytics['project_description'] ?? '';
$compliancePercentage = (float) ($projectAnalytics['compliance_percentage'] ?? 0);
$clientReadyIssues = (int) ($projectAnalytics['client_ready_issues'] ?? ($projectAnalytics['total_issues'] ?? 0));
$resolvedIssues = (int) ($projectAnalytics['resolved_issues'] ?? 0);
$pendingIssues = (int) ($projectAnalytics['pending_issues'] ?? 0);

$pageTitle = $projectName;
require_once __DIR__ . '/../../header.php';
?>

<div class="container-fluid py-4 px-lg-4">
    <div class="project-analytics-view client-project-view dashboard-shell">
        <section class="project-hero-card dashboard-hero-card">
            <div class="project-hero-grid">
                <div class="project-hero-copy">
                    <div class="hero-badge-row">
                        <span class="hero-badge hero-badge-light">
                            <i class="fas fa-chart-line"></i>
                            Digital Asset Analytics
                        </span>
                        <span class="hero-badge hero-badge-outline">
                            <i class="fas fa-shield-alt"></i>
                            Compliance <?php echo number_format($compliancePercentage, 1); ?>%
                        </span>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-3">
                            <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/client/dashboard">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Digital Asset Analytics</li>
                        </ol>
                    </nav>
                    <h1 class="hero-title mb-2"><?php echo htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <?php if ($projectDescription): ?>
                    <p class="hero-subtitle mb-0"><?php echo htmlspecialchars($projectDescription, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php else: ?>
                    <p class="hero-subtitle mb-0">Detailed issue patterns, compliance signals, and exportable reports for this digital asset.</p>
                    <?php endif; ?>
                </div>
                <div class="hero-metrics-panel project-hero-metrics">
                    <div class="hero-stat-card">
                        <span class="hero-stat-label">Visible issues</span>
                        <strong class="hero-stat-value"><?php echo number_format($clientReadyIssues); ?></strong>
                    </div>
                    <div class="hero-stat-card">
                        <span class="hero-stat-label">Resolved</span>
                        <strong class="hero-stat-value"><?php echo number_format($resolvedIssues); ?></strong>
                    </div>
                    <div class="hero-stat-card">
                        <span class="hero-stat-label">Open now</span>
                        <strong class="hero-stat-value"><?php echo number_format($pendingIssues); ?></strong>
                    </div>
                </div>
            </div>
            <div class="hero-toolbar project-hero-toolbar">
                <div class="hero-toolbar-links" aria-label="Analytics sections">
                    <a href="#project-kpis" class="hero-toolbar-link">Overview</a>
                    <a href="#project-reports" class="hero-toolbar-link">Reports</a>
                    <a href="#project-actions" class="hero-toolbar-link">Actions</a>
                </div>
                <div class="hero-toolbar-actions">
                    <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/client/issues_overview.php?project_id=<?php echo (int) $projectId; ?>" class="btn btn-light" title="Open Issue Summary">
                        <i class="fas fa-list-ul"></i> Issue Summary
                    </a>
                    <button type="button" class="btn btn-outline-light" data-project-refresh="1" title="Refresh Data">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button type="button" class="dropdown-item" data-project-export="pdf">
                                <i class="fas fa-file-pdf text-danger me-2"></i>Export as PDF
                            </button></li>
                            <li><button type="button" class="dropdown-item" data-project-export="excel">
                                <i class="fas fa-file-excel text-success me-2"></i>Export as Excel
                            </button></li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <section id="project-kpis" class="dashboard-section">
            <div class="row mb-4 g-3">
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm metric-card metric-card-primary text-center h-100">
                        <div class="card-body">
                            <div class="metric-card-icon"><i class="fas fa-bug"></i></div>
                            <div class="h2 mb-1 text-primary"><?php echo $clientReadyIssues; ?></div>
                            <div class="text-muted small">Visible Issues</div>
                            <div class="metric-card-meta">Current client-facing issue inventory</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm metric-card metric-card-success text-center h-100">
                        <div class="card-body">
                            <div class="metric-card-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="h2 mb-1 text-success"><?php echo $resolvedIssues; ?></div>
                            <div class="text-muted small">Resolved</div>
                            <div class="metric-card-meta">Issues already cleared for this asset</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm metric-card metric-card-warning text-center h-100">
                        <div class="card-body">
                            <div class="metric-card-icon"><i class="fas fa-hourglass-half"></i></div>
                            <div class="h2 mb-1 text-warning"><?php echo $pendingIssues; ?></div>
                            <div class="text-muted small">Open Issues</div>
                            <div class="metric-card-meta">Items still affecting live compliance</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm metric-card metric-card-info text-center h-100">
                        <div class="card-body">
                            <div class="metric-card-icon"><i class="fas fa-percentage"></i></div>
                            <div class="h2 mb-1 text-info"><?php echo number_format($compliancePercentage, 1); ?>%</div>
                            <div class="text-muted small">Compliance</div>
                            <div class="metric-card-meta">Weighted score across visible issues</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="project-reports" class="dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">Analytics Modules</span>
                    <h2 class="section-title mb-2">Detailed reports for this digital asset</h2>
                    <p class="section-description mb-0">Review severity, WCAG distribution, trend movement, and page-level concentration from a single workspace.</p>
                </div>
            </div>
            <?php
            $dashboardData = $projectAnalytics;
            try {
                if ($dashboardController && isset($dashboardController->visualization)) {
                    echo $dashboardController->visualization->getVisualizationCSS();
                }
                include __DIR__ . '/../../../modules/client/partials/dashboard_widgets.php';
            } catch (Throwable $e) {
                error_log('Client project analytics partial failed: ' . $e->getMessage());
                echo '<div class="alert alert-warning">Project analytics widgets could not be loaded.</div>';
            }
            ?>
        </section>

        <section id="project-actions" class="dashboard-section dashboard-inline-actions">
            <div class="section-heading compact-heading">
                <div>
                    <span class="section-kicker">Next Actions</span>
                    <h2 class="section-title mb-2">Move from analysis to action</h2>
                </div>
            </div>
            <div class="quick-actions-grid compact-actions-grid">
                <div class="action-card action-card-neutral">
                    <div class="action-icon text-primary"><i class="fas fa-list-check"></i></div>
                    <div class="action-content">
                        <span class="action-eyebrow">Issue workflow</span>
                        <h4 class="action-title">Review issue backlog</h4>
                        <p class="action-description">Review the client-visible issue totals for this asset without leaving the client-safe workflow.</p>
                        <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/client/issues_overview.php?project_id=<?php echo (int) $projectId; ?>" class="btn btn-primary action-button">
                            <i class="fas fa-arrow-right"></i> Open Summary
                        </a>
                    </div>
                </div>
                <div class="action-card action-card-info">
                    <div class="action-icon text-info"><i class="fas fa-file-export"></i></div>
                    <div class="action-content">
                        <span class="action-eyebrow">Share updates</span>
                        <h4 class="action-title">Export current analytics</h4>
                        <p class="action-description">Create a PDF or Excel snapshot when you need to circulate the latest dashboard state outside the app.</p>
                        <button type="button" data-project-export="pdf" class="btn btn-info action-button">
                            <i class="fas fa-download"></i> Export PDF
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Dashboard Visualization Scripts -->
<script src="https://code.highcharts.com/highcharts.js"></script>
<script src="https://code.highcharts.com/modules/accessibility.js"></script>
<?php 
try {
    if ($dashboardController && isset($dashboardController->visualization)) {
        echo $dashboardController->visualization->getVisualizationJS(); 
    }
} catch (Throwable $e) {
    error_log('Client project visualization JS failed: ' . $e->getMessage());
}
?>

<script nonce="<?php echo htmlspecialchars($cspNonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
window._projectHeaderConfig = {
    projectId: <?php echo json_encode((int) $projectId); ?>,
    clientId: <?php echo json_encode((int) ($projectAnalytics['project_metadata']['client_id'] ?? $projectAnalytics['client_id'] ?? 0)); ?>,
    baseDir: <?php echo json_encode($baseDir, JSON_HEX_TAG | JSON_HEX_AMP); ?>
};
</script>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/client-project-header.js"></script>

<?php 
require_once __DIR__ . '/../../footer.php';
?>