<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
ob_end_clean();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=20, stale-while-revalidate=20');

$auth = new Auth();
if (!$auth->isLoggedIn() || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'project_lead'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$projectId    = (int)($_GET['project_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');

// Validate status dynamically from DB
if ($filterStatus !== '') {
    try {
        $dbChk = Database::getInstance();
        $chk   = $dbChk->prepare("SELECT COUNT(*) FROM project_statuses WHERE status_key = ?");
        $chk->execute([$filterStatus]);
        if ((int)$chk->fetchColumn() === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid status']);
            exit;
        }
    } catch (Throwable $e) {
        // allow through if table unavailable
    }
}

$cacheTtl = 45;
$cacheKey = '';
if (function_exists('apcu_fetch')) {
    $cacheKey = 'rpbt:' . md5(json_encode([
        'uid' => (int)($_SESSION['user_id'] ?? 0),
        'role' => (string)($_SESSION['role'] ?? ''),
        'pid' => $projectId,
        'st' => $filterStatus
    ]));
    $cached = apcu_fetch($cacheKey, $hit);
    if ($hit && is_string($cached)) {
        echo $cached;
        exit;
    }
}

try {
    $db = Database::getInstance();

    // No date filter — match stat cards which show all projects
    $whereType = "1=1";
    $params    = [];

    if ($projectId > 0) {
        $whereType .= " AND p.id = ?";
        $params[]   = $projectId;
    }
    if ($filterStatus !== '') {
        $whereType .= " AND (p.status = ? OR (? = 'not_started' AND (p.status IS NULL OR p.status = '' OR p.status = 'not_started')))";
        $params[]   = $filterStatus;
        $params[]   = $filterStatus;
    }

    $stmt = $db->prepare("
        SELECT p.id, p.project_type, p.title, p.po_number AS code, p.status, c.name AS client
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        WHERE $whereType
        ORDER BY p.project_type, p.title
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by type
    $map = [];
    foreach ($rows as $p) {
        $type = $p['project_type'] ?: 'N/A';
        if (!isset($map[$type])) {
            $map[$type] = ['project_type' => $type, 'total' => 0, 'completed' => 0, 'projects_list' => []];
        }
        $map[$type]['total']++;
        if ($p['status'] === 'completed') $map[$type]['completed']++;
        $map[$type]['projects_list'][] = [
            'id'     => $p['id'],
            'title'  => $p['title'],
            'code'   => $p['code'],
            'status' => $p['status'],
            'client' => $p['client'],
        ];
    }

    $result = [];
    foreach ($map as &$t) {
        $t['completion_rate'] = ($t['total'] > 0 && $filterStatus === '')
            ? round(($t['completed'] * 100.0) / $t['total'], 2)
            : null;
        $result[] = $t;
    }
    unset($t);

    // Sort by project_type
    usort($result, fn($a, $b) => strcmp($a['project_type'], $b['project_type']));

    $response = json_encode(['success' => true, 'data' => $result, 'filter_status' => $filterStatus], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($cacheKey !== '' && function_exists('apcu_store') && is_string($response)) {
        apcu_store($cacheKey, $response, $cacheTtl);
    }

    echo $response;

} catch (Throwable $e) {
    error_log("report_projects_by_type Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
