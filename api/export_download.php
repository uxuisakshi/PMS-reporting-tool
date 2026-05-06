<?php
/**
 * Secure Export File Download API
 */
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/models/ExportEngine.php';
ob_end_clean();

// Start session for token validation
session_start();

try {
    // Validate authentication
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        die('Unauthorized access');
    }
    
    $userId = $auth->getUserId();
    $requestId = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    
    // Validate parameters
    if (!$requestId || !$token) {
        http_response_code(400);
        die('Missing required parameters');
    }
    
    // Validate token
    if (!isset($_SESSION['export_tokens'][$requestId]) || 
        $_SESSION['export_tokens'][$requestId] !== $token) {
        http_response_code(403);
        die('Invalid or expired download token');
    }
    
    // Initialize export engine and download file
    $exportEngine = new ExportEngine();
    $exportEngine->downloadExportFile($requestId, $userId);
    
    // Clean up token after successful download
    unset($_SESSION['export_tokens'][$requestId]);
    
} catch (Exception $e) {
    error_log("Export download error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
