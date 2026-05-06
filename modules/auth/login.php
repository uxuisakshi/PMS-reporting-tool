<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$error = '';
$success = '';

// Check for logout success message via short-lived cookie (not URL param)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_COOKIE['logout_msg'])) {
    $success = 'You have been successfully logged out.';
    // Immediately expire the cookie
    setcookie('logout_msg', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['session']) && $_GET['session'] === 'expired') {
    $error = 'Your session has expired. Please log in again.';
}

// If already logged in, redirect to dashboard
if ($auth->isLoggedIn()) {
    $role = $_SESSION['role'];
    
    if ($role === 'client') {
        redirect("/client/dashboard");
    } else {
        $moduleDir = getModuleDirectory($role);
        redirect("/modules/{$moduleDir}/dashboard.php");
    }
}

// Store form load time in session for time-based check
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['login_form_load_time'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    // Honeypot check — real users leave this field empty
    } elseif (!empty($_POST['hp_email_confirm'])) {
        $error = "Invalid username or password";
    // Time-based check — bots submit too fast (under 2 seconds)
    } elseif (isset($_SESSION['login_form_load_time']) && (time() - $_SESSION['login_form_load_time']) < 2) {
        $error = "Invalid username or password";
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = "Username and password are required";
        } else {
            $loginResult = $auth->login($username, $password);
            if ($loginResult === true) {
                // Redirect based on role with proper mapping
                $role = $_SESSION['role'];
                
                // Special handling for client users - redirect to new client router
                if ($role === 'client') {
                    redirect("/client/dashboard");
                } else {
                    $moduleDir = getModuleDirectory($role);
                    redirect("/modules/{$moduleDir}/dashboard.php");
                }
            } elseif ($loginResult === '2fa_required') {
                redirect("/modules/auth/verify_2fa.php");
            } elseif ($loginResult === 'locked') {
                $error = "Too many failed login attempts. Your account has been temporarily locked for 15 minutes. Please try again later.";
            } elseif (is_int($loginResult) && $loginResult > 0) {
                $error = "Invalid username or password.";
            } else {
                $error = "Invalid username or password";
            }
        }
    }
}
// Clear form load time only after successful processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    unset($_SESSION['login_form_load_time']);
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
    <div class="row justify-content-center mt-5">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h1 class="text-center">Login to Project Management System</h1>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert"><?php echo e($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert"><?php echo e($success); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" id="loginForm" novalidate data-has-error="<?php echo $error ? '1' : '0'; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                        
                        <?php /* Honeypot: hidden from real users, bots will fill it */ ?>
                        <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;">
                            <label for="hp_email_confirm">Leave this field empty</label>
                            <input type="text" id="hp_email_confirm" name="hp_email_confirm" tabindex="-1" autocomplete="off" value="">
                        </div>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username or Email</label>
                            <input type="text" autocomplete="username" class="form-control" id="username" name="username" value="<?php echo e($_POST['username'] ?? ''); ?>">
                            <div class="invalid-feedback">Please enter your username or email.</div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" autocomplete="off" class="form-control" id="password" name="password">
                                <button type="button" class="btn btn-outline-secondary" data-toggle-password="password" aria-label="Toggle password visibility" aria-pressed="false">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div id="password-error" class="invalid-feedback d-block" style="display:none!important"></div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Login</button>
                    </form>
                    <script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/auth-login.js"></script>
                    

                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>