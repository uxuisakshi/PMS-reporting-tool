<?php
/**
 * Issue Page Screenshot Upload API
 * Handles multiple screenshot uploads for issues with grouped URL reference
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$userRole = $auth->getUserRole();
$allowedRoles = ['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'client'];
if (!in_array($userRole, $allowedRoles)) {
    error_log("[IssueScreenshotAPI] Insufficient permissions. Role: $userRole");
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
// Create upload directory if it doesn't exist
$uploadDir = __DIR__ . '/../assets/uploads/issue_screenshots';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create screenshot upload directory']);
    exit;
}

// Handle GET requests
if ($method === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'grouped_urls') {
        $pageId = (int)($_GET['page_id'] ?? 0);

        if (!$pageId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing page_id']);
            exit;
        }

        $stmt = $db->prepare('SELECT id, project_id, url FROM project_pages WHERE id = ? LIMIT 1');
        $stmt->execute([$pageId]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$page) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Page not found']);
            exit;
        }

        if (!hasProjectAccess($db, $userId, (int)$page['project_id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }

        $pageUrl = trim((string)($page['url'] ?? ''));
        $uniqueIds = [$pageId];

        if ($pageUrl !== '') {
            $uniqueStmt = $db->prepare('
                SELECT DISTINCT unique_page_id
                FROM grouped_urls
                WHERE project_id = ?
                  AND unique_page_id IS NOT NULL
                  AND (url = ? OR normalized_url = ?)
            ');
            $uniqueStmt->execute([(int)$page['project_id'], $pageUrl, $pageUrl]);

            foreach ($uniqueStmt->fetchAll(PDO::FETCH_COLUMN) as $uniqueId) {
                $uniqueId = (int)$uniqueId;
                if ($uniqueId > 0) {
                    $uniqueIds[] = $uniqueId;
                }
            }
        }

        $uniqueIds = array_values(array_unique(array_filter($uniqueIds)));
        $params = [(int)$page['project_id']];
        $conditions = [];

        if (!empty($uniqueIds)) {
            $conditions[] = 'gu.unique_page_id IN (' . implode(',', array_fill(0, count($uniqueIds), '?')) . ')';
            $params = array_merge($params, $uniqueIds);
        }

        if ($pageUrl !== '') {
            $conditions[] = 'gu.url = ?';
            $conditions[] = 'gu.normalized_url = ?';
            $params[] = $pageUrl;
            $params[] = $pageUrl;
        }

        $groupedUrls = [];
        if (!empty($conditions)) {
            $sql = '
                SELECT DISTINCT gu.id,
                    COALESCE(NULLIF(gu.url, \'\'), gu.normalized_url) AS url,
                    gu.normalized_url,
                    gu.unique_page_id
                FROM grouped_urls gu
                WHERE gu.project_id = ?
                  AND (' . implode(' OR ', $conditions) . ')
                ORDER BY COALESCE(NULLIF(gu.url, \'\'), gu.normalized_url)
            ';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $groupedUrls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($pageUrl !== '') {
            $pageUrlExists = false;
            foreach ($groupedUrls as $groupedUrl) {
                $existingUrl = trim((string)($groupedUrl['url'] ?? $groupedUrl['normalized_url'] ?? ''));
                if ($existingUrl !== '' && strcasecmp($existingUrl, $pageUrl) === 0) {
                    $pageUrlExists = true;
                    break;
                }
            }

            if (!$pageUrlExists) {
                $groupedUrls[] = [
                    'id' => null,
                    'url' => $pageUrl,
                    'normalized_url' => $pageUrl,
                    'unique_page_id' => $pageId,
                ];
            }
        }

        if (empty($groupedUrls) && $pageUrl !== '') {
            $groupedUrls[] = [
                'id' => null,
                'url' => $pageUrl,
                'normalized_url' => $pageUrl,
                'unique_page_id' => $pageId,
            ];
        }

        echo json_encode([
            'success' => true,
            'grouped_urls' => $groupedUrls,
        ]);
        exit;
    }
    
    if ($action === 'list') {
        // Get list of screenshots for a page
        $pageId = (int)($_GET['page_id'] ?? 0);
        
        if (!$pageId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing page_id']);
            exit;
        }
        
        // Validate page_id exists
        $stmt = $db->prepare("SELECT id FROM project_pages WHERE id = ?");
        $stmt->execute([$pageId]);
        if (!$stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid page_id']);
            exit;
        }
        
        // Verify access via page
        $stmt = $db->prepare("
            SELECT pp.project_id FROM project_pages pp 
            WHERE pp.id = ?
        ");
        $stmt->execute([$pageId]);
        $page = $stmt->fetch();
        
        if (!$page) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Page not found']);
            exit;
        }
        
        require_once __DIR__ . '/../includes/project_permissions.php';
        if (!hasProjectAccess($db, $userId, $page['project_id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        
        // Fetch screenshots
        $stmt = $db->prepare("
        SELECT ips.*, u.full_name, COALESCE(gu.url, ips.url_text) as grouped_url
        FROM issue_page_screenshots ips
        LEFT JOIN users u ON ips.uploaded_by = u.id
        LEFT JOIN grouped_urls gu ON ips.grouped_url_id = gu.id
        WHERE ips.page_id = ?
        ORDER BY ips.created_at DESC
    ");
        $stmt->execute([$pageId]);
        $screenshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($screenshots as &$screenshot) {
            $screenshot['public_url'] = build_public_image_url_from_src((string)($screenshot['file_path'] ?? ''));
        }
        unset($screenshot);
        
        echo json_encode([
            'success' => true,
            'screenshots' => $screenshots
        ]);
        exit;
    }

    if ($action === 'count') {
        $pageId = (int)($_GET['page_id'] ?? 0);
        $stmt = $db->prepare("SELECT COUNT(*) FROM issue_page_screenshots WHERE page_id = ?");
        $stmt->execute([$pageId]);
        echo json_encode([
            'success' => true,
            'count' => (int)$stmt->fetchColumn()
        ]);
        exit;
    }
}

// Handle POST requests
if ($method === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        if ($action === 'upload') {
            // Handle file upload
            $issueId = (int)($_POST['issue_id'] ?? 0);
            $pageId = (int)($_POST['page_id'] ?? 0);
            $groupedUrlId = (int)($_POST['grouped_url_id'] ?? 0);
            $selectedUrlText = trim((string)($_POST['selected_url_text'] ?? ''));
            $description = trim($_POST['description'] ?? '');

            if (!$pageId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing page_id']);
                exit;
            }
        
            // Validate grouped_url_id if provided
            if ($groupedUrlId > 0) {
                $stmt = $db->prepare("SELECT id FROM grouped_urls WHERE id = ?");
                $stmt->execute([$groupedUrlId]);
                if (!$stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid grouped URL']);
                    exit;
                }
            }
        
            // Verify user has access to this project via page
            $stmt = $db->prepare("
                SELECT pp.project_id FROM project_pages pp 
                WHERE pp.id = ?
            ");
            $stmt->execute([$pageId]);
            $page = $stmt->fetch();
        
            if (!$page) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Page not found']);
                exit;
            }
        
            $projectId = $page['project_id'];
        
            // Check project access
            if (!hasProjectAccess($db, $userId, $projectId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }

            if (!is_writable($uploadDir)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Upload directory is not writable']);
                exit;
            }
        
            // Handle file uploads
            if (!isset($_FILES['screenshots']) || empty($_FILES['screenshots']['name'][0])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No files uploaded']);
                exit;
            }
        
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
            $maxFileSize = 10 * 1024 * 1024; // 10MB
            $uploadedFiles = [];
            $errors = [];
        
            $fileCount = count($_FILES['screenshots']['name']);
        
            for ($i = 0; $i < $fileCount; $i++) {
                $file = [
                    'name' => $_FILES['screenshots']['name'][$i],
                    'type' => $_FILES['screenshots']['type'][$i],
                    'tmp_name' => $_FILES['screenshots']['tmp_name'][$i],
                    'size' => $_FILES['screenshots']['size'][$i],
                    'error' => $_FILES['screenshots']['error'][$i]
                ];
            
                // Validate file
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = $file['name'] . ': Upload error code ' . $file['error'];
                    continue;
                }
            
                if ($file['size'] > $maxFileSize) {
                    $errors[] = $file['name'] . ': File too large (max 10MB)';
                    continue;
                }
            
                if (!in_array($file['type'], $allowedMimes, true)) {
                    $errors[] = $file['name'] . ': Invalid file type (' . $file['type'] . ')';
                    continue;
                }
            
                // Generate unique filename
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $uniqueName = 'page_' . $pageId . '_' . time() . '_' . uniqid('', true) . '.' . $ext;
                $filePath = $uploadDir . '/' . $uniqueName;
            
                // Move uploaded file
                if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                    $errors[] = $file['name'] . ': Failed to save file';
                    continue;
                }
            
                // Save to database
                if (!isset($insertStmt)) {
                    $insertStmt = $db->prepare("
                        INSERT INTO issue_page_screenshots 
                        (issue_id, page_id, grouped_url_id, url_text, file_path, original_filename, file_size, mime_type, description, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                }
            
                try {
                    $insertStmt->execute([
                        $issueId > 0 ? $issueId : null,
                        $pageId,
                        $groupedUrlId > 0 ? $groupedUrlId : null,
                        $selectedUrlText !== '' ? $selectedUrlText : null,
                        'assets/uploads/issue_screenshots/' . $uniqueName,
                        $file['name'],
                        $file['size'],
                        $file['type'],
                        $description,
                        $userId
                    ]);
                    
                    $uploadedFiles[] = [
                        'id' => $db->lastInsertId(),
                        'filename' => $file['name'],
                        'path' => 'assets/uploads/issue_screenshots/' . $uniqueName,
                        'size' => $file['size']
                    ];
                } catch (Exception $e) {
                    $errors[] = $file['name'] . ': Database error - ' . $e->getMessage();
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
            }
            
            $successCount = count($uploadedFiles);
            $message = $successCount > 0
                ? $successCount . ' file(s) uploaded' . (!empty($errors) ? ' with some errors' : '')
                : (!empty($errors) ? implode(' | ', $errors) : 'No files were uploaded');

            echo json_encode([
                'success' => $successCount > 0,
                'uploaded' => $uploadedFiles,
                'errors' => $errors,
                'message' => $message
            ]);
            exit;
        }
    
    if ($action === 'delete') {
        // Delete a screenshot
        $screenshotId = (int)($_POST['screenshot_id'] ?? 0);
        
        if (!$screenshotId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid screenshot ID']);
            exit;
        }
        
        // Get screenshot details
        $stmt = $db->prepare("
            SELECT ips.*, pp.project_id FROM issue_page_screenshots ips
            JOIN project_pages pp ON ips.page_id = pp.id
            WHERE ips.id = ?
        ");
        $stmt->execute([$screenshotId]);
        $screenshot = $stmt->fetch();
        
        if (!$screenshot) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Screenshot not found']);
            exit;
        }
        
        // Verify access
        if (!hasProjectAccess($db, $userId, $screenshot['project_id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        
        // Delete file
        $filePath = __DIR__ . '/../' . $screenshot['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Delete from database
        $stmt = $db->prepare("DELETE FROM issue_page_screenshots WHERE id = ?");
        $stmt->execute([$screenshotId]);
        
        echo json_encode(['success' => true, 'message' => 'Screenshot deleted']);
        exit;
    }
    } catch (Exception $e) {
        error_log('Screenshot API error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid request']);
exit;
