<?php

require_once __DIR__ . '/AnalyticsEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';

/**
 * Page Issues Analytics Engine
 * 
 * Groups issues by page URL, calculates issue density, and identifies
 * pages with highest issue concentration for targeted remediation.
 * 
 * Requirements: 9.1, 9.2, 9.4
 */
class PageIssuesAnalytics extends AnalyticsEngine {
    
    /**
     * Generate page issues analytics report
     * 
     * @param int|null $projectId Optional project filter
     * @param int|null $clientId Optional client filter for access control
     * @return AnalyticsReport
     */
    public function generateReport($projectId = null, $clientId = null) {
        $cacheKey = $this->generateCacheKey('page_issues_v3', $projectId, $clientId);
        
        if ($cached = $this->getCachedReport($cacheKey)) {
            return $cached;
        }
        
        $data = $this->analyzePageIssues($projectId, $clientId);
        
        $report = new AnalyticsReport([
            'type' => 'page_issues',
            'title' => 'Page Issues Analysis',
            'description' => 'Analysis of issues grouped by page with density metrics and concentration identification',
            'data' => $data,
            'metadata' => [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'total_pages' => $data['summary']['total_pages'],
                'pages_with_issues' => $data['summary']['pages_with_issues'],
                'avg_issues_per_page' => $data['summary']['avg_issues_per_page']
            ],
            'visualization_config' => [
                'primary_chart' => [
                    'type' => 'bar',
                    'data_key' => 'top_pages',
                    'title' => 'Pages by Issue Count',
                    'x_axis' => 'Page',
                    'y_axis' => 'Issue Count'
                ],
                'secondary_chart' => [
                    'type' => 'scatter',
                    'data_key' => 'density_analysis',
                    'title' => 'Issue Density vs Page Complexity',
                    'x_axis' => 'Page Complexity Score',
                    'y_axis' => 'Issue Density'
                ]
            ]
        ]);
        
        $this->cacheReport($cacheKey, $report);
        return $report;
    }
    
    /**
     * Analyze page issues and density
     * 
     * @param int|null $projectId
     * @param int|null $clientId
     * @return array
     */
    private function analyzePageIssues($projectId = null, $clientId = null) {
        $issues = $this->getFilteredIssues($projectId, $clientId);
        
        // Include every project page so zero-issue pages are visible in the client tab.
        $pageGroups = $this->initializeProjectPageGroups($projectId, $clientId);
        $pageGroups = $this->groupIssuesByPage($issues, $pageGroups);
        
        // Calculate page metrics
        $pageMetrics = $this->calculatePageMetrics($pageGroups);
        
        // Analyze issue density
        $densityAnalysis = $this->analyzeDensity($pageMetrics);
        
        // Identify page patterns
        $pagePatterns = $this->identifyPagePatterns($pageMetrics);
        
        // Get top problematic pages
        $topPages = $this->getTopProblematicPages($pageMetrics);
        
        // Analyze page types
        $pageTypeAnalysis = $this->analyzePageTypes($pageMetrics);
        
        $totalPages = count($pageGroups);
        $pagesWithIssues = count(array_filter($pageGroups, function($group) {
            return count($group['issues']) > 0;
        }));
        
        $totalIssues = count($issues);
        $avgIssuesPerPage = $totalPages > 0 ? round($totalIssues / $totalPages, 1) : 0;
        
        return [
            'summary' => [
                'total_pages' => $totalPages,
                'pages_with_issues' => $pagesWithIssues,
                'pages_without_issues' => $totalPages - $pagesWithIssues,
                'total_issues' => $totalIssues,
                'avg_issues_per_page' => $avgIssuesPerPage,
                'max_issues_per_page' => !empty($pageMetrics) ? max(array_column($pageMetrics, 'issue_count')) : 0,
                'pages_above_threshold' => $this->countPagesAboveThreshold($pageMetrics, 5) // 5+ issues
            ],
            'top_pages' => $topPages,
            'density_analysis' => $densityAnalysis,
            'page_patterns' => $pagePatterns,
            'page_type_analysis' => $pageTypeAnalysis,
            'severity_distribution' => $this->analyzePageSeverityDistribution($pageMetrics),
            'recommendations' => $this->generatePageRecommendations($pageMetrics, $densityAnalysis, $pagePatterns)
        ];
    }
    
