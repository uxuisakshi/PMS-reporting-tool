<?php
// Get project-specific performance data
$projectId = $project['id'];

// Check if qa_status_master table exists
$hasQaStatusMaster = false;
try {
    $db->query("SELECT 1 FROM qa_status_master LIMIT 1");
    $hasQaStatusMaster = true;
} catch (Exception $e) {
    $hasQaStatusMaster = false;
}

$hasReporterQaStatusTable = false;
try {
    $db->query("SELECT 1 FROM issue_reporter_qa_status LIMIT 1");
    $hasReporterQaStatusTable = true;
} catch (Exception $e) {
    $hasReporterQaStatusTable = false;
}

$performanceData = [];
$projectStats = [
    'total_comments' => 0,
    'total_issues' => 0,
    'total_project_issues' => 0,
    'avg_error_rate' => 0,
    'avg_error_rate_percent' => 0,
    'total_resources' => 0
];

try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM issues WHERE project_id = ?");
    $countStmt->execute([$projectId]);
    $projectStats['total_project_issues'] = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $projectStats['total_project_issues'] = 0;
}

if ($hasQaStatusMaster) {
    // Primary source: reporter-level QA status mapping.
    if ($hasReporterQaStatusTable) {
        $perfSql = "SELECT
                        u.id AS user_id,
                        u.full_name,
                        u.username,
                        u.role,
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
                        AVG(COALESCE(qsm.error_points, 0)) AS avg_error_points,
                        MAX(COALESCE(irqs.updated_at, i.updated_at)) AS last_activity_date
                    FROM users u
                    LEFT JOIN (
                        -- Get all issues where user is involved (main reporter OR additional reporter)
                        SELECT DISTINCT i.id, i.project_id, i.updated_at, u_rel.user_id
                        FROM issues i
                        CROSS JOIN (
                            -- Get main reporters
                            SELECT i2.id as issue_id, i2.reporter_id as user_id
                            FROM issues i2
                            WHERE i2.project_id = ?
                            
                            UNION DISTINCT
                            
                            -- Get additional reporters from QA status table
                            SELECT irqs2.issue_id, irqs2.reporter_user_id as user_id
                            FROM issue_reporter_qa_status irqs2
                            INNER JOIN issues i3 ON i3.id = irqs2.issue_id
                            WHERE i3.project_id = ?
                        ) u_rel ON u_rel.issue_id = i.id
                        WHERE i.project_id = ?
                    ) i ON i.user_id = u.id
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
                    WHERE u.role NOT IN ('admin')
                      AND i.id IS NOT NULL
                    GROUP BY u.id, u.full_name, u.username, u.role
                    HAVING COUNT(DISTINCT i.id) > 0
                    ORDER BY total_error_points DESC, total_comments DESC";

        try {
            $perfStmt = $db->prepare($perfSql);
            $perfStmt->execute([$projectId, $projectId, $projectId]);
            $performanceData = $perfStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('tab_performance reporter-level query failed: ' . $e->getMessage());
            $performanceData = [];
        }
    }

    // Fallback: legacy issue metadata qa_status (single QA status shared across reporters).
    if (empty($performanceData)) {
        $metaSql = "SELECT
                        u.id AS user_id,
                        u.full_name,
                        u.username,
                        u.role,
                        COUNT(DISTINCT im.id) AS total_comments,
                        COUNT(DISTINCT i.id) AS total_issues,
                        COUNT(DISTINCT CASE WHEN im.meta_value IS NOT NULL AND im.meta_value != '' AND COALESCE(qsm.error_points, 0) > 0 THEN i.id END) AS issues_with_changes,
                        COUNT(DISTINCT CASE WHEN (im.meta_value IS NULL OR im.meta_value = '') THEN i.id END) AS issues_pending_qa,
                        SUM(COALESCE(qsm.error_points, 0)) AS total_error_points,
                        AVG(COALESCE(qsm.error_points, 0)) AS avg_error_points,
                        MAX(i.updated_at) AS last_activity_date
                    FROM issues i
                    INNER JOIN users u ON i.reporter_id = u.id
                    LEFT JOIN issue_metadata im ON im.issue_id = i.id AND im.meta_key = 'qa_status'
                    LEFT JOIN qa_status_master qsm
                        ON (
                            LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_general_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_general_ci
                            OR LOWER(TRIM(qsm.status_label)) COLLATE utf8mb4_general_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_general_ci
                        )
                       AND qsm.is_active = 1
                    WHERE i.project_id = ?
                      AND u.role NOT IN ('admin')
                    GROUP BY u.id, u.full_name, u.username, u.role
                    ORDER BY total_error_points DESC, total_comments DESC";
        try {
            $metaStmt = $db->prepare($metaSql);
            $metaStmt->execute([$projectId]);
            $performanceData = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('tab_performance legacy meta query failed: ' . $e->getMessage());
            $performanceData = [];
        }
    }

    // Fallback for very old rows stored in user_qa_performance.
    if (empty($performanceData)) {
        $legacySql = "SELECT 
                        u.id as user_id,
                        u.full_name,
                        u.username,
                        u.role,
                        COUNT(DISTINCT uqp.id) as total_comments,
                        COUNT(DISTINCT uqp.issue_id) as total_issues,
                        COUNT(DISTINCT CASE WHEN uqp.qa_status_id IS NOT NULL AND COALESCE(qsm.error_points, 0) > 0 THEN uqp.issue_id END) as issues_with_changes,
                        COUNT(DISTINCT CASE WHEN uqp.qa_status_id IS NULL THEN uqp.issue_id END) as issues_pending_qa,
                        SUM(uqp.error_points) as total_error_points,
                        AVG(uqp.error_points) as avg_error_points,
                        MAX(uqp.comment_date) as last_activity_date
                    FROM user_qa_performance uqp
                    JOIN users u ON uqp.user_id = u.id
                    LEFT JOIN qa_status_master qsm ON uqp.qa_status_id = qsm.id
                    WHERE uqp.project_id = ?
                    AND u.role NOT IN ('admin')
                    GROUP BY u.id, u.full_name, u.username, u.role
                    ORDER BY total_error_points DESC, total_comments DESC";
        try {
            $legacyStmt = $db->prepare($legacySql);
            $legacyStmt->execute([$projectId]);
            $performanceData = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('tab_performance legacy performance table query failed: ' . $e->getMessage());
            $performanceData = [];
        }
    }
    
    // Calculate performance score for each user (without error rate dependency)
    foreach ($performanceData as &$data) {
        $totalComments = (int)$data['total_comments'];
        $totalIssues = (int)$data['total_issues'];
        $issuesWithChanges = (int)($data['issues_with_changes'] ?? 0);
        $totalErrorPoints = (float)($data['total_error_points'] ?? 0);
        
        // Ensure issues_with_changes and issues_pending_qa have default values
        if (!isset($data['issues_with_changes'])) {
            $data['issues_with_changes'] = 0;
        }
        if (!isset($data['issues_pending_qa'])) {
            $data['issues_pending_qa'] = 0;
        }
        
        $evaluatedIssues = max(0, $totalIssues - (int)$data['issues_pending_qa']);
        
        // Calculate error rate (average error points per issue)
        $data['error_rate'] = $evaluatedIssues > 0 ? round($totalErrorPoints / $evaluatedIssues, 2) : 0;
        
        // Calculate performance score based on quality ratio
        $qualityRatio = $evaluatedIssues > 0 ? (($evaluatedIssues - $issuesWithChanges) / $evaluatedIssues) : 1;
        
        if ($evaluatedIssues == 0) {
            $data['performance_score'] = 0;
            $data['grade'] = 'N/A';
            $data['grade_color'] = 'secondary';
            $data['performance_display'] = 'N/A';
        } else {
            $data['performance_score'] = max(0, round($qualityRatio * 100, 1));
            $data['performance_display'] = $data['performance_score'] . '%';
            
            if ($data['performance_score'] >= 90) {
                $data['grade'] = 'A+';
                $data['grade_color'] = 'success';
            } elseif ($data['performance_score'] >= 80) {
                $data['grade'] = 'A';
                $data['grade_color'] = 'success';
            } elseif ($data['performance_score'] >= 70) {
                $data['grade'] = 'B';
                $data['grade_color'] = 'info';
            } elseif ($data['performance_score'] >= 60) {
                $data['grade'] = 'C';
                $data['grade_color'] = 'warning';
            } else {
                $data['grade'] = 'D';
                $data['grade_color'] = 'danger';
            }
        }
    }
    unset($data);
    
    // Sort by performance score (best first)
    usort($performanceData, function($a, $b) {
        if ($a['performance_score'] == $b['performance_score']) {
            return $a['error_rate'] <=> $b['error_rate']; // Lower error rate is better
        }
        return $b['performance_score'] <=> $a['performance_score']; // Higher score is better
    });
    
    // Calculate project statistics correctly using DB to avoid cross-user duplication
    if (!empty($performanceData)) {
        $projectStats['total_resources'] = count($performanceData);
        
        try {
            // Get accurate project-wide distinct counts
            // An issue is only considered "Reviewed" if NO reporters on that issue are pending QA
            $projStatsSql = "
                SELECT 
                    COUNT(DISTINCT i.id) as true_total_issues,
                    COUNT(DISTINCT CASE 
                        WHEN NOT EXISTS (
                            -- Check if there are any pending reporters for this issue
                            SELECT 1 FROM issue_reporter_qa_status irqs_sub 
                            WHERE irqs_sub.issue_id = i.id 
                            AND (irqs_sub.qa_status_key IS NULL OR irqs_sub.qa_status_key = '' OR TRIM(irqs_sub.qa_status_key) = '')
                        ) 
                        AND EXISTS (
                            -- Check if there is at least one reviewed reporter for this issue
                            SELECT 1 FROM issue_reporter_qa_status irqs_sub2
                            WHERE irqs_sub2.issue_id = i.id
                            AND irqs_sub2.qa_status_key IS NOT NULL 
                            AND irqs_sub2.qa_status_key != '' 
                            AND TRIM(irqs_sub2.qa_status_key) != ''
                        )
                        THEN i.id 
                    END) AS true_issues_reviewed,
                    COUNT(DISTINCT irqs.id) AS true_total_comments
                FROM issues i
                LEFT JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id
                WHERE i.project_id = ?
            ";
            $statStmt = $db->prepare($projStatsSql);
            $statStmt->execute([$projectId]);
            $trueStats = $statStmt->fetch(PDO::FETCH_ASSOC);
            
            $projectStats['total_issues'] = (int)$trueStats['true_total_issues'];
            $projectStats['issues_reviewed'] = (int)$trueStats['true_issues_reviewed'];
            $projectStats['total_comments'] = (int)$trueStats['true_total_comments'];
        } catch (Exception $e) {
            // Fallback (might have duplicates)
            $projectStats['total_comments'] = array_sum(array_column($performanceData, 'total_comments'));
            $projectStats['total_issues'] = array_sum(array_column($performanceData, 'total_issues'));
            $projectStats['issues_reviewed'] = array_sum(array_column($performanceData, 'issues_with_changes'));
        }
        
        // Calculate project average error rate based on the users
        $totalErrorRate = 0;
        $validUsers = 0;
        foreach ($performanceData as $d) {
            if (isset($d['error_rate'])) {
                $totalErrorRate += (float)$d['error_rate'];
                $validUsers++;
            }
        }
        $projectStats['avg_error_rate'] = $validUsers > 0 ? round($totalErrorRate / $validUsers, 2) : 0;
        $projectStats['avg_error_rate_percent'] = round(min(100, ($projectStats['avg_error_rate'] / 3) * 100), 1);
    }
}
?>

