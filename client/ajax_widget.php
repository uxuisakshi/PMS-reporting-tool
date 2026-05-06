<?php
/**
 * AJAX Widget Endpoint
 * Handles AJAX requests for dashboard widget data
 * Requirements: 12.3, 12.4, 13.5
 */

// Set JSON content type
header('Content-Type: application/json');

// Start session
session_start();

try {
    // Include required files
    require_once __DIR__ . '/../includes/controllers/ClientDashboardController.php';
    
    // Check if request is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    // Create controller instance
    $controller = new ClientDashboardController();
    
    // Handle the AJAX widget request
    $controller->ajaxWidget();
    
} catch (Exception $e) {
    error_log("AJAX Widget Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>