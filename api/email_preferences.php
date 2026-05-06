<?php
/**
 * Email Preferences API
 */
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/models/NotificationManager.php';
ob_end_clean();

header('Content-Type: application/json');

try {
    $notificationManager = new NotificationManager();
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Handle GET requests (for unsubscribe links)
    if ($method === 'GET') {
        $clientId = $_GET['client_id'] ?? null;
        $action = $_GET['action'] ?? 'view';
        
        if (!$clientId) {
            throw new Exception('Client ID is required');
        }
        
        // Validate client exists and is active
        $stmt = $db->prepare("
            SELECT id, username, email, full_name 
            FROM users 
            WHERE id = ? AND role = 'client' AND is_active = 1
        ");
        $stmt->execute([$clientId]);
        $clientData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$clientData) {
            throw new Exception('Invalid client ID');
        }
        
        if ($action === 'unsubscribe') {
            // Unsubscribe from all notifications
            $preferences = [
                'summary_opt_out' => '1',
                'assignment_notifications' => '0',
                'revocation_notifications' => '0'
            ];
            
            $success = $notificationManager->updateCommunicationPreferences($clientId, $preferences);
            
            if ($success) {
                // Redirect to confirmation page - use only path, not full URL to prevent open redirect
                header("Location: /preferences?client_id=" . (int)$clientId . "&unsubscribed=1");
                exit;
            } else {
                throw new Exception('Failed to update preferences');
            }
        } else {
            // Redirect to preferences page
            header("Location: /preferences?client_id=" . (int)$clientId);
            exit;
        }
    }
    
    // Handle POST requests (for authenticated preference updates)
    if ($method === 'POST') {
        // Ensure user is authenticated
        if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }

        // CSRF protection
        require_once __DIR__ . '/../includes/helpers.php';
        enforceApiCsrf();
        
        $clientUserId = $_SESSION['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['preferences'])) {
            throw new Exception('Invalid request data');
        }
        
        $preferences = $input['preferences'];
        
        // Validate preference keys
        $validKeys = ['summary_opt_out', 'assignment_notifications', 'revocation_notifications'];
        foreach ($preferences as $key => $value) {
            if (!in_array($key, $validKeys)) {
                throw new Exception("Invalid preference key: $key");
            }
            
            // Ensure values are strings '0' or '1'
            $preferences[$key] = $value ? '1' : '0';
        }
        
        $success = $notificationManager->updateCommunicationPreferences($clientUserId, $preferences);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Preferences updated successfully',
                'preferences' => $preferences
            ]);
        } else {
            throw new Exception('Failed to update preferences');
        }
    }
    
    // Handle unsupported methods
    if (!in_array($method, ['GET', 'POST'])) {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
