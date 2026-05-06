<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();

$userId = $_SESSION['user_id'];
$db = Database::getInstance();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: index.php');
        exit;
    }
    $categoryId = $_POST['category_id'] ?? '';
    $description = $_POST['description'] ?? '';
        $hours = isset($_POST['hours_spent']) && $_POST['hours_spent'] !== '' ? floatval($_POST['hours_spent']) : 0;
    $date = $_POST['task_date'] ?? date('Y-m-d');
    
        if (empty($categoryId)) {
            $error = 'Please select a category.';
    } else {
        $stmt = $db->prepare("
            INSERT INTO user_generic_tasks (user_id, category_id, task_description, hours_spent, task_date)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$userId, $categoryId, $description, $hours, $date])) {
            $_SESSION['success'] = "Task logged successfully!";
            header("Location: index.php?date=$date");
            exit;
        } else {
            $error = 'Failed to log task. Please try again.';
        }
    }
}

// Get active categories
$categories = $db->query("SELECT * FROM generic_task_categories WHERE is_active = 1 ORDER BY name")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card mt-4">
                <div class="card-header">
                    <h4>Log Generic Task</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <div class="mb-3">
                            <label class="form-label">Task Date *</label>
                            <input type="date" name="task_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Category *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">Select a Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Details about the task..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                               <label class="form-label">Hours Spent</label>
                               <input type="number" name="hours_spent" class="form-control" step="0.01" min="0" placeholder="Optional">
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Task</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 