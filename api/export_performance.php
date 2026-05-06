<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/performance_helper.php';

$auth = new Auth();
$auth->requireRole('admin');

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;

$helper = new PerformanceHelper();
$data = $helper->getResourceStats(null, $projectId, $startDate, $endDate);

// Prepare CSV
$filename = "Performance_Report_" . date('Y-m-d_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 support
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers
fputcsv($output, [
    'Resource Name', 
    'Role', 
    'Accuracy (%)', 
    'Total Findings', 
    'Corrections Needed', 
    'Total Actions (Activity)', 
    'Communication Count',
    'Report Period'
]);

$period = ($startDate && $endDate) ? "$startDate to $endDate" : "Last 30 Days";

foreach ($data as $row) {
    fputcsv($output, [
        $row['name'],
        ucfirst(str_replace('_', ' ', $row['role'])),
        $row['stats']['accuracy']['accuracy_percentage'] . '%',
        $row['stats']['accuracy']['total_findings'],
        $row['stats']['accuracy']['corrected_count'],
        $row['stats']['activity']['total_actions'],
        $row['stats']['communication']['total_comments'],
        $period
    ]);
}

fclose($output);
exit;
