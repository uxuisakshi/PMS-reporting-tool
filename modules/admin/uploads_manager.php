<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$db = Database::getInstance();
$baseDir = getBaseDir();

function formatBytesAdminUpload(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, 2) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return number_format($value, 2) . ' TB';
}

function buildUploadRoots(): array {
    $roots = [];
    $uploadsPath = __DIR__ . '/../../uploads';
    $assetsUploadsPath = __DIR__ . '/../../assets/uploads';

    if (is_dir($uploadsPath)) {
        $resolved = realpath($uploadsPath);
        if ($resolved !== false) {
            $roots['uploads'] = $resolved;
        }
    }
    if (is_dir($assetsUploadsPath)) {
        $resolved = realpath($assetsUploadsPath);
        if ($resolved !== false) {
            $roots['assets_uploads'] = $resolved;
        }
    }
    return $roots;
}

function isWithinRoot(string $root, string $candidate): bool {
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $candidateNorm = str_replace('\\', '/', $candidate);
    return strpos($candidateNorm, $rootNorm) === 0;
}

$roots = buildUploadRoots();

// AJAX: preview cleanup count before actual delete
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'preview_cleanup') {
    header('Content-Type: application/json');
    $scopeType = trim((string)($_GET['scope_type'] ?? ''));
    $projectId = (int)($_GET['project_id'] ?? 0);
    $userId    = (int)($_GET['user_id'] ?? 0);

    $where  = ["asset_type = 'file'", "file_path IS NOT NULL", "file_path LIKE 'assets/uploads/%'"];
    $params = [];
    $label  = '';

    if ($scopeType === 'project' && $projectId > 0) {
        $where[]  = 'project_id = ?';
        $params[] = $projectId;
        $proj = $db->prepare("SELECT title FROM projects WHERE id = ? LIMIT 1");
        $proj->execute([$projectId]);
        $label = 'Project: ' . ($proj->fetchColumn() ?: '#' . $projectId);
    } elseif ($scopeType === 'user' && $userId > 0) {
        $where[]  = 'created_by = ?';
        $params[] = $userId;
        $usr = $db->prepare("SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
        $usr->execute([$userId]);
        $urow = $usr->fetch(PDO::FETCH_ASSOC);
        $label = $urow ? $urow['full_name'] . ' (' . $urow['email'] . ')' : '#' . $userId;
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid scope or missing selection.']);
        exit;
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM project_assets WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $count = (int)$countStmt->fetchColumn();

    echo json_encode(['success' => true, 'count' => $count, 'label' => $label, 'scope_type' => $scopeType]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection for all state-changing POST actions
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $_SESSION['error'] = 'Invalid CSRF token.';
        header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
        exit;
    }

    if (isset($_POST['cleanup_action']) && $_POST['cleanup_action'] === 'purge_project_assets_scope') {
        $scopeType = trim((string)($_POST['scope_type'] ?? ''));
        $projectId = (int)($_POST['project_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);

        $where = ["asset_type = 'file'", "file_path IS NOT NULL", "file_path LIKE 'assets/uploads/%'"];
        $params = [];
        if ($scopeType === 'project') {
            if ($projectId <= 0) {
                $_SESSION['error'] = 'Please select a valid project.';
                header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
                exit;
            }
            $where[] = 'project_id = ?';
            $params[] = $projectId;
        } elseif ($scopeType === 'user') {
            if ($userId <= 0) {
                $_SESSION['error'] = 'Please select a valid user.';
                header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
                exit;
            }
            $where[] = 'created_by = ?';
            $params[] = $userId;
        } else {
            $_SESSION['error'] = 'Invalid cleanup scope.';
            header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
            exit;
        }

        $selSql = 'SELECT id, file_path FROM project_assets WHERE ' . implode(' AND ', $where);
        $selStmt = $db->prepare($selSql);
        $selStmt->execute($params);
        $assets = $selStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($assets)) {
            $_SESSION['error'] = 'No matching project asset uploads found for selected scope.';
            header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
            exit;
        }

        $assetsRoot = $roots['assets_uploads'] ?? realpath(__DIR__ . '/../../assets/uploads');
        $removedFiles = 0;
        $missingFiles = 0;
        $assetIds = [];
        foreach ($assets as $asset) {
            $assetIds[] = (int)$asset['id'];
            $fp = str_replace('\\', '/', (string)($asset['file_path'] ?? ''));
            if (strpos($fp, 'assets/uploads/') !== 0 || !$assetsRoot) {
                continue;
            }
            $relative = substr($fp, strlen('assets/uploads/'));
            $full = $assetsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = realpath($full);
            if ($real !== false && is_file($real) && isWithinRoot($assetsRoot, $real)) {
                if (@unlink($real)) {
                    $removedFiles++;
                }
            } else {
                $missingFiles++;
            }
        }

        if (!empty($assetIds)) {
            $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
            $delStmt = $db->prepare("DELETE FROM project_assets WHERE id IN ($placeholders)");
            $delStmt->execute($assetIds);
            $deletedRows = (int)$delStmt->rowCount();
        } else {
            $deletedRows = 0;
        }

        $_SESSION['success'] = $deletedRows . ' project asset upload record(s) deleted. Physical files removed: ' . $removedFiles . '. Missing files skipped: ' . $missingFiles . '.';
        try {
            logActivity($db, (int)$_SESSION['user_id'], 'admin_purge_uploads_by_scope', 'project_assets', 0, [
                'scope_type' => $scopeType,
                'project_id' => $projectId > 0 ? $projectId : null,
                'user_id' => $userId > 0 ? $userId : null,
                'deleted_rows' => $deletedRows,
                'removed_files' => $removedFiles
            ]);
        } catch (Throwable $_) {
            // non-fatal
        }
        header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
        exit;
    }

    if (isset($_POST['bulk_delete']) && $_POST['bulk_delete'] === '1') {
        $selectedFiles = $_POST['selected_files'] ?? [];
        if (!is_array($selectedFiles) || empty($selectedFiles)) {
            $_SESSION['error'] = 'No files selected for deletion.';
            header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
            exit;
        }

        $redirectQuery = (string)($_POST['redirect_query'] ?? '');
        $deletedCount  = 0;
        $failedCount   = 0;

        foreach ($selectedFiles as $encoded) {
            // Each entry is "storageKey::relativePath" base64-encoded
            $decoded = base64_decode((string)$encoded, true);
            if ($decoded === false || strpos($decoded, '::') === false) {
                $failedCount++;
                continue;
            }
            [$storageKey, $relativePath] = explode('::', $decoded, 2);
            $storageKey   = trim($storageKey);
            $relativePath = trim($relativePath);

            if (!isset($roots[$storageKey]) || $relativePath === '' || strpos($relativePath, "\0") !== false) {
                $failedCount++;
                continue;
            }

            $rootPath   = $roots[$storageKey];
            $targetPath = realpath($rootPath . DIRECTORY_SEPARATOR . $relativePath);

            if ($targetPath === false || !is_file($targetPath) || !isWithinRoot($rootPath, $targetPath)) {
                $failedCount++;
                continue;
            }

            $relativeUnix = ltrim(str_replace('\\', '/', $relativePath), '/');
            if (@unlink($targetPath)) {
                $deletedCount++;
                if ($storageKey === 'assets_uploads') {
                    $dbPath = 'assets/uploads/' . $relativeUnix;
                    $db->prepare('DELETE FROM project_assets WHERE file_path = ?')->execute([$dbPath]);
                }
            } else {
                $failedCount++;
            }
        }

        try {
            logActivity($db, (int)$_SESSION['user_id'], 'admin_bulk_delete_uploads', 'file', 0, [
                'deleted' => $deletedCount,
                'failed'  => $failedCount,
            ]);
        } catch (Throwable $_) {}

        $_SESSION['success'] = $deletedCount . ' file(s) deleted successfully.' . ($failedCount > 0 ? ' ' . $failedCount . ' failed.' : '');
        $target = $baseDir . '/modules/admin/uploads_manager.php';
        if ($redirectQuery !== '') {
            $redirectQuery = preg_replace('/[\r\n]/', '', $redirectQuery);
            $target .= '?' . ltrim($redirectQuery, '?');
        }
        header('Location: ' . $target);
        exit;
    }

    if (isset($_POST['delete_upload']) && $_POST['delete_upload'] === '1') {
        $storageKey = trim((string)($_POST['storage_key'] ?? ''));
        $relativePath = trim((string)($_POST['relative_path'] ?? ''));
        $redirectQuery = (string)($_POST['redirect_query'] ?? '');

        if (!isset($roots[$storageKey])) {
            $_SESSION['error'] = 'Invalid storage location.';
        } elseif ($relativePath === '' || strpos($relativePath, "\0") !== false) {
            $_SESSION['error'] = 'Invalid file path.';
        } else {
            $rootPath = $roots[$storageKey];
            $targetPath = realpath($rootPath . DIRECTORY_SEPARATOR . $relativePath);

            if ($targetPath === false || !is_file($targetPath) || !isWithinRoot($rootPath, $targetPath)) {
                $_SESSION['error'] = 'File not found or path not allowed.';
            } else {
                $relativeUnix = ltrim(str_replace('\\', '/', $relativePath), '/');
                $deleted = @unlink($targetPath);
                if (!$deleted) {
                    $_SESSION['error'] = 'Unable to delete file.';
                } else {
                    $cleanupNotes = [];
                    if ($storageKey === 'assets_uploads') {
                        $dbPath = 'assets/uploads/' . $relativeUnix;
                        $stmt = $db->prepare('DELETE FROM project_assets WHERE file_path = ?');
                        $stmt->execute([$dbPath]);
                        if ($stmt->rowCount() > 0) {
                            $cleanupNotes[] = 'removed ' . $stmt->rowCount() . ' project asset record(s)';
                        }
                    }

                    if ($storageKey === 'uploads') {
                        $dbPath = 'uploads/' . $relativeUnix;
                    }

                    $msg = 'File deleted successfully.';
                    if (!empty($cleanupNotes)) {
                        $msg .= ' Also ' . implode('; ', $cleanupNotes) . '.';
                    }
                    $_SESSION['success'] = $msg;
                    try {
                        logActivity($db, (int)$_SESSION['user_id'], 'admin_delete_upload', 'file', 0, [
                            'storage_key' => $storageKey,
                            'relative_path' => $relativeUnix
                        ]);
                    } catch (Throwable $_) {
                        // Ignore logging failures.
                    }
                }
            }
        }

        $target = $baseDir . '/modules/admin/uploads_manager.php';
        if ($redirectQuery !== '') {
            // Sanitize redirectQuery - strip CRLF to prevent header injection
            $redirectQuery = preg_replace('/[\r\n]/', '', $redirectQuery);
            $target .= '?' . ltrim($redirectQuery, '?');
        }
        header('Location: ' . $target);
        exit;
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$locationFilter = trim((string)($_GET['location'] ?? 'all'));
$sort = trim((string)($_GET['sort'] ?? 'newest'));
$filterProject = (int)($_GET['filter_project'] ?? 0);
$filterUser    = (int)($_GET['filter_user'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 50);
if ($perPage <= 0 || $perPage > 500) {
    $perPage = 50;
}

$projects = $db->query("SELECT id, title FROM projects ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query("SELECT id, full_name, email FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$files = [];
$totalSize = 0;
foreach ($roots as $storageKey => $rootPath) {
    if ($locationFilter !== 'all' && $locationFilter !== $storageKey) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $name = $item->getFilename();
        // Skip hidden/system files like .htaccess from admin file listing.
        if ($name === '' || $name[0] === '.') {
            continue;
        }
        $fullPath = $item->getPathname();
        $relativePath = ltrim(str_replace('\\', '/', substr($fullPath, strlen($rootPath))), '/');
        $searchHaystack = strtolower($storageKey . '/' . $relativePath);
        if ($q !== '' && strpos($searchHaystack, strtolower($q)) === false) {
            continue;
        }
        $size = (int)$item->getSize();
        $mtime = (int)$item->getMTime();
        $rawPath = ($storageKey === 'uploads' ? 'uploads/' : 'assets/uploads/') . str_replace('\\', '/', $relativePath);
        $urlPath = $baseDir . '/api/secure_file.php?path=' . rawurlencode($rawPath);

        $files[] = [
            'storage_key'   => $storageKey,
            'storage_label' => $storageKey === 'uploads' ? 'uploads/' : 'assets/uploads/',
            'relative_path' => $relativePath,
            'name'          => $item->getFilename(),
            'extension'     => strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION)),
            'size'          => $size,
            'mtime'         => $mtime,
            'mtime_display' => date('Y-m-d H:i:s', $mtime),
            'url'           => $urlPath,
            'raw_path'      => $rawPath,
        ];
        $totalSize += $size;
    }
}

// Build full asset meta map for ALL scanned files (needed for project/user filter)
$allAssetPaths = [];
foreach ($files as $f) {
    if ($f['storage_key'] === 'assets_uploads') {
        $allAssetPaths[] = $f['raw_path'];
    }
}
$fullMetaMap = [];
if (!empty($allAssetPaths)) {
    $allAssetPaths = array_values(array_unique($allAssetPaths));
    $ph = implode(',', array_fill(0, count($allAssetPaths), '?'));
    $metaStmt = $db->prepare("
        SELECT pa.file_path, pa.project_id, pa.created_by,
               p.title AS project_title, u.full_name AS uploader_name
        FROM project_assets pa
        LEFT JOIN projects p ON p.id = pa.project_id
        LEFT JOIN users u ON u.id = pa.created_by
        WHERE pa.file_path IN ($ph)
    ");
    $metaStmt->execute($allAssetPaths);
    foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $fullMetaMap[(string)$m['file_path']] = $m;
    }
}

// Apply project/user filter using meta map
if ($filterProject > 0 || $filterUser > 0) {
    $files = array_values(array_filter($files, function ($f) use ($filterProject, $filterUser, $fullMetaMap) {
        $meta = $fullMetaMap[$f['raw_path']] ?? null;
        if ($meta === null) {
            // File not in project_assets — cannot match project/user filter
            return false;
        }
        if ($filterProject > 0 && (int)($meta['project_id'] ?? 0) !== $filterProject) {
            return false;
        }
        if ($filterUser > 0 && (int)($meta['created_by'] ?? 0) !== $filterUser) {
            return false;
        }
        return true;
    }));
    // Recalculate total size after filter
    $totalSize = array_sum(array_column($files, 'size'));
}

usort($files, function (array $a, array $b) use ($sort): int {
    if ($sort === 'oldest') {
        return $a['mtime'] <=> $b['mtime'];
    }
    if ($sort === 'largest') {
        return $b['size'] <=> $a['size'];
    }
    if ($sort === 'smallest') {
        return $a['size'] <=> $b['size'];
    }
    if ($sort === 'name_asc') {
        return strcasecmp($a['name'], $b['name']);
    }
    if ($sort === 'name_desc') {
        return strcasecmp($b['name'], $a['name']);
    }
    return $b['mtime'] <=> $a['mtime'];
});

$totalFiles = count($files);
$totalPages = max(1, (int)ceil($totalFiles / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$rows = array_slice($files, $offset, $perPage);

$assetMetaMap = $fullMetaMap;

$queryForForms = $_GET;
unset($queryForForms['page']);
$redirectQuery = http_build_query($queryForForms);

$pageTitle = 'Uploads Manager';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-1">Uploads Manager</h3>
            <p class="text-muted mb-0">Direct admin access to all uploaded files. You can review and delete files to reduce storage.</p>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2">
                    <div class="small text-muted">Total Files</div>
                    <div class="h5 mb-0"><?php echo (int)$totalFiles; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2">
                    <div class="small text-muted">Estimated Size</div>
                    <div class="h5 mb-0"><?php echo htmlspecialchars(formatBytesAdminUpload($totalSize)); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2">
                    <div class="small text-muted">Scanned Locations</div>
                    <div class="h6 mb-0"><?php echo htmlspecialchars(implode(', ', array_keys($roots)) ?: 'None'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3 border-warning">
        <div class="card-body py-3">
            <div class="fw-semibold mb-2">Project/User Wise Upload Cleanup</div>
            <div class="small text-muted mb-2">This cleanup targets mapped uploads from <code>project_assets.file_path</code> only.</div>
            <form method="post" id="cleanupForm" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="cleanup_action" value="purge_project_assets_scope">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Scope</label>
                    <select name="scope_type" id="uploadScopeType" class="form-select form-select-sm">
                        <option value="project">Project Wise</option>
                        <option value="user">User Wise</option>
                    </select>
                </div>
                <div class="col-md-4" id="uploadScopeProjectWrap">
                    <label class="form-label form-label-sm">Project</label>
                    <select name="project_id" class="form-select form-select-sm">
                        <option value="">Select project</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-none" id="uploadScopeUserWrap">
                    <label class="form-label form-label-sm">User</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">Select user</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)$u['full_name'] . ' (' . (string)$u['email'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-danger">Delete Matching Uploads</button>
                </div>
            </form>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-3">
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search by file name or path">
        </div>
        <div class="col-md-2">
            <select name="location" class="form-select form-select-sm">
                <option value="all"<?php if ($locationFilter === 'all') echo ' selected'; ?>>All Locations</option>
                <option value="uploads"<?php if ($locationFilter === 'uploads') echo ' selected'; ?>>uploads/</option>
                <option value="assets_uploads"<?php if ($locationFilter === 'assets_uploads') echo ' selected'; ?>>assets/uploads/</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="filter_project" class="form-select form-select-sm">
                <option value="">All Projects</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>"<?php if ($filterProject === (int)$p['id']) echo ' selected'; ?>>
                        <?php echo htmlspecialchars((string)$p['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="filter_user" class="form-select form-select-sm">
                <option value="">All Users</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>"<?php if ($filterUser === (int)$u['id']) echo ' selected'; ?>>
                        <?php echo htmlspecialchars((string)$u['full_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <select name="sort" class="form-select form-select-sm">
                <option value="newest"<?php if ($sort === 'newest') echo ' selected'; ?>>Newest</option>
                <option value="oldest"<?php if ($sort === 'oldest') echo ' selected'; ?>>Oldest</option>
                <option value="largest"<?php if ($sort === 'largest') echo ' selected'; ?>>Largest</option>
                <option value="smallest"<?php if ($sort === 'smallest') echo ' selected'; ?>>Smallest</option>
                <option value="name_asc"<?php if ($sort === 'name_asc') echo ' selected'; ?>>Name A-Z</option>
                <option value="name_desc"<?php if ($sort === 'name_desc') echo ' selected'; ?>>Name Z-A</option>
            </select>
        </div>
        <div class="col-md-1">
            <select name="per_page" class="form-select form-select-sm">
                <?php foreach ([25, 50, 100, 200] as $pp): ?>
                    <option value="<?php echo $pp; ?>"<?php if ($perPage === $pp) echo ' selected'; ?>><?php echo $pp; ?>/page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1 d-flex gap-1">
            <button class="btn btn-sm btn-primary flex-grow-1">Apply</button>
            <?php if ($q || $locationFilter !== 'all' || $filterProject || $filterUser): ?>
                <a href="<?php echo htmlspecialchars($baseDir . '/modules/admin/uploads_manager.php'); ?>" class="btn btn-sm btn-outline-secondary" title="Reset filters"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($filterProject > 0 || $filterUser > 0): ?>
    <div class="alert alert-info py-2 mb-3">
        <i class="fas fa-filter me-1"></i>
        Showing uploads filtered by:
        <?php if ($filterProject > 0):
            $fp = array_filter($projects, fn($p) => (int)$p['id'] === $filterProject);
            $fpTitle = $fp ? reset($fp)['title'] : '#' . $filterProject;
        ?>
            <strong>Project:</strong> <?php echo htmlspecialchars($fpTitle); ?>
        <?php endif; ?>
        <?php if ($filterUser > 0):
            $fu = array_filter($users, fn($u) => (int)$u['id'] === $filterUser);
            $fuName = $fu ? reset($fu)['full_name'] : '#' . $filterUser;
        ?>
            <?php if ($filterProject > 0) echo ' &amp; '; ?>
            <strong>User:</strong> <?php echo htmlspecialchars($fuName); ?>
        <?php endif; ?>
        &mdash; <strong><?php echo number_format($totalFiles); ?></strong> file<?php echo $totalFiles !== 1 ? 's' : ''; ?> found
        (<?php echo htmlspecialchars(formatBytesAdminUpload($totalSize)); ?>)
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <!-- Bulk action bar (hidden until selection) -->
        <div id="umBulkBar" class="d-none alert alert-warning py-2 mb-2 d-flex align-items-center gap-3">
            <span id="umBulkCount" class="fw-semibold"></span>
            <button type="button" class="btn btn-sm btn-danger" id="umBulkDeleteBtn">
                <i class="fas fa-trash me-1"></i> Delete Selected
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="umBulkClearBtn">
                Clear Selection
            </button>
        </div>

        <!-- Hidden bulk delete form -->
        <form method="post" id="umBulkForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="bulk_delete" value="1">
            <input type="hidden" name="redirect_query" value="<?php echo htmlspecialchars($redirectQuery); ?>">
            <div id="umBulkInputs"></div>
        </form>

        <table class="table table-striped table-sm align-middle" id="umTable">
            <thead>
                <tr>
                    <th style="width:36px;">
                        <input type="checkbox" id="umSelectAll" class="form-check-input" title="Select all on this page">
                    </th>
                    <th>File</th>
                    <th>Location</th>
                    <th>Project</th>
                    <th>Uploader</th>
                    <th>Relative Path</th>
                    <th>Size</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-muted">No files found for current filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $metaKey  = $r['storage_key'] === 'assets_uploads' ? ('assets/uploads/' . $r['relative_path']) : '';
                        $meta     = $metaKey !== '' && isset($assetMetaMap[$metaKey]) ? $assetMetaMap[$metaKey] : null;
                        $fileToken = base64_encode($r['storage_key'] . '::' . $r['relative_path']);
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input um-row-check"
                                       value="<?php echo htmlspecialchars($fileToken, ENT_QUOTES, 'UTF-8'); ?>"
                                       data-name="<?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($r['extension'] ?: 'no-extension'); ?></div>
                            </td>
                            <td><code><?php echo htmlspecialchars($r['storage_label']); ?></code></td>
                            <td><?php echo htmlspecialchars((string)($meta['project_title'] ?? '-')); ?></td>
                            <td><?php echo htmlspecialchars((string)($meta['uploader_name'] ?? '-')); ?></td>
                            <td><code><?php echo htmlspecialchars($r['relative_path']); ?></code></td>
                            <td><?php echo htmlspecialchars(formatBytesAdminUpload((int)$r['size'])); ?></td>
                            <td><?php echo htmlspecialchars($r['mtime_display']); ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="<?php echo htmlspecialchars($r['url']); ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">Open</a>
                                    <form method="post" class="d-inline um-delete-form" data-filename="<?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="delete_upload" value="1">
                                        <input type="hidden" name="storage_key" value="<?php echo htmlspecialchars($r['storage_key']); ?>">
                                        <input type="hidden" name="relative_path" value="<?php echo htmlspecialchars($r['relative_path']); ?>">
                                        <input type="hidden" name="redirect_query" value="<?php echo htmlspecialchars($redirectQuery); ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <nav aria-label="Uploads pages">
        <ul class="pagination pagination-sm flex-wrap">
            <?php
            $qs = $_GET;
            unset($qs['page']);
            $baseQs = http_build_query($qs);
            $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');

            $buildHref = function(int $p) use ($baseUrl, $baseQs): string {
                return $baseUrl . '?' . ($baseQs !== '' ? $baseQs . '&' : '') . 'page=' . $p;
            };

            // Prev button
            if ($page > 1) {
                echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildHref($page - 1)) . '">&laquo;</a></li>';
            } else {
                echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
            }

            // Smart page numbers with ellipsis
            $pagesToShow = [];
            if ($totalPages <= 9) {
                // Show all if 9 or fewer
                for ($i = 1; $i <= $totalPages; $i++) $pagesToShow[] = $i;
            } else {
                $pagesToShow[] = 1;
                if ($page > 4) $pagesToShow[] = '...';
                for ($i = max(2, $page - 2); $i <= min($totalPages - 1, $page + 2); $i++) {
                    $pagesToShow[] = $i;
                }
                if ($page < $totalPages - 3) $pagesToShow[] = '...';
                $pagesToShow[] = $totalPages;
            }

            foreach ($pagesToShow as $p) {
                if ($p === '...') {
                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                } else {
                    $activeClass = $p === $page ? ' active' : '';
                    echo '<li class="page-item' . $activeClass . '"><a class="page-link" href="' . htmlspecialchars($buildHref((int)$p)) . '">' . $p . '</a></li>';
                }
            }

            // Next button
            if ($page < $totalPages) {
                echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildHref($page + 1)) . '">&raquo;</a></li>';
            } else {
                echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
            }
            ?>
        </ul>
    </nav>
    <div class="text-muted small mt-1">
        Page <?php echo $page; ?> of <?php echo $totalPages; ?> &mdash;
        <?php echo number_format($totalFiles); ?> file<?php echo $totalFiles !== 1 ? 's' : ''; ?> total
    </div>
</div>

<script src="<?php echo getBaseDir(); ?>/assets/js/uploads-manager.js?v=<?php echo time(); ?>"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; 