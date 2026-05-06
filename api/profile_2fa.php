<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/GoogleAuthenticator.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$user_id = $_SESSION['user_id'];
$db = Database::getInstance();

// CSRF protection
enforceApiCsrf();

// For sensitive operations, require password re-authentication
if (in_array($action, ['disable_2fa', 'generate_secret'], true)) {
    $password = $_POST['password'] ?? '';
    
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Incorrect password. Re-authentication failed.']);
        exit;
    }
}

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'generate_secret':
            // Don't overwrite if already enabled
            $stmt = $db->prepare("SELECT two_factor_enabled FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $is_enabled = (int)$stmt->fetchColumn();
            
            if ($is_enabled === 1) {
                echo json_encode(['success' => false, 'message' => '2FA is already enabled on this account.']);
                exit;
            }

            $ga = new GoogleAuthenticator();
            $secret = $ga->createSecret();
            
            // Save temporary secret to database
            $upd = $db->prepare("UPDATE users SET two_factor_secret = ? WHERE id = ?");
            $upd->execute([$secret, $user_id]);
            
            // Generate QR Code URL
            $appName = 'PMS SIS';
            $userEmail = $_SESSION['email'] ?? 'User';
            $otpauthUri = $ga->getOTPAuthUri($userEmail, $secret, $appName);
            $qrCodeUrl = $ga->getQRCodeGoogleUrl($userEmail, $secret, $appName);
            
            echo json_encode([
                'success' => true,
                'secret' => $secret,
                'qr_url' => $qrCodeUrl,
                'otpauth_uri' => $otpauthUri
            ]);
            break;

        case 'verify_and_enable':
            $code = trim($_POST['code'] ?? '');
            if (empty($code) || strlen($code) !== 6) {
                echo json_encode(['success' => false, 'message' => 'Invalid code format.']);
                exit;
            }

            $stmt = $db->prepare("SELECT two_factor_secret, two_factor_enabled FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if (!$user || empty($user['two_factor_secret'])) {
                echo json_encode(['success' => false, 'message' => 'Setup not initiated. Please reload and try again.']);
                exit;
            }
            
            if ($user['two_factor_enabled'] == 1) {
                echo json_encode(['success' => false, 'message' => '2FA is already enabled.']);
                exit;
            }

            $ga = new GoogleAuthenticator();
            // Verify code with 1 window discrepancy (30 seconds)
            $checkResult = $ga->verifyCode($user['two_factor_secret'], $code, 1);
            
            if ($checkResult) {
                // Success: Enable it!
                $upd = $db->prepare("UPDATE users SET two_factor_enabled = 1 WHERE id = ?");
                $upd->execute([$user_id]);
                echo json_encode(['success' => true, 'message' => 'Two-Factor Authentication successfully enabled.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Incorrect code. Please try again.']);
            }
            break;

        case 'disable_2fa':
            $upd = $db->prepare("UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?");
            $upd->execute([$user_id]);
            echo json_encode(['success' => true, 'message' => 'Two-Factor Authentication has been disabled.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
