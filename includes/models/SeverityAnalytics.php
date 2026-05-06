<?php

require_once __DIR__ . '/AnalyticsEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';

/**
 * Severity Analytics Engine
 * 
 * Analyzes issues by severity levels (Critical, High, Medium, Low) and calculates
 * distribution percentages, trend tracking, and resolution priorities.
 * 
 * Requirements: 6.1, 6.2, 6.4
 */
class SeverityAnalytics extends AnalyticsEngine {
    
    /**
     * Generate severity analytics report
     * 
     * @param int|null $projectId Optional project filter
     * @param int|null $clientId Optional client filter for access control
     * @return AnalyticsReport
     */
    public function generateReport($projectId = null, $clientId = null) {
        $cacheKey = $this->generateCacheKey('severity_analytics', $projectId, $clientId);
        
        if ($cached = $this->getCachedReport($cacheKey)) {
            return $cached;
        }
        
        $data = $this->calculateSeverityMetrics($projectId, $clientId);
        
        $report = new AnalyticsReport([
            'type' => 'severity_analytics',
            'title' => 'Severity Distribution Analysis',
            'description' => 'Analysis of issues by severity levels with distribution and trend tracking',
            'data' => $data,
            'metadata' => [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'total_issues' => $data['summary']['total_issues'],
                'critical_issues' => $data['summary']['critical_count'],
                'severity_score' => $data['summary']['severity_score']
            ],
            'visualization_config' => [
                'primary_chart' => [
                    'type' => 'bar',
                    'data_key' => 'issue_severity_distribution',
                    'title' => 'Issues by Severity Level',
                    'x_axis' => 'Severity Level',
                    'y_axis' => 'Issue Count',
                    'colors' => ['#7f1d1d', '#dc2626', '#ea580c', '#2563eb'] // Blocker, Critical, Major, Minor
                ],
                'secondary_chart' => [
                    'type' => 'pie',
                    'data_key' => 'issue_severity_breakdown',
                    'title' => 'Severity Distribution'
                ]
            ]
        ]);
        
        $this->cacheReport($cacheKey, $report);
        return $report;
    }
    
    /**
     * Calculate severity metrics and trends
     * 
     * @param int|null $projectId
     * @param int|null $clientId
     * @return array
     */
    private function calculateSeverityMetrics($projectId = null, $clientId = null) {
        $issues = $this->getFilteredIssues($projectId, $clientId);
        
        $severityCounts = [
            'Critical' => 0,
            'High' => 0,
            'Medium' => 0,
            'Low' => 0
        ];

        $issueSeverityCounts = [
            'Blocker' => 0,
            'Critical' => 0,
            'Major' => 0,
            'Minor' => 0
        ];
        
        $severityByStatus = [
            'Critical' => ['Open' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0],
            'High' => ['Open' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0],
            'Medium' => ['Open' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0]
        ];
        
        $severityByPage = [];
        $severityTrends = [];
        $resolutionTimes = [
            'Critical' => [],
            'High' => [],
            'Medium' => [],
            'Low' => []
        ];
        
        foreach ($issues as $issue) {
            $severity = $this->normalizeSeverity($issue['severity'] ?? 'Medium');
            $issueSeverity = $this->normalizeIssueSeverityTerm($issue['severity'] ?? 'major');
            $status = $issue['status'] ?? 'Open';
            $page = $issue['page_url'] ?? 'Unknown';
            $createdDate = $issue['created_at'] ?? date('Y-m-d');
            
            $severityCounts[$severity]++;
            $issueSeverityCounts[$issueSeverity]++;
            $severityByStatus[$severity][$status]++;
            
            // Track severity by page
            if (!isset($severityByPage[$page])) {
                $severityByPage[$page] = [
                    'Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0,
                    'total' => 0, 'severity_score' => 0
                ];
            }
            $severityByPage[$page][$severity]++;
            $severityByPage[$page]['total']++;
            
            // Track trends by month
            $month = date('Y-m', strtotime($createdDate));
            if (!isset($severityTrends[$month])) {
                $severityTrends[$month] = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0];
            }
            $severityTrends[$month][$severity]++;
            
            // Track resolution times
            if (in_array($status, ['Resolved', 'Closed']) && !empty($issue['resolved_at'])) {
                $resolutionTime = $this->calculateResolutionTime($issue['created_at'], $issue['resolved_at']);
                $resolutionTimes[$severity][] = $resolutionTime;
            }
        }
        
        $totalIssues = array_sum($severityCounts);
        
        // Calculate severity score (weighted by severity level)
        $severityScore = $this->calculateSeverityScore($severityCounts, $totalIssues);
        
