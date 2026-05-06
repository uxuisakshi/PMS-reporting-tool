<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// CSRF protection
enforceApiCsrf();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get':
            // Get draft for user and project
            $projectId = $_GET['project_id'] ?? null;
            if (!$projectId) {
                throw new Exception('Project ID is required');
            }
            
            $stmt = $db->prepare("
                SELECT issue_params, updated_at 
                FROM issue_drafts 
                WHERE user_id = ? AND project_id = ?
            ");
            $stmt->execute([$userId, $projectId]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($draft) {
                echo json_encode([
                    'success' => true,
                    'draft' => json_decode($draft['issue_params'], true),
                    'updated_at' => $draft['updated_at']
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'draft' => null
                ]);
            }
            break;
            
        case 'save':
            // Save or update draft
            $projectId = $_POST['project_id'] ?? null;
            $issueParams = $_POST['issue_params'] ?? null;
            
            if (!$projectId || !$issueParams) {
                throw new Exception('Project ID and issue parameters are required');
            }
            
            // Validate JSON
            $paramsDecoded = json_decode($issueParams, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON in issue parameters');
            }
            
            // Insert or update
            $stmt = $db->prepare("
                INSERT INTO issue_drafts (user_id, project_id, issue_params)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    issue_params = VALUES(issue_params),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$userId, $projectId, $issueParams]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Draft saved successfully'
            ]);
            break;
            
        case 'delete':
            // Delete draft
            $projectId = $_POST['project_id'] ?? $_GET['project_id'] ?? null;
            if (!$projectId) {
                throw new Exception('Project ID is required');
            }
            
            $stmt = $db->prepare("
                DELETE FROM issue_drafts 
                WHERE user_id = ? AND project_id = ?
            ");
            $stmt->execute([$userId, $projectId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Draft deleted successfully'
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    error_log('issue_drafts error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An internal error occurred'
    ]);
}