<div class="tab-pane fade" id="performance" role="tabpanel">
    <div class="p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="mb-1"><i class="fas fa-chart-line text-primary"></i> Resource Performance</h5>
                <small class="text-muted">Performance metrics for resources working on this project</small>
            </div>
            <?php if (hasAdminPrivileges()): ?>
            <a href="<?php echo $baseDir; ?>/modules/admin/performance.php?project_id=<?php echo $projectId; ?>" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-external-link-alt"></i> View Full Report
            </a>
            <?php endif; ?>
        </div>

        <?php if (!$hasQaStatusMaster): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> QA Status Master system not configured. Please run migration 052.
        </div>
        <?php elseif (empty($performanceData)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> No performance data available for this project yet.
        </div>
        <?php else: ?>
        
        <!-- Project Statistics -->
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3 mb-4">
            <div class="col">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-2x text-primary mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['total_resources']; ?></h4>
                        <small class="text-muted">Resources</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <i class="fas fa-comments fa-2x text-info mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['total_comments']; ?></h4>
                        <small class="text-muted">QA Comments</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <i class="fas fa-bug fa-2x text-secondary mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['issues_reviewed']; ?></h4>
                        <small class="text-muted">Issues Reviewed</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-dark">
                    <div class="card-body text-center">
                        <i class="fas fa-list-check fa-2x text-dark mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['total_project_issues']; ?></h4>
                        <small class="text-muted">Total Issues</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['avg_error_rate_percent']; ?>%</h4>
                        <small class="text-muted">Avg Error Rate (%)</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Cards -->
        <div class="row">
            <?php foreach ($performanceData as $data): ?>
            <div class="col-lg-6 col-xl-4 mb-4">
                <div class="card h-100 performance-card" data-user-id="<?php echo $data['user_id']; ?>">
                    <div class="card-header bg-gradient-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0 text-white" style="font-size: 0.9rem;"><?php echo htmlspecialchars($data['full_name']); ?></h6>
                                <small class="text-white-50" style="font-size: 0.75rem;">@<?php echo htmlspecialchars($data['username']); ?></small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?php echo $data['grade_color']; ?> fs-6 mb-1" style="font-size: 0.8rem !important;"><?php echo $data['grade']; ?></span>
                                <div class="small text-white-50" style="font-size: 0.7rem;"><?php echo ucfirst(str_replace('_', ' ', $data['role'])); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <!-- Performance Content (shown by default) -->
                        <div class="performance-content" id="performance-content-<?php echo $data['user_id']; ?>">
                            <!-- Performance Score -->
                            <div class="text-center mb-2">
                                <div class="position-relative d-inline-block">
                                    <svg width="70" height="70" class="performance-circle">
                                        <circle cx="35" cy="35" r="30" fill="none" stroke="#e9ecef" stroke-width="5"/>
                                        <circle cx="35" cy="35" r="30" fill="none" 
                                                stroke="<?php echo $data['grade_color'] == 'success' ? '#28a745' : ($data['grade_color'] == 'info' ? '#17a2b8' : ($data['grade_color'] == 'warning' ? '#ffc107' : '#dc3545')); ?>" 
                                                stroke-width="5" 
                                                stroke-dasharray="<?php echo 2 * 3.14159 * 30; ?>" 
                                                stroke-dashoffset="<?php echo 2 * 3.14159 * 30 * (1 - $data['performance_score'] / 100); ?>"
                                                transform="rotate(-90 35 35)"
                                                class="performance-progress"/>
                                    </svg>
                                    <div class="position-absolute top-50 start-50 translate-middle text-center">
                                        <div class="h6 mb-0 text-<?php echo $data['grade_color']; ?>" style="font-size: 0.9rem;"><?php echo $data['performance_display'] ?? $data['performance_score'] . '%'; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Stats Grid -->
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <div class="bg-light rounded p-2 text-center">
                                        <div class="h6 mb-0 text-primary" style="font-size: 0.9rem;"><?php echo $data['total_issues']; ?></div>
                                        <small class="text-muted" style="font-size: 0.7rem;">Total Issues</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-light rounded p-2 text-center">
                                        <div class="h6 mb-0 text-info" style="font-size: 0.9rem;"><?php echo $data['total_comments']; ?></div>
                                        <small class="text-muted" style="font-size: 0.7rem;">Comments</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-light rounded p-2 text-center">
                                        <div class="h6 mb-0 text-warning" style="font-size: 0.9rem;"><?php echo $data['issues_with_changes']; ?></div>
                                        <small class="text-muted" style="font-size: 0.7rem;">With Changes</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-light rounded p-2 text-center">
                                        <div class="h6 mb-0 text-secondary" style="font-size: 0.9rem;"><?php echo $data['issues_pending_qa']; ?></div>
                                        <small class="text-muted" style="font-size: 0.7rem;">Pending QA</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Last Activity -->
                            <div class="text-center mb-3">
                                <small class="text-muted" style="font-size: 0.75rem;">
                                    <i class="fas fa-clock"></i> 
                                    Last Activity: <?php 
                                        if (!empty($data['last_activity_date']) && $data['last_activity_date'] !== '0000-00-00 00:00:00') {
                                            echo date('M d, Y', strtotime($data['last_activity_date']));
                                        } else {
                                            echo 'No recent activity';
                                        }
                                    ?>
                                </small>
                            </div>
                        </div>
                        
                        <!-- QA Breakdown Content (hidden by default) -->
                        <div class="qa-breakdown-content" id="qa-breakdown-content-<?php echo $data['user_id']; ?>" style="display: none;">
                            <h6 class="mb-2 text-center" style="font-size: 0.9rem;"><i class="fas fa-chart-pie text-primary"></i> QA Status Breakdown</h6>
                            <div id="qa-breakdown-data-<?php echo $data['user_id']; ?>" class="qa-breakdown-scroll">
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-spinner fa-spin"></i> Loading breakdown...
                                </div>
                            </div>
                        </div>
                        
                        <!-- View Details Button (always at bottom) -->
                        <div class="mt-auto pt-2">
                            <button class="btn btn-primary btn-sm w-100 view-details-btn" onclick="toggleUserDetails(<?php echo $data['user_id']; ?>)" style="font-size: 0.8rem;">
                                <i class="fas fa-chart-pie me-1"></i> View QA Breakdown
                                <i class="fas fa-chevron-down ms-1 details-chevron"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Performance Insights -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="fas fa-lightbulb"></i> Performance Insights</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <h6 class="text-success">Top Performers</h6>

<script src="<?php echo $baseDir; ?>/assets/js/tab-performance.js?v=<?php echo time(); ?>"></script>

<style>
#performance .performance-card {
    transition: all 0.3s ease;
    border: 2px solid #e9ecef;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    height: 480px !important;
    max-height: 480px !important;
    overflow: hidden;
    border-radius: 12px;
    background: #ffffff;
}

