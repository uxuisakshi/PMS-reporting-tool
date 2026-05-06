<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Discard any accidental output from includes (warnings, BOM, whitespace)
ob_end_clean();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=20, stale-while-revalidate=20');

// Auth: must be logged in with admin or project_lead role
$auth = new Auth();
if (!$auth->isLoggedIn() || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'project_lead'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Input validation
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

$projectId    = (int)($_GET['project_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$fpPage       = max(1, (int)($_GET['fp_page'] ?? 1));
$fpPerPage    = 15;
$fpOffset     = ($fpPage - 1) * $fpPerPage;

// Validate status against DB values (dynamic whitelist — handles custom statuses)
if (!empty($filterStatus)) {
    try {
        $db = Database::getInstance();
        $chkStmt = $db->prepare("SELECT COUNT(*) FROM project_statuses WHERE status_key = ?");
        $chkStmt->execute([$filterStatus]);
        $validStatus = (int)$chkStmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        // Fallback static whitelist if table unavailable
        $validStatus = in_array($filterStatus, ['planning','in_progress','on_hold','completed','cancelled','not_started'], true);
    }
    if (!$validStatus) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit;
    }
}

// Build WHERE
if (!empty($filterStatus)) {
    $fpWhere       = "(p.status = ? OR (? = 'not_started' AND (p.status IS NULL OR p.status = '' OR p.status = 'not_started')))";
    $fpParams      = [$filterStatus, $filterStatus];
    $fpCountParams = [$filterStatus, $filterStatus];
} else {
    $fpWhere       = "1=1";
    $fpParams      = [];
    $fpCountParams = [];
}
if ($projectId > 0) {
    $fpWhere .= " AND p.id = ?";
    $fpParams[]      = $projectId;
    $fpCountParams[] = $projectId;
}

$cacheTtl = 30;
$cacheKey = '';
if (function_exists('apcu_fetch')) {
    $cacheKey = 'rp:' . md5(json_encode([
        'uid' => (int)($_SESSION['user_id'] ?? 0),
        'role' => (string)($_SESSION['role'] ?? ''),
        'sd' => $startDate,
        'ed' => $endDate,
        'pid' => $projectId,
        'st' => $filterStatus,
        'p' => $fpPage,
        'pp' => $fpPerPage
    ]));
    $cached = apcu_fetch($cacheKey, $hit);
    if ($hit && is_string($cached)) {
        echo $cached;
        exit;
    }
}

try {
    $db = Database::getInstance();

    $fpCountStmt = $db->prepare("SELECT COUNT(*) FROM projects p WHERE $fpWhere");
    $fpCountStmt->execute($fpCountParams);
    $fpTotalCount = (int)$fpCountStmt->fetchColumn();

    $fpStmt = $db->prepare("
        SELECT p.id, p.title, p.po_number, p.status, p.created_at,
               c.name as client_name,
               u.full_name as lead_name
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        LEFT JOIN users u ON p.project_lead_id = u.id
        WHERE $fpWhere
        ORDER BY p.title ASC
        LIMIT $fpPerPage OFFSET $fpOffset
    ");
    $fpStmt->execute($fpParams);
    $projects = $fpStmt->fetchAll(PDO::FETCH_ASSOC);

    $response = json_encode([
        'success'     => true,
        'projects'    => $projects,
        'total'       => $fpTotalCount,
        'page'        => $fpPage,
        'per_page'    => $fpPerPage,
        'total_pages' => $fpTotalCount > 0 ? (int)ceil($fpTotalCount / $fpPerPage) : 1,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($cacheKey !== '' && function_exists('apcu_store') && is_string($response)) {
        apcu_store($cacheKey, $response, $cacheTtl);
    }

    echo $response;

} catch (Throwable $e) {
    error_log("report_projects API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
