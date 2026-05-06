<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'admin']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Allowed file extensions and their MIME types
// SVG intentionally excluded — SVG files can contain embedded scripts (XSS risk)
const ALLOWED_ASSET_TYPES = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xls'  => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'ppt'  => ['application/vnd.ms-powerpoint'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'txt'  => ['text/plain'],
    'csv'  => ['text/csv', 'text/plain', 'application/csv'],
    'png'  => ['image/png'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'zip'  => ['application/zip', 'application/x-zip-compressed'],
    'mp4'  => ['video/mp4'],
    'mp3'  => ['audio/mpeg'],
];

function validateAssetUpload(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'File upload error.'];
    }
    if ($file['size'] > 50 * 1024 * 1024) { // 50MB limit
        return ['ok' => false, 'error' => 'File too large. Maximum size is 50MB.'];
    }

    $origName = $file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!array_key_exists($ext, ALLOWED_ASSET_TYPES)) {
        return ['ok' => false, 'error' => 'File type not allowed. Allowed: ' . implode(', ', array_keys(ALLOWED_ASSET_TYPES))];
    }

    // MIME check via finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($file['tmp_name']);
    $allowedMimes = ALLOWED_ASSET_TYPES[$ext];

    if (!in_array($detectedMime, $allowedMimes, true)) {
        return ['ok' => false, 'error' => 'File content does not match its extension.'];
    }

    return ['ok' => true, 'ext' => $ext];
}

/**
 * @return string|false
 */
function saveAssetFile(array $file, string $ext) {
    $uploadDir = __DIR__ . '/../../assets/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0750, true);
    }
    // Sanitize filename - keep only safe chars
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
    $fileName = bin2hex(random_bytes(8)) . '_' . $safeName;
    $targetPath = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'assets/uploads/' . $fileName;
    }
    return false;
}

function canManageProjectAsset($db, $userId, $userRole, $projectId, $assetId, $permissionType = 'assets_edit') {
    if (in_array($userRole, ['admin'], true)) {
        return true;
    }
    $assetStmt = $db->prepare("SELECT created_by FROM project_assets WHERE id = ? AND project_id = ? LIMIT 1");
    $assetStmt->execute([$assetId, $projectId]);
    $assetCreatorId = (int)($assetStmt->fetchColumn() ?? 0);
    return $assetCreatorId === (int)$userId;
}