#performance .performance-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(0,0,0,0.15);
    border-color: #007bff;
}

#performance .performance-card .card-body {
    height: calc(100% - 60px) !important;
    max-height: calc(100% - 60px) !important;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    padding: 1rem;
}

#performance .bg-gradient-primary {
    background: linear-gradient(135deg, #0056b3 0%, #007bff 50%, #0d6efd 100%);
    box-shadow: 0 3px 15px rgba(0,123,255,0.4);
    border-bottom: 3px solid rgba(255,255,255,0.2);
}

#performance .bg-gradient-primary .text-white {
    color: #ffffff !important;
    font-weight: 600;
    text-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

#performance .bg-gradient-primary .text-white-50 {
    color: rgba(255,255,255,0.9) !important;
    font-weight: 500;
}

#performance .performance-circle {
    transform: rotate(-90deg);
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
}

#performance .performance-progress {
    transition: stroke-dashoffset 1s ease-in-out;
}

#performance .view-details-btn {
    transition: all 0.3s ease;
    border: 2px solid #007bff !important;
    background: #ffffff !important;
    color: #007bff !important;
    font-weight: 600;
    padding: 0.6rem 0.8rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,123,255,0.15);
    text-transform: uppercase;
    font-size: 0.8rem !important;
    letter-spacing: 0.5px;
}

