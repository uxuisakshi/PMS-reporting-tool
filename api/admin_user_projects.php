<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin']);

header('Content-Type: application/json');

$db = Database::getInstance();

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

// Global Filters
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
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
if (!empty($severityLevel)) {
    $whereConditions[] = 'qsm.severity_level = ?';
    $params[] = $severityLevel;
}

// Specifically filter for this user
$whereConditions[] = 'u.id = ?';
$params[] = $userId;

$whereSql = !empty($whereConditions) ? implode(' AND ', $whereConditions) : '1=1';

$sql = "SELECT
            p.id AS project_id,
            p.title AS project_title,
            COUNT(DISTINCT i.id) AS total_issues,
            COUNT(DISTINCT CASE 
                WHEN irqs.qa_status_key IS NOT NULL 
                AND irqs.qa_status_key != '' 
                AND TRIM(irqs.qa_status_key) != ''
                AND COALESCE(qsm.error_points, 0) > 0 
                THEN i.id 
            END) AS issues_with_changes,
            COUNT(DISTINCT CASE 
                WHEN irqs.qa_status_key IS NULL 
                OR irqs.qa_status_key = '' 
                OR TRIM(irqs.qa_status_key) = '' 
                THEN i.id 
            END) AS issues_pending_qa,
            COUNT(DISTINCT irqs.id) AS total_comments,
            SUM(COALESCE(qsm.error_points, 0)) AS total_error_points,
            MAX(COALESCE(irqs.updated_at, i.updated_at)) AS last_activity_date
        FROM users u
        LEFT JOIN (
            SELECT DISTINCT i.id, i.project_id, i.updated_at, u_rel.user_id
            FROM issues i
            CROSS JOIN (
                SELECT i2.id as issue_id, i2.reporter_id as user_id
                FROM issues i2
                UNION DISTINCT
                SELECT irqs2.issue_id, irqs2.reporter_user_id as user_id
                FROM issue_reporter_qa_status irqs2
            ) u_rel ON u_rel.issue_id = i.id
        ) i ON i.user_id = u.id
        INNER JOIN projects p ON p.id = i.project_id
        LEFT JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id AND irqs.reporter_user_id = u.id
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
        WHERE i.id IS NOT NULL 
          AND ($whereSql)
        GROUP BY p.id, p.title
        HAVING COUNT(DISTINCT i.id) > 0
        ORDER BY total_error_points DESC, total_comments DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate derived metrics
    foreach ($projects as &$proj) {
        $proj['performance_score'] = 100;
        $proj['grade'] = 'N/A';
        $proj['grade_color'] = 'secondary';

        if ($proj['total_issues'] > 0) {
            $evaluatedIssues = max(0, $proj['total_issues'] - $proj['issues_pending_qa']);
            $qualityRatio = $evaluatedIssues > 0 ? (($evaluatedIssues - $proj['issues_with_changes']) / $evaluatedIssues) : 1;

            $proj['error_rate'] = $evaluatedIssues > 0 ? round(($proj['issues_with_changes'] / $evaluatedIssues) * 100, 1) . '%' : '0%';

            if ($evaluatedIssues == 0) {
                $proj['performance_score'] = 'N/A';
                $proj['grade'] = 'N/A';
                $proj['grade_color'] = 'secondary';
            }
            else {
                $perfScore = $qualityRatio * 100;
                $proj['performance_score'] = round($perfScore, 1);

                if ($proj['performance_score'] >= 90) {
                    $proj['grade'] = 'A';
                    $proj['grade_color'] = 'success';
                }
                elseif ($proj['performance_score'] >= 80) {
                    $proj['grade'] = 'B';
                    $proj['grade_color'] = 'info';
                }
                elseif ($proj['performance_score'] >= 70) {
                    $proj['grade'] = 'C';
                    $proj['grade_color'] = 'warning';
                }
                else {
                    $proj['grade'] = 'D';
                    $proj['grade_color'] = 'danger';
                }
            }
        }
        else {
            $proj['error_rate'] = '0%';
        }
    }

    echo json_encode([
        'success' => true,
        'projects' => $projects
    ]);
}
catch (Exception $e) {
    error_log('api/admin_user_projects.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
}
