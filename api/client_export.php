<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/models/ClientAccessControlManager.php';
require_once __DIR__ . '/../includes/models/ClientComplianceScoreResolver.php';
require_once __DIR__ . '/../includes/models/SecurityValidator.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    die('Unauthorized');
}

$securityValidator = new SecurityValidator();
$csrfToken = (string) ($_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!$securityValidator->validateCSRFToken($csrfToken, (string) ($_SESSION['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$db = Database::getInstance();
$clientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';
$allowedProjectIds = null;

if (!$clientId) {
    if ($_SESSION['role'] === 'client') {
        $accessControl = new ClientAccessControlManager();
        $assignedProjects = $accessControl->getAssignedProjects((int) ($_SESSION['user_id'] ?? 0));

        if ($projectId) {
            foreach ($assignedProjects as $assignedProject) {
                if ((int) ($assignedProject['id'] ?? 0) === (int) $projectId) {
                    $clientId = (int) ($assignedProject['client_id'] ?? 0);
                    break;
                }
            }
        }

        if (!$clientId && !empty($assignedProjects)) {
            $clientId = (int) ($assignedProjects[0]['client_id'] ?? 0);
        }
    } else {
        die('Client ID required');
    }
}

if (!$clientId) {
    die('Client ID required');
}

if (($_SESSION['role'] ?? '') === 'client') {
    $accessControl = $accessControl ?? new ClientAccessControlManager();
    $assignedProjects = $assignedProjects ?? $accessControl->getAssignedProjects((int) ($_SESSION['user_id'] ?? 0));
    $allowedProjectIds = array_values(array_unique(array_map('intval', array_column($assignedProjects, 'id'))));

    if ($projectId !== null) {
        if (!in_array((int) $projectId, $allowedProjectIds, true)) {
            die('Unauthorized project access');
        }

        $allowedProjectIds = [(int) $projectId];
    }

    if (empty($allowedProjectIds)) {
        die('No assigned projects found');
    }
}

if ($projectId) {
    header('Location: export_client_report.php?' . http_build_query([
        'project_id' => (int) $projectId,
        'format' => $format,
        'client_ready_only' => 1,
        'csrf_token' => $csrfToken,
    ]));
    exit;
}

// Get client info
$stmt = $db->prepare("SELECT name FROM clients WHERE id = ?");
$stmt->execute([$clientId]);
$client = $stmt->fetch();

if (!$client) {
    die('Client not found');
}

$projectFilter = $projectId ? "AND i.project_id = ?" : "";
$params = $projectId ? [$clientId, $projectId] : [$clientId];

// Fetch all data
$data = fetchDashboardData($db, $clientId, $projectId, $allowedProjectIds);

if ($format === 'excel') {
    $displayClientName = ($_SESSION['role'] ?? '') === 'client' ? '' : $client['name'];
    exportToExcel($data, $displayClientName);
} else {
    $displayClientName = ($_SESSION['role'] ?? '') === 'client' ? '' : $client['name'];
    exportToPDF($data, $displayClientName);
}

function fetchDashboardData($db, $clientId, $projectId, $allowedProjectIds = null) {
    $issueScope = buildProjectScopeSql($projectId, $allowedProjectIds, 'p.id', 'i.project_id');
    $pageScope = buildProjectScopeSql($projectId, $allowedProjectIds, 'p.id', 'p.id');
    $projectFilter = $issueScope['sql'];
    $params = array_merge([$clientId], $issueScope['params']);
    
    // Summary
    $summaryQuery = "
        SELECT 
            COUNT(DISTINCT i.id) as total_issues,
            COUNT(DISTINCT CASE WHEN i.severity = 'blocker' THEN i.id END) as blocker_issues,
            COUNT(DISTINCT CASE WHEN LOWER(ist.name) IN ('open', 'in progress', 'reopened', 'in_progress') THEN i.id END) as open_issues,
            COUNT(DISTINCT CASE WHEN LOWER(ist.name) IN ('resolved', 'closed', 'fixed') THEN i.id END) as resolved_issues
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
    ";
    $stmt = $db->prepare($summaryQuery);
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    $complianceResolver = new ClientComplianceScoreResolver();
    $summary['compliance_score'] = $complianceResolver->resolveForScope(
        $allowedProjectIds !== null ? $allowedProjectIds : ($projectId ? [(int) $projectId] : fetchClientProjectIds($db, $clientId)),
        1
    );
    
    // Severity
    $severityQuery = "
        SELECT i.severity, COUNT(i.id) as count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        GROUP BY i.severity
    ";
    $stmt = $db->prepare($severityQuery);
    $stmt->execute($params);
    $severity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Common Issues
    $commonQuery = "
        SELECT i.common_issue_title as title, i.severity, COUNT(i.id) as count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        AND i.common_issue_title IS NOT NULL
        GROUP BY i.common_issue_title, i.severity
        HAVING count > 1
        ORDER BY count DESC
        LIMIT 10
    ";
    $stmt = $db->prepare($commonQuery);
    $stmt->execute($params);
    $commonIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Blockers
    $blockersQuery = "
        SELECT i.issue_key, i.title, ist.name as status, pp.page_name
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        LEFT JOIN project_pages pp ON i.page_id = pp.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        AND i.severity = 'blocker'
        ORDER BY i.created_at DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($blockersQuery);
    $stmt->execute($params);
    $topBlockers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Pages
    $pagesQuery = "
        SELECT pp.page_name, p.title as project_title, COUNT(i.id) as issue_count
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        LEFT JOIN issues i ON pp.id = i.page_id AND i.client_ready = 1
        WHERE p.client_id = ? {$pageScope['sql']}
        GROUP BY pp.id
        HAVING issue_count > 0
        ORDER BY issue_count DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($pagesQuery);
    $stmt->execute(array_merge([$clientId], $pageScope['params']));
    $topPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Comments
    $commentsQuery = "
        SELECT i.issue_key, i.title, ist.name as status, COUNT(ic.id) as comment_count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        LEFT JOIN issue_comments ic ON i.id = ic.issue_id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        GROUP BY i.id
        HAVING comment_count > 0
        ORDER BY comment_count DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($commentsQuery);
    $stmt->execute($params);
    $topComments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusBreakdownQuery = "
        SELECT COALESCE(NULLIF(ist.name, ''), 'Unmapped') as status_name, COUNT(DISTINCT i.id) as count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        GROUP BY COALESCE(NULLIF(ist.name, ''), 'Unmapped')
        ORDER BY count DESC, status_name ASC
    ";
    $stmt = $db->prepare($statusBreakdownQuery);
    $stmt->execute($params);
    $statusBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $projectBreakdownQuery = "
        SELECT p.id, p.title, COUNT(DISTINCT i.id) as issue_count,
               COUNT(DISTINCT CASE WHEN i.severity = 'blocker' THEN i.id END) as blocker_count,
               COUNT(DISTINCT CASE WHEN LOWER(ist.name) IN ('open', 'in progress', 'reopened', 'in_progress') THEN i.id END) as open_count,
               COUNT(DISTINCT pp.id) as page_count
        FROM projects p
        LEFT JOIN project_pages pp ON pp.project_id = p.id
        LEFT JOIN issues i ON i.project_id = p.id AND i.client_ready = 1
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        WHERE p.client_id = ? {$pageScope['sql']}
        GROUP BY p.id, p.title
        HAVING issue_count > 0 OR page_count > 0
        ORDER BY issue_count DESC, blocker_count DESC, p.title ASC
        LIMIT 8
    ";
    $stmt = $db->prepare($projectBreakdownQuery);
    $stmt->execute(array_merge([$clientId], $pageScope['params']));
    $projectBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'summary' => $summary,
        'severity' => $severity,
        'statusBreakdown' => $statusBreakdown,
        'projectBreakdown' => $projectBreakdown,
        'commonIssues' => $commonIssues,
        'topBlockers' => $topBlockers,
        'topPages' => $topPages,
        'topComments' => $topComments
    ];
}

function buildProjectScopeSql($projectId, $allowedProjectIds, $projectColumn = 'p.id', $issueProjectColumn = 'i.project_id') {
    $sql = '';
    $params = [];

    if ($allowedProjectIds !== null) {
        $allowedProjectIds = array_values(array_unique(array_map('intval', $allowedProjectIds)));

        if (empty($allowedProjectIds)) {
            return ['sql' => ' AND 1 = 0', 'params' => []];
        }

        $placeholders = implode(',', array_fill(0, count($allowedProjectIds), '?'));
        $scopeColumn = $issueProjectColumn ?: $projectColumn;
        $sql .= " AND {$scopeColumn} IN ($placeholders)";
        $params = array_merge($params, $allowedProjectIds);
    } elseif ($projectId) {
        $scopeColumn = $issueProjectColumn ?: $projectColumn;
        $sql .= " AND {$scopeColumn} = ?";
        $params[] = (int) $projectId;
    }

    return ['sql' => $sql, 'params' => $params];
}

function fetchClientProjectIds($db, $clientId) {
    $stmt = $db->prepare('SELECT id FROM projects WHERE client_id = ?');
    $stmt->execute([(int) $clientId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function exportToExcel($data, $clientName) {
    ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitizeFilename($clientName) . '_Dashboard_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Summary
    fputcsv($output, [$clientName . ' - Dashboard Report']);
    fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    fputcsv($output, ['Summary Statistics']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Issues', $data['summary']['total_issues']]);
    fputcsv($output, ['Blocker Issues', $data['summary']['blocker_issues']]);
    fputcsv($output, ['Open Issues', $data['summary']['open_issues']]);
    fputcsv($output, ['Resolved Issues', $data['summary']['resolved_issues']]);
    fputcsv($output, ['Compliance Score', round((float) ($data['summary']['compliance_score'] ?? 0), 1) . '%']);
    fputcsv($output, []);
    
    // Severity
    fputcsv($output, ['Issues by Severity']);
    fputcsv($output, ['Severity', 'Count']);
    foreach ($data['severity'] as $row) {
        fputcsv($output, [ucfirst($row['severity']), $row['count']]);
    }
    fputcsv($output, []);
    
    // Common Issues
    fputcsv($output, ['Most Common Issues']);
    fputcsv($output, ['Issue Title', 'Severity', 'Occurrences']);
    foreach ($data['commonIssues'] as $row) {
        fputcsv($output, [$row['title'], ucfirst($row['severity']), $row['count']]);
    }
    fputcsv($output, []);
    
    // Top Blockers
    fputcsv($output, ['Top Blocker Issues']);
    fputcsv($output, ['Issue Key', 'Title', 'Status', 'Page']);
    foreach ($data['topBlockers'] as $row) {
        fputcsv($output, [$row['issue_key'], $row['title'], $row['status'], $row['page_name'] ?: 'N/A']);
    }
    fputcsv($output, []);
    
    // Top Pages
    fputcsv($output, ['Top Pages with Most Issues']);
    fputcsv($output, ['Page Name', 'Project', 'Issue Count']);
    foreach ($data['topPages'] as $row) {
        fputcsv($output, [$row['page_name'], $row['project_title'], $row['issue_count']]);
    }
    fputcsv($output, []);
    
    // Top Comments
    fputcsv($output, ['Most Commented Issues']);
    fputcsv($output, ['Issue Key', 'Title', 'Status', 'Comments']);
    foreach ($data['topComments'] as $row) {
        fputcsv($output, [$row['issue_key'], $row['title'], $row['status'], $row['comment_count']]);
    }
    
    fclose($output);
    exit;
}

function exportToPDF($data, $clientName) {
    ob_clean();
    $summary = $data['summary'] ?? [];
    $severityRows = $data['severity'] ?? [];
    $statusRows = $data['statusBreakdown'] ?? [];
    $projectRows = $data['projectBreakdown'] ?? [];
    $commonIssues = $data['commonIssues'] ?? [];
    $topBlockers = $data['topBlockers'] ?? [];
    $topPages = $data['topPages'] ?? [];
    $topComments = $data['topComments'] ?? [];

    $totalIssues = (int) ($summary['total_issues'] ?? 0);
    $blockerIssues = (int) ($summary['blocker_issues'] ?? 0);
    $openIssues = (int) ($summary['open_issues'] ?? 0);
    $resolvedIssues = (int) ($summary['resolved_issues'] ?? 0);
    $complianceScore = round((float) ($summary['compliance_score'] ?? 0), 1);
    $clientLabel = trim((string) $clientName) !== '' ? trim((string) $clientName) : 'Client Portfolio';

    $severityChart = buildHorizontalBarChart($severityRows, 'severity', 'count', [
        'blocker' => '#c81e1e',
        'critical' => '#ea580c',
        'high' => '#d97706',
        'medium' => '#2563eb',
        'low' => '#16a34a',
    ]);
    $statusChart = buildHorizontalBarChart($statusRows, 'status_name', 'count', []);
    $projectChart = buildHorizontalBarChart($projectRows, 'title', 'issue_count', []);
    $severityDonut = buildDonutChartSvg($severityRows, 'severity', 'count', [
        '#c81e1e', '#ea580c', '#d97706', '#2563eb', '#16a34a', '#64748b'
    ], $totalIssues, 'Total issues');

    $executiveHighlights = [];
    $executiveHighlights[] = $totalIssues > 0
        ? $totalIssues . ' client-ready issues are currently tracked across the selected dashboard scope.'
        : 'No client-ready issues are currently available in the selected dashboard scope.';
    $executiveHighlights[] = $blockerIssues > 0
        ? $blockerIssues . ' blocker issue(s) need immediate attention and should be prioritized for stakeholder review.'
        : 'No blocker issues are present in the current client-ready set.';
    $executiveHighlights[] = $openIssues > 0
        ? $openIssues . ' issue(s) remain active, while ' . $resolvedIssues . ' are already resolved or closed.'
        : 'The active issue queue is under control, with most client-ready items already resolved.';
    $executiveHighlights[] = 'Current compliance score stands at ' . number_format($complianceScore, 1) . '%, indicating the present overall accessibility posture.';

    $topProject = $projectRows[0] ?? null;
    if ($topProject) {
        $executiveHighlights[] = 'The most impacted digital asset is ' . (string) ($topProject['title'] ?? 'Unknown Project') . ' with ' . (int) ($topProject['issue_count'] ?? 0) . ' issue(s).';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($clientName); ?> - Dashboard Report</title>
        <style>
            :root { --ink: #0f172a; --muted: #475569; --line: #dbe3ef; --panel: #f8fbff; --brand: #0f4c81; --brand-soft: #e0f2fe; --accent: #0ea5e9; --success: #15803d; --danger: #c81e1e; --warning: #b45309; }
            * { box-sizing: border-box; }
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.5; color: var(--ink); max-width: 1120px; margin: 0 auto; padding: 28px; background: #ffffff; }
            .report-shell { border: 1px solid var(--line); border-radius: 22px; overflow: hidden; }
            .hero { background: linear-gradient(135deg, #0f4c81 0%, #1d4ed8 52%, #0891b2 100%); color: #fff; padding: 34px; }
            .hero-grid { display: table; width: 100%; }
            .hero-copy, .hero-meta { display: table-cell; vertical-align: top; }
            .hero-meta { width: 280px; text-align: right; }
            .eyebrow { display: inline-block; font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; opacity: 0.82; margin-bottom: 10px; }
            h1 { margin: 0 0 10px; font-size: 34px; line-height: 1.15; }
            .hero p { margin: 0; max-width: 620px; color: rgba(255,255,255,0.88); }
            .hero-chip { display: inline-block; margin-top: 14px; padding: 8px 12px; border-radius: 999px; background: rgba(255,255,255,0.16); font-size: 12px; }
            .hero-meta-card { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.16); border-radius: 18px; padding: 16px 18px; text-align: left; margin-left: auto; }
            .hero-meta-card div { margin-bottom: 10px; }
            .hero-meta-card div:last-child { margin-bottom: 0; }
            .meta-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.12em; opacity: 0.72; }
            .meta-value { font-size: 15px; font-weight: 600; }
            .page { padding: 28px 30px 10px; }
            .section { margin-bottom: 28px; page-break-inside: avoid; }
            .section-title { font-size: 18px; margin: 0 0 14px; padding-left: 12px; border-left: 4px solid var(--accent); }
            .section-subtitle { color: var(--muted); margin: -6px 0 16px; font-size: 13px; }
            .stats-grid { display: table; width: 100%; border-spacing: 14px 0; margin: 0 -14px 22px; }
            .stat-wrap { display: table-cell; width: 25%; }
            .stat-card { background: var(--panel); border: 1px solid var(--line); border-radius: 18px; padding: 18px; min-height: 130px; }
            .stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.12em; color: var(--muted); margin-bottom: 10px; }
            .stat-value { font-size: 30px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
            .stat-context { font-size: 13px; color: var(--muted); }
            .grid-2 { display: table; width: 100%; border-spacing: 16px 0; margin: 0 -16px; }
            .grid-cell { display: table-cell; width: 50%; vertical-align: top; }
            .panel { border: 1px solid var(--line); border-radius: 18px; padding: 18px; background: #fff; }
            .panel-soft { background: var(--panel); }
            .bullet-list { margin: 0; padding-left: 18px; }
            .bullet-list li { margin-bottom: 8px; }
            .chart-card { border: 1px solid var(--line); border-radius: 18px; padding: 18px; background: #fff; }
            .bar-chart-row { margin-bottom: 12px; }
            .bar-chart-head { display: table; width: 100%; margin-bottom: 4px; font-size: 13px; }
            .bar-chart-label, .bar-chart-value { display: table-cell; }
            .bar-chart-value { text-align: right; font-weight: 600; }
            .bar-track { width: 100%; height: 10px; border-radius: 999px; background: #e6edf5; overflow: hidden; }
            .bar-fill { height: 10px; border-radius: 999px; }
            .donut-wrap { text-align: center; }
            .donut-meta { margin-top: 10px; color: var(--muted); font-size: 13px; }
            .legend-list { margin: 14px 0 0; padding: 0; list-style: none; }
            .legend-item { display: table; width: 100%; font-size: 13px; margin-bottom: 6px; }
            .legend-label, .legend-value { display: table-cell; }
            .legend-value { text-align: right; font-weight: 600; }
            .swatch { display: inline-block; width: 10px; height: 10px; border-radius: 999px; margin-right: 8px; }
            table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            th, td { border-bottom: 1px solid var(--line); padding: 10px 12px; text-align: left; vertical-align: top; font-size: 13px; }
            th { background: #eff6ff; color: #1e3a8a; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; }
            tr:nth-child(even) td { background: #fbfdff; }
            .severity-pill, .status-pill { display: inline-block; border-radius: 999px; padding: 4px 10px; font-size: 11px; font-weight: 700; letter-spacing: 0.02em; }
            .severity-blocker { background: #fee2e2; color: #991b1b; }
            .severity-critical { background: #ffedd5; color: #9a3412; }
            .severity-high { background: #fef3c7; color: #92400e; }
            .severity-medium { background: #dbeafe; color: #1d4ed8; }
            .severity-low { background: #dcfce7; color: #166534; }
            .severity-default, .status-default { background: #e2e8f0; color: #334155; }
            .status-open { background: #fee2e2; color: #991b1b; }
            .status-resolved { background: #dcfce7; color: #166534; }
            .status-progress { background: #dbeafe; color: #1d4ed8; }
            .footer-note { padding: 0 30px 28px; color: var(--muted); font-size: 12px; }
            @media print {
                .no-print { display: none; }
                body { padding: 0; }
                button { display: none; }
                .report-shell { border: none; border-radius: 0; }
                .section { break-inside: avoid; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 20px;">
            <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 5px; cursor: pointer;">Print to PDF</button>
            <button onclick="window.close()" style="padding: 10px 20px; background: #64748b; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">Close</button>
        </div>
        <div class="report-shell">
            <div class="hero">
                <div class="hero-grid">
                    <div class="hero-copy">
                        <span class="eyebrow">Accessibility Reporting Suite</span>
                        <h1>Client Accessibility Dashboard Report</h1>
                        <p>A professional snapshot of issue inventory, severity concentration, remediation progress, portfolio hotspots, and collaboration signals for stakeholder review.</p>
                        <span class="hero-chip">Detailed export prepared for presentation and PDF distribution</span>
                    </div>
                    <div class="hero-meta">
                        <div class="hero-meta-card">
                            <div>
                                <div class="meta-label">Client / Scope</div>
                                <div class="meta-value"><?php echo htmlspecialchars($clientLabel); ?></div>
                            </div>
                            <div>
                                <div class="meta-label">Generated</div>
                                <div class="meta-value"><?php echo date('F j, Y, g:i a'); ?></div>
                            </div>
                            <div>
                                <div class="meta-label">Compliance score</div>
                                <div class="meta-value"><?php echo number_format($complianceScore, 1); ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="page">
                <div class="section">
                    <h2 class="section-title">Executive Snapshot</h2>
                    <div class="stats-grid">
                        <div class="stat-wrap"><div class="stat-card"><div class="stat-label">Total Issues</div><div class="stat-value"><?php echo $totalIssues; ?></div><div class="stat-context">Client-ready issues currently exposed to stakeholders.</div></div></div>
                        <div class="stat-wrap"><div class="stat-card"><div class="stat-label">Blocker Issues</div><div class="stat-value"><?php echo $blockerIssues; ?></div><div class="stat-context">High-risk issues demanding immediate review.</div></div></div>
                        <div class="stat-wrap"><div class="stat-card"><div class="stat-label">Open Issues</div><div class="stat-value"><?php echo $openIssues; ?></div><div class="stat-context">Active remediation workload remaining in progress.</div></div></div>
                        <div class="stat-wrap"><div class="stat-card"><div class="stat-label">Resolved Issues</div><div class="stat-value"><?php echo $resolvedIssues; ?></div><div class="stat-context">Issues already closed or resolved for the client view.</div></div></div>
                    </div>

                    <div class="grid-2">
                        <div class="grid-cell">
                            <div class="panel panel-soft">
                                <h3 style="margin-top:0;">What This Report Highlights</h3>
                                <ul class="bullet-list">
                                    <?php foreach ($executiveHighlights as $highlight): ?>
                                        <li><?php echo htmlspecialchars($highlight); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="grid-cell">
                            <div class="panel panel-soft">
                                <h3 style="margin-top:0;">Report Coverage</h3>
                                <table>
                                    <tbody>
                                        <tr><th style="width:50%;">Common issue patterns</th><td><?php echo count($commonIssues); ?> tracked clusters</td></tr>
                                        <tr><th>Pages with issue concentration</th><td><?php echo count($topPages); ?> hotspot entries</td></tr>
                                        <tr><th>Most commented issues</th><td><?php echo count($topComments); ?> collaboration signals</td></tr>
                                        <tr><th>Projects represented</th><td><?php echo count($projectRows); ?> digital asset(s)</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h2 class="section-title">Visual Breakdown</h2>
                    <p class="section-subtitle">Issue mix and progress distribution rendered for fast stakeholder comprehension.</p>
                    <div class="grid-2">
                        <div class="grid-cell">
                            <div class="chart-card">
                                <h3 style="margin-top:0;">Severity Mix</h3>
                                <div class="donut-wrap"><?php echo $severityDonut; ?></div>
                            </div>
                        </div>
                        <div class="grid-cell">
                            <div class="chart-card">
                                <h3 style="margin-top:0;">Severity Distribution</h3>
                                <?php echo $severityChart; ?>
                            </div>
                        </div>
                    </div>
                    <div class="grid-2" style="margin-top:16px;">
                        <div class="grid-cell">
                            <div class="chart-card">
                                <h3 style="margin-top:0;">Issue Status Distribution</h3>
                                <?php echo $statusChart; ?>
                            </div>
                        </div>
                        <div class="grid-cell">
                            <div class="chart-card">
                                <h3 style="margin-top:0;">Project Issue Footprint</h3>
                                <?php echo $projectChart; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h2 class="section-title">Project Breakdown</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Digital Asset</th>
                                <th>Total Issues</th>
                                <th>Blockers</th>
                                <th>Open</th>
                                <th>Pages</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($projectRows)): ?>
                                <tr><td colspan="5">No project-level breakdown is available for this export scope.</td></tr>
                            <?php else: ?>
                                <?php foreach ($projectRows as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($row['title'] ?? 'Untitled')); ?></td>
                                        <td><?php echo (int) ($row['issue_count'] ?? 0); ?></td>
                                        <td><?php echo (int) ($row['blocker_count'] ?? 0); ?></td>
                                        <td><?php echo (int) ($row['open_count'] ?? 0); ?></td>
                                        <td><?php echo (int) ($row['page_count'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <h2 class="section-title">Common Issue Patterns</h2>
                    <table>
                        <thead>
                            <tr><th>Issue Pattern</th><th>Severity</th><th>Occurrences</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($commonIssues)): ?>
                                <tr><td colspan="3">No repeating common issue clusters were found in the current client-ready scope.</td></tr>
                            <?php else: ?>
                                <?php foreach ($commonIssues as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($row['title'] ?? 'Untitled issue')); ?></td>
                                        <td><span class="severity-pill <?php echo htmlspecialchars(severityBadgeClass((string) ($row['severity'] ?? ''))); ?>"><?php echo htmlspecialchars(ucfirst((string) ($row['severity'] ?? 'unknown'))); ?></span></td>
                                        <td><?php echo (int) ($row['count'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <h2 class="section-title">Blocker Watchlist</h2>
                    <table>
                        <thead>
                            <tr><th>Issue ID</th><th>Title</th><th>Status</th><th>Location</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topBlockers)): ?>
                                <tr><td colspan="4">No blocker issues are present in the current client-ready scope.</td></tr>
                            <?php else: ?>
                                <?php foreach ($topBlockers as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($row['issue_key'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($row['title'] ?? 'Untitled blocker')); ?></td>
                                        <td><span class="status-pill <?php echo htmlspecialchars(statusBadgeClass((string) ($row['status'] ?? ''))); ?>"><?php echo htmlspecialchars((string) ($row['status'] ?? 'Unmapped')); ?></span></td>
                                        <td><?php echo htmlspecialchars((string) ($row['page_name'] ?: 'Global / Unknown')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <h2 class="section-title">Page Hotspots</h2>
                    <table>
                        <thead>
                            <tr><th>Page</th><th>Digital Asset</th><th>Issue Concentration</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topPages)): ?>
                                <tr><td colspan="3">No page hotspot data is available yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($topPages as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($row['page_name'] ?? 'Unnamed Page')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($row['project_title'] ?? 'Unknown Project')); ?></td>
                                        <td><?php echo (int) ($row['issue_count'] ?? 0); ?> issue(s)</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <h2 class="section-title">Collaboration Signals</h2>
                    <table>
                        <thead>
                            <tr><th>Issue ID</th><th>Title</th><th>Status</th><th>Comment Count</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topComments)): ?>
                                <tr><td colspan="4">No comment activity is available for the selected scope.</td></tr>
                            <?php else: ?>
                                <?php foreach ($topComments as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($row['issue_key'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($row['title'] ?? 'Untitled issue')); ?></td>
                                        <td><span class="status-pill <?php echo htmlspecialchars(statusBadgeClass((string) ($row['status'] ?? ''))); ?>"><?php echo htmlspecialchars((string) ($row['status'] ?? 'Unmapped')); ?></span></td>
                                        <td><?php echo (int) ($row['comment_count'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="footer-note">
                This PDF is designed for stakeholder presentation, print/PDF conversion, and executive sharing. Values reflect the client-ready dashboard scope available at export time.
            </div>
        </div>

        <script>
            // Auto-trigger print after a short delay to allow rendering
            window.onload = function() {
                setTimeout(function() {
                    // window.print();
                }, 500);
            };
        </script>
    </body>
    </html>
    <?php
    exit;
}

function sanitizeFilename($filename) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
}

function severityBadgeClass($severity) {
    $severity = strtolower(trim((string) $severity));
    $map = [
        'blocker' => 'severity-blocker',
        'critical' => 'severity-critical',
        'high' => 'severity-high',
        'medium' => 'severity-medium',
        'low' => 'severity-low',
    ];
    return $map[$severity] ?? 'severity-default';
}

function statusBadgeClass($status) {
    $status = strtolower(trim((string) $status));
    if (in_array($status, ['open', 'reopened'], true)) {
        return 'status-open';
    }
    if (in_array($status, ['resolved', 'closed', 'fixed'], true)) {
        return 'status-resolved';
    }
    if (in_array($status, ['in progress', 'in_progress'], true)) {
        return 'status-progress';
    }
    return 'status-default';
}

function buildHorizontalBarChart(array $rows, $labelKey, $valueKey, array $colorMap = []) {
    if (empty($rows)) {
        return '<p class="section-subtitle">No chart data available.</p>';
    }

    $maxValue = 0;
    foreach ($rows as $row) {
        $maxValue = max($maxValue, (int) ($row[$valueKey] ?? 0));
    }
    $maxValue = max(1, $maxValue);
    $fallbackPalette = ['#0f4c81', '#2563eb', '#0ea5e9', '#16a34a', '#d97706', '#c81e1e', '#64748b', '#7c3aed'];

    $html = '';
    foreach (array_values($rows) as $index => $row) {
        $label = trim((string) ($row[$labelKey] ?? 'Unlabelled'));
        $value = (int) ($row[$valueKey] ?? 0);
        $normalized = strtolower(trim(str_replace(' ', '_', $label)));
        $width = min(100, max(4, (int) round(($value / $maxValue) * 100)));
        $color = $colorMap[$normalized] ?? $fallbackPalette[$index % count($fallbackPalette)];
        $html .= '<div class="bar-chart-row">';
        $html .= '<div class="bar-chart-head"><span class="bar-chart-label">' . htmlspecialchars($label) . '</span><span class="bar-chart-value">' . $value . '</span></div>';
        $html .= '<div class="bar-track"><div class="bar-fill" style="width:' . $width . '%; background:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';"></div></div>';
        $html .= '</div>';
    }

    return $html;
}

function buildDonutChartSvg(array $rows, $labelKey, $valueKey, array $palette, $centerValue, $centerLabel) {
    if (empty($rows)) {
        return '<p class="section-subtitle">No chart data available.</p>';
    }

    $total = 0;
    foreach ($rows as $row) {
        $total += (int) ($row[$valueKey] ?? 0);
    }
    if ($total <= 0) {
        return '<p class="section-subtitle">No chart data available.</p>';
    }

    $radius = 64;
    $circumference = 2 * M_PI * $radius;
    $offset = 0.0;
    $segments = '';
    $legend = '<ul class="legend-list">';

    foreach (array_values($rows) as $index => $row) {
        $value = (int) ($row[$valueKey] ?? 0);
        if ($value <= 0) {
            continue;
        }

        $color = $palette[$index % count($palette)];
        $dash = ($value / $total) * $circumference;
        $segments .= '<circle cx="90" cy="90" r="' . $radius . '" fill="none" stroke="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" stroke-width="18" stroke-linecap="butt" stroke-dasharray="' . round($dash, 2) . ' ' . round($circumference - $dash, 2) . '" stroke-dashoffset="-' . round($offset, 2) . '" transform="rotate(-90 90 90)" />';
        $offset += $dash;

        $label = trim((string) ($row[$labelKey] ?? 'Unlabelled'));
        $legend .= '<li class="legend-item"><span class="legend-label"><span class="swatch" style="background:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '"></span>' . htmlspecialchars($label) . '</span><span class="legend-value">' . $value . '</span></li>';
    }

    $legend .= '</ul>';

    $svg = '<svg width="180" height="180" viewBox="0 0 180 180" aria-hidden="true">';
    $svg .= '<circle cx="90" cy="90" r="' . $radius . '" fill="none" stroke="#e6edf5" stroke-width="18" />';
    $svg .= $segments;
    $svg .= '<circle cx="90" cy="90" r="48" fill="#fff" />';
    $svg .= '<text x="90" y="84" text-anchor="middle" font-size="26" font-weight="700" fill="#0f172a">' . (int) $centerValue . '</text>';
    $svg .= '<text x="90" y="104" text-anchor="middle" font-size="11" fill="#475569">' . htmlspecialchars($centerLabel) . '</text>';
    $svg .= '</svg>';

    return $svg . '<div class="donut-meta">Severity share by client-ready issue count</div>' . $legend;
}
