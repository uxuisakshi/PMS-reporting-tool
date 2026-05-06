<?php
/**
 * Project Analytics Widgets Partial
 * 
 * Detailed analytics widgets for individual project
 */

$analyticsWidgets = $projectAnalytics['analytics_widgets'] ?? [];
$activeReport = (string) ($_GET['report'] ?? '');
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="section-title">
            <i class="fas fa-chart-pie text-primary"></i>
            Detailed Analytics
        </h2>
        <p class="text-muted">Comprehensive analytics for this digital asset</p>
    </div>
</div>

<?php if (!empty($analyticsWidgets)): ?>

<!-- First Row: User Impact and WCAG Compliance -->
<div class="row mb-4">
    <?php if (isset($analyticsWidgets['user_affected'])): ?>
    <div class="col-lg-6 mb-4">
        <div class="project-analytics-widget<?php echo $activeReport === 'user_affected' ? ' is-active' : ''; ?>" id="analytics-report-user_affected">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['user_affected']); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($analyticsWidgets['wcag_compliance'])): ?>
    <div class="col-lg-6 mb-4">
        <div class="project-analytics-widget<?php echo $activeReport === 'wcag_compliance' ? ' is-active' : ''; ?>" id="analytics-report-wcag_compliance">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['wcag_compliance']); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Second Row: Severity and Common Issues -->
<div class="row mb-4">
    <?php if (isset($analyticsWidgets['severity_analysis'])): ?>
    <div class="col-lg-6 mb-4">
        <div class="project-analytics-widget<?php echo $activeReport === 'severity_analysis' ? ' is-active' : ''; ?>" id="analytics-report-severity_analysis">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['severity_analysis']); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($analyticsWidgets['common_issues'])): ?>
    <div class="col-lg-6 mb-4">
        <div class="project-analytics-widget<?php echo $activeReport === 'common_issues' ? ' is-active' : ''; ?>" id="analytics-report-common_issues">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['common_issues']); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Third Row: Blockers and Page Issues -->
<div class="row mb-4">
    <?php if (isset($analyticsWidgets['blocker_issues'])): ?>
    <div class="col-lg-6 mb-4">
        <div class="project-analytics-widget<?php echo $activeReport === 'blocker_issues' ? ' is-active' : ''; ?>" id="analytics-report-blocker_issues">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['blocker_issues']); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($analyticsWidgets['page_issues'])): ?>
    <div class="col-lg-6 mb-4">
        <div class="project-analytics-widget<?php echo $activeReport === 'page_issues' ? ' is-active' : ''; ?>" id="analytics-report-page_issues">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['page_issues']); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Fourth Row: Comments and Trends -->
<div class="row mb-4">
    <?php if (isset($analyticsWidgets['commented_issues'])): ?>
    <div class="col-lg-6 mb-4">
        <div class="project-analytics-widget<?php echo $activeReport === 'commented_issues' ? ' is-active' : ''; ?>" id="analytics-report-commented_issues">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['commented_issues']); ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-lg-6 mb-4">
        <!-- Project Health Score Widget -->
        <div class="project-analytics-widget">
            <div class="dashboard-widget health-score-widget">
                <div class="widget-header">
                    <h3 class="widget-title">
                        <i class="fas fa-heartbeat text-danger"></i>
                        Project Health Score
                    </h3>
                </div>
                <div class="widget-content">
                    <?php
                    // Calculate health score based on various metrics
                    $healthScore = 0;
                    $factors = [];
                    
                    // Compliance score factor (40% weight)
                    $complianceWeight = 0.4;
                    $complianceFactor = ($complianceScore / 100) * $complianceWeight;
                    $healthScore += $complianceFactor * 100;
                    $factors[] = ['label' => 'Compliance', 'score' => $complianceScore, 'weight' => 40];
                    
                    // Resolution rate factor (30% weight)
                    $resolutionWeight = 0.3;
                    $resolutionRate = $totalIssues > 0 ? ($resolvedIssues / $totalIssues) * 100 : 100;
                    $resolutionFactor = ($resolutionRate / 100) * $resolutionWeight;
                    $healthScore += $resolutionFactor * 100;
                    $factors[] = ['label' => 'Resolution Rate', 'score' => $resolutionRate, 'weight' => 30];
                    
                    // Critical issues factor (20% weight) - inverse scoring
                    $criticalWeight = 0.2;
                    $criticalRate = $totalIssues > 0 ? ($criticalIssues / $totalIssues) * 100 : 0;
                    $criticalFactor = (1 - ($criticalRate / 100)) * $criticalWeight;
                    $healthScore += $criticalFactor * 100;
                    $factors[] = ['label' => 'Critical Issues', 'score' => 100 - $criticalRate, 'weight' => 20];
                    
                    // Availability factor (10% weight)
                    $readinessWeight = 0.1;
                    $readinessRate = $totalIssues > 0 ? ($clientReadyIssues / $totalIssues) * 100 : 100;
                    $readinessFactor = ($readinessRate / 100) * $readinessWeight;
                    $healthScore += $readinessFactor * 100;
                    $factors[] = ['label' => 'Availability', 'score' => $readinessRate, 'weight' => 10];
                    
                    $healthScore = round($healthScore, 1);
                    
                    // Determine health status
                    $healthStatus = 'excellent';
                    $healthColor = 'success';
                    $healthIcon = 'fa-heart';
                    
                    if ($healthScore < 50) {
                        $healthStatus = 'critical';
                        $healthColor = 'danger';
                        $healthIcon = 'fa-heart-broken';
                    } elseif ($healthScore < 70) {
                        $healthStatus = 'needs attention';
                        $healthColor = 'warning';
                        $healthIcon = 'fa-heartbeat';
                    } elseif ($healthScore < 85) {
                        $healthStatus = 'good';
                        $healthColor = 'info';
                        $healthIcon = 'fa-heart';
                    }
                    ?>
                    
                    <div class="health-score-display">
                        <div class="score-circle">
                            <div class="score-value text-<?php echo $healthColor; ?>">
                                <?php echo $healthScore; ?>%
                            </div>
                            <div class="score-label">Health Score</div>
                        </div>
                        <div class="score-status">
                            <i class="fas <?php echo $healthIcon; ?> text-<?php echo $healthColor; ?>"></i>
                            <span class="status-text text-<?php echo $healthColor; ?>">
                                <?php echo ucfirst($healthStatus); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="health-factors">
                        <h5>Contributing Factors</h5>
                        <?php foreach ($factors as $factor): ?>
                        <div class="factor-item">
                            <div class="factor-header">
                                <span class="factor-label"><?php echo $factor['label']; ?></span>
                                <span class="factor-weight"><?php echo $factor['weight']; ?>% weight</span>
                            </div>
                            <div class="factor-score">
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-<?php echo $factor['score'] >= 80 ? 'success' : ($factor['score'] >= 60 ? 'warning' : 'danger'); ?>" 
                                         style="width: <?php echo $factor['score']; ?>%"></div>
                                </div>
                                <small class="text-muted"><?php echo round($factor['score'], 1); ?>%</small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Full Width: Compliance Trends -->
