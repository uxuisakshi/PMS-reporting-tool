<?php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../config/database.php';

/**
 * ExportEngine - Base class for handling export requests and file generation
 * 
 * Provides secure export functionality for analytics reports:
 * - Export request handling and validation
 * - Secure file storage and cleanup
 * - Support for PDF and Excel formats
 * - Audit logging for security compliance
 * 
 * Requirements: 14.1, 15.1, 17.3
 */
class ExportEngine {
    
    protected $db;
    protected $exportDir;
    protected $maxFileAge;
    protected $allowedFormats;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->exportDir = __DIR__ . '/../../tmp/exports/';
        $this->maxFileAge = 3600; // 1 hour in seconds
        $this->allowedFormats = ['pdf', 'excel'];
        
        // Ensure export directory exists and is secure
        $this->initializeExportDirectory();
    }
    
    /**
     * Create export request and generate file
     * 
     * @param int $userId User requesting the export
     * @param string $exportType 'pdf' or 'excel'
     * @param string $reportType Type of analytics report
     * @param array $projectIds Array of project IDs to include
     * @param array $options Export configuration options
     * @return array Export result with file path and metadata
     * @throws Exception If export fails or user lacks permissions
     */
    public function createExportRequest($userId, $exportType, $reportType, $projectIds, $options = []) {
        // Validate inputs
        $this->validateExportRequest($userId, $exportType, $reportType, $projectIds);
        
        // Create export request record
        $requestId = $this->createExportRequestRecord($userId, $exportType, $reportType, $projectIds, $options);
        
        try {
            // Update status to processing
            $this->updateExportStatus($requestId, 'processing');
            
            // Generate the export file
            $filePath = $this->generateExportFile($requestId, $exportType, $reportType, $projectIds, $options);
            
            // Update status to completed
            $this->updateExportStatus($requestId, 'completed', $filePath);
            
            // Log export activity for audit
            $this->logExportActivity($userId, $exportType, $reportType, $projectIds, 'success');
            
            return [
                'success' => true,
                'request_id' => $requestId,
                'file_path' => $filePath,
                'download_url' => $this->generateSecureDownloadUrl($requestId),
                'expires_at' => date('Y-m-d H:i:s', time() + $this->maxFileAge)
            ];
            
        } catch (Exception $e) {
            // Update status to failed
            $this->updateExportStatus($requestId, 'failed', null, $e->getMessage());
            
            // Log export failure
            $this->logExportActivity($userId, $exportType, $reportType, $projectIds, 'failed', $e->getMessage());
            
            throw $e;
        }
    }
    
    /**
     * Get export request status
     * 
     * @param int $requestId Export request ID
     * @param int $userId User ID for security validation
     * @return array Export request details
     */
    public function getExportStatus($requestId, $userId) {
        $stmt = $this->db->prepare("
            SELECT id, user_id, export_type, report_type, project_ids, status, 
                   file_path, error_message, requested_at, completed_at
            FROM export_requests 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$requestId, $userId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Export request not found or access denied');
        }
        
        $request['project_ids'] = json_decode($request['project_ids'], true);
        
        return $request;
    }
    
    /**
     * Download export file securely
     * 
     * @param int $requestId Export request ID
     * @param int $userId User ID for security validation
     * @return void Outputs file for download
     */
    public function downloadExportFile($requestId, $userId) {
        $request = $this->getExportStatus($requestId, $userId);
        
        if ($request['status'] !== 'completed') {
            throw new Exception('Export not completed or failed');
        }
        
        if (!$request['file_path'] || !file_exists($request['file_path'])) {
            throw new Exception('Export file not found');
        }
        
        // Check if file has expired
        if ($request['completed_at'] && 
            strtotime($request['completed_at']) + $this->maxFileAge < time()) {
            $this->cleanupExpiredFile($request['file_path']);
            throw new Exception('Export file has expired');
        }
        
        // Set appropriate headers and output file
        $this->outputFile($request['file_path'], $request['export_type']);
        
        // Log download activity
        $this->logExportActivity($userId, $request['export_type'], $request['report_type'], 
                                json_decode($request['project_ids'], true), 'downloaded');
    }
    
    /**
     * Clean up expired export files
     * 
     * @return int Number of files cleaned up
     */
    public function cleanupExpiredFiles() {
        $cleanedCount = 0;
        
        // Get expired export requests
        $stmt = $this->db->prepare("
            SELECT id, file_path 
            FROM export_requests 
            WHERE status = 'completed' 
            AND completed_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$this->maxFileAge]);
        $expiredRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($expiredRequests as $request) {
            if ($request['file_path'] && file_exists($request['file_path'])) {
                if ($this->cleanupExpiredFile($request['file_path'])) {
                    $cleanedCount++;
                }
            }
            
            // Update database record
            $updateStmt = $this->db->prepare("
                UPDATE export_requests 
                SET file_path = NULL, status = 'expired' 
                WHERE id = ?
            ");
            $updateStmt->execute([$request['id']]);
        }
        
        return $cleanedCount;
    }
    
    /**
     * Generate report header with project information
     * 
     * @param array $projectIds Array of project IDs
     * @return array Header information for reports
     */
    public function generateReportHeader($projectIds) {
        if (empty($projectIds)) {
            return [
                'title' => 'Analytics Report',
                'projects' => [],
                'generated_at' => date('Y-m-d H:i:s'),
                'total_projects' => 0
            ];
        }
        
        $placeholders = str_repeat('?,', count($projectIds) - 1) . '?';
        $stmt = $this->db->prepare("
            SELECT id, title, description, client_id
            FROM projects 
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($projectIds);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'title' => count($projects) > 1 ? 'Multi-Project Analytics Report' : 'Project Analytics Report',
            'projects' => $projects,
            'generated_at' => date('Y-m-d H:i:s'),
            'total_projects' => count($projects)
        ];
    }
    
    /**
     * Format data for export based on format type
     * 
     * @param array $data Raw analytics data
     * @param string $format Export format ('pdf' or 'excel')
     * @return array Formatted data suitable for export
     */
    public function formatDataForExport($data, $format) {
        switch ($format) {
            case 'pdf':
                return $this->formatDataForPDF($data);
            case 'excel':
                return $this->formatDataForExcel($data);
            default:
                throw new Exception("Unsupported export format: $format");
        }
    }
    
    /**
     * Validate export request parameters
     */
    private function validateExportRequest($userId, $exportType, $reportType, $projectIds) {
        if (!is_numeric($userId) || $userId <= 0) {
            throw new Exception('Invalid user ID');
        }
        
        if (!in_array($exportType, $this->allowedFormats)) {
            throw new Exception('Invalid export format. Allowed: ' . implode(', ', $this->allowedFormats));
        }
        
        if (empty($reportType)) {
            throw new Exception('Report type is required');
        }
        
        if (!is_array($projectIds) || empty($projectIds)) {
            throw new Exception('At least one project ID is required');
        }
        
        // Validate user has access to all specified projects
        $this->validateProjectAccess($userId, $projectIds);
    }
    
    /**
     * Validate user access to projects
     */
    private function validateProjectAccess($userId, $projectIds) {
        // For client users, check project assignments
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('User not found');
        }
        
        if ($user['role'] === 'client') {
            $placeholders = str_repeat('?,', count($projectIds) - 1) . '?';
            $params = array_merge([$userId], $projectIds);
            
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT project_id) as accessible_count
                FROM client_project_assignments 
                WHERE client_user_id = ? AND project_id IN ($placeholders) AND is_active = 1
            ");
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['accessible_count'] != count($projectIds)) {
                throw new Exception('Access denied to one or more projects');
            }
        }
        // Admin users have access to all projects - no additional validation needed
    }
    
    /**
     * Create export request database record
     */
    private function createExportRequestRecord($userId, $exportType, $reportType, $projectIds, $options) {
        $stmt = $this->db->prepare("
            INSERT INTO export_requests 
            (user_id, export_type, report_type, project_ids, export_options, status, requested_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $stmt->execute([
            $userId,
            $exportType,
            $reportType,
            json_encode($projectIds),
            json_encode($options)
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Update export request status
     */
    private function updateExportStatus($requestId, $status, $filePath = null, $errorMessage = null) {
        $completedAt = ($status === 'completed') ? 'NOW()' : 'NULL';
        
        $stmt = $this->db->prepare("
            UPDATE export_requests 
            SET status = ?, file_path = ?, error_message = ?, completed_at = $completedAt
            WHERE id = ?
        ");
        
        $stmt->execute([$status, $filePath, $errorMessage, $requestId]);
    }
    
    /**
     * Generate the export file (to be implemented by subclasses)
     * 
     * @param int $requestId Export request ID
     * @param string $exportType Export format
     * @param string $reportType Type of analytics report
     * @param array $projectIds Array of project IDs
     * @param array $options Export configuration options
     * @return string Path to generated file
     * @throws Exception If generation fails
     */
    protected function generateExportFile($requestId, $exportType, $reportType, $projectIds, $options): string {
        throw new Exception('generateExportFile must be implemented by subclasses');
    }
    
    /**
     * Initialize secure export directory
     */
    private function initializeExportDirectory() {
        if (!is_dir($this->exportDir)) {
            if (!mkdir($this->exportDir, 0750, true)) {
                throw new Exception('Failed to create export directory');
            }
        }
        
        // Create .htaccess file to prevent direct access
        $htaccessPath = $this->exportDir . '.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Deny from all\n");
        }
    }
    
    /**
     * Generate secure download URL
     */
    private function generateSecureDownloadUrl($requestId) {
        // Generate a secure token for download
        $token = bin2hex(random_bytes(16));
        
        // Store token in session or database for validation
        $_SESSION['export_tokens'][$requestId] = $token;
        
        return "/api/export_download.php?request_id=$requestId&token=$token";
    }
    
    /**
     * Output file for download
     */
    private function outputFile($filePath, $exportType) {
        $filename = basename($filePath);
        
        switch ($exportType) {
            case 'pdf':
                header('Content-Type: application/pdf');
                break;
            case 'excel':
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                break;
        }
        
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        
        readfile($filePath);
    }
    
    /**
     * Clean up expired file
     */
    private function cleanupExpiredFile($filePath) {
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return true;
    }
    
    /**
     * Log export activity for audit trail
     */
    private function logExportActivity($userId, $exportType, $reportType, $projectIds, $action, $errorMessage = null) {
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs 
            (user_id, action, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $details = json_encode([
            'export_type' => $exportType,
            'report_type' => $reportType,
            'project_ids' => $projectIds,
            'action' => $action,
            'error_message' => $errorMessage
        ]);
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $stmt->execute([
            $userId,
            "export_$action",
            $details,
            $ipAddress
        ]);
    }
    
    /**
     * Format data for PDF export
     */
    private function formatDataForPDF($data) {
        // Convert data to PDF-friendly format
        $formatted = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $formatted[$key] = $this->formatArrayForPDF($value);
            } else {
                $formatted[$key] = $value;
            }
        }
        
        return $formatted;
    }
    
    /**
     * Format data for Excel export
     */
    private function formatDataForExcel($data) {
        // Convert data to Excel-friendly format with worksheets
        $worksheets = [];
        
        foreach ($data as $sheetName => $sheetData) {
            $worksheets[$sheetName] = $this->formatArrayForExcel($sheetData);
        }
        
        return $worksheets;
    }
    
    /**
     * Format array data for PDF
     */
    private function formatArrayForPDF($array) {
        if (empty($array)) {
            return [];
        }
        
        // Convert to table format for PDF
        $formatted = [];
        
        if (isset($array[0]) && is_array($array[0])) {
            // Array of arrays - convert to table
            $headers = array_keys($array[0]);
            $formatted['headers'] = $headers;
            $formatted['rows'] = $array;
        } else {
            // Simple key-value array
            $formatted['data'] = $array;
        }
        
        return $formatted;
    }
    
    /**
     * Format array data for Excel
     */
    private function formatArrayForExcel($array) {
        if (empty($array)) {
            return [
                'headers' => [],
                'rows' => []
            ];
        }
        
        if (isset($array[0]) && is_array($array[0])) {
            // Array of arrays - extract headers and rows
            return [
                'headers' => array_keys($array[0]),
                'rows' => $array
            ];
        } else {
            // Simple key-value array - convert to two-column format
            $rows = [];
            foreach ($array as $key => $value) {
                $rows[] = [$key, $value];
            }
            
            return [
                'headers' => ['Property', 'Value'],
                'rows' => $rows
            ];
        }
    }

    /**
     * Bridge method for generating export (simplified call from controller)
     */
    public function generateExport($exportType, $reportType, $projectIds, $options = []) {
        $userId = $_SESSION['client_user_id'] ?? ($_SESSION['user_id'] ?? 0);
        return $this->createExportRequest($userId, $exportType, $reportType, $projectIds, $options);
    }
}