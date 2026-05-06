<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
ob_end_clean();

header('Content-Type: application/json');

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int)($_GET['user_id'] ?? 0);
$projectId = (int)($_GET['project_id'] ?? 0);

if (!$userId || !$projectId) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Cache key: per user+project, 3 minute TTL
    $cacheKey = "qa_breakdown_{$projectId}_{$userId}";
    $cacheTtl = 180;
    if (function_exists('apcu_fetch')) {
        $cached = false;
        $data = apcu_fetch($cacheKey, $cached);
        if ($cached) {
            echo json_encode(['success' => true, 'breakdown' => $data['breakdown'], 'total_unique_issues' => $data['total'], 'cached' => true]);
            exit;
        }
    }

    // Set GROUP_CONCAT max length to handle large issue lists
    $db->exec("SET SESSION group_concat_max_len = 10000");
    
    // Get QA status breakdown for this user - including both assigned and pending issues
    $breakdownSql = "SELECT 
                        COALESCE(qsm.status_label, 'Pending QA') as status_label,
                        COALESCE(qsm.status_key, 'pending') as status_key,
                        COALESCE(qsm.error_points, 0) as error_points,
                        COUNT(DISTINCT i.id) as issue_count,
                        GROUP_CONCAT(DISTINCT CONCAT(i.id, ':', COALESCE(i.issue_key, CONCAT('ISSUE-', i.id)), ':', COALESCE(i.title, 'Untitled Issue'), ':', COALESCE(i.page_id, 0)) SEPARATOR '||') as issues_list
                    FROM issues i
                    INNER JOIN (
                        -- Get main reporters
                        SELECT i2.id as issue_id, i2.reporter_id as user_id
                        FROM issues i2
                        WHERE i2.project_id = ? AND i2.reporter_id = ?
                        
                        UNION DISTINCT
                        
                        -- Get additional reporters from QA status table
                        SELECT irqs2.issue_id, irqs2.reporter_user_id as user_id
                        FROM issue_reporter_qa_status irqs2
                        INNER JOIN issues i3 ON i3.id = irqs2.issue_id
                        WHERE i3.project_id = ? AND irqs2.reporter_user_id = ?
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
                    WHERE i.project_id = ?
                    GROUP BY COALESCE(qsm.id, 0), COALESCE(qsm.status_label, 'Pending QA'), COALESCE(qsm.status_key, 'pending'), COALESCE(qsm.error_points, 0)
                    HAVING issue_count > 0
                    ORDER BY COALESCE(qsm.error_points, 0) DESC, issue_count DESC";
    
    $stmt = $db->prepare($breakdownSql);
    $stmt->execute([$projectId, $userId, $projectId, $userId, $userId, $userId, $projectId]);
    $breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process issues list for each status
    $allUniqueIssues = [];
    foreach ($breakdown as &$item) {
        $issues = [];
        
        // If issues_list is empty or null, fetch issues separately
        if (empty($item['issues_list'])) {
            // Fetch issues for this specific status
            $issuesSql = "SELECT DISTINCT i.id, COALESCE(i.issue_key, CONCAT('ISSUE-', i.id)) as issue_key, COALESCE(i.title, 'Untitled Issue') as title, COALESCE(i.page_id, 0) as page_id
                         FROM issues i
                         INNER JOIN (
                             -- Get main reporters
                             SELECT i2.id as issue_id, i2.reporter_id as user_id
                             FROM issues i2
                             WHERE i2.project_id = ? AND i2.reporter_id = ?
                             
                             UNION DISTINCT
                             
                             -- Get additional reporters from QA status table
                             SELECT irqs2.issue_id, irqs2.reporter_user_id as user_id
                             FROM issue_reporter_qa_status irqs2
                             INNER JOIN issues i3 ON i3.id = irqs2.issue_id
                             WHERE i3.project_id = ? AND irqs2.reporter_user_id = ?
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
                         WHERE i.project_id = ? AND COALESCE(qsm.status_key, 'pending') = ?
                         ORDER BY i.id";
            
            $issuesStmt = $db->prepare($issuesSql);
            $issuesStmt->execute([$projectId, $userId, $projectId, $userId, $userId, $userId, $projectId, $item['status_key']]);
            $issuesResult = $issuesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert to expected format
            foreach ($issuesResult as $issue) {
                $issues[] = [
                    'id' => (int)$issue['id'],
                    'issue_key' => $issue['issue_key'],
                    'title' => $issue['title'],
                    'page_id' => (int)$issue['page_id']
                ];
                $allUniqueIssues[(int)$issue['id']] = true; // Track unique issues
            }
        } else {
            // Process the GROUP_CONCAT result
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
                    $allUniqueIssues[(int)$parts[0]] = true; // Track unique issues
                }
            }
        }
        
        $item['issues'] = $issues;
        unset($item['issues_list']); // Remove the raw string
    }
    
    // Store in APCu cache
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, ['breakdown' => $breakdown, 'total' => count($allUniqueIssues)], $cacheTtl);
    }

    echo json_encode(['success' => true, 'breakdown' => $breakdown, 'total_unique_issues' => count($allUniqueIssues)]);
} catch (Exception $e) {
    error_log('QA breakdown query failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An internal error occurred']);
}
?>
