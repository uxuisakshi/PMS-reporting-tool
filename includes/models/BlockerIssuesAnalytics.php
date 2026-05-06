<?php

require_once __DIR__ . '/AnalyticsEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';

/**
 * Blocker Issues Analytics Engine
 * 
 * Identifies blocker issues, calculates resolution rates, and categorizes
 * by affected functionality and urgency levels.
 * 
 * Requirements: 8.1, 8.2, 8.4
 */
class BlockerIssuesAnalytics extends AnalyticsEngine {
    
    /**
     * Generate blocker issues analytics report
     * 
     * @param int|null $projectId Optional project filter
     * @param int|null $clientId Optional client filter for access control
     * @return AnalyticsReport
     */
    public function generateReport($projectId = null, $clientId = null) {
        $cacheKey = $this->generateCacheKey('blocker_issues_v2', $projectId, $clientId);
        
        if ($cached = $this->getCachedReport($cacheKey)) {
            return $cached;
        }
        
        $data = $this->analyzeBlockerIssues($projectId, $clientId);
        
        $report = new AnalyticsReport([
            'type' => 'blocker_issues',
            'title' => 'Blocker Issues Analysis',
            'description' => 'Analysis of blocker issues with resolution rates and functionality impact',
            'data' => $data,
            'metadata' => [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'total_blockers' => $data['summary']['total_blockers'],
                'active_blockers' => $data['summary']['active_blockers'],
                'resolution_rate' => $data['summary']['resolution_rate']
            ],
            'visualization_config' => [
                'primary_chart' => [
                    'type' => 'bar',
                    'data_key' => 'top_blockers',
                    'title' => 'Top 5 Blocker Issues',
                    'x_axis' => 'Issue',
                    'y_axis' => 'Impact Score'
                ],
                'secondary_chart' => [
                    'type' => 'pie',
                    'data_key' => 'functionality_breakdown',
                    'title' => 'Blockers by Functionality'
                ]
            ]
        ]);
        
        $this->cacheReport($cacheKey, $report);
        return $report;
    }
    
    /**
     * Analyze blocker issues
     * 
     * @param int|null $projectId
     * @param int|null $clientId
     * @return array
     */
    private function analyzeBlockerIssues($projectId = null, $clientId = null) {
        $issues = $this->getFilteredIssues($projectId, $clientId);
        
        // Identify blocker issues
        $blockerIssues = $this->identifyBlockerIssues($issues);
        
        // Categorize by functionality
        $functionalityBreakdown = $this->categorizeByfunctionality($blockerIssues);
        
        // Calculate resolution metrics
        $resolutionMetrics = $this->calculateResolutionMetrics($blockerIssues);
        
        // Analyze urgency levels
        $urgencyAnalysis = $this->analyzeUrgencyLevels($blockerIssues);
        
        // Get top blocker issues
        $topBlockers = $this->getTopBlockerIssues($blockerIssues);
        
        // Analyze trends
        $trendAnalysis = $this->analyzeTrends($blockerIssues);
        
        $totalBlockers = count($blockerIssues);
        $activeBlockers = count(array_filter($blockerIssues, function($issue) {
            return !in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']);
        }));
        
        return [
            'summary' => [
                'total_blockers' => $totalBlockers,
                'active_blockers' => $activeBlockers,
                'resolved_blockers' => $totalBlockers - $activeBlockers,
                'resolution_rate' => $this->calculatePercentage($totalBlockers - $activeBlockers, $totalBlockers),
                'avg_resolution_time' => $resolutionMetrics['avg_resolution_time'],
                'critical_blockers' => $this->countCriticalBlockers($blockerIssues)
            ],
            'top_blockers' => $topBlockers,
            'blocker_issue_list' => $this->buildBlockerIssueList($blockerIssues),
            'functionality_breakdown' => $functionalityBreakdown,
            'resolution_metrics' => $resolutionMetrics,
            'urgency_analysis' => $urgencyAnalysis,
            'trend_analysis' => $trendAnalysis,
            'impact_assessment' => $this->assessImpact($blockerIssues),
            'recommendations' => $this->generateBlockerRecommendations($blockerIssues, $resolutionMetrics, $urgencyAnalysis)
        ];
    }
    
