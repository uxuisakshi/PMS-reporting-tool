<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/email.php';

$auth = new Auth();
$auth->requireRole('admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Verify CSRF token
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$userIds = [];
if (isset($_POST['user_id'])) {
    $userIds[] = (int)$_POST['user_id'];
} elseif (isset($_POST['user_ids']) && is_array($_POST['user_ids'])) {
    $userIds = array_map('intval', $_POST['user_ids']);
}

if (empty($userIds)) {
    echo json_encode(['success' => false, 'error' => 'No users selected']);
    exit;
}

$db = Database::getInstance();
$mailer = new EmailSender();
$successCount = 0;
$failCount = 0;
$errors = [];

foreach ($userIds as $id) {
    if ($id <= 0) continue;
    
    $stmt = $db->prepare("SELECT full_name, email, two_factor_enabled FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $errors[] = "User #$id not found";
        $failCount++;
        continue;
    }
    
    if ($user['two_factor_enabled']) {
        $errors[] = "{$user['full_name']} already has 2FA enabled";
        $failCount++;
        continue;
    }
    
    if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "{$user['full_name']} has an invalid email";
        $failCount++;
        continue;
    }
    
    if ($mailer->send2FAReminderEmail($user['email'], $user['full_name'])) {
        $successCount++;
        // Log activity
        logActivity($db, $id, '2fa_reminder_sent', 'admin', null, ['sent_by' => $_SESSION['user_id']]);
    } else {
        $errors[] = "Failed to send email to {$user['full_name']}";
        $failCount++;
    }
}

echo json_encode([
    'success' => $successCount > 0,
    'success_count' => $successCount,
    'fail_count' => $failCount,
    'errors' => $errors,
    'message' => $successCount > 0 ? "Successfully sent $successCount reminder(s)." : "Failed to send reminders."
]);
