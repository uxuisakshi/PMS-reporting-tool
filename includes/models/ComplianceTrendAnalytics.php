<?php

require_once __DIR__ . '/AnalyticsEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';
require_once __DIR__ . '/ClientComplianceScoreResolver.php';

/**
 * Compliance Trend Analytics Engine
 * 
 * Calculates compliance metrics across time periods, tracks resolution rates,
 * and identifies trend patterns for daily, weekly, monthly, and yearly analysis.
 * 
 * Requirements: 11.1, 11.2, 11.4
 */
class ComplianceTrendAnalytics extends AnalyticsEngine {
    private $complianceResolver;

    public function __construct() {
        parent::__construct();
        $this->complianceResolver = new ClientComplianceScoreResolver();
    }
    
    /**
     * Generate compliance trend analytics report
     * 
     * @param int|null $projectId Optional project filter
     * @param int|null $clientId Optional client filter for access control
     * @return AnalyticsReport
     */
    public function generateReport($projectId = null, $clientId = null) {
        $cacheKey = $this->generateCacheKey('compliance_trend', $projectId, $clientId);
        
        if ($cached = $this->getCachedReport($cacheKey)) {
            return $cached;
        }
        
        $data = $this->analyzeComplianceTrends($projectId, $clientId);
        
        $report = new AnalyticsReport([
            'type' => 'compliance_trend',
            'title' => 'Compliance Trend Analysis',
            'description' => 'Analysis of compliance metrics over time with resolution rates and trend patterns',
            'data' => $data,
            'metadata' => [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'current_compliance_score' => $data['summary']['current_compliance_score'],
                'trend_direction' => $data['summary']['trend_direction'],
                'resolution_rate' => $data['summary']['overall_resolution_rate']
            ],
            'visualization_config' => [
                'primary_chart' => [
                    'type' => 'line',
                    'data_key' => 'monthly_trends',
                    'title' => 'Monthly Compliance Score Trend',
                    'x_axis' => 'Month',
                    'y_axis' => 'Compliance Score (%)'
                ],
                'secondary_chart' => [
                    'type' => 'bar',
                    'data_key' => 'resolution_trends',
                    'title' => 'Monthly Resolution Rate',
                    'x_axis' => 'Month',
                    'y_axis' => 'Resolution Rate (%)'
                ]
            ]
        ]);
        
        $this->cacheReport($cacheKey, $report);
        return $report;
    }
    
    /**
     * Analyze compliance trends over time
     * 
     * @param int|null $projectId
     * @param int|null $clientId
     * @return array
     */
    private function analyzeComplianceTrends($projectId = null, $clientId = null) {
        $issues = $this->getFilteredIssues($projectId, $clientId);
        
        // Analyze trends by different time periods
        $dailyTrends = $this->analyzeDailyTrends($issues);
        $weeklyTrends = $this->analyzeWeeklyTrends($issues);
        $monthlyTrends = $this->analyzeMonthlyTrends($issues);
        $yearlyTrends = $this->analyzeYearlyTrends($issues);
        
        // Calculate resolution trends
        $resolutionTrends = $this->analyzeResolutionTrends($issues);
        
        // Identify patterns and forecasts
        $trendPatterns = $this->identifyTrendPatterns($monthlyTrends);
        $forecast = $this->generateForecast($monthlyTrends);
        
        // Calculate current metrics
        $currentMetrics = $this->calculateCurrentMetrics($issues);
        
        return [
            'summary' => [
                'current_compliance_score' => $currentMetrics['compliance_score'],
                'trend_direction' => $trendPatterns['overall_direction'],
                'overall_resolution_rate' => $currentMetrics['resolution_rate'],
                'resolved_issues' => $currentMetrics['resolved_issues'],
                'total_issues_tracked' => count($issues),
                'improvement_rate' => $trendPatterns['improvement_rate'],
                'forecast_next_month' => $forecast['next_month_score']
            ],
            'daily_trends' => array_slice($dailyTrends, -30), // Last 30 days
            'weekly_trends' => array_slice($weeklyTrends, -12), // Last 12 weeks
            'monthly_trends' => array_slice($monthlyTrends, -12), // Last 12 months
            'yearly_trends' => $yearlyTrends,
            'resolution_trends' => $resolutionTrends,
            'trend_patterns' => $trendPatterns,
            'forecast' => $forecast,
            'recommendations' => $this->generateTrendRecommendations($trendPatterns, $currentMetrics, $forecast)
        ];
    }
    
    /**
     * Analyze daily compliance trends
     * 
     * @param array $issues
     * @return array
     */
    private function analyzeDailyTrends($issues) {
        if (empty($issues)) {
            return [];
        }

        $dailyData = [];
        $createdByDate = [];
        $resolvedByDate = [];
        $eventDates = [];

        foreach ($issues as $issue) {
            $createdDate = date('Y-m-d', strtotime($issue['created_at'] ?? date('Y-m-d')));
            $createdByDate[$createdDate][] = $issue;
            $eventDates[] = $createdDate;

            if ($this->isResolvedTrendIssue($issue) && !empty($issue['resolved_at'])) {
                $resolvedDate = date('Y-m-d', strtotime($issue['resolved_at']));
                $resolvedByDate[$resolvedDate][] = $issue;
                $eventDates[] = $resolvedDate;
            }
        }

        $startDate = min($eventDates);
        $endDate = max(max($eventDates), date('Y-m-d'));
        $cursor = strtotime($startDate);
        $endTimestamp = strtotime($endDate);

        while ($cursor <= $endTimestamp) {
            $date = date('Y-m-d', $cursor);
            $dailyData[$date] = [
                'date' => $date,
                'new_issues' => 0,
                'resolved_issues' => 0,
                'total_issues' => 0,
                'cumulative_resolved' => 0,
                'compliance_score' => 0,
                'severity_breakdown' => []
            ];

            foreach ($createdByDate[$date] ?? [] as $issue) {
                $dailyData[$date]['new_issues']++;
                $severity = $issue['severity'] ?? 'Medium';
                $dailyData[$date]['severity_breakdown'][$severity] = ($dailyData[$date]['severity_breakdown'][$severity] ?? 0) + 1;
            }

            $dailyData[$date]['resolved_issues'] = count($resolvedByDate[$date] ?? []);
            $cursor = strtotime('+1 day', $cursor);
        }

        $runningTotal = 0;
        $runningResolved = 0;

        foreach ($dailyData as $date => &$data) {
            $runningTotal += $data['new_issues'];
            $runningResolved += $data['resolved_issues'];

            $data['total_issues'] = $runningTotal;
            $data['cumulative_resolved'] = $runningResolved;
            $data['compliance_score'] = $this->calculateComplianceScoreForDate($issues, $date);
        }

        return array_values($dailyData);
    }

    private function calculateComplianceScoreForDate(array $issues, string $date): float {
        $effectiveIssues = [];
        $dateTimestamp = strtotime($date . ' 23:59:59');

        foreach ($issues as $issue) {
            $createdAt = strtotime((string) ($issue['created_at'] ?? ''));
            if ($createdAt === false || $createdAt > $dateTimestamp) {
                continue;
            }

            $effectiveIssue = $issue;
            $resolvedAt = strtotime((string) ($issue['resolved_at'] ?? ''));
            if ($resolvedAt === false || $resolvedAt > $dateTimestamp) {
                $effectiveIssue['status'] = 'Open';
                $effectiveIssue['status_name'] = 'Open';
            }

            $effectiveIssues[] = $effectiveIssue;
        }

        return $this->complianceResolver->calculateWcagComplianceFromIssues($effectiveIssues);
    }

    private function isResolvedTrendIssue(array $issue): bool {
        $status = strtolower(trim((string) ($issue['status_name'] ?? ($issue['status'] ?? ''))));
        return in_array($status, ['resolved', 'closed', 'fixed'], true);
    }
    
    /**
     * Analyze weekly compliance trends
     * 
     * @param array $issues
     * @return array
     */
    private function analyzeWeeklyTrends($issues) {
        $weeklyData = [];
        $issuesByWeek = [];
        
        foreach ($issues as $issue) {
            $week = date('Y-W', strtotime($issue['created_at'] ?? date('Y-m-d')));
            
            if (!isset($weeklyData[$week])) {
                $weeklyData[$week] = [
                    'week' => $week,
                    'week_start' => date('Y-m-d', strtotime($week . '-1')),
                    'new_issues' => 0,
                    'resolved_issues' => 0,
                    'compliance_score' => 0,
                    'avg_resolution_time' => 0,
                    'resolution_times' => []
                ];
            }
            
            $weeklyData[$week]['new_issues']++;
            $issuesByWeek[$week][] = $issue;
            
            if (in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']) && !empty($issue['resolved_at'])) {
                $resolvedWeek = date('Y-W', strtotime($issue['resolved_at']));
                if (isset($weeklyData[$resolvedWeek])) {
                    $weeklyData[$resolvedWeek]['resolved_issues']++;
                    
                    // Calculate resolution time
                    $resolutionTime = $this->calculateResolutionTime($issue['created_at'], $issue['resolved_at']);
                    $weeklyData[$resolvedWeek]['resolution_times'][] = $resolutionTime;
                }
            }
        }
        
        // Calculate metrics for each week
        ksort($weeklyData);
        foreach ($weeklyData as $week => &$data) {
            // Get week end date to evaluate issues as they were at that point in time
            $weekEndDate = date('Y-m-d', strtotime($week . '-7'));
            
            // Filter issues: only those created before or during week, and resolved (if resolved) by week end
            $weekEffectiveIssues = [];
            foreach ($issues as $issue) {
                $createdDate = $issue['created_at'] ?? '';
                if ($createdDate <= $weekEndDate . ' 23:59:59') {
                    // Include resolved status as of this week's end date
                    $resolvedDate = $issue['resolved_at'] ?? '';
                    $isResolved = !empty($resolvedDate) && $resolvedDate <= $weekEndDate . ' 23:59:59';
                    
                    $effectiveIssue = $issue;
                    if (!$isResolved) {
                        $effectiveIssue['status'] = 'Open';
                    }
                    $weekEffectiveIssues[] = $effectiveIssue;
                }
            }
            
            $data['compliance_score'] = $this->complianceResolver->calculateWcagComplianceFromIssues($weekEffectiveIssues);
            $data['avg_resolution_time'] = !empty($data['resolution_times']) ? 
                round(array_sum($data['resolution_times']) / count($data['resolution_times']), 1) : 0;
            unset($data['resolution_times']); // Remove raw data to save space
        }
        
        return array_values($weeklyData);
    }
    
    /**
     * Analyze monthly compliance trends
     * 
     * @param array $issues
     * @return array
     */
    private function analyzeMonthlyTrends($issues) {
        $monthlyData = [];
        $issuesByMonth = [];
        
        foreach ($issues as $issue) {
            $month = date('Y-m', strtotime($issue['created_at'] ?? date('Y-m-d')));
            
            if (!isset($monthlyData[$month])) {
                $monthlyData[$month] = [
                    'month' => $month,
                    'month_name' => date('M Y', strtotime($month . '-01')),
                    'new_issues' => 0,
                    'resolved_issues' => 0,
                    'compliance_score' => 0,
                    'resolution_rate' => 0,
                    'severity_distribution' => [],
                    'wcag_level_distribution' => ['A' => 0, 'AA' => 0, 'AAA' => 0, 'Unknown' => 0]
                ];
            }
            
            $monthlyData[$month]['new_issues']++;
            $issuesByMonth[$month][] = $issue;
            
            $severity = $issue['severity'] ?? 'Medium';
            $monthlyData[$month]['severity_distribution'][$severity] = ($monthlyData[$month]['severity_distribution'][$severity] ?? 0) + 1;
            
            $wcagLevel = $this->extractWCAGLevel($issue);
            $monthlyData[$month]['wcag_level_distribution'][$wcagLevel]++;
            
            if (in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']) && !empty($issue['resolved_at'])) {
                $resolvedMonth = date('Y-m', strtotime($issue['resolved_at']));
                if (isset($monthlyData[$resolvedMonth])) {
                    $monthlyData[$resolvedMonth]['resolved_issues']++;
                }
            }
        }
        
        // Calculate monthly metrics
        ksort($monthlyData);
        foreach ($monthlyData as $month => &$data) {
            $data['resolution_rate'] = $this->calculatePercentage($data['resolved_issues'], $data['new_issues']);
            
            // Get month end date to evaluate issues as they were at that point in time
            $monthEndDate = date('Y-m-t', strtotime($month . '-01'));
            
            // Filter issues: only those created before or during month, and resolved (if resolved) by month end
            $monthEffectiveIssues = [];
            foreach ($issues as $issue) {
                $createdDate = $issue['created_at'] ?? '';
                if ($createdDate <= $monthEndDate . ' 23:59:59') {
                    // Include resolved status as of this month's end date
                    $resolvedDate = $issue['resolved_at'] ?? '';
                    $isResolved = !empty($resolvedDate) && $resolvedDate <= $monthEndDate . ' 23:59:59';
                    
                    $effectiveIssue = $issue;
                    if (!$isResolved) {
                        $effectiveIssue['status'] = 'Open';
                    }
                    $monthEffectiveIssues[] = $effectiveIssue;
                }
            }
            
            $data['compliance_score'] = $this->complianceResolver->calculateWcagComplianceFromIssues($monthEffectiveIssues);
        }
        
        return array_values($monthlyData);
    }
    
    /**
     * Analyze yearly compliance trends
     * 
     * @param array $issues
     * @return array
     */
    private function analyzeYearlyTrends($issues) {
        $yearlyData = [];
        $issuesByYear = [];
        
        foreach ($issues as $issue) {
            $year = date('Y', strtotime($issue['created_at'] ?? date('Y-m-d')));
            
            if (!isset($yearlyData[$year])) {
                $yearlyData[$year] = [
                    'year' => $year,
                    'new_issues' => 0,
                    'resolved_issues' => 0,
                    'compliance_score' => 0,
                    'resolution_rate' => 0,
                    'avg_resolution_time' => 0,
                    'resolution_times' => []
                ];
            }
            
            $yearlyData[$year]['new_issues']++;
            $issuesByYear[$year][] = $issue;
            
            if (in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']) && !empty($issue['resolved_at'])) {
                $resolvedYear = date('Y', strtotime($issue['resolved_at']));
                if (isset($yearlyData[$resolvedYear])) {
                    $yearlyData[$resolvedYear]['resolved_issues']++;
                    
                    $resolutionTime = $this->calculateResolutionTime($issue['created_at'], $issue['resolved_at']);
                    $yearlyData[$resolvedYear]['resolution_times'][] = $resolutionTime;
                }
            }
        }
        
        // Calculate yearly metrics
        ksort($yearlyData);
        foreach ($yearlyData as $year => &$data) {
            $data['resolution_rate'] = $this->calculatePercentage($data['resolved_issues'], $data['new_issues']);
            
            // Get year end date to evaluate issues as they were at that point in time
            $yearEndDate = date('Y-12-31', strtotime($year . '-01-01'));
            
            // Filter issues: only those created before or during year, and resolved (if resolved) by year end
            $yearEffectiveIssues = [];
            foreach ($issues as $issue) {
                $createdDate = $issue['created_at'] ?? '';
                if ($createdDate <= $yearEndDate . ' 23:59:59') {
                    // Include resolved status as of this year's end date
                    $resolvedDate = $issue['resolved_at'] ?? '';
                    $isResolved = !empty($resolvedDate) && $resolvedDate <= $yearEndDate . ' 23:59:59';
                    
                    $effectiveIssue = $issue;
                    if (!$isResolved) {
                        $effectiveIssue['status'] = 'Open';
                    }
                    $yearEffectiveIssues[] = $effectiveIssue;
                }
            }
            
            $data['compliance_score'] = $this->complianceResolver->calculateWcagComplianceFromIssues($yearEffectiveIssues);
            $data['avg_resolution_time'] = !empty($data['resolution_times']) ? 
                round(array_sum($data['resolution_times']) / count($data['resolution_times']), 1) : 0;
            unset($data['resolution_times']);
        }
        
        return array_values($yearlyData);
    }
    
    /**
     * Analyze resolution trends
     * 
     * @param array $issues
     * @return array
     */
    private function analyzeResolutionTrends($issues) {
        $resolutionData = [];
        
        foreach ($issues as $issue) {
            if (in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']) && !empty($issue['resolved_at'])) {
                $month = date('Y-m', strtotime($issue['resolved_at']));
                
                if (!isset($resolutionData[$month])) {
                    $resolutionData[$month] = [
                        'month' => $month,
                        'month_name' => date('M Y', strtotime($month . '-01')),
                        'resolved_count' => 0,
                        'resolution_times' => [],
                        'severity_resolved' => ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0]
                    ];
                }
                
                $resolutionData[$month]['resolved_count']++;
                
                $resolutionTime = $this->calculateResolutionTime($issue['created_at'], $issue['resolved_at']);
                $resolutionData[$month]['resolution_times'][] = $resolutionTime;
                
                $severity = ucfirst(strtolower((string) ($issue['severity'] ?? 'Medium')));
                if (!isset($resolutionData[$month]['severity_resolved'][$severity])) {
                    $resolutionData[$month]['severity_resolved'][$severity] = 0;
                }
                $resolutionData[$month]['severity_resolved'][$severity]++;
            }
        }
        
        // Calculate resolution metrics
        ksort($resolutionData);
        foreach ($resolutionData as $month => &$data) {
            $data['avg_resolution_time'] = !empty($data['resolution_times']) ? 
                round(array_sum($data['resolution_times']) / count($data['resolution_times']), 1) : 0;
            $data['median_resolution_time'] = $this->calculateMedian($data['resolution_times']);
            unset($data['resolution_times']);
        }
        
        return array_values($resolutionData);
    }
    
    /**
     * Identify trend patterns
     * 
     * @param array $monthlyTrends
     * @return array
     */
    private function identifyTrendPatterns($monthlyTrends) {
        if (count($monthlyTrends) < 3) {
            return [
                'overall_direction' => 'insufficient_data',
                'improvement_rate' => 0,
                'volatility' => 0,
                'seasonal_patterns' => [],
                'key_insights' => ['Insufficient data for trend analysis']
            ];
        }
        
        $complianceScores = array_column($monthlyTrends, 'compliance_score');
        $resolutionRates = array_column($monthlyTrends, 'resolution_rate');
        
        // Calculate overall direction
        $overallDirection = $this->calculateOverallDirection($complianceScores);
        
        // Calculate improvement rate
        $improvementRate = $this->calculateImprovementRate($complianceScores);
        
        // Calculate volatility
        $volatility = $this->calculateVolatility($complianceScores);
        
        // Identify seasonal patterns
        $seasonalPatterns = $this->identifySeasonalPatterns($monthlyTrends);
        
        // Generate insights
        $keyInsights = $this->generateTrendInsights($monthlyTrends, $overallDirection, $improvementRate);
        
        return [
            'overall_direction' => $overallDirection,
            'improvement_rate' => $improvementRate,
            'volatility' => round($volatility, 1),
            'seasonal_patterns' => $seasonalPatterns,
            'key_insights' => $keyInsights,
            'correlation_score_resolution' => $this->calculateCorrelation($complianceScores, $resolutionRates)
        ];
    }
    
    /**
     * Generate forecast for future compliance
     * 
     * @param array $monthlyTrends
     * @return array
     */
    private function generateForecast($monthlyTrends) {
        if (count($monthlyTrends) < 6) {
            return [
                'next_month_score' => 0,
                'next_quarter_trend' => 'unknown',
                'confidence_level' => 'low',
                'forecast_insights' => ['Insufficient historical data for reliable forecast']
            ];
        }
        
        $recentTrends = array_slice($monthlyTrends, -6); // Last 6 months
        $complianceScores = array_column($recentTrends, 'compliance_score');
        
        // Simple linear regression for next month prediction
        $nextMonthScore = $this->predictNextValue($complianceScores);
        
        // Determine trend direction for next quarter
        $trendDirection = $this->predictTrendDirection($complianceScores);
        
        // Calculate confidence based on data consistency
        $confidenceLevel = $this->calculateForecastConfidence($complianceScores);
        
        // Generate forecast insights
        $forecastInsights = $this->generateForecastInsights($nextMonthScore, $trendDirection, $recentTrends);
        
        return [
            'next_month_score' => round($nextMonthScore, 1),
            'next_quarter_trend' => $trendDirection,
            'confidence_level' => $confidenceLevel,
            'forecast_insights' => $forecastInsights
        ];
    }
    
    /**
     * Calculate current compliance metrics
     * 
     * @param array $issues
     * @return array
     */
    private function calculateCurrentMetrics($issues) {
        $totalIssues = count($issues);
        $resolvedIssues = count(array_filter($issues, function($issue) {
            return in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']);
        }));
        
        $resolutionRate = $this->calculatePercentage($resolvedIssues, $totalIssues);
        $complianceScore = $this->complianceResolver->calculateWcagComplianceFromIssues($issues);
        
        return [
            'total_issues' => $totalIssues,
            'resolved_issues' => $resolvedIssues,
            'resolution_rate' => $resolutionRate,
            'compliance_score' => $complianceScore
        ];
    }
    
    /**
     * Calculate compliance score
     * 
     * @param int $totalIssues
     * @param int $resolvedIssues
     * @return float
     */
    private function calculateComplianceScore($totalIssues, $resolvedIssues) {
        if ($totalIssues === 0) return 100;
        
        // Compliance score = (resolved issues / total issues) * 100
        // But also consider the absolute number of issues
        $resolutionRate = ($resolvedIssues / $totalIssues) * 100;
        
        // Penalty for high number of total issues
        $volumePenalty = min(20, $totalIssues / 10); // Max 20% penalty
        
        $complianceScore = max(0, $resolutionRate - $volumePenalty);
        
        return round($complianceScore, 1);
    }
    
    /**
     * Calculate weekly compliance score
     * 
     * @param array $weekData
     * @return float
     */
    private function calculateWeeklyComplianceScore($weekData) {
        $newIssues = $weekData['new_issues'];
        $resolvedIssues = $weekData['resolved_issues'];
        
        if ($newIssues === 0 && $resolvedIssues === 0) return 100;
        if ($newIssues === 0) return 100; // No new issues is perfect
        
        // Weekly score considers both resolution and new issue creation
        $resolutionScore = min(100, ($resolvedIssues / $newIssues) * 100);
        $creationPenalty = min(10, $newIssues / 5); // Penalty for creating many issues
        
        return round(max(0, $resolutionScore - $creationPenalty), 1);
    }
    
    /**
     * Calculate monthly compliance score
     * 
     * @param array $monthData
     * @return float
     */
    private function calculateMonthlyComplianceScore($monthData) {
        $newIssues = $monthData['new_issues'];
        $resolvedIssues = $monthData['resolved_issues'];
        
        // Base score from resolution rate
        $baseScore = $this->calculateComplianceScore($newIssues, $resolvedIssues);
        
        // Adjust for severity distribution
        $severityAdjustment = $this->calculateSeverityAdjustment($monthData['severity_distribution'], $newIssues);
        
        // Adjust for WCAG level distribution
        $wcagAdjustment = $this->calculateWCAGAdjustment($monthData['wcag_level_distribution'], $newIssues);
        
        $finalScore = $baseScore + $severityAdjustment + $wcagAdjustment;
        
        return round(max(0, min(100, $finalScore)), 1);
    }
    
    /**
     * Calculate yearly compliance score
     * 
     * @param array $yearData
     * @return float
     */
    private function calculateYearlyComplianceScore($yearData) {
        return $this->calculateComplianceScore($yearData['new_issues'], $yearData['resolved_issues']);
    }
    
    /**
     * Extract WCAG level from issue
     * 
     * @param array $issue
     * @return string
     */
    private function extractWCAGLevel($issue) {
        $content = $this->normalizeLowerText(($issue['title'] ?? '') . ' ' . ($issue['description'] ?? ''));
        
        // Check for explicit WCAG level mentions
        if (preg_match('/wcag\s*(2\.1|2\.0)?\s*level?\s*(aaa|aa|a)\b/i', $content, $matches)) {
            return strtoupper(end($matches));
        }
        
        // Infer from common patterns
        $levelAPatterns = ['alt text', 'keyboard', 'form label', 'heading'];
        $levelAAPatterns = ['color contrast', 'focus indicator', 'resize text'];
        $levelAAAPatterns = ['enhanced contrast', 'context help'];
        
        foreach ($levelAPatterns as $pattern) {
            if (strpos($content, $pattern) !== false) return 'A';
        }
        foreach ($levelAAPatterns as $pattern) {
            if (strpos($content, $pattern) !== false) return 'AA';
        }
        foreach ($levelAAAPatterns as $pattern) {
            if (strpos($content, $pattern) !== false) return 'AAA';
        }
        
        return 'Unknown';
    }
    
    /**
     * Calculate severity adjustment for compliance score
     * 
     * @param array $severityDistribution
     * @param int $totalIssues
     * @return float
     */
    private function calculateSeverityAdjustment($severityDistribution, $totalIssues) {
        if ($totalIssues === 0) return 0;
        
        // Penalty map based on actual DB severity enum values
        $penaltyMap = [
            'blocker'  => -15,
            'critical' => -10,
            'major'    => -5,
            'minor'    => -2,
            'low'      => 2,   // bonus for low severity issues
        ];
        
        $adjustment = 0;
        foreach ($severityDistribution as $severity => $count) {
            $penalty = $penaltyMap[$this->normalizeLowerText($severity)] ?? -3;
            $adjustment += ($count / $totalIssues) * $penalty;
        }
        
        return $adjustment;
    }
    
    /**
     * Calculate WCAG adjustment for compliance score
     * 
     * @param array $wcagDistribution
     * @param int $totalIssues
     * @return float
     */
    private function calculateWCAGAdjustment($wcagDistribution, $totalIssues) {
        if ($totalIssues === 0) return 0;
        
        // Penalty for Level A issues (most critical)
        $levelAPenalty = ($wcagDistribution['A'] / $totalIssues) * -8;
        $levelAAPenalty = ($wcagDistribution['AA'] / $totalIssues) * -4;
        $levelAAAPenalty = ($wcagDistribution['AAA'] / $totalIssues) * -2;
        
        return $levelAPenalty + $levelAAPenalty + $levelAAAPenalty;
    }
    
    /**
     * Calculate overall trend direction
     * 
     * @param array $values
     * @return string
     */
    private function calculateOverallDirection($values) {
        if (count($values) < 2) return 'stable';
        
        $first = reset($values);
        $last = end($values);
        $change = (($last - $first) / $first) * 100;
        
        if ($change > 5) return 'improving';
        if ($change < -5) return 'declining';
        return 'stable';
    }
    
    /**
     * Calculate improvement rate
     * 
     * @param array $values
     * @return float
     */
    private function calculateImprovementRate($values) {
        if (count($values) < 2) return 0;
        
        $first = reset($values);
        $last = end($values);
        
        if ($first === 0) return 0;
        
        return round((($last - $first) / $first) * 100, 1);
    }
    
    /**
     * Calculate volatility (standard deviation)
     * 
     * @param array $values
     * @return float
     */
    private function calculateVolatility($values) {
        if (count($values) < 2) return 0;
        
        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(function($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values);
        
        $variance = array_sum($squaredDiffs) / count($values);
        return sqrt($variance);
    }
    
    /**
     * Identify seasonal patterns
     * 
     * @param array $monthlyTrends
     * @return array
     */
    private function identifySeasonalPatterns($monthlyTrends) {
        $patterns = [];
        $monthlyAverages = [];
        
        foreach ($monthlyTrends as $trend) {
            $month = date('n', strtotime($trend['month'] . '-01')); // 1-12
            if (!isset($monthlyAverages[$month])) {
                $monthlyAverages[$month] = [];
            }
            $monthlyAverages[$month][] = $trend['compliance_score'];
        }
        
        // Calculate average for each month
        foreach ($monthlyAverages as $month => $scores) {
            $patterns[$month] = round(array_sum($scores) / count($scores), 1);
        }
        
        return $patterns;
    }
    
    /**
     * Generate trend insights
     * 
     * @param array $monthlyTrends
     * @param string $overallDirection
     * @param float $improvementRate
     * @return array
     */
    private function generateTrendInsights($monthlyTrends, $overallDirection, $improvementRate) {
        $insights = [];
        
        // Overall trend insight
        if ($overallDirection === 'improving') {
            $insights[] = "Compliance score is improving with a {$improvementRate}% increase over the period";
        } elseif ($overallDirection === 'declining') {
            $insights[] = "Compliance score is declining with a {$improvementRate}% decrease over the period";
        } else {
            $insights[] = "Compliance score remains stable with minimal variation";
        }
        
        // Recent performance insight
        if (count($monthlyTrends) >= 3) {
            $recent = array_slice($monthlyTrends, -3);
            $recentScores = array_column($recent, 'compliance_score');
            $recentTrend = $this->calculateOverallDirection($recentScores);
            
            if ($recentTrend !== $overallDirection) {
                $insights[] = "Recent 3-month trend shows {$recentTrend} performance, different from overall trend";
            }
        }
        
        // Resolution rate insight
        $latestMonth = end($monthlyTrends);
        if ($latestMonth['resolution_rate'] < 50) {
            $insights[] = "Current resolution rate of {$latestMonth['resolution_rate']}% is below recommended threshold";
        }
        
        return $insights;
    }
    
    /**
     * Predict next value using simple linear regression
     * 
     * @param array $values
     * @return float
     */
    private function predictNextValue($values) {
        $n = count($values);
        if ($n < 2) return end($values) ?: 0;
        
        $x = range(1, $n);
        $y = array_values($values);
        
        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumX2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] * $x[$i];
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        return $slope * ($n + 1) + $intercept;
    }
    
    /**
     * Predict trend direction
     * 
     * @param array $values
     * @return string
     */
    private function predictTrendDirection($values) {
        $predicted = $this->predictNextValue($values);
        $current = end($values);
        
        $change = (($predicted - $current) / $current) * 100;
        
        if ($change > 2) return 'improving';
        if ($change < -2) return 'declining';
        return 'stable';
    }
    
    /**
     * Calculate forecast confidence
     * 
     * @param array $values
     * @return string
     */
    private function calculateForecastConfidence($values) {
        $volatility = $this->calculateVolatility($values);
        
        if ($volatility < 5) return 'high';
        if ($volatility < 15) return 'medium';
        return 'low';
    }
    
    /**
     * Generate forecast insights
     * 
     * @param float $nextMonthScore
     * @param string $trendDirection
     * @param array $recentTrends
     * @return array
     */
    private function generateForecastInsights($nextMonthScore, $trendDirection, $recentTrends) {
        $insights = [];
        
        $currentScore = end($recentTrends)['compliance_score'];
        $scoreDiff = $nextMonthScore - $currentScore;
        
        if (abs($scoreDiff) > 2) {
            $direction = $scoreDiff > 0 ? 'increase' : 'decrease';
            $insights[] = "Predicted {$direction} of " . abs(round($scoreDiff, 1)) . " points next month";
        } else {
            $insights[] = "Compliance score expected to remain stable next month";
        }
        
        if ($trendDirection === 'improving') {
            $insights[] = "Positive trend expected to continue in the next quarter";
        } elseif ($trendDirection === 'declining') {
            $insights[] = "Declining trend may continue without intervention";
        }
        
        return $insights;
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
    
    /**
     * Calculate median of array
     * 
     * @param array $values
     * @return float
     */
    private function calculateMedian($values) {
        if (empty($values)) return 0;
        
        sort($values);
        $count = count($values);
        
        if ($count % 2 === 0) {
            return ($values[$count/2 - 1] + $values[$count/2]) / 2;
        } else {
            return $values[floor($count/2)];
        }
    }
    
    /**
     * Calculate correlation between two arrays
     * 
     * @param array $x
     * @param array $y
     * @return float
     */
    private function calculateCorrelation($x, $y) {
        if (count($x) !== count($y) || count($x) < 2) return 0;
        
        $n = count($x);
        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumX2 = 0;
        $sumY2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] * $x[$i];
            $sumY2 += $y[$i] * $y[$i];
        }
        
        $numerator = ($n * $sumXY) - ($sumX * $sumY);
        $denominator = sqrt((($n * $sumX2) - ($sumX * $sumX)) * (($n * $sumY2) - ($sumY * $sumY)));
        
        return $denominator != 0 ? round($numerator / $denominator, 3) : 0;
    }
    
    /**
     * Generate trend-specific recommendations
     * 
     * @param array $trendPatterns
     * @param array $currentMetrics
     * @param array $forecast
     * @return array
     */
    private function generateTrendRecommendations($trendPatterns, $currentMetrics, $forecast) {
        $recommendations = [];
        
        // Trend direction recommendation
        if ($trendPatterns['overall_direction'] === 'declining') {
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'Declining Trend',
                'recommendation' => 'Compliance trend is declining. Implement immediate corrective measures to reverse the trend.',
                'impact' => 'Critical for maintaining accessibility standards'
            ];
        }
        
        // Resolution rate recommendation
        if ($currentMetrics['resolution_rate'] < 60) {
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'Low Resolution Rate',
                'recommendation' => "Current resolution rate of {$currentMetrics['resolution_rate']}% is below target. Focus on issue resolution processes.",
                'impact' => 'Improve overall compliance score'
            ];
        }
        
        // Forecast-based recommendation
        if ($forecast['next_month_score'] < $currentMetrics['compliance_score']) {
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'Forecast Alert',
                'recommendation' => 'Forecast indicates potential decline next month. Proactive measures recommended.',
                'impact' => 'Prevent future compliance degradation'
            ];
        }
        
        // Volatility recommendation
        if ($trendPatterns['volatility'] > 15) {
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'High Volatility',
                'recommendation' => 'Compliance scores show high volatility. Implement consistent quality processes.',
                'impact' => 'Stabilize compliance performance'
            ];
        }
        
        return $recommendations;
    }
}