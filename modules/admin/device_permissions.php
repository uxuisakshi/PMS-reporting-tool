<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireAdmin();

$page_title = 'Device Permissions';
$baseDir = getBaseDir();
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_device_perm'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: device_permissions.php');
        exit;
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    $allow = isset($_POST['can_manage_devices']) ? 1 : 0;
    if ($userId > 0) {
        $check = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $check->execute([$userId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['role'], ['admin','admin'])) {
            $_SESSION['error'] = "Role-based device access for admin users cannot be changed here.";
        } else {
            $prevStmt = $db->prepare("SELECT can_manage_devices FROM users WHERE id = ? LIMIT 1");
            $prevStmt->execute([$userId]);
            $prev = (int)($prevStmt->fetchColumn() ?? 0);
            $upd = $db->prepare("UPDATE users SET can_manage_devices = ? WHERE id = ?");
            $upd->execute([$allow, $userId]);
            if ($prev !== (int)$allow) {
                $msg = $allow ? 'You have been granted Device Management access.' : 'Your Device Management access has been removed.';
                createNotification($db, (int)$userId, 'system', $msg, $baseDir . "/modules/admin/devices.php");
            }
            $_SESSION['success'] = "Device permission updated.";
        }
    }
    header("Location: " . $baseDir . "/modules/admin/device_permissions.php");
    exit;
}

$stmt = $db->query("
    SELECT id, full_name, username, email, role, is_active, can_manage_devices
    FROM users
    ORDER BY role, full_name
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3 align-items-center">
        <div class="col">
            <h2><i class="fas fa-user-shield"></i> Device Permissions</h2>
            <p class="text-muted mb-0">Manage who can add/edit/delete/assign/return devices</p>
        </div>
        <div class="col-auto">
            <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/users.php" class="btn btn-outline-primary">
                Manage Users
            </a>
        </div>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold mb-1">Search</label>
                    <input type="text" id="dpSearch" class="form-control form-control-sm" placeholder="Name, username or email...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Role</label>
                    <select id="dpFilterRole" class="form-select form-select-sm">
                        <option value="">All Roles</option>
                        <?php
                            $roles = array_unique(array_column($users, 'role'));
                            sort($roles);
                            foreach ($roles as $r):
                        ?>
                        <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $r)), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Status</label>
                    <select id="dpFilterStatus" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Permission</label>
                    <select id="dpFilterPerm" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="granted">Granted</option>
                        <option value="not_granted">Not Granted</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="dpResetFilters()">
                        <i class="fas fa-times"></i> Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="dpTable">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Permission</th>
                            <th>Manage</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted p-4">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <?php
                                $roleLabel = ucfirst(str_replace('_', ' ', $u['role']));
                                $permLabel = in_array($u['role'], ['admin','admin']) ? 'Role-based' : 'Explicit';
                                $isRoleBased = in_array($u['role'], ['admin','admin']);
                                $hasPermission = (!empty($u['can_manage_devices']) || $isRoleBased) ? 'granted' : 'not_granted';
                                $activeStatus = !empty($u['is_active']) ? 'active' : 'inactive';
                            ?>
                            <tr data-name="<?php echo htmlspecialchars(strtolower($u['full_name'] . ' ' . $u['username'] . ' ' . $u['email']), ENT_QUOTES, 'UTF-8'); ?>"
                                data-role="<?php echo htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-status="<?php echo $activeStatus; ?>"
                                data-perm="<?php echo $hasPermission; ?>">
                                <td><?php echo htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if (!empty($u['is_active'])): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo $permLabel; ?></span>
                                </td>
                                <td>
                                    <form method="POST" class="d-flex align-items-center gap-2" data-confirm="device-perm">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="update_device_perm" value="1">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <div class="form-check m-0">
                                            <input class="form-check-input" type="checkbox" name="can_manage_devices"
                                                id="perm_<?php echo (int)$u['id']; ?>"
                                                <?php echo (!empty($u['can_manage_devices']) || $isRoleBased) ? 'checked' : ''; ?>
                                                <?php echo $isRoleBased ? 'disabled' : ''; ?>>
                                        </div>
                                        <?php if (!$isRoleBased): ?>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                        <?php else: ?>
                                            <span class="text-muted small">Locked</span>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="text-muted small" id="dpResultsInfo"></div>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label small mb-0 fw-semibold">Per page:</label>
            <select id="dpPerPage" class="form-select form-select-sm" style="width:80px;">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="dpPagination"></ul>
        </nav>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/admin-device-permissions.js"></script>

