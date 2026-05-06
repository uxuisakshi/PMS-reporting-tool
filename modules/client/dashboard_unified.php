<?php
/**
 * Unified Dashboard View Template
 * 
 * Responsive dashboard layout with widget grid for client analytics
 * Implements drill-down navigation to detailed reports
 * 
 * Requirements: 12.3, 12.4, 13.5
 */

// Analytics dashboard for client users

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/controllers/UnifiedDashboardController.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Initialize dashboard controller
$dashboardController = new UnifiedDashboardController();

// Get client user ID (either current user or admin viewing specific client)
$clientUserId = $userId;
if (in_array($userRole, ['admin']) && isset($_GET['client_id'])) {
    $clientUserId = intval($_GET['client_id']);
}

// Generate dashboard data
try {
    $dashboardData = $dashboardController->generateUnifiedDashboard($clientUserId);
} catch (Exception $e) {
    error_log("Dashboard generation error: " . $e->getMessage());
    $dashboardData = [
        'onboarding' => false,
        'project_statistics' => [
            'total_projects' => 0,
            'client_ready_issues' => 0,
            'total_issues' => 0
        ],
        'assigned_projects' => []
    ];
}

// Determine actual client_id for export
$actualClientId = 0;
if (in_array($userRole, ['admin']) && isset($_GET['client_id'])) {
    $actualClientId = intval($_GET['client_id']);
} elseif (!empty($dashboardData['assigned_projects'])) {
    // For client users, take the client_id from their first assigned project
    $actualClientId = $dashboardData['assigned_projects'][0]['client_id'];
}

// Set page title for header.php
$pageTitle = 'Analytics Dashboard';

// Ensure baseDir is set correctly
if (!isset($baseDir)) {
    require_once __DIR__ . '/../../includes/helpers.php';
    $baseDir = getBaseDir();
}

// Handle flash messages
// These will be picked up by header.php if included after, 
// but dashboard_unified.php has its own toast logic below.
?>
<?php include __DIR__ . '/../../includes/header.php'; ?>

<!-- Dashboard Styles -->
<?php 
try {
    if (isset($dashboardController->visualization) && method_exists($dashboardController->visualization, 'getVisualizationCSS')) {
        echo $dashboardController->visualization->getVisualizationCSS(); 
    }
} catch (Exception $e) {
    error_log("Visualization CSS error: " . $e->getMessage());
}
?>

<div class="container-fluid" id="main-content" tabindex="-1">
    <?php if (($dashboardData['onboarding'] ?? false) || empty($dashboardData['assigned_projects'])): ?>
        <!-- Onboarding Dashboard -->
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info">
                    <h4>Welcome to the Analytics Dashboard</h4>
                    <p>No digital assets are currently assigned to you. Please contact your administrator to get access.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Analytics Dashboard -->
        <?php 
        try {
            include __DIR__ . '/partials/dashboard_header.php'; 
        } catch (Exception $e) {
            echo '<div class="alert alert-warning">Dashboard header could not be loaded.</div>';
        }
        ?>
        
        <?php 
        try {
            include __DIR__ . '/partials/dashboard_summary.php'; 
        } catch (Exception $e) {
            echo '<div class="alert alert-warning">Dashboard summary could not be loaded.</div>';
        }
        ?>
        
        <?php 
        try {
            include __DIR__ . '/partials/dashboard_widgets.php'; 
        } catch (Exception $e) {
            echo '<div class="alert alert-warning">Dashboard widgets could not be loaded.</div>';
        }
        ?>
        
        <?php 
        try {
            include __DIR__ . '/partials/dashboard_actions.php'; 
        } catch (Exception $e) {
            echo '<div class="alert alert-warning">Dashboard actions could not be loaded.</div>';
        }
        ?>
    <?php endif; ?>
</div>

<?php 
try {
    if (isset($dashboardController->visualization) && method_exists($dashboardController->visualization, 'getVisualizationJS')) {
        echo $dashboardController->visualization->getVisualizationJS(); 
    }
} catch (Exception $e) {
    error_log("Visualization JS error: " . $e->getMessage());
}
?>

<script nonce="<?php echo htmlspecialchars($cspNonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
// Global config for dashboard.js
window.actualClientId = <?php echo json_encode((int) $actualClientId); ?>;
window.selectedProjectId = <?php echo json_encode((int) ($selectedProjectId ?? 0)); ?>;
window.baseUrl = <?php echo json_encode($baseDir, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
if (typeof initializeDashboard === "function") initializeDashboard();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; 