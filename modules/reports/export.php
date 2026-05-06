<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead']);

$db = Database::getInstance();
$type = $_GET['type'] ?? 'projects';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$format = $_GET['format'] ?? 'csv';

// Set headers based on format
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Ymd') . '.csv"');
} elseif ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Ymd') . '.xls"');
} elseif ($format === 'pdf') {
    // PDF generation would require additional library
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Ymd') . '.pdf"');
}

// Create output stream
$output = fopen('php://output', 'w');

switch ($type) {
    case 'projects':
        exportProjects($output, $startDate, $endDate);
        break;
    case 'tester':
        exportTesterStats($output, $startDate, $endDate);
        break;
    case 'qa':
        exportQAStats($output, $startDate, $endDate);
        break;
    case 'pages':
        exportPageStats($output, $startDate, $endDate);
        break;
    default:
        exportAll($output, $startDate, $endDate);
}

fclose($output);
exit;

function exportProjects($output, $startDate, $endDate) {
    global $db;
    
    // Write header
    fputcsv($output, [
        'Project Code', 'Project Title', 'Client', 'Type', 'Priority', 
        'Status', 'Project Lead', 'Total Hours', 'Created Date', 
        'Completion Date', 'Total Pages', 'Completed Pages', 'Completion %'
    ]);
    
    $stmt = $db->prepare("
        SELECT 
            p.po_number,
            p.title,
            c.name as client_name,
            p.project_type,
            p.priority,
            p.status,
            u.full_name as project_lead,
            p.total_hours,
            p.created_at,
            p.completed_at,
            COUNT(pp.id) as total_pages,
            SUM(CASE WHEN pp.status = 'completed' THEN 1 ELSE 0 END) as completed_pages,
            ROUND(SUM(CASE WHEN pp.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(pp.id), 2) as completion_percentage
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        LEFT JOIN users u ON p.project_lead_id = u.id
        LEFT JOIN project_pages pp ON p.id = pp.project_id
        WHERE p.created_at BETWEEN ? AND ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['po_number'],
            $row['title'],
            $row['client_name'],
            strtoupper($row['project_type']),
            ucfirst($row['priority']),
            formatProjectStatusLabel($row['status']),
            $row['project_lead'] ?: 'Not assigned',
            $row['total_hours'] ?: '0',
            $row['created_at'],
            $row['completed_at'] ?: 'Not completed',
            $row['total_pages'],
            $row['completed_pages'],
            $row['completion_percentage'] . '%'
        ]);
    }
}

function exportTesterStats($output, $startDate, $endDate) {
    global $db;
    
    fputcsv($output, [
        'Tester Name', 'Role', 'Email', 'Total Pages Tested', 
        'Total Hours', 'Issues Found', 'Avg Hours/Page'
    ]);
    
    $stmt = $db->prepare("
        SELECT 
            u.full_name,
            u.role,
            u.email,
            COUNT(DISTINCT tr.page_id) as pages_tested,
            SUM(tr.hours_spent) as total_hours,
            SUM(tr.issues_found) as total_issues,
            ROUND(AVG(tr.hours_spent), 2) as avg_hours_per_page
        FROM users u
        LEFT JOIN testing_results tr ON u.id = tr.tester_id
        WHERE u.role IN ('at_tester', 'ft_tester') 
        AND u.is_active = 1
        AND tr.tested_at BETWEEN ? AND ?
        GROUP BY u.id
        ORDER BY pages_tested DESC
    ");
    
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['full_name'],
            strtoupper($row['role']),
            $row['email'],
            $row['pages_tested'] ?: '0',
            $row['total_hours'] ?: '0',
            $row['total_issues'] ?: '0',
            $row['avg_hours_per_page'] ?: '0'
        ]);
    }
}

function exportQAStats($output, $startDate, $endDate) {
    global $db;
    
    fputcsv($output, [
        'QA Name', 'Email', 'Pages Reviewed', 'Total Hours', 
        'Issues Found', 'Avg Hours/Page'
    ]);
    
    $stmt = $db->prepare("
        SELECT 
            u.full_name,
            u.email,
            COUNT(DISTINCT qr.page_id) as pages_reviewed,
            SUM(qr.hours_spent) as total_hours,
            SUM(qr.issues_found) as total_issues,
            ROUND(AVG(qr.hours_spent), 2) as avg_hours_per_page
        FROM users u
        LEFT JOIN qa_results qr ON u.id = qr.qa_id
        WHERE u.role = 'qa' 
        AND u.is_active = 1
        AND qr.qa_date BETWEEN ? AND ?
        GROUP BY u.id
        ORDER BY pages_reviewed DESC
    ");
    
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['full_name'],
            $row['email'],
            $row['pages_reviewed'] ?: '0',
            $row['total_hours'] ?: '0',
            $row['total_issues'] ?: '0',
            $row['avg_hours_per_page'] ?: '0'
        ]);
    }
}

