<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();

// Handle create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_env'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: environments.php');
        exit;
    }
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $browser = trim($_POST['browser'] ?: null);
    $at = trim($_POST['assistive_tech'] ?: null);
    if ($name) {
        $stmt = $db->prepare("INSERT INTO testing_environments (name, type, browser, assistive_tech) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $type, $browser, $at]);
        $_SESSION['success'] = 'Environment created';
    }
    header('Location: environments.php'); exit;
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->prepare("DELETE FROM testing_environments WHERE id = ?")->execute([$id]);
    $_SESSION['success'] = 'Environment deleted';
    header('Location: environments.php'); exit;
}

// Handle edit save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_env'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: environments.php');
        exit;
    }
    $id = (int)($_POST['env_id'] ?? 0);
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $browser = trim($_POST['browser'] ?: null);
    $at = trim($_POST['assistive_tech'] ?: null);
    if ($id && $name) {
        $stmt = $db->prepare("UPDATE testing_environments SET name = ?, type = ?, browser = ?, assistive_tech = ? WHERE id = ?");
        $stmt->execute([$name, $type, $browser, $at, $id]);
        $_SESSION['success'] = 'Environment updated';
    }
    header('Location: environments.php'); exit;
}

// If editing, load the env
$editing = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editing = $db->prepare("SELECT * FROM testing_environments WHERE id = ?");
    $editing->execute([$editId]);
    $editing = $editing->fetch(PDO::FETCH_ASSOC);
}

$envs = $db->query("SELECT * FROM testing_environments ORDER BY name")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid">
    <h2>Manage Testing Environments</h2>
    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header">Create Environment</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input name="name" class="form-control" required value="<?php echo $editing ? htmlspecialchars($editing['name']) : ''; ?>" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="web" <?php echo ($editing && $editing['type'] === 'web') ? 'selected' : ''; ?>>Web</option>
                                <option value="app" <?php echo ($editing && $editing['type'] === 'app') ? 'selected' : ''; ?>>App</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Browser</label>
                            <input name="browser" class="form-control" value="<?php echo $editing ? htmlspecialchars($editing['browser']) : ''; ?>" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assistive Tech</label>
                            <input name="assistive_tech" class="form-control" value="<?php echo $editing ? htmlspecialchars($editing['assistive_tech']) : ''; ?>" />
                        </div>
                        <?php if ($editing): ?>
                            <input type="hidden" name="env_id" value="<?php echo (int)$editing['id']; ?>" />
                            <button type="submit" name="update_env" class="btn btn-success">Save Changes</button>
                            <a href="environments.php" class="btn btn-secondary">Cancel</a>
                        <?php else: ?>
                            <button type="submit" name="create_env" class="btn btn-primary">Create</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Existing Environments</div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead><tr><th>Name</th><th>Type</th><th>Browser</th><th>AT</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($envs as $e): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($e['name']); ?></td>
                                <td><?php echo htmlspecialchars($e['type']); ?></td>
                                <td><?php echo htmlspecialchars($e['browser']); ?></td>
                                <td><?php echo htmlspecialchars($e['assistive_tech']); ?></td>
                                <td>
                                    <a href="environments.php?edit=<?php echo $e['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="environments.php?delete=<?php echo $e['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="confirmModal('Delete environment?', function(){ window.location.href='environments.php?delete=<?php echo $e['id']; ?>'; }); return false;">Delete</a>
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
<?php include __DIR__ . '/../../includes/footer.php'; 