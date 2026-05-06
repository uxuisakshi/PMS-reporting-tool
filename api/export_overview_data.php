<?php
/**
 * Returns all data needed to populate the Overview sheet of the client Excel report.
 */
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    http_response_code(400);
    echo json_encode(['error' => 'project_id required']);
    exit;
}

$db = Database::getInstance();

// IDOR: verify user has access to this project
require_once __DIR__ . '/../includes/project_permissions.php';
if (!hasProjectAccess($db, $_SESSION['user_id'], $projectId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// ── 1. Project info ───────────────────────────────────────────────────────────
$projStmt = $db->prepare("SELECT p.title, p.project_type, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$projStmt->execute([$projectId]);
$project = $projStmt->fetch(PDO::FETCH_ASSOC);
if (!$project) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    exit;
}

// ── 2. Team members (from user_assignments, active only) ──────────────────────
$teamStmt = $db->prepare("
    SELECT DISTINCT u.full_name, ua.role
    FROM user_assignments ua
    JOIN users u ON u.id = ua.user_id
    WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    ORDER BY ua.role, u.full_name
");
$teamStmt->execute([$projectId]);
$teamMembers = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

// Role label map
$roleLabels = [
    'admin'        => 'Admin',
    'project_lead' => 'Project Lead',
    'qa'           => 'QA',
    'at_tester'    => 'AT Tester',
    'ft_tester'    => 'FT Tester',
];

$teamFormatted = array_map(function($m) use ($roleLabels) {
    return [
        'name' => $m['full_name'],
        'role' => $roleLabels[$m['role']] ?? ucfirst(str_replace('_', ' ', $m['role']))
    ];
}, $teamMembers);

// ── 3. All issues metadata ────────────────────────────────────────────────────
$issueStmt = $db->prepare("
    SELECT i.id, i.title, i.status_id
    FROM issues i
    WHERE i.project_id = ?
");
$issueStmt->execute([$projectId]);
$issues = $issueStmt->fetchAll(PDO::FETCH_ASSOC);
$issueIds = array_column($issues, 'id');

if (empty($issueIds)) {
    echo json_encode([
        'project'        => $project,
        'team'           => $teamFormatted,
        'wcag_level_a'   => 0,
        'wcag_level_aa'  => 0,
        'top_issues'     => [],
        'users_affected' => [],
        'severity_counts'=> [],
        'export_date'    => date('d-M'),
    ]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($issueIds), '?'));

// Fetch all metadata
$metaStmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders)");
$metaStmt->execute($issueIds);
$metaRows = $metaStmt->fetchAll(PDO::FETCH_ASSOC);

// Build meta map: issue_id => [key => [values]]
$metaMap = [];
foreach ($metaRows as $m) {
    $iid = (int)$m['issue_id'];
    $key = $m['meta_key'];
    if (!isset($metaMap[$iid][$key])) $metaMap[$iid][$key] = [];
    $metaMap[$iid][$key][] = $m['meta_value'];
}

// Helper: get first meta value
function metaFirst($metaMap, $iid, $key) {
    $vals = $metaMap[$iid][$key] ?? [];
    if (empty($vals)) return '';
    $v = $vals[0];
    // Try JSON decode
    $decoded = json_decode($v, true);
    if (is_array($decoded) && !empty($decoded)) return $decoded[0];
    return $v;
}

// Helper: get meta as flat array
function metaArray($metaMap, $iid, $key) {
    $vals = $metaMap[$iid][$key] ?? [];
    $result = [];
    foreach ($vals as $v) {
        $decoded = json_decode($v, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if ($item !== '' && $item !== null) $result[] = $item;
            }
        } elseif ($v !== '' && $v !== null) {
            // comma-separated fallback
            foreach (array_filter(array_map('trim', explode(',', $v))) as $item) {
                $result[] = $item;
            }
        }
    }
    return array_values(array_unique($result));
}

// ── 4. WCAG Level A and AA unique failing SCs ─────────────────────────────────
// Fetch WCAG SC levels from wcag_criteria table
$wcagLevelMap = [];
try {
    $wcagStmt = $db->query("SELECT criterion_number, level FROM wcag_criteria");
    while ($row = $wcagStmt->fetch(PDO::FETCH_ASSOC)) {
        $wcagLevelMap[trim($row['criterion_number'])] = strtoupper(trim($row['level']));
    }
} catch (Exception $e) {
    // table may not exist, fallback below
}

$failingSCsA  = [];
$failingSCsAA = [];

foreach ($issueIds as $iid) {
    $scNums = metaArray($metaMap, $iid, 'wcagsuccesscriteria');
    $scLevels = metaArray($metaMap, $iid, 'wcagsuccesscriterialevel');

    foreach ($scNums as $idx => $sc) {
        $sc = trim($sc);
        if ($sc === '') continue;

        // Determine level: from issue metadata first, then from wcag_criteria table
        $level = strtoupper(trim($scLevels[$idx] ?? ''));
        if ($level === '' && isset($wcagLevelMap[$sc])) {
            $level = $wcagLevelMap[$sc];
        }

        if ($level === 'A') {
            $failingSCsA[$sc] = true;
        } elseif ($level === 'AA') {
            $failingSCsAA[$sc] = true;
        }
    }
}

// ── 5. Top 5 issues by severity (unique titles, no repeats) ──────────────────
$severityOrder = ['blocker' => 0, 'critical' => 1, 'major' => 2, 'minor' => 3, 'low' => 4];

$issuesWithMeta = [];
foreach ($issues as $iss) {
    $iid = (int)$iss['id'];
    $severity = strtolower(trim(metaFirst($metaMap, $iid, 'severity') ?: 'minor'));
    $scNums = metaArray($metaMap, $iid, 'wcagsuccesscriteria');
    $issuesWithMeta[] = [
        'id'       => $iid,
        'title'    => $iss['title'],
        'severity' => $severity,
        'sc_nums'  => $scNums,
        'sev_rank' => $severityOrder[$severity] ?? 99,
    ];
}

// Sort by severity rank
usort($issuesWithMeta, function($a, $b) { return $a['sev_rank'] - $b['sev_rank']; });

// Pick top 5 unique titles
$topIssues = [];
$seenTitles = [];
foreach ($issuesWithMeta as $iss) {
    $titleKey = strtolower(trim($iss['title']));
    if (isset($seenTitles[$titleKey])) continue;
    $seenTitles[$titleKey] = true;
    $topIssues[] = [
        'title'   => $iss['title'],
        'sc_nums' => implode(', ', $iss['sc_nums']),
    ];
    if (count($topIssues) >= 5) break;
}

// ── 6. Users affected counts ──────────────────────────────────────────────────
$userAffectedCounts = [];
foreach ($issueIds as $iid) {
    $users = metaArray($metaMap, $iid, 'usersaffected');
    foreach ($users as $u) {
        $u = trim($u);
        if ($u === '') continue;
        $userAffectedCounts[$u] = ($userAffectedCounts[$u] ?? 0) + 1;
    }
}
arsort($userAffectedCounts);
$usersAffected = [];
foreach ($userAffectedCounts as $user => $count) {
    $usersAffected[] = ['user' => $user, 'count' => $count];
}

// ── 7. Severity counts ────────────────────────────────────────────────────────
$severityCounts = [];
foreach ($issueIds as $iid) {
    $sev = ucfirst(strtolower(trim(metaFirst($metaMap, $iid, 'severity') ?: 'minor')));
    $severityCounts[$sev] = ($severityCounts[$sev] ?? 0) + 1;
}
// Sort by severity order
$sevOrderKeys = ['Blocker', 'Critical', 'Major', 'Minor', 'Low'];
$severityCountsSorted = [];
foreach ($sevOrderKeys as $k) {
    if (isset($severityCounts[$k])) {
        $severityCountsSorted[] = ['severity' => $k, 'count' => $severityCounts[$k]];
    }
}
// Add any remaining
foreach ($severityCounts as $k => $v) {
    if (!in_array($k, $sevOrderKeys)) {
        $severityCountsSorted[] = ['severity' => $k, 'count' => $v];
    }
}

echo json_encode([
    'project'        => $project,
    'team'           => $teamFormatted,
    'wcag_level_a'   => count($failingSCsA),
    'wcag_level_aa'  => count($failingSCsAA),
    'top_issues'     => $topIssues,
    'users_affected' => $usersAffected,
    'severity_counts'=> $severityCountsSorted,
    'export_date'    => date('d-M'),
], JSON_UNESCAPED_UNICODE);