    /**
     * Identify blocker issues from all issues
     * 
     * @param array $issues
     * @return array
     */
    private function identifyBlockerIssues($issues) {
        $blockerIssues = [];
        
        foreach ($issues as $issue) {
            if ($this->isBlockerIssue($issue)) {
                $blockerIssues[] = array_merge($issue, [
                    'blocker_type' => $this->classifyBlockerType($issue),
                    'functionality_impact' => $this->assessFunctionalityImpact($issue),
                    'urgency_level' => $this->assessUrgencyLevel($issue),
                    'impact_score' => $this->calculateBlockerImpactScore($issue)
                ]);
            }
        }
        
        return $blockerIssues;
    }
    
    /**
     * Determine if an issue is a blocker
     * 
     * @param array $issue
     * @return bool
     */
    private function isBlockerIssue($issue) {
        $severity = $this->normalizeLowerText($issue['severity'] ?? '');
        return $severity === 'blocker';
    }
    
    /**
     * Classify blocker type
     * 
     * @param array $issue
     * @return string
     */
    private function classifyBlockerType($issue) {
        $content = $this->normalizeLowerText(($issue['title'] ?? '') . ' ' . ($issue['description'] ?? ''));
        
        $types = [
            'Accessibility Blocker' => ['keyboard', 'screen reader', 'navigation', 'focus', 'alt text', 'aria'],
            'Functional Blocker' => ['function', 'feature', 'broken', 'error', 'fails', 'not working'],
            'Content Blocker' => ['content', 'text', 'missing', 'incorrect', 'wrong'],
            'Visual Blocker' => ['visual', 'display', 'layout', 'design', 'appearance'],
            'Performance Blocker' => ['slow', 'performance', 'timeout', 'loading', 'speed'],
            'Compliance Blocker' => ['wcag', 'compliance', 'standard', 'requirement', 'violation']
        ];
        
        foreach ($types as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    return $type;
                }
            }
        }
        
        return 'General Blocker';
    }
    
    /**
     * Assess functionality impact
     * 
     * @param array $issue
     * @return string
     */
    private function assessFunctionalityImpact($issue) {
        $content = $this->normalizeLowerText(($issue['title'] ?? '') . ' ' . ($issue['description'] ?? ''));
        
        $functionalities = [
            'Navigation' => ['navigation', 'menu', 'link', 'breadcrumb', 'tab'],
            'Forms' => ['form', 'input', 'submit', 'field', 'validation'],
            'Content Access' => ['content', 'text', 'read', 'view', 'access'],
            'Interactive Elements' => ['button', 'click', 'interactive', 'control'],
            'Media' => ['image', 'video', 'audio', 'media', 'graphic'],
            'Search' => ['search', 'find', 'filter', 'query'],
            'Authentication' => ['login', 'auth', 'password', 'account'],
            'E-commerce' => ['cart', 'checkout', 'purchase', 'payment'],
            'Communication' => ['contact', 'message', 'chat', 'email']
        ];
        
        foreach ($functionalities as $functionality => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    return $functionality;
                }
            }
        }
        
        return 'General Functionality';
    }
    
    /**
     * Assess urgency level
     * 
     * @param array $issue
     * @return string
     */
    private function assessUrgencyLevel($issue) {
        $severity = $this->normalizeLowerText($issue['severity'] ?? '');
        $priority = $this->normalizeLowerText($issue['priority'] ?? '');
        $content = $this->normalizeLowerText(($issue['title'] ?? '') . ' ' . ($issue['description'] ?? ''));
        
        // Critical urgency indicators
        $criticalIndicators = ['critical', 'immediate', 'showstopper', 'emergency'];
        foreach ($criticalIndicators as $indicator) {
            if (strpos($content . ' ' . $severity . ' ' . $priority, $indicator) !== false) {
                return 'Critical';
            }
        }
        
        // High urgency indicators
        $highIndicators = ['high', 'important', 'major', 'significant'];
        foreach ($highIndicators as $indicator) {
            if (strpos($content . ' ' . $severity . ' ' . $priority, $indicator) !== false) {
                return 'High';
            }
        }
        
        // Check for user impact indicators
        $userImpactIndicators = ['all users', 'many users', 'most users', 'cannot use'];
        foreach ($userImpactIndicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                return 'High';
            }
        }
        
        return 'Medium';
    }
    
    /**
     * Calculate blocker impact score
     * 
     * @param array $issue
     * @return float
     */
    private function calculateBlockerImpactScore($issue) {
        $baseScore = 50; // Base score for being a blocker
        
        // Severity multiplier
        $severityMultipliers = [
            'critical' => 2.0,
            'blocker' => 2.0,
            'high' => 1.5,
            'medium' => 1.0,
            'low' => 0.5
        ];
        
        $severity = $this->normalizeLowerText($issue['severity'] ?? 'medium');
        $severityMultiplier = $severityMultipliers[$severity] ?? 1.0;
        
        // Users affected multiplier
        $usersAffected = $issue['users_affected'] ?? 1;
        $userMultiplier = min(2.0, 1 + ($usersAffected / 100));
        
        // Age factor (older issues get higher priority)
        $createdAt = $issue['created_at'] ?? date('Y-m-d');
        $daysOld = (time() - strtotime($createdAt)) / (24 * 3600);
        $ageMultiplier = min(1.5, 1 + ($daysOld / 30) * 0.1);
        
        $score = $baseScore * $severityMultiplier * $userMultiplier * $ageMultiplier;
        
        return round($score, 1);
    }
    
    /**
     * Categorize blockers by functionality
     * 
     * @param array $blockerIssues
     * @return array
     */
    private function categorizeByfunctionality($blockerIssues) {
        $breakdown = [];
        
        foreach ($blockerIssues as $issue) {
            $functionality = $issue['functionality_impact'];
            
            if (!isset($breakdown[$functionality])) {
                $breakdown[$functionality] = [
                    'count' => 0,
                    'active' => 0,
                    'resolved' => 0,
                    'avg_impact_score' => 0,
                    'urgency_breakdown' => ['Critical' => 0, 'High' => 0, 'Medium' => 0]
                ];
            }
            
            $breakdown[$functionality]['count']++;
            $breakdown[$functionality]['avg_impact_score'] += $issue['impact_score'];
            $breakdown[$functionality]['urgency_breakdown'][$issue['urgency_level']]++;
            
            if (in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed'])) {
                $breakdown[$functionality]['resolved']++;
            } else {
                $breakdown[$functionality]['active']++;
            }
        }
        
        // Calculate averages and sort
        foreach ($breakdown as $functionality => &$data) {
            $data['avg_impact_score'] = $data['count'] > 0 ? 
                round($data['avg_impact_score'] / $data['count'], 1) : 0;
        }
        
        uasort($breakdown, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return $breakdown;
    }
    
    /**
     * Calculate resolution metrics
     * 
     * @param array $blockerIssues
     * @return array
     */
    private function calculateResolutionMetrics($blockerIssues) {
        $resolutionTimes = [];
        $resolutionsByUrgency = ['Critical' => [], 'High' => [], 'Medium' => []];
        $resolutionsByType = [];
        
        foreach ($blockerIssues as $issue) {
            if (in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']) && 
                !empty($issue['resolved_at'])) {
                
                $resolutionTime = $this->calculateResolutionTime($issue['created_at'], $issue['resolved_at']);
                $resolutionTimes[] = $resolutionTime;
                
                $urgency = $issue['urgency_level'];
                $resolutionsByUrgency[$urgency][] = $resolutionTime;
                
                $type = $issue['blocker_type'];
                if (!isset($resolutionsByType[$type])) {
                    $resolutionsByType[$type] = [];
                }
                $resolutionsByType[$type][] = $resolutionTime;
            }
        }
        
        $avgResolutionTime = !empty($resolutionTimes) ? 
            round(array_sum($resolutionTimes) / count($resolutionTimes), 1) : 0;
        
        // Calculate metrics by urgency
        $urgencyMetrics = [];
        foreach ($resolutionsByUrgency as $urgency => $times) {
            $urgencyMetrics[$urgency] = [
                'avg_days' => !empty($times) ? round(array_sum($times) / count($times), 1) : 0,
                'count' => count($times),
                'median_days' => $this->calculateMedian($times)
            ];
        }
        
        // Calculate metrics by type
        $typeMetrics = [];
        foreach ($resolutionsByType as $type => $times) {
            $typeMetrics[$type] = [
                'avg_days' => !empty($times) ? round(array_sum($times) / count($times), 1) : 0,
                'count' => count($times)
            ];
        }
        
        return [
            'avg_resolution_time' => $avgResolutionTime,
            'total_resolved' => count($resolutionTimes),
            'by_urgency' => $urgencyMetrics,
            'by_type' => $typeMetrics,
            'resolution_distribution' => $this->calculateResolutionDistribution($resolutionTimes)
        ];
    }
    
    /**
     * Analyze urgency levels
     * 
     * @param array $blockerIssues
     * @return array
     */
    private function analyzeUrgencyLevels($blockerIssues) {
        $urgencyBreakdown = ['Critical' => 0, 'High' => 0, 'Medium' => 0];
        $urgencyByStatus = [
            'Critical' => ['Open' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0],
            'High' => ['Open' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0],
            'Medium' => ['Open' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0]
        ];
        
        foreach ($blockerIssues as $issue) {
            $urgency = $issue['urgency_level'];
            $status = $issue['status'] ?? 'Open';
            
            $urgencyBreakdown[$urgency]++;
            $urgencyByStatus[$urgency][$status]++;
        }
        
        return [
            'breakdown' => $urgencyBreakdown,
            'by_status' => $urgencyByStatus,
            'critical_percentage' => $this->calculatePercentage($urgencyBreakdown['Critical'], count($blockerIssues))
        ];
    }
    
    /**
     * Get top blocker issues
     * 
     * @param array $blockerIssues
     * @return array
     */
    private function getTopBlockerIssues($blockerIssues) {
        // Sort by impact score
        usort($blockerIssues, function($a, $b) {
            return $b['impact_score'] - $a['impact_score'];
        });
        
        $topBlockers = [];
        foreach (array_slice($blockerIssues, 0, 5) as $index => $issue) {
            $topBlockers[] = [
                'rank' => $index + 1,
                'title' => $issue['title'] ?? 'Untitled Issue',
                'blocker_type' => $issue['blocker_type'],
                'functionality_impact' => $issue['functionality_impact'],
                'urgency_level' => $issue['urgency_level'],
                'impact_score' => $issue['impact_score'],
                'status' => $issue['status'] ?? 'Open',
                'age_days' => $this->calculateAgeDays($issue['created_at'] ?? date('Y-m-d')),
                'users_affected' => $issue['users_affected'] ?? 1
            ];
        }
        
        return $topBlockers;
    }

    /**
     * Build a full blocker issue list for dashboard rendering.
     *
     * @param array $blockerIssues
     * @return array
     */
    private function buildBlockerIssueList($blockerIssues) {
        usort($blockerIssues, function($left, $right) {
            $leftResolved = in_array($left['status'] ?? 'Open', ['Resolved', 'Closed'], true);
            $rightResolved = in_array($right['status'] ?? 'Open', ['Resolved', 'Closed'], true);

            if ($leftResolved !== $rightResolved) {
                return $leftResolved ? 1 : -1;
            }

            return ($right['impact_score'] ?? 0) <=> ($left['impact_score'] ?? 0);
        });

        return array_map(function($issue) {
            return [
                'id' => (int) ($issue['id'] ?? 0),
                'project_id' => (int) ($issue['project_id'] ?? 0),
                'page_id' => (int) ($issue['page_id'] ?? 0),
                'issue_key' => (string) ($issue['issue_key'] ?? ''),
                'title' => (string) ($issue['title'] ?? 'Untitled Issue'),
                'status' => (string) ($issue['status'] ?? 'Open'),
                'urgency_level' => (string) ($issue['urgency_level'] ?? 'Medium'),
                'blocker_type' => (string) ($issue['blocker_type'] ?? 'General Blocker'),
                'impact_score' => (float) ($issue['impact_score'] ?? 0),
                'page_url' => (string) ($issue['page_url'] ?? ''),
            ];
        }, $blockerIssues);
    }
    
    /**
     * Analyze trends in blocker issues
     * 
     * @param array $blockerIssues
     * @return array
     */
    private function analyzeTrends($blockerIssues) {
        $monthlyData = [];
        
        foreach ($blockerIssues as $issue) {
            $month = date('Y-m', strtotime($issue['created_at'] ?? date('Y-m-d')));
            
            if (!isset($monthlyData[$month])) {
                $monthlyData[$month] = [
                    'total' => 0,
                    'by_urgency' => ['Critical' => 0, 'High' => 0, 'Medium' => 0],
                    'by_type' => [],
                    'resolved' => 0
                ];
            }
            
            $monthlyData[$month]['total']++;
            $monthlyData[$month]['by_urgency'][$issue['urgency_level']]++;
            
            $type = $issue['blocker_type'];
            if (!isset($monthlyData[$month]['by_type'][$type])) {
                $monthlyData[$month]['by_type'][$type] = 0;
            }
            $monthlyData[$month]['by_type'][$type]++;
            
            if (in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed'])) {
                $monthlyData[$month]['resolved']++;
            }
        }
        
        ksort($monthlyData);
        
        return [
            'monthly_data' => $monthlyData,
            'trend_direction' => $this->calculateTrendDirection($monthlyData),
            'insights' => $this->generateTrendInsights($monthlyData)
        ];
    }
    
    /**
     * Assess overall impact of blocker issues
     * 
     * @param array $blockerIssues
     * @return array
     */
    private function assessImpact($blockerIssues) {
        $totalImpactScore = 0;
        $functionalityImpact = [];
        $userImpact = 0;
        
        foreach ($blockerIssues as $issue) {
            $totalImpactScore += $issue['impact_score'];
            $userImpact += $issue['users_affected'] ?? 1;
            
            $functionality = $issue['functionality_impact'];
            if (!isset($functionalityImpact[$functionality])) {
                $functionalityImpact[$functionality] = 0;
            }
            $functionalityImpact[$functionality] += $issue['impact_score'];
        }
        
        arsort($functionalityImpact);
        
        return [
            'total_impact_score' => round($totalImpactScore, 1),
            'avg_impact_score' => count($blockerIssues) > 0 ? 
                round($totalImpactScore / count($blockerIssues), 1) : 0,
            'total_users_affected' => $userImpact,
            'most_impacted_functionality' => !empty($functionalityImpact) ? 
                array_keys($functionalityImpact)[0] : 'None',
            'functionality_impact_scores' => $functionalityImpact
        ];
    }
    
    /**
     * Count critical blocker issues
     * 
     * @param array $blockerIssues
     * @return int
     */
    private function countCriticalBlockers($blockerIssues) {
        return count(array_filter($blockerIssues, function($issue) {
            return $issue['urgency_level'] === 'Critical';
        }));
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
     * Calculate resolution distribution
     * 
     * @param array $resolutionTimes
     * @return array
     */
    private function calculateResolutionDistribution($resolutionTimes) {
        if (empty($resolutionTimes)) return [];
        
        $distribution = [
            '0-1 days' => 0,
            '1-3 days' => 0,
            '3-7 days' => 0,
            '7-14 days' => 0,
            '14+ days' => 0
        ];
        
        foreach ($resolutionTimes as $time) {
            if ($time <= 1) {
                $distribution['0-1 days']++;
            } elseif ($time <= 3) {
                $distribution['1-3 days']++;
            } elseif ($time <= 7) {
                $distribution['3-7 days']++;
            } elseif ($time <= 14) {
                $distribution['7-14 days']++;
            } else {
                $distribution['14+ days']++;
            }
        }
        
        return $distribution;
    }
    
    /**
     * Calculate trend direction
     * 
     * @param array $monthlyData
     * @return string
     */
    private function calculateTrendDirection($monthlyData) {
        if (count($monthlyData) < 2) return 'insufficient_data';
        
        $months = array_keys($monthlyData);
        $latest = end($months);
        $previous = prev($months);
        
        $latestCount = $monthlyData[$latest]['total'];
        $previousCount = $monthlyData[$previous]['total'];
        
        if ($latestCount > $previousCount * 1.1) {
            return 'increasing';
        } elseif ($latestCount < $previousCount * 0.9) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }
    
    /**
     * Generate trend insights
     * 
     * @param array $monthlyData
     * @return array
     */
    private function generateTrendInsights($monthlyData) {
        $insights = [];
        
        if (count($monthlyData) < 2) {
            return ['Insufficient data for trend analysis'];
        }
        
        $months = array_keys($monthlyData);
        $latest = end($months);
        $previous = prev($months);
        
        $latestData = $monthlyData[$latest];
        $previousData = $monthlyData[$previous];
        
        // Total blockers trend
        $totalChange = $latestData['total'] - $previousData['total'];
        if ($totalChange > 0) {
            $insights[] = "Blocker issues increased by {$totalChange} from last month";
        } elseif ($totalChange < 0) {
            $insights[] = "Blocker issues decreased by " . abs($totalChange) . " from last month";
        }
        
        // Critical blockers trend
        $criticalChange = $latestData['by_urgency']['Critical'] - $previousData['by_urgency']['Critical'];
        if ($criticalChange > 0) {
            $insights[] = "Critical blockers increased by {$criticalChange}";
        } elseif ($criticalChange < 0) {
            $insights[] = "Critical blockers decreased by " . abs($criticalChange);
        }
        
        return $insights;
    }
    
    /**
     * Calculate age in days
     * 
     * @param string $createdAt
     * @return int
     */
    private function calculateAgeDays($createdAt) {
        return floor((time() - strtotime($createdAt)) / (24 * 3600));
    }
    
    /**
     * Generate blocker-specific recommendations
     * 
     * @param array $blockerIssues
     * @param array $resolutionMetrics
     * @param array $urgencyAnalysis
     * @return array
     */
    private function generateBlockerRecommendations($blockerIssues, $resolutionMetrics, $urgencyAnalysis) {
        $recommendations = [];
        
        // Critical blockers recommendation
        $criticalCount = $urgencyAnalysis['breakdown']['Critical'];
        if ($criticalCount > 0) {
            $recommendations[] = [
                'priority' => 'Critical',
                'category' => 'Critical Blockers',
                'recommendation' => "Address {$criticalCount} critical blocker issues immediately to prevent user access barriers.",
                'impact' => 'Immediate resolution required for accessibility compliance'
            ];
        }
        
        // Resolution time recommendation
        if ($resolutionMetrics['avg_resolution_time'] > 5) {
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'Resolution Time',
                'recommendation' => "Average blocker resolution time is {$resolutionMetrics['avg_resolution_time']} days. Consider dedicated blocker resolution process.",
                'impact' => 'Faster resolution reduces user impact duration'
            ];
        }
        
        // Active blockers recommendation
        $activeCount = count(array_filter($blockerIssues, function($issue) {
            return !in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']);
        }));
        
        if ($activeCount > 5) {
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'Active Blockers',
                'recommendation' => "There are {$activeCount} active blocker issues. Consider prioritization and resource allocation.",
                'impact' => 'Systematic approach to blocker resolution'
            ];
        }
        
        return $recommendations;
    }
}