#performance .view-details-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,123,255,0.25);
    background: #007bff !important;
    color: #ffffff !important;
    border-color: #0056b3 !important;
}

#performance .details-chevron {
    transition: transform 0.2s ease;
}

/* Performance and QA content containers */
#performance .performance-content,
#performance .qa-breakdown-content {
    flex: 1;
    overflow: hidden;
    max-height: calc(100% - 70px) !important;
    transition: opacity 0.3s ease;
}

#performance .qa-breakdown-content {
    display: flex;
    flex-direction: column;
}

#performance .qa-breakdown-scroll {
    flex: 1;
    overflow-y: auto;
    padding-right: 5px;
    max-height: calc(100% - 50px) !important;
}

/* Enhanced stats grid with better contrast */
#performance .bg-light {
    background-color: #ffffff !important;
    border: 2px solid #e9ecef;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

#performance .bg-light:hover {
    background-color: #f8f9fa !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-color: #007bff;
}

#performance .bg-light .h6 {
    color: #212529 !important;
    font-weight: 800;
    font-size: 1.1rem;
}

#performance .bg-light .text-primary {
    color: #0056b3 !important;
    font-weight: 900;
}

#performance .bg-light .text-info {
    color: #0c5460 !important;
    font-weight: 900;
}

#performance .bg-light .text-warning {
    color: #664d03 !important;
    font-weight: 900;
}

