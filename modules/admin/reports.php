<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$db = Database::getInstance();

// Get report data
$reportType = $_GET['type'] ?? 'overview';
$month = $_GET['month'] ?? date('Y-m');
$year = $_GET['year'] ?? date('Y');

// Calculate date ranges
$startDate = "$year-" . ($month !== 'all' ? $month : '01') . "-01";
$endDate = "$year-" . ($month !== 'all' ? $month : '12') . "-31";

// Overview statistics
$overview = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM projects WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate') as new_projects,
        (SELECT COUNT(*) FROM projects WHERE status = 'completed' AND DATE(completed_at) BETWEEN '$startDate' AND '$endDate') as completed_projects,
        (SELECT COUNT(*) FROM project_pages WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate') as new_pages,
        (SELECT COUNT(*) FROM project_pages WHERE status = 'completed' AND DATE(updated_at) BETWEEN '$startDate' AND '$endDate') as completed_pages,
        (SELECT COUNT(DISTINCT user_id) FROM testing_results WHERE DATE(tested_at) BETWEEN '$startDate' AND '$endDate') as active_testers,
        (SELECT COUNT(DISTINCT qa_id) FROM qa_results WHERE DATE(qa_date) BETWEEN '$startDate' AND '$endDate') as active_qas,
        (SELECT SUM(hours_spent) FROM testing_results WHERE DATE(tested_at) BETWEEN '$startDate' AND '$endDate') as tester_hours,
        (SELECT SUM(hours_spent) FROM qa_results WHERE DATE(qa_date) BETWEEN '$startDate' AND '$endDate') as qa_hours
")->fetch();

// Monthly trends
$monthlyTrends = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as projects_created,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as projects_completed
    FROM projects
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month
")->fetchAll();

// Top performers
$topTesters = $db->query("
    SELECT 
        u.full_name,
        u.role,
        COUNT(DISTINCT tr.page_id) as pages_tested,
        ROUND(AVG(CASE WHEN tr.status = 'pass' THEN 1 ELSE 0 END) * 100, 2) as pass_rate,
        SUM(tr.hours_spent) as total_hours
    FROM users u
    JOIN testing_results tr ON u.id = tr.tester_id
    WHERE tr.tested_at BETWEEN '$startDate' AND '$endDate'
    GROUP BY u.id
    ORDER BY pages_tested DESC
    LIMIT 10
")->fetchAll();

$topQAs = $db->query("
    SELECT 
        u.full_name,
        COUNT(DISTINCT qr.page_id) as pages_reviewed,
        ROUND(AVG(CASE WHEN qr.status = 'pass' THEN 1 ELSE 0 END) * 100, 2) as pass_rate,
        SUM(qr.hours_spent) as total_hours,
        SUM(qr.issues_found) as issues_found
    FROM users u
    JOIN qa_results qr ON u.id = qr.qa_id
    WHERE qr.qa_date BETWEEN '$startDate' AND '$endDate'
    GROUP BY u.id
    ORDER BY pages_reviewed DESC
    LIMIT 10
")->fetchAll();

// Project type distribution
$projectTypes = $db->query("
    SELECT 
        project_type,
        COUNT(*) as count,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM projects WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate'), 2) as percentage
    FROM projects
    WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate'
    GROUP BY project_type
")->fetchAll();

// Priority distribution
$priorityStats = $db->query("
    SELECT 
        priority,
        COUNT(*) as count,
        AVG(DATEDIFF(COALESCE(completed_at, NOW()), created_at)) as avg_days,
        AVG(total_hours) as avg_hours
    FROM projects
    WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate'
    GROUP BY priority
    ORDER BY 
        CASE priority 
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid">
    <h2>Advanced Reports</h2>
    
    <!-- Report Filters -->
    <div class="card mb-3">
        <div class="card-header">
            <h5>Report Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label>Report Type</label>
                    <select name="type" class="form-select">
                        <option value="overview" <?php echo $reportType === 'overview' ? 'selected' : ''; ?>>Overview</option>
                        <option value="performance" <?php echo $reportType === 'performance' ? 'selected' : ''; ?>>Performance</option>
                        <option value="projects" <?php echo $reportType === 'projects' ? 'selected' : ''; ?>>Projects</option>
                        <option value="team" <?php echo $reportType === 'team' ? 'selected' : ''; ?>>Team</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Year</label>
                    <select name="year" class="form-select">
                        <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Month</label>
                    <select name="month" class="form-select">
                        <option value="all" <?php echo $month === 'all' ? 'selected' : ''; ?>>All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): 
                            $monthNum = str_pad($m, 2, '0', STR_PAD_LEFT);
                            $monthName = date('F', mktime(0, 0, 0, $m, 1));
                        ?>
                        <option value="<?php echo $monthNum; ?>" <?php echo $month == $monthNum ? 'selected' : ''; ?>>
                            <?php echo $monthName; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Overview Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center bg-primary text-white">
                <div class="card-body">
                    <h3><?php echo $overview['new_projects']; ?></h3>
                    <p class="mb-0">New Projects</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center bg-success text-white">
                <div class="card-body">
                    <h3><?php echo $overview['completed_projects']; ?></h3>
                    <p class="mb-0">Completed Projects</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center bg-warning text-dark">
                <div class="card-body">
                    <h3><?php echo $overview['completed_pages']; ?></h3>
                    <p class="mb-0">Completed Pages</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center bg-info text-dark">
                <div class="card-body">
                    <h3><?php echo round($overview['tester_hours'] + $overview['qa_hours'], 1); ?></h3>
                    <p class="mb-0">Total Hours</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Project Type Distribution -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Project Type Distribution</h5>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Project Type</th>
                                <th>Count</th>
                                <th>Percentage</th>
                                <th>Chart</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projectTypes as $type): ?>
                            <tr>
                                <td><?php echo strtoupper($type['project_type']); ?></td>
                                <td><?php echo $type['count']; ?></td>
                                <td><?php echo $type['percentage']; ?>%</td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?php echo $type['percentage']; ?>%;"
                                             aria-valuenow="<?php echo $type['percentage']; ?>" 
                                             aria-valuemin="0" aria-valuemax="100">
                                            <?php echo $type['percentage']; ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Priority Stats -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Priority Statistics</h5>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Priority</th>
                                <th>Projects</th>
                                <th>Avg Days</th>
                                <th>Avg Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priorityStats as $priority): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $priority['priority'] === 'critical' ? 'danger' : 
                                             ($priority['priority'] === 'high' ? 'warning' : 'secondary');
                                    ?>">
                                        <?php echo ucfirst($priority['priority']); ?>
                                    </span>
                                </td>
                                <td><?php echo $priority['count']; ?></td>
                                <td><?php echo round($priority['avg_days'], 1); ?> days</td>
                                <td><?php echo round($priority['avg_hours'], 1); ?> hours</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-3">
        <!-- Top Testers -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Top Testers</h5>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Tester</th>
                                <th>Role</th>
                                <th>Pages</th>
                                <th>Pass Rate</th>
                                <th>Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topTesters as $tester): ?>
                            <tr>
                                <td><?php echo $tester['full_name']; ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo strtoupper($tester['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo $tester['pages_tested']; ?></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-<?php 
                                            echo $tester['pass_rate'] > 80 ? 'success' : 
                                                 ($tester['pass_rate'] > 60 ? 'warning' : 'danger');
                                        ?>" role="progressbar" 
                                             style="width: <?php echo $tester['pass_rate']; ?>%;"
                                             aria-valuenow="<?php echo $tester['pass_rate']; ?>" 
                                             aria-valuemin="0" aria-valuemax="100">
                                            <?php echo $tester['pass_rate']; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo round($tester['total_hours'], 1); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Top QAs -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Top QA Reviewers</h5>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>QA</th>
                                <th>Pages</th>
                                <th>Pass Rate</th>
                                <th>Issues</th>
                                <th>Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topQAs as $qa): ?>
                            <tr>
                                <td><?php echo $qa['full_name']; ?></td>
                                <td><?php echo $qa['pages_reviewed']; ?></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-<?php 
                                            echo $qa['pass_rate'] > 80 ? 'success' : 
                                                 ($qa['pass_rate'] > 60 ? 'warning' : 'danger');
                                        ?>" role="progressbar" 
                                             style="width: <?php echo $qa['pass_rate']; ?>%;"
                                             aria-valuenow="<?php echo $qa['pass_rate']; ?>" 
                                             aria-valuemin="0" aria-valuemax="100">
                                            <?php echo $qa['pass_rate']; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $qa['issues_found'] > 20 ? 'danger' : 
                                             ($qa['issues_found'] > 10 ? 'warning' : 'success');
                                    ?>">
                                        <?php echo $qa['issues_found']; ?>
                                    </span>
                                </td>
                                <td><?php echo round($qa['total_hours'], 1); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Monthly Trends -->
    <div class="card mt-3">
        <div class="card-header">
            <h5>Monthly Trends (Last 12 Months)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Projects Created</th>
                            <th>Projects Completed</th>
                            <th>Completion Rate</th>
                            <th>Trend</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyTrends as $trend): ?>
                        <tr>
                            <td><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></td>
                            <td><?php echo $trend['projects_created']; ?></td>
                            <td><?php echo $trend['projects_completed']; ?></td>
                            <td>
                                <?php 
                                $rate = $trend['projects_created'] > 0 ? 
                                    round(($trend['projects_completed'] / $trend['projects_created']) * 100, 1) : 0;
                                ?>
                                <span class="badge bg-<?php echo $rate > 70 ? 'success' : ($rate > 40 ? 'warning' : 'danger'); ?>">
                                    <?php echo $rate; ?>%
                                </span>
                            </td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar" role="progressbar" 
                                         style="width: <?php echo min($rate, 100); ?>%;"
                                         aria-valuenow="<?php echo $rate; ?>" 
                                         aria-valuemin="0" aria-valuemax="100">
                                        <?php echo $rate; ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Export Options -->
    <div class="card mt-3">
        <div class="card-header">
            <h5>Export Reports</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=projects&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-primary w-100">
                        <i class="fas fa-file-excel"></i> Export Projects
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=tester&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-success w-100">
                        <i class="fas fa-file-excel"></i> Export Tester Stats
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=qa&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-info w-100">
                        <i class="fas fa-file-excel"></i> Export QA Stats
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=all&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-warning w-100">
                        <i class="fas fa-file-pdf"></i> Export Full Report
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>