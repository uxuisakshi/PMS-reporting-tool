<?php
/**
 * AdminAssignmentController
 * Handles project assignment requests with admin validation
 * Implements bulk assignment functionality
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../models/ProjectAssignmentManager.php';
require_once __DIR__ . '/../models/ClientUser.php';
require_once __DIR__ . '/../models/SecurityValidator.php';
require_once __DIR__ . '/../models/AuditLogger.php';
require_once __DIR__ . '/../auth.php';

class AdminAssignmentController {
    private $db;
    private $assignmentManager;
    private $securityValidator;
    private $auditLogger;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->assignmentManager = new ProjectAssignmentManager();
        $this->auditLogger = new AuditLogger();
        $this->securityValidator = new SecurityValidator($this->auditLogger);
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Show assignment management interface
     */
    public function showAssignmentInterface() {
        try {
            // Check admin authentication
            if (!$this->isAdmin()) {
                $this->redirectToLogin();
                return;
            }
            
            // Get all client users
            $clientUsers = $this->getClientUsers();
            
            // Get all projects
            $projects = $this->getProjects();
            
            // Get current assignments
            $assignments = $this->getCurrentAssignments();
            
            // Generate CSRF token
            $csrfToken = $this->securityValidator->generateCSRFToken();
            
            // Render assignment interface
            $this->renderAssignmentInterface($clientUsers, $projects, $assignments, $csrfToken);
            
        } catch (Exception $e) {
            error_log("Assignment interface error: " . $e->getMessage());
            $this->renderError("Unable to load assignment interface");
        }
    }
    
    /**
     * Handle project assignment
     */
    public function assignProjects() {
        try {
            // Check admin authentication
            if (!$this->isAdmin()) {
                http_response_code(401);
                echo json_encode(['error' => 'Admin access required']);
                return;
            }
            
            // Validate CSRF token
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!$this->securityValidator->validateCSRFToken($csrfToken, $_SESSION['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                return;
            }
            
            // Validate input
            $validation = $this->securityValidator->validateInput($_POST, [
                'client_user_id' => ['required' => true, 'type' => 'int'],
                'project_ids' => ['required' => true, 'type' => 'string'],
                'expires_at' => ['type' => 'string'],
                'notify_client' => ['type' => 'string']
            ]);
            
            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid input', 'details' => $validation['errors']]);
                return;
            }
            
            $clientUserId = $validation['data']['client_user_id'];
            $projectIds = array_map('intval', explode(',', $validation['data']['project_ids']));
            $expiresAt = !empty($validation['data']['expires_at']) ? $validation['data']['expires_at'] : null;
            $notifyClient = ($validation['data']['notify_client'] ?? 'no') === 'yes';
            
            // Validate client user exists and has client role
            if (!$this->validateClientUser($clientUserId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid client user']);
                return;
            }
            
            // Validate projects exist
            $validProjects = $this->validateProjects($projectIds);
            if (count($validProjects) !== count($projectIds)) {
                http_response_code(400);
                echo json_encode(['error' => 'One or more invalid projects']);
                return;
            }
            
            // Assign projects
            $result = $this->assignmentManager->assignProjectsToClient(
                $clientUserId,
                $projectIds,
                $_SESSION['user_id'],
                $expiresAt,
                $notifyClient
            );
            
            if ($result['success']) {
                // Log admin action
                $this->auditLogger->logAdminActivity(
                    $_SESSION['user_id'],
                    'project_assignment',
                    "Assigned projects " . implode(',', $projectIds) . " to client user $clientUserId",
                    $clientUserId
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Projects assigned successfully',
                    'assigned_count' => count($projectIds)
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $result['error']
                ]);
            }
            
        } catch (Exception $e) {
            error_log("Project assignment error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * Handle project revocation
     */
    public function revokeProjects() {
        try {
            // Check admin authentication
            if (!$this->isAdmin()) {
                http_response_code(401);
                echo json_encode(['error' => 'Admin access required']);
                return;
            }
            
            // Validate CSRF token
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!$this->securityValidator->validateCSRFToken($csrfToken, $_SESSION['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                return;
            }
            
            // Validate input
            $validation = $this->securityValidator->validateInput($_POST, [
                'client_user_id' => ['required' => true, 'type' => 'int'],
                'project_ids' => ['required' => true, 'type' => 'string'],
                'notify_client' => ['type' => 'string']
            ]);
            
            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid input', 'details' => $validation['errors']]);
                return;
            }
            
            $clientUserId = $validation['data']['client_user_id'];
            $projectIds = array_map('intval', explode(',', $validation['data']['project_ids']));
            $notifyClient = ($validation['data']['notify_client'] ?? 'no') === 'yes';
            
            // Revoke project access
            $revokedCount = 0;
            $errors = [];
            
            foreach ($projectIds as $projectId) {
                $revResult = $this->assignmentManager->revokeProjectAccess(
                    $clientUserId,
                    $projectId,
                    $_SESSION['user_id'],
                    $notifyClient ? 'Revoked by admin' : ''
                );
                
                if ($revResult['success']) {
                    $revokedCount++;
                } else {
                    $errors[] = "Project $projectId: " . $revResult['error'];
                }
            }
            
            if ($revokedCount > 0) {
                // Log admin action
                $this->auditLogger->logAdminActivity(
                    $_SESSION['user_id'],
                    'project_revocation',
                    "Revoked $revokedCount projects from client user $clientUserId",
                    $clientUserId
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => "Project access revoked successfully ($revokedCount projects)",
                    'revoked_count' => $revokedCount,
                    'errors' => $errors
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to revoke any projects',
                    'details' => $errors
                ]);
            }
            
        } catch (Exception $e) {
            error_log("Project revocation error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * Handle bulk assignment operations
     */
    public function bulkAssignment() {
        try {
            // Check admin authentication
            if (!$this->isAdmin()) {
                http_response_code(401);
                echo json_encode(['error' => 'Admin access required']);
                return;
            }
            
            // Validate CSRF token
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!$this->securityValidator->validateCSRFToken($csrfToken, $_SESSION['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                return;
            }
            
            // Validate input
            $validation = $this->securityValidator->validateInput($_POST, [
                'operation' => ['required' => true, 'type' => 'string'],
                'assignments' => ['required' => true, 'type' => 'string']
            ]);
            
            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid input', 'details' => $validation['errors']]);
                return;
            }
            
            $operation = $validation['data']['operation'];
            $assignments = json_decode($validation['data']['assignments'], true);
            
            if (!in_array($operation, ['assign', 'revoke']) || !is_array($assignments)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid operation or assignments data']);
                return;
            }
            
            $results = [];
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($assignments as $assignment) {
                $clientUserId = $assignment['client_user_id'] ?? null;
                $projectIds = $assignment['project_ids'] ?? [];
                
                if (!$clientUserId || empty($projectIds)) {
                    $results[] = ['client_user_id' => $clientUserId, 'success' => false, 'error' => 'Invalid assignment data'];
                    $errorCount++;
                    continue;
                }
                
                try {
                    if ($operation === 'assign') {
                        $result = $this->assignmentManager->assignProjectsToClient(
                            $clientUserId,
                            $projectIds,
                            $_SESSION['user_id'],
                            $assignment['expires_at'] ?? null,
                            false // Don't notify for bulk operations
                        );
                    } else {
                        $result = $this->assignmentManager->revokeProjectAccess(
                            $clientUserId,
                            $projectIds,
                            $_SESSION['user_id'],
                            false // Don't notify for bulk operations
                        );
                    }
                    
                    $results[] = [
                        'client_user_id' => $clientUserId,
                        'success' => $result['success'],
                        'error' => $result['success'] ? null : $result['error']
                    ];
                    
                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                    
                } catch (Exception $e) {
                    $results[] = ['client_user_id' => $clientUserId, 'success' => false, 'error' => $e->getMessage()];
                    $errorCount++;
                }
            }
            
            // Log bulk operation
            $this->auditLogger->logAdminActivity(
                $_SESSION['user_id'],
                "bulk_$operation",
                "Bulk $operation operation: $successCount successful, $errorCount failed"
            );
            
            echo json_encode([
                'success' => $errorCount === 0,
                'message' => "Bulk operation completed: $successCount successful, $errorCount failed",
                'results' => $results,
                'summary' => [
                    'total' => count($assignments),
                    'successful' => $successCount,
                    'failed' => $errorCount
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Bulk assignment error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * Get assignment history for audit trail
     */
    public function getAssignmentHistory() {
        try {
            // Check admin authentication
            if (!$this->isAdmin()) {
                http_response_code(401);
                echo json_encode(['error' => 'Admin access required']);
                return;
            }
            
            // Get pagination parameters
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(100, max(10, intval($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            
            // Get filter parameters
            $clientUserId = !empty($_GET['client_user_id']) ? intval($_GET['client_user_id']) : null;
            $actionType = !empty($_GET['action_type']) ? $_GET['action_type'] : null;
            
            // Build query
            $whereConditions = ["action_type IN ('project_assignment', 'project_revocation', 'bulk_assign', 'bulk_revoke')"];
            $params = [];
            
            if ($clientUserId) {
                $whereConditions[] = "target_user_id = ?";
                $params[] = $clientUserId;
            }
            
            if ($actionType) {
                $whereConditions[] = "action_type = ?";
                $params[] = $actionType;
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            // Get history records
            $stmt = $this->db->prepare("
                SELECT al.*, u.username as admin_username, cu.username as client_username
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                LEFT JOIN users cu ON al.target_user_id = cu.id
                WHERE $whereClause
                ORDER BY al.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM audit_logs WHERE $whereClause
            ");
            $countStmt->execute(array_slice($params, 0, -2));
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            header('Content-Type: application/json');
            echo json_encode([
                'history' => $history,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Assignment history error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * Check if current user is admin
     */
    private function isAdmin() {
        return isset($_SESSION['user_id']) && 
               isset($_SESSION['role']) && 
               in_array($_SESSION['role'], ['admin']);
    }
    
    /**
     * Get all client users
     */
    private function getClientUsers() {
        $stmt = $this->db->prepare("
            SELECT id, username, email, first_name, last_name, created_at, last_login
            FROM users 
            WHERE role = 'client' AND active = 1
            ORDER BY username
        ");
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all projects
     */
    private function getProjects() {
        $stmt = $this->db->prepare("
            SELECT id, name, description, status, created_at
            FROM projects 
            WHERE active = 1
            ORDER BY name
        ");
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get current assignments
     */
    private function getCurrentAssignments() {
        $stmt = $this->db->prepare("
            SELECT cpa.*, u.username, p.name as project_name
            FROM client_project_assignments cpa
            JOIN users u ON cpa.client_user_id = u.id
            JOIN projects p ON cpa.project_id = p.id
            WHERE cpa.active = 1
            ORDER BY u.username, p.name
        ");
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Validate client user
     */
    private function validateClientUser($clientUserId) {
        $stmt = $this->db->prepare("
            SELECT id FROM users 
            WHERE id = ? AND role = 'client' AND active = 1
        ");
        
        $stmt->execute([$clientUserId]);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Validate projects
     */
    private function validateProjects($projectIds) {
        if (empty($projectIds)) {
            return [];
        }
        
        $placeholders = str_repeat('?,', count($projectIds) - 1) . '?';
        $stmt = $this->db->prepare("
            SELECT id FROM projects 
            WHERE id IN ($placeholders) AND active = 1
        ");
        
        $stmt->execute($projectIds);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Render assignment interface
     */
    private function renderAssignmentInterface($clientUsers, $projects, $assignments, $csrfToken) {
        $pageTitle = "Client Project Assignment Management";
        
        include __DIR__ . '/../header.php';
        include __DIR__ . '/../templates/admin/assignment_interface.php';
        include __DIR__ . '/../footer.php';
    }
    
    /**
     * Render error page
     */
    private function renderError($message) {
        $pageTitle = "Assignment Error";
        $errorMessage = $this->securityValidator->sanitizeString($message);
        
        include __DIR__ . '/../header.php';
        include __DIR__ . '/../templates/admin/error.php';
        include __DIR__ . '/../footer.php';
    }
    
    /**
     * Redirect to login page
     */
    private function redirectToLogin() {
        header('Location: /login.php');
        exit;
    }
}
