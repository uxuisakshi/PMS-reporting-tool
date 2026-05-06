<?php
/**
 * Dashboard Template
 * Displays unified analytics dashboard with interactive charts
 */

// Ensure we have the required data
$assignedProjects = $assignedProjects ?? [];
$dashboardData = $dashboardData ?? [];
$csrfToken = $csrfToken ?? '';
$clientUser = $clientUser ?? [];
$pageTitle = $pageTitle ?? 'Analytics Dashboard';

// Extract project IDs for JavaScript
$projectIds = array_column($assignedProjects, 'id');

require_once __DIR__ . '/../../header.php';
?>

<div class="container-fluid py-4 px-lg-4">
    <div class="client-dashboard dashboard-shell">
            <?php 
            // Map local variables to what partials expect
            // $dashboardController is passed from the controller
            
            try {
                include __DIR__ . '/../../../modules/client/partials/dashboard_header.php'; 
            } catch (Throwable $e) {
                error_log('Client dashboard header partial failed: ' . $e->getMessage());
                echo '<div class="alert alert-warning">Dashboard header could not be loaded.</div>';
            }
            
            try {
                echo '<section id="dashboard-overview" class="dashboard-section">';
                include __DIR__ . '/../../../modules/client/partials/dashboard_summary.php'; 
                echo '</section>';
            } catch (Throwable $e) {
                error_log('Client dashboard summary partial failed: ' . $e->getMessage());
                echo '<div class="alert alert-warning">Dashboard summary could not be loaded.</div>';
            }
            
            try {
                echo '<section id="dashboard-reports" class="dashboard-section">';
                include __DIR__ . '/../../../modules/client/partials/dashboard_widgets.php'; 
                echo '</section>';
            } catch (Throwable $e) {
                error_log('Client dashboard widgets partial failed: ' . $e->getMessage());
                echo '<div class="alert alert-warning">Dashboard widgets could not be loaded.</div>';
            }
            
            try {
                echo '<section id="dashboard-next-steps" class="dashboard-section">';
                include __DIR__ . '/../../../modules/client/partials/dashboard_actions.php'; 
                echo '</section>';
            } catch (Throwable $e) {
                error_log('Client dashboard actions partial failed: ' . $e->getMessage());
                echo '<div class="alert alert-warning">Dashboard actions could not be loaded.</div>';
            }
            ?>
        </div>
    </div>
<?php 
// Chart.js and Dashboard Scripts 
?>

<?php 
try {
    if (isset($dashboardController) && isset($dashboardController->visualization) && method_exists($dashboardController->visualization, 'getVisualizationJS')) {
        echo $dashboardController->visualization->getVisualizationJS(); 
    }
} catch (Throwable $e) {
    error_log('Client dashboard visualization JS failed: ' . $e->getMessage());
}
?>

<script nonce="<?php echo htmlspecialchars($cspNonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
window.actualClientId = <?php echo json_encode((int) (($assignedProjects[0]['client_id'] ?? 0))); ?>;
window.selectedProjectId = <?php echo json_encode((int) ($dashboardData['selected_project_id'] ?? 0)); ?>;
window.baseUrl = <?php echo json_encode($baseDir, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>

<?php 
require_once __DIR__ . '/../../footer.php';
?>