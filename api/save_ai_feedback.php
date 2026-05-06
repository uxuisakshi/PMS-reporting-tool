<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

// Security: Only certain roles can "train" the AI
$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['rule_id']) || empty($data['original_text']) || empty($data['improved_text'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$feedbackFile = __DIR__ . '/../storage/ai_feedback.json';
$feedback = [];

if (file_exists($feedbackFile)) {
    $content = file_get_contents($feedbackFile);
    $feedback = json_decode($content, true) ?: [];
}

// Add new feedback entry. 
// We keep it simple: date, rule_id, context, and the "Style" fix.
$newEntry = [
    'id' => uniqid(),
    'timestamp' => date('Y-m-d H:i:s'),
    'rule_id' => $data['rule_id'],
    'snippet' => $data['snippet'] ?? '',
    'original_recommendation' => $data['original_text'] ?? '',
    'improved_recommendation' => $data['improved_text'] ?? '',
    'actual_results' => $data['actual_results'] ?? '',
    'incorrect_code' => $data['incorrect_code'] ?? '',
    'correct_code' => $data['correct_code'] ?? '',
    'user_id' => $_SESSION['user_id']
];

array_unshift($feedback, $newEntry);

// Keep only the last 100 entries to avoid bloating the scanner context
$feedback = array_slice($feedback, 0, 100);

if (file_put_contents($feedbackFile, json_encode($feedback, JSON_PRETTY_PRINT))) {
    // If finding_id and project_id are provided, update the database so the edit is persistent in the UI
    $findingId = (int)($data['finding_id'] ?? 0);
    $projectId = (int)($data['project_id'] ?? 0);
    
    if ($findingId > 0 && $projectId > 0) {
        try {
            require_once __DIR__ . '/../includes/functions.php';
            $db = Database::getInstance();
            $up = $db->prepare("UPDATE automated_a11y_findings SET recommendation = ?, actual_results = ?, incorrect_code = ?, correct_code = ?, updated_at = NOW() WHERE id = ? AND project_id = ?");
            $up->execute([
                $data['improved_text'] ?? '',
                $data['actual_results'] ?? '',
                $data['incorrect_code'] ?? '',
                $data['correct_code'] ?? '',
                $findingId, 
                $projectId
            ]);
        } catch (Throwable $e) {
            // Silently ignore DB update error, the primary goal (JSON feedback) succeeded
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'AI style feedback saved successfully']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save feedback file']);
}
