<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();

$userId = $_SESSION['user_id'];
$db = Database::getInstance();

// If no force reset is needed, redirect back to dashboard
if (!($_SESSION['force_reset'] ?? false)) {
    $role = $_SESSION['role'] ?? 'auth';
    $moduleDir = getModuleDirectory($role);
    redirect("/modules/$moduleDir/dashboard.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (strlen($newPassword) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $newPassword)) {
        $error = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[0-9]/', $newPassword)) {
        $error = "Password must contain at least one number.";
    } elseif (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        $error = "Password must contain at least one special character.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match!";
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password, clear the flag, clear temp password, and mark account setup as completed
        $stmt = $db->prepare("UPDATE users SET password = ?, force_password_reset = 0, temp_password = NULL, account_setup_completed = 1 WHERE id = ?");
        if ($stmt->execute([$hashedPassword, $userId])) {
            $_SESSION['force_reset'] = 0;
            $_SESSION['success'] = "Password updated successfully. Welcome!";
            
            $role = $_SESSION['role'] ?? 'auth';
            $moduleDir = getModuleDirectory($role);
            redirect("/modules/$moduleDir/dashboard.php");
            exit;
        } else {
            $error = "Failed to update password. Please try again.";
        }
    }
    } // end CSRF check
}

// We don't include header.php because it might cause a redirect loop
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - First Login - PMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; }
        .reset-container { max-width: 450px; margin-top: 100px; }
    </style>
</head>
<body>
    <div class="container reset-container">
        <div class="card shadow">
            <div class="card-header bg-primary text-white text-center">
                <h4><i class="fas fa-lock"></i> Change Password</h4>
                <p class="mb-0">This is your first login. Please set a new password.</p>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" id="new_password" name="new_password" autocomplete="off" class="form-control" placeholder="Min 8 chars, uppercase, number, special char">
                            <button type="button" class="btn btn-outline-secondary" data-toggle-password="new_password" aria-label="Toggle password visibility" aria-pressed="false">
                                <i class="fas fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" id="confirm_password" name="confirm_password" autocomplete="off" class="form-control">
                            <button type="button" class="btn btn-outline-secondary" data-toggle-password="confirm_password" aria-label="Toggle password visibility" aria-pressed="false">
                                <i class="fas fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="d-grid shadow-sm">
                        <button type="submit" class="btn btn-primary">Update Password & Continue</button>
                    </div>
                </form>
            </div>
            <div class="card-footer text-center">
                <small class="text-muted">You will be redirected to your dashboard after update.</small>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo htmlspecialchars(getBaseDir(), ENT_QUOTES, 'UTF-8'); ?>/assets/js/auth-force-reset.js"></script>
</body>
</html>
