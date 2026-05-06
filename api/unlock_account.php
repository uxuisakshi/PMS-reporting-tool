<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';

$auth = new Auth();
$auth->requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

header('Content-Type: application/json');

$db = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'list_locked') {
    // Get users currently locked (5+ attempts in last 15 min)
    $stmt = $db->query("
        SELECT la.username_hash, COUNT(*) as attempts, MIN(la.attempted_at) as first_attempt,
               MAX(la.attempted_at) as last_attempt,
               u.username, u.full_name, u.email
        FROM login_attempts la
        LEFT JOIN users u ON MD5(LOWER(u.username)) = la.username_hash
        WHERE la.attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        GROUP BY la.username_hash
        HAVING attempts >= 5
        ORDER BY last_attempt DESC
    ");
    $locked = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'locked_users' => $locked]);
    exit;
}

if ($action === 'unlock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameHash = trim($_POST['username_hash'] ?? '');
    if (!$usernameHash) {
        echo json_encode(['error' => 'username_hash required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE username_hash = ?");
    $stmt->execute([$usernameHash]);
    echo json_encode(['success' => true, 'message' => 'Account unlocked']);
    exit;
}

if ($action === 'unlock_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db->exec("DELETE FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    echo json_encode(['success' => true, 'message' => 'All locked accounts unlocked']);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
