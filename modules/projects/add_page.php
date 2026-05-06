<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa']);

$projectId = isset($_GET['project_id']) && is_numeric($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$db = Database::getInstance();

// Ensure necessary columns exist in project_pages table (adds them if missing).
function ensure_project_pages_columns(PDO $db)
{
    $cols = [
        'created_by' => "ADD COLUMN created_by INT NULL",
        'at_tester_ids' => "ADD COLUMN at_tester_ids JSON NULL",
        'ft_tester_ids' => "ADD COLUMN ft_tester_ids JSON NULL",
    ];

    foreach ($cols as $col => $alter) {
        $check = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_pages' AND COLUMN_NAME = ?");
        $check->execute([$col]);
        $exists = (int)$check->fetchColumn();
        if ($exists === 0) {
            try {
                $db->exec("ALTER TABLE project_pages $alter");
            } catch (Exception $e) {
                // ignore failures — will surface on insert if truly incompatible
            }
        }
    }
}

ensure_project_pages_columns($db);

// If a project was provided, validate access and get title. Otherwise load projects for optional selection.
$projectTitle = null;
if ($projectId) {
    $accessCheck = $db->prepare("SELECT title FROM projects WHERE id = ?");
    $accessCheck->execute([$projectId]);
    $projectTitle = $accessCheck->fetchColumn();
    if (!$projectTitle) {
        $projectId = null; // treat as not provided
    }
}

// Load projects for optional selection in the form
$projects = $db->query("SELECT id, title FROM projects ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);

// Fetch testers and environments for the form
$atTesters = $db->query("SELECT id, full_name FROM users WHERE role IN ('at_tester', 'project_lead')")->fetchAll(PDO::FETCH_ASSOC);
$ftTesters = $db->query("SELECT id, full_name FROM users WHERE role IN ('ft_tester', 'project_lead')")->fetchAll(PDO::FETCH_ASSOC);
$environments = $db->query("SELECT id, name FROM testing_environments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: add_page.php' . ($projectId ? '?project_id=' . $projectId : ''));
        exit;
    }
    // Determine selected project for insertion (POST overrides GET)
    $selectedProjectId = null;
    if (!empty($_POST['project_id']) && is_numeric($_POST['project_id'])) {
        $selectedProjectId = (int)$_POST['project_id'];
    } elseif ($projectId) {
        $selectedProjectId = $projectId;
    }

    // If a bulk file is uploaded, process CSV bulk import
    if (!empty($_FILES['bulk_file']['tmp_name'])) {
        $file = $_FILES['bulk_file']['tmp_name'];
        if (($handle = fopen($file, 'r')) !== false) {
            $db->beginTransaction();
            $header = fgetcsv($handle);
            // Expect header names: page_name,url,screen_name,at_testers,ft_testers,environments,status
            while (($row = fgetcsv($handle)) !== false) {
                if (count($header) !== count($row)) {
                    continue; // Skip malformed rows
                }
                $data = array_combine($header, $row);
                $pageName = trim($data['page_name'] ?? '');
                if (empty($pageName)) continue;
                // allow optional project_id per CSV row
                $rowProjectId = null;
                if (!empty($data['project_id']) && is_numeric($data['project_id'])) {
                    $rowProjectId = (int)$data['project_id'];
                } else {
                    $rowProjectId = $selectedProjectId;
                }
                $url = trim($data['url'] ?? '');
                $screenName = trim($data['screen_name'] ?? '');
                $status = $data['status'] ?? 'pending';

                $atIds = [];
                if (!empty($data['at_testers'])) {
                    $atIds = array_filter(array_map('trim', explode(',', $data['at_testers'])));
                }
                $ftIds = [];
                if (!empty($data['ft_testers'])) {
                    $ftIds = array_filter(array_map('trim', explode(',', $data['ft_testers'])));
                }

                $stmt = $db->prepare("INSERT INTO project_pages (project_id, page_name, url, screen_name, status, created_by, at_tester_ids, ft_tester_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $rowProjectId,
                    $pageName,
                    $url,
                    $screenName,
                    $status,
                    $_SESSION['user_id'],
                    empty($atIds) ? null : json_encode(array_values($atIds)),
                    empty($ftIds) ? null : json_encode(array_values($ftIds))
                ]);
                $pageId = $db->lastInsertId();

                if (!empty($data['environments'])) {
                    $envs = array_filter(array_map('trim', explode(',', $data['environments'])));
                    $peStmt = $db->prepare("INSERT INTO page_environments (page_id, environment_id) VALUES (?, ?)");
                    foreach ($envs as $envId) {
                        if (is_numeric($envId)) $peStmt->execute([$pageId, $envId]);
                    }
                }
            }
            fclose($handle);
            $db->commit();
            $_SESSION['success'] = "Bulk pages imported successfully.";
            header("Location: view.php?id=$projectId");
            exit;
        } else {
            $error = "Unable to read uploaded file.";
        }
    }

    // Single page add
    $pageName = trim($_POST['page_name'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $screenName = trim($_POST['screen_name'] ?? '');
    $status = $_POST['status'] ?? 'pending';
    $atSelected = $_POST['at_testers'] ?? [];
    $ftSelected = $_POST['ft_testers'] ?? [];
    $envSelected = $_POST['environments'] ?? [];

    if (empty($pageName)) {
        $error = "Page Name is required.";
    } else {
        $stmt = $db->prepare("INSERT INTO project_pages (project_id, page_name, url, screen_name, status, created_by, at_tester_ids, ft_tester_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $selectedProjectId,
            $pageName,
            $url,
            $screenName,
            $status,
            $_SESSION['user_id'],
            empty($atSelected) ? null : json_encode(array_values($atSelected)),
            empty($ftSelected) ? null : json_encode(array_values($ftSelected))
        ]);

        $pageId = $db->lastInsertId();
        if (!empty($envSelected)) {
            $peStmt = $db->prepare("INSERT INTO page_environments (page_id, environment_id) VALUES (?, ?)");
            foreach ($envSelected as $envId) {
                if (is_numeric($envId)) $peStmt->execute([$pageId, $envId]);
            }
        }

        // Log activity
        logActivity($db, $_SESSION['user_id'], 'added_page', 'project', $selectedProjectId, [
            'page_id' => $pageId,
            'page_name' => $pageName,
            'url' => $url,
            'screen_name' => $screenName,
            'status' => $status,
            'environments_count' => count($envSelected),
            'at_testers_count' => count($atSelected),
            'ft_testers_count' => count($ftSelected)
        ]);

        $_SESSION['success'] = "Page added successfully!";
        header("Location: view.php?id=$projectId");
        exit;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card mt-4">
                <div class="card-header">
                    <h4><?php echo 'Add Page' . ($projectTitle ? ' to: ' . htmlspecialchars($projectTitle) : ''); ?></h4>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <?php if (empty($projectId)): ?>
                            <div class="mb-3">
                                <label class="form-label">Project (optional)</label>
                                <select name="project_id" class="form-select">
                                    <option value="">No project (optional)</option>
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Bulk upload (CSV)</label>
                            <input type="file" name="bulk_file" class="form-control">
                            <div class="form-text">Optional: upload CSV with header: page_name,url,screen_name,project_id,at_testers,ft_testers,environments,status — tester/env IDs comma-separated.</div>
                        </div>

                        <hr />

                        <div class="mb-3">
                            <label class="form-label">Page / Screen Name *</label>
                            <input type="text" name="page_name" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Screen Name (Optional)</label>
                            <input type="text" name="screen_name" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL / Screen ID (Optional)</label>
                            <input type="text" name="url" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign AT Testers</label>
                            <select name="at_testers[]" class="form-select" multiple>
                                <?php foreach ($atTesters as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign FT Testers</label>
                            <select name="ft_testers[]" class="form-select" multiple>
                                <?php foreach ($ftTesters as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Environments</label>
                            <select name="environments[]" class="form-select" multiple>
                                <?php foreach ($environments as $e): ?>
                                    <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="pending">pending</option>
                                <option value="not_started">not_started</option>
                                <option value="in_progress">in_progress</option>
                                <option value="on_hold">on_hold</option>
                                <option value="qa_in_progress">qa_in_progress</option>
                                <option value="in_fixing">in_fixing</option>
                                <option value="needs_review">needs_review</option>
                            </select>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="<?php echo $projectId ? 'view.php?id=' . $projectId : 'list.php'; ?>" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Add Page</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 