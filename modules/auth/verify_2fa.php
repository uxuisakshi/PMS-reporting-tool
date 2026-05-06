<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$error = '';

// Check if we are actually in a 2FA pending state
if (!isset($_SESSION['2fa_pending_user_id'])) {
    redirect("/modules/auth/login.php");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        $code = trim($_POST['otp_code'] ?? '');
        
        if (empty($code)) {
            $error = "Please enter the 6-digit code from your app.";
        } else {
            $userId = $_SESSION['2fa_pending_user_id'];
            $verifyResult = $auth->verify2FALogin($userId, $code);
            if ($verifyResult === true) {
                // Handle "Remember this device"
                if (!empty($_POST['trust_device'])) {
                    $auth->trustDevice($userId);
                }
                
                $role = $_SESSION['role'];
                if ($role === 'client') {
                    redirect("/client/dashboard");
                } else {
                    $moduleDir = getModuleDirectory($role);
                    redirect("/modules/{$moduleDir}/dashboard.php");
                }
            } elseif ($verifyResult === 'locked') {
                $error = "Too many invalid verification attempts. Please wait 10 minutes and try again.";
            } else {
                $error = "Invalid or expired verification code. Please try again.";
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container d-flex align-items-center justify-content-center" style="min-height: 70vh;">
    <div class="row justify-content-center w-100">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-3 text-center">
                    <div class="mb-3">
                        <i class="fas fa-shield-alt fa-2x text-primary"></i>
                    </div>
                    <h4 class="card-title mb-1">2FA Verification</h4>
                    <p class="text-muted small mb-3">Enter the 6-digit code from your app.</p>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger small py-1 mb-2"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <div class="mb-4 px-4">
                            <input type="text" 
                                   name="otp_code" 
                                   id="otp_code" 
                                   class="form-control form-control-lg text-center fw-bold fs-2 tracking-widest border-2" 
                                   placeholder="000000" 
                                   maxlength="6" 
                                   pattern="\d{6}"
                                   autofocus 
                                   required 
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                        </div>

                        <div class="mb-4 text-start d-flex align-items-center justify-content-center">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="trust_device" id="trust_device" value="1">
                                <label class="form-check-label small" for="trust_device">
                                    Remember this device for 30 days
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3 shadow-sm">
                            <i class="fas fa-sign-in-alt me-2"></i> Verify & Login
                        </button>
                    </form>
                    
                    <a href="login.php" class="btn btn-link btn-sm text-muted text-decoration-none">
                        <i class="fas fa-arrow-left me-1"></i> Back to Login
                    </a>
                </div>
            </div>
            
            <div class="text-center mt-4 text-muted small">
                <p>Lost access to your device? Contact your system administrator for assistance.</p>
            </div>
        </div>
    </div>
</div>

<style>
.tracking-widest {
    letter-spacing: 0.25em;
}
.border-2 {
    border-width: 2px !important;
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; 