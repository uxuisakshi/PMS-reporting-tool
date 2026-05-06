<?php
/**
 * ClientExportController
 * Handles export requests with security validation
 * Implements download functionality with secure file access
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../models/ClientUser.php';
require_once __DIR__ . '/../models/ClientAccessControlManager.php';
require_once __DIR__ . '/../models/ExportEngine.php';
require_once __DIR__ . '/../models/ExportRequest.php';
require_once __DIR__ . '/../models/SecurityValidator.php';
require_once __DIR__ . '/../models/AuditLogger.php';

class ClientExportController {
    private $db;
    private $accessControl;
    private $exportEngine;
    private $securityValidator;
    private $auditLogger;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->accessControl = new ClientAccessControlManager();
        $this->exportEngine = new ExportEngine();
        $this->auditLogger = new AuditLogger();
        $this->securityValidator = new SecurityValidator($this->auditLogger);
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Handle export request
     */
    public function requestExport() {
        try {
            // Check rate limiting
            $clientUser = $this->authenticateClient();
            if (!$clientUser) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }
            
            if (!$this->checkRateLimit($clientUser['id'])) {
                http_response_code(429);
                echo json_encode(['error' => 'Too many export requests. Please wait before trying again.']);
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
                'export_type' => ['required' => true, 'type' => 'string'],
                'report_type' => ['required' => true, 'type' => 'string'],
                'project_ids' => ['required' => true, 'type' => 'string'],
                'date_range' => ['type' => 'string']
            ]);
            
            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request parameters', 'details' => $validation['errors']]);
                return;
            }
            
            $exportType = $validation['data']['export_type'];
            $reportType = $validation['data']['report_type'];
            $projectIds = array_map('intval', explode(',', $validation['data']['project_ids']));
            $dateRange = $validation['data']['date_range'] ?? null;
            
            // Validate export and report types
            if (!in_array($exportType, ['pdf', 'excel'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid export type']);
                return;
            }
            
            $validReportTypes = [
                'user_affected', 'wcag_compliance', 'severity_analytics',
                'common_issues', 'blocker_issues', 'page_issues',
                'commented_issues', 'compliance_trend', 'unified_dashboard'
            ];
            
            if (!in_array($reportType, $validReportTypes)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid report type']);
                return;
            }
            
            // Validate project access
            foreach ($projectIds as $projectId) {
                if (!$this->accessControl->hasProjectAccess($clientUser['id'], $projectId)) {
                    $this->auditLogger->logSecurityViolation(
                        $clientUser['id'],
                        'unauthorized_export_access',
                        "Attempted to export data for project $projectId",
                        'high'
                    );
                    
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied to one or more projects']);
                    return;
                }
            }
            
            // Create export request
            $exportRequest = new ExportRequest();
            $requestId = $exportRequest->createRequest(
                $clientUser['id'],
                $exportType,
                $reportType,
                $projectIds,
                $dateRange
            );
            
            // Log export request
            $this->auditLogger->logClientActivity(
                $clientUser['id'],
                AuditLogger::ACTION_EXPORT_REQUEST,
                "Requested $exportType export for $reportType report",
                true,
                null,
                'export_request',
                $requestId
            );
            
            // Process export (synchronous for small exports, async for large ones)
            $estimatedSize = $this->estimateExportSize($reportType, $projectIds);
            
            if ($estimatedSize < 1000) { // Small export - process immediately
                $result = $this->processExportSync($requestId, $exportType, $reportType, $projectIds, $dateRange);
                
                if ($result['success']) {
                    echo json_encode([
                        'success' => true,
                        'request_id' => $requestId,
                        'download_url' => "/client/download?id=$requestId",
                        'status' => 'completed'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => $result['error']
                    ]);
                }
            } else { // Large export - process in background
                $exportRequest->updateStatus($requestId, 'queued');
                
                echo json_encode([
                    'success' => true,
                    'request_id' => $requestId,
                    'status' => 'queued',
                    'message' => 'Export queued for processing. You will be notified when ready.'
                ]);
                
                // Queue for background processing
                $this->queueBackgroundExport($requestId);
            }
            
        } catch (Exception $e) {
            error_log("Export request error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * Handle file download
     */
    public function downloadFile($requestId) {
        try {
            // Authenticate client
            $clientUser = $this->authenticateClient();
            if (!$clientUser) {
                $this->redirectToLogin();
                return;
            }
            
            // Validate request ID
            $validation = $this->securityValidator->validateInput(
                ['request_id' => $requestId],
                ['request_id' => ['required' => true, 'type' => 'int']]
            );
            
            if (!$validation['valid']) {
                $this->renderError("Invalid download request");
                return;
            }
            
            $requestId = $validation['data']['request_id'];
            
            // Get export request
            $exportRequest = new ExportRequest();
            $request = $exportRequest->getRequest($requestId);
            
            if (!$request) {
                $this->renderError("Export request not found");
                return;
            }
            
            // Verify ownership
            if ($request['client_user_id'] != $clientUser['id']) {
                $this->auditLogger->logSecurityViolation(
                    $clientUser['id'],
                    'unauthorized_download_access',
                    "Attempted to download export request $requestId",
                    'high'
                );
                
                $this->renderError("Access denied");
                return;
            }
            
            // Check if file is ready
            if ($request['status'] !== 'completed') {
                $this->renderError("Export is not ready for download. Status: " . $request['status']);
                return;
            }
            
            // Verify file exists and is secure
            $filePath = $request['file_path'];
            if (!$this->isSecureFilePath($filePath) || !file_exists($filePath)) {
                $this->renderError("File not found or access denied");
                return;
            }
            
            // Log download
            $this->auditLogger->logClientActivity(
                $clientUser['id'],
                AuditLogger::ACTION_EXPORT_DOWNLOAD,
                "Downloaded export file: " . basename($filePath),
                true,
                null,
                'export_request',
                $requestId
            );
            
            // Serve file
            $this->serveFile($filePath, $request['export_type'], $request['report_type']);
            
        } catch (Exception $e) {
            error_log("Download error: " . $e->getMessage());
            $this->renderError("Unable to download file. Please try again later.");
        }
    }
    
    /**
     * Get export status
     */
    public function getExportStatus($requestId) {
        try {
            // Authenticate client
            $clientUser = $this->authenticateClient();
            if (!$clientUser) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }
            
            // Validate request ID
            $validation = $this->securityValidator->validateInput(
                ['request_id' => $requestId],
                ['request_id' => ['required' => true, 'type' => 'int']]
            );
            
            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request ID']);
                return;
            }
            
            $requestId = $validation['data']['request_id'];
            
            // Get export request
            $exportRequest = new ExportRequest();
            $request = $exportRequest->getRequest($requestId);
            
            if (!$request || $request['client_user_id'] != $clientUser['id']) {
                http_response_code(404);
                echo json_encode(['error' => 'Export request not found']);
                return;
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'request_id' => $requestId,
                'status' => $request['status'],
                'progress' => $request['progress'] ?? 0,
                'created_at' => $request['created_at'],
                'completed_at' => $request['completed_at'],
                'download_url' => $request['status'] === 'completed' ? "/client/download?id=$requestId" : null,
                'error_message' => $request['error_message']
            ]);
            
        } catch (Exception $e) {
            error_log("Export status error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * List user's export requests
     */
    public function listExports() {
        try {
            // Authenticate client
            $clientUser = $this->authenticateClient();
            if (!$clientUser) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }
            
            // Get pagination parameters
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(50, max(10, intval($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            
            // Get export requests
            $exportRequest = new ExportRequest();
            $requests = $exportRequest->getUserRequests($clientUser['id'], $limit, $offset);
            $total = $exportRequest->getUserRequestCount($clientUser['id']);
            
            header('Content-Type: application/json');
            echo json_encode([
                'requests' => $requests,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("List exports error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * Process export synchronously
     */
    private function processExportSync($requestId, $exportType, $reportType, $projectIds, $dateRange) {
        try {
            $exportRequest = new ExportRequest();
            $exportRequest->updateStatus($requestId, 'processing');
            
            // Generate export
            $result = $this->exportEngine->generateExport(
                $exportType,
                $reportType,
                $projectIds,
                $dateRange
            );
            
            if ($result['success']) {
                $exportRequest->completeRequest($requestId, $result['file_path']);
                return ['success' => true];
            } else {
                $exportRequest->failRequest($requestId, $result['error']);
                return ['success' => false, 'error' => $result['error']];
            }
            
        } catch (Exception $e) {
            $exportRequest->failRequest($requestId, $e->getMessage());
            return ['success' => false, 'error' => 'Export processing failed'];
        }
    }
    
    /**
     * Estimate export size for processing decision
     */
    private function estimateExportSize($reportType, $projectIds) {
        // Simple estimation based on project count and report type
        $baseSize = count($projectIds) * 100;
        
        $multipliers = [
            'unified_dashboard' => 3,
            'compliance_trend' => 2,
            'common_issues' => 2,
            'page_issues' => 2,
            'user_affected' => 1,
            'wcag_compliance' => 1,
            'severity_analytics' => 1,
            'blocker_issues' => 1,
            'commented_issues' => 1
        ];
        
        return $baseSize * ($multipliers[$reportType] ?? 1);
    }
    
    /**
     * Queue export for background processing
     */
    private function queueBackgroundExport($requestId) {
        // In a real implementation, this would add to a job queue
        // For now, we'll just log it
        error_log("Export request $requestId queued for background processing");
    }
    
    /**
     * Verify file path is secure
     */
    private function isSecureFilePath($filePath) {
        $allowedDir = realpath(__DIR__ . '/../../exports/');
        $realPath = realpath($filePath);
        
        return $realPath && strpos($realPath, $allowedDir) === 0;
    }
    
    /**
     * Serve file for download
     */
    private function serveFile($filePath, $exportType, $reportType) {
        $filename = str_replace(["\r", "\n", '"'], '', basename($filePath));
        $mimeType = $exportType === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        
        readfile($filePath);
        exit;
    }
    
    /**
     * Authenticate client user
     */
    private function authenticateClient() {
        if (!isset($_SESSION['client_user_id']) || !isset($_SESSION['client_role'])) {
            return null;
        }
        
        $stmt = $this->db->prepare("
            SELECT id, username, email, role 
            FROM users 
            WHERE id = ? AND role = 'client' AND is_active = 1
        ");
        
        $stmt->execute([$_SESSION['client_user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check rate limiting for export requests
     */
    private function checkRateLimit($clientId) {
        return $this->securityValidator->checkRateLimit(
            "client_export_$clientId",
            5,  // 5 exports
            3600 // per hour
        );
    }
    
    /**
     * Render error page
     */
    private function renderError($message) {
        $pageTitle = "Export Error";
        $errorMessage = $this->securityValidator->sanitizeString($message);
        
        include __DIR__ . '/../header.php';
        include __DIR__ . '/../templates/client/error.php';
        include __DIR__ . '/../footer.php';
    }
    
    /**
     * Redirect to login page
     */
    private function redirectToLogin() {
        header('Location: ' . getBaseDir() . '/modules/auth/login.php');
        exit;
    }
}
