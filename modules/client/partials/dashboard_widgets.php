<?php
/**
 * Dashboard Analytics Widgets Partial
 * 
 * Grid layout of analytics widgets with drill-down capabilities
 */

$analyticsWidgets = $dashboardData['analytics_widgets'] ?? [];
$assignedProjects = $dashboardData['assigned_projects'] ?? [];
$projectIdsList = implode(',', array_column($assignedProjects, 'id'));
$activeReport = (string) ($_GET['report'] ?? '');
$commonIssuesWidget = $analyticsWidgets['common_issues'] ?? [
    'type' => 'analytics',
    'title' => 'Common Issues',
    'icon' => 'fas fa-list-ul',
    'reportType' => 'common_issues',
    'drillDownUrl' => '',
    'summary' => [],
    'emptyMessage' => '0 common issues found.'
];

if (empty($commonIssuesWidget['summary'])) {
    $commonIssuesWidget['emptyMessage'] = $commonIssuesWidget['emptyMessage'] ?? '0 common issues found.';
}

$reportTabs = [];

if (isset($analyticsWidgets['user_affected'])) {
    $reportTabs[] = [
        'key' => 'user_affected',
        'label' => 'User Impact',
        'widgetType' => 'analytics',
        'widgetData' => $analyticsWidgets['user_affected'],
        'fullWidth' => false,
    ];
}

if (isset($analyticsWidgets['wcag_compliance'])) {
    $reportTabs[] = [
        'key' => 'wcag_compliance',
        'label' => 'WCAG',
        'widgetType' => 'analytics',
        'widgetData' => $analyticsWidgets['wcag_compliance'],
        'fullWidth' => false,
    ];
}

if (isset($analyticsWidgets['severity_analysis'])) {
    $reportTabs[] = [
        'key' => 'severity_analysis',
        'label' => 'Severity',
        'widgetType' => 'analytics',
        'widgetData' => $analyticsWidgets['severity_analysis'],
        'fullWidth' => false,
    ];
}

$reportTabs[] = [
    'key' => 'common_issues',
    'label' => 'Common Issues',
    'widgetType' => 'analytics',
    'widgetData' => $commonIssuesWidget,
    'fullWidth' => false,
];

if (isset($analyticsWidgets['blocker_issues'])) {
    $reportTabs[] = [
        'key' => 'blocker_issues',
        'label' => 'Blockers',
        'widgetType' => 'analytics',
        'widgetData' => $analyticsWidgets['blocker_issues'],
        'fullWidth' => false,
    ];
}

if (isset($analyticsWidgets['page_issues'])) {
    $reportTabs[] = [
        'key' => 'page_issues',
        'label' => 'Pages',
        'widgetType' => 'analytics',
        'widgetData' => $analyticsWidgets['page_issues'],
        'fullWidth' => false,
    ];
}

if (isset($analyticsWidgets['commented_issues'])) {
    $reportTabs[] = [
        'key' => 'commented_issues',
        'label' => 'Discussion',
        'widgetType' => 'analytics',
        'widgetData' => $analyticsWidgets['commented_issues'],
        'fullWidth' => false,
    ];
}

if (isset($analyticsWidgets['compliance_trend'])) {
    $reportTabs[] = [
        'key' => 'compliance_trend',
        'label' => 'Trend',
        'widgetType' => 'trend',
        'widgetData' => $analyticsWidgets['compliance_trend'],
        'fullWidth' => true,
    ];
}

$availableReportKeys = array_column($reportTabs, 'key');
if ($activeReport === '' || !in_array($activeReport, $availableReportKeys, true)) {
    $activeReport = $availableReportKeys[0] ?? '';
}
?>

<div class="section-heading">
    <div>
        <span class="section-kicker">Report Workspace</span>
        <h2 class="section-title mb-2">Analytics reports</h2>
    </div>
</div>

<?php if (!empty($reportTabs)): ?>
<div class="analytics-tabs-shell">
    <div class="analytics-tabs-intro">
        <span class="analytics-tabs-label">Report tabs</span>
    </div>
    <div class="analytics-shortcuts" role="tablist" aria-label="Analytics report tabs">
        <?php foreach ($reportTabs as $reportTab): ?>
            <?php $isActiveTab = $activeReport === $reportTab['key']; ?>
            <button
                type="button"
                class="analytics-shortcut-pill<?php echo $isActiveTab ? ' is-active' : ''; ?>"
                id="analytics-tab-<?php echo htmlspecialchars($reportTab['key'], ENT_QUOTES, 'UTF-8'); ?>"
                data-report-tab="<?php echo htmlspecialchars($reportTab['key'], ENT_QUOTES, 'UTF-8'); ?>"
                data-report-target="analytics-report-<?php echo htmlspecialchars($reportTab['key'], ENT_QUOTES, 'UTF-8'); ?>"
                role="tab"
                aria-selected="<?php echo $isActiveTab ? 'true' : 'false'; ?>"
                aria-controls="analytics-report-<?php echo htmlspecialchars($reportTab['key'], ENT_QUOTES, 'UTF-8'); ?>"
                tabindex="<?php echo $isActiveTab ? '0' : '-1'; ?>"
            ><?php echo htmlspecialchars($reportTab['label'], ENT_QUOTES, 'UTF-8'); ?></button>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Analytics Widgets Grid -->
<div class="analytics-tab-panels">
    <?php if (!empty($reportTabs)): ?>
        <?php foreach ($reportTabs as $reportTab): ?>
        <?php $isActivePanel = $activeReport === $reportTab['key']; ?>
        <section
            class="widget-container analytics-tab-panel<?php echo $reportTab['fullWidth'] ? ' widget-full-width' : ''; ?><?php echo $isActivePanel ? ' is-active' : ''; ?>"
            id="analytics-report-<?php echo htmlspecialchars($reportTab['key'], ENT_QUOTES, 'UTF-8'); ?>"
            data-report-panel="<?php echo htmlspecialchars($reportTab['key'], ENT_QUOTES, 'UTF-8'); ?>"
            role="tabpanel"
            aria-labelledby="analytics-tab-<?php echo htmlspecialchars($reportTab['key'], ENT_QUOTES, 'UTF-8'); ?>"
            <?php echo $isActivePanel ? '' : 'hidden'; ?>
        >
            <?php echo $dashboardController->visualization->renderDashboardWidget($reportTab['widgetType'], $reportTab['widgetData']); ?>
        </section>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- No Data State -->
        <div class="col-12">
            <div class="no-data-state text-center py-5">
                <div class="no-data-icon mb-4">
                    <i class="fas fa-chart-bar fa-4x text-muted opacity-50"></i>
                </div>
                <h3 class="text-muted">No Analytics Data Available</h3>
                <p class="text-muted mb-4">
                    Analytics widgets will appear here when data is available.
                </p>
                <a href="<?php echo $baseDir; ?>/modules/client/projects.php" class="btn btn-primary">
                    <i class="fas fa-folder-open"></i> View Digital Assets
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/client-dashboard-widgets.js?v=<?php echo urlencode((string) ($assetVersion ?? '20260406v16')); ?>"></script>