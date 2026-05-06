<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
ob_end_clean();

// Debug log function - disabled in production
function issue_upload_debug_log($msg) {
    // Enable logging temporarily for debugging upload issues
    @file_put_contents(__DIR__ . '/../tmp/issue_upload_debug.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}


issue_upload_debug_log('--- New upload request ---');
$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'client']);

header('Content-Type: application/json');

// CSRF protection for file uploads
enforceApiCsrf();

// Removed rate limiting as per user request to allow unlimited uploads.



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    issue_upload_debug_log('Request method not POST: ' . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}


if (!isset($_FILES['image'])) {
    issue_upload_debug_log('No image uploaded in \'_FILES\'.');
    http_response_code(400);
    echo json_encode(['error' => 'No image uploaded']);
    exit;
}


$file = $_FILES['image'];
issue_upload_debug_log('File received: name=' . ($file['name'] ?? 'N/A') . ', size=' . ($file['size'] ?? 'N/A') . ', error=' . ($file['error'] ?? 'N/A'));


// Validate file
if ($file['error'] !== UPLOAD_ERR_OK) {
    issue_upload_debug_log('Upload error: ' . $file['error']);
    $uploadErrorMessages = [
        UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds the server upload limit. Please try a smaller screenshot.',
        UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the allowed form upload size. Please try a smaller screenshot.',
        UPLOAD_ERR_PARTIAL => 'File upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Upload temporary directory is missing on the server.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.'
    ];
    http_response_code(400);
    echo json_encode([
        'error' => $uploadErrorMessages[$file['error']] ?? ('Upload error: ' . $file['error']),
        'code' => (int) $file['error']
    ]);
    exit;
}


// Check file size (max 10MB)
$maxSize = 10 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    issue_upload_debug_log('File too large: ' . $file['size']);
    http_response_code(400);
    echo json_encode(['error' => 'File too large. Max 10MB allowed.']);
    exit;
}

// Check file type (robust on shared hosting with limited extensions)
$allowedMimeTypes = [
    'image/jpeg' => '.jpg',
    'image/jpg' => '.jpg',
    'image/pjpeg' => '.jpg',
    'image/png' => '.png',
    'image/x-png' => '.png',
    'image/gif' => '.gif',
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
    // Accept extension fallback only when file looks like an image.
    $imgInfo = @getimagesize($file['tmp_name']);
    if (is_array($imgInfo)) {
        $ext = $allowedNameExt[$nameExt];
    }
}

$debugMime = 'mime=' . $mime . ', nameExt=' . $nameExt . ', ext=' . $ext;
if ($ext === '') {
    issue_upload_debug_log('Invalid file type: ' . $debugMime);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only JPG, PNG, GIF, WEBP allowed.', 'detected_mime' => $mime]);
    exit;
}
$folder = __DIR__ . '/../uploads/issues/' . date('Ymd');


if (!is_dir($folder)) {
    if (!@mkdir($folder, 0755, true) && !is_dir($folder)) {
        issue_upload_debug_log('Failed to create upload dir: ' . $folder);
        http_response_code(500);
        echo json_encode(['error' => 'Upload directory is not writable']);
        exit;
    } else {
        issue_upload_debug_log('Created upload dir: ' . $folder);
    }
}


$filename = uniqid('issue_', true) . $ext;
$dest = $folder . '/' . $filename;
issue_upload_debug_log('Saving file to: ' . $dest);


if (!move_uploaded_file($file['tmp_name'], $dest)) {
    issue_upload_debug_log('move_uploaded_file failed: tmp=' . $file['tmp_name'] . ', dest=' . $dest);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store image']);
    exit;
} else {
    issue_upload_debug_log('File saved successfully: ' . $dest);
}

// Get base directory for URL
$baseDir = '';
if (function_exists('getBaseDir')) {
    $baseDir = getBaseDir();
}

$relativePath = 'uploads/issues/' . date('Ymd') . '/' . $filename;
$previewKey = 'temporary_issue_upload_paths';
$previewTtl = 2 * 60 * 60;
if (!isset($_SESSION[$previewKey]) || !is_array($_SESSION[$previewKey])) {
    $_SESSION[$previewKey] = [];
}
foreach ($_SESSION[$previewKey] as $path => $timestamp) {
    if (!is_string($path) || !is_numeric($timestamp) || ((int)$timestamp + $previewTtl) < time()) {
        unset($_SESSION[$previewKey][$path]);
    }
}
$_SESSION[$previewKey][$relativePath] = time();
$url = rtrim((string)$baseDir, '/') . '/api/secure_file.php?path=' . rawurlencode($relativePath);


issue_upload_debug_log('Upload complete. URL: ' . $url);
echo json_encode(['success' => true, 'url' => $url]);
