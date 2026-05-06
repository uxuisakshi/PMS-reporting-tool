<?php
require_once __DIR__ . '/../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Extension-Token');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$requiredToken = trim((string)getenv('EXTENSION_TEMP_UPLOAD_TOKEN'));
$providedToken = trim((string)($_SERVER['HTTP_X_EXTENSION_TOKEN'] ?? $_POST['token'] ?? ''));
if ($requiredToken !== '' && !hash_equals($requiredToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid extension token']);
    exit;
}

if (!isset($_FILES['screenshot']) || !is_array($_FILES['screenshot'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Screenshot file is required']);
    exit;
}

$file = $_FILES['screenshot'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Upload failed', 'code' => $file['error'] ?? null]);
    exit;
}

$allowedTypes = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp'
];

$mime = '';
if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = @finfo_file($finfo, $file['tmp_name']);
        if (is_string($detected)) {
            $mime = strtolower(trim($detected));
        }
        @finfo_close($finfo);
    }
}

if (!isset($allowedTypes[$mime])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported screenshot type']);
    exit;
}

$scanId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['scan_id'] ?? ''));
if ($scanId === '') {
    $scanId = 'scan_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Project root not found']);
    exit;
}

$relativeDir = 'uploads/temporary-extensions-testing/' . date('Ymd') . '/' . $scanId;
$targetDir = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create target directory']);
    exit;
}

$screenshotName = 'viewport.' . $allowedTypes[$mime];
$screenshotPath = $targetDir . DIRECTORY_SEPARATOR . $screenshotName;
if (!@move_uploaded_file($file['tmp_name'], $screenshotPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to store screenshot']);
    exit;
}

$metadata = [
    'scan_id' => $scanId,
    'page_title' => trim((string)($_POST['page_title'] ?? '')),
    'page_url' => trim((string)($_POST['page_url'] ?? '')),
    'created_at' => date('c'),
    'finding_count' => (int)($_POST['finding_count'] ?? 0),
    'impact_summary' => json_decode((string)($_POST['impact_summary'] ?? '{}'), true),
    'findings' => json_decode((string)($_POST['findings_json'] ?? '[]'), true),
    'screenshot_relative_path' => $relativeDir . '/' . $screenshotName,
]
;
@file_put_contents(
    $targetDir . DIRECTORY_SEPARATOR . 'scan-metadata.json',
    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

$baseDir = function_exists('getBaseDir') ? rtrim((string)getBaseDir(), '/') : '';

echo json_encode([
    'success' => true,
    'scan_id' => $scanId,
    'relative_dir' => $relativeDir,
    'screenshot_relative_path' => $relativeDir . '/' . $screenshotName,
    'metadata_relative_path' => $relativeDir . '/scan-metadata.json',
    'base_dir' => $baseDir
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);