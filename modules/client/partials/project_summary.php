<?php
/**
 * Project Summary Cards Partial
 * 
 * Project-specific statistics and metrics
 */

$stats = $projectAnalytics['project_statistics'] ?? [];
// For client view, only show client-ready issues as total
$clientReadyIssues = $stats['client_ready_issues'] ?? 0;
$totalIssues = $clientReadyIssues; // Hide internal total from client
$openIssues = $stats['open_issues'] ?? 0;
$resolvedIssues = $stats['resolved_issues'] ?? 0;
$complianceScore = $stats['compliance_score'] ?? 0;
$criticalIssues = $stats['critical_issues'] ?? 0;
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="section-title">
            <i class="fas fa-chart-bar text-primary"></i>
            Project Statistics
        </h2>
    </div>
</div>

<div class="row mb-4">
    
    <!-- Total Issues -->
    <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
        <div class="project-stat-card">
            <div class="stat-icon">
                <i class="fas fa-list-ul text-primary"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($totalIssues); ?></div>
                <div class="stat-label">Total Issues</div>
            </div>
        </div>
    </div>

    <!-- Open Issues -->
    <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
        <div class="project-stat-card">
            <div class="stat-icon">
                <i class="fas fa-exclamation-circle text-warning"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($openIssues); ?></div>
                <div class="stat-label">Open Issues</div>
            </div>
        </div>
    </div>

    <!-- Resolved Issues -->
    <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
        <div class="project-stat-card">
            <div class="stat-icon">
                <i class="fas fa-check text-success"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($resolvedIssues); ?></div>
                <div class="stat-label">Resolved</div>
            </div>
        </div>
    </div>

    <!-- Critical Issues -->
    <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
        <div class="project-stat-card">
            <div class="stat-icon">
                <i class="fas fa-ban text-danger"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($criticalIssues); ?></div>
                <div class="stat-label">Critical</div>
            </div>
        </div>
    </div>

    <!-- Compliance Score -->
    <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
        <div class="project-stat-card">
            <div class="stat-icon">
                <i class="fas fa-shield-alt text-info"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo round($complianceScore, 1); ?>%</div>
                <div class="stat-label">Compliance</div>
                <div class="stat-progress">
                    <div class="progress" style="height: 4px;">
                        <div class="progress-bar bg-<?php echo $complianceScore >= 80 ? 'success' : ($complianceScore >= 50 ? 'warning' : 'danger'); ?>" 
                             style="width: <?php echo $complianceScore; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Additional Project Metrics -->
<div class="row mb-4">
    <div class="col-12">
        <div class="project-metrics-card">
            <h3 class="metrics-title">
                <i class="fas fa-analytics text-primary"></i>
                Key Performance Indicators
            </h3>
            
            <div class="metrics-grid">
                
                <!-- Resolution Rate -->
                <div class="metric-item">
                    <div class="metric-header">
                        <span class="metric-label">Resolution Rate</span>
                        <span class="metric-value">
                            <?php 
                            $resolutionRate = $totalIssues > 0 ? round(($resolvedIssues / $totalIssues) * 100, 1) : 0;
                            echo $resolutionRate; 
                            ?>%
                        </span>
                    </div>
                    <div class="metric-bar">
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-success" style="width: <?php echo $resolutionRate; ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Availability -->
                <div class="metric-item">
                    <div class="metric-header">
                        <span class="metric-label">Availability Rate</span>
                        <span class="metric-value">
                            <?php 
                            $readinessRate = $totalIssues > 0 ? round(($clientReadyIssues / $totalIssues) * 100, 1) : 0;
                            echo $readinessRate; 
                            ?>%
                        </span>
                    </div>
                    <div class="metric-bar">
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-info" style="width: <?php echo $readinessRate; ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Critical Issue Ratio -->
                <div class="metric-item">
                    <div class="metric-header">
                        <span class="metric-label">Critical Issue Ratio</span>
                        <span class="metric-value">
                            <?php 
                            $criticalRate = $totalIssues > 0 ? round(($criticalIssues / $totalIssues) * 100, 1) : 0;
                            echo $criticalRate; 
                            ?>%
                        </span>
                    </div>
                    <div class="metric-bar">
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-danger" style="width: <?php echo $criticalRate; ?>%"></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<style>
.project-stat-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    height: 100%;
}

.project-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.1);
    border-color: #2563eb;
}

.stat-icon {
    font-size: 2rem;
    margin-bottom: 12px;
    opacity: 0.8;
}

.stat-content {
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 4px;
    line-height: 1;
}

.stat-label {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 500;
    margin-bottom: 8px;
}

.stat-progress {
    margin-top: 8px;
}

.project-metrics-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.metrics-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.metric-item {
    padding: 16px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.metric-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.metric-label {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 500;
}

.metric-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2c3e50;
}

.metric-bar {
    margin-top: 8px;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .metrics-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
    }
}

@media (max-width: 768px) {
    .project-stat-card {
        padding: 16px;
    }
    
    .stat-value {
        font-size: 1.75rem;
    }
    
    .stat-icon {
        font-size: 1.5rem;
        margin-bottom: 8px;
    }
    
    .project-metrics-card {
        padding: 20px;
    }
    
    .metrics-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .metric-item {
        padding: 12px;
    }
}

@media (max-width: 576px) {
    .stat-value {
        font-size: 1.5rem;
    }
    
    .metrics-title {
        font-size: 1.1rem;
    }
}
</style>