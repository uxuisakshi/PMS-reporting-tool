<?php

require_once __DIR__ . '/AnalyticsEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';
require_once __DIR__ . '/../exceptions/UnauthorizedAccessException.php';

/**
 * UserAffectedAnalytics - Analytics for users affected by issues
 * 
 * Calculates metrics based on usersaffected metadata values:
 * - Uses each mentioned users-affected value as a chart category
 * - Provides distribution analysis and mention summaries
 * 
 * Requirements: 4.1, 4.2, 4.4
 */
class UserAffectedAnalytics extends AnalyticsEngine {
    
    /**
     * Generate analytics report - required by AnalyticsEngine
     * 
     * @param int|null $projectId Optional project filter
     * @param int|null $clientId Optional client filter for access control
     * @return AnalyticsReport
     */
    public function generateReport($projectId = null, $clientId = null) {
        $cacheKey = $this->generateCacheKey('user_affected', $projectId, $clientId);
        
        if ($cached = $this->getCachedReport($cacheKey)) {
            return $cached;
        }
        
        $issues = $this->getFilteredIssues($projectId, $clientId);
        $data = $this->calculateUserAffectedMetrics($issues);
        
        $report = new AnalyticsReport([
            'type' => 'user_affected',
            'title' => 'User Affected Analytics',
            'description' => 'Analysis of issues by number of users affected',
            'data' => $data,
            'metadata' => [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'total_issues' => count($issues)
            ]
        ]);
        
        $this->cacheReport($cacheKey, $report);
        return $report;
    }
    
