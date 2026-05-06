<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

/** @var \PDO $db */
$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: manage_categories.php');
        exit;
    }
    $name = trim($_POST['name']);
    $desc = trim($_POST['description']);
    
    if (!empty($name)) {
        // Prevent duplicate names
        $dup = $db->prepare("SELECT id FROM generic_task_categories WHERE name = ? LIMIT 1");
        $dup->execute([$name]);
        if ($dup->fetchColumn()) {
            $_SESSION['error'] = "Generic Task already exists with the same title.";
        } else {
            $stmt = $db->prepare("INSERT INTO generic_task_categories (name, description, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$name, $desc, $userId]);
            $_SESSION['success'] = "Generic Task added successfully.";
        }
    } else {
        $_SESSION['error'] = "Title is required.";
    }
    header("Location: manage_categories.php");
    exit;
}

// Handle Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: manage_categories.php');
        exit;
    }
    $catId = (int)($_POST['category_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if ($catId <= 0 || $name === '') {
        $_SESSION['error'] = "Title is required.";
    } else {
        $dup = $db->prepare("SELECT id FROM generic_task_categories WHERE name = ? AND id <> ? LIMIT 1");
        $dup->execute([$name, $catId]);
        if ($dup->fetchColumn()) {
            $_SESSION['error'] = "Another Generic Task already exists with the same title.";
        } else {
            $stmt = $db->prepare("UPDATE generic_task_categories SET name = ?, description = ? WHERE id = ?");
            if ($stmt->execute([$name, $desc, $catId])) {
                $_SESSION['success'] = "Generic Task updated successfully.";
            } else {
                $_SESSION['error'] = "Failed to update Generic Task.";
            }
        }
    }
    header("Location: manage_categories.php");
    exit;
}

// Handle Toggle Status
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: manage_categories.php');
        exit;
    }
    $id = $_GET['id'];
    // Toggle active status
    $db->prepare("UPDATE generic_task_categories SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    header("Location: manage_categories.php");
    exit;
}

// Handle Delete Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: manage_categories.php');
        exit;
    }
    $catId = (int)($_POST['category_id'] ?? 0);
    
    // Check if category has tasks
    $stmt = $db->prepare("SELECT COUNT(*) FROM user_generic_tasks WHERE category_id = ?");
    $stmt->execute([$catId]);
    
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = "Cannot delete category with existing task logs. Try deactivating it instead.";
    } else {
        $stmt = $db->prepare("DELETE FROM generic_task_categories WHERE id = ?");
        if ($stmt->execute([$catId])) {
            $_SESSION['success'] = "Generic Task deleted successfully.";
        } else {
            $_SESSION['error'] = "Failed to delete Generic Task.";
        }
    }
    header("Location: manage_categories.php");
    exit;
}

$categories = $db->query("
    SELECT gtc.*,
           (SELECT COUNT(*) FROM user_generic_tasks ugt WHERE ugt.category_id = gtc.id) AS usage_count
    FROM generic_task_categories gtc
    ORDER BY gtc.name
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Manage Generic Tasks</h2>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Tasks
        </a>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5>Add Generic Task</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <div class="mb-3">
                            <label class="form-label">Title *</label>
                            <input type="text" name="name" class="form-control" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <button type="submit" name="add_category" class="btn btn-primary w-100">Save</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>Existing Generic Tasks</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Usage</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cat['description']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $cat['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo (int)$cat['usage_count']; ?></span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editCatModal<?php echo $cat['id']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="manage_categories.php?toggle=1&id=<?php echo $cat['id']; ?>&csrf_token=<?php echo generateCsrfToken(); ?>" 
                                           class="btn btn-sm btn-<?php echo $cat['is_active'] ? 'warning' : 'success'; ?>">
                                            <?php echo $cat['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-danger" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#deleteCatModal<?php echo $cat['id']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>

                                        <!-- Edit Category Modal -->
                                        <div class="modal fade" id="editCatModal<?php echo $cat['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                                        <input type="hidden" name="category_id" value="<?php echo (int)$cat['id']; ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Generic Task</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body text-start">
                                                            <div class="mb-3">
                                                                <label class="form-label">Title *</label>
                                                                <input type="text" name="name" class="form-control" required maxlength="100" value="<?php echo htmlspecialchars($cat['name']); ?>">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Description</label>
                                                                <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($cat['description']); ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="edit_category" class="btn btn-primary">Save</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Delete Category Modal -->
                                        <div class="modal fade" id="deleteCatModal<?php echo $cat['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Delete Generic Task</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body text-start">
                                                            <p>Are you sure you want to delete generic task <strong><?php echo htmlspecialchars($cat['name']); ?></strong>?</p>
                                                            <p class="text-danger small">You can only delete items that have no task logs. Current usage: <?php echo (int)$cat['usage_count']; ?></p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="delete_category" class="btn btn-danger" <?php echo ((int)$cat['usage_count'] > 0) ? 'disabled' : ''; ?>>Delete</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
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
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 