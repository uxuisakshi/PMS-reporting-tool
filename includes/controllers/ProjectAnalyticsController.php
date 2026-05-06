<?php
/**
 * ProjectAnalyticsController
 * 
 * Generates all 9 analytics reports filtered to a single project with enhanced
 * detail levels and project metadata. Provides comprehensive project-specific
 * analytics with drill-down capabilities.
 * 
 * Requirements: 13.1, 13.2, 13.4
 */

require_once __DIR__ . '/../models/ClientAccessControlManager.php';
require_once __DIR__ . '/../models/AnalyticsEngine.php';
require_once __DIR__ . '/../models/UserAffectedAnalytics.php';
require_once __DIR__ . '/../models/WCAGComplianceAnalytics.php';
require_once __DIR__ . '/../models/SeverityAnalytics.php';
require_once __DIR__ . '/../models/CommonIssuesAnalytics.php';
require_once __DIR__ . '/../models/BlockerIssuesAnalytics.php';
require_once __DIR__ . '/../models/PageIssuesAnalytics.php';
require_once __DIR__ . '/../models/CommentedIssuesAnalytics.php';
require_once __DIR__ . '/../models/ComplianceTrendAnalytics.php';
require_once __DIR__ . '/../models/ClientComplianceScoreResolver.php';
require_once __DIR__ . '/../models/VisualizationRenderer.php';
class ProjectAnalyticsController {
    private $accessControl;
    private $complianceResolver;
    private $visualization;
    private $analyticsEngines;
    private $db;
    
    public function __construct() {
        global $db;
        $this->db = $db;
        $this->accessControl = new ClientAccessControlManager();
        $this->complianceResolver = new ClientComplianceScoreResolver($this->accessControl);
        $this->visualization = new VisualizationRenderer();
        
        // Initialize all 9 analytics engines
        $this->analyticsEngines = [
            'user_affected' => new UserAffectedAnalytics(),
            'wcag_compliance' => new WCAGComplianceAnalytics(),
            'severity_analysis' => new SeverityAnalytics(),
            'common_issues' => new CommonIssuesAnalytics(),
            'blocker_issues' => new BlockerIssuesAnalytics(),
            'page_issues' => new PageIssuesAnalytics(),
            'commented_issues' => new CommentedIssuesAnalytics(),
            'compliance_trend' => new ComplianceTrendAnalytics()
        ];
    }
    