    /**
     * Group issues by page URL
     * 
     * @param array $issues
     * @return array
     */
    private function groupIssuesByPage($issues, array $pageGroups = []) {
        
        foreach ($issues as $issue) {
            $pageId = (int) ($issue['page_id'] ?? 0);
            $pageLabel = $this->buildPageLabel(
                $issue['page_name'] ?? '',
                $issue['page_number'] ?? '',
                $issue['page_url'] ?? ''
            );
            $groupKey = $this->buildPageGroupKey($pageId, $pageLabel, $issue['page_url'] ?? '');
            
            if (!isset($pageGroups[$groupKey])) {
                $projectId = (int) ($issue['project_id'] ?? 0);
                $issueId = (int) ($issue['id'] ?? 0);
                $pageGroups[$groupKey] = [
                    'url' => (string) ($issue['page_url'] ?? ''),
                    'display_label' => $pageLabel,
                    'issues' => [],
                    'severities' => [],
                    'categories' => [],
                    'statuses' => [],
                    'page_id' => $pageId,
                    'project_id' => $projectId,
                    'sample_issue_id' => $issueId,
                    'page_name' => trim((string) ($issue['page_name'] ?? '')),
                    'page_number' => trim((string) ($issue['page_number'] ?? '')),
                ];
            }
            
            if (($pageGroups[$groupKey]['project_id'] ?? 0) <= 0) {
                $pageGroups[$groupKey]['project_id'] = (int) ($issue['project_id'] ?? 0);
            }
            if (($pageGroups[$groupKey]['sample_issue_id'] ?? 0) <= 0) {
                $pageGroups[$groupKey]['sample_issue_id'] = (int) ($issue['id'] ?? 0);
            }

            $pageGroups[$groupKey]['issues'][] = $issue;
            $pageGroups[$groupKey]['severities'][] = $issue['severity'] ?? 'Medium';
            $pageGroups[$groupKey]['categories'][] = $this->categorizeIssue($issue);
            $pageGroups[$groupKey]['statuses'][] = $issue['status'] ?? 'Open';
        }
        
        return $pageGroups;
    }
    
    /**
     * Preload all project pages so the dashboard shows the complete page inventory.
     */
    private function initializeProjectPageGroups($projectId = null, $clientId = null) {
        $pageGroups = [];

        foreach ($this->getProjectPages($projectId, $clientId) as $page) {
            $pageId = (int) ($page['id'] ?? 0);
            $pageLabel = $this->buildPageLabel(
                $page['page_name'] ?? '',
                $page['page_number'] ?? '',
                $page['url'] ?? ''
            );
            $groupKey = $this->buildPageGroupKey($pageId, $pageLabel, $page['url'] ?? '');

            $pageGroups[$groupKey] = [
                'url' => trim((string) ($page['url'] ?? '')),
                'display_label' => $pageLabel,
                'issues' => [],
                'severities' => [],
                'categories' => [],
                'statuses' => [],
                'page_id' => $pageId,
                'project_id' => (int) ($page['project_id'] ?? 0),
                'sample_issue_id' => 0,
                'page_name' => trim((string) ($page['page_name'] ?? '')),
                'page_number' => trim((string) ($page['page_number'] ?? '')),
            ];
        }

        return $pageGroups;
    }

