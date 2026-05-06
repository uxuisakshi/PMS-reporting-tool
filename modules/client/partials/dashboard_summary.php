<?php
/**
 * Dashboard Summary Cards Partial
 * 
 * Overview statistics cards showing key metrics
 */

$projectStats = $dashboardData['project_statistics'] ?? [];
$totalProjects = $projectStats['total_projects'] ?? 0;
$clientReadyIssues = $projectStats['client_ready_issues'] ?? 0;
// For client view, client-ready issues ARE the total issues
$totalIssues = $clientReadyIssues; // Hide internal total count from client
$openIssues = (int) ($projectStats['open_issues'] ?? 0);
$resolvedIssues = (int) ($projectStats['resolved_issues'] ?? 0);
$compliancePercentage = $dashboardData['compliance_percentage'] ?? 0;
$cardLinks = [
    'assets' => $baseDir . '/modules/client/projects.php',
    'issues' => '#analytics-report-severity_analysis',
    'resolved' => '#analytics-report-compliance_trend',
    'compliance' => '#analytics-report-wcag_compliance'
];
?>

<div class="section-heading">
    <div>
        <span class="section-kicker">Portfolio Snapshot</span>
        <h2 class="section-title mb-2">Overview statistics</h2>
    </div>
</div>

<div class="row mb-4 g-3">
    <div class="col-xl-3 col-md-6">
        <a href="<?php echo htmlspecialchars($cardLinks['assets'], ENT_QUOTES, 'UTF-8'); ?>" class="summary-card-link">
        <div class="summary-card card h-100 border-0 shadow-sm summary-card-primary">
            <div class="card-body d-flex flex-column h-100">
                <div class="summary-card-topline">Digital Assets</div>
                <div class="summary-card-main">
                    <div class="summary-icon"><i class="fas fa-folder-open"></i></div>
                    <h3 class="summary-value text-primary mb-0"><?php echo number_format($totalProjects); ?></h3>
                </div>
                <div class="mt-auto">
                    <p class="summary-label mb-2">Digital Assets</p>
                </div>
            </div>
        </div>
        </a>
    </div>

    <div class="col-xl-3 col-md-6">
        <a href="<?php echo htmlspecialchars($cardLinks['issues'], ENT_QUOTES, 'UTF-8'); ?>" class="summary-card-link">
        <div class="summary-card card h-100 border-0 shadow-sm summary-card-warning">
            <div class="card-body d-flex flex-column h-100">
                <div class="summary-card-topline">Active workload</div>
                <div class="summary-card-main">
                    <div class="summary-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3 class="summary-value text-warning mb-0"><?php echo number_format($openIssues); ?></h3>
                </div>
                <div class="mt-auto">
                    <p class="summary-label mb-2">Open Issues</p>
                    <small class="text-muted"><?php echo number_format($totalIssues); ?> visible issues right now.</small>
                </div>
            </div>
        </div>
        </a>
    </div>

    <div class="col-xl-3 col-md-6">
        <a href="<?php echo htmlspecialchars($cardLinks['resolved'], ENT_QUOTES, 'UTF-8'); ?>" class="summary-card-link">
        <div class="summary-card card h-100 border-0 shadow-sm summary-card-success">
            <div class="card-body d-flex flex-column h-100">
                <div class="summary-card-topline">Progress</div>
                <div class="summary-card-main">
                    <div class="summary-icon"><i class="fas fa-check-circle"></i></div>
                    <h3 class="summary-value text-success mb-0"><?php echo number_format($resolvedIssues); ?></h3>
                </div>
                <div class="mt-auto">
                    <p class="summary-label mb-2">Resolved Issues</p>
                </div>
            </div>
        </div>
        </a>
    </div>

    <div class="col-xl-3 col-md-6">
        <a href="<?php echo htmlspecialchars($cardLinks['compliance'], ENT_QUOTES, 'UTF-8'); ?>" class="summary-card-link">
        <div class="summary-card card h-100 border-0 shadow-sm summary-card-info">
            <div class="card-body d-flex flex-column h-100">
                <div class="summary-card-topline">Compliance signal</div>
                <div class="summary-card-main">
                    <div class="summary-icon"><i class="fas fa-percentage"></i></div>
                    <h3 class="summary-value text-info mb-0"><?php echo $compliancePercentage; ?>%</h3>
                </div>
                <div class="mt-auto">
                    <p class="summary-label mb-2">Compliance Percentage</p>
                    <small class="text-muted">Jump to the WCAG compliance widget and trend line.</small>
                    <div class="progress summary-progress mt-3">
                        <div class="progress-bar bg-info" 
                             style="width: <?php echo $compliancePercentage; ?>%"
                             role="progressbar" 
                             aria-valuenow="<?php echo $compliancePercentage; ?>" 
                             aria-valuemin="0" 
                             aria-valuemax="100">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </a>
    </div>
</div>