    /**
     * Generate comprehensive analytics for a single project
     * 
     * @param int $projectId Project ID
     * @param int $clientUserId Client user ID
     * @return array Complete project analytics data
     */
    public function generateProjectAnalytics($projectId, $clientUserId) {
        // Validate project access
        if (!$this->accessControl->hasProjectAccess($clientUserId, $projectId)) {
            throw new Exception('Access denied to project');
        }
        
        // Get project metadata
        $projectMetadata = $this->getProjectMetadata($projectId, $clientUserId);
        
        if (!$projectMetadata) {
            throw new Exception('Project not found or not accessible');
        }
        
        // Generate all analytics reports for this project
        $analyticsReports = $this->generateAllAnalytics($projectId, $clientUserId);
        
        // Create enhanced detail widgets
        $widgets = $this->createEnhancedProjectWidgets($analyticsReports, $projectId);
        
        // Get project-specific statistics
        $projectStats = $this->getProjectStatistics($projectId, $clientUserId);
        
        return [
            'success' => true,
            'project_id' => $projectId,
            'client_user_id' => $clientUserId,
            'project_metadata' => $projectMetadata,
            'project_statistics' => $projectStats,
            'analytics_widgets' => $widgets,
            'generated_at' => date('Y-m-d H:i:s'),
            'last_updated' => $projectMetadata['last_updated'] ?? null
        ];
    }
    
    
    /**
     * Get comprehensive project metadata including name, description, and last update timestamp
     */
    private function getProjectMetadata($projectId, $clientUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    p.id, p.title, p.description, p.status, p.created_at,
                    c.name as client_name,
                    cpa.assigned_at,
                    (SELECT MAX(i.updated_at) FROM issues i 
                     WHERE i.project_id = p.id AND i.client_ready = 1) as last_updated,
                    (SELECT COUNT(*) FROM issues i 
                     WHERE i.project_id = p.id AND i.client_ready = 1) as client_ready_issues_count
                FROM client_project_assignments cpa
                INNER JOIN projects p ON cpa.project_id = p.id
                LEFT JOIN clients c ON p.client_id = c.id
                WHERE cpa.client_user_id = ? AND cpa.project_id = ? AND cpa.is_active = 1
            ");
            $stmt->execute([$clientUserId, $projectId]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$project) {
                return null;
            }
            
            return [
                'id' => (int)$project['id'],
                'title' => $project['title'],
                'description' => $project['description'] ?: 'No description available',
                'status' => $project['status'],
                'client_name' => $project['client_name'],
                'assigned_at' => $project['assigned_at'],
                'created_at' => $project['created_at'],
                'last_updated' => $project['last_updated'] ?: $project['created_at'],
                'client_ready_issues_count' => (int)$project['client_ready_issues_count']
            ];
            
        } catch (Exception $e) {
            error_log('ProjectAnalyticsController getProjectMetadata error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate all 9 analytics reports filtered to the selected project only
     */
    private function generateAllAnalytics($projectId, $clientUserId) {
        $reports = [];
        foreach ($this->analyticsEngines as $type => $engine) {
            try {
                $report = $engine->generateReport($projectId, $clientUserId);
                $reports[$type] = $report;
            } catch (Exception $e) {
                error_log("Error generating {$type} analytics for project {$projectId}: " . $e->getMessage());
                $reports[$type] = null;
            }
        }
        return $reports;
    }
    
    /**
     * Create enhanced project widgets with detailed information and drill-down capabilities
     */
    private function createEnhancedProjectWidgets($analyticsReports, $projectId) {
        $widgets = [];
        foreach ($analyticsReports as $type => $report) {
            if ($report) {
                $widgets[$type] = [
                    'type' => 'analytics',
                    'title' => $this->getWidgetTitle($type),
                    'icon' => $this->getWidgetIcon($type),
                    'reportType' => $type,
                    'enhanced' => true,
                    'project_id' => $projectId,
                    'drillDownUrl' => buildClientProjectUrl((int) $projectId, '', '', ['report' => $type]) . '#analytics-report-' . rawurlencode($type),
                    'summary' => $this->getWidgetSummary($report->getData(), $type),
                    'insights' => $this->generateInsights($report->getData(), $type)
                ];
            }
        }
        return $widgets;
    }
    
    /**
     * Get comprehensive project statistics
     */
    private function getProjectStatistics($projectId, $clientUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_issues,
                    COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_issues,
                    COUNT(CASE WHEN priority = 'Critical' THEN 1 END) as critical_issues,
                    AVG(CASE WHEN users_affected IS NOT NULL THEN users_affected END) as avg_users_affected
                FROM issues 
                WHERE project_id = ? AND client_ready = 1
            ");
            $stmt->execute([$projectId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $totalIssues = (int)$stats['total_issues'];
            $resolvedIssues = (int)$stats['resolved_issues'];
            $complianceRate = $this->complianceResolver->resolveForClientUser((int) $clientUserId, [(int) $projectId]);
            
            return [
                'total_issues' => $totalIssues,
                'resolved_issues' => $resolvedIssues,
                'critical_issues' => (int)$stats['critical_issues'],
                'compliance_rate' => round($complianceRate, 1),
                'avg_users_affected' => round((float)$stats['avg_users_affected'], 1)
            ];
            
        } catch (Exception $e) {
            error_log('ProjectAnalyticsController getProjectStatistics error: ' . $e->getMessage());
            return [];
        }
    }
    
    private function getWidgetTitle($type) {
        $titles = [
            'user_affected' => 'User Impact Analysis',
            'wcag_compliance' => 'WCAG Compliance Status',
            'severity_analysis' => 'Issue Severity Analysis',
            'common_issues' => 'Most Common Issues',
            'blocker_issues' => 'Blocker Issues Analysis',
            'page_issues' => 'Page-Level Issue Analysis',
            'commented_issues' => 'Issues with Comments',
            'compliance_trend' => 'Compliance Trend Analysis'
        ];
        return $titles[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
    
    private function getWidgetIcon($type) {
        $icons = [
            'user_affected' => 'fas fa-users',
            'wcag_compliance' => 'fas fa-check-circle',
            'severity_analysis' => 'fas fa-exclamation-triangle',
            'common_issues' => 'fas fa-list-ul',
            'blocker_issues' => 'fas fa-ban',
            'page_issues' => 'fas fa-file-alt',
            'commented_issues' => 'fas fa-comments',
            'compliance_trend' => 'fas fa-chart-line'
        ];
        return $icons[$type] ?? 'fas fa-chart-bar';
    }
    
    private function getWidgetSummary($data, $type) {
        $summary = $data['summary'] ?? [];
        return [
            ['label' => 'Total Items', 'value' => $summary['total'] ?? 0],
            ['label' => 'Active Items', 'value' => $summary['active'] ?? 0],
            ['label' => 'Resolved Items', 'value' => $summary['resolved'] ?? 0],
            ['label' => 'Percentage', 'value' => round($summary['percentage'] ?? 0, 1) . '%']
        ];
    }
    
    private function generateInsights($data, $type) {
        return [
            [
                'type' => 'info',
                'title' => 'Analysis Complete',
                'message' => "Project analytics have been generated successfully for {$type}."
            ]
        ];
    }
}