// CSRF check for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid request token.']);
        } else {
            $pid = (int)($_POST['project_id'] ?? 0);
            $_SESSION['error'] = 'Invalid request. Please try again.';
            header("Location: view.php?id=$pid#assets");
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_asset'])) {
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $assetName = sanitizeInput($_POST['asset_name']);
    $assetType = in_array($_POST['asset_type'] ?? '', ['link', 'file', 'text']) ? $_POST['asset_type'] : '';

    if (!$projectId || !$assetName || !$assetType) {
        $_SESSION['error'] = "Missing required information.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    $mainUrl = null;
    $filePath = null;
    $linkType = null;
    $textContent = null;
    $description = null;

    if ($assetType === 'link') {
        $mainUrl = sanitizeInput($_POST['main_url']);
        $linkType = sanitizeInput($_POST['link_type']);
        $description = $_POST['link_description'] ?? null;
        if (!$mainUrl) {
            $_SESSION['error'] = "URL is required for links.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }
    } elseif ($assetType === 'file') {
        $description = $_POST['file_description'] ?? null;
        if (!isset($_FILES['asset_file']) || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = "Error uploading file.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }
        $validation = validateAssetUpload($_FILES['asset_file']);
        if (!$validation['ok']) {
            $_SESSION['error'] = $validation['error'];
            header("Location: view.php?id=$projectId#assets");
            exit;
        }
        $filePath = saveAssetFile($_FILES['asset_file'], $validation['ext']);
        if (!$filePath) {
            $_SESSION['error'] = "Failed to save uploaded file.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }
    } elseif ($assetType === 'text') {
        $textContent = $_POST['text_content'] ?? null;
        $linkType = sanitizeInput($_POST['text_category']);
        if (!$textContent || trim(strip_tags($textContent)) === '') {
            $_SESSION['error'] = "Text content is required for text assets.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO project_assets (project_id, asset_name, main_url, file_path, created_by, asset_type, link_type, description, text_content)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$projectId, $assetName, $mainUrl, $filePath, $userId, $assetType, $linkType, $description, $textContent]);

        $logStmt = $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
        $logStmt->execute([$userId, "Added $assetType asset", 'project', $projectId, json_encode(['asset_name' => $assetName, 'asset_type' => $assetType])]);

        $_SESSION['success'] = "Asset added successfully!";
        $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) { echo json_encode(['success' => true]); exit; }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error.";
        $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) { echo json_encode(['success' => false]); exit; }
    }
    header("Location: view.php?id=$projectId#assets");
    exit;
}

// Handle asset edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_asset'])) {
    $assetId = isset($_POST['asset_id']) ? intval($_POST['asset_id']) : 0;
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $assetName = sanitizeInput($_POST['asset_name'] ?? '');

    if (!$assetId || !$projectId || !$assetName) {
        $_SESSION['error'] = "Missing required information for asset update.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    if (!canManageProjectAsset($db, $userId, $userRole, $projectId, $assetId, 'assets_edit')) {
        $_SESSION['error'] = "You don't have permission to edit this asset.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    try {
        $assetStmt = $db->prepare("SELECT * FROM project_assets WHERE id = ? AND project_id = ? LIMIT 1");
        $assetStmt->execute([$assetId, $projectId]);
        $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$asset) {
            $_SESSION['error'] = "Asset not found.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }

        $fields = ['asset_name = ?'];
        $params = [$assetName];

        if ($asset['asset_type'] === 'link') {
            $mainUrl = sanitizeInput($_POST['main_url'] ?? '');
            if (!$mainUrl) {
                $_SESSION['error'] = "URL is required for link assets.";
                header("Location: view.php?id=$projectId#assets");
                exit;
            }
            $fields[] = 'main_url = ?';
            $fields[] = 'link_type = ?';
            $fields[] = 'description = ?';
            $params[] = $mainUrl;
            $params[] = sanitizeInput($_POST['link_type'] ?? '') ?: null;
            $params[] = $_POST['link_description'] ?? null;
        } elseif ($asset['asset_type'] === 'text') {
            $textContent = $_POST['text_content'] ?? '';
            if (trim(strip_tags($textContent)) === '') {
                $_SESSION['error'] = "Text content is required for text assets.";
                header("Location: view.php?id=$projectId#assets");
                exit;
            }
            $fields[] = 'text_content = ?';
            $fields[] = 'link_type = ?';
            $params[] = $textContent;
            $params[] = sanitizeInput($_POST['text_category'] ?? '') ?: null;
        } elseif ($asset['asset_type'] === 'file') {
            $fields[] = 'description = ?';
            $params[] = $_POST['file_description'] ?? null;

            if (isset($_FILES['asset_file']) && $_FILES['asset_file']['error'] === UPLOAD_ERR_OK) {
                $validation = validateAssetUpload($_FILES['asset_file']);
                if (!$validation['ok']) {
                    $_SESSION['error'] = $validation['error'];
                    header("Location: view.php?id=$projectId#assets");
                    exit;
                }
                $newPath = saveAssetFile($_FILES['asset_file'], $validation['ext']);
                if (!$newPath) {
                    $_SESSION['error'] = "Failed to replace uploaded file.";
                    header("Location: view.php?id=$projectId#assets");
                    exit;
                }
                // Remove old file
                if (!empty($asset['file_path'])) {
                    $oldPath = __DIR__ . '/../../' . $asset['file_path'];
                    if (is_file($oldPath)) { @unlink($oldPath); }
                }
                $fields[] = 'file_path = ?';
                $params[] = $newPath;
            }
        }

        $params[] = $assetId;
        $params[] = $projectId;
        $upd = $db->prepare("UPDATE project_assets SET " . implode(', ', $fields) . " WHERE id = ? AND project_id = ?");
        $upd->execute($params);

        $log = $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
        $log->execute([$userId, "Edited asset", 'project', $projectId, json_encode(['asset_id' => $assetId, 'asset_name' => $assetName])]);

        $_SESSION['success'] = "Asset updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error.";
    }

    header("Location: view.php?id=$projectId#assets");
    exit;
}

// Handle asset deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_asset'])) {
    $assetId = isset($_POST['asset_id']) ? intval($_POST['asset_id']) : 0;
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;

    if (!$assetId || !$projectId) {
        $_SESSION['error'] = "Invalid request.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    if (!canManageProjectAsset($db, $userId, $userRole, $projectId, $assetId, 'assets_delete')) {
        $_SESSION['error'] = "You don't have permission to delete this asset.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM project_assets WHERE id = ? AND project_id = ?");
        $stmt->execute([$assetId, $projectId]);
        $asset = $stmt->fetch();

        if (!$asset) {
            $_SESSION['error'] = "Asset not found.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }

        if ($asset['asset_type'] === 'file' && $asset['file_path']) {
            $filePath = __DIR__ . '/../../' . $asset['file_path'];
            if (is_file($filePath)) { @unlink($filePath); }
        }

        $db->prepare("DELETE FROM project_assets WHERE id = ?")->execute([$assetId]);

        $log = $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
        $log->execute([$userId, "Deleted asset", 'project', $projectId, json_encode(['asset_id' => $assetId, 'asset_name' => $asset['asset_name']])]);

        $_SESSION['success'] = "Asset deleted successfully!";
        $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) { echo json_encode(['success' => true]); exit; }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error.";
        $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) { echo json_encode(['success' => false]); exit; }
    }

    header("Location: view.php?id=$projectId#assets");
    exit;
}
