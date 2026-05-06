<?php

require_once __DIR__ . '/AnalyticsEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';

/**
 * Commented Issues Analytics Engine
 * 
 * Identifies issues with comments, calculates activity metrics, categorizes
 * comments by type, and highlights recent activity for collaboration tracking.
 * 
 * Requirements: 10.1, 10.2, 10.4
 */
class CommentedIssuesAnalytics extends AnalyticsEngine {
    private $issueCommentColumnMap;
    
    /**
     * Generate commented issues analytics report
     * 
     * @param int|null $projectId Optional project filter
     * @param int|null $clientId Optional client filter for access control
     * @return AnalyticsReport
     */
    public function generateReport($projectId = null, $clientId = null) {
        $cacheKey = $this->generateCacheKey('commented_issues_v2', $projectId, $clientId);
        
        if ($cached = $this->getCachedReport($cacheKey)) {
            return $cached;
        }
        
        $data = $this->analyzeCommentedIssues($projectId, $clientId);
        
        $report = new AnalyticsReport([
            'type' => 'commented_issues',
            'title' => 'Commented Issues Analysis',
            'description' => 'Analysis of issues with comments, activity metrics, and collaboration tracking',
            'data' => $data,
            'metadata' => [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'total_commented_issues' => $data['summary']['total_commented_issues'],
                'total_comments' => $data['summary']['total_comments'],
                'avg_comments_per_issue' => $data['summary']['avg_comments_per_issue']
            ],
            'visualization_config' => [
                'primary_chart' => [
                    'type' => 'bar',
                    'data_key' => 'top_commented_issues',
                    'title' => 'Top 5 Most Commented Issues',
                    'x_axis' => 'Issue',
                    'y_axis' => 'Comment Count'
                ],
                'secondary_chart' => [
                    'type' => 'pie',
                    'data_key' => 'comment_type_breakdown',
                    'title' => 'Comments by Type'
                ]
            ]
        ]);
        
        $this->cacheReport($cacheKey, $report);
        return $report;
    }
    
    /**
     * Analyze commented issues and activity
     * 
     * @param int|null $projectId
     * @param int|null $clientId
     * @return array
     */
    private function analyzeCommentedIssues($projectId = null, $clientId = null) {
        $issues = $this->getFilteredIssues($projectId, $clientId);
        
        // Get issues with comments
        $commentedIssues = $this->getIssuesWithComments($issues, $projectId, $clientId !== null);
        
        // Analyze comment activity
        $activityMetrics = $this->analyzeCommentActivity($commentedIssues);
        
        // Categorize comments
        $commentCategories = $this->categorizeComments($commentedIssues);
        
        // Get top commented issues
        $topCommentedIssues = $this->getTopCommentedIssues($commentedIssues);
        
        // Analyze recent activity
        $recentActivity = $this->analyzeRecentActivity($commentedIssues);
        
        // Analyze collaboration patterns
        $collaborationMetrics = $this->analyzeCollaboration($commentedIssues);
        
        // Analyze resolution correlation
        $resolutionCorrelation = $this->analyzeResolutionCorrelation($commentedIssues);
        
        $totalCommentedIssues = count($commentedIssues);
        $totalComments = array_sum(array_column($commentedIssues, 'comment_count'));
        $avgCommentsPerIssue = $totalCommentedIssues > 0 ? round($totalComments / $totalCommentedIssues, 1) : 0;
        
        return [
            'summary' => [
                'total_commented_issues' => $totalCommentedIssues,
                'total_comments' => $totalComments,
                'avg_comments_per_issue' => $avgCommentsPerIssue,
                'issues_with_recent_activity' => $recentActivity['recent_count'],
                'most_active_issue' => !empty($topCommentedIssues) ? $topCommentedIssues[0]['comment_count'] : 0,
                'collaboration_score' => $collaborationMetrics['overall_score']
            ],
            'top_commented_issues' => $topCommentedIssues,
            'commented_issue_list' => $this->buildCommentedIssueList($commentedIssues),
            'comment_type_breakdown' => $commentCategories,
            'activity_metrics' => $activityMetrics,
            'recent_activity' => $recentActivity,
            'collaboration_metrics' => $collaborationMetrics,
            'resolution_correlation' => $resolutionCorrelation,
            'recommendations' => $this->generateCommentRecommendations($commentedIssues, $activityMetrics, $collaborationMetrics)
        ];
    }
    