        // Process page severity data
        $topSeverityPages = $this->processPageSeverityData($severityByPage);
        
        // Calculate resolution metrics
        $resolutionMetrics = $this->calculateResolutionMetrics($resolutionTimes);
        
        // Process trends
        $trendAnalysis = $this->processTrendData($severityTrends);
        
        return [
            'summary' => [
                'total_issues' => $totalIssues,
                'critical_count' => $severityCounts['Critical'],
                'high_count' => $severityCounts['High'],
                'medium_count' => $severityCounts['Medium'],
                'low_count' => $severityCounts['Low'],
                'severity_score' => $severityScore,
                'avg_resolution_time' => $resolutionMetrics['overall_avg']
            ],
            'severity_distribution' => [
                ['severity' => 'Critical', 'count' => $severityCounts['Critical'], 'percentage' => $this->calculatePercentage($severityCounts['Critical'], $totalIssues)],
                ['severity' => 'High', 'count' => $severityCounts['High'], 'percentage' => $this->calculatePercentage($severityCounts['High'], $totalIssues)],
                ['severity' => 'Medium', 'count' => $severityCounts['Medium'], 'percentage' => $this->calculatePercentage($severityCounts['Medium'], $totalIssues)],
                ['severity' => 'Low', 'count' => $severityCounts['Low'], 'percentage' => $this->calculatePercentage($severityCounts['Low'], $totalIssues)]
            ],
            'issue_severity_distribution' => [
                ['severity' => 'Blocker', 'count' => $issueSeverityCounts['Blocker'], 'percentage' => $this->calculatePercentage($issueSeverityCounts['Blocker'], $totalIssues)],
                ['severity' => 'Critical', 'count' => $issueSeverityCounts['Critical'], 'percentage' => $this->calculatePercentage($issueSeverityCounts['Critical'], $totalIssues)],
                ['severity' => 'Major', 'count' => $issueSeverityCounts['Major'], 'percentage' => $this->calculatePercentage($issueSeverityCounts['Major'], $totalIssues)],
                ['severity' => 'Minor', 'count' => $issueSeverityCounts['Minor'], 'percentage' => $this->calculatePercentage($issueSeverityCounts['Minor'], $totalIssues)]
            ],
            'severity_breakdown' => [
                ['label' => 'Critical', 'value' => $severityCounts['Critical'], 'color' => '#dc3545'],
                ['label' => 'High', 'value' => $severityCounts['High'], 'color' => '#fd7e14'],
                ['label' => 'Medium', 'value' => $severityCounts['Medium'], 'color' => '#ffc107'],
                ['label' => 'Low', 'value' => $severityCounts['Low'], 'color' => '#28a745']
            ],
            'issue_severity_breakdown' => [
                ['label' => 'Blocker', 'value' => $issueSeverityCounts['Blocker'], 'color' => '#7f1d1d'],
                ['label' => 'Critical', 'value' => $issueSeverityCounts['Critical'], 'color' => '#dc2626'],
                ['label' => 'Major', 'value' => $issueSeverityCounts['Major'], 'color' => '#ea580c'],
                ['label' => 'Minor', 'value' => $issueSeverityCounts['Minor'], 'color' => '#2563eb']
            ],
            'severity_by_status' => $severityByStatus,
            'top_severity_pages' => $topSeverityPages,
            'resolution_metrics' => $resolutionMetrics,
            'trend_analysis' => $trendAnalysis,
            'recommendations' => $this->generateSeverityRecommendations($severityCounts, $resolutionMetrics, $totalIssues)
        ];
    }
    
    /**
     * Normalize severity values to standard levels
     * 
     * @param string $severity
     * @return string
     */
    private function normalizeSeverity($severity) {
        $severity = $this->normalizeLowerText($severity);
        
        $mapping = [
            'critical' => 'Critical',
            'blocker' => 'Critical',
            'high' => 'High',
            'major' => 'High',
            'important' => 'High',
            'medium' => 'Medium',
            'moderate' => 'Medium',
            'normal' => 'Medium',
            'low' => 'Low',
            'minor' => 'Low',
            'trivial' => 'Low'
        ];
        
        return $mapping[$severity] ?? 'Medium';
    }

    /**
     * Normalize raw issue severity labels to the terms used in issue records.
     *
     * @param string $severity
     * @return string
     */
    private function normalizeIssueSeverityTerm($severity) {
        $severity = $this->normalizeLowerText($severity);

        $mapping = [
            'blocker' => 'Blocker',
            'critical' => 'Critical',
            'high' => 'Critical',
            'major' => 'Major',
            'medium' => 'Major',
            'moderate' => 'Major',
            'normal' => 'Major',
            'minor' => 'Minor',
            'low' => 'Minor',
            'trivial' => 'Minor'
        ];

        return $mapping[$severity] ?? 'Major';
    }
    
    /**
     * Calculate overall severity score
     * 
     * @param array $severityCounts
     * @param int $totalIssues
     * @return float
     */
    private function calculateSeverityScore($severityCounts, $totalIssues) {
        if ($totalIssues === 0) return 0;
        
        // Weight: Critical=4, High=3, Medium=2, Low=1
        $weightedScore = ($severityCounts['Critical'] * 4) + 
                        ($severityCounts['High'] * 3) + 
                        ($severityCounts['Medium'] * 2) + 
                        ($severityCounts['Low'] * 1);
        
        $maxPossibleScore = $totalIssues * 4; // If all were critical
        
        return round(($weightedScore / $maxPossibleScore) * 100, 1);
    }
    
    /**
     * Process page severity data
     * 
     * @param array $severityByPage
     * @return array
     */
    private function processPageSeverityData($severityByPage) {
        // Calculate severity score for each page
        foreach ($severityByPage as $page => &$data) {
            $data['severity_score'] = $this->calculateSeverityScore($data, $data['total']);
        }
        
        // Sort by severity score (highest first)
        uasort($severityByPage, function($a, $b) {
            return $b['severity_score'] - $a['severity_score'];
        });
        
        $result = [];
        $rank = 1;
        foreach (array_slice($severityByPage, 0, 10, true) as $page => $data) {
            $result[] = [
                'rank' => $rank++,
                'page' => $page,
                'total_issues' => $data['total'],
                'critical' => $data['Critical'],
                'high' => $data['High'],
                'medium' => $data['Medium'],
                'low' => $data['Low'],
                'severity_score' => $data['severity_score']
            ];
        }
        
        return $result;
    }
    
    /**
     * Calculate resolution time metrics
     * 
     * @param array $resolutionTimes
     * @return array
     */
    private function calculateResolutionMetrics($resolutionTimes) {
        $metrics = [
            'overall_avg' => 0,
            'by_severity' => []
        ];
        
        $allTimes = [];
        
        foreach ($resolutionTimes as $severity => $times) {
            if (empty($times)) {
                $metrics['by_severity'][$severity] = [
                    'avg_days' => 0,
                    'median_days' => 0,
                    'min_days' => 0,
                    'max_days' => 0,
                    'resolved_count' => 0
                ];
                continue;
            }
            
            $allTimes = array_merge($allTimes, $times);
            
            sort($times);
            $count = count($times);
            $median = $count % 2 === 0 ? 
                ($times[$count/2 - 1] + $times[$count/2]) / 2 : 
                $times[floor($count/2)];
            
            $metrics['by_severity'][$severity] = [
                'avg_days' => round(array_sum($times) / $count, 1),
                'median_days' => round($median, 1),
                'min_days' => min($times),
                'max_days' => max($times),
                'resolved_count' => $count
            ];
        }
        
        if (!empty($allTimes)) {
            $metrics['overall_avg'] = round(array_sum($allTimes) / count($allTimes), 1);
        }
        
        return $metrics;
    }
    
    /**
     * Process trend data
     * 
     * @param array $severityTrends
     * @return array
     */
    private function processTrendData($severityTrends) {
        ksort($severityTrends); // Sort by month
        
        $trendData = [];
        $previousMonth = null;
        
        foreach ($severityTrends as $month => $counts) {
            $totalForMonth = array_sum($counts);
            $severityScore = $this->calculateSeverityScore($counts, $totalForMonth);
            
            $trend = 'stable';
            $change = 0;
            
            if ($previousMonth !== null) {
                $prevTotal = array_sum($severityTrends[$previousMonth]);
                if ($prevTotal > 0) {
                    $change = (($totalForMonth - $prevTotal) / $prevTotal) * 100;
                    $trend = $change > 5 ? 'increasing' : ($change < -5 ? 'decreasing' : 'stable');
                }
            }
            
            $trendData[] = [
                'month' => $month,
                'total_issues' => $totalForMonth,
                'critical' => $counts['Critical'],
                'high' => $counts['High'],
                'medium' => $counts['Medium'],
                'low' => $counts['Low'],
                'severity_score' => $severityScore,
                'trend' => $trend,
                'change_percentage' => round($change, 1)
            ];
            
            $previousMonth = $month;
        }
        
        return [
            'monthly_data' => $trendData,
            'overall_trend' => $this->calculateOverallTrend($trendData),
            'trend_summary' => $this->generateTrendSummary($trendData)
        ];
    }
    
    /**
     * Calculate overall trend direction
     * 
     * @param array $trendData
     * @return string
     */
    private function calculateOverallTrend($trendData) {
        if (count($trendData) < 2) return 'insufficient_data';
        
        $first = reset($trendData);
        $last = end($trendData);
        
        $severityChange = $last['severity_score'] - $first['severity_score'];
        $volumeChange = $last['total_issues'] - $first['total_issues'];
        
        if ($severityChange > 5 || $volumeChange > 10) {
            return 'worsening';
        } elseif ($severityChange < -5 || $volumeChange < -10) {
            return 'improving';
        } else {
            return 'stable';
        }
    }
    
    /**
     * Generate trend summary
     * 
     * @param array $trendData
     * @return array
     */
    private function generateTrendSummary($trendData) {
        if (empty($trendData)) return [];
        
        $latest = end($trendData);
        $criticalTrend = 0;
        $highTrend = 0;
        
        if (count($trendData) >= 2) {
            $previous = $trendData[count($trendData) - 2];
            $criticalTrend = $latest['critical'] - $previous['critical'];
            $highTrend = $latest['high'] - $previous['high'];
        }
        
        return [
            'latest_month' => $latest['month'],
            'critical_trend' => $criticalTrend,
            'high_trend' => $highTrend,
            'severity_score_trend' => $latest['trend'],
            'key_insights' => $this->generateTrendInsights($trendData)
        ];
    }
    
    /**
     * Generate trend insights
     * 
     * @param array $trendData
     * @return array
     */
    private function generateTrendInsights($trendData) {
        $insights = [];
        
        if (count($trendData) < 2) {
            return ['Insufficient data for trend analysis'];
        }
        
        $latest = end($trendData);
        $previous = $trendData[count($trendData) - 2];
        
        // Critical issues trend
        if ($latest['critical'] > $previous['critical']) {
            $insights[] = "Critical issues increased by " . ($latest['critical'] - $previous['critical']) . " from last month";
        } elseif ($latest['critical'] < $previous['critical']) {
            $insights[] = "Critical issues decreased by " . ($previous['critical'] - $latest['critical']) . " from last month";
        }
        
        // Overall severity trend
        if ($latest['severity_score'] > $previous['severity_score']) {
            $insights[] = "Overall severity score increased, indicating more severe issues";
        } elseif ($latest['severity_score'] < $previous['severity_score']) {
            $insights[] = "Overall severity score decreased, indicating improvement";
        }
        
        return $insights;
    }
    
    /**
     * Generate severity-based recommendations
     * 
     * @param array $severityCounts
     * @param array $resolutionMetrics
     * @param int $totalIssues
     * @return array
     */
    private function generateSeverityRecommendations($severityCounts, $resolutionMetrics, $totalIssues) {
        $recommendations = [];
        
        // Critical issues priority
        if ($severityCounts['Critical'] > 0) {
            $recommendations[] = [
                'priority' => 'Critical',
                'category' => 'Critical Issues',
                'recommendation' => "Address {$severityCounts['Critical']} critical issues immediately as they represent severe accessibility barriers.",
                'impact' => 'Immediate user impact and compliance risk'
            ];
        }
        
        // High volume recommendations
        $highVolumeThreshold = $totalIssues * 0.3; // 30% of total
        if ($severityCounts['High'] > $highVolumeThreshold) {
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'High Severity Volume',
                'recommendation' => "High severity issues represent a large portion of total issues. Consider systematic review of common patterns.",
                'impact' => 'Significant user experience impact'
            ];
        }
        
        // Resolution time recommendations
        if (isset($resolutionMetrics['by_severity']['Critical']['avg_days']) && 
            $resolutionMetrics['by_severity']['Critical']['avg_days'] > 7) {
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'Resolution Time',
                'recommendation' => "Critical issues are taking an average of {$resolutionMetrics['by_severity']['Critical']['avg_days']} days to resolve. Consider faster escalation processes.",
                'impact' => 'Delayed resolution increases user impact'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Calculate resolution time in days
     * 
     * @param string $createdAt
     * @param string $resolvedAt
     * @return float
     */
    private function calculateResolutionTime($createdAt, $resolvedAt) {
        $created = new DateTime($createdAt);
        $resolved = new DateTime($resolvedAt);
        $diff = $resolved->diff($created);
        
        return $diff->days + ($diff->h / 24) + ($diff->i / 1440);
    }
}