<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead']);

/** @var PDO $db */
$db = Database::getInstance();

// --- 1. Handle Filters ---
$roleFilter = $_GET['role_filter'] ?? 'all';
$userFilter = $_GET['user_filter'] ?? 'all';
$projectFilter = $_GET['project_filter'] ?? 'all';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Helper for XML Escaping
function xml_esc($str)
{
    return htmlspecialchars((string) $str, ENT_XML1, 'UTF-8');
}

// --- 2. Build Query Parts ---
$fromAndWhere = "
    FROM project_time_logs ptl
    JOIN users u ON ptl.user_id = u.id
    LEFT JOIN projects p ON ptl.project_id = p.id
    WHERE ptl.log_date BETWEEN ? AND ?
";

$params = [$startDate, $endDate];

if ($userFilter !== 'all') {
    $fromAndWhere .= " AND ptl.user_id = ?";
    $params[] = $userFilter;
} else if ($roleFilter !== 'all') {
    $fromAndWhere .= " AND u.role = ?";
    $params[] = $roleFilter;
} else {
    $fromAndWhere .= " AND u.role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
}

if ($projectFilter !== 'all') {
    $fromAndWhere .= " AND ptl.project_id = ?";
    $params[] = $projectFilter;
}

// Summary metrics (Totals)
$summarySql = "SELECT COALESCE(SUM(ptl.hours_spent), 0) AS total_hours " . $fromAndWhere;
$summaryStmt = $db->prepare($summarySql);
$summaryStmt->execute($params);
$totalSum = $summaryStmt->fetchColumn();

// Detailed Logs
$logsSql = "
    SELECT ptl.*, u.full_name as user_name, u.role as user_role, p.title as project_title, p.po_number
    " . $fromAndWhere . "
    ORDER BY ptl.log_date DESC, u.full_name ASC
";
$logsStmt = $db->prepare($logsSql);
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. Pivot Logic ---
$allDates = [];
$startTs = strtotime($startDate);
$endTs = strtotime($endDate);
for ($ts = $startTs; $ts <= $endTs; $ts = strtotime("+1 day", $ts)) {
    $allDates[] = date('Y-m-d', $ts);
}

// Fetch all relevant users (Excluding Admin)
$usersQuery = "SELECT id, full_name, role FROM users WHERE role != 'admin' AND is_active = 1";
$usersParams = [];
if ($userFilter !== 'all') {
    $usersQuery .= " AND id = ?";
    $usersParams[] = $userFilter;
}
$usersQuery .= " ORDER BY full_name";
$resourceList = $db->prepare($usersQuery);
$resourceList->execute($usersParams);
$resources = $resourceList->fetchAll(PDO::FETCH_ASSOC);

// Map logs to user-date matrix
$pivotData = [];
foreach ($logs as $log) {
    if ($log['user_role'] === 'admin')
        continue;
    $pivotData[$log['user_id']][$log['log_date']] = ($pivotData[$log['user_id']][$log['log_date']] ?? 0) + $log['hours_spent'];
}

// --- 4. Generate multi-sheet XML ---
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="Production_Hours_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

echo '<?xml version="1.0"?>' . PHP_EOL;
echo '<?mso-application progid="Excel.Sheet"?>' . PHP_EOL;
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">' . PHP_EOL;

// --- Styles ---
echo ' <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Bottom"/>
   <Borders/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#000000"/>
   <Interior/>
   <NumberFormat/>
   <Protection/>
  </Style>
  <Style ss:ID="sHeader">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#004085" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="sData">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="sTotal">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1"/>
   <Interior ss:Color="#E9ECEF" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
 </Styles>';

// --- SHEET 1: SUMMARY GRID ---
echo ' <Worksheet ss:Name="Summary Grid">
  <Table>
   <Column ss:AutoFitWidth="0" ss:Width="150"/>' . str_repeat('<Column ss:AutoFitWidth="0" ss:Width="60"/>', count($allDates) + 1) . '
   <Row ss:Height="25">
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Resource Name</Data></Cell>';
foreach ($allDates as $d) {
    echo '    <Cell ss:StyleID="sHeader"><Data ss:Type="String">' . xml_esc(date('M d', strtotime($d))) . '</Data></Cell>';
}
echo '    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Total</Data></Cell>
   </Row>';

foreach ($resources as $res) {
    if ($res['role'] === 'admin')
        continue;
    echo '   <Row>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc($res['full_name']) . '</Data></Cell>';
    $userTotal = 0;
    foreach ($allDates as $d) {
        $hrs = $pivotData[$res['id']][$d] ?? 0;
        $userTotal += $hrs;
        if ($hrs > 0) {
            echo '    <Cell ss:StyleID="sData"><Data ss:Type="Number">' . $hrs . '</Data></Cell>';
        } else {
            echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">-</Data></Cell>';
        }
    }
    echo '    <Cell ss:StyleID="sTotal"><Data ss:Type="Number">' . $userTotal . '</Data></Cell>';
    echo '   </Row>';
}
echo '  </Table>
 </Worksheet>';

// --- SHEET 2: LOG BREAKDOWN ---
echo ' <Worksheet ss:Name="Log Breakdown">
  <Table>
   <Column ss:AutoFitWidth="1" ss:Width="80"/>
   <Column ss:AutoFitWidth="1" ss:Width="120"/>
   <Column ss:AutoFitWidth="1" ss:Width="150"/>
   <Column ss:AutoFitWidth="1" ss:Width="100"/>
   <Column ss:AutoFitWidth="1" ss:Width="100"/>
   <Column ss:AutoFitWidth="1" ss:Width="150"/>
   <Column ss:AutoFitWidth="1" ss:Width="100"/>
   <Column ss:AutoFitWidth="0" ss:Width="250"/>
   <Column ss:AutoFitWidth="0" ss:Width="250"/>
   <Column ss:AutoFitWidth="1" ss:Width="60"/>
   <Row ss:Height="20">
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Date</Data></Cell>
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Resource</Data></Cell>
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Project</Data></Cell>
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">PO Number</Data></Cell>
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Task Type</Data></Cell>
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Page/Task</Data></Cell>
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Environment</Data></Cell>
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Issue Details/Description</Data></Cell>
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Comments</Data></Cell>
    <Cell ss:StyleID="sHeader"><Data ss:Type="String">Hours</Data></Cell>
   </Row>';

foreach ($logs as $log) {
    echo '   <Row>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc($log['log_date']) . '</Data></Cell>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc($log['user_name']) . '</Data></Cell>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc($log['project_title'] ?: 'No Project') . '</Data></Cell>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc($log['po_number'] ?: '-') . '</Data></Cell>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc(ucfirst(str_replace('_', ' ', $log['task_type'] ?? '-'))) . '</Data></Cell>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc($log['page_name'] ?: '-') . '</Data></Cell>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc($log['environment_name'] ?: '-') . '</Data></Cell>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc($log['description'] ?: '-') . '</Data></Cell>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="String">' . xml_esc($log['comments'] ?: '-') . '</Data></Cell>';
    echo '    <Cell ss:StyleID="sData"><Data ss:Type="Number">' . $log['hours_spent'] . '</Data></Cell>';
    echo '   </Row>';
}

echo '   <Row ss:StyleID="sTotal">';
echo '    <Cell ss:MergeAcross="8" ss:StyleID="sTotal"><Data ss:Type="String">Total Logged Hours:</Data></Cell>';
echo '    <Cell ss:StyleID="sTotal"><Data ss:Type="Number">' . $totalSum . '</Data></Cell>';
echo '   </Row>';
echo '  </Table>
 </Worksheet>';

echo '</Workbook>';
exit;
