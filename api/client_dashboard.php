<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/models/ClientAccessControlManager.php';
require_once __DIR__ . '/../includes/models/ClientComplianceScoreResolver.php';
require_once __DIR__ . '/../includes/client_issue_snapshots.php';
ob_end_clean();

header('Content-Type: application/json');

function parseUserAffectedValues($value) {
    if (is_array($value)) {
        $items = [];
        foreach ($value as $entry) {
            $items = array_merge($items, parseUserAffectedValues($entry));
        }
        return $items;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return [];
    }

    if ($value[0] === '[') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return parseUserAffectedValues($decoded);
        }
    }

    return array_values(array_filter(array_map('trim', explode(',', $value)), function ($item) {
        return $item !== '';
    }));
}

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$clientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;

if (!$clientId) {
    echo json_encode(['success' => false, 'error' => 'Client ID required']);
    exit;
}

// IDOR fix: non-admin users can only access their own client's data
$sessionRole = $_SESSION['role'] ?? '';
if (!in_array($sessionRole, ['admin'])) {
    $ownerCheck = $db->prepare("SELECT id FROM users WHERE id = ? AND client_id = ?");
    $ownerCheck->execute([$_SESSION['user_id'], $clientId]);
    if (!$ownerCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
}

try {
    $complianceResolver = new ClientComplianceScoreResolver();
    $complianceProjectScope = getComplianceProjectScope($db, $clientId, $projectId);
    $visibleRecords = getClientVisibleIssueRecords($db, $complianceProjectScope, ['order_by' => 'i.created_at ASC, i.id ASC']);
    $visibleIssues = [];
    foreach ($visibleRecords as $record) {
        $issue = $record['issue'] ?? [];
        if (empty($issue)) {
            continue;
        }
        $issue['metadata'] = $record['meta'] ?? [];
        $issue['pages'] = $record['pages'] ?? [];
        $issue['client_visible_source'] = $record['source'] ?? 'live';
        $issue['client_visible_published_at'] = $record['published_at'] ?? '';
        $visibleIssues[] = $issue;
    }

    $summary = [
        'total_issues' => count($visibleIssues),
        'blocker_issues' => 0,
        'open_issues' => 0,
        'resolved_issues' => 0,
    ];
    foreach ($visibleIssues as $issue) {
        $severityValue = strtolower(trim((string)($issue['severity'] ?? '')));
        $statusValue = strtolower(trim((string)($issue['status_name'] ?? '')));
        if ($severityValue === 'blocker') {
            $summary['blocker_issues']++;
        }
        if (in_array($statusValue, ['open', 'in progress', 'reopened', 'in_progress'], true)) {
            $summary['open_issues']++;
        }
        if (in_array($statusValue, ['resolved', 'closed', 'fixed'], true)) {
            $summary['resolved_issues']++;
        }
    }

    $totalIssues = $summary['total_issues'];
    $complianceScore = $complianceResolver->resolveForScope($complianceProjectScope, 1);
    
    $summary['compliance'] = $complianceScore;
    $summary['total'] = $totalIssues;
    $summary['blockers'] = $summary['blocker_issues'];
    $summary['open'] = $summary['open_issues'];
    
    $userAffectedCounts = [];
    $seenByIssue = [];
    foreach ($visibleIssues as $issue) {
        $issueId = (int)($issue['id'] ?? 0);
        $meta = $issue['metadata'] ?? [];
        $labels = [];
        foreach (($meta['usersaffected'] ?? []) as $value) {
            $labels = array_merge($labels, parseUserAffectedValues($value));
        }
        $labels = array_values(array_unique($labels));
        foreach ($labels as $label) {
            if (isset($seenByIssue[$issueId][$label])) {
                continue;
            }
            $seenByIssue[$issueId][$label] = true;
            $userAffectedCounts[$label] = ($userAffectedCounts[$label] ?? 0) + 1;
        }
    }
    arsort($userAffectedCounts);
    $userAffectedCounts = array_slice($userAffectedCounts, 0, 8, true);
    
    $userAffected = [
        'labels' => array_keys($userAffectedCounts),
        'values' => array_values(array_map('intval', $userAffectedCounts)),
        'total' => $totalIssues
    ];
    
    $wcagCounts = [];
    foreach ($visibleIssues as $issue) {
        $levels = array_values(array_unique(array_filter(array_map('trim', (array)(($issue['metadata']['wcagsuccesscriterialevel'] ?? []))))));
        foreach ($levels as $level) {
            $wcagCounts[$level] = ($wcagCounts[$level] ?? 0) + 1;
        }
    }
    $wcagData = [];
    foreach (['A', 'AA', 'AAA'] as $levelKey) {
        if (isset($wcagCounts[$levelKey])) {
            $wcagData[] = ['level' => $levelKey, 'count' => $wcagCounts[$levelKey]];
        }
    }
    
    $wcagLevels = [
        'labels' => array_column($wcagData, 'level'),
        'values' => array_map('intval', array_column($wcagData, 'count'))
    ];
    
    $severityCounts = [];
    foreach ($visibleIssues as $issue) {
        $severityKey = strtolower(trim((string)($issue['severity'] ?? '')));
        if ($severityKey === '') {
            continue;
        }
        $severityCounts[$severityKey] = ($severityCounts[$severityKey] ?? 0) + 1;
    }
    $severityData = [];
    foreach (['blocker', 'critical', 'major', 'minor', 'low'] as $severityKey) {
        if (isset($severityCounts[$severityKey])) {
            $severityData[] = ['severity' => $severityKey, 'count' => $severityCounts[$severityKey]];
        }
    }
    
    $severity = [
        'labels' => array_map('ucfirst', array_column($severityData, 'severity')),
        'values' => array_map('intval', array_column($severityData, 'count'))
    ];
    
    $commonIssuesMap = [];
    foreach ($visibleIssues as $issue) {
        $title = trim((string)($issue['common_issue_title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $severityKey = strtolower(trim((string)($issue['severity'] ?? '')));
        $groupKey = $title . '|' . $severityKey;
        if (!isset($commonIssuesMap[$groupKey])) {
            $commonIssuesMap[$groupKey] = ['title' => $title, 'severity' => $severityKey, 'count' => 0];
        }
        $commonIssuesMap[$groupKey]['count']++;
    }
    $commonIssues = array_values($commonIssuesMap);
    usort($commonIssues, static function ($left, $right) {
        $countCompare = ((int)$right['count']) <=> ((int)$left['count']);
        if ($countCompare !== 0) {
            return $countCompare;
        }
        return strcasecmp((string)$left['severity'], (string)$right['severity']);
    });
    $commonIssues = array_slice($commonIssues, 0, 10);
    
    $topBlockers = [];
    foreach ($visibleIssues as $issue) {
        $severityKey = strtolower(trim((string)($issue['severity'] ?? '')));
        if (!in_array($severityKey, ['blocker', 'critical'], true)) {
            continue;
        }
        $pages = $issue['pages'] ?? [];
        $firstPage = $pages[0] ?? [];
        $topBlockers[] = [
            'id' => (int)($issue['id'] ?? 0),
            'issue_key' => (string)($issue['issue_key'] ?? ''),
            'title' => (string)($issue['title'] ?? ''),
            'severity' => $severityKey,
            'status' => (string)($issue['status_name'] ?? ''),
            'page_name' => (string)($firstPage['page_name'] ?? ''),
            'page_id' => (int)($firstPage['id'] ?? ($issue['page_id'] ?? 0)),
            'project_id' => (int)($issue['project_id'] ?? 0),
        ];
    }
    usort($topBlockers, static function ($left, $right) {
        $severityOrder = ['blocker' => 0, 'critical' => 1];
        $leftOrder = $severityOrder[$left['severity']] ?? 99;
        $rightOrder = $severityOrder[$right['severity']] ?? 99;
        if ($leftOrder !== $rightOrder) {
            return $leftOrder <=> $rightOrder;
        }
        return strcmp((string)$right['issue_key'], (string)$left['issue_key']);
    });
    $topBlockers = array_slice($topBlockers, 0, 10);
    
    $projectTitles = [];
    if (!empty($complianceProjectScope)) {
        $projectIds = is_array($complianceProjectScope) ? $complianceProjectScope : [$complianceProjectScope];
        $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
        if (!empty($projectIds)) {
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $projectStmt = $db->prepare("SELECT id, title FROM projects WHERE id IN ($placeholders)");
            $projectStmt->execute($projectIds);
            while ($projectRow = $projectStmt->fetch(PDO::FETCH_ASSOC)) {
                $projectTitles[(int)$projectRow['id']] = (string)$projectRow['title'];
            }
        }
    }
    $topPagesMap = [];
    foreach ($visibleIssues as $issue) {
        foreach (($issue['pages'] ?? []) as $page) {
            $pageIdValue = (int)($page['id'] ?? 0);
            if ($pageIdValue <= 0) {
                continue;
            }
            if (!isset($topPagesMap[$pageIdValue])) {
                $topPagesMap[$pageIdValue] = [
                    'page_id' => $pageIdValue,
                    'page_name' => (string)($page['page_name'] ?? ''),
                    'project_id' => (int)($issue['project_id'] ?? 0),
                    'project_title' => (string)($projectTitles[(int)($issue['project_id'] ?? 0)] ?? ''),
                    'issue_count' => 0,
                ];
            }
            $topPagesMap[$pageIdValue]['issue_count']++;
        }
    }
    $topPages = array_values($topPagesMap);
    usort($topPages, static function ($left, $right) {
        return ((int)$right['issue_count']) <=> ((int)$left['issue_count']);
    });
    $topPages = array_slice(array_values(array_filter($topPages, static function ($row) {
        return (int)$row['issue_count'] > 0;
    })), 0, 5);
    
    $topComments = [];
    foreach ($visibleRecords as $record) {
        $issue = $record['issue'] ?? [];
        $issueIdValue = (int)($issue['id'] ?? 0);
        if ($issueIdValue <= 0) {
            continue;
        }
        if (($record['source'] ?? 'live') === 'snapshot' && !empty($record['published_at'])) {
            $commentStmt = $db->prepare("SELECT COUNT(*) FROM issue_comments WHERE issue_id = ? AND comment_type = 'regression' AND created_at <= ?");
            $commentStmt->execute([$issueIdValue, (string)$record['published_at']]);
        } else {
            $commentStmt = $db->prepare("SELECT COUNT(*) FROM issue_comments WHERE issue_id = ? AND comment_type = 'regression'");
            $commentStmt->execute([$issueIdValue]);
        }
        $commentCount = (int)$commentStmt->fetchColumn();
        if ($commentCount <= 0) {
            continue;
        }
        $firstPage = ($issue['pages'][0] ?? []);
        $topComments[] = [
            'id' => $issueIdValue,
            'issue_key' => (string)($issue['issue_key'] ?? ''),
            'title' => (string)($issue['title'] ?? ''),
            'status' => (string)($issue['status_name'] ?? ''),
            'page_id' => (int)($firstPage['id'] ?? ($issue['page_id'] ?? 0)),
            'project_id' => (int)($issue['project_id'] ?? 0),
            'comment_count' => $commentCount,
        ];
    }
    usort($topComments, static function ($left, $right) {
        return ((int)$right['comment_count']) <=> ((int)$left['comment_count']);
    });
    $topComments = array_slice($topComments, 0, 10);
    
    // Compliance Trend Analysis
    $trendIssues = array_map(static function ($issue) {
        return [
            'id' => $issue['id'] ?? 0,
            'title' => $issue['title'] ?? '',
            'description' => $issue['description'] ?? '',
            'created_at' => $issue['created_at'] ?? '',
        ];
    }, $visibleIssues);
    $trend = [
        'daily' => getTrendData($trendIssues, $complianceResolver, 'daily', 30),
        'weekly' => getTrendData($trendIssues, $complianceResolver, 'weekly', 12),
        'monthly' => getTrendData($trendIssues, $complianceResolver, 'monthly', 12),
        'yearly' => getTrendData($trendIssues, $complianceResolver, 'yearly', 5)
    ];
    
    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'userAffected' => $userAffected,
        'wcagLevels' => $wcagLevels,
        'severity' => $severity,
        'commonIssues' => $commonIssues,
        'topBlockers' => $topBlockers,
        'topPages' => $topPages,
        'topComments' => $topComments,
        'trend' => $trend
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function getComplianceProjectScope($db, $clientId, $projectId) {
    if ($projectId) {
        return [(int) $projectId];
    }

    if (($_SESSION['role'] ?? '') === 'client') {
        $accessControl = new ClientAccessControlManager();
        $assignedProjects = $accessControl->getAssignedProjects((int) ($_SESSION['user_id'] ?? 0));
        return array_values(array_unique(array_map('intval', array_column($assignedProjects, 'id'))));
    }

    $stmt = $db->prepare('SELECT id FROM projects WHERE client_id = ?');
    $stmt->execute([(int) $clientId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function fetchTrendIssues($db, $clientId, $projectId) {
    $projectFilter = $projectId ? 'AND i.project_id = ?' : '';
    $params = $projectId ? [$clientId, $projectId] : [$clientId];

    $query = "
        SELECT i.id, i.title, i.description, i.created_at
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        ORDER BY i.created_at ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTrendData($issues, $complianceResolver, $period, $limit) {
    $issuesByPeriod = [];

    foreach ($issues as $issue) {
        $createdAt = strtotime((string) ($issue['created_at'] ?? 'now'));
        switch ($period) {
            case 'weekly':
                $periodKey = date('Y-W', $createdAt);
                break;
            case 'monthly':
                $periodKey = date('Y-m', $createdAt);
                break;
            case 'yearly':
                $periodKey = date('Y', $createdAt);
                break;
            case 'daily':
            default:
                $periodKey = date('Y-m-d', $createdAt);
                break;
        }

        $issuesByPeriod[$periodKey][] = $issue;
    }

    if (empty($issuesByPeriod)) {
        return ['labels' => [], 'values' => []];
    }

    ksort($issuesByPeriod);

    $series = [];
    $runningIssues = [];

    foreach ($issuesByPeriod as $periodKey => $periodIssues) {
        $runningIssues = array_merge($runningIssues, $periodIssues);
        $series[] = [
            'label' => $periodKey,
            'value' => $complianceResolver->calculateWcagComplianceFromIssues($runningIssues)
        ];
    }

    $series = array_slice($series, -$limit);

    return [
        'labels' => array_column($series, 'label'),
        'values' => array_column($series, 'value')
    ];
}
