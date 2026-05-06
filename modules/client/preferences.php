<?php
/**
 * Client Preferences Page
 * Allows clients to manage email notification preferences and account settings.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Ensure user is authenticated and has client role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header('Location: ' . getBaseDir() . '/modules/auth/login.php');
    exit;
}

$db = Database::getInstance();
$clientUserId = (int)$_SESSION['user_id'];
$baseDir = getBaseDir();

// Ensure user_meta table exists (auto-create if missing)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `user_meta` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `meta_key` varchar(100) NOT NULL,
        `meta_value` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_meta` (`user_id`, `meta_key`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_meta_key` (`meta_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Table may already exist, ignore
}

// Helper: get a meta value
function getMetaValue($db, $userId, $key, $default = null) {
    try {
        $stmt = $db->prepare("SELECT meta_value FROM user_meta WHERE user_id = ? AND meta_key = ?");
        $stmt->execute([$userId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row['meta_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Helper: set a meta value (upsert)
function setMetaValue($db, $userId, $key, $value) {
    $stmt = $db->prepare("
        INSERT INTO user_meta (user_id, meta_key, meta_value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()
    ");
    $stmt->execute([$userId, $key, $value]);
}

$flashSuccess = '';
$flashError = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $flashError = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // --- Email Notification Preferences ---
        if ($action === 'update_email_prefs') {
            try {
                setMetaValue($db, $clientUserId, 'email_assignment_notifications', isset($_POST['assignment_notifications']) ? '1' : '0');
                setMetaValue($db, $clientUserId, 'email_revocation_notifications', isset($_POST['revocation_notifications']) ? '1' : '0');
                setMetaValue($db, $clientUserId, 'email_summary_opt_out', isset($_POST['summary_opt_out']) ? '1' : '0');
                $flashSuccess = 'Email notification preferences saved successfully.';
            } catch (Exception $e) {
                error_log('Preferences update error: ' . $e->getMessage());
                $flashError = 'Failed to save preferences. Please try again.';
            }
        }

        // --- Unsubscribe from all ---
        if ($action === 'unsubscribe_all') {
            try {
                setMetaValue($db, $clientUserId, 'email_assignment_notifications', '0');
                setMetaValue($db, $clientUserId, 'email_revocation_notifications', '0');
                setMetaValue($db, $clientUserId, 'email_summary_opt_out', '1');
                $flashSuccess = 'You have been unsubscribed from all email notifications.';
            } catch (Exception $e) {
                $flashError = 'Failed to unsubscribe. Please try again.';
            }
        }

        // --- Change Password ---
        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $flashError = 'All password fields are required.';
            } elseif ($newPassword !== $confirmPassword) {
                $flashError = 'New password and confirmation do not match.';
            } elseif (strlen($newPassword) < 8) {
                $flashError = 'New password must be at least 8 characters long.';
            } else {
                try {
                    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                    $stmt->execute([$clientUserId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$user || !password_verify($currentPassword, $user['password'])) {
                        $flashError = 'Current password is incorrect.';
                    } else {
                        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $clientUserId]);
                        $flashSuccess = 'Password changed successfully.';
                    }
                } catch (Exception $e) {
                    error_log('Password change error: ' . $e->getMessage());
                    $flashError = 'Failed to change password. Please try again.';
                }
            }
        }
    }

    // PRG pattern
    if ($flashSuccess) $_SESSION['pref_success'] = $flashSuccess;
    if ($flashError)   $_SESSION['pref_error']   = $flashError;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Read flash from session after redirect
if (isset($_SESSION['pref_success'])) { $flashSuccess = $_SESSION['pref_success']; unset($_SESSION['pref_success']); }
if (isset($_SESSION['pref_error']))   { $flashError   = $_SESSION['pref_error'];   unset($_SESSION['pref_error']); }

// Handle unsubscribe via GET link (from email)
if (isset($_GET['action']) && $_GET['action'] === 'unsubscribe') {
    try {
        setMetaValue($db, $clientUserId, 'email_assignment_notifications', '0');
        setMetaValue($db, $clientUserId, 'email_revocation_notifications', '0');
        setMetaValue($db, $clientUserId, 'email_summary_opt_out', '1');
        $flashSuccess = 'You have been unsubscribed from all email notifications.';
    } catch (Exception $e) {
        $flashError = 'Failed to unsubscribe. Please try again.';
    }
}

// Load current preferences
$prefs = [
    'assignment_notifications' => getMetaValue($db, $clientUserId, 'email_assignment_notifications', '1'),
    'revocation_notifications' => getMetaValue($db, $clientUserId, 'email_revocation_notifications', '1'),
    'summary_opt_out'          => getMetaValue($db, $clientUserId, 'email_summary_opt_out', '0'),
];

// Load user info
$stmt = $db->prepare("SELECT full_name, email, username, created_at FROM users WHERE id = ?");
$stmt->execute([$clientUserId]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <!-- Page Header -->
            <div class="d-flex align-items-center gap-3 mb-4">
                <a href="<?php echo $baseDir; ?>/client/dashboard" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h4 class="mb-0"><i class="fas fa-sliders-h text-primary me-2"></i>Preferences</h4>
                    <small class="text-muted">Manage your account and notification settings</small>
                </div>
            </div>

            <?php if ($flashSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($flashSuccess); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($flashError); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Account Info Card -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-user-circle text-primary me-2"></i>Account Information</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label text-muted small">Full Name</label>
                            <div class="fw-semibold"><?php echo htmlspecialchars($userInfo['full_name'] ?? '—'); ?></div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted small">Username</label>
                            <div class="fw-semibold"><?php echo htmlspecialchars($userInfo['username'] ?? '—'); ?></div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted small">Email Address</label>
                            <div class="fw-semibold"><?php echo htmlspecialchars($userInfo['email'] ?? '—'); ?></div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted small">Member Since</label>
                            <div class="fw-semibold"><?php echo isset($userInfo['created_at']) ? date('M j, Y', strtotime($userInfo['created_at'])) : '—'; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Email Notification Preferences -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-bell text-primary me-2"></i>Email Notification Preferences</h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_email_prefs">

                        <p class="text-muted small mb-3">Choose which email notifications you'd like to receive:</p>

                        <div class="list-group list-group-flush mb-3">
                            <!-- Assignment Notifications -->
                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="form-check form-switch mt-1">
                                        <input class="form-check-input" type="checkbox"
                                               id="assignment_notifications"
                                               name="assignment_notifications"
                                               role="switch"
                                               <?php echo ($prefs['assignment_notifications'] !== '0') ? 'checked' : ''; ?>>
                                    </div>
                                    <div>
                                        <label class="form-check-label fw-semibold" for="assignment_notifications">
                                            Project Assignment Notifications
                                        </label>
                                        <div class="text-muted small mt-1">
                                            Receive emails when you're granted access to new projects or when project access is modified.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Revocation Notifications -->
                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="form-check form-switch mt-1">
                                        <input class="form-check-input" type="checkbox"
                                               id="revocation_notifications"
                                               name="revocation_notifications"
                                               role="switch"
                                               <?php echo ($prefs['revocation_notifications'] !== '0') ? 'checked' : ''; ?>>
                                    </div>
                                    <div>
                                        <label class="form-check-label fw-semibold" for="revocation_notifications">
                                            Access Change Notifications
                                        </label>
                                        <div class="text-muted small mt-1">
                                            Receive emails when your project access is revoked or when there are changes to your permissions.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Summary Reports -->
                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="form-check form-switch mt-1">
                                        <input class="form-check-input" type="checkbox"
                                               id="summary_opt_out"
                                               name="summary_opt_out"
                                               role="switch"
                                               <?php echo ($prefs['summary_opt_out'] === '1') ? 'checked' : ''; ?>>
                                    </div>
                                    <div>
                                        <label class="form-check-label fw-semibold" for="summary_opt_out">
                                            Opt Out of Summary Reports
                                        </label>
                                        <div class="text-muted small mt-1">
                                            Enable this to stop receiving periodic summary emails with accessibility metrics and progress reports.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> Save Preferences
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Unsubscribe from all email notifications?')">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="unsubscribe_all">
                                <button type="submit" class="btn btn-outline-secondary">
                                    <i class="fas fa-bell-slash me-1"></i> Unsubscribe from All
                                </button>
                            </form>
                        </div>
                    </form>

                    <div class="alert alert-info mt-3 mb-0 py-2 small">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Note:</strong> Critical security alerts and system notifications cannot be disabled and will always be sent.
                    </div>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-lock text-primary me-2"></i>Change Password</h6>
                </div>
                <div class="card-body">
                    <form method="POST" id="changePasswordForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="change_password">

                        <div class="row g-3">
                            <div class="col-12">
                                <label for="current_password" class="form-label">Current Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="current_password"
                                           name="current_password" placeholder="Enter current password" autocomplete="current-password">
                                    <button class="btn btn-outline-secondary toggle-pw" type="button" data-target="current_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <label for="new_password" class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="new_password"
                                           name="new_password" placeholder="Min. 8 characters" autocomplete="new-password">
                                    <button class="btn btn-outline-secondary toggle-pw" type="button" data-target="new_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div id="pwStrength" class="mt-1"></div>
                            </div>
                            <div class="col-sm-6">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="confirm_password"
                                           name="confirm_password" placeholder="Repeat new password" autocomplete="new-password">
                                    <button class="btn btn-outline-secondary toggle-pw" type="button" data-target="confirm_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div id="pwMatch" class="mt-1 small"></div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key me-1"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
// Toggle password visibility
document.querySelectorAll('.toggle-pw').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var targetId = this.dataset.target;
        var input = document.getElementById(targetId);
        var icon = this.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});

// Password strength indicator
var newPwInput = document.getElementById('new_password');
var confirmPwInput = document.getElementById('confirm_password');
var pwStrength = document.getElementById('pwStrength');
var pwMatch = document.getElementById('pwMatch');

if (newPwInput) {
    newPwInput.addEventListener('input', function() {
        var pw = this.value;
        var strength = 0;
        if (pw.length >= 8) strength++;
        if (/[A-Z]/.test(pw)) strength++;
        if (/[0-9]/.test(pw)) strength++;
        if (/[^A-Za-z0-9]/.test(pw)) strength++;

        var labels = ['', '<span class="text-danger small">Weak</span>', '<span class="text-warning small">Fair</span>', '<span class="text-info small">Good</span>', '<span class="text-success small">Strong</span>'];
        pwStrength.innerHTML = pw.length > 0 ? labels[strength] || labels[1] : '';
        checkMatch();
    });
}

if (confirmPwInput) {
    confirmPwInput.addEventListener('input', checkMatch);
}

function checkMatch() {
    if (!confirmPwInput.value) { pwMatch.innerHTML = ''; return; }
    if (newPwInput.value === confirmPwInput.value) {
        pwMatch.innerHTML = '<span class="text-success"><i class="fas fa-check me-1"></i>Passwords match</span>';
    } else {
        pwMatch.innerHTML = '<span class="text-danger"><i class="fas fa-times me-1"></i>Passwords do not match</span>';
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