    /**
     * Load project pages for the selected projects only.
     */
    private function getProjectPages($projectId = null, $clientId = null) {
        if (!$this->pdo) {
            return [];
        }

        try {
            $projectIds = [];

            if (is_array($projectId)) {
                $projectIds = array_values(array_filter(array_map('intval', $projectId)));
            } elseif ($projectId !== null) {
                $projectIds = [(int) $projectId];
            } elseif ($clientId !== null) {
                $projectIds = array_values(array_filter(array_map('intval', $this->getAssignedProjects($clientId))));
            }

            if (empty($projectIds)) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $sql = "SELECT id, project_id, page_name, page_number, url
                    FROM project_pages
                    WHERE project_id IN ($placeholders)
                    ORDER BY project_id ASC,
                             CASE WHEN page_number LIKE 'Global%' THEN 0 WHEN page_number LIKE 'Page%' THEN 1 ELSE 2 END,
                             CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(page_number, ' ', -1), ' ', 1) AS UNSIGNED),
                             page_number,
                             page_name,
                             id ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($projectIds);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('Error fetching project pages for page analytics: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Build a stable display label that prefers the saved page name over URLs.
     */
    private function buildPageLabel($pageName = '', $pageNumber = '', $url = '') {
        $pageName = trim((string) $pageName);
        $pageNumber = trim((string) $pageNumber);
        $url = trim((string) $url);

        if ($pageName !== '') {
            return $pageName;
        }

        if ($pageNumber !== '') {
            return $pageNumber;
        }

        if ($url !== '' && strcasecmp($url, 'Unknown') !== 0) {
            $url = preg_replace('/^https?:\/\/(www\.)?/', '', $url);
            $url = preg_replace('/[?#].*$/', '', $url);
            $url = rtrim($url, '/');
            return $url !== '' ? $url : 'Home Page';
        }

        return 'Unknown Page';
    }

    /**
     * Build a unique page group key.
     */
    private function buildPageGroupKey($pageId, $pageLabel, $url = '') {
        $pageId = (int) $pageId;
        if ($pageId > 0) {
            return 'page_' . $pageId;
        }

        $fallback = trim((string) $pageLabel);
        if ($fallback === '') {
            $fallback = trim((string) $url);
        }

        return 'label_' . md5($fallback);
    }
    
    /**
     * Calculate metrics for each page
     * 
     * @param array $pageGroups
     * @return array
     */
    private function calculatePageMetrics($pageGroups) {
        $pageMetrics = [];
        
        foreach ($pageGroups as $pageUrl => $group) {
            $issues = $group['issues'];
            $issueCount = count($issues);
            $pageLabel = (string) ($group['display_label'] ?? $pageUrl);
            $rawUrl = trim((string) ($group['url'] ?? ''));
            
            $severityCounts = array_count_values($group['severities']);
            $categoryCounts = array_count_values($group['categories']);
            $statusCounts = array_count_values($group['statuses']);
            
            // Calculate severity score
            $severityScore = $this->calculatePageSeverityScore($severityCounts, $issueCount);
            
            // Calculate complexity score
            $complexityScore = $this->calculatePageComplexityScore($pageLabel, $categoryCounts);
            
            // Calculate issue density
            $issueDensity = $this->calculateIssueDensity($issueCount, $complexityScore);
            
            // Calculate resolution rate
            $resolvedCount = ($statusCounts['Resolved'] ?? 0) + ($statusCounts['Closed'] ?? 0);
            $resolutionRate = $this->calculatePercentage($resolvedCount, $issueCount);
            
            $pageMetrics[] = [
                'url' => $pageUrl,
                'raw_url' => $rawUrl,
                'display_url' => $this->getDisplayUrl($pageLabel),
                'project_id' => (int) ($group['project_id'] ?? 0),
                'page_id' => (int) ($group['page_id'] ?? 0),
                'sample_issue_id' => (int) ($group['sample_issue_id'] ?? 0),
                'page_name' => (string) ($group['page_name'] ?? ''),
                'page_number' => (string) ($group['page_number'] ?? ''),
                'issue_count' => $issueCount,
                'severity_score' => $severityScore,
                'complexity_score' => $complexityScore,
                'issue_density' => $issueDensity,
                'resolution_rate' => $resolutionRate,
                'severity_breakdown' => $severityCounts,
                'category_breakdown' => $categoryCounts,
                'status_breakdown' => $statusCounts,
                'priority_score' => $this->calculatePagePriorityScore($issueCount, $severityScore, $resolutionRate),
                'page_type' => $this->identifyPageType($rawUrl !== '' ? $rawUrl : $pageLabel),
                'unique_categories' => count($categoryCounts)
            ];
        }
        
        return $pageMetrics;
    }
    
    /**
     * Calculate page severity score
     * 
     * @param array $severityCounts
     * @param int $totalIssues
     * @return float
     */
    private function calculatePageSeverityScore($severityCounts, $totalIssues) {
        if ($totalIssues === 0) return 0;
        
        // Weights based on actual DB severity enum values
        $weights = [
            'blocker'  => 5,
            'critical' => 4,
            'major'    => 3,
            'minor'    => 2,
            'low'      => 1,
        ];
        $weightedScore = 0;
        
        foreach ($severityCounts as $severity => $count) {
            $weight = $weights[$this->normalizeLowerText($severity)] ?? 2;
            $weightedScore += $weight * $count;
        }
        
        $maxPossibleScore = $totalIssues * 5; // max weight is 5 (blocker)
        return round(($weightedScore / $maxPossibleScore) * 100, 1);
    }
    
    /**
     * Calculate page complexity score
     * 
     * @param string $pageUrl
     * @param array $categoryCounts
     * @return float
     */
    private function calculatePageComplexityScore($pageUrl, $categoryCounts) {
        $baseScore = 10; // Base complexity
        
        // URL complexity indicators
        $urlComplexity = 0;
        $urlComplexity += substr_count($pageUrl, '/') * 2; // Path depth
        $urlComplexity += substr_count($pageUrl, '?') * 3; // Query parameters
        $urlComplexity += substr_count($pageUrl, '&') * 1; // Multiple parameters
        
        // Category diversity indicates complexity
        $categoryComplexity = count($categoryCounts) * 3;
        
        // Specific page type complexity
        $pageTypeComplexity = 0;
        $complexPageTypes = ['form', 'checkout', 'dashboard', 'admin', 'search', 'cart'];
        foreach ($complexPageTypes as $type) {
            if (strpos($this->normalizeLowerText($pageUrl), $type) !== false) {
                $pageTypeComplexity += 5;
                break;
            }
        }
        
        $totalScore = $baseScore + $urlComplexity + $categoryComplexity + $pageTypeComplexity;
        
        return round(min(100, $totalScore), 1);
    }
    
    /**
     * Calculate issue density
     * 
     * @param int $issueCount
     * @param float $complexityScore
     * @return float
     */
    private function calculateIssueDensity($issueCount, $complexityScore) {
        if ($complexityScore === 0) return 0;
        
        // Density = issues per unit of complexity
        $density = ($issueCount / $complexityScore) * 100;
        
        return round($density, 2);
    }
    
    /**
     * Calculate page priority score
     * 
     * @param int $issueCount
     * @param float $severityScore
     * @param float $resolutionRate
     * @return float
     */
    private function calculatePagePriorityScore($issueCount, $severityScore, $resolutionRate) {
        if ($issueCount <= 0) {
            return 0;
        }

        // Higher issue count and severity = higher priority
        // Lower resolution rate = higher priority
        $priorityScore = ($issueCount * 0.4) + ($severityScore * 0.4) + ((100 - $resolutionRate) * 0.2);
        
        return round($priorityScore, 1);
    }
    
    /**
     * Identify page type from URL
     * 
     * @param string $pageUrl
     * @return string
     */
    private function identifyPageType($pageUrl) {
        $url = $this->normalizeLowerText($pageUrl);
        
        $pageTypes = [
            'Home Page' => ['', 'home', 'index', 'main'],
            'Product Page' => ['product', 'item', 'detail'],
            'Category Page' => ['category', 'catalog', 'browse'],
            'Form Page' => ['form', 'contact', 'register', 'signup'],
            'Checkout Page' => ['checkout', 'cart', 'payment', 'order'],
            'User Account' => ['account', 'profile', 'dashboard', 'user'],
            'Search Page' => ['search', 'results', 'find'],
            'Content Page' => ['about', 'help', 'faq', 'blog', 'article'],
            'Admin Page' => ['admin', 'manage', 'control'],
            'Login Page' => ['login', 'signin', 'auth']
        ];
        
        foreach ($pageTypes as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($url, $keyword) !== false) {
                    return $type;
                }
            }
        }
        
