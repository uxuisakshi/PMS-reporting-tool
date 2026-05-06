<?php
/**
 * ClientDashboardController
 * Handles client dashboard requests with authentication
 * Integrates all analytics engines and visualization layer
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../models/ClientUser.php';
require_once __DIR__ . '/../models/ClientAccessControlManager.php';
require_once __DIR__ . '/UnifiedDashboardController.php';
require_once __DIR__ . '/../models/SecurityValidator.php';
require_once __DIR__ . '/../models/AuditLogger.php';

class ClientDashboardController {
    private $db;
    private $accessControl;
    private $dashboardController;
    private $securityValidator;
    private $auditLogger;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->accessControl = new ClientAccessControlManager();
        $this->dashboardController = new UnifiedDashboardController();
        $this->auditLogger = new AuditLogger();
        $this->securityValidator = new SecurityValidator($this->auditLogger);
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Main dashboard view
     */
    public function dashboard() {
        try {
            // Authenticate client
            $clientUser = $this->authenticateClient();
            if (!$clientUser) {
                $this->redirectToLogin();
                return;
            }
            
            // Log dashboard access
            $this->auditLogger->logClientActivity(
                $clientUser['id'],
                AuditLogger::ACTION_DATA_ACCESS,
                'Accessed unified dashboard'
            );
            
            // Get assigned projects
            $assignedProjects = $this->accessControl->getAssignedProjects($clientUser['id']);
            
            if (empty($assignedProjects)) {
                $this->renderNoProjectsView($clientUser);
                return;
            }
            
            // Generate dashboard data
            $dashboardData = $this->dashboardController->generateUnifiedDashboard($clientUser['id']);
            
            // Render dashboard
            $this->renderDashboard($clientUser, $assignedProjects, $dashboardData);
            
        } catch (Throwable $e) {
            error_log("Dashboard error: " . $e->getMessage());
            $this->renderError("Unable to load dashboard. Please try again later.");
        }
    }
    
    /**
     * Individual project view
     */
    public function projectView($projectIdentifier) {
        try {
            // Authenticate client
            $clientUser = $this->authenticateClient();
            if (!$clientUser) {
                $this->redirectToLogin();
                return;
            }

            $projectIdentifier = trim((string) $projectIdentifier);
            $projectId = $this->accessControl->resolveProjectIdentifier($clientUser['id'], $projectIdentifier);

            if (!$projectId) {
                $this->renderError("Invalid project reference");
                return;
            }

            $canonicalIdentifier = $this->accessControl->getCanonicalProjectIdentifier($clientUser['id'], $projectId);
            if ($canonicalIdentifier && $projectIdentifier !== $canonicalIdentifier) {
                $target = getBaseDir() . '/client/project/' . rawurlencode($canonicalIdentifier);
                $queryString = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
                if ($queryString !== '') {
                    $target .= '?' . $queryString;
                }

                header('Location: ' . $target, true, 302);
                return;
            }
            
            // Check project access
            if (!$this->accessControl->hasProjectAccess($clientUser['id'], $projectId)) {
                $this->auditLogger->logSecurityViolation(
                    $clientUser['id'],
                    'unauthorized_project_access',
                    "Attempted to access project {$projectIdentifier}",
                    'high'
                );
                $this->renderError("Access denied to this project");
                return;
            }
            
            // Log project access
            $this->auditLogger->logClientActivity(
                $clientUser['id'],
                AuditLogger::ACTION_PROJECT_ACCESS,
                "Accessed project $projectId analytics",
                true,
                null,
                'project',
                $projectId
            );
            
            // Get project analytics
            $projectAnalytics = $this->dashboardController->generateProjectAnalytics($projectId, $clientUser['id']);
            
            // Render project view
            $this->renderProjectView($clientUser, $projectId, $projectAnalytics);
            
        } catch (Throwable $e) {
            error_log("Project view error: " . $e->getMessage());
            file_put_contents(__DIR__ . '/../../debug_error.txt', date('Y-m-d H:i:s') . "\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
            $this->renderError("Unable to load project analytics. Please try again later.");
        }
    }
    
    /**
     * AJAX endpoint for dashboard widgets
     */
    public function ajaxWidget() {
        try {
            // Validate CSRF token
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!$this->securityValidator->validateCSRFToken($csrfToken, $_SESSION['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                return;
            }
            
            // Validate input
            $validation = $this->securityValidator->validateInput($_POST, [
                'widget_type' => ['required' => true, 'type' => 'string'],
                'project_ids' => ['type' => 'string']
            ]);
            
            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request parameters']);
                return;
            }
            
            $widgetType = $validation['data']['widget_type'];
            $projectIds = !empty($validation['data']['project_ids']) ? 
                explode(',', $validation['data']['project_ids']) : [];
            
            // Authenticate client
            $clientUser = $this->authenticateClient();
            if (!$clientUser) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }
            
            // Validate project access
            foreach ($projectIds as $projectId) {
                if (!$this->accessControl->hasProjectAccess($clientUser['id'], $projectId)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied']);
                    return;
                }
            }
            
            // Get widget data
            $widgetData = $this->getWidgetData($widgetType, $projectIds);
            
            header('Content-Type: application/json');
            echo json_encode($widgetData);
            
        } catch (Throwable $e) {
            error_log("AJAX widget error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * Authenticate client user
     */
    private function authenticateClient() {
        if (!isset($_SESSION['client_user_id']) || !isset($_SESSION['client_role'])) {
            return null;
        }
        
        // Verify session is still valid
        $stmt = $this->db->prepare("
            SELECT id, username, full_name, email, role 
            FROM users 
            WHERE id = ? AND role = 'client' AND is_active = 1
        ");
        
        $stmt->execute([$_SESSION['client_user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // Clear invalid session
            session_destroy();
            return null;
        }
        
        // Check session timeout (4 hours)
        if (isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity']) > 14400) {
            
            $this->auditLogger->logClientActivity(
                $user['id'],
                AuditLogger::ACTION_LOGOUT,
                'Session timeout'
            );
            
            session_destroy();
            return null;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        return $user;
    }
    
    /**
     * Get widget data for AJAX requests
     */
    private function getWidgetData($widgetType, $projectIds) {
        switch ($widgetType) {
            case 'user_affected_summary':
                return $this->dashboardController->getUserAffectedSummary($projectIds);
                
            case 'wcag_compliance_summary':
                return $this->dashboardController->getWCAGComplianceSummary($projectIds);
                
            case 'severity_distribution':
                return $this->dashboardController->getSeverityDistribution($projectIds);
                
            case 'common_issues_top':
                return $this->dashboardController->getTopCommonIssues($projectIds, 5);
                
            case 'blocker_issues_summary':
                return $this->dashboardController->getBlockerIssuesSummary($projectIds);
                
            case 'page_issues_top':
                return $this->dashboardController->getTopPageIssues($projectIds, 5);
                
            case 'recent_activity':
                return $this->dashboardController->getRecentActivity($projectIds, 10);
                
            case 'compliance_trend':
                return $this->dashboardController->getComplianceTrend($projectIds, 30);
                
            default:
                throw new Exception("Unknown widget type: $widgetType");
        }
    }
    
    /**
     * Render main dashboard
     */
    private function renderDashboard($clientUser, $assignedProjects, $dashboardData) {
        $pageTitle = "Analytics Dashboard";
        $csrfToken = $this->securityValidator->generateCSRFToken();
        $baseDir = getBaseDir();
        $dashboardController = $this->dashboardController;
        
        include __DIR__ . '/../templates/client/dashboard.php';
    }
    
    /**
     * Render individual project view
     */
    private function renderProjectView($clientUser, $projectId, $projectAnalytics) {
        $pageTitle = "Project Analytics - " . $projectAnalytics['project_name'];
        $csrfToken = $this->securityValidator->generateCSRFToken();
        $baseDir = getBaseDir();
        $dashboardController = $this->dashboardController;
        
        include __DIR__ . '/../templates/client/project_view.php';
    }
    
    /**
     * Render no projects view
     */
    private function renderNoProjectsView($clientUser) {
        $pageTitle = "No Projects Assigned";
        $baseDir = getBaseDir();
        
        include __DIR__ . '/../templates/client/no_projects.php';
    }
    
    /**
     * Render error page
     */
    private function renderError($message) {
        $pageTitle = "Error";
        $errorMessage = $this->securityValidator->sanitizeString($message);
        $baseDir = getBaseDir();
        
        include __DIR__ . '/../templates/client/error.php';
    }
    
    /**
     * Redirect to login page
     */
    private function redirectToLogin() {
        redirect("/modules/auth/login.php");
        exit;
    }
    
    /**
     * Check rate limiting for client requests
     */
    private function checkRateLimit($clientId) {
        return $this->securityValidator->checkRateLimit(
            "client_dashboard_$clientId",
            30, // 30 requests
            300 // per 5 minutes
        );
    }
}
