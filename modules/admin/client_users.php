<?php
// Enable error reporting for debugging (remove after fixing)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Check if email class exists
if (file_exists(__DIR__ . '/../../includes/email.php')) {
    require_once __DIR__ . '/../../includes/email.php';
}

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();

// Check if migration has been run
try {
    $checkColumn = $db->query("SHOW COLUMNS FROM users LIKE 'client_id'")->fetch();
    if (!$checkColumn) {
        die('
            <div style="font-family: Arial; padding: 40px; max-width: 800px; margin: 50px auto; background: #fff3cd; border: 2px solid #ffc107; border-radius: 10px;">
                <h2 style="color: #856404; margin-top: 0;">⚠️ Database Migration Required</h2>
                <p style="font-size: 16px; line-height: 1.6;">
                    The database needs to be updated before you can use this feature.
                </p>
                <h3 style="color: #856404;">Please run the migration first:</h3>
                <ol style="font-size: 14px; line-height: 1.8;">
                    <li><strong>Option 1 (Easiest):</strong> Use phpMyAdmin
                        <ul>
                            <li>Open phpMyAdmin</li>
                            <li>Select your database</li>
                            <li>Click "SQL" tab</li>
                            <li>Copy and paste content from: <code>database/migrations/add_client_dashboard_access.sql</code></li>
                            <li>Click "Go"</li>
                        </ul>
                    </li>
                    <li><strong>Option 2:</strong> Use migration tool
                        <ul>
                            <li>Go to: <a href="../../database/migrate.php">database/migrate.php</a></li>
                            <li>Login with password</li>
                            <li>Create backup</li>
                            <li>Run migration</li>
                        </ul>
                    </li>
                </ol>
                <p style="margin-top: 20px; padding: 15px; background: white; border-left: 4px solid #ffc107;">
                    <strong>📖 Need help?</strong> Check the guide: <code>docs/PHPMYADMIN_MIGRATION.md</code>
                </p>
                <p style="text-align: center; margin-top: 30px;">
                    <a href="../../" style="display: inline-block; padding: 12px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;">
                        ← Back to Dashboard
                    </a>
                </p>
            </div>
        ');
    }
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

// Check if client role exists
try {
    $checkRole = $db->query("SHOW COLUMNS FROM users WHERE Field = 'role'")->fetch();
    if ($checkRole && strpos($checkRole['Type'], 'client') === false) {
        die('
            <div style="font-family: Arial; padding: 40px; max-width: 800px; margin: 50px auto; background: #fff3cd; border: 2px solid #ffc107; border-radius: 10px;">
                <h2 style="color: #856404; margin-top: 0;">⚠️ Migration Incomplete</h2>
                <p style="font-size: 16px;">
                    The "client" role has not been added to the users table yet.
                </p>
                <p>Please run the migration: <code>database/migrations/add_client_dashboard_access.sql</code></p>
                <p style="text-align: center; margin-top: 30px;">
                    <a href="../../" style="display: inline-block; padding: 12px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;">
                        ← Back to Dashboard
                    </a>
                </p>
            </div>
        ');
    }
} catch (Exception $e) {
    // Ignore this check if it fails
}

// Check if client_permissions table exists
try {
    $checkTable = $db->query("SHOW TABLES LIKE 'client_permissions'")->fetch();
    if (!$checkTable) {
        die('
            <div style="font-family: Arial; padding: 40px; max-width: 800px; margin: 50px auto; background: #fff3cd; border: 2px solid #ffc107; border-radius: 10px;">
                <h2 style="color: #856404; margin-top: 0;">⚠️ Migration Required</h2>
                <p style="font-size: 16px;">
                    The client_permissions table does not exist yet.
                </p>
                <p>Please run the migration: <code>database/migrations/add_client_project_permissions.sql</code></p>
                <p style="text-align: center; margin-top: 30px;">
                    <a href="../../" style="display: inline-block; padding: 12px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;">
                        ← Back to Dashboard
                    </a>
                </p>
            </div>
        ');
    }
} catch (Exception $e) {
    // Ignore this check if it fails
}

// Handle Create Client User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_client_user'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: client_users.php');
        exit;
    }
    $username = sanitizeInput($_POST['username']);
    $fullName = sanitizeInput($_POST['full_name']);
    $email = sanitizeInput($_POST['email']);
    $clientId = intval($_POST['client_id']);
    $password = $_POST['password'];
    $grantViewAccess = isset($_POST['grant_view_access']) ? 1 : 0;
    
    try {
        // Check if email or username already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = "Email or Username already exists.";
        } else {
            // Create user
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("
                INSERT INTO users (username, full_name, email, password, role, client_id, force_password_reset, temp_password, account_setup_completed, created_at) 
                VALUES (?, ?, ?, ?, 'client', ?, 1, ?, 0, NOW())
            ");
            $stmt->execute([$username, $fullName, $email, $hashedPassword, $clientId, $password]);
            $userId = $db->lastInsertId();
            
            // Grant view_project permission if checked
            if ($grantViewAccess) {
                $stmt = $db->prepare("
                    INSERT INTO client_permissions (client_id, user_id, permission_type, granted_by, is_active)
                    VALUES (?, ?, 'view_project', ?, 1)
                ");
                $stmt->execute([$clientId, $userId, $_SESSION['user_id']]);
            }
            
            // Send welcome email
            try {
                if (class_exists('EmailSender')) {
                    $clientStmt = $db->prepare("SELECT name FROM clients WHERE id = ?");
                    $clientStmt->execute([$clientId]);
                    $client = $clientStmt->fetch();
                    
                    $emailSender = new EmailSender();
                    $subject = "Welcome to PMS - Client Portal Access";
                    $body = "
                        <h2>Welcome to PMS</h2>
                        <p>Hello {$fullName},</p>
                        <p>Your client portal account has been created for <strong>{$client['name']}</strong>.</p>
                        <p><strong>Login Details:</strong></p>
                        <ul>
                            <li>Email: {$email}</li>
                            <li>Password: {$password}</li>
                        </ul>
                        <p>Please login and change your password immediately.</p>
                        <p>You can access your project dashboard at: <a href='" . getBaseDir() . "/modules/client/dashboard.php'>Client Dashboard</a></p>
                    ";
                    $emailSender->send($email, $subject, $body, true);
                }
            } catch (Exception $e) {
                error_log("Failed to send welcome email: " . $e->getMessage());
                // Don't fail the user creation if email fails
            }
            
            // Log activity
            $stmt = $db->prepare("
                INSERT INTO activity_log (user_id, action, entity_type, entity_id, details)
                VALUES (?, 'create', 'client_user', ?, ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $userId,
                json_encode(['client_id' => $clientId, 'email' => $email])
            ]);
            
            $_SESSION['success'] = "Client user created successfully!";
            redirect("/modules/admin/client_users.php");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error creating client user: " . $e->getMessage();
    }
}

// Handle Update Client User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_client_user'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: client_users.php');
        exit;
    }
    $userId = intval($_POST['user_id']);
    $username = sanitizeInput($_POST['username']);
    $fullName = sanitizeInput($_POST['full_name']);
    $email = sanitizeInput($_POST['email']);
    $clientId = intval($_POST['client_id']);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        $stmt = $db->prepare("
            UPDATE users 
            SET username = ?, full_name = ?, email = ?, client_id = ?, is_active = ?
            WHERE id = ? AND role = 'client'
        ");
        $stmt->execute([$username, $fullName, $email, $clientId, $isActive, $userId]);
        
        $_SESSION['success'] = "Client user updated successfully!";
        redirect("/modules/admin/client_users.php");
    } catch (Exception $e) {
        $_SESSION['error'] = "Error updating client user: " . $e->getMessage();
    }
}

// Handle Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: client_users.php');
        exit;
    }
    $userId = intval($_POST['user_id']);
    $newPassword = $_POST['new_password'];
    
    try {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ?, force_password_reset = 1 WHERE id = ? AND role = 'client'");
        $stmt->execute([$hashedPassword, $userId]);
        
        $_SESSION['success'] = "Password reset successfully! User will be prompted to change it on next login.";
        redirect("/modules/admin/client_users.php");
    } catch (Exception $e) {
        $_SESSION['error'] = "Error resetting password: " . $e->getMessage();
    }
}

