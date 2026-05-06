<?php

require_once __DIR__ . '/AnalyticsEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';

/**
 * Common Issues Analytics Engine
 * 
 * Identifies and ranks issues by frequency of occurrence, groups similar issues,
 * and calculates impact reduction potential for addressing common patterns.
 * 
 * Requirements: 7.1, 7.2, 7.4
 */
class CommonIssuesAnalytics extends AnalyticsEngine {
    
    /**
     * Generate common issues analytics report
     * 
     * @param int|null $projectId Optional project filter
     * @param int|null $clientId Optional client filter for access control
     * @return AnalyticsReport
     */
    public function generateReport($projectId = null, $clientId = null) {
        $cacheKey = $this->generateCacheKey('common_issues', $projectId, $clientId);
        
        if ($cached = $this->getCachedReport($cacheKey)) {
            return $cached;
        }
        
        $data = $this->analyzeCommonIssues($projectId, $clientId);
        
        $report = new AnalyticsReport([
            'type' => 'common_issues',
            'title' => 'Common Issues Analysis',
            'description' => 'Analysis of frequently occurring issues with impact reduction potential',
            'data' => $data,
            'metadata' => [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'total_issues' => $data['summary']['total_issues'],
                'unique_patterns' => $data['summary']['unique_patterns'],
                'top_pattern_impact' => $data['summary']['top_pattern_impact']
            ],
            'visualization_config' => [
                'primary_chart' => [
                    'type' => 'bar',
                    'data_key' => 'top_common_issues',
                    'title' => 'Most Common Issues',
                    'x_axis' => 'Issue Pattern',
                    'y_axis' => 'Occurrence Count'
                ],
                'secondary_chart' => [
                    'type' => 'scatter',
                    'data_key' => 'impact_analysis',
                    'title' => 'Impact vs Frequency Analysis',
                    'x_axis' => 'Frequency',
                    'y_axis' => 'Impact Score'
                ]
            ]
        ]);
        
        $this->cacheReport($cacheKey, $report);
        return $report;
    }
    
    /**
     * Analyze common issues and patterns
     * 
     * @param int|null $projectId
     * @param int|null $clientId
     * @return array
     */
    private function analyzeCommonIssues($projectId = null, $clientId = null) {
        $issueGroups = $this->loadAuthoritativeCommonIssueGroups($projectId, $clientId);
        $totalIssues = 0;

        if (!empty($issueGroups)) {
            foreach ($issueGroups as $group) {
                $totalIssues += count($group['issues'] ?? []);
            }
        } else {
            $issues = $this->getFilteredIssues($projectId, $clientId);

            // Fallback to heuristic grouping only when no saved common issues exist.
            $issueGroups = $this->groupSimilarIssues($issues);
            $totalIssues = count($issues);
        }
        
        // Calculate frequency and impact metrics
        $commonIssues = $this->calculateFrequencyMetrics($issueGroups);
        
        // Analyze patterns across pages
        $patternAnalysis = $this->analyzePatterns($issueGroups);
        
        // Calculate impact reduction potential
        $impactAnalysis = $this->calculateImpactReduction($commonIssues);
        
        $uniquePatterns = count($issueGroups);
        
        return [
            'summary' => [
                'total_issues' => $totalIssues,
                'unique_patterns' => $uniquePatterns,
                'pattern_coverage' => $this->calculatePatternCoverage($commonIssues, $totalIssues),
                'top_pattern_impact' => !empty($commonIssues) ? $commonIssues[0]['impact_score'] : 0,
                'avg_issues_per_pattern' => $uniquePatterns > 0 ? round($totalIssues / $uniquePatterns, 1) : 0
            ],
            'top_common_issues' => array_slice($commonIssues, 0, 15),
            'pattern_analysis' => $patternAnalysis,
            'impact_analysis' => $this->prepareImpactAnalysisData($commonIssues),
            'category_breakdown' => $this->analyzeCategoryBreakdown($commonIssues),
            'severity_distribution' => $this->analyzeSeverityDistribution($commonIssues),
            'recommendations' => $this->generateCommonIssuesRecommendations($commonIssues, $patternAnalysis)
        ];
    }

