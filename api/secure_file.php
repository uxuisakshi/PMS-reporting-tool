<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_permissions.php';
require_once __DIR__ . '/../includes/client_issue_snapshots.php';

$relPath = trim((string)($_GET['path'] ?? ''));
if ($relPath === '' || strpos($relPath, "\0") !== false) {
    http_response_code(400);
    echo 'Invalid path';
    exit;
}

$relPath = ltrim(str_replace('\\', '/', $relPath), '/');
if (strpos($relPath, '..') !== false) {
    http_response_code(400);
    echo 'Invalid path';
    exit;
}

$allowedPrefixes = ['uploads/', 'assets/uploads/'];
$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (strpos($relPath, $prefix) === 0) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$fileExt = strtolower((string)pathinfo($relPath, PATHINFO_EXTENSION));
$publicImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];
$allowPublicIssueImage = (
    (strpos($relPath, 'uploads/issues/') === 0 || strpos($relPath, 'assets/uploads/issues/') === 0)
    && in_array($fileExt, $publicImageExts, true)
);

// Only log errors, not every request.
// Direct issue screenshot image URLs are allowed without login, but only for exact image files.
if (!isset($_SESSION['user_id']) && !$allowPublicIssueImage) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden: Not logged in';
    exit;
}


$parts = explode('/', $relPath);
foreach ($parts as $part) {
    if ($part !== '' && $part[0] === '.') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$baseDir = realpath(__DIR__ . '/..');
$fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath));
if ($fullPath === false || !is_file($fullPath)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$uploadsRoot = realpath($baseDir . DIRECTORY_SEPARATOR . 'uploads');
$assetsUploadsRoot = realpath($baseDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads');
$fullNorm = str_replace('\\', '/', $fullPath);
$insideAllowed = false;
if ($uploadsRoot !== false) {
    $uNorm = rtrim(str_replace('\\', '/', $uploadsRoot), '/') . '/';
    if (strpos($fullNorm, $uNorm) === 0) {
        $insideAllowed = true;
    }
}
if (!$insideAllowed && $assetsUploadsRoot !== false) {
    $aNorm = rtrim(str_replace('\\', '/', $assetsUploadsRoot), '/') . '/';
    if (strpos($fullNorm, $aNorm) === 0) {
        $insideAllowed = true;
    }
}
if (!$insideAllowed) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function escapeLikeValue($value) {
    return strtr((string)$value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_'
    ]);
}

function buildReferencedFileLikePatterns(string $relPath): array {
    $normalized = ltrim(str_replace('\\', '/', trim($relPath)), '/');
    if ($normalized === '') {
        return [];
    }

    $variants = [$normalized];
    $encoded = rawurlencode($normalized);
    if ($encoded !== '') {
        $variants[] = $encoded;
        $variants[] = 'path=' . $encoded;
        $variants[] = '/api/secure_file.php?path=' . $encoded;
        $variants[] = 'api/secure_file.php?path=' . $encoded;
    }

    $likePatterns = [];
    foreach (array_values(array_unique($variants)) as $variant) {
        $likePatterns[] = '%' . escapeLikeValue($variant) . '%';
    }

    return $likePatterns;
}

function queryAccessibleProjectIds(PDO $db, string $sql, array $likePatterns, int $userId): bool {
    foreach ($likePatterns as $like) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$like]);
        while (($projectId = (int)$stmt->fetchColumn()) > 0) {
            if (hasProjectAccess($db, $userId, $projectId)) {
                return true;
            }
        }
    }
    return false;
}

function userCanAccessReferencedIssueProject(PDO $db, int $userId, string $role, string $relPath): bool {
    $likePatterns = buildReferencedFileLikePatterns($relPath);
    if (empty($likePatterns)) {
        return false;
    }
    ensureIssueClientSnapshotTable($db);

    $issueSql = "
        SELECT DISTINCT i.project_id
        FROM issues i
        WHERE i.description LIKE ? ESCAPE '\\\\'
    ";
    if ($role === 'client') {
        $issueSql .= " AND i.client_ready = 1";
    }
    if (queryAccessibleProjectIds($db, $issueSql, $likePatterns, $userId)) {
        return true;
    }

    $commentSql = "
        SELECT DISTINCT i.project_id
        FROM issue_comments ic
        JOIN issues i ON i.id = ic.issue_id
        WHERE ic.comment_html LIKE ? ESCAPE '\\\\'
    ";
    if ($role === 'client') {
        $commentSql .= " AND i.client_ready = 1";
    }
    if (queryAccessibleProjectIds($db, $commentSql, $likePatterns, $userId)) {
        return true;
    }

    if ($role === 'client') {
        if (queryAccessibleProjectIds(
            $db,
            "SELECT DISTINCT s.project_id
             FROM issue_client_snapshots s
             WHERE s.snapshot_json LIKE ? ESCAPE '\\\\'",
            $likePatterns,
            $userId
        )) {
            return true;
        }

        if (queryAccessibleProjectIds(
            $db,
            "SELECT DISTINCT s.project_id
             FROM issue_client_snapshots s
             JOIN issue_comments ic ON ic.issue_id = s.issue_id AND ic.created_at <= s.published_at
             WHERE ic.comment_html LIKE ? ESCAPE '\\\\'",
            $likePatterns,
            $userId
        )) {
            return true;
        }
    }

    return false;
}

