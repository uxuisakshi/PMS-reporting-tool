<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn() || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int)($_GET['user_id'] ?? 0);
if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;
$severityLevel = $_GET['severity_level'] ?? '';

$whereConditions = [];
$params = [];

if (!empty($startDate)) {
    $whereConditions[] = 'DATE(COALESCE(irqs.updated_at, i.updated_at)) >= ?';
    $params[] = $startDate;
}
if (!empty($endDate)) {
    $whereConditions[] = 'DATE(COALESCE(irqs.updated_at, i.updated_at)) <= ?';
    $params[] = $endDate;
}
if ($projectId) {
    $whereConditions[] = 'i.project_id = ?';
    $params[] = $projectId;
}
if (!empty($severityLevel)) {
    $whereConditions[] = 'qsm.severity_level = ?';
    $params[] = $severityLevel;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

try {
    $db = Database::getInstance();
    $db->exec("SET SESSION group_concat_max_len = 10000");

    // Setup base query logic similar to admin/performance.php logic for reporter/additional reporter
    // First, verify tables existence
    $hasReporterQaStatusTable = false;
    try {
        $db->query("SELECT 1 FROM issue_reporter_qa_status LIMIT 1");
        $hasReporterQaStatusTable = true;
    }
    catch (Exception $e) {
    }

    if (!$hasReporterQaStatusTable) {
        throw new Exception("Reporter QA status table is required for this view.");
    }

    $breakdownSql = "SELECT 
                        COALESCE(qsm.status_label, 'Pending QA') as status_label,
                        COALESCE(qsm.status_key, 'pending') as status_key,
                        COALESCE(qsm.error_points, 0) as error_points,
                        COUNT(DISTINCT i.id) as issue_count,
                        GROUP_CONCAT(DISTINCT CONCAT(i.id, ':', COALESCE(i.issue_key, CONCAT('ISSUE-', i.id)), ':', REPLACE(COALESCE(i.title, 'Untitled Issue'), ':', '-'), ':', COALESCE(i.page_id, 0)) SEPARATOR '||') as issues_list
                    FROM issues i
                    INNER JOIN (
                        -- Get main reporters
                        SELECT i2.id as issue_id, i2.reporter_id as user_id
                        FROM issues i2
                        WHERE i2.reporter_id = ?
                        
                        UNION DISTINCT
                        
                        -- Get additional reporters
                        SELECT irqs2.issue_id, irqs2.reporter_user_id as user_id
                        FROM issue_reporter_qa_status irqs2
                        WHERE irqs2.reporter_user_id = ?
                    ) user_issues ON user_issues.issue_id = i.id AND user_issues.user_id = ?
                    LEFT JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id AND irqs.reporter_user_id = ?
                    LEFT JOIN qa_status_master qsm
                        ON FIND_IN_SET(
                            LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_general_ci,
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(LOWER(TRIM(COALESCE(irqs.qa_status_key, ''))) COLLATE utf8mb4_general_ci, ' ', ''),
                                        '[', ''
                                    ),
                                    ']', ''
                                ),
                                CHAR(34), ''
                            )
                        ) > 0
                       AND qsm.is_active = 1
                    $whereClause
                    GROUP BY COALESCE(qsm.id, 0), COALESCE(qsm.status_label, 'Pending QA'), COALESCE(qsm.status_key, 'pending'), COALESCE(qsm.error_points, 0)
                    HAVING issue_count > 0
                    ORDER BY COALESCE(qsm.error_points, 0) DESC, issue_count DESC";

    // Prepare params: 4 user_ids, then the filter params
    $queryParams = array_merge([$userId, $userId, $userId, $userId], $params);

    $stmt = $db->prepare($breakdownSql);
    $stmt->execute($queryParams);
    $breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allUniqueIssues = [];
    foreach ($breakdown as &$item) {
        $issues = [];

        if (empty($item['issues_list'])) {
            // Fetch fallback individually if needed
            $filterQueryStr = !empty($whereConditions) ? ' AND ' . implode(' AND ', $whereConditions) : '';
            $statusCond = $item['status_key'] == 'pending' ? "AND (qsm.status_key IS NULL OR qsm.status_key = 'pending')" : "AND qsm.status_key = ?";

            $issuesSql = "SELECT DISTINCT i.id, COALESCE(i.issue_key, CONCAT('ISSUE-', i.id)) as issue_key, COALESCE(i.title, 'Untitled Issue') as title, COALESCE(i.page_id, 0) as page_id
                          FROM issues i
                          INNER JOIN (
                              SELECT i2.id as issue_id, i2.reporter_id as user_id FROM issues i2 WHERE i2.reporter_id = ?
                              UNION DISTINCT
                              SELECT irqs2.issue_id, irqs2.reporter_user_id as user_id FROM issue_reporter_qa_status irqs2 WHERE irqs2.reporter_user_id = ?
                          ) user_issues ON user_issues.issue_id = i.id AND user_issues.user_id = ?
                          LEFT JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id AND irqs.reporter_user_id = ?
                          LEFT JOIN qa_status_master qsm ON FIND_IN_SET(LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_general_ci, REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE(irqs.qa_status_key, ''))) COLLATE utf8mb4_general_ci, ' ', ''), '[', ''), ']', ''), CHAR(34), '')) > 0 AND qsm.is_active = 1
                          WHERE 1=1 $filterQueryStr $statusCond
                          ORDER BY i.id";

            $iParams = array_merge([$userId, $userId, $userId, $userId], $params);
            if ($item['status_key'] != 'pending') {
                $iParams[] = $item['status_key'];
            }

            $issuesStmt = $db->prepare($issuesSql);
            $issuesStmt->execute($iParams);
            $issuesResult = $issuesStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($issuesResult as $issue) {
                $issues[] = [
                    'id' => (int)$issue['id'],
                    'issue_key' => $issue['issue_key'],
                    'title' => $issue['title'],
                    'page_id' => (int)$issue['page_id']
                ];
                $allUniqueIssues[(int)$issue['id']] = true;
            }
        }
        else {
            // GROUP_CONCAT fallback
            $issueEntries = explode('||', $item['issues_list']);
            foreach ($issueEntries as $entry) {
                $parts = explode(':', $entry, 4);
                if (count($parts) >= 4) {
                    $issues[] = [
                        'id' => (int)$parts[0],
                        'issue_key' => $parts[1],
                        'title' => $parts[2],
                        'page_id' => (int)$parts[3]
                    ];
                    $allUniqueIssues[(int)$parts[0]] = true;
                }
            }
        }

        $item['issues'] = $issues;
        unset($item['issues_list']);
    }

    echo json_encode(['success' => true, 'breakdown' => $breakdown, 'total_unique_issues' => count($allUniqueIssues)]);
}
catch (Exception $e) {
    error_log('Admin QA breakdown query failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
