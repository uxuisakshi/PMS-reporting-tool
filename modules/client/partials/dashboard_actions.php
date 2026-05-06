<?php
/**
 * Dashboard Quick Actions Partial
 * 
 * Quick action buttons and navigation links
 */

$assignedProjects = $dashboardData['assigned_projects'] ?? [];
$projectIdsList = implode(',', array_column($assignedProjects, 'id'));
?>

<div class="section-heading compact-heading">
    <div>
        <span class="section-kicker">Next Steps</span>
        <h2 class="section-title mb-2">Quick actions</h2>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="quick-actions-grid">

            <!-- Export PDF Report -->
            <div class="action-card action-card-success">
                <div class="action-icon text-success">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <div class="action-content">
                    <span class="action-eyebrow">Shareable summary</span>
                    <h4 class="action-title">Export PDF Report</h4>
                    <p class="action-description">Download comprehensive analytics as a PDF document</p>
                    <button type="button" data-dashboard-export="pdf" class="btn btn-success action-button">
                        <i class="fas fa-download"></i> Download PDF
                    </button>
                </div>
            </div>

            <!-- Export Excel Data -->
            <div class="action-card action-card-info">
                <div class="action-icon text-info">
                    <i class="fas fa-file-excel"></i>
                </div>
                <div class="action-content">
                    <span class="action-eyebrow">Raw data</span>
                    <h4 class="action-title">Export Excel Data</h4>
                    <p class="action-description">Download raw analytics data in Excel format for analysis</p>
                    <button type="button" data-dashboard-export="excel" class="btn btn-info action-button">
                        <i class="fas fa-download"></i> Download Excel
                    </button>
                </div>
            </div>

            <!-- View Digital Assets -->
            <div class="action-card action-card-neutral">
                <div class="action-icon text-secondary">
                    <i class="fas fa-folder-open"></i>
                </div>
                <div class="action-content">
                    <span class="action-eyebrow">Portfolio navigation</span>
                    <h4 class="action-title">View Digital Assets</h4>
                          <p class="action-description">Browse all digital assets from one place</p>
                    <a href="<?php echo $baseDir; ?>/modules/client/projects.php" 
                       class="btn btn-secondary action-button">
                        <i class="fas fa-arrow-right"></i> Browse Digital Assets
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/client-dashboard-actions.js"></script>