        return 'Other Page';
    }
    
    /**
     * Categorize issue type
     * 
     * @param array $issue
     * @return string
     */
    private function categorizeIssue($issue) {
        $content = $this->normalizeLowerText(($issue['title'] ?? '') . ' ' . ($issue['description'] ?? ''));
        
        $categories = [
            'Navigation' => ['navigation', 'menu', 'link', 'breadcrumb'],
            'Forms' => ['form', 'input', 'field', 'validation', 'submit'],
            'Images' => ['image', 'alt', 'graphic', 'photo', 'picture'],
            'Content' => ['content', 'text', 'heading', 'paragraph'],
            'Interactive' => ['button', 'click', 'interactive', 'control'],
            'Visual' => ['color', 'contrast', 'visual', 'design'],
            'Keyboard' => ['keyboard', 'focus', 'tab', 'shortcut'],
            'ARIA' => ['aria', 'role', 'label', 'landmark']
        ];
        
        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    return $category;
                }
            }
        }
        
        return 'Other';
    }
    
    /**
     * Analyze issue density patterns
     * 
     * @param array $pageMetrics
     * @return array
     */
    private function analyzeDensity($pageMetrics) {
        if (empty($pageMetrics)) return [];
        
        $densities = array_column($pageMetrics, 'issue_density');
        $complexities = array_column($pageMetrics, 'complexity_score');
        
        sort($densities);
        $densityStats = [
            'min' => min($densities),
            'max' => max($densities),
            'avg' => round(array_sum($densities) / count($densities), 2),
            'median' => $this->calculateMedian($densities)
        ];
        
        // Identify high-density pages
        $highDensityThreshold = $densityStats['avg'] * 1.5;
        $highDensityPages = array_filter($pageMetrics, function($page) use ($highDensityThreshold) {
            return $page['issue_density'] > $highDensityThreshold;
        });
        
        // Prepare scatter plot data
        $scatterData = [];
        foreach ($pageMetrics as $page) {
            $scatterData[] = [
                'x' => $page['complexity_score'],
                'y' => $page['issue_density'],
                'label' => $page['display_url'],
                'issue_count' => $page['issue_count']
            ];
        }
        
        return [
            'statistics' => $densityStats,
            'high_density_pages' => array_values($highDensityPages),
            'scatter_data' => $scatterData,
            'density_distribution' => $this->calculateDensityDistribution($densities),
            'correlation' => $this->calculateCorrelation($complexities, $densities)
        ];
    }
    
    /**
     * Identify page patterns
     * 
     * @param array $pageMetrics
     * @return array
     */
    private function identifyPagePatterns($pageMetrics) {
        $patterns = [
            'high_issue_pages' => [],
            'low_resolution_pages' => [],
            'complex_pages' => [],
            'category_patterns' => []
        ];
        
        foreach ($pageMetrics as $page) {
            // High issue pages (above average)
            if ($page['issue_count'] > 5) {
                $patterns['high_issue_pages'][] = $page;
            }
            
            // Low resolution pages
            if ($page['resolution_rate'] < 50) {
                $patterns['low_resolution_pages'][] = $page;
            }
            
            // Complex pages
            if ($page['complexity_score'] > 50) {
                $patterns['complex_pages'][] = $page;
            }
        }
        
        // Analyze category patterns
        $categoryIssues = [];
        foreach ($pageMetrics as $page) {
            foreach ($page['category_breakdown'] as $category => $count) {
                if (!isset($categoryIssues[$category])) {
                    $categoryIssues[$category] = ['pages' => 0, 'total_issues' => 0];
                }
                $categoryIssues[$category]['pages']++;
                $categoryIssues[$category]['total_issues'] += $count;
            }
        }
        
        arsort($categoryIssues);
        $patterns['category_patterns'] = array_slice($categoryIssues, 0, 5, true);
        
        return $patterns;
    }
    
    /**
     * Get top problematic pages
     * 
     * @param array $pageMetrics
     * @return array
     */
    private function getTopProblematicPages($pageMetrics) {
        // Sort by priority score (highest first)
        usort($pageMetrics, function($a, $b) {
            return $b['priority_score'] - $a['priority_score'];
        });
        
        $topPages = [];
        foreach ($pageMetrics as $index => $page) {
            $topPages[] = [
                'rank' => $index + 1,
                'url' => $page['display_url'],
            'raw_url' => $page['raw_url'] ?? '',
                'display_url' => $page['display_url'],
                'project_id' => (int) ($page['project_id'] ?? 0),
                'page_id' => (int) ($page['page_id'] ?? 0),
                'sample_issue_id' => (int) ($page['sample_issue_id'] ?? 0),
            'page_number' => (string) ($page['page_number'] ?? ''),
                'issue_count' => $page['issue_count'],
                'severity_score' => $page['severity_score'],
                'issue_density' => $page['issue_density'],
                'resolution_rate' => $page['resolution_rate'],
                'priority_score' => $page['priority_score'],
                'page_type' => $page['page_type'],
                'top_categories' => $this->getTopCategories($page['category_breakdown'], 3)
            ];
        }
        
        return $topPages;
    }
    
    /**
     * Analyze page types
     * 
     * @param array $pageMetrics
     * @return array
     */
    private function analyzePageTypes($pageMetrics) {
        $typeAnalysis = [];
        
        foreach ($pageMetrics as $page) {
            $type = $page['page_type'];
            
            if (!isset($typeAnalysis[$type])) {
                $typeAnalysis[$type] = [
                    'page_count' => 0,
                    'total_issues' => 0,
                    'avg_issues' => 0,
                    'avg_severity_score' => 0,
                    'avg_resolution_rate' => 0
                ];
            }
            
            $typeAnalysis[$type]['page_count']++;
            $typeAnalysis[$type]['total_issues'] += $page['issue_count'];
            $typeAnalysis[$type]['avg_severity_score'] += $page['severity_score'];
            $typeAnalysis[$type]['avg_resolution_rate'] += $page['resolution_rate'];
        }
        
        // Calculate averages
        foreach ($typeAnalysis as $type => &$data) {
            $pageCount = $data['page_count'];
            $data['avg_issues'] = round($data['total_issues'] / $pageCount, 1);
            $data['avg_severity_score'] = round($data['avg_severity_score'] / $pageCount, 1);
            $data['avg_resolution_rate'] = round($data['avg_resolution_rate'] / $pageCount, 1);
        }
        
        // Sort by total issues
        uasort($typeAnalysis, function($a, $b) {
            return $b['total_issues'] - $a['total_issues'];
        });
        
        return $typeAnalysis;
    }
    
    /**
     * Analyze severity distribution across pages
     * 
     * @param array $pageMetrics
     * @return array
     */
    private function analyzePageSeverityDistribution($pageMetrics) {
        $totalSeverities = [];
        
        foreach ($pageMetrics as $page) {
            foreach ($page['severity_breakdown'] as $severity => $count) {
                $totalSeverities[$severity] = ($totalSeverities[$severity] ?? 0) + $count;
            }
        }
        
        $total = array_sum($totalSeverities);
        $percentages = [];
        foreach ($totalSeverities as $severity => $count) {
            $percentages[$severity] = $this->calculatePercentage($count, $total);
        }
        
        return [
            'counts' => $totalSeverities,
            'percentages' => $percentages
        ];
    }
    
    /**
     * Count pages above threshold
     * 
     * @param array $pageMetrics
     * @param int $threshold
     * @return int
     */
    private function countPagesAboveThreshold($pageMetrics, $threshold) {
        return count(array_filter($pageMetrics, function($page) use ($threshold) {
            return $page['issue_count'] >= $threshold;
        }));
    }
    
    /**
     * Get display URL (shortened for UI)
     * 
     * @param string $url
     * @return string
     */
    private function getDisplayUrl($url) {
        if (strlen($url) <= 50) {
            return $url;
        }
        
        return substr($url, 0, 47) . '...';
    }
    
    /**
     * Get top categories for a page
     * 
     * @param array $categoryBreakdown
     * @param int $limit
     * @return array
     */
    private function getTopCategories($categoryBreakdown, $limit = 3) {
        arsort($categoryBreakdown);
        return array_slice(array_keys($categoryBreakdown), 0, $limit);
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
     * Calculate density distribution
     * 
     * @param array $densities
     * @return array
     */
    private function calculateDensityDistribution($densities) {
        if (empty($densities)) return [];
        
        $distribution = [
            'Very Low (0-1)' => 0,
            'Low (1-3)' => 0,
            'Medium (3-6)' => 0,
            'High (6-10)' => 0,
            'Very High (10+)' => 0
        ];
        
        foreach ($densities as $density) {
            if ($density <= 1) {
                $distribution['Very Low (0-1)']++;
            } elseif ($density <= 3) {
                $distribution['Low (1-3)']++;
            } elseif ($density <= 6) {
                $distribution['Medium (3-6)']++;
            } elseif ($density <= 10) {
                $distribution['High (6-10)']++;
            } else {
                $distribution['Very High (10+)']++;
            }
        }
        
        return $distribution;
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
     * Generate page-specific recommendations
     * 
     * @param array $pageMetrics
     * @param array $densityAnalysis
     * @param array $pagePatterns
     * @return array
     */
    private function generatePageRecommendations($pageMetrics, $densityAnalysis, $pagePatterns) {
        $recommendations = [];
        
        // Top page recommendation
        if (!empty($pageMetrics)) {
            $topPage = $pageMetrics[0];
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'Top Priority Page',
                'recommendation' => "Focus on '{$topPage['display_url']}' which has {$topPage['issue_count']} issues and highest priority score.",
                'impact' => 'Maximum impact per page remediation effort'
            ];
        }
        
        // High density pages recommendation
        if (!empty($densityAnalysis['high_density_pages'])) {
            $count = count($densityAnalysis['high_density_pages']);
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'High Density Pages',
                'recommendation' => "Address {$count} pages with high issue density for efficient remediation.",
                'impact' => 'Efficient resolution of concentrated issues'
            ];
        }
        
        // Page type recommendation
        if (!empty($pagePatterns['category_patterns'])) {
            $topCategory = array_keys($pagePatterns['category_patterns'])[0];
            $categoryData = $pagePatterns['category_patterns'][$topCategory];
            
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'Category Focus',
                'recommendation' => "Focus on '{$topCategory}' issues which appear across {$categoryData['pages']} pages.",
                'impact' => 'Systematic approach to common issue types'
            ];
        }
        
        return $recommendations;
    }
}