// Handle Delete Client User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client_user'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: client_users.php');
        exit;
    }
    $userId = intval($_POST['user_id']);
    try {
        // Verify it's a client user
        $check = $db->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND role = 'client' LIMIT 1");
        $check->execute([$userId]);
        $clientUser = $check->fetch(PDO::FETCH_ASSOC);

        if (!$clientUser) {
            $_SESSION['error'] = 'Client user not found.';
            header('Location: client_users.php');
            exit;
        }

        // Delete related records first
        $db->prepare("DELETE FROM client_permissions WHERE user_id = ?")->execute([$userId]);
        $db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$userId]);

        // Delete the user
        $db->prepare("DELETE FROM users WHERE id = ? AND role = 'client'")->execute([$userId]);

        // Log activity
        try {
            logActivity($db, (int)$_SESSION['user_id'], 'delete_client_user', 'users', $userId, [
                'deleted_email' => $clientUser['email'],
                'deleted_name'  => $clientUser['full_name'],
            ]);
        } catch (Throwable $_) {}

        $_SESSION['success'] = "Client user '{$clientUser['full_name']}' deleted successfully.";
    } catch (Exception $e) {
        $_SESSION['error'] = "Error deleting client user: " . $e->getMessage();
    }
    header('Location: client_users.php');
    exit;
}

