<?php
/**
 * Dashboard Header Partial
 * 
 * Header section with title, project selector, and export buttons
 */

$assignedProjects = $dashboardData['assigned_projects'] ?? [];
$projectStats = $dashboardData['project_statistics'] ?? [];
$selectedProjectId = $_GET['project_id'] ?? null;
$selectedProject = $dashboardData['selected_project'] ?? null;
$generatedAt = $dashboardData['generated_at'] ?? '';
$openIssues = (int) ($projectStats['open_issues'] ?? 0);
$resolvedIssues = (int) ($projectStats['resolved_issues'] ?? 0);
$compliancePercentage = (float) ($dashboardData['compliance_percentage'] ?? 0);
$assetCount = count($assignedProjects);
?>

<div class="dashboard-hero-card">
    <div class="dashboard-hero-grid">
        <div class="dashboard-hero-copy">
            <h1 class="hero-title">
                <?php echo $selectedProject ? htmlspecialchars((string) ($selectedProject['title'] ?? 'Analytics Dashboard'), ENT_QUOTES, 'UTF-8') : 'Analytics Dashboard'; ?>
            </h1>
            <p class="hero-subtitle mb-0">
                <?php if ($selectedProject): ?>
                    Accessibility analytics for the selected digital asset.
                <?php else: ?>
                    Accessibility analytics across <strong><?php echo $assetCount; ?></strong> digital assets.
                <?php endif; ?>
            </p>
            <div class="hero-badge-row">
                <span class="hero-badge hero-badge-light">
                    <i class="fas fa-layer-group"></i>
                    <?php echo $selectedProject ? 'Focused asset view' : 'Portfolio overview'; ?>
                </span>
                <span class="hero-badge hero-badge-outline">
                    <i class="fas fa-clock"></i>
                    Updated
                    <?php echo htmlspecialchars($generatedAt !== '' ? date('M j, Y g:i A', strtotime($generatedAt)) : date('M j, Y g:i A')); ?>
                </span>
            </div>
        </div>
        <div class="hero-metrics-panel">
            <div class="row">
                <div class="col-xl-3 col-md-6">
                    <a href="<?php echo htmlspecialchars($cardLinks['assets'], ENT_QUOTES, 'UTF-8'); ?>"
                        class="summary-card-link">
                        <div class="summary-card card h-100 border-0 shadow-sm summary-card-primary">
                            <div class="card-body d-flex flex-column h-100">
                                <div class="summary-card-topline">Assets in Scope</div>
                                <div class="summary-card-main">
                                    <h3 class="summary-value text-primary mb-0">
                                        <?php echo number_format($totalProjects); ?>
                                    </h3>
                                </div>
                                <div class="mt-auto">
                                    <p class="summary-label mb-2">Digital Assets</p>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-xl-3 col-md-6">
                    <a href="<?php echo htmlspecialchars($cardLinks['issues'], ENT_QUOTES, 'UTF-8'); ?>"
                        class="summary-card-link">
                        <div class="summary-card card h-100 border-0 shadow-sm summary-card-warning">
                            <div class="card-body d-flex flex-column h-100">
                                <div class="summary-card-topline">Open issues</div>
                                <div class="summary-card-main">
                                    <h3 class="summary-value text-warning mb-0">
                                        <?php echo number_format($openIssues); ?>
                                    </h3>
                                </div>
                                <div class="mt-auto">
                                    <p class="summary-label mb-2">Issue</p>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-xl-3 col-md-6">
                    <a href="<?php echo htmlspecialchars($cardLinks['resolved'], ENT_QUOTES, 'UTF-8'); ?>"
                        class="summary-card-link">
                        <div class="summary-card card h-100 border-0 shadow-sm summary-card-success">
                            <div class="card-body d-flex flex-column h-100">
                                <div class="summary-card-topline">Progress</div>
                                <div class="summary-card-main">
                                    <h3 class="summary-value text-success mb-0">
                                        <?php echo number_format($resolvedIssues); ?>
                                    </h3>
                                </div>
                                <div class="mt-auto">
                                    <p class="summary-label mb-2">Resolved Issues</p>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-xl-3 col-md-6">
                    <a href="<?php echo htmlspecialchars($cardLinks['compliance'], ENT_QUOTES, 'UTF-8'); ?>"
                        class="summary-card-link">
                        <div class="summary-card card h-100 border-0 shadow-sm summary-card-info">
                            <div class="card-body d-flex flex-column h-100">
                                <div class="summary-card-topline">Compliance</div>
                                <div class="summary-card-main">
                                    <h3 class="summary-value text-info mb-0"><?php echo $compliancePercentage; ?>%</h3>
                                </div>
                                <div class="mt-auto">
                                    <p class="summary-label mb-2">Compliance score</p>
                                    <!-- <small class="text-muted">Jump to the WCAG compliance widget and trend line.</small> -->
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <section class="widget-container analytics-tab-panel" id="analytics-report-commented_issues"
        data-report-panel="commented_issues" role="tabpanel" aria-labelledby="analytics-tab-commented_issues"
        data-landmark-index="10">
        <div class="dashboard-widget analytics-widget" id="widget_chart_7_69fc54870c776"
            data-report-type="commented_issues">
            <div class="widget-content">
                <div class="hero-toolbar">
                    <div class="hero-toolbar-links" aria-label="Dashboard sections">
                        <a href="#dashboard-overview" class="hero-toolbar-link">Overview</a>
                        <a href="#dashboard-reports" class="hero-toolbar-link">Reports</a>
                        <a href="#dashboard-next-steps" class="hero-toolbar-link">Actions</a>
                    </div>
                    <div class="hero-toolbar-actions dashboard-controls">



                        <div class="control-group dashboard-status-chip" aria-hidden="true">
                            <span class="hero-inline-stat">
                                <?php echo number_format($resolvedIssues); ?> resolved
                            </span>
                        </div>
                    </div>

                </div>

                <div class="analytics-summary">
                    <div class="summary-metric">
                        <div class="control-group control-group-wide">
                            <label for="projectFilter" class="form-label small mb-1">Filter by Digital Asset</label>
                            <select id="projectFilter" class="form-select form-select-sm">
                                <option value="">All Digital Assets</option>
                                <?php foreach ($assignedProjects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" <?php echo ($selectedProjectId == $project['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="summary-metric">
                        <div class="control-group">
                            <label class="form-label small mb-1">Export Reports</label>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-light btn-sm" data-dashboard-export="pdf">
                                    <i class="fas fa-file-pdf"></i> PDF
                                </button> <button type="button" class="btn btn-outline-light btn-sm"
                                    data-dashboard-export="excel">
                                    <i class="fas fa-file-excel"></i> Excel
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="summary-metric">
                        <div class="control-group">
                            <label class="form-label small mb-1">Refresh</label>
                            <button type="button" class="btn btn-outline-light btn-sm" data-dashboard-refresh="1">
                                <i class="fas fa-sync-alt"></i> Reload
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>