function userCanAccessReferencedChat(PDO $db, int $userId, string $role, string $relPath): bool {
    $likePatterns = buildReferencedFileLikePatterns($relPath);
    if (empty($likePatterns)) {
        return false;
    }

    foreach ($likePatterns as $like) {
        $chatStmt = $db->prepare("SELECT DISTINCT project_id FROM chat_messages WHERE project_id IS NOT NULL AND message LIKE ? ESCAPE '\\\\'");
        $chatStmt->execute([$like]);
        while (($projectId = (int)$chatStmt->fetchColumn()) > 0) {
            if (hasProjectAccess($db, $userId, $projectId)) {
                return true;
            }
        }
    }

    if ($role === 'admin') {
        foreach ($likePatterns as $like) {
            $adminStmt = $db->prepare("SELECT 1 FROM chat_messages WHERE message LIKE ? ESCAPE '\\\\' LIMIT 1");
            $adminStmt->execute([$like]);
            if ((bool)$adminStmt->fetchColumn()) {
                return true;
            }
        }
        return false;
    }

    foreach ($likePatterns as $like) {
        $ownStmt = $db->prepare("SELECT 1 FROM chat_messages WHERE project_id IS NULL AND user_id = ? AND message LIKE ? ESCAPE '\\\\' LIMIT 1");
        $ownStmt->execute([$userId, $like]);
        if ((bool)$ownStmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}

function userCanAccessIssuePageScreenshot(PDO $db, int $userId, string $relPath): bool {
    $stmt = $db->prepare("SELECT pp.project_id
        FROM issue_page_screenshots ips
        JOIN project_pages pp ON pp.id = ips.page_id
        WHERE ips.file_path = ?
        LIMIT 1");
    $stmt->execute([$relPath]);
    $projectId = (int)$stmt->fetchColumn();

    if ($projectId <= 0) {
        return false;
    }

    return hasProjectAccess($db, $userId, $projectId);
}

function userCanAccessFilePath(PDO $db, int $userId, string $role, string $relPath): bool {
    $assetStmt = $db->prepare("SELECT project_id FROM project_assets WHERE file_path = ? LIMIT 1");
    $assetStmt->execute([$relPath]);
    $projectId = (int)$assetStmt->fetchColumn();
    if ($projectId > 0) {
        return hasProjectAccess($db, $userId, $projectId);
    }

    if (strpos($relPath, 'uploads/issues/') === 0) {
        return userCanAccessReferencedIssueProject($db, $userId, $role, $relPath);
    }

    // Chat images: allow any logged-in non-client user
    // Images are uploaded by authenticated users and linked in chat messages
    if (strpos($relPath, 'uploads/chat/') === 0) {
        return $role !== 'client';
    }

    if (strpos($relPath, 'assets/uploads/') === 0) {
        if (strpos($relPath, 'assets/uploads/issue_screenshots/') === 0) {
            return userCanAccessIssuePageScreenshot($db, $userId, $relPath);
        }

        if (strpos($relPath, 'assets/uploads/issues/') === 0) {
            return userCanAccessReferencedIssueProject($db, $userId, $role, $relPath);
        }

        if (strpos($relPath, 'assets/uploads/chat/') === 0) {
            return $role !== 'client';
        }

        return false;
    }

    if (strpos($relPath, 'uploads/automated_findings/project_') === 0) {
        if (preg_match('/^uploads\/automated_findings\/project_(\d+)\//', $relPath, $m)) {
            $projectIdCheck = (int)$m[1];
            return hasProjectAccess($db, $userId, $projectIdCheck);
        }
    }

    return false;
}

function userCanPreviewTemporaryIssueUpload(string $relPath): bool {
    $previewTtl = 2 * 60 * 60;

    // Check issue upload paths
    $issueKey = 'temporary_issue_upload_paths';
    if (isset($_SESSION[$issueKey]) && is_array($_SESSION[$issueKey])) {
        foreach ($_SESSION[$issueKey] as $path => $timestamp) {
            if (!is_string($path) || !is_numeric($timestamp) || ((int)$timestamp + $previewTtl) < time()) {
                unset($_SESSION[$issueKey][$path]);
                continue;
            }
            if ($path === $relPath) return true;
        }
    }

    // Check chat upload paths
    $chatKey = 'temporary_chat_upload_paths';
    if (isset($_SESSION[$chatKey]) && is_array($_SESSION[$chatKey])) {
        foreach ($_SESSION[$chatKey] as $path => $timestamp) {
            if (!is_string($path) || !is_numeric($timestamp) || ((int)$timestamp + $previewTtl) < time()) {
                unset($_SESSION[$chatKey][$path]);
                continue;
            }
            if ($path === $relPath) return true;
        }
    }

    return false;
}
try {
    if (!$allowPublicIssueImage) {
        $db = Database::getInstance();
        $userId = (int)$_SESSION['user_id'];
        $userRole = (string)($_SESSION['role'] ?? '');

        if (!userCanPreviewTemporaryIssueUpload($relPath) && !userCanAccessFilePath($db, $userId, $userRole, $relPath)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Forbidden';
            exit;
        }
    }
} catch (Exception $e) {
    error_log('secure_file.php: permission check failed: ' . $e->getMessage());
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

// Set appropriate MIME type
$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = @$finfo->file($fullPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    } catch (Exception $e) {
        // Fallback to extension-based MIME if finfo fails
    }
}

// Force extension check for web files to prevent strict nosniff blocking
$fileExt = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'pdf' => 'application/pdf',
    'avif' => 'image/avif'
];

if (isset($mimeTypes[$fileExt])) {
    $mime = $mimeTypes[$fileExt];
} elseif ($mime === 'application/octet-stream' || $mime === '') {
    if ($fileExt === 'txt') $mime = 'text/plain';
    if ($fileExt === 'csv') $mime = 'text/csv';
}

// Add caching headers for images to reduce server load
$fileExt = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$commonImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'];
if (in_array($fileExt, $commonImageExts)) {
    header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
}

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');

// Clear any accidental whitespace or output from included files
while (ob_get_level()) {
    ob_end_clean();
}

readfile($fullPath);
exit;