    /**
     * Get issues with comments from database
     * 
     * @param array $issues
     * @param int|null $projectId
     * @return array
     */
    private function getIssuesWithComments($issues, $projectId = null, $regressionOnly = false) {
        $commentedIssues = [];
        
        foreach ($issues as $issue) {
            $comments = $this->getIssueComments($issue['id'], $regressionOnly);
            
            if (!empty($comments)) {
                $commentedIssues[] = array_merge($issue, [
                    'comments' => $comments,
                    'comment_count' => count($comments),
                    'last_comment_date' => $this->getLastCommentDate($comments),
                    'comment_authors' => $this->getCommentAuthors($comments),
                    'comment_types' => $this->analyzeCommentTypes($comments),
                    'activity_score' => $this->calculateActivityScore($comments)
                ]);
            }
        }
        
        return $commentedIssues;
    }
    
    /**
     * Get comments for a specific issue
     * 
     * @param int $issueId
     * @return array
     */
    private function getIssueComments($issueId, $regressionOnly = false) {
        try {
            if (!$this->pdo) {
                return [];
            }
            $columnMap = $this->getIssueCommentColumnMap();
            $typeSelect = $columnMap['has_comment_type'] ? ', comment_type' : ", 'normal' AS comment_type";
            $typeFilter = $regressionOnly && $columnMap['has_comment_type'] ? " AND comment_type = 'regression'" : '';
            $stmt = $this->pdo->prepare("
                SELECT 
                    id,
                    issue_id,
                    user_id,
                    {$columnMap['comment']},
                    created_at,
                    {$columnMap['updated_at']}
                    {$typeSelect}
                FROM issue_comments 
                WHERE issue_id = ? 
                    {$typeFilter}
                ORDER BY created_at ASC
            ");
            $stmt->execute([$issueId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching issue comments: " . $e->getMessage());
            return [];
        }
    }

    private function getIssueCommentColumnMap() {
        if (is_array($this->issueCommentColumnMap)) {
            return $this->issueCommentColumnMap;
        }

        $this->issueCommentColumnMap = [
            'comment' => 'comment_html AS comment',
            'updated_at' => 'NULL AS updated_at',
            'has_comment_type' => false,
        ];

        if (!$this->pdo) {
            return $this->issueCommentColumnMap;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'issue_comments'
                     AND COLUMN_NAME IN ('comment', 'comment_html', 'updated_at', 'comment_type')"
            );
            $stmt->execute();
            $columns = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

            if (isset($columns['comment'])) {
                $this->issueCommentColumnMap['comment'] = 'comment';
            }

            if (isset($columns['updated_at'])) {
                $this->issueCommentColumnMap['updated_at'] = 'updated_at';
            }

            if (isset($columns['comment_type'])) {
                $this->issueCommentColumnMap['has_comment_type'] = true;
            }
        } catch (Exception $e) {
            error_log('CommentedIssuesAnalytics schema detection failed: ' . $e->getMessage());
        }

        return $this->issueCommentColumnMap;
    }
    
    /**
     * Get last comment date
     * 
     * @param array $comments
     * @return string
     */
    private function getLastCommentDate($comments) {
        if (empty($comments)) return '';
        
        $lastComment = end($comments);
        return $lastComment['created_at'] ?? '';
    }
    
    /**
     * Get unique comment authors
     * 
     * @param array $comments
     * @return array
     */
    private function getCommentAuthors($comments) {
        $authors = [];
        foreach ($comments as $comment) {
            $userId = $comment['user_id'] ?? 0;
            if (!in_array($userId, $authors)) {
                $authors[] = $userId;
            }
        }
        return $authors;
    }
    
    /**
     * Analyze comment types
     * 
     * @param array $comments
     * @return array
     */
    private function analyzeCommentTypes($comments) {
        $types = [];
        
        foreach ($comments as $comment) {
            $type = $this->classifyCommentType($comment['comment'] ?? '');
            if (!isset($types[$type])) {
                $types[$type] = 0;
            }
            $types[$type]++;
        }
        
        return $types;
    }
    
    /**
     * Classify comment type based on content
     * 
     * @param string $commentText
     * @return string
     */
    private function classifyCommentType($commentText) {
        $text = strtolower($commentText);
        
        // Question indicators
        $questionIndicators = ['?', 'how', 'what', 'why', 'when', 'where', 'which', 'can you', 'could you', 'clarify', 'explain'];
        foreach ($questionIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                return 'Question';
            }
        }
        
        // Clarification indicators
        $clarificationIndicators = ['clarification', 'unclear', 'confusing', 'not sure', 'understand', 'mean', 'specify'];
        foreach ($clarificationIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                return 'Clarification Request';
            }
        }
        
        // Solution indicators
        $solutionIndicators = ['solution', 'fix', 'resolve', 'try', 'suggest', 'recommend', 'should', 'could', 'might'];
        foreach ($solutionIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                return 'Solution/Suggestion';
            }
        }
        
