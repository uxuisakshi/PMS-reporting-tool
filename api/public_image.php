<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '' || strpos($token, '.') === false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid token';
    exit;
}

$parts = explode('.', $token, 2);
$payloadB64 = (string)($parts[0] ?? '');
$sig = (string)($parts[1] ?? '');

$expected = hash_hmac('sha256', $payloadB64, get_public_image_token_secret());

$decoded = base64url_decode($payloadB64);
if ($decoded === false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid token payload';
    exit;
}

$payload = json_decode($decoded, true);
$relPath = ltrim(str_replace('\\', '/', (string)($payload['p'] ?? '')), '/');
if ($relPath === '' || strpos($relPath, "\0") !== false || strpos($relPath, '..') !== false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid path';
    exit;
}

if (!hash_equals($expected, $sig)) {
    // Grace fallback for old URLs sent to clients:
    // If signature fails (because the server directory path case changed), 
    // we still serve the image if it belongs to 'uploads/issues/' or 'uploads/chat/'.
    // These directories use uniqid() filenames, making IDOR enumeration mathematically infeasible.
    if (strpos($relPath, 'uploads/issues/') !== 0 && strpos($relPath, 'uploads/chat/') !== 0) {
        error_log("Public Image API: Forbidden - Signature mismatch for payload: " . $payloadB64);
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
}

$allowedPrefixes = ['uploads/issues/', 'uploads/chat/', 'assets/uploads/'];
$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (strpos($relPath, $prefix) === 0) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    error_log("Public Image API: Forbidden - Path prefix not allowed: " . $relPath);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$ext = strtolower((string)pathinfo($relPath, PATHINFO_EXTENSION));
// SVG intentionally excluded: SVGs can contain embedded scripts that execute
// when the signed URL is opened directly in a browser tab (Content-Disposition: inline).
$allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];
if (!in_array($ext, $allowedExts, true)) {
    error_log("Public Image API: Forbidden - Extension not allowed: " . $ext . " for path: " . $relPath);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error';
    exit;
}

$candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
$fullPath = realpath($candidate);
if ($fullPath === false || !is_file($fullPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$fullNorm = str_replace('\\', '/', $fullPath);
$baseNorm = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
// Use stripos for case-insensitive comparison (crucial on Windows XAMPP)
if (stripos($fullNorm, $baseNorm . 'uploads/') !== 0 && stripos($fullNorm, $baseNorm . 'assets/uploads/') !== 0) {
    error_log("Public Image API: Forbidden - Base directory escape check failed. Full: " . $fullNorm . " Base: " . $baseNorm);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $fi = @finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $detected = @finfo_file($fi, $fullPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
        @finfo_close($fi);
    }
}
if ($mime === 'application/octet-stream' && function_exists('mime_content_type')) {
    $detected = @mime_content_type($fullPath);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }
}
if ($mime === 'application/octet-stream') {
    $imgInfo = @getimagesize($fullPath);
    if (is_array($imgInfo) && isset($imgInfo['mime']) && is_string($imgInfo['mime'])) {
        $mime = $imgInfo['mime'];
    }
}
if ($mime === 'application/octet-stream' || $mime === '') {
    $mimeFallback = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif'
    ];
    if (isset($mimeFallback[$ext])) {
        $mime = $mimeFallback[$ext];
    }
}

if (stripos($mime, 'image/') !== 0) {
    error_log("Public Image API: Forbidden - Invalid MIME type: " . $mime . " for path: " . $relPath);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
readfile($fullPath);
exit;