#performance .bg-light .text-secondary {
    color: #495057 !important;
    font-weight: 900;
}

#performance .bg-light small {
    color: #495057 !important;
    font-weight: 600;
    font-size: 0.8rem;
}

#performance .issue-item-new:hover {
    background-color: #e3f2fd !important;
    transform: translateX(3px);
    transition: all 0.2s ease;
    border-color: #007bff !important;
}

/* Issues container with controlled height */
#performance .issues-container {
    max-height: 120px;
    overflow-y: auto;
    border: 1px solid #dee2e6 !important;
    background-color: #ffffff;
}

#performance .issues-container::-webkit-scrollbar {
    width: 4px;
}

#performance .issues-container::-webkit-scrollbar-track {
    background: #f8f9fa;
    border-radius: 2px;
}

#performance .issues-container::-webkit-scrollbar-thumb {
    background: #007bff;
    border-radius: 2px;
}

#performance .issues-container::-webkit-scrollbar-thumb:hover {
    background: #0056b3;
}

/* Custom scrollbar for QA breakdown */
#performance .qa-breakdown-scroll::-webkit-scrollbar {
    width: 5px;
}

#performance .qa-breakdown-scroll::-webkit-scrollbar-track {
    background: #f8f9fa;
    border-radius: 3px;
}