    private function loadAuthoritativeCommonIssueGroups($projectId = null, $clientId = null) {
        try {
            $sql = "SELECT ci.id AS common_id, ci.project_id, ci.title AS common_title, i.id AS issue_id, i.severity, i.common_issue_title
                    FROM common_issues ci
                    INNER JOIN issues i ON i.id = ci.issue_id
                    WHERE 1=1";
            $params = [];

            if ($projectId !== null) {
                if (is_array($projectId)) {
                    $projectIds = array_values(array_filter(array_map('intval', $projectId), function ($value) {
                        return $value > 0;
                    }));
                    if (empty($projectIds)) {
                        return [];
                    }
                    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
                    $sql .= " AND ci.project_id IN ($placeholders)";
                    $params = array_merge($params, $projectIds);
                } else {
                    $sql .= " AND ci.project_id = ?";
                    $params[] = (int) $projectId;
                }
            }

            if ($clientId !== null) {
                $sql .= " AND i.client_ready = 1";
            }

            $sql .= " ORDER BY ci.id ASC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return [];
            }

            $issueIds = array_values(array_unique(array_map(function ($row) {
                return (int) ($row['issue_id'] ?? 0);
            }, $rows)));

            $pageIdsByIssue = [];
            if (!empty($issueIds)) {
                $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
                $metaStmt = $this->pdo->prepare("SELECT issue_id, meta_value FROM issue_metadata WHERE meta_key = 'page_ids' AND issue_id IN ($placeholders)");
                $metaStmt->execute($issueIds);
                while ($metaRow = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
                    $issueId = (int) ($metaRow['issue_id'] ?? 0);
                    $rawValue = trim((string) ($metaRow['meta_value'] ?? ''));
                    if ($issueId <= 0 || $rawValue === '') {
                        continue;
                    }

                    $decodedValues = [];
                    if ($rawValue[0] === '[') {
                        $decoded = json_decode($rawValue, true);
                        if (is_array($decoded)) {
                            $decodedValues = $decoded;
                        }
                    }
                    if (empty($decodedValues)) {
                        $decodedValues = [$rawValue];
                    }

                    foreach ($decodedValues as $value) {
                        $pageIdValue = (int) $value;
                        if ($pageIdValue <= 0) {
                            continue;
                        }
                        if (!isset($pageIdsByIssue[$issueId])) {
                            $pageIdsByIssue[$issueId] = [];
                        }
                        if (!in_array($pageIdValue, $pageIdsByIssue[$issueId], true)) {
                            $pageIdsByIssue[$issueId][] = $pageIdValue;
                        }
                    }
                }
            }

            $allPageIds = [];
            foreach ($pageIdsByIssue as $issuePageIds) {
                foreach ($issuePageIds as $pageIdValue) {
                    if (!in_array($pageIdValue, $allPageIds, true)) {
                        $allPageIds[] = $pageIdValue;
                    }
                }
            }

            $pageLabelsById = [];
            if (!empty($allPageIds)) {
                $placeholders = implode(',', array_fill(0, count($allPageIds), '?'));
                $pageStmt = $this->pdo->prepare("SELECT id, page_name, page_number FROM project_pages WHERE id IN ($placeholders)");
                $pageStmt->execute($allPageIds);
                while ($pageRow = $pageStmt->fetch(PDO::FETCH_ASSOC)) {
                    $pageIdValue = (int) ($pageRow['id'] ?? 0);
                    $pageName = trim((string) ($pageRow['page_name'] ?? ''));
                    $pageNumber = trim((string) ($pageRow['page_number'] ?? ''));
                    $pageLabelsById[$pageIdValue] = $pageName !== ''
                        ? ($pageNumber !== '' ? ($pageNumber . ' - ' . $pageName) : $pageName)
                        : ($pageNumber !== '' ? $pageNumber : 'Unknown');
                }
            }

            $groups = [];
            foreach ($rows as $row) {
                $issueId = (int) ($row['issue_id'] ?? 0);
                $title = trim((string) ($row['common_title'] ?? $row['common_issue_title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $pageLabels = array_values(array_unique(array_filter(array_map(function ($pageIdValue) use ($pageLabelsById) {
                    return $pageLabelsById[$pageIdValue] ?? '';
                }, $pageIdsByIssue[$issueId] ?? []))));