function exportPageStats($output, $startDate, $endDate) {
    global $db;
    
    fputcsv($output, [
        'Project', 'Page Name', 'URL/Screen', 'AT Tester', 'FT Tester', 
        'QA', 'Test Status', 'QA Status', 'Page Status', 'Issues Found',
        'Test Hours', 'QA Hours', 'Total Hours', 'Created Date', 'Completed Date'
    ]);
    
    $stmt = $db->prepare("
        SELECT 
            p.title as project_title,
            pp.page_name,
            COALESCE(pp.url, pp.screen_name, 'N/A') as page_url,
            at_user.full_name as at_tester,
            ft_user.full_name as ft_tester,
            pp.at_tester_ids,
            pp.ft_tester_ids,
            qa_user.full_name as qa,
            tr.status as test_status,
            qr.status as qa_status,
            pp.status as page_status,
            COALESCE(tr.issues_found, 0) + COALESCE(qr.issues_found, 0) as total_issues,
            tr.hours_spent as test_hours,
            qr.hours_spent as qa_hours,
            COALESCE(tr.hours_spent, 0) + COALESCE(qr.hours_spent, 0) as total_hours,
            pp.created_at,
            CASE 
                WHEN pp.status = 'completed' THEN qr.qa_date
                ELSE NULL 
            END as completed_date
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        LEFT JOIN users at_user ON pp.at_tester_id = at_user.id
        LEFT JOIN users ft_user ON pp.ft_tester_id = ft_user.id
        LEFT JOIN users qa_user ON pp.qa_id = qa_user.id
        LEFT JOIN testing_results tr ON pp.id = tr.page_id 
            AND tr.tested_at = (SELECT MAX(tested_at) FROM testing_results WHERE page_id = pp.id)
        LEFT JOIN qa_results qr ON pp.id = qr.page_id 
            AND qr.qa_date = (SELECT MAX(qa_date) FROM qa_results WHERE page_id = pp.id)
        WHERE pp.created_at BETWEEN ? AND ?
        ORDER BY p.title, pp.page_name
    ");
    
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    
    while ($row = $stmt->fetch()) {
        // Resolve AT tester names from JSON array if join produced no name
        $atDisplay = $row['at_tester'];
        if (empty($atDisplay) && !empty($row['at_tester_ids'])) {
            $ids = json_decode($row['at_tester_ids'], true);
            if (is_array($ids) && count($ids) > 0) {
                $names = getUserNamesByIds($db, $ids);
                if (!empty($names)) $atDisplay = implode('; ', $names);
            }
        }
        $ftDisplay = $row['ft_tester'];
        if (empty($ftDisplay) && !empty($row['ft_tester_ids'])) {
            $ids = json_decode($row['ft_tester_ids'], true);
            if (is_array($ids) && count($ids) > 0) {
                $names = getUserNamesByIds($db, $ids);
                if (!empty($names)) $ftDisplay = implode('; ', $names);
            }
        }

        $testLabel = formatTestStatusLabel($row['test_status'] ?? null);
        $qaLabel = formatQAStatusLabel($row['qa_status'] ?? null);
        fputcsv($output, [
            $row['project_title'],
            $row['page_name'],
            $row['page_url'],
            $atDisplay ?: 'Not assigned',
            $ftDisplay ?: 'Not assigned',
            $row['qa'] ?: 'Not assigned',
            $testLabel,
            $qaLabel,
            ucfirst(str_replace('_', ' ', $row['page_status'])),
            $row['total_issues'],
            $row['test_hours'] ?: '0',
            $row['qa_hours'] ?: '0',
            $row['total_hours'],
            $row['created_at'],
            $row['completed_date'] ?: 'Not completed'
        ]);
    }
}

function exportAll($output, $startDate, $endDate) {
    // Export all data
    exportProjects($output, $startDate, $endDate);
    fputcsv($output, []); // Empty row as separator
    fputcsv($output, ['TESTER STATISTICS']);
    exportTesterStats($output, $startDate, $endDate);
    fputcsv($output, []);
    fputcsv($output, ['QA STATISTICS']);
    exportQAStats($output, $startDate, $endDate);
    fputcsv($output, []);
    fputcsv($output, ['PAGE STATISTICS']);
    exportPageStats($output, $startDate, $endDate);
}
