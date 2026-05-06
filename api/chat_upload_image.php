<?php
// DO NOT call session_start() here - let auth.php handle it with correct session name (PMS_SESSION)
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
ob_end_clean();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$viewerRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$viewerRole = preg_replace('/[^a-z0-9]+/', '_', $viewerRole);
$viewerRole = trim($viewerRole, '_');
if ($viewerRole === 'client') {
    http_response_code(403);
    echo json_encode(['error' => 'Project chat is not available for client accounts.']);
    exit;
}

// CSRF protection for file uploads
enforceApiCsrf();

// Removed rate limiting as per user request to allow unlimited uploads.

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload failed']);
    exit;
}
$allowedMimeTypes = [
    'image/jpeg' => '.jpg',
    'image/jpg' => '.jpg',
    'image/pjpeg' => '.jpg',
    'image/png'  => '.png',
    'image/x-png' => '.png',
    'image/gif'  => '.gif',
    'image/webp' => '.webp'
];
$allowedNameExt = [
    'jpg' => '.jpg',
    'jpeg' => '.jpg',
    'png' => '.png',
    'gif' => '.gif',
    'webp' => '.webp'
];

$mime = '';
if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = @finfo_file($finfo, $file['tmp_name']);
        if (is_string($detected)) {
            $mime = $detected;
        }
        @finfo_close($finfo);
    }
}
if ($mime === '' && function_exists('mime_content_type')) {
    $detected = @mime_content_type($file['tmp_name']);
    if (is_string($detected)) {
        $mime = $detected;
    }
}
if ($mime === '') {
    $imgInfo = @getimagesize($file['tmp_name']);
    if (is_array($imgInfo) && isset($imgInfo['mime']) && is_string($imgInfo['mime'])) {
        $mime = $imgInfo['mime'];
    }
}
if ($mime === '' && function_exists('exif_imagetype')) {
    $imgType = @exif_imagetype($file['tmp_name']);
    if ($imgType) {
        $detected = @image_type_to_mime_type($imgType);
        if (is_string($detected)) {
            $mime = $detected;
        }
    }
}
$mime = strtolower(trim(explode(';', (string)$mime)[0]));
$nameExt = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
$ext = '';
if ($mime !== '' && isset($allowedMimeTypes[$mime])) {
    $ext = $allowedMimeTypes[$mime];
} elseif ($nameExt !== '' && isset($allowedNameExt[$nameExt])) {
    $imgInfo = @getimagesize($file['tmp_name']);
    if (is_array($imgInfo)) {
        $ext = $allowedNameExt[$nameExt];
    }
}

if ($ext === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Only JPG, PNG, GIF, WEBP allowed', 'detected_mime' => $mime]);
    exit;
}

$maxSize = 10 * 1024 * 1024; // 10MB (match issue upload behavior)
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Image too large (max 10MB)']);
    exit;
}

$folder = __DIR__ . '/../uploads/chat/' . date('Ymd');
if (!is_dir($folder)) {
    if (!@mkdir($folder, 0755, true) && !is_dir($folder)) {
        http_response_code(500);
        echo json_encode(['error' => 'Upload directory is not writable']);
        exit;
    }
}
$filename = uniqid('chat_', true) . $ext;
$dest = $folder . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store image']);
    exit;
}

$relativePath = 'uploads/chat/' . date('Ymd') . '/' . $filename;
if (!isset($baseDir)) {
    require_once __DIR__ . '/../includes/helpers.php';
    $baseDir = getBaseDir();
}

// Register in session for temporary preview access (before message is sent)
if (!isset($_SESSION['temporary_chat_upload_paths']) || !is_array($_SESSION['temporary_chat_upload_paths'])) {
    $_SESSION['temporary_chat_upload_paths'] = [];
}
$_SESSION['temporary_chat_upload_paths'][$relativePath] = time();

$url = rtrim($baseDir, '/') . '/api/secure_file.php?path=' . rawurlencode($relativePath);

echo json_encode(['success' => true, 'url' => $url]);