// Get all clients
$clients = [];
try {
    $clients = $db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching clients: " . $e->getMessage());
    $_SESSION['error'] = "Error loading clients: " . $e->getMessage();
}

// Get all client users (check if columns exist)
$clientUsers = [];
$hasLastLogin = false;
$hasIsActive = false;

try {
    $checkColumn = $db->query("SHOW COLUMNS FROM users LIKE 'last_login'")->fetch();
    $hasLastLogin = $checkColumn ? true : false;
    
    $checkColumn = $db->query("SHOW COLUMNS FROM users LIKE 'is_active'")->fetch();
    $hasIsActive = $checkColumn ? true : false;
} catch (Exception $e) {
    error_log("Error checking columns: " . $e->getMessage());
    $hasLastLogin = false;
    $hasIsActive = false;
}

try {
    $lastLoginField = $hasLastLogin ? 'u.last_login,' : '';
    $isActiveField = $hasIsActive ? 'u.is_active,' : '1 as is_active,';

    $query = "
        SELECT 
            u.id,
            u.username,
            u.full_name,
            u.email,
            u.client_id,
            $isActiveField
            $lastLoginField
            u.created_at,
            c.name as client_name,
            GROUP_CONCAT(DISTINCT cp.permission_type) as permissions
        FROM users u
        LEFT JOIN clients c ON u.client_id = c.id
        LEFT JOIN client_permissions cp ON u.id = cp.user_id AND cp.is_active = 1
        WHERE u.role = 'client'
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ";
    
    $clientUsers = $db->query($query)->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching client users: " . $e->getMessage());
    $_SESSION['error'] = "Error loading client users. Please check if migrations have been run. Error: " . $e->getMessage();
    $clientUsers = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2><i class="fas fa-users"></i> Client Users Management</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="fas fa-plus"></i> Create Client User
                </button>
            </div>
            <p class="text-muted">Manage client portal users and their access to projects</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="clientUsersTable">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Client</th>
                            <th>Permissions</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientUsers as $user): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($user['username'] ?? ''); ?></strong></td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <?php if ($user['client_name']): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($user['client_name']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">No Client</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['permissions']): ?>
                                    <?php foreach (explode(',', $user['permissions']) as $perm): ?>
                                        <span class="badge bg-success"><?php echo htmlspecialchars($perm); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                if ($hasLastLogin && isset($user['last_login']) && $user['last_login']) {
                                    echo date('M d, Y H:i', strtotime($user['last_login']));
                                } else {
                                    echo '<span class="text-muted">N/A</span>';
                                }
                                ?>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-outline-warning" onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    <a href="<?php echo $baseDir; ?>/modules/admin/client_permissions.php?user_id=<?php echo $user['id']; ?>" class="btn btn-outline-info">
                                        <i class="fas fa-shield-alt"></i>
                                    </a>
                                    <button class="btn btn-outline-danger" onclick="deleteClientUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name']), ENT_QUOTES); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Create Client User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Client <span class="text-danger">*</span></label>
                        <select name="client_id" class="form-select" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="text" name="password" class="form-control" required value="<?php echo bin2hex(random_bytes(8)); ?>">
                        <small class="text-muted">User will be prompted to change this on first login</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="grant_view_access" class="form-check-input" id="grantViewAccess" checked>
                            <label class="form-check-label" for="grantViewAccess">
                                Grant view access to all client projects
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_client_user" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Client User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="edit_username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Client <span class="text-danger">*</span></label>
                        <select name="client_id" id="edit_client_id" class="form-select" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="edit_is_active">
                            <label class="form-check-label" for="edit_is_active">
                                Active
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_client_user" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="user_id" id="reset_user_id">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Reset password for: <strong id="reset_user_name"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">New Password <span class="text-danger">*</span></label>
                        <input type="text" name="new_password" class="form-control" required value="<?php echo bin2hex(random_bytes(8)); ?>">
                        <small class="text-muted">User will be forced to change this on next login</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-warning">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Client User Modal -->
<div class="modal fade" id="deleteClientUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="deleteClientUserForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="delete_client_user" value="1">
                <input type="hidden" name="user_id" id="delete_user_id">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="fas fa-trash me-2"></i>Delete Client User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>This action cannot be undone.</strong>
                    </div>
                    <p>Are you sure you want to permanently delete client user: <strong id="delete_user_name"></strong>?</p>
                    <p class="text-muted small mb-0">This will also remove their permissions and session records.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>Yes, Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo $baseDir; ?>/assets/js/client-users.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/../../includes/footer.php'; 