<?php if (isset($analyticsWidgets['compliance_trend'])): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="project-analytics-widget<?php echo $activeReport === 'compliance_trend' ? ' is-active' : ''; ?>" id="analytics-report-compliance_trend">
            <?php echo $dashboardController->visualization->renderDashboardWidget('trend', $analyticsWidgets['compliance_trend']); ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- No Analytics Data -->
<div class="row">
    <div class="col-12">
        <div class="no-analytics-state text-center py-5">
            <div class="no-data-icon mb-4">
                <i class="fas fa-chart-pie fa-4x text-muted opacity-50"></i>
            </div>
            <h3 class="text-muted">No Analytics Data Available</h3>
            <p class="text-muted mb-4">
                This project doesn't have any accessibility issues available yet. Analytics will appear once issues are available for review.
            </p>
            <a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $projectId, (string) ($project['title'] ?? ''), (string) ($project['project_code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">
                <i class="fas fa-eye"></i> Return to Asset Analytics
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.project-analytics-widget {
    height: 100%;
}

.project-analytics-widget .dashboard-widget {
    height: 100%;
    min-height: 400px;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.project-analytics-widget .dashboard-widget:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    border-color: #2563eb;
}

.project-analytics-widget.is-active .dashboard-widget,
.project-analytics-widget .dashboard-widget.is-active {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15), 0 12px 30px rgba(37, 99, 235, 0.12);
}

.health-score-widget .widget-content {
    padding: 24px;
}

.health-score-display {
    text-align: center;
    margin-bottom: 24px;
}

.score-circle {
    display: inline-block;
    padding: 20px;
    border: 3px solid #e9ecef;
    border-radius: 50%;
    margin-bottom: 16px;
    background: #f8f9fa;
}

.score-value {
    font-size: 2.5rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 4px;
}

.score-label {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 500;
}

.score-status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 1.1rem;
    font-weight: 600;
}

.health-factors h5 {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 16px;
    text-align: center;
}

.factor-item {
    margin-bottom: 16px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.factor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.factor-label {
    font-size: 0.9rem;
    font-weight: 500;
    color: #495057;
}

.factor-weight {
    font-size: 0.8rem;
    color: #6c757d;
    font-weight: 500;
}

.factor-score {
    display: flex;
    align-items: center;
    gap: 8px;
}

.factor-score .progress {
    flex: 1;
}

.no-analytics-state {
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px dashed #dee2e6;
    margin: 2rem 0;
}

/* Responsive Design */
@media (max-width: 768px) {
    .project-analytics-widget .dashboard-widget {
        min-height: 350px;
    }
    
    .score-circle {
        padding: 16px;
    }
    
    .score-value {
        font-size: 2rem;
    }
    
    .health-factors {
        margin-top: 20px;
    }
    
    .factor-item {
        padding: 10px;
        margin-bottom: 12px;
    }
}

@media (max-width: 576px) {
    .project-analytics-widget .dashboard-widget {
        min-height: 300px;
    }
    
    .score-value {
        font-size: 1.75rem;
    }
    
    .factor-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
}
</style>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/client-dashboard-widgets.js?v=<?php echo urlencode((string) ($assetVersion ?? '20260406v16')); ?>"></script>