                if (empty($pageLabels)) {
                    $pageLabels = ['Unknown'];
                }

                $groupKey = 'common:' . (int) ($row['common_id'] ?? 0);
                $issueCount = max(1, count($pageLabels));
                $severity = (string) ($row['severity'] ?? 'Medium');

                $groups[$groupKey] = [
                    'signature' => $groupKey,
                    'pattern' => $title,
                    'category' => 'Cross-Page Issues',
                    'issues' => array_fill(0, $issueCount, ['issue_id' => $issueId, 'title' => $title]),
                    'pages' => $pageLabels,
                    'severities' => array_fill(0, $issueCount, $severity),
                ];
            }

            return $groups;
        } catch (Exception $e) {
            error_log('CommonIssuesAnalytics authoritative load failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Group similar issues based on content similarity
     * 
     * @param array $issues
     * @return array
     */
    private function groupSimilarIssues($issues) {
        $groups = [];
        
        foreach ($issues as $issue) {
            $signature = $this->generateIssueSignature($issue);
            $groupKey = $this->findSimilarGroup($signature, $groups);
            
            if ($groupKey === null) {
                // Create new group
                $groupKey = $signature;
                $groups[$groupKey] = [
                    'signature' => $signature,
                    'pattern' => $this->extractPattern($issue),
                    'category' => $this->categorizeIssue($issue),
                    'issues' => [],
                    'pages' => [],
                    'severities' => []
                ];
            }
            
            $groups[$groupKey]['issues'][] = $issue;
            $groups[$groupKey]['pages'][] = $this->resolveIssuePageLabel($issue);
            $groups[$groupKey]['severities'][] = $issue['severity'] ?? 'Medium';
        }
        
        return $groups;
    }
    
    /**
     * Generate issue signature for similarity matching
     * 
     * @param array $issue
     * @return string
     */
    private function generateIssueSignature($issue) {
        $explicitCommonTitle = $this->getExplicitCommonIssueTitle($issue);
        if ($explicitCommonTitle !== '') {
            return 'common:' . md5($this->normalizeLowerText($explicitCommonTitle));
        }

        $title = $this->normalizeLowerText($issue['title'] ?? '');
        $description = $this->normalizeLowerText($issue['description'] ?? '');
        
        // Extract key terms and normalize
        $keyTerms = $this->extractKeyTerms($title . ' ' . $description);
        sort($keyTerms);
        
        return md5(implode('|', $keyTerms));
    }
    
    /**
     * Extract key terms from issue text
     * 
     * @param string $text
     * @return array
     */
    private function extractKeyTerms($text) {
        // Remove common words and extract meaningful terms
        $stopWords = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'cannot', 'this', 'that', 'these', 'those', 'a', 'an'];
        
        // Extract words and filter
        preg_match_all('/\b[a-z]{3,}\b/', $text, $matches);
        $words = $matches[0];
        
        // Remove stop words and duplicates
        $keyTerms = array_unique(array_diff($words, $stopWords));
        
        // Add specific accessibility terms with higher weight
        $accessibilityTerms = ['alt', 'aria', 'label', 'heading', 'contrast', 'keyboard', 'focus', 'wcag', 'accessibility', 'screen', 'reader'];
        foreach ($accessibilityTerms as $term) {
            if (strpos($text, $term) !== false) {
                $keyTerms[] = $term . '_priority';
            }
        }
        
        return array_values($keyTerms);
    }
    
    /**
     * Find similar group for issue
     * 
     * @param string $signature
     * @param array $groups
     * @return string|null
     */
    private function findSimilarGroup($signature, $groups) {
        // For now, use exact signature matching
        // Could be enhanced with fuzzy matching algorithms
        return array_key_exists($signature, $groups) ? $signature : null;
    }
    
    /**
     * Extract pattern description from issue
     * 
     * @param array $issue
     * @return string
     */
    private function extractPattern($issue) {
        $explicitCommonTitle = $this->getExplicitCommonIssueTitle($issue);
        if ($explicitCommonTitle !== '') {
            return $explicitCommonTitle;
        }

        $title = $issue['title'] ?? '';
        $description = $issue['description'] ?? '';
        
        // Try to extract a generalized pattern
        $patterns = [
            'Missing alt text' => '/missing.*alt.*text|alt.*text.*missing|image.*without.*alt/i',
            'Color contrast issue' => '/color.*contrast|contrast.*ratio|insufficient.*contrast/i',
            'Missing form label' => '/missing.*label|label.*missing|form.*without.*label/i',
            'Keyboard navigation' => '/keyboard.*navigation|tab.*order|focus.*management/i',
            'Heading structure' => '/heading.*structure|heading.*hierarchy|h[1-6].*order/i',
            'Link accessibility' => '/link.*text|descriptive.*link|link.*purpose/i',
            'ARIA attributes' => '/aria.*attribute|aria.*label|aria.*role/i',
            'Focus indicator' => '/focus.*indicator|focus.*visible|focus.*outline/i'
        ];
        
        $content = $title . ' ' . $description;
        
        foreach ($patterns as $pattern => $regex) {
            if (preg_match($regex, $content)) {
                return $pattern;
            }
        }
        
        // Fallback: use first few words of title
        $words = explode(' ', $title);
        return implode(' ', array_slice($words, 0, 4));
    }

    private function getExplicitCommonIssueTitle($issue) {
        $commonTitle = trim((string)($issue['common_title'] ?? ''));
        if ($commonTitle !== '') {
            return $commonTitle;
        }

        $commonIssueTitle = trim((string)($issue['common_issue_title'] ?? ''));
        if ($commonIssueTitle !== '') {
            return $commonIssueTitle;
        }

        return '';
    }

    private function resolveIssuePageLabel($issue) {
        $pageUrl = trim((string)($issue['page_url'] ?? ''));
        if ($pageUrl !== '' && strcasecmp($pageUrl, 'Unknown') !== 0) {
            return $pageUrl;
        }

        $pageName = trim((string)($issue['page_name'] ?? ''));
        $pageNumber = trim((string)($issue['page_number'] ?? ''));
        if ($pageName !== '') {
            return $pageNumber !== '' ? ($pageNumber . ' - ' . $pageName) : $pageName;
        }

        return 'Unknown';
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
            'Images and Media' => ['image', 'alt', 'media', 'video', 'audio', 'graphic'],
            'Forms and Controls' => ['form', 'input', 'button', 'field', 'control', 'select'],
            'Navigation' => ['navigation', 'menu', 'link', 'breadcrumb', 'tab'],
            'Content Structure' => ['heading', 'structure', 'hierarchy', 'content', 'text'],
            'Visual Design' => ['color', 'contrast', 'visual', 'design', 'layout'],
            'Keyboard Access' => ['keyboard', 'focus', 'tab', 'shortcut', 'access'],
            'ARIA and Semantics' => ['aria', 'role', 'semantic', 'label', 'landmark'],
            'Interactive Elements' => ['interactive', 'clickable', 'hover', 'touch', 'gesture']
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
     * Calculate frequency metrics for issue groups
     * 
     * @param array $issueGroups
     * @return array
     */
    private function calculateFrequencyMetrics($issueGroups) {
        $commonIssues = [];
        
        foreach ($issueGroups as $group) {
            $issueCount = count($group['issues']);
            $pageCount = count(array_unique($group['pages']));
            $severities = array_count_values($group['severities']);
            
            // Calculate impact score
            $impactScore = $this->calculatePatternImpactScore($issueCount, $pageCount, $severities);
            
            // Calculate average severity weight
            $avgSeverityWeight = $this->calculateAverageSeverityWeight($severities);
            
            $commonIssues[] = [
                'pattern' => $group['pattern'],
                'category' => $group['category'],
                'frequency' => $issueCount,
                'pages_affected' => $pageCount,
                'impact_score' => $impactScore,
                'avg_severity_weight' => $avgSeverityWeight,
                'severity_breakdown' => $severities,
                'sample_pages' => array_slice(array_unique($group['pages']), 0, 5),
                'reduction_potential' => $this->calculateReductionPotential($issueCount, $pageCount)
            ];
        }
        
        // Sort by impact score (highest first)
        usort($commonIssues, function($a, $b) {
            return $b['impact_score'] - $a['impact_score'];
        });
        
        return $commonIssues;
    }
    
    /**
     * Calculate impact score for issue pattern
     * 
     * @param int $frequency
     * @param int $pageCount
     * @param array $severities
     * @return float
     */
    private function calculatePatternImpactScore($frequency, $pageCount, $severities) {
        // Base score from frequency and spread
        $baseScore = ($frequency * 0.6) + ($pageCount * 0.4);
        
        // Severity multiplier - weights based on actual DB severity values
        $severityMultiplierMap = [
            'blocker'  => 2.5,
            'critical' => 2.0,
            'major'    => 1.5,
            'minor'    => 1.0,
            'low'      => 0.5,
        ];
        $severityMultiplier = 1.0;
        $totalSeverities = array_sum($severities);
        
        if ($totalSeverities > 0) {
            $severityMultiplier = 0;
            foreach ($severities as $severity => $count) {
                $multiplier = $severityMultiplierMap[$this->normalizeLowerText($severity)] ?? 1.0;
                $severityMultiplier += ($count / $totalSeverities) * $multiplier;
            }
        }
        
        return round($baseScore * $severityMultiplier, 1);
    }
    
    /**
     * Calculate average severity weight
     * 
     * @param array $severities
     * @return float
     */
    private function calculateAverageSeverityWeight($severities) {
        // Weights based on actual DB severity enum values
        $weights = [
            'blocker'  => 5,
            'critical' => 4,
            'major'    => 3,
            'minor'    => 2,
            'low'      => 1,
        ];
        $totalWeight = 0;
        $totalCount = 0;
        
        foreach ($severities as $severity => $count) {
            $weight = $weights[$this->normalizeLowerText($severity)] ?? 2;
            $totalWeight += $weight * $count;
            $totalCount += $count;
        }
        
        return $totalCount > 0 ? round($totalWeight / $totalCount, 1) : 2.0;
    }
    
    /**
     * Calculate reduction potential
     * 
     * @param int $frequency
     * @param int $pageCount
     * @return float
     */
    private function calculateReductionPotential($frequency, $pageCount) {
        // Higher frequency and page spread = higher reduction potential
        $potential = ($frequency * 0.7) + ($pageCount * 0.3);
        
        // Normalize to percentage (assuming max reasonable values)
        $maxFrequency = 100;
        $maxPages = 50;
        $maxPotential = ($maxFrequency * 0.7) + ($maxPages * 0.3);
        
        return round(min(100, ($potential / $maxPotential) * 100), 1);
    }
    
    /**
     * Analyze patterns across pages
     * 
     * @param array $issueGroups
     * @return array
     */
    private function analyzePatterns($issueGroups) {
        $patternsByCategory = [];
        $crossPagePatterns = [];
        
        foreach ($issueGroups as $group) {
            $category = $group['category'];
            $pageCount = count(array_unique($group['pages']));
            
            if (!isset($patternsByCategory[$category])) {
                $patternsByCategory[$category] = [
                    'pattern_count' => 0,
                    'total_issues' => 0,
                    'avg_pages_per_pattern' => 0
                ];
            }
            
            $patternsByCategory[$category]['pattern_count']++;
            $patternsByCategory[$category]['total_issues'] += count($group['issues']);
            
            // Track cross-page patterns
            if ($pageCount > 1) {
                $crossPagePatterns[] = [
                    'pattern' => $group['pattern'],
                    'category' => $category,
                    'pages_affected' => $pageCount,
                    'total_occurrences' => count($group['issues'])
                ];
            }
        }
        
        // Calculate averages
        foreach ($patternsByCategory as $category => &$data) {
            $data['avg_pages_per_pattern'] = $data['pattern_count'] > 0 ? 
                round($data['total_issues'] / $data['pattern_count'], 1) : 0;
        }
        
        // Sort cross-page patterns by impact
        usort($crossPagePatterns, function($a, $b) {
            return ($b['pages_affected'] * $b['total_occurrences']) - ($a['pages_affected'] * $a['total_occurrences']);
        });
        
        return [
            'by_category' => $patternsByCategory,
            'cross_page_patterns' => array_slice($crossPagePatterns, 0, 10),
            'pattern_insights' => $this->generatePatternInsights($patternsByCategory, $crossPagePatterns)
        ];
    }
    
    /**
     * Calculate impact reduction potential
     * 
     * @param array $commonIssues
     * @return array
     */
    private function calculateImpactReduction($commonIssues) {
        $impactData = [];
        
        foreach ($commonIssues as $issue) {
            $impactData[] = [
                'pattern' => $issue['pattern'],
                'frequency' => $issue['frequency'],
                'impact_score' => $issue['impact_score'],
                'reduction_potential' => $issue['reduction_potential'],
                'effort_estimate' => $this->estimateEffort($issue),
                'roi_score' => $this->calculateROI($issue['reduction_potential'], $this->estimateEffort($issue))
            ];
        }
        
        return $impactData;
    }
    
    /**
     * Estimate effort required to fix pattern
     * 
     * @param array $issue
     * @return string
     */
    private function estimateEffort($issue) {
        $frequency = $issue['frequency'];
        $pageCount = $issue['pages_affected'];
        $category = $issue['category'];
        
        // Effort factors by category
        $categoryEffort = [
            'Images and Media' => 2, // Medium effort
            'Forms and Controls' => 3, // High effort
            'Navigation' => 3, // High effort
            'Content Structure' => 2, // Medium effort
            'Visual Design' => 1, // Low effort
            'Keyboard Access' => 3, // High effort
            'ARIA and Semantics' => 2, // Medium effort
            'Interactive Elements' => 3 // High effort
        ];
        
        $baseEffort = $categoryEffort[$category] ?? 2;
        
        // Adjust for frequency and spread
        if ($frequency > 20 || $pageCount > 10) {
            $baseEffort++;
        }
        
        $effortLevels = ['Low', 'Medium', 'High', 'Very High'];
        return $effortLevels[min(3, max(0, $baseEffort - 1))];
    }
    
    /**
     * Calculate ROI score
     * 
     * @param float $reductionPotential
     * @param string $effort
     * @return float
     */
    private function calculateROI($reductionPotential, $effort) {
        $effortWeights = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Very High' => 4];
        $effortWeight = $effortWeights[$effort] ?? 2;
        
        return round($reductionPotential / $effortWeight, 1);
    }
    
    /**
     * Prepare impact analysis data for visualization
     * 
     * @param array $commonIssues
     * @return array
     */
    private function prepareImpactAnalysisData($commonIssues) {
        $data = [];
        
        foreach (array_slice($commonIssues, 0, 20) as $issue) {
            $data[] = [
                'x' => $issue['frequency'],
                'y' => $issue['impact_score'],
                'label' => $issue['pattern'],
                'category' => $issue['category'],
                'size' => $issue['pages_affected']
            ];
        }
        
        return $data;
    }
    
    /**
     * Analyze category breakdown
     * 
     * @param array $commonIssues
     * @return array
     */
    private function analyzeCategoryBreakdown($commonIssues) {
        $categories = [];
        
        foreach ($commonIssues as $issue) {
            $category = $issue['category'];
            
            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'pattern_count' => 0,
                    'total_frequency' => 0,
                    'avg_impact' => 0,
                    'total_pages' => 0
                ];
            }
            
            $categories[$category]['pattern_count']++;
            $categories[$category]['total_frequency'] += $issue['frequency'];
            $categories[$category]['avg_impact'] += $issue['impact_score'];
            $categories[$category]['total_pages'] += $issue['pages_affected'];
        }
        
        // Calculate averages
        foreach ($categories as $category => &$data) {
            $data['avg_impact'] = $data['pattern_count'] > 0 ? 
                round($data['avg_impact'] / $data['pattern_count'], 1) : 0;
        }
        
        // Sort by total frequency
        uasort($categories, function($a, $b) {
            return $b['total_frequency'] - $a['total_frequency'];
        });
        
        return $categories;
    }
    
    /**
     * Analyze severity distribution across common issues
     * 
     * @param array $commonIssues
     * @return array
     */
    private function analyzeSeverityDistribution($commonIssues) {
        $distribution = [];
        
        foreach ($commonIssues as $issue) {
            foreach ($issue['severity_breakdown'] as $severity => $count) {
                $distribution[$severity] = ($distribution[$severity] ?? 0) + $count;
            }
        }
        
        $total = array_sum($distribution);
        $percentages = [];
        foreach ($distribution as $severity => $count) {
            $percentages[$severity] = $this->calculatePercentage($count, $total);
        }
        
        return [
            'counts' => $distribution,
            'percentages' => $percentages
        ];
    }
    
    /**
     * Calculate pattern coverage percentage
     * 
     * @param array $commonIssues
     * @param int $totalIssues
     * @return float
     */
    private function calculatePatternCoverage($commonIssues, $totalIssues) {
        if ($totalIssues === 0) return 0;
        
        $coveredIssues = 0;
        foreach (array_slice($commonIssues, 0, 10) as $issue) { // Top 10 patterns
            $coveredIssues += $issue['frequency'];
        }
        
        return round(($coveredIssues / $totalIssues) * 100, 1);
    }
    
    /**
     * Generate pattern insights
     * 
     * @param array $patternsByCategory
     * @param array $crossPagePatterns
     * @return array
     */
    private function generatePatternInsights($patternsByCategory, $crossPagePatterns) {
        $insights = [];
        
        // Most problematic category
        $topCategory = '';
        $maxIssues = 0;
        foreach ($patternsByCategory as $category => $data) {
            if ($data['total_issues'] > $maxIssues) {
                $maxIssues = $data['total_issues'];
                $topCategory = $category;
            }
        }
        
        if ($topCategory) {
            $insights[] = "'{$topCategory}' has the highest number of issues ({$maxIssues} total)";
        }
        
        // Cross-page pattern insight
        if (!empty($crossPagePatterns)) {
            $topPattern = $crossPagePatterns[0];
            $insights[] = "'{$topPattern['pattern']}' appears across {$topPattern['pages_affected']} pages";
        }
        
        return $insights;
    }
    
    /**
     * Generate recommendations for common issues
     * 
     * @param array $commonIssues
     * @param array $patternAnalysis
     * @return array
     */
    private function generateCommonIssuesRecommendations($commonIssues, $patternAnalysis) {
        $recommendations = [];
        
        if (!empty($commonIssues)) {
            $topIssue = $commonIssues[0];
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'Top Pattern',
                'recommendation' => "Address '{$topIssue['pattern']}' pattern which occurs {$topIssue['frequency']} times across {$topIssue['pages_affected']} pages.",
                'impact' => "Could reduce {$topIssue['reduction_potential']}% of similar issues"
            ];
        }
        
        // Category-based recommendation
        if (!empty($patternAnalysis['by_category'])) {
            $topCategory = array_keys($patternAnalysis['by_category'])[0];
            $categoryData = $patternAnalysis['by_category'][$topCategory];
            
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'Category Focus',
                'recommendation' => "Focus on '{$topCategory}' issues which have {$categoryData['pattern_count']} distinct patterns.",
                'impact' => 'Systematic approach to category-specific issues'
            ];
        }
        
        // Cross-page pattern recommendation
        if (!empty($patternAnalysis['cross_page_patterns'])) {
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'Cross-Page Issues',
                'recommendation' => "Address cross-page patterns to achieve maximum impact with minimal effort.",
                'impact' => 'Fixes multiple pages simultaneously'
            ];
        }
        
        return $recommendations;
    }
}