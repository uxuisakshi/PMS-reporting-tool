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
            <div class="hero-badge-row">
                <span class="hero-badge hero-badge-light">
                    <i class="fas fa-layer-group"></i>
                    <?php echo $selectedProject ? 'Focused asset view' : 'Portfolio overview'; ?>
                </span>
                <span class="hero-badge hero-badge-outline">
                    <i class="fas fa-clock"></i>
                    Updated <?php echo htmlspecialchars($generatedAt !== '' ? date('M j, Y g:i A', strtotime($generatedAt)) : date('M j, Y g:i A')); ?>
                </span>
            </div>
            <h1 class="hero-title"><?php echo $selectedProject ? htmlspecialchars((string) ($selectedProject['title'] ?? 'Analytics Dashboard'), ENT_QUOTES, 'UTF-8') : 'Analytics Dashboard'; ?></h1>
            <p class="hero-subtitle mb-0">
                <?php if ($selectedProject): ?>
                    Accessibility analytics for the selected digital asset.
                <?php else: ?>
                    Accessibility analytics across <strong><?php echo $assetCount; ?></strong> digital assets.
                <?php endif; ?>
            </p>
        </div>
        <div class="hero-metrics-panel">
            <div class="hero-stat-card">
                <span class="hero-stat-label">Assets in scope</span>
                <strong class="hero-stat-value"><?php echo number_format($assetCount); ?></strong>
            </div>
            <div class="hero-stat-card">
                <span class="hero-stat-label">Open issues</span>
                <strong class="hero-stat-value"><?php echo number_format($openIssues); ?></strong>
            </div>
            <div class="hero-stat-card">
                <span class="hero-stat-label">Compliance</span>
                <strong class="hero-stat-value"><?php echo number_format($compliancePercentage, 1); ?>%</strong>
            </div>
        </div>
    </div>
    <div class="hero-toolbar">
        <div class="hero-toolbar-links" aria-label="Dashboard sections">
            <a href="#dashboard-overview" class="hero-toolbar-link">Overview</a>
            <a href="#dashboard-reports" class="hero-toolbar-link">Reports</a>
            <a href="#dashboard-next-steps" class="hero-toolbar-link">Actions</a>
        </div>
        <div class="hero-toolbar-actions dashboard-controls">
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
            <div class="control-group">
                <label class="form-label small mb-1">Export Reports</label>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-light btn-sm" data-dashboard-export="pdf">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" data-dashboard-export="excel">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                </div>
            </div>
            <div class="control-group">
                <label class="form-label small mb-1">Refresh</label>
                <button type="button" class="btn btn-outline-light btn-sm" data-dashboard-refresh="1">
                    <i class="fas fa-sync-alt"></i> Reload
                </button>
            </div>
            <div class="control-group dashboard-status-chip" aria-hidden="true">
                <span class="hero-inline-stat"><?php echo number_format($resolvedIssues); ?> resolved</span>
            </div>
        </div>
    </div>
</div>