#performance .qa-breakdown-scroll::-webkit-scrollbar-thumb {
    background: #007bff;
    border-radius: 3px;
}

#performance .qa-breakdown-scroll::-webkit-scrollbar-thumb:hover {
    background: #0056b3;
}

/* Enhanced badges with better contrast */
#performance .badge.bg-danger {
    background-color: #dc3545 !important;
    color: white !important;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(220,53,69,0.3);
}

#performance .badge.bg-success {
    background-color: #198754 !important;
    color: white !important;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(25,135,84,0.3);
}

#performance .badge.bg-warning {
    background-color: #ffc107 !important;
    color: #212529 !important;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(255,193,7,0.3);
}

#performance .badge.bg-info {
    background-color: #0dcaf0 !important;
    color: #212529 !important;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(13,202,240,0.3);
}

/* QA Status items with better styling */
#performance .mb-3 .d-flex {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 1rem;
    transition: all 0.2s ease;
    margin-bottom: 0.75rem;
}

#performance .mb-3 .d-flex:hover {
    border-color: #007bff;
    box-shadow: 0 4px 12px rgba(0,123,255,0.2);
    transform: translateY(-2px);
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
}

#performance .mb-3 .d-flex strong {
    color: #212529 !important;
    font-weight: 700;
}

#performance .mb-3 .d-flex .text-muted {
    color: #495057 !important;
    font-weight: 600;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    #performance .performance-card {
        height: 440px !important;
        max-height: 440px !important;
        margin-bottom: 1rem;
    }
    
    #performance .performance-card .card-body {
        height: calc(100% - 60px) !important;
        max-height: calc(100% - 60px) !important;
        padding: 0.8rem;
    }
    
    #performance .col-lg-6.col-xl-4 {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
}