        // Status update indicators
        $statusIndicators = ['completed', 'done', 'finished', 'working on', 'in progress', 'started', 'testing'];
        foreach ($statusIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                return 'Status Update';
            }
        }
        
        // Feedback indicators
        $feedbackIndicators = ['good', 'great', 'excellent', 'thanks', 'appreciate', 'helpful', 'works', 'confirmed'];
        foreach ($feedbackIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                return 'Feedback';
            }
        }
        
        // Issue report indicators
        $issueIndicators = ['problem', 'issue', 'bug', 'error', 'broken', 'not working', 'fails', 'incorrect'];
        foreach ($issueIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                return 'Issue Report';
            }
        }
        
        return 'General Discussion';
    }
    
    /**
     * Calculate activity score for issue
     * 
     * @param array $comments
     * @return float
     */
    private function calculateActivityScore($comments) {
        if (empty($comments)) return 0;
        
        $commentCount = count($comments);
        $authorCount = count($this->getCommentAuthors($comments));
        
        // Recent activity bonus
        $recentBonus = 0;
        $lastComment = end($comments);
        if (!empty($lastComment['created_at'])) {
            $daysSinceLastComment = (time() - strtotime($lastComment['created_at'])) / (24 * 3600);
            $recentBonus = max(0, 10 - $daysSinceLastComment); // Bonus decreases over 10 days
        }
        
        // Activity score = comments + author diversity + recency
        $score = ($commentCount * 2) + ($authorCount * 3) + $recentBonus;
        
        return round($score, 1);
    }
    
    /**
     * Analyze comment activity patterns
     * 
     * @param array $commentedIssues
     * @return array
     */
    private function analyzeCommentActivity($commentedIssues) {
        $activityData = [
            'by_day' => [],
            'by_hour' => array_fill(0, 24, 0),
            'by_author' => [],
            'response_times' => []
        ];
        
        foreach ($commentedIssues as $issue) {
            $comments = $issue['comments'];
            $previousComment = null;
            
            foreach ($comments as $comment) {
                $createdAt = $comment['created_at'] ?? '';
                if (empty($createdAt)) continue;
                
                // Activity by day
                $day = date('Y-m-d', strtotime($createdAt));
                if (!isset($activityData['by_day'][$day])) {
                    $activityData['by_day'][$day] = 0;
                }
                $activityData['by_day'][$day]++;
                
                // Activity by hour
                $hour = (int)date('H', strtotime($createdAt));
                $activityData['by_hour'][$hour]++;
                
                // Activity by author
                $author = $comment['user_id'] ?? 0;
                if (!isset($activityData['by_author'][$author])) {
                    $activityData['by_author'][$author] = 0;
                }
                $activityData['by_author'][$author]++;
                
                // Response times
                if ($previousComment && $previousComment['user_id'] !== $comment['user_id']) {
                    $responseTime = strtotime($createdAt) - strtotime($previousComment['created_at']);
                    $activityData['response_times'][] = $responseTime / 3600; // Convert to hours
                }
                
                $previousComment = $comment;
            }
        }
        
        // Sort daily activity
        ksort($activityData['by_day']);
        
        // Calculate response time metrics
        $responseTimes = $activityData['response_times'];
        $responseMetrics = [
            'avg_hours' => !empty($responseTimes) ? round(array_sum($responseTimes) / count($responseTimes), 1) : 0,
            'median_hours' => $this->calculateMedian($responseTimes),
            'count' => count($responseTimes)
        ];
        
        return [
            'daily_activity' => array_slice($activityData['by_day'], -30, null, true), // Last 30 days
            'hourly_distribution' => $activityData['by_hour'],
            'author_activity' => $activityData['by_author'],
            'response_metrics' => $responseMetrics,
            'peak_activity_hour' => array_search(max($activityData['by_hour']), $activityData['by_hour']),
            'most_active_author' => !empty($activityData['by_author']) ? array_search(max($activityData['by_author']), $activityData['by_author']) : null
        ];
    }
    
    /**
     * Categorize all comments
     * 
     * @param array $commentedIssues
     * @return array
     */
    private function categorizeComments($commentedIssues) {
        $categoryTotals = [];
        
        foreach ($commentedIssues as $issue) {
            foreach ($issue['comment_types'] as $type => $count) {
                if (!isset($categoryTotals[$type])) {
                    $categoryTotals[$type] = 0;
                }
                $categoryTotals[$type] += $count;
            }
        }
        
        arsort($categoryTotals);
        
        $total = array_sum($categoryTotals);
        $breakdown = [];
        
        foreach ($categoryTotals as $type => $count) {
            $breakdown[] = [
                'type' => $type,
                'count' => $count,
                'percentage' => $this->calculatePercentage($count, $total)
            ];
        }
        
        return $breakdown;
    }
    
    /**
     * Get top commented issues
     * 
     * @param array $commentedIssues
     * @return array
     */
    private function getTopCommentedIssues($commentedIssues) {
        // Sort by comment count
        usort($commentedIssues, function($a, $b) {
            return $b['comment_count'] - $a['comment_count'];
        });
        
        $topIssues = [];
        foreach (array_slice($commentedIssues, 0, 5) as $index => $issue) {
            $topIssues[] = [
                'rank' => $index + 1,
                'title' => $issue['title'] ?? 'Untitled Issue',
                'comment_count' => $issue['comment_count'],
                'author_count' => count($issue['comment_authors']),
                'activity_score' => $issue['activity_score'],
                'last_comment_date' => $issue['last_comment_date'],
                'status' => $issue['status'] ?? 'Open',
                'severity' => $issue['severity'] ?? 'Medium',
                'top_comment_types' => $this->getTopCommentTypes($issue['comment_types'], 2)
            ];
        }
        
        return $topIssues;
    }

    /**
     * Build a raw commented issue list for dashboard rendering.
     *
     * @param array $commentedIssues
     * @return array
     */
    private function buildCommentedIssueList($commentedIssues) {
        usort($commentedIssues, function($a, $b) {
            return ($b['comment_count'] ?? 0) <=> ($a['comment_count'] ?? 0);
        });

        return array_map(function($issue) {
            return [
                'id' => (int) ($issue['id'] ?? 0),
                'project_id' => (int) ($issue['project_id'] ?? 0),
                'page_id' => (int) ($issue['page_id'] ?? 0),
                'issue_key' => (string) ($issue['issue_key'] ?? ''),
                'title' => (string) ($issue['title'] ?? 'Untitled Issue'),
                'status' => (string) ($issue['status'] ?? 'Open'),
                'severity' => (string) ($issue['severity'] ?? 'Medium'),
                'page_url' => (string) ($issue['page_url'] ?? ''),
                'comment_count' => (int) ($issue['comment_count'] ?? 0),
                'author_count' => count($issue['comment_authors'] ?? []),
                'last_comment_date' => (string) ($issue['last_comment_date'] ?? ''),
            ];
        }, array_slice($commentedIssues, 0, 8));
    }
    
    /**
     * Analyze recent activity
     * 
     * @param array $commentedIssues
     * @return array
     */
    private function analyzeRecentActivity($commentedIssues) {
        $recentThreshold = date('Y-m-d H:i:s', strtotime('-7 days'));
        $recentIssues = [];
        $recentComments = [];
        
        foreach ($commentedIssues as $issue) {
            $hasRecentActivity = false;
            
            foreach ($issue['comments'] as $comment) {
                if ($comment['created_at'] >= $recentThreshold) {
                    $recentComments[] = $comment;
                    $hasRecentActivity = true;
                }
            }
            
            if ($hasRecentActivity) {
                $recentIssues[] = [
                    'title' => $issue['title'] ?? 'Untitled Issue',
                    'last_activity' => $issue['last_comment_date'],
                    'recent_comment_count' => count(array_filter($issue['comments'], function($c) use ($recentThreshold) {
                        return $c['created_at'] >= $recentThreshold;
                    }))
                ];
            }
        }
        
        // Sort by last activity
        usort($recentIssues, function($a, $b) {
            return strtotime($b['last_activity']) - strtotime($a['last_activity']);
        });
        
        return [
            'recent_count' => count($recentIssues),
            'recent_issues' => array_slice($recentIssues, 0, 10),
            'recent_comment_count' => count($recentComments),
            'activity_trend' => $this->calculateActivityTrend($commentedIssues)
        ];
    }
    
    /**
     * Analyze collaboration patterns
     * 
     * @param array $commentedIssues
     * @return array
     */
    private function analyzeCollaboration($commentedIssues) {
        $collaborationData = [
            'multi_author_issues' => 0,
            'single_author_issues' => 0,
            'avg_authors_per_issue' => 0,
            'author_interactions' => []
        ];
        
        $totalAuthors = 0;
        
        foreach ($commentedIssues as $issue) {
            $authorCount = count($issue['comment_authors']);
            $totalAuthors += $authorCount;
            
            if ($authorCount > 1) {
                $collaborationData['multi_author_issues']++;
                
                // Track author interactions
                $authors = $issue['comment_authors'];
                for ($i = 0; $i < count($authors); $i++) {
                    for ($j = $i + 1; $j < count($authors); $j++) {
                        $pair = $authors[$i] . '-' . $authors[$j];
                        if (!isset($collaborationData['author_interactions'][$pair])) {
                            $collaborationData['author_interactions'][$pair] = 0;
                        }
                        $collaborationData['author_interactions'][$pair]++;
                    }
                }
            } else {
                $collaborationData['single_author_issues']++;
            }
        }
        
        $totalIssues = count($commentedIssues);
        $collaborationData['avg_authors_per_issue'] = $totalIssues > 0 ? round($totalAuthors / $totalIssues, 1) : 0;
        
        // Calculate collaboration score
        $collaborationScore = 0;
        if ($totalIssues > 0) {
            $multiAuthorPercentage = ($collaborationData['multi_author_issues'] / $totalIssues) * 100;
            $avgAuthorsScore = min(100, $collaborationData['avg_authors_per_issue'] * 25);
            $collaborationScore = ($multiAuthorPercentage * 0.6) + ($avgAuthorsScore * 0.4);
        }
        
        return array_merge($collaborationData, [
            'collaboration_percentage' => $this->calculatePercentage($collaborationData['multi_author_issues'], $totalIssues),
            'overall_score' => round($collaborationScore, 1),
            'top_collaborations' => $this->getTopCollaborations($collaborationData['author_interactions'])
        ]);
    }
    
    /**
     * Analyze correlation between comments and resolution
     * 
     * @param array $commentedIssues
     * @return array
     */
    private function analyzeResolutionCorrelation($commentedIssues) {
        $resolvedIssues = array_filter($commentedIssues, function($issue) {
            return in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']);
        });
        
        $openIssues = array_filter($commentedIssues, function($issue) {
            return !in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']);
        });
        
        $resolvedComments = array_sum(array_column($resolvedIssues, 'comment_count'));
        $openComments = array_sum(array_column($openIssues, 'comment_count'));
        
        $avgCommentsResolved = count($resolvedIssues) > 0 ? round($resolvedComments / count($resolvedIssues), 1) : 0;
        $avgCommentsOpen = count($openIssues) > 0 ? round($openComments / count($openIssues), 1) : 0;
        
        return [
            'resolved_issues' => count($resolvedIssues),
            'open_issues' => count($openIssues),
            'avg_comments_resolved' => $avgCommentsResolved,
            'avg_comments_open' => $avgCommentsOpen,
            'resolution_rate' => $this->calculatePercentage(count($resolvedIssues), count($commentedIssues)),
            'comment_resolution_correlation' => $this->calculateCommentResolutionCorrelation($commentedIssues)
        ];
    }
    
    /**
     * Calculate activity trend
     * 
     * @param array $commentedIssues
     * @return string
     */
    private function calculateActivityTrend($commentedIssues) {
        $recentWeek = 0;
        $previousWeek = 0;
        $recentThreshold = strtotime('-7 days');
        $previousThreshold = strtotime('-14 days');
        
        foreach ($commentedIssues as $issue) {
            foreach ($issue['comments'] as $comment) {
                $commentTime = strtotime($comment['created_at']);
                
                if ($commentTime >= $recentThreshold) {
                    $recentWeek++;
                } elseif ($commentTime >= $previousThreshold) {
                    $previousWeek++;
                }
            }
        }
        
        if ($previousWeek === 0) {
            return $recentWeek > 0 ? 'increasing' : 'stable';
        }
        
        $change = (($recentWeek - $previousWeek) / $previousWeek) * 100;
        
        if ($change > 10) {
            return 'increasing';
        } elseif ($change < -10) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }
    
    /**
     * Get top comment types for an issue
     * 
     * @param array $commentTypes
     * @param int $limit
     * @return array
     */
    private function getTopCommentTypes($commentTypes, $limit = 2) {
        arsort($commentTypes);
        return array_slice(array_keys($commentTypes), 0, $limit);
    }
    
    /**
     * Get top collaborations
     * 
     * @param array $interactions
     * @return array
     */
    private function getTopCollaborations($interactions) {
        arsort($interactions);
        return array_slice($interactions, 0, 5, true);
    }
    
    /**
     * Calculate comment-resolution correlation
     * 
     * @param array $commentedIssues
     * @return float
     */
    private function calculateCommentResolutionCorrelation($commentedIssues) {
        if (count($commentedIssues) < 2) return 0;
        
        $commentCounts = [];
        $resolutionStatus = [];
        
        foreach ($commentedIssues as $issue) {
            $commentCounts[] = $issue['comment_count'];
            $resolutionStatus[] = in_array($issue['status'] ?? 'Open', ['Resolved', 'Closed']) ? 1 : 0;
        }
        
        return $this->calculateCorrelation($commentCounts, $resolutionStatus);
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
     * Generate comment-specific recommendations
     * 
     * @param array $commentedIssues
     * @param array $activityMetrics
     * @param array $collaborationMetrics
     * @return array
     */
    private function generateCommentRecommendations($commentedIssues, $activityMetrics, $collaborationMetrics) {
        $recommendations = [];
        
        // High activity issues recommendation
        if (!empty($commentedIssues)) {
            $highActivityIssues = array_filter($commentedIssues, function($issue) {
                return $issue['comment_count'] > 5;
            });
            
            if (!empty($highActivityIssues)) {
                $count = count($highActivityIssues);
                $recommendations[] = [
                    'priority' => 'High',
                    'category' => 'High Activity Issues',
                    'recommendation' => "Review {$count} issues with high comment activity for potential resolution blockers or clarification needs.",
                    'impact' => 'Resolve communication bottlenecks and accelerate issue resolution'
                ];
            }
        }
        
        // Collaboration recommendation
        if ($collaborationMetrics['collaboration_percentage'] < 30) {
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'Collaboration',
                'recommendation' => "Only {$collaborationMetrics['collaboration_percentage']}% of commented issues have multiple contributors. Encourage cross-team collaboration.",
                'impact' => 'Improve knowledge sharing and issue resolution quality'
            ];
        }
        
        // Response time recommendation
        if (isset($activityMetrics['response_metrics']['avg_hours']) && 
            $activityMetrics['response_metrics']['avg_hours'] > 24) {
            $avgHours = $activityMetrics['response_metrics']['avg_hours'];
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'Response Time',
                'recommendation' => "Average response time is {$avgHours} hours. Consider establishing response time targets for commented issues.",
                'impact' => 'Faster communication and issue resolution'
            ];
        }
        
        return $recommendations;
    }
}