    /**
     * Generate user affected analytics report for single project
     * 
     * @param int $project_id Project ID
     * @param int $client_id Client ID (for access validation)
     * @param array $filters Additional filters
     * @return array Analytics report data
     */
    public function generateProjectReport($project_id, $client_id, $filters = []) {
        // Validate project access
        if (!$this->validateProjectAccess($client_id, $project_id)) {
            throw new UnauthorizedAccessException("Client does not have access to project {$project_id}");
        }
        
        // Check cache first
        $cache_key = $this->generateCacheKey('user_affected', $project_id, $filters);
        $cached_data = $this->getCachedData($cache_key);
        
        if ($cached_data) {
            return $cached_data;
        }
        
        // Get client-ready issues
        $issues = $this->getClientReadyIssues($project_id, $filters);
        
        // Generate analytics
        $analytics = $this->calculateUserAffectedMetrics($issues);
        
        // Create report structure
        $report_data = [
            'project_id' => $project_id,
            'total_issues' => count($issues),
            'distribution' => $analytics['distribution'],
            'summary' => $analytics['summary'],
            'impact_analysis' => $analytics['impact_analysis'],
            'trends' => $analytics['trends'],
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        // Cache the results
        $this->setCachedData($cache_key, $report_data);
        
        return $report_data;
    }
    
    /**
     * Generate user affected analytics report for multiple projects
     * 
     * @param int $client_id Client ID
     * @param array $filters Additional filters
     * @return array Analytics report data
     */
    public function generateUnifiedReport($client_id, $filters = []) {
        // Get assigned projects for client
        $project_ids = $this->getAssignedProjects($client_id);
        
        if (empty($project_ids)) {
            return $this->getEmptyReport();
        }
        
        // Check cache first
        $cache_key = $this->generateCacheKey('user_affected_unified', $project_ids, $filters);
        $cached_data = $this->getCachedData($cache_key);
        
        if ($cached_data) {
            return $cached_data;
        }
        
        // Get client-ready issues from all projects
        $issues = $this->getClientReadyIssuesMultiple($project_ids, $filters);
        
        // Generate analytics
        $analytics = $this->calculateUserAffectedMetrics($issues);
        
        // Add per-project breakdown
        $project_breakdown = $this->calculateProjectBreakdown($issues, $project_ids);
        
        // Create report structure
        $report_data = [
            'project_ids' => $project_ids,
            'total_issues' => count($issues),
            'distribution' => $analytics['distribution'],
            'summary' => $analytics['summary'],
            'impact_analysis' => $analytics['impact_analysis'],
            'trends' => $analytics['trends'],
            'project_breakdown' => $project_breakdown,
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        // Cache the results
        $this->setCachedData($cache_key, $report_data);
        
        return $report_data;
    }
    
    /**
     * Calculate user affected metrics from issues
     * 
     * @param array $issues Array of issues
     * @return array Calculated metrics
     */
    private function calculateUserAffectedMetrics($issues) {
        $issues = $this->hydrateUsersAffectedMetadata($issues);
        $distribution = $this->calculateDistribution($issues);
        $summary = $this->calculateSummary($issues, $distribution);
        $impact_analysis = $this->calculateImpactAnalysis($issues, $distribution);
        $trends = $this->calculateTrends($issues, 'week');
        
        return [
            'distribution' => $distribution,
            'summary' => $summary,
            'impact_analysis' => $impact_analysis,
            'trends' => $trends
        ];
    }
    
    /**
     * Calculate trends for user affected data
     * 
     * @param array $issues Array of issues
     * @param string $period Time period (day, week, month)
     * @return array Trend data
     */
    private function calculateTrends($issues, $period = 'week') {
        // Simple trend calculation - can be enhanced later
        $trends = [
            'period' => $period,
            'data_points' => [],
            'trend_direction' => 'stable',
            'change_percentage' => 0
        ];
        
        // Group issues by creation date
        $grouped = [];
        foreach ($issues as $issue) {
            $date = date('Y-m-d', strtotime($issue['created_at'] ?? 'now'));
            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $issue;
        }
        
        // Calculate trend points
        foreach ($grouped as $date => $dateIssues) {
            $totalUsers = array_sum(array_map(function($issue) {
                return (int)($issue['users_affected_count'] ?? 0);
            }, $dateIssues));
            
            $trends['data_points'][] = [
                'date' => $date,
                'issues_count' => count($dateIssues),
                'users_affected' => $totalUsers
            ];
        }
        
        return $trends;
    }
    
    /**
    * Calculate distribution of issues by mentioned users-affected labels
     * 
     * @param array $issues Array of issues
     * @return array Distribution data
     */
    private function calculateDistribution($issues) {
        $distribution = [];
        foreach ($issues as $issue) {
            $labels = $issue['users_affected_labels'] ?? [];
            foreach ($labels as $label) {
                if (!isset($distribution[$label])) {
                    $distribution[$label] = [
                        'count' => 0,
                        'percentage' => 0,
                        'range_label' => $label,
                    ];
                }
                $distribution[$label]['count']++;
            }
        }

        uasort($distribution, function($left, $right) {
            $countCompare = ($right['count'] ?? 0) <=> ($left['count'] ?? 0);
            if ($countCompare !== 0) {
                return $countCompare;
            }
            return strcasecmp((string)($left['range_label'] ?? ''), (string)($right['range_label'] ?? ''));
        });

        $totalMentions = array_sum(array_column($distribution, 'count'));
        foreach ($distribution as &$item) {
            $item['percentage'] = $totalMentions > 0 ? round((($item['count'] ?? 0) / $totalMentions) * 100, 2) : 0;
        }
        unset($item);

        return $distribution;
    }
    
    /**
     * Calculate summary statistics
     * 
     * @param array $issues Array of issues
     * @param array $distribution Distribution data
     * @return array Summary statistics
     */
    private function calculateSummary($issues, $distribution) {
        $user_counts = array_map(function($issue) {
            return (int)($issue['users_affected_count'] ?? 0);
        }, $issues);

        $total_users_affected = array_sum($user_counts);
        $avg_users_per_issue = count($issues) > 0 ? round($total_users_affected / count($issues), 1) : 0;

        $mostCommonLabel = '';
        $mostCommonCount = 0;
        foreach ($distribution as $label => $data) {
            if (($data['count'] ?? 0) > $mostCommonCount) {
                $mostCommonCount = (int)$data['count'];
                $mostCommonLabel = (string)$label;
            }
        }

        return [
            'total_issues' => count($issues),
            'total_users_affected' => $total_users_affected,
            'average_users_per_issue' => $avg_users_per_issue,
            'most_common_range' => $mostCommonLabel,
            'most_common_range_label' => $mostCommonLabel,
            'high_impact_issues' => count(array_filter($issues, function($issue) {
                return !empty($issue['users_affected_labels']);
            })),
            'low_impact_issues' => count(array_filter($issues, function($issue) {
                return empty($issue['users_affected_labels']);
            })),
            'distinct_user_groups' => count($distribution),
            'top_user_group' => $mostCommonLabel,
            'top_user_group_count' => $mostCommonCount,
            'issues_with_user_mentions' => count(array_filter($issues, function($issue) {
                return !empty($issue['users_affected_labels']);
            }))
        ];
    }
    
    /**
     * Calculate impact analysis
     * 
     * @param array $issues Array of issues
     * @param array $distribution Distribution data
     * @return array Impact analysis
     */
    private function calculateImpactAnalysis($issues, $distribution) {
        $total_issues = count($issues);
        $issuesWithMentions = count(array_filter($issues, function($issue) {
            return !empty($issue['users_affected_labels']);
        }));
        $critical_issues_percentage = $total_issues > 0
            ? round(($issuesWithMentions / $total_issues) * 100, 1)
            : 0;

        $potential_reduction = $this->calculatePotentialReduction($issues);
        $topCategories = array_slice($distribution, 0, 3, true);

        return [
            'critical_issues_count' => $issuesWithMentions,
            'critical_issues_percentage' => $critical_issues_percentage,
            'potential_user_impact_reduction' => $potential_reduction,
            'priority_recommendation' => $this->getPriorityRecommendation($distribution),
            'impact_score' => $this->calculateUserImpactScore($issues),
            'top_categories' => $topCategories,
        ];
    }
    
    /**
     * Calculate project breakdown for unified reports
     * 
     * @param array $issues Array of issues
     * @param array $project_ids Array of project IDs
     * @return array Project breakdown
     */
    private function calculateProjectBreakdown($issues, $project_ids) {
        $breakdown = [];
        
        // Group issues by project
        $issues_by_project = $this->aggregateByField($issues, 'project_id');
        
        foreach ($project_ids as $project_id) {
            $project_issues = [];
            if (isset($issues_by_project[$project_id])) {
                // Extract the actual issues from the aggregated structure
                foreach ($issues as $issue) {
                    if ($issue['project_id'] == $project_id) {
                        $project_issues[] = $issue;
                    }
                }
            }
            
            $project_distribution = $this->calculateDistribution($project_issues);
            $project_summary = $this->calculateSummary($project_issues, $project_distribution);
            
            $breakdown[$project_id] = [
                'issue_count' => count($project_issues),
                'distribution' => $project_distribution,
                'summary' => $project_summary
            ];
        }
        
        return $breakdown;
    }
    
    /**
    /**
     * Calculate potential user impact reduction
     * 
     * @param array $issues Array of issues
     * @return array Potential reduction data
     */
    private function calculatePotentialReduction($issues) {
        $high_impact_issues = array_filter($issues, function($issue) {
            return count($issue['users_affected_labels'] ?? []) > 0;
        });

        $potential_users_helped = array_sum(array_map(function($issue) {
            return (int)($issue['users_affected_count'] ?? 0);
        }, $high_impact_issues));
        
        return [
            'high_impact_issues_count' => count($high_impact_issues),
            'potential_users_helped' => $potential_users_helped,
            'percentage_of_total' => count($issues) > 0 ? 
                round((count($high_impact_issues) / count($issues)) * 100, 1) : 0
        ];
    }
    
    /**
     * Get priority recommendation based on distribution
     * 
     * @param array $distribution Distribution data
     * @return string Priority recommendation
     */
    private function getPriorityRecommendation($distribution) {
        if (empty($distribution)) {
            return 'No users affected categories were tagged on the current issue set.';
        }

        $top = reset($distribution);
        $label = (string)($top['range_label'] ?? 'Unknown');
        $count = (int)($top['count'] ?? 0);
        return "Prioritize issues tagged for {$label} because they appear on {$count} issue(s).";
    }
    
    /**
     * Calculate overall impact score
     * 
     * @param array $issues Array of issues
     * @return float Impact score (0-100)
     */
    protected function calculateUserImpactScore($issues) {
        if (empty($issues)) {
            return 0.0;
        }
        
        $total_score = 0;
        $max_possible_score = 0;
        
        foreach ($issues as $issue) {
            $mentions = (int)($issue['users_affected_count'] ?? 0);
            $score = min(100, $mentions * 25);
            
            $total_score += $score;
            $max_possible_score += 100;
        }
        
        return round(($total_score / $max_possible_score) * 100, 1);
    }
    
    /**
     * Get empty report structure
     * 
     * @return array Empty report data
     */
    protected function getEmptyReport() {
        return [
            'project_ids' => [],
            'total_issues' => 0,
            'distribution' => [],
            'summary' => [
                'total_issues' => 0,
                'total_users_affected' => 0,
                'average_users_per_issue' => 0,
                'most_common_range' => '',
                'most_common_range_label' => '',
                'high_impact_issues' => 0,
                'low_impact_issues' => 0,
                'distinct_user_groups' => 0,
                'top_user_group' => '',
                'top_user_group_count' => 0,
                'issues_with_user_mentions' => 0
            ],
            'impact_analysis' => [
                'critical_issues_count' => 0,
                'critical_issues_percentage' => 0,
                'potential_user_impact_reduction' => [
                    'high_impact_issues_count' => 0,
                    'potential_users_helped' => 0,
                    'percentage_of_total' => 0
                ],
                'priority_recommendation' => 'No issues found',
                'impact_score' => 0.0
            ],
            'trends' => [],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Save analytics report to database
     * 
     * @param array $report_data Report data
     * @param int $client_id Client ID
     * @param array $project_ids Project IDs
     * @return AnalyticsReport Saved report instance
     */
    public function saveReport($report_data, $client_id, $project_ids) {
        $report = AnalyticsReport::create(
            'user_affected',
            $project_ids,
            $client_id,
            $report_data
        );
        
        $report->save();
        return $report;
    }
    
    /**
     * Get chart configuration for visualization
     * 
     * @param array $report_data Report data
     * @return array Chart configuration
     */
    public function getChartConfig($report_data) {
        $distribution = $report_data['distribution'] ?? [];
        
        return [
            'type' => 'pie',
            'title' => 'Issues by Users Affected',
            'data' => [
                'labels' => array_map(function($range_data) {
                    return $range_data['range_label'];
                }, $distribution),
                'datasets' => [[
                    'data' => array_map(function($range_data) {
                        return $range_data['count'];
                    }, $distribution),
                    'backgroundColor' => [
                        '#28a745', // Green for very low impact
                        '#ffc107', // Yellow for low impact
                        '#fd7e14', // Orange for medium impact
                        '#dc3545'  // Red for high impact
                    ]
                ]]
            ],
            'options' => [
                'responsive' => true,
                'plugins' => [
                    'legend' => [
                        'position' => 'bottom'
                    ],
                    'tooltip' => [
                        'callbacks' => [
                            'label' => 'function(context) { return context.label + ": " + context.parsed + " issues (" + Math.round((context.parsed / context.dataset.data.reduce((a,b) => a+b, 0)) * 100) + "%)"; }'
                        ]
                    ]
                ]
            ]
        ];
    }

    private function hydrateUsersAffectedMetadata($issues) {
        if (empty($issues) || !$this->pdo) {
            return $issues;
        }

        $issueIds = array_values(array_unique(array_filter(array_map(function($issue) {
            return (int)($issue['id'] ?? 0);
        }, $issues))));

        if (empty($issueIds)) {
            return $issues;
        }

        $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
        $stmt = $this->pdo->prepare("SELECT issue_id, meta_value FROM issue_metadata WHERE meta_key = 'usersaffected' AND issue_id IN ($placeholders) ORDER BY id ASC");
        $stmt->execute($issueIds);

        $labelsByIssue = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $issueId = (int)($row['issue_id'] ?? 0);
            $labelsByIssue[$issueId] = array_merge($labelsByIssue[$issueId] ?? [], $this->parseUsersAffectedMetaValue($row['meta_value'] ?? ''));
        }

        foreach ($issues as &$issue) {
            $issueId = (int)($issue['id'] ?? 0);
            $labels = array_values(array_unique($labelsByIssue[$issueId] ?? []));
            $issue['users_affected_labels'] = $labels;
            $issue['users_affected_count'] = count($labels);
        }
        unset($issue);

        return $issues;
    }

    private function parseUsersAffectedMetaValue($value) {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $entry) {
                $items = array_merge($items, $this->parseUsersAffectedMetaValue($entry));
            }
            return $items;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }

        if ($value[0] === '[') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->parseUsersAffectedMetaValue($decoded);
            }
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), function($item) {
            return $item !== '';
        }));
    }
}