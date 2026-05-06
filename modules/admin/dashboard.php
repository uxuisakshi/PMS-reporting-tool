<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$projectManager = new ProjectManager();
$baseDir = getBaseDir();
$devicesApiUrl = $baseDir . '/api/devices.php';

function dashboardColumnExists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function loadPendingDashboardData(PDO $db, $baseDir)
{
    $pendingBuckets = [];
    $pendingFeed = [];
    $pendingTotalCount = 0;

    try {
        $devicePendingCount = (int) $db->query("SELECT COUNT(*) FROM device_switch_requests WHERE status = 'Pending'")->fetchColumn();
        $devicePendingRows = $db->query("
            SELECT dsr.id, dsr.requested_at, d.device_name, d.device_type, u.full_name AS requester_name
            FROM device_switch_requests dsr
            JOIN devices d ON d.id = dsr.device_id
            JOIN users u ON u.id = dsr.requested_by
            WHERE dsr.status = 'Pending'
            ORDER BY dsr.requested_at DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $pendingBuckets[] = [
            'key' => 'device',
            'label' => 'Device Requests',
            'count' => $devicePendingCount,
            'link' => $baseDir . '/modules/admin/devices.php',
            'items' => $devicePendingRows
        ];
        foreach ($devicePendingRows as $row) {
            $pendingFeed[] = [
                'type' => 'Device',
                'title' => trim((string) $row['device_name']) . ' (' . trim((string) $row['device_type']) . ')',
                'user' => (string) ($row['requester_name'] ?? 'Unknown'),
                'requested_at' => (string) ($row['requested_at'] ?? ''),
                'link' => $baseDir . '/modules/admin/devices.php',
                'action_kind' => 'device',
                'request_id' => (int) ($row['id'] ?? 0)
            ];
        }
        $pendingTotalCount += $devicePendingCount;
    } catch (Exception $e) {
        error_log('dashboard pending device requests load failed: ' . $e->getMessage());
    }

    try {
        $hoursPendingCount = (int) $db->query("SELECT COUNT(*) FROM user_edit_requests WHERE status = 'pending'")->fetchColumn();
        $hoursPendingRows = $db->query("
            SELECT uer.id, uer.user_id, uer.req_date, uer.request_type, uer.created_at, u.full_name AS requester_name
            FROM user_edit_requests uer
            JOIN users u ON u.id = uer.user_id
            WHERE uer.status = 'pending'
            ORDER BY uer.created_at DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $pendingBuckets[] = [
            'key' => 'hours',
            'label' => 'Hours Log Requests',
            'count' => $hoursPendingCount,
            'link' => $baseDir . '/modules/admin/edit_requests.php',
            'items' => $hoursPendingRows
        ];
        foreach ($hoursPendingRows as $row) {
            $requestType = strtolower(trim((string) ($row['request_type'] ?? 'edit'))) === 'delete' ? 'Delete' : 'Edit';
            $pendingFeed[] = [
                'type' => 'Hours',
                'title' => $requestType . ' request for ' . (string) ($row['req_date'] ?? '-'),
                'user' => (string) ($row['requester_name'] ?? 'Unknown'),
                'requested_at' => (string) ($row['created_at'] ?? ''),
                'link' => $baseDir . '/modules/admin/edit_requests.php',
                'action_kind' => 'hours',
                'request_id' => (int) ($row['id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'req_date' => (string) ($row['req_date'] ?? '')
            ];
        }
        $pendingTotalCount += $hoursPendingCount;
    } catch (Exception $e) {
        error_log('dashboard pending hours requests load failed: ' . $e->getMessage());
    }

    try {
        $pendingEditsCount = (int) $db->query("SELECT COUNT(*) FROM user_pending_log_edits WHERE status = 'pending'")->fetchColumn();
        $pendingBuckets[] = [
            'key' => 'log_edits',
            'label' => 'Pending Log Edit Items',
            'count' => $pendingEditsCount,
            'link' => $baseDir . '/modules/admin/edit_requests.php',
            'items' => []
        ];
        $pendingTotalCount += $pendingEditsCount;
    } catch (Exception $e) {
        error_log('dashboard pending log edits load failed: ' . $e->getMessage());
    }

    try {
        $pendingDeletesCount = (int) $db->query("SELECT COUNT(*) FROM user_pending_log_deletions WHERE status = 'pending'")->fetchColumn();
        $pendingBuckets[] = [
            'key' => 'log_deletes',
            'label' => 'Pending Log Delete Items',
            'count' => $pendingDeletesCount,
            'link' => $baseDir . '/modules/admin/edit_requests.php',
            'items' => []
        ];
        $pendingTotalCount += $pendingDeletesCount;
    } catch (Exception $e) {
        error_log('dashboard pending log deletions load failed: ' . $e->getMessage());
    }

    usort($pendingFeed, static function (array $a, array $b): int {
        return strtotime((string) ($b['requested_at'] ?? '')) <=> strtotime((string) ($a['requested_at'] ?? ''));
    });
    $pendingFeed = array_slice($pendingFeed, 0, 8);

    return [
        'pendingBuckets' => $pendingBuckets,
        'pendingFeed' => $pendingFeed,
        'pendingTotalCount' => $pendingTotalCount
    ];
}

$pendingData = loadPendingDashboardData($db, $baseDir);
$pendingBuckets = $pendingData['pendingBuckets'];
$pendingFeed = $pendingData['pendingFeed'];
$pendingTotalCount = (int) $pendingData['pendingTotalCount'];

if (isset($_GET['action']) && $_GET['action'] === 'pending_requests_summary') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode([
        'success' => true,
        'pendingBuckets' => $pendingBuckets,
        'pendingFeed' => $pendingFeed,
        'pendingTotalCount' => $pendingTotalCount
    ]);
    exit;
}
$myDevicesStmt = $db->prepare("
    SELECT d.device_name, d.device_type, d.model, d.version, da.assigned_at
    FROM device_assignments da
    JOIN devices d ON d.id = da.device_id
    WHERE da.user_id = ? AND da.status = 'Active'
    ORDER BY da.assigned_at DESC
");
$myDevicesStmt->execute([$userId]);
$myDevices = $myDevicesStmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH DATA FOR REPORTS SECTION ---
$repoUsers = $db->query("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role IN ('project_lead', 'qa', 'at_tester', 'ft_tester') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$repoProjects = $db->query("SELECT id, title, po_number FROM projects WHERE status NOT IN ('completed', 'cancelled') ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);

// Get project statuses from status master (same source used in project create/edit flows)
$projectStatusOptions = getStatusOptions('project');
if (empty($projectStatusOptions)) {
    $projectStatusOptions = [
        ['status_key' => 'planning', 'status_label' => 'Planning'],
        ['status_key' => 'in_progress', 'status_label' => 'In Progress'],
        ['status_key' => 'on_hold', 'status_label' => 'On Hold'],
        ['status_key' => 'completed', 'status_label' => 'Completed'],
        ['status_key' => 'cancelled', 'status_label' => 'Cancelled'],
    ];
}

// Count projects by status
$statusRows = $db->query("
    SELECT COALESCE(NULLIF(TRIM(status), ''), 'not_started') AS status_key, COUNT(*) AS total
    FROM projects
    GROUP BY COALESCE(NULLIF(TRIM(status), ''), 'not_started')
")->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = [];
foreach ($statusRows as $row) {
    $statusCounts[(string) $row['status_key']] = (int) $row['total'];
}

// Keep stats shape for existing references
$stats = [
    'total_projects' => (int) $db->query("SELECT COUNT(*) FROM projects")->fetchColumn()
];

// Get recent projects (include current phase if available)
$recentProjects = $db->query(
    "SELECT p.*, c.name as client_name, u.full_name as lead_name, p.project_lead_id,
        (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) as current_phase
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN users u ON p.project_lead_id = u.id
    ORDER BY p.created_at DESC
    LIMIT 5"
)->fetchAll();

// Handle filters for resource workload
$roleFilter = $_GET['role_filter'] ?? '';
$workloadFilter = $_GET['workload_filter'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'name';

// Build WHERE clause for filters
$whereConditions = ["u.is_active = 1"];
$params = [];

if ($roleFilter && $roleFilter !== 'all') {
    $whereConditions[] = "u.role = ?";
    $params[] = $roleFilter;
} else {
    $whereConditions[] = "u.role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
}

$whereClause = implode(' AND ', $whereConditions);

// Build ORDER BY clause
$orderBy = match ($sortBy) {
    'role' => 'u.role, u.full_name',
    'projects' => 'active_projects DESC, u.full_name',
    'hours' => 'total_hours DESC, u.full_name',
    'pages' => 'assigned_pages DESC, u.full_name',
    default => 'u.full_name'
};

// Get resource workload with enhanced data
$sql = "
    SELECT
        u.id,
        u.full_name,
        u.role,
        u.email,
        (SELECT COUNT(DISTINCT p2.id) 
         FROM project_pages pp2 
         JOIN projects p2 ON pp2.project_id = p2.id 
          WHERE (pp2.at_tester_id = u.id OR pp2.ft_tester_id = u.id OR pp2.qa_id = u.id
              OR (pp2.at_tester_ids IS NOT NULL AND JSON_VALID(pp2.at_tester_ids) AND JSON_CONTAINS(pp2.at_tester_ids, JSON_ARRAY(u.id)))
              OR (pp2.ft_tester_ids IS NOT NULL AND JSON_VALID(pp2.ft_tester_ids) AND JSON_CONTAINS(pp2.ft_tester_ids, JSON_ARRAY(u.id))))
         AND p2.status NOT IN ('completed', 'cancelled')) as active_projects,
        (SELECT COUNT(DISTINCT pp3.id) 
         FROM project_pages pp3 
          WHERE (pp3.at_tester_id = u.id OR pp3.ft_tester_id = u.id OR pp3.qa_id = u.id
              OR (pp3.at_tester_ids IS NOT NULL AND JSON_VALID(pp3.at_tester_ids) AND JSON_CONTAINS(pp3.at_tester_ids, JSON_ARRAY(u.id)))
              OR (pp3.ft_tester_ids IS NOT NULL AND JSON_VALID(pp3.ft_tester_ids) AND JSON_CONTAINS(pp3.ft_tester_ids, JSON_ARRAY(u.id))))) as assigned_pages,
        (SELECT COUNT(DISTINCT p3.id) 
         FROM project_pages pp4 
         JOIN projects p3 ON pp4.project_id = p3.id 
          WHERE (pp4.at_tester_id = u.id OR pp4.ft_tester_id = u.id OR pp4.qa_id = u.id
              OR (pp4.at_tester_ids IS NOT NULL AND JSON_VALID(pp4.at_tester_ids) AND JSON_CONTAINS(pp4.at_tester_ids, JSON_ARRAY(u.id)))
              OR (pp4.ft_tester_ids IS NOT NULL AND JSON_VALID(pp4.ft_tester_ids) AND JSON_CONTAINS(pp4.ft_tester_ids, JSON_ARRAY(u.id))))
         AND p3.status = 'in_progress') as in_progress_projects,
        (SELECT COUNT(DISTINCT p4.id) 
         FROM project_pages pp5 
         JOIN projects p4 ON pp5.project_id = p4.id 
          WHERE (pp5.at_tester_id = u.id OR pp5.ft_tester_id = u.id OR pp5.qa_id = u.id
              OR (pp5.at_tester_ids IS NOT NULL AND JSON_VALID(pp5.at_tester_ids) AND JSON_CONTAINS(pp5.at_tester_ids, JSON_ARRAY(u.id)))
              OR (pp5.ft_tester_ids IS NOT NULL AND JSON_VALID(pp5.ft_tester_ids) AND JSON_CONTAINS(pp5.ft_tester_ids, JSON_ARRAY(u.id))))
         AND p4.priority = 'critical') as critical_projects,
        (SELECT COALESCE(SUM(hours_spent), 0) FROM testing_results tr WHERE tr.tester_id = u.id AND DATE(tr.tested_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as hours_last_30_days,
        (SELECT COALESCE(SUM(hours_spent), 0) FROM testing_results tr WHERE tr.tester_id = u.id) as total_hours,
        (SELECT COUNT(*) FROM project_time_logs ptl WHERE ptl.user_id = u.id AND DATE(ptl.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as recent_activity
    FROM users u
    WHERE $whereClause
    ORDER BY $orderBy
";

$workload = [];
try {
    if (!empty($params)) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $workload = $stmt->fetchAll();
    } else {
        $workload = $db->query($sql)->fetchAll();
    }
} catch (Exception $e) {
    error_log('dashboard workload query failed: ' . $e->getMessage());
    $workload = [];
}

// Apply workload filter after query
if ($workloadFilter && $workloadFilter !== 'all') {
    $workload = array_filter($workload, function ($resource) use ($workloadFilter) {
        return match ($workloadFilter) {
            'overloaded' => $resource['active_projects'] > 5,
            'busy' => $resource['active_projects'] >= 3 && $resource['active_projects'] <= 5,
            'available' => $resource['active_projects'] < 3,
            'inactive' => $resource['recent_activity'] == 0,
            default => true
        };
    });
}

$workload = array_values($workload);
$workloadUserIds = array_values(array_filter(array_map(static function ($row) {
    return (int) ($row['id'] ?? 0);
}, $workload)));
$allocatedHoursByUser = [];
if (!empty($workloadUserIds)) {
    $ph = implode(',', array_fill(0, count($workloadUserIds), '?'));
    $allocStmt = $db->prepare("
        SELECT ua.user_id, COALESCE(SUM(ua.hours_allocated), 0) AS allocated
        FROM user_assignments ua
        JOIN projects p ON ua.project_id = p.id
        WHERE ua.user_id IN ($ph)
          AND p.status NOT IN ('completed', 'cancelled')
        GROUP BY ua.user_id
    ");
    $allocStmt->execute($workloadUserIds);
    while ($allocRow = $allocStmt->fetch(PDO::FETCH_ASSOC)) {
        $allocatedHoursByUser[(int) $allocRow['user_id']] = (float) $allocRow['allocated'];
    }
}

foreach ($workload as &$resourceRow) {
    $rid = (int) ($resourceRow['id'] ?? 0);
    $resourceRow['allocated_hours'] = (float) ($allocatedHoursByUser[$rid] ?? 0);
}
unset($resourceRow);

$hoursByUserProject = [];
if (!empty($workloadUserIds)) {
    $ph = implode(',', array_fill(0, count($workloadUserIds), '?'));
    $hoursStmt = $db->prepare("\n        SELECT user_id, project_id, COALESCE(SUM(hours_spent), 0) AS total_hours\n        FROM project_time_logs\n        WHERE user_id IN ($ph)\n        GROUP BY user_id, project_id\n    ");
    $hoursStmt->execute($workloadUserIds);
    while ($hrow = $hoursStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = (int) $hrow['user_id'] . ':' . (int) $hrow['project_id'];
        $hoursByUserProject[$key] = (float) $hrow['total_hours'];
    }
}

$issuesByUserProject = [];
if (!empty($workloadUserIds)) {
    $ph = implode(',', array_fill(0, count($workloadUserIds), '?'));
    $issuesStmt = $db->prepare("\n        SELECT reporter_id AS user_id, project_id, COUNT(*) AS total_issues\n        FROM issues\n        WHERE reporter_id IN ($ph)\n        GROUP BY reporter_id, project_id\n    ");
    $issuesStmt->execute($workloadUserIds);
    while ($irow = $issuesStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = (int) $irow['user_id'] . ':' . (int) $irow['project_id'];
        $issuesByUserProject[$key] = (int) $irow['total_issues'];
    }
}

$resourceProjectOrderBy = dashboardColumnExists($db, 'projects', 'updated_at')
    ? 'ORDER BY p.updated_at DESC, p.id DESC'
    : 'ORDER BY p.id DESC';

// BATCH FETCH ALL PROJECT ASSIGNMENTS for the found users
$workloadProjectDetails = [];
if (!empty($workloadUserIds)) {
    $ph = implode(',', array_fill(0, count($workloadUserIds), '?'));
    $batchProjectSql = "
        SELECT
            p.id AS project_id,
            p.project_code,
            p.po_number,
            p.title AS project_title,
            COALESCE(NULLIF(TRIM(p.status), ''), 'not_started') AS project_status,
            COUNT(DISTINCT pp.id) AS assigned_pages,
            u.id as user_id
        FROM project_pages pp
        INNER JOIN projects p ON p.id = pp.project_id
        INNER JOIN users u ON (pp.at_tester_id = u.id OR pp.ft_tester_id = u.id OR pp.qa_id = u.id 
            OR (pp.at_tester_ids IS NOT NULL AND JSON_VALID(pp.at_tester_ids) AND JSON_CONTAINS(pp.at_tester_ids, JSON_ARRAY(u.id)))
            OR (pp.ft_tester_ids IS NOT NULL AND JSON_VALID(pp.ft_tester_ids) AND JSON_CONTAINS(pp.ft_tester_ids, JSON_ARRAY(u.id))))
        WHERE u.id IN ($ph)
        GROUP BY u.id, p.id, p.project_code, p.po_number, p.title, p.status
    ";
    
    // Using a simpler order for the batch query
    $batchProjectSql .= " ORDER BY u.id, p.id DESC";
    
    $batchStmt = $db->prepare($batchProjectSql);
    $batchStmt->execute($workloadUserIds);
    
    while ($pRow = $batchStmt->fetch(PDO::FETCH_ASSOC)) {
        $uId = (int)$pRow['user_id'];
        $projectId = (int)$pRow['project_id'];
        $statusKey = (string)$pRow['project_status'];
        $metricKey = $uId . ':' . $projectId;

        $projectCode = trim((string)($pRow['project_code'] ?: ($pRow['po_number'] ?: 'PRJ-' . $projectId)));

        $workloadProjectDetails[$uId][] = [
            'project_id' => $projectId,
            'project_code' => $projectCode,
            'project_title' => (string)$pRow['project_title'],
            'status_key' => $statusKey,
            'status_label' => formatProjectStatusLabel($statusKey),
            'status_badge' => projectStatusBadgeClass($statusKey),
            'assigned_pages' => (int)$pRow['assigned_pages'],
            'hours_worked' => (float)($hoursByUserProject[$metricKey] ?? 0),
            'issues_reported' => (int)($issuesByUserProject[$metricKey] ?? 0),
        ];
    }
}

// Update workload rows from our map
foreach ($workload as &$resourceRow) {
    $rid = (int) ($resourceRow['id'] ?? 0);
    $details = $workloadProjectDetails[$rid] ?? [];
    
    $resourceRow['project_details'] = $details;
    $resourceRow['project_count'] = count($details);
    if (!empty($details)) {
        $summaryRows = array_slice($details, 0, 4);
        $summary = array_map(static function ($item) {
            return $item['project_code'] . ' - ' . $item['status_label'];
        }, $summaryRows);
        if (count($details) > 4) {
            $summary[] = '+' . (count($details) - 4) . ' more';
        }
        $resourceRow['project_summary'] = implode(' | ', $summary);
    } else {
        $resourceRow['project_summary'] = 'No assigned projects';
    }
}
unset($resourceRow);

$workloadCount = count($workload);
$avgProjects = $workloadCount > 0 ? round(array_sum(array_column($workload, 'active_projects')) / $workloadCount, 1) : 0;
$overloaded = count(array_filter($workload, fn($r) => (int) $r['active_projects'] > 5));
$busy = count(array_filter($workload, fn($r) => (int) $r['active_projects'] >= 3 && (int) $r['active_projects'] <= 5));
$available = count(array_filter($workload, fn($r) => (int) $r['active_projects'] < 3));
$inactive = count(array_filter($workload, fn($r) => (int) $r['recent_activity'] === 0));
$total = $workloadCount;

// --- FETCH AVAILABILITY STATUS DATA ---
$todayDate = date('Y-m-d');
$userStatusQuery = "
    SELECT 
        u.id, 
        u.full_name, 
        u.role, 
        uds.status AS status_key, 
        uds.notes,
        asm.status_label,
        asm.badge_color,
        asm.display_order,
        login_today.latest_login_at,
        COALESCE(off_prod.off_production_hours, 0) AS off_production_hours
    FROM users u
    LEFT JOIN user_daily_status uds ON u.id = uds.user_id AND uds.status_date = ?
    LEFT JOIN availability_status_master asm ON (uds.status COLLATE utf8mb4_unicode_ci) = asm.status_key
    LEFT JOIN (
        SELECT user_id, MAX(created_at) AS latest_login_at
        FROM activity_log
        WHERE action IN ('login', 'login_2fa')
          AND DATE(created_at) = ?
        GROUP BY user_id
    ) login_today ON login_today.user_id = u.id
    LEFT JOIN (
        SELECT ptl.user_id, SUM(ptl.hours_spent) AS off_production_hours
        FROM project_time_logs ptl
        INNER JOIN projects p ON p.id = ptl.project_id
        WHERE ptl.log_date = ?
          AND (p.project_code = 'OFF-PROD-001' OR p.title LIKE 'Off-Production / Bench%')
        GROUP BY ptl.user_id
    ) off_prod ON off_prod.user_id = u.id
    WHERE u.is_active = 1 AND u.role NOT IN ('client', 'admin')
    ORDER BY CASE WHEN uds.status IS NULL OR uds.status = 'not_updated' THEN 1 ELSE 0 END, asm.display_order ASC, u.full_name ASC
";
$availUserStatusList = [];
try {
    $stmt = $db->prepare($userStatusQuery);
    $stmt->execute([$todayDate, $todayDate, $todayDate]);
    $availUserStatusList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('dashboard user status query failed: ' . $e->getMessage());
}

// Fetch all available status options for the filter
$availableStatusOptions = [];
try {
    $stmt = $db->query("SELECT status_key, status_label, badge_color FROM availability_status_master WHERE is_active = 1 ORDER BY display_order ASC");
    $availableStatusOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $availableStatusOptions = [];
}

$availabilityStatusMeta = [];
foreach ($availableStatusOptions as $statusOption) {
    $statusKey = trim((string) ($statusOption['status_key'] ?? ''));
    if ($statusKey === '') {
        continue;
    }
    $availabilityStatusMeta[$statusKey] = [
        'status_label' => (string) ($statusOption['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))),
        'badge_color' => (string) ($statusOption['badge_color'] ?? 'secondary'),
    ];
}
if (!isset($availabilityStatusMeta['not_updated'])) {
    $availabilityStatusMeta['not_updated'] = [
        'status_label' => 'Not Updated',
        'badge_color' => 'secondary',
    ];
}

$statusSummaryCounts = [];
$loggedInTodayCount = 0;
$notUpdatedLoggedInCount = 0;
$offProductionCount = 0;

foreach ($availUserStatusList as &$statusRow) {
    $statusKey = trim((string) ($statusRow['status_key'] ?? ''));
    if ($statusKey === '' || $statusKey === 'not_updated') {
        $statusKey = 'not_updated';
    }

    $statusRow['status_key'] = $statusKey;
    $statusRow['status_label'] = (string) ($availabilityStatusMeta[$statusKey]['status_label'] ?? 'Not Updated');
    $statusRow['badge_color'] = (string) ($availabilityStatusMeta[$statusKey]['badge_color'] ?? 'secondary');

    $statusSummaryCounts[$statusKey] = ($statusSummaryCounts[$statusKey] ?? 0) + 1;

    $activityParts = [];
    $notesText = trim((string) ($statusRow['notes'] ?? ''));
    if ($notesText !== '') {
        $activityParts[] = $notesText;
    }

    $offProductionHours = (float) ($statusRow['off_production_hours'] ?? 0);
    if ($offProductionHours > 0) {
        $offProductionCount++;
        $activityParts[] = 'Off-production logged today: ' . number_format($offProductionHours, 1) . 'h';
    }

    $latestLoginAt = trim((string) ($statusRow['latest_login_at'] ?? ''));
    if ($latestLoginAt !== '') {
        $loggedInTodayCount++;
        $activityParts[] = 'Logged in today at ' . date('g:i A', strtotime($latestLoginAt));
        if ($statusKey === 'not_updated') {
            $notUpdatedLoggedInCount++;
        }
    }

    $statusRow['activity_note'] = implode("\n", $activityParts);
}
unset($statusRow);

$hasOffProductionStatusSummary = isset($statusSummaryCounts['off_production']);


include __DIR__ . '/../../includes/header.php';
?>
<style>
    .dashboard-no-page-overflow {
        overflow-x: clip;
    }

    .dashboard-no-page-overflow .table-responsive {
        max-width: 100%;
        max-height: 420px;
        overflow-x: auto;
        overflow-y: auto;
    }

    .dashboard-no-page-overflow .list-group {
        max-height: 420px;
        overflow-y: auto;
    }
</style>
<div class="container-fluid dashboard-no-page-overflow">
    <div class="d-flex justify-content-between align-items-center mb-2">
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="<?php echo $baseDir; ?>/modules/admin/bulk_hours_management.php"
            class="btn btn-outline-primary btn-sm">Bulk Hours Management</a>
        <a href="<?php echo $baseDir; ?>/modules/admin/resource_workload.php"
            class="btn btn-outline-secondary btn-sm">Resource Workload</a>
        <a href="<?php echo $baseDir; ?>/modules/admin/calendar.php" class="btn btn-outline-secondary btn-sm">Users
            Calendar</a>
    </div>

    <div class="card mb-3 border-warning">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-inbox"></i> Pending Requests (All Modules)</h6>
            <span class="badge bg-warning text-dark" id="pendingTotalBadge"><?php echo (int) $pendingTotalCount; ?>
                pending</span>
        </div>
        <div class="card-body" id="pendingRequestsContent">
            <?php if ((int) $pendingTotalCount === 0): ?>
                <span class="text-muted">No pending requests right now.</span>
            <?php else: ?>
                <div class="row g-2 mb-3">
                    <?php foreach ($pendingBuckets as $bucket): ?>
                        <?php if ((int) ($bucket['count'] ?? 0) <= 0)
                            continue; ?>
                        <div class="col-sm-6 col-xl-3">
                            <a href="<?php echo htmlspecialchars((string) $bucket['link']); ?>" class="text-decoration-none">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted"><?php echo htmlspecialchars((string) $bucket['label']); ?>
                                    </div>
                                    <div class="h5 mb-0 text-dark"><?php echo (int) $bucket['count']; ?></div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($pendingFeed)): ?>
                    <h6 class="mb-2">Latest Pending Requests</h6>
                    <div class="list-group">
                        <?php foreach ($pendingFeed as $feed): ?>
                            <div class="list-group-item" data-pending-item="1"
                                data-action-kind="<?php echo htmlspecialchars((string) ($feed['action_kind'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-request-id="<?php echo (int) ($feed['request_id'] ?? 0); ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span
                                            class="badge bg-secondary me-2"><?php echo htmlspecialchars((string) $feed['type']); ?></span>
                                        <strong><?php echo htmlspecialchars((string) $feed['title']); ?></strong>
                                        <div class="small text-muted">Requested by
                                            <?php echo htmlspecialchars((string) $feed['user']); ?></div>
                                    </div>
                                    <small
                                        class="text-muted"><?php echo !empty($feed['requested_at']) ? date('M d, H:i', strtotime((string) $feed['requested_at'])) : '-'; ?></small>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php if (($feed['action_kind'] ?? '') === 'device' && (int) ($feed['request_id'] ?? 0) > 0): ?>
                                        <button type="button" class="btn btn-sm btn-success js-device-request"
                                            data-id="<?php echo (int) $feed['request_id']; ?>" data-action="approve">Accept</button>
                                        <button type="button" class="btn btn-sm btn-danger js-device-request"
                                            data-id="<?php echo (int) $feed['request_id']; ?>" data-action="reject">Reject</button>
                                        <a href="<?php echo htmlspecialchars((string) $feed['link']); ?>"
                                            class="btn btn-sm btn-outline-secondary">Open</a>
                                    <?php elseif (($feed['action_kind'] ?? '') === 'hours' && (int) ($feed['request_id'] ?? 0) > 0): ?>
                                        <button type="button" class="btn btn-sm btn-success js-hours-request"
                                            data-id="<?php echo (int) $feed['request_id']; ?>"
                                            data-user="<?php echo (int) ($feed['user_id'] ?? 0); ?>"
                                            data-date="<?php echo htmlspecialchars((string) ($feed['req_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-action="approved">Accept</button>
                                        <button type="button" class="btn btn-sm btn-danger js-hours-request"
                                            data-id="<?php echo (int) $feed['request_id']; ?>"
                                            data-user="<?php echo (int) ($feed['user_id'] ?? 0); ?>"
                                            data-date="<?php echo htmlspecialchars((string) ($feed['req_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-action="rejected">Reject</button>
                                        <a href="<?php echo htmlspecialchars((string) $feed['link']); ?>"
                                            class="btn btn-sm btn-outline-secondary">Open</a>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars((string) $feed['link']); ?>"
                                            class="btn btn-sm btn-outline-secondary">Open</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Today's Availability Status (Recovered & Enhanced) -->
    <div class="card mb-3 border-info shadow-sm rounded-3">
        <div
            class="card-header bg-info text-white d-flex flex-column flex-sm-row justify-content-between align-items-sm-center py-2 px-3 gap-2">
            <h6 class="mb-0 fw-bold" style="font-size: 0.95rem;">
                <i class="fas fa-user-clock me-2"></i>Today's Status (<?php echo date('M d'); ?>)
            </h6>
            <div class="d-flex align-items-center gap-2">
                <input type="text" id="statusSearch" class="form-control form-control-sm py-0 bg-white shadow-none"
                    placeholder="Search name..."
                    style="font-size: 0.75rem; width: 120px; height: 26px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.2);">
                <select id="statusFilter" class="form-select form-select-sm py-0 bg-white shadow-none"
                    style="font-size: 0.75rem; width: 120px; height: 26px; border-radius: 4px;">
                    <option value="all">All Statuses</option>
                    <option value="not_updated">Not Updated</option>
                    <?php foreach ($availableStatusOptions as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt['status_key']); ?>">
                            <?php echo htmlspecialchars($opt['status_label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <a href="<?php echo $baseDir; ?>/modules/admin/calendar.php"
                    class="btn btn-sm btn-light py-0 px-2 fw-medium"
                    style="font-size: 0.75rem; height: 26px; display: flex; align-items: center; border-radius: 4px;">Calendar</a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="px-3 pt-3 pb-2 border-bottom bg-light">
                <div class="d-flex flex-wrap gap-2 align-items-center" style="font-size: 0.76rem;">
                    <?php foreach ($statusSummaryCounts as $summaryStatusKey => $summaryCount): ?>
                        <?php $summaryMeta = $availabilityStatusMeta[$summaryStatusKey] ?? ['status_label' => ucwords(str_replace('_', ' ', $summaryStatusKey)), 'badge_color' => 'secondary']; ?>
                        <span class="badge bg-<?php echo htmlspecialchars((string) ($summaryMeta['badge_color'] ?? 'secondary')); ?> px-2 py-1">
                            <?php echo htmlspecialchars((string) ($summaryMeta['status_label'] ?? $summaryStatusKey)); ?>: <?php echo (int) $summaryCount; ?>
                        </span>
                    <?php endforeach; ?>
                    <span class="badge bg-dark-subtle text-dark border px-2 py-1">Logged in today: <?php echo (int) $loggedInTodayCount; ?></span>
                    <?php if (!$hasOffProductionStatusSummary && $offProductionCount > 0): ?>
                        <span class="badge bg-warning text-dark px-2 py-1">Logged off-production hours: <?php echo (int) $offProductionCount; ?></span>
                    <?php endif; ?>
                    <?php if ($notUpdatedLoggedInCount > 0): ?>
                        <span class="badge bg-secondary px-2 py-1">Not Updated after login: <?php echo (int) $notUpdatedLoggedInCount; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-sm table-hover mb-0" id="availStatusTable" style="font-size: 0.88rem;">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3 py-2 border-0">Resource Name</th>
                            <th class="py-2 border-0">Status</th>
                            <th class="py-2 border-0">Notes / Activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($availUserStatusList)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4 fst-italic">No active users found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($availUserStatusList as $us): ?>
                                <?php
                                $statusKey = $us['status_key'] ?? 'not_updated';
                                $statusLabel = $us['status_label'] ?? 'Not Updated';
                                $badgeColor = $us['badge_color'] ?? 'secondary';
                                if (empty($statusKey) || $statusKey === 'not_updated') {
                                    $statusKey = 'not_updated';
                                    $statusLabel = 'Not Updated';
                                    $badgeColor = 'secondary';
                                }
                                ?>
                                <tr class="align-middle status-row" data-status="<?php echo htmlspecialchars($statusKey); ?>"
                                    data-user-id="<?php echo (int) $us['id']; ?>">
                                    <td class="ps-3 py-2">
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($us['full_name']); ?></div>
                                        <div class="text-muted" style="font-size: 0.72rem;">
                                            <?php echo ucfirst(str_replace('_', ' ', $us['role'])); ?></div>
                                    </td>
                                    <td class="py-2">
                                        <span class="badge bg-<?php echo htmlspecialchars($badgeColor); ?> shadow-none"
                                            style="font-weight: 500; min-width: 85px; text-align: center; font-size: 0.75rem;">
                                            <?php echo htmlspecialchars($statusLabel); ?>
                                        </span>
                                    </td>
                                    <td class="py-2">
                                        <?php if (!empty($us['activity_note'])): ?>
                                            <div class="text-secondary text-wrap"
                                                style="max-width: 450px; line-height: 1.3; font-size: 0.82rem;">
                                                <?php echo nl2br(htmlspecialchars((string) $us['activity_note'])); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic small">No notes</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-light py-1 px-3 border-top-0">
            <div class="d-flex justify-content-between align-items-center" style="font-size: 0.72rem;">
                <span class="text-muted">Total Resources: <strong
                        class="text-dark"><?php echo count($availUserStatusList); ?></strong></span>
                <?php
                $updatedCount = count(array_filter($availUserStatusList, fn($u) => !empty($u['status_key']) && $u['status_key'] !== 'not_updated'));
                $pendingCount = count($availUserStatusList) - $updatedCount;
                ?>
                <span class="text-muted">
                    Updated: <span class="badge bg-success px-2 py-1"><?php echo $updatedCount; ?></span>
                    <?php if ($pendingCount > 0): ?>
                        <span class="mx-1">|</span>
                        Pending: <span class="badge bg-warning text-dark px-2 py-1"><?php echo $pendingCount; ?></span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- AI Resource Performance Insights Section (New) -->
    <div class="card mb-3 border-primary shadow-sm rounded-3">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-2 px-3">
            <h6 class="mb-0 fw-bold" style="font-size: 0.95rem;">
                <i class="fas fa-chart-line me-2"></i>Resource Performance Insights (AI Powered)
            </h6>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="d-flex align-items-center gap-1">
                    <span class="small text-white-50">Project:</span>
                    <input type="hidden" id="insightProjectFilter" value="">
                    <input type="text" id="insightProjectSearch" class="form-control form-control-sm py-0 bg-white" list="insightProjectOptions" placeholder="Overall / search project" style="font-size: 0.75rem; width: 200px; height: 26px;" value="Overall" autocomplete="off">
                    <datalist id="insightProjectOptions">
                        <option value="Overall" data-project-id=""></option>
                        <?php foreach ($repoProjects as $p): ?>
                            <option value="<?php echo htmlspecialchars((string) $p['title'], ENT_QUOTES, 'UTF-8'); ?>" data-project-id="<?php echo (int) $p['id']; ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <input type="date" id="insightStartDate" class="form-control form-control-sm py-0 bg-white" style="font-size: 0.75rem; width: 110px; height: 26px;" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                    <span class="small text-white-50">to</span>
                    <input type="date" id="insightEndDate" class="form-control form-control-sm py-0 bg-white" style="font-size: 0.75rem; width: 110px; height: 26px;" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <input type="text" id="insightSearch" class="form-control form-control-sm py-0 bg-white" placeholder="Search..." style="font-size: 0.75rem; width: 100px; height: 26px;">
                <button id="btnExportInsights" class="btn btn-sm btn-light py-0 fw-bold shadow-sm" style="font-size: 0.7rem; height: 26px;">
                    <i class="fas fa-download me-1"></i>Export
                </button>
            </div>
        </div>
        <div class="card-body border-bottom bg-light py-2 px-3">
            <div class="small text-muted d-flex flex-wrap gap-3 align-items-center">
                <span><i class="fas fa-robot me-1 text-primary"></i>Insights auto-queue in background.</span>
                <span><i class="fas fa-shield-alt me-1 text-success"></i>Processing is throttled and runs one resource at a time.</span>
                <span><i class="fas fa-eye me-1 text-info"></i>Admin only gets View Report actions.</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-sm table-hover mb-0" style="font-size: 0.88rem;">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3 py-2">Resource</th>
                            <th class="py-2">Accuracy</th>
                            <th class="py-2">Activity</th>
                            <th class="py-2 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="insightTableBody">
                        <!-- Dynamically Populated by JS -->
                        <tr><td colspan="4" class="text-center py-4 text-muted">Loading metrics...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-laptop"></i> My Assigned Devices</h6>
            <a href="<?php echo $baseDir; ?>/modules/devices.php" class="btn btn-sm btn-outline-primary">View
                Devices</a>
        </div>
        <div class="card-body py-2">
            <?php if (empty($myDevices)): ?>
                <span class="text-muted">No office device assigned.</span>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($myDevices as $dev): ?>
                        <span class="badge bg-light text-dark border">
                            <?php echo htmlspecialchars((string) $dev['device_name']); ?>
                            (<?php echo htmlspecialchars((string) $dev['device_type']); ?>)
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics Cards -->
    <!-- Statistics Cards -->
    <div class="row mb-4 g-3">
        <div class="col-md-6 col-xl-3">
            <a href="<?php echo $baseDir; ?>/modules/reports/dashboard.php" class="text-decoration-none">
                <div class="widget widget-primary clickable-widget h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['total_projects']; ?></h3>
                            <p>Total Projects</p>
                        </div>
                        <span class="widget-pill">Overview</span>
                    </div>
                </div>
            </a>
        </div>
        <?php foreach ($projectStatusOptions as $opt): ?>
            <?php
            $statusKey = (string) ($opt['status_key'] ?? '');
            if ($statusKey === '')
                continue;
            $count = (int) ($statusCounts[$statusKey] ?? 0);
            $badgeClass = projectStatusBadgeClass($statusKey);
            $widgetClass = $badgeClass === 'warning' ? 'widget-warning'
                : ($badgeClass === 'success' ? 'widget-success'
                    : ($badgeClass === 'info' ? 'widget-info'
                        : ($badgeClass === 'danger' ? 'widget-danger' : 'widget-secondary')));
            $label = (string) ($opt['status_label'] ?? formatProjectStatusLabel($statusKey));
            ?>
            <div class="col-md-6 col-xl-3">
                <a href="<?php echo $baseDir; ?>/modules/reports/dashboard.php?status=<?php echo urlencode($statusKey); ?>"
                    class="text-decoration-none">
                    <div class="widget <?php echo $widgetClass; ?> clickable-widget h-100">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h3><?php echo $count; ?></h3>
                                <p><?php echo htmlspecialchars($label); ?></p>
                            </div>
                            <span class="widget-pill">Status</span>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>



    <!-- Admin dashboard content starts here (no navigation links) -->

    <div class="row mb-4">
        <div class="col-12">
            <!-- Reports & Exports Section -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h5 class="mb-0 text-primary"><i class="fas fa-file-export me-2"></i>Reports & Exports</h5>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-lg-9">
                            <div class="p-3 border rounded-3 bg-light">
                                <h6 class="mb-3 font-weight-bold">Production Hours Export</h6>
                                <form action="<?php echo $baseDir; ?>/api/export_production_hours.php" method="GET"
                                    target="_blank">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label small text-uppercase text-muted fw-bold">Resource /
                                                User</label>
                                            <select name="user_filter" class="form-select border-0 shadow-sm">
                                                <option value="all">All Active Resources</option>
                                                <?php foreach ($repoUsers as $u): ?>
                                                    <option value="<?php echo $u['id']; ?>">
                                                        <?php echo htmlspecialchars($u['full_name']); ?>
                                                        (<?php echo ucfirst(str_replace('_', ' ', $u['role'])); ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label
                                                class="form-label small text-uppercase text-muted fw-bold">Project</label>
                                            <select name="project_filter" class="form-select border-0 shadow-sm">
                                                <option value="all">All Projects</option>
                                                <?php foreach ($repoProjects as $p): ?>
                                                    <option value="<?php echo $p['id']; ?>">
                                                        <?php echo htmlspecialchars($p['title']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-uppercase text-muted fw-bold">From
                                                Date</label>
                                            <input type="date" name="start_date" class="form-control border-0 shadow-sm"
                                                value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-uppercase text-muted fw-bold">To
                                                Date</label>
                                            <input type="date" name="end_date" class="form-control border-0 shadow-sm"
                                                value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary w-100 shadow-sm">
                                                <i class="fas fa-file-excel me-1"></i> Export
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div
                                class="h-100 p-3 border rounded-3 d-flex flex-column justify-content-center align-items-center text-center bg-gradient-light">
                                <i class="fas fa-info-circle text-info mb-2 fa-2x"></i>
                                <p class="small text-muted mb-0">Generates an <strong>Excel (.xls)</strong> report with
                                    comprehensive logs & hours.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rest of dashboard styles / charts -->
    <style>
        .clickable-widget {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }

        .clickable-widget:hover,
        .hover-shadow:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1) !important;
        }

        .widget-pill {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            white-space: nowrap;
        }

        .badge-sm {
            font-size: 0.7em;
        }

        .resource-workload {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .resource-workload .card-header {
            flex: 0 0 auto;
        }

        .resource-workload .resource-workload-body,
        .resource-workload>.card-body {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        .resource-workload .resource-workload-scroll {
            flex: 1 1 auto;
            min-height: 220px;
            max-height: 46vh;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid #f1f3f5;
            border-radius: 8px;
        }

        .resource-workload .resource-workload-footer {
            flex: 0 0 auto;
            margin-top: 0.75rem;
            padding-top: 0.5rem;
            border-top: 1px solid #f1f3f5;
        }

        @media (min-width: 992px) {
            .resource-workload {
                height: min(70vh, 740px);
            }

            .resource-workload .resource-workload-scroll {
                max-height: none;
            }
        }

        .progress {
            border-radius: 10px;
        }

        .resource-workload-stats .stat-card {
            border: 1px solid #eef1f4;
            border-radius: 10px;
            background: #fafbfc;
            padding: 10px;
        }

        .resource-workload-stats .stat-value {
            font-size: 1.15rem;
            font-weight: 700;
            line-height: 1;
        }

        .resource-workload-table {
            margin-bottom: 0;
            font-size: 0.86rem;
        }

        .resource-workload-table th,
        .resource-workload-table td {
            vertical-align: middle;
        }

        .resource-main-row {
            cursor: pointer;
        }

        .resource-summary {
            color: #6c757d;
            font-size: 0.78rem;
        }

        .resource-projects-row>td {
            background: #fafbfc;
            padding: 0.65rem;
        }

        .resource-projects-table {
            margin-bottom: 0;
            font-size: 0.82rem;
        }

        .resource-projects-table thead th {
            white-space: nowrap;
            background: #f1f3f5;
        }

        .resource-projects-toggle {
            min-width: 102px;
        }
    </style>


    <div class="row">
        <!-- Recent Projects -->
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>Recent Projects</h5>
                </div>
                <div class="card-body resource-workload-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Project Code</th>
                                    <th>Title</th>
                                    <th>Client</th>
                                    <th>Lead</th>
                                    <th>Status</th>
                                    <th>Phase</th>
                                    <th>Priority</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentProjects as $project): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars(!empty($project['project_code']) ? $project['project_code'] : ($project['po_number'] ?? '')); ?>
                                        </td>
                                        <td>
                                            <a
                                                href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>">
                                                <?php echo htmlspecialchars($project['title']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($project['client_name'] ?? '—'); ?></td>
                                        <td>
                                            <?php if (!empty($project['project_lead_id'])): ?>
                                                <a
                                                    href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $project['project_lead_id']; ?>">
                                                    <?php echo htmlspecialchars($project['lead_name'] ?? 'Not assigned'); ?>
                                                </a>
                                            <?php else: ?>
                                                Not assigned
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php $pClass = projectStatusBadgeClass($project['status']);
                                            $pLabel = formatProjectStatusLabel($project['status']); ?>
                                            <span class="badge bg-<?php echo $pClass; ?>"><?php echo $pLabel; ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($project['current_phase'])): ?>
                                                <span
                                                    class="badge bg-secondary"><?php echo htmlspecialchars($project['current_phase']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php
                                            echo $project['priority'] === 'critical' ? 'danger' :
                                                ($project['priority'] === 'high' ? 'warning' : 'secondary');
                                            ?>">
                                                <?php echo ucfirst($project['priority']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Resource Workload -->
        <div class="col-12">
            <div class="card resource-workload">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>Resource Workload</h5>
                    <div>
                        <a href="<?php echo $baseDir; ?>/modules/admin/resource_workload.php?v=<?php echo time(); ?>"
                            class="btn btn-sm btn-outline-info me-2" title="Detailed View">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse"
                            data-bs-target="#workloadFilters">
                            <i class="fas fa-filter"></i> Filters
                        </button>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="collapse" id="workloadFilters">
                    <div class="card-body border-bottom">
                        <form method="GET" class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Role</label>
                                <select name="role_filter" class="form-select form-select-sm">
                                    <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All Roles
                                    </option>
                                    <option value="project_lead" <?php echo $roleFilter === 'project_lead' ? 'selected' : ''; ?>>Project Lead</option>
                                    <option value="qa" <?php echo $roleFilter === 'qa' ? 'selected' : ''; ?>>QA</option>
                                    <option value="at_tester" <?php echo $roleFilter === 'at_tester' ? 'selected' : ''; ?>>AT Tester</option>
                                    <option value="ft_tester" <?php echo $roleFilter === 'ft_tester' ? 'selected' : ''; ?>>FT Tester</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Workload</label>
                                <select name="workload_filter" class="form-select form-select-sm">
                                    <option value="all" <?php echo $workloadFilter === 'all' ? 'selected' : ''; ?>>All
                                    </option>
                                    <option value="overloaded" <?php echo $workloadFilter === 'overloaded' ? 'selected' : ''; ?>>Overloaded (5+ projects)</option>
                                    <option value="busy" <?php echo $workloadFilter === 'busy' ? 'selected' : ''; ?>>Busy
                                        (3-5 projects)</option>
                                    <option value="available" <?php echo $workloadFilter === 'available' ? 'selected' : ''; ?>>Available (&lt;3 projects)</option>
                                    <option value="inactive" <?php echo $workloadFilter === 'inactive' ? 'selected' : ''; ?>>Inactive (No recent activity)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Sort By</label>
                                <select name="sort_by" class="form-select form-select-sm">
                                    <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name</option>
                                    <option value="role" <?php echo $sortBy === 'role' ? 'selected' : ''; ?>>Role</option>
                                    <option value="projects" <?php echo $sortBy === 'projects' ? 'selected' : ''; ?>>
                                        Active Projects</option>
                                    <option value="hours" <?php echo $sortBy === 'hours' ? 'selected' : ''; ?>>Total Hours
                                    </option>
                                    <option value="pages" <?php echo $sortBy === 'pages' ? 'selected' : ''; ?>>Assigned
                                        Pages</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary btn-sm w-100">Apply Filters</button>
                                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>"
                                    class="btn btn-outline-secondary btn-sm w-100 mt-1">Clear Filters</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card-body resource-workload-body">
                    <div class="resource-workload-stats row g-2 mb-3">
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <div class="text-muted small">Total</div>
                                <div class="stat-value"><?php echo $workloadCount; ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <div class="text-muted small">Avg Projects</div>
                                <div class="stat-value"><?php echo $avgProjects; ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <div class="text-muted small">Overloaded</div>
                                <div class="stat-value text-danger"><?php echo $overloaded; ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <div class="text-muted small">Inactive</div>
                                <div class="stat-value text-warning"><?php echo $inactive; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="resource-workload-scroll table-responsive">
                        <table class="table table-hover resource-workload-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Resource</th>
                                    <th>Role</th>
                                    <th>Assigned Projects</th>
                                    <th>Project Status Summary</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workload as $resource): ?>
                                    <?php
                                    $rid = (int) ($resource['id'] ?? 0);
                                    $activeProjects = (int) ($resource['active_projects'] ?? 0);
                                    $projectCount = (int) ($resource['project_count'] ?? 0);
                                    $roleClass = match ($resource['role']) {
                                        'project_lead' => 'primary',
                                        'qa' => 'success',
                                        'at_tester' => 'info',
                                        'ft_tester' => 'warning',
                                        default => 'secondary'
                                    };
                                    $collapseId = 'resourceProjects_' . $rid;
                                    $projectDetails = $resource['project_details'] ?? [];
                                    ?>
                                    <tr class="resource-main-row" data-bs-toggle="collapse"
                                        data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false"
                                        aria-controls="<?php echo $collapseId; ?>">
                                        <td>
                                            <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $rid; ?>"
                                                class="text-decoration-none fw-semibold">
                                                <?php echo htmlspecialchars((string) ($resource['full_name'] ?? '')); ?>
                                            </a>
                                            <div class="resource-summary">
                                                Allocated:
                                                <?php echo number_format((float) ($resource['allocated_hours'] ?? 0), 1); ?>h
                                                |
                                                Last 30d:
                                                <?php echo number_format((float) ($resource['hours_last_30_days'] ?? 0), 1); ?>h
                                                |
                                                Total:
                                                <?php echo number_format((float) ($resource['total_hours'] ?? 0), 1); ?>h
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $roleClass; ?>">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $resource['role']))); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo $projectCount; ?></strong>
                                            <div class="resource-summary">Active: <?php echo $activeProjects; ?></div>
                                        </td>
                                        <td>
                                            <span
                                                class="resource-summary"><?php echo htmlspecialchars((string) ($resource['project_summary'] ?? 'No assigned projects')); ?></span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary resource-projects-toggle"
                                                type="button" data-bs-toggle="collapse"
                                                data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false"
                                                aria-controls="<?php echo $collapseId; ?>">
                                                View Projects
                                            </button>
                                        </td>
                                    </tr>
                                    <tr id="<?php echo $collapseId; ?>" class="collapse resource-projects-row">
                                        <td colspan="5">
                                            <?php if (!empty($projectDetails)): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm resource-projects-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Project</th>
                                                                <th>Status</th>
                                                                <th>Assigned Tasks/Pages</th>
                                                                <th>Hours Worked</th>
                                                                <th>Issues Reported</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($projectDetails as $projectDetail): ?>
                                                                <tr>
                                                                    <td>
                                                                        <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo (int) $projectDetail['project_id']; ?>"
                                                                            class="text-decoration-none fw-semibold">
                                                                            <?php echo htmlspecialchars((string) $projectDetail['project_code']); ?>
                                                                        </a>
                                                                        <div class="resource-summary">
                                                                            <?php echo htmlspecialchars((string) $projectDetail['project_title']); ?>
                                                                        </div>
                                                                    </td>
                                                                    <td>
                                                                        <span
                                                                            class="badge bg-<?php echo htmlspecialchars((string) $projectDetail['status_badge']); ?>">
                                                                            <?php echo htmlspecialchars((string) $projectDetail['status_label']); ?>
                                                                        </span>
                                                                    </td>
                                                                    <td><?php echo (int) $projectDetail['assigned_pages']; ?></td>
                                                                    <td><?php echo number_format((float) $projectDetail['hours_worked'], 1); ?>h
                                                                    </td>
                                                                    <td><?php echo (int) $projectDetail['issues_reported']; ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-muted">No project assignments found for this resource.</div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($workload)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3">No resources found matching the
                                            selected filters.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Workload Distribution Chart -->
                    <div class="resource-workload-footer">
                        <h6 class="text-muted mb-2">Workload Distribution</h6>
                        <div class="progress mb-2" style="height: 20px;">
                            <?php if ($total > 0): ?>
                                <div class="progress-bar bg-danger" style="width: <?php echo ($overloaded / $total) * 100; ?>%">
                                    <?php echo $overloaded; ?>
                                </div>
                                <div class="progress-bar bg-warning" style="width: <?php echo ($busy / $total) * 100; ?>%">
                                    <?php echo $busy; ?>
                                </div>
                                <div class="progress-bar bg-success" style="width: <?php echo ($available / $total) * 100; ?>%">
                                    <?php echo $available; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-between">
                            <small class="text-danger">Overloaded: <?php echo $overloaded; ?></small>
                            <small class="text-warning">Busy: <?php echo $busy; ?></small>
                            <small class="text-success">Available: <?php echo $available; ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- AI Performance Insight Modal -->
<div class="modal fade" id="aiInsightModal" tabindex="-1" aria-labelledby="aiInsightModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="aiInsightModalLabel"><i class="fas fa-brain me-2"></i>Resource Performance
                    Insights</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="aiInsightLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">AI is analyzing performance data, please wait...</p>
                </div>
                <div id="aiInsightContent" style="display: none;">
                    <div class="row mb-3 g-3">
                        <div class="col-lg-2 col-md-4 col-6">
                            <div class="card bg-light border-0">
                                <div class="card-body p-3">
                                    <div class="text-muted small">Accessibility Accuracy</div>
                                    <div class="h4 mb-0" id="aiStatAccuracy">--</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-6">
                            <div class="card bg-light border-0">
                                <div class="card-body p-3">
                                    <div class="text-muted small">Total Actions</div>
                                    <div class="h4 mb-0" id="aiStatActivity">--</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-6">
                            <div class="card bg-light border-0">
                                <div class="card-body p-3">
                                    <div class="text-muted small">Hours Logged</div>
                                    <div class="h4 mb-0" id="aiStatHours">--</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-6">
                            <div class="card bg-light border-0">
                                <div class="card-body p-3">
                                    <div class="text-muted small">Projects Touched</div>
                                    <div class="h4 mb-0" id="aiStatProjects">--</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-6">
                            <div class="card bg-light border-0">
                                <div class="card-body p-3">
                                    <div class="text-muted small">Avg Test Hours / Page</div>
                                    <div class="h4 mb-0" id="aiStatHoursPerPage">--</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-6">
                            <div class="card bg-light border-0">
                                <div class="card-body p-3">
                                    <div class="text-muted small">Issues / Test Hour</div>
                                    <div class="h4 mb-0" id="aiStatIssuesPerHour">--</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="small text-muted mb-3" id="aiGeneratedAt">Report status: --</div>

                    <div class="mb-3">
                        <h6 class="text-secondary"><i class="fas fa-calendar-day me-2"></i>Daily Progress</h6>
                        <ul id="aiDailyProgressList" class="list-group list-group-flush mb-0"></ul>
                    </div>

                    <h6>Overall Summary</h6>
                    <p id="aiSummaryText" class="text-dark bg-light p-3 rounded border-start border-4 border-primary">
                    </p>

                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-success"><i class="fas fa-plus-circle me-2"></i>Positive Feedback</h6>
                            <ul id="aiPositiveList" class="list-group list-group-flush mb-3"></ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-danger"><i class="fas fa-exclamation-circle me-2"></i>Areas for Improvement
                            </h6>
                            <ul id="aiNegativeList" class="list-group list-group-flush mb-3"></ul>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-info"><i class="fas fa-route me-2"></i>Login & Navigation</h6>
                            <ul id="aiWorkPatternList" class="list-group list-group-flush mb-3"></ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="fas fa-folder-open me-2"></i>Project & Hours Focus</h6>
                            <ul id="aiProjectFocusList" class="list-group list-group-flush mb-3"></ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0 d-flex justify-content-end">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script nonce="<?php echo $cspNonce ?? ''; ?>">
    window.AdminDashboardConfig = {
        pendingSummaryUrl: <?php echo json_encode($baseDir . '/modules/admin/dashboard.php?action=pending_requests_summary', JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        devicesApiUrl: <?php echo json_encode($devicesApiUrl, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        editRequestsUrl: <?php echo json_encode($baseDir . '/modules/admin/edit_requests.php', JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        dashboardUrl: <?php echo json_encode($baseDir . '/modules/admin/dashboard.php', JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        performanceApiUrl: <?php echo json_encode($baseDir . '/api/resource_performance.php', JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        baseDir: <?php echo json_encode($baseDir, JSON_HEX_TAG | JSON_HEX_AMP); ?>
    };

    // --- Availability Status Filtering & Search (Restored) ---
    (function () {
        var statusFilter = document.getElementById('statusFilter');
        var statusSearch = document.getElementById('statusSearch');

        if (statusFilter && statusSearch) {
            var filterTable = function () {
                var selectedStatus = statusFilter.value;
                var searchTerm = statusSearch.value.toLowerCase();
                var rows = document.querySelectorAll('.status-row');

                rows.forEach(function (row) {
                    var statusValue = row.getAttribute('data-status');
                    var nameText = row.querySelector('.fw-bold').textContent.toLowerCase();

                    var statusMatch = (selectedStatus === 'all' || statusValue === selectedStatus);
                    var searchMatch = nameText.includes(searchTerm);

                    row.style.display = (statusMatch && searchMatch) ? '' : 'none';
                });
            };

            statusFilter.addEventListener('change', filterTable);
            statusSearch.addEventListener('input', filterTable);
        }
    })();

    // --- Insight Section Search ---
    (function () {
        var insightSearch = document.getElementById('insightSearch');
        if (insightSearch) {
            insightSearch.addEventListener('input', function () {
                var term = this.value.toLowerCase();
                var rows = document.querySelectorAll('.insight-row');
                rows.forEach(row => {
                    var name = row.querySelector('.fw-bold').textContent.toLowerCase();
                    row.style.display = name.includes(term) ? '' : 'none';
                });
            });
        }
    })();

    // CSP Compliant Action Handlers

    // --- AI Performance Insights ---
    (function () {
        var modal = new bootstrap.Modal(document.getElementById('aiInsightModal'));
        var loading = document.getElementById('aiInsightLoading');
        var content = document.getElementById('aiInsightContent');
        var tableBody = document.getElementById('insightTableBody');
        
        var projectFilter = document.getElementById('insightProjectFilter');
        var projectSearchInput = document.getElementById('insightProjectSearch');
        var projectOptions = document.querySelectorAll('#insightProjectOptions option');
        var startDateInput = document.getElementById('insightStartDate');
        var endDateInput = document.getElementById('insightEndDate');
        var insightSearch = document.getElementById('insightSearch');
        var btnExport = document.getElementById('btnExportInsights');
        
        var currentUserId = null;

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function (char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] || char;
            });
        }

        function formatAccuracy(stats) {
            var accuracy = stats && stats.accuracy ? stats.accuracy : {};
            var hasAccuracy = Boolean(accuracy.accuracy_available) && Number(accuracy.total_findings || 0) > 0;
            var pct = hasAccuracy ? Number(accuracy.accuracy_percentage || 0) : null;

            return {
                hasAccuracy: hasAccuracy,
                percentage: pct,
                label: hasAccuracy ? (pct.toFixed(2).replace(/\.00$/, '') + '%') : 'N/A'
            };
        }

        function formatRatio(value, suffix) {
            if (value === null || typeof value === 'undefined' || value === '') {
                return 'N/A';
            }

            var numeric = Number(value);
            return Number.isFinite(numeric) ? numeric.toFixed(2) + suffix : 'N/A';
        }

        function getAccuracySubtext(stats) {
            var accuracy = stats && stats.accuracy ? stats.accuracy : {};
            var hours = stats && stats.hours ? stats.hours : {};
            var totalFindings = Number(accuracy.total_findings || 0);
            var reportedIssueCount = Number(hours.reported_issue_count || 0);

            if (Boolean(accuracy.accuracy_available) && totalFindings > 0) {
                return 'Based on accessibility findings';
            }

            if (reportedIssueCount > 0) {
                return 'No accessibility finding sample; ' + reportedIssueCount + ' manual issue(s) reported in selected range';
            }

            return 'No accessibility finding sample in selected range';
        }

        function getStatusBadge(status) {
            if (status === 'ready') {
                return '<span class="badge bg-success-subtle text-success border">Ready</span>';
            }
            if (status === 'processing') {
                return '<span class="badge bg-info-subtle text-info border">Processing</span>';
            }
            if (status === 'failed') {
                return '<span class="badge bg-danger-subtle text-danger border">Retry Queued</span>';
            }
            return '<span class="badge bg-secondary-subtle text-secondary border">Queued</span>';
        }

        function buildInsightAction(item) {
            var status = item.report_status || 'queued';
            if (status === 'ready' || item.has_report_content) {
                var label = status === 'ready' ? 'View Report' : 'View Progress';
                return '<button class="btn btn-sm btn-primary py-0 btn-ai-insight" style="font-size: 0.75rem; height: 24px;" data-user-id="' + item.user_id + '" data-user-name="' + escapeHtml(item.name) + '"><i class="fas fa-eye me-1"></i> ' + label + '</button>';
            }

            var label = status === 'processing' ? 'Processing' : (status === 'failed' ? 'Retry Queued' : 'Queued');
            return '<button class="btn btn-sm btn-outline-secondary py-0" style="font-size: 0.75rem; height: 24px;" disabled><i class="fas fa-clock me-1"></i> ' + label + '</button>';
        }

        function setListStatusMessage(message, isError) {
            if (!tableBody) {
                return;
            }
            var cssClass = isError ? 'text-danger' : 'text-muted';
            tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-4 ' + cssClass + '">' + message + '</td></tr>';
        }

        function fillList(listId, items, iconClass, emptyText) {
            var listEl = document.getElementById(listId);
            if (!listEl) {
                return;
            }

            listEl.innerHTML = '';
            if (!Array.isArray(items) || items.length === 0) {
                var emptyLi = document.createElement('li');
                emptyLi.className = 'list-group-item bg-transparent px-0 border-0 text-muted';
                emptyLi.textContent = emptyText;
                listEl.appendChild(emptyLi);
                return;
            }

            items.forEach(function (text) {
                var li = document.createElement('li');
                li.className = 'list-group-item bg-transparent px-0 border-0';
                li.innerHTML = '<i class="' + iconClass + ' me-2"></i>' + escapeHtml(text);
                listEl.appendChild(li);
            });
        }

        function resolveProjectFilterValue() {
            if (!projectFilter || !projectSearchInput) {
                return '';
            }

            var typedValue = String(projectSearchInput.value || '').trim();
            if (typedValue === '' || typedValue.toLowerCase() === 'overall') {
                projectFilter.value = '';
                if (typedValue === '') {
                    projectSearchInput.value = 'Overall';
                }
                return '';
            }

            var resolvedId = '';
            var normalizedTypedValue = typedValue.toLowerCase();

            projectOptions.forEach(function (option) {
                if (resolvedId !== '') {
                    return;
                }
                if (String(option.value || '').trim().toLowerCase() === normalizedTypedValue) {
                    resolvedId = option.getAttribute('data-project-id') || '';
                }
            });

            projectFilter.value = resolvedId;
            return resolvedId;
        }

        // Fetch metrics and re-render table
        function refreshMetrics() {
            setListStatusMessage('<i class="fas fa-spinner fa-spin me-2"></i>Loading metrics...', false);
            
            var pId = resolveProjectFilterValue();
            var sd = startDateInput ? startDateInput.value : '';
            var ed = endDateInput ? endDateInput.value : '';
            
            var url = window.AdminDashboardConfig.performanceApiUrl + '?project_id=' + pId + '&start_date=' + sd + '&end_date=' + ed;
            
            // Client-side timeout (100s) for slow VPS inference
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 100000);

            fetch(url, { signal: controller.signal })
                .then(response => response.json())
                .then(data => {
                    clearTimeout(timeoutId);
                    if (data.success && tableBody) {
                        tableBody.innerHTML = '';
                        if (data.data.length === 0) {
                            setListStatusMessage('No resources found for this selection.', false);
                            return;
                        }

                        data.data.forEach(item => {
                            var accuracyInfo = formatAccuracy(item.stats || {});
                            var pct = accuracyInfo.percentage;
                            var color = pct === null ? 'bg-secondary' : (pct > 80 ? 'bg-success' : (pct > 50 ? 'bg-info' : 'bg-warning'));
                            var totalHours = Number(item.stats && item.stats.hours ? item.stats.hours.total_hours : 0);
                            var projectCount = Number(item.stats && item.stats.hours ? item.stats.hours.project_count : 0);
                            var pageCount = Number(item.stats && item.stats.hours ? item.stats.hours.page_count : 0);
                            var assignedPageTestingCount = Number(item.stats && item.stats.hours ? item.stats.hours.assigned_page_testing_count : 0);
                            var pageTestingHours = Number(item.stats && item.stats.hours ? item.stats.hours.page_testing_hours : 0);
                            var avgHoursPerPage = item.stats && item.stats.hours ? item.stats.hours.avg_hours_per_page : null;
                            var issuesPerHour = item.stats && item.stats.hours ? item.stats.hours.issues_per_hour : null;
                            var reportedIssueCount = Number(item.stats && item.stats.hours ? item.stats.hours.reported_issue_count : 0);
                            var totalActions = Number(item.stats && item.stats.activity ? item.stats.activity.total_actions : 0);
                            var status = item.report_status || 'queued';
                            var generatedAt = item.report_generated_at ? 'Updated ' + item.report_generated_at : 'Waiting for background report';
                            var tr = document.createElement('tr');
                            tr.className = 'align-middle insight-row';
                            tr.innerHTML = `
                                <td class="ps-3 py-2">
                                    <div class="fw-bold">${escapeHtml(item.name)}</div>
                                    <div class="text-muted" style="font-size: 0.7rem;">${escapeHtml((item.role || 'user').replace('_', ' '))}</div>
                                </td>
                                <td class="py-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress" style="height: 6px; width: 60px;">
                                            <div class="progress-bar ${color}" style="width: ${pct === null ? 100 : pct}%"></div>
                                        </div>
                                        <span class="fw-bold" style="font-size: 0.75rem;">${accuracyInfo.label}</span>
                                    </div>
                                    <div class="text-muted mt-1" style="font-size: 0.68rem;">${escapeHtml(getAccuracySubtext(item.stats || {}))}</div>
                                </td>
                                <td class="py-2 text-muted small">
                                    <div>${totalHours.toFixed(1)}h across ${projectCount} project(s)</div>
                                    <div>${pageTestingHours.toFixed(1)}h page testing on ${assignedPageTestingCount} assigned page(s)</div>
                                    <div>${reportedIssueCount} issues reported • ${formatRatio(issuesPerHour, ' /test h')}</div>
                                    <div>${totalActions} tracked actions</div>
                                    <div class="mt-1">${getStatusBadge(status)}</div>
                                    <div class="text-muted mt-1" style="font-size: 0.68rem;">${escapeHtml(generatedAt)}</div>
                                </td>
                                <td class="py-2 text-center">
                                    ${buildInsightAction(item)}
                                </td>
                            `;
                            tableBody.appendChild(tr);
                        });
                        // trigger search filter in case user has text in search box
                        if (insightSearch && insightSearch.value) insightSearch.dispatchEvent(new Event('input'));
                    }
                })
                .catch(err => {
                    clearTimeout(timeoutId);
                    console.error('Metric load failed:', err);
                    setListStatusMessage('Server timed out or returned an error.', true);
                });
        }

        // Initial load
        refreshMetrics();
        window.setInterval(refreshMetrics, 60000);

        // Listen for filter changes
        [startDateInput, endDateInput].forEach(el => {
            if (el) el.addEventListener('change', refreshMetrics);
        });

        if (projectSearchInput) {
            projectSearchInput.addEventListener('change', refreshMetrics);
            projectSearchInput.addEventListener('blur', function () {
                resolveProjectFilterValue();
            });
            projectSearchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    refreshMetrics();
                }
            });
        }

        // Search Filter
        if (insightSearch) {
            insightSearch.addEventListener('input', function () {
                var term = this.value.toLowerCase();
                document.querySelectorAll('.insight-row').forEach(row => {
                    var name = row.querySelector('.fw-bold').textContent.toLowerCase();
                    row.style.display = name.includes(term) ? '' : 'none';
                });
            });
        }

        // Export Logic
        if (btnExport) {
            btnExport.addEventListener('click', function() {
                var pId = resolveProjectFilterValue();
                var sd = startDateInput ? startDateInput.value : '';
                var ed = endDateInput ? endDateInput.value : '';
                var url = window.AdminDashboardConfig.baseDir + '/api/export_performance.php?project_id=' + pId + '&start_date=' + sd + '&end_date=' + ed;
                window.location.href = url;
            });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-ai-insight');
            if (btn) {
                var userId = btn.getAttribute('data-user-id');
                var userName = btn.getAttribute('data-user-name');
                currentUserId = userId;
                document.getElementById('aiInsightModalLabel').innerHTML = '<i class="fas fa-brain me-2"></i>Insights: ' + escapeHtml(userName);
                fetchInsight(userId);
                modal.show();
            }
        });

        function fetchInsight(userId) {
            loading.style.display = 'block';
            content.style.display = 'none';

            var pId = resolveProjectFilterValue();
            var sd = startDateInput ? startDateInput.value : '';
            var ed = endDateInput ? endDateInput.value : '';
            
            var url = window.AdminDashboardConfig.performanceApiUrl + '?user_id=' + userId + '&project_id=' + pId + '&start_date=' + sd + '&end_date=' + ed;

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        var insight = data.data[0];
                        displayInsight(insight);
                    } else {
                        alert('Failed to fetch cached report.');
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('An error occurred while fetching the background report.');
                })
                .finally(() => {
                    loading.style.display = 'none';
                    content.style.display = 'block';
                });
        }

        function displayInsight(item) {
            var stats = item.stats || {};
            var summary = item.summary || {};
            var coverage = item.coverage || {};
            var accuracyInfo = formatAccuracy(stats || {});
            var coverageText = '';
            if (typeof coverage.ready_days !== 'undefined' && typeof coverage.total_days !== 'undefined') {
                coverageText = ' • Daily coverage ' + coverage.ready_days + '/' + coverage.total_days;
            }
            document.getElementById('aiStatAccuracy').textContent = accuracyInfo.label;
            document.getElementById('aiStatActivity').textContent = (stats.activity && stats.activity.total_actions) || 0;
            document.getElementById('aiStatHours').textContent = (((stats.hours && stats.hours.total_hours) || 0)).toFixed(1) + 'h';
            document.getElementById('aiStatProjects').textContent = (stats.hours && stats.hours.project_count) || 0;
            document.getElementById('aiStatHoursPerPage').textContent = formatRatio(stats.hours && stats.hours.avg_hours_per_page, 'h');
            document.getElementById('aiStatIssuesPerHour').textContent = formatRatio(stats.hours && stats.hours.issues_per_hour, '');
            document.getElementById('aiGeneratedAt').textContent = 'Report status: ' + (item.report_status || 'queued') + coverageText + (item.report_generated_at ? ' • Last daily insight ' + item.report_generated_at : '');
            document.getElementById('aiSummaryText').textContent = summary.overall_summary || 'Background analysis has been queued.';

            fillList('aiPositiveList', summary.positive || [], 'fas fa-check text-success', 'No positive highlights captured yet.');
            fillList('aiNegativeList', summary.negative || [], 'fas fa-arrow-right text-muted', 'No improvement points captured yet.');
            fillList('aiWorkPatternList', summary.work_patterns || [], 'fas fa-route text-info', 'Login/navigation highlights will appear after processing.');
            fillList('aiProjectFocusList', summary.project_focus || [], 'fas fa-folder-open text-primary', 'Project-hour focus will appear after processing.');
            fillList('aiDailyProgressList', summary.daily_progress || [], 'fas fa-calendar-check text-secondary', 'Daily additions will appear here as background summaries are generated.');
        }
    })();

    // --- Resource Workload Search (New) ---
    (function () {
        var workloadSearch = document.createElement('input');
        workloadSearch.type = 'text';
        workloadSearch.className = 'form-control form-control-sm mb-2';
        workloadSearch.placeholder = 'Search resource name...';
        workloadSearch.style.borderRadius = '20px';

        var container = document.querySelector('.resource-workload-stats');
        if (container) {
            var col = document.createElement('div');
            col.className = 'col-12 mt-2';
            col.appendChild(workloadSearch);
            container.appendChild(col);
        }

        workloadSearch.addEventListener('input', function () {
            var term = this.value.toLowerCase();
            var rows = document.querySelectorAll('.resource-main-row');
            rows.forEach(row => {
                var name = row.querySelector('td:first-child .fw-semibold').textContent.toLowerCase();
                row.style.display = name.includes(term) ? '' : 'none';
                // Also hide details row if it exists
                var detailsId = row.getAttribute('data-bs-target');
                if (detailsId) {
                    var detailsRow = document.querySelector(detailsId);
                    if (detailsRow) detailsRow.classList.remove('show');
                }
            });
        });
    })();
</script>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/admin-dashboard.js"></script>
<?php include __DIR__ . '/../../includes/footer.php';
