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
$type = $_GET['type'] ?? 'tester';
if (!in_array($type, ['tester', 'qa'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid type']);
    exit;
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');

// Validate date format YYYY-MM-DD
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

$projectId = (int)($_GET['project_id'] ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 10;
$offset    = ($page - 1) * $perPage;
$dateEnd   = $endDate . ' 23:59:59';

$cacheTtl = 30;
$cacheKey = '';
if (function_exists('apcu_fetch')) {
    $cacheKey = 'rperf:' . md5(json_encode([
        'uid' => (int)($_SESSION['user_id'] ?? 0),
        'role' => (string)($_SESSION['role'] ?? ''),
        'type' => $type,
        'start' => $startDate,
        'end' => $endDate,
        'pid' => $projectId,
        'page' => $page,
        'pp' => $perPage,
    ]));
    $cached = apcu_fetch($cacheKey, $hit);
    if ($hit && is_string($cached)) {
        echo $cached;
        exit;
    }
}

try {
    $db = Database::getInstance();

    $projectFilter = $projectId > 0 ? " AND ptl.project_id = ?" : '';

    if ($type === 'tester') {
        $roleWhere = "u.role IN ('at_tester', 'ft_tester')";

        $countParams = [$startDate, $dateEnd];
        if ($projectId > 0) $countParams[] = $projectId;

        $countStmt = $db->prepare("
            SELECT COUNT(DISTINCT u.id)
            FROM users u
            LEFT JOIN project_time_logs ptl
                ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $projectFilter
            WHERE $roleWhere AND u.is_active = 1
        ");
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();

        $dataParams = [$startDate, $dateEnd, $startDate, $dateEnd];
        if ($projectId > 0) $dataParams[] = $projectId;

        $dataStmt = $db->prepare("
            SELECT u.id, u.full_name, u.role,
                COUNT(DISTINCT ptl.project_id) as pages_tested,
                COALESCE(SUM(ptl.hours_spent), 0) as total_hours,
                COALESCE(ic.total_issues, 0) as total_issues
            FROM users u
            LEFT JOIN project_time_logs ptl
                ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $projectFilter
            LEFT JOIN (
                SELECT reporter_id, COUNT(*) AS total_issues
                FROM issues
                WHERE created_at BETWEEN ? AND ?
                GROUP BY reporter_id
            ) ic ON ic.reporter_id = u.id
            WHERE $roleWhere AND u.is_active = 1
            GROUP BY u.id
            ORDER BY total_hours DESC
            LIMIT $perPage OFFSET $offset
        ");
        $dataStmt->execute($dataParams);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $roleWhere = "u.role = 'qa'";

        $countParams = [$startDate, $dateEnd];
        if ($projectId > 0) $countParams[] = $projectId;

        $countStmt = $db->prepare("
            SELECT COUNT(DISTINCT u.id)
            FROM users u
            LEFT JOIN project_time_logs ptl
                ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $projectFilter
            WHERE $roleWhere AND u.is_active = 1
        ");
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();

        $dataParams = [$startDate, $dateEnd, $startDate, $dateEnd];
        if ($projectId > 0) $dataParams[] = $projectId;

        $dataStmt = $db->prepare("
            SELECT u.id, u.full_name,
                COUNT(DISTINCT ptl.project_id) as pages_reviewed,
                COALESCE(SUM(ptl.hours_spent), 0) as total_hours,
                COALESCE(ic.total_issues, 0) as total_issues
            FROM users u
            LEFT JOIN project_time_logs ptl
                ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $projectFilter
            LEFT JOIN (
                SELECT reporter_id, COUNT(*) AS total_issues
                FROM issues
                WHERE created_at BETWEEN ? AND ?
                GROUP BY reporter_id
            ) ic ON ic.reporter_id = u.id
            WHERE $roleWhere AND u.is_active = 1
            GROUP BY u.id
            ORDER BY total_hours DESC
            LIMIT $perPage OFFSET $offset
        ");
        $dataStmt->execute($dataParams);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $response = json_encode([
        'success'     => true,
        'type'        => $type,
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($cacheKey !== '' && function_exists('apcu_store') && is_string($response)) {
        apcu_store($cacheKey, $response, $cacheTtl);
    }

    echo $response;

} catch (Throwable $e) {
    error_log("report_performance API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