/* Ensure smooth transitions */
#performance .collapse {
    transition: all 0.3s ease;
}

#performance .collapse.show {
    animation: slideDown 0.3s ease;
}

/* Content fade transitions */
#performance .performance-content,
#performance .qa-breakdown-content {
    opacity: 1;
    transition: opacity 0.3s ease;
}

#performance .performance-content[style*="display: none"],
#performance .qa-breakdown-content[style*="display: none"] {
    opacity: 0;
}

/* Card header improvements */
#performance .card-header {
    border-bottom: none;
    border-radius: 12px 12px 0 0 !important;
}

/* Better text contrast */
#performance .text-muted {
    color: #495057 !important;
    font-weight: 600;
}

#performance .small.text-muted {
    color: #343a40 !important;
    font-weight: 600;
}

#performance .text-white-50 {
    color: rgba(255,255,255,0.8) !important;
    font-weight: 500;
}
</style>
                                <ul class="list-unstyled">
                                    <?php
                                    // Top performers: performance score >= 80% and not in other categories
                                    $topPerformers = array_filter($performanceData, function($d) {
                                        return $d['performance_score'] >= 80 && $d['error_rate'] <= 1.5;
                                    });
                                    foreach (array_slice($topPerformers, 0, 5) as $performer):
                                    ?>
                                    <li class="mb-1">
                                        <i class="fas fa-trophy text-warning"></i>
                                        <strong><?php echo htmlspecialchars($performer['full_name']); ?></strong>
                                        - <?php echo $performer['performance_display'] ?? $performer['performance_score'] . '%'; ?> (<?php echo $performer['grade']; ?>)
                                    </li>
                                    <?php endforeach; ?>
                                    <?php if (empty($topPerformers)): ?>
                                    <li class="text-muted"><i class="fas fa-info-circle text-info"></i> No top performers yet!</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6 class="text-warning">Needs Attention</h6>
                                <ul class="list-unstyled">
                                    <?php
                                    // Needs attention: performance score < 80% but error rate <= 1.5
                                    $needsAttention = array_filter($performanceData, function($d) {
                                        return $d['performance_score'] < 80 && $d['error_rate'] <= 1.5;
                                    });
                                    foreach (array_slice($needsAttention, 0, 5) as $resource):
                                    ?>
                                    <li class="mb-1">
                                        <i class="fas fa-exclamation-circle text-warning"></i>
                                        <strong><?php echo htmlspecialchars($resource['full_name']); ?></strong>
                                        - <?php echo $resource['performance_display'] ?? $resource['performance_score'] . '%'; ?> (<?php echo $resource['grade']; ?>)
                                    </li>
                                    <?php endforeach; ?>
                                    <?php if (empty($needsAttention)): ?>
                                    <li class="text-muted"><i class="fas fa-check-circle text-success"></i> All resources performing well!</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6 class="text-danger">High Error Rate</h6>
                                <ul class="list-unstyled">
                                    <?php
                                    $highErrors = array_filter($performanceData, function($d) {
                                        return $d['error_rate'] > 1.5;
                                    });
                                    usort($highErrors, function($a, $b) {
                                        return $b['error_rate'] - $a['error_rate'];
                                    });
                                    foreach (array_slice($highErrors, 0, 3) as $resource):
                                    ?>
                                    <li class="mb-1">
                                        <i class="fas fa-times-circle text-danger"></i>
                                        <strong><?php echo htmlspecialchars($resource['full_name']); ?></strong>
                                        - Error Rate: <?php echo $resource['error_rate']; ?>
                                    </li>
                                    <?php endforeach; ?>
                                    <?php if (empty($highErrors)): ?>
                                    <li class="text-muted"><i class="fas fa-check-circle text-success"></i> No high error rates detected!</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>
