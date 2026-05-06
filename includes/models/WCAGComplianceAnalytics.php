<?php

require_once __DIR__ . '/AnalyticsEngine.php';
require_once __DIR__ . '/AnalyticsReport.php';

/**
 * WCAG Compliance Analytics Engine
 * 
 * Analyzes issues by WCAG conformance levels (A, AA, AAA) and calculates
 * compliance percentages, identifying common violations and compliance gaps.
 * 
 * Requirements: 5.1, 5.2, 5.4
 */
class WCAGComplianceAnalytics extends AnalyticsEngine {
    
    /**
     * Generate WCAG compliance analytics report
     * 
     * @param int|null $projectId Optional project filter
     * @param int|null $clientId Optional client filter for access control
     * @return AnalyticsReport
     */
    public function generateReport($projectId = null, $clientId = null) {
        $cacheKey = $this->generateCacheKey('wcag_compliance', $projectId, $clientId);
        
        if ($cached = $this->getCachedReport($cacheKey)) {
            return $cached;
        }
        
        $data = $this->calculateWCAGCompliance($projectId, $clientId);
        
        $report = new AnalyticsReport([
            'type' => 'wcag_compliance',
            'title' => 'WCAG Compliance Analysis',
            'description' => 'Analysis of issues by WCAG conformance levels with compliance percentages',
            'data' => $data,
            'metadata' => [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'total_issues' => $data['summary']['total_issues'],
                'compliance_score' => $data['summary']['overall_compliance_score']
            ],
            'visualization_config' => [
                'primary_chart' => [
                    'type' => 'bar',
                    'data_key' => 'level_distribution',
                    'title' => 'Issues by WCAG Level',
                    'x_axis' => 'WCAG Level',
                    'y_axis' => 'Issue Count'
                ],
                'secondary_chart' => [
                    'type' => 'pie',
                    'data_key' => 'compliance_breakdown',
                    'title' => 'Compliance Status Distribution'
                ]
            ]
        ]);
        
        $this->cacheReport($cacheKey, $report);
        return $report;
    }
    
    /**
     * Calculate WCAG compliance metrics
     * 
     * @param int|null $projectId
     * @param int|null $clientId
     * @return array
     */
    private function calculateWCAGCompliance($projectId = null, $clientId = null) {
        $issues = $this->getFilteredIssues($projectId, $clientId);
        $totalVisibleIssues = count($issues);
        $resolvedIssueCount = 0;

        if ($clientId !== null) {
            $issues = array_values(array_filter($issues, function ($issue) use (&$resolvedIssueCount) {
                $isResolved = $this->isResolvedIssue($issue);
                if ($isResolved) {
                    $resolvedIssueCount++;
                }
                return !$isResolved;
            }));
        }
        
        $levelCounts = [
            'A' => 0,
            'AA' => 0,
            'AAA' => 0,
            'Unknown' => 0
        ];
        
        $severityByLevel = [
            'A' => [],
            'AA' => [],
            'AAA' => [],
            'Unknown' => []
        ];
        
        $commonViolations = [];
        $pageCompliance = [];
        
        foreach ($issues as $issue) {
            $wcagLevel = $this->extractWCAGLevel($issue);
            $severity = $issue['severity'] ?? 'Medium';
            $page = $issue['page_url'] ?? 'Unknown';
            
            $levelCounts[$wcagLevel]++;
            $severityByLevel[$wcagLevel][$severity] = ($severityByLevel[$wcagLevel][$severity] ?? 0) + 1;
            
            // Track common violations
            $violationType = $this->categorizeViolation($issue);
            if (!isset($commonViolations[$violationType])) {
                $commonViolations[$violationType] = [
                    'count' => 0,
                    'wcag_level' => $wcagLevel,
                    'avg_severity' => 0,
                    'pages_affected' => []
                ];
            }
            $commonViolations[$violationType]['count']++;
            $commonViolations[$violationType]['pages_affected'][] = $page;
            
            // Track page-level compliance
            if (!isset($pageCompliance[$page])) {
                $pageCompliance[$page] = [
                    'total_issues' => 0,
                    'by_level' => ['A' => 0, 'AA' => 0, 'AAA' => 0, 'Unknown' => 0],
                    'compliance_score' => 0
                ];
            }
            $pageCompliance[$page]['total_issues']++;
            $pageCompliance[$page]['by_level'][$wcagLevel]++;
        }
        
        // Calculate compliance scores
        $activeIssueCount = array_sum($levelCounts);
        $totalIssues = $clientId !== null ? $totalVisibleIssues : $activeIssueCount;
        $complianceScores = $this->calculateComplianceScores($levelCounts, $totalIssues);
        
        // Process common violations
        $topViolations = $this->processCommonViolations($commonViolations);
        
        // Calculate page compliance scores
        foreach ($pageCompliance as $page => &$compliance) {
            $compliance['compliance_score'] = $this->calculatePageComplianceScore($compliance);
        }
        
        return [
            'summary' => [
                'total_issues' => $totalIssues,
                'resolved_issues' => $resolvedIssueCount,
                'active_issues' => $activeIssueCount,
                'overall_compliance_score' => $complianceScores['overall'],
                'level_a_compliance' => $complianceScores['level_a'],
                'level_aa_compliance' => $complianceScores['level_aa'],
                'level_aaa_compliance' => $complianceScores['level_aaa']
            ],
            'level_distribution' => [
                ['level' => 'Level A', 'count' => $levelCounts['A'], 'percentage' => $this->calculatePercentage($levelCounts['A'], $totalIssues)],
                ['level' => 'Level AA', 'count' => $levelCounts['AA'], 'percentage' => $this->calculatePercentage($levelCounts['AA'], $totalIssues)],
                ['level' => 'Level AAA', 'count' => $levelCounts['AAA'], 'percentage' => $this->calculatePercentage($levelCounts['AAA'], $totalIssues)],
                ['level' => 'Unknown', 'count' => $levelCounts['Unknown'], 'percentage' => $this->calculatePercentage($levelCounts['Unknown'], $totalIssues)]
            ],
            'severity_by_level' => $severityByLevel,
            'compliance_breakdown' => [
                ['status' => 'Compliant', 'count' => max(0, $totalIssues - $levelCounts['A'] - $levelCounts['AA']), 'percentage' => $complianceScores['overall']],
                ['status' => 'Level A Issues', 'count' => $levelCounts['A'], 'percentage' => $this->calculatePercentage($levelCounts['A'], $totalIssues)],
                ['status' => 'Level AA Issues', 'count' => $levelCounts['AA'], 'percentage' => $this->calculatePercentage($levelCounts['AA'], $totalIssues)],
                ['status' => 'Level AAA Issues', 'count' => $levelCounts['AAA'], 'percentage' => $this->calculatePercentage($levelCounts['AAA'], $totalIssues)]
            ],
            'top_violations' => $topViolations,
            'page_compliance' => array_slice($pageCompliance, 0, 10), // Top 10 pages
            'recommendations' => $this->generateRecommendations($levelCounts, $topViolations)
        ];
    }
    
    /**
     * Extract WCAG level from issue data
     * 
     * @param array $issue
     * @return string
     */
    private function extractWCAGLevel($issue) {
        $title = $this->normalizeLowerText($issue['title'] ?? '');
        $description = $this->normalizeLowerText($issue['description'] ?? '');
        $content = $title . ' ' . $description;
        
        // Check for explicit WCAG level mentions
        if (preg_match('/wcag\s*(2\.1|2\.0)?\s*level?\s*(aaa|aa|a)\b/i', $content, $matches)) {
            return strtoupper(end($matches));
        }
        
        // Check for specific WCAG success criteria patterns
        if (preg_match('/\b(1\.[1-4]\.[0-9]+|2\.[1-5]\.[0-9]+|3\.[1-3]\.[0-9]+|4\.1\.[1-3])\b/', $content, $matches)) {
            return $this->mapSuccessCriteriaToLevel($matches[0]);
        }
        
        // Infer level from common accessibility issues
        return $this->inferWCAGLevel($content);
    }
    
    /**
     * Map WCAG success criteria to conformance level
     * 
     * @param string $criteria
     * @return string
     */
    private function mapSuccessCriteriaToLevel($criteria) {
        // Level A criteria
        $levelA = [
            '1.1.1', '1.2.1', '1.2.2', '1.2.3', '1.3.1', '1.3.2', '1.3.3', '1.4.1', '1.4.2',
            '2.1.1', '2.1.2', '2.1.4', '2.2.1', '2.2.2', '2.3.1', '2.4.1', '2.4.2', '2.4.3', '2.4.4',
            '3.1.1', '3.2.1', '3.2.2', '3.3.1', '3.3.2', '4.1.1', '4.1.2'
        ];
        
        // Level AA criteria
        $levelAA = [
            '1.2.4', '1.2.5', '1.3.4', '1.3.5', '1.4.3', '1.4.4', '1.4.5', '1.4.10', '1.4.11',
            '1.4.12', '1.4.13', '2.4.5', '2.4.6', '2.4.7', '2.4.11', '2.4.12', '2.4.13',
            '2.5.3', '2.5.7', '2.5.8', '3.1.2', '3.2.3', '3.2.4', '3.2.6', '3.3.3', '3.3.4'
        ];
        
        if (in_array($criteria, $levelA)) {
            return 'A';
        } elseif (in_array($criteria, $levelAA)) {
            return 'AA';
        } else {
            return 'AAA';
        }
    }
    
    /**
     * Infer WCAG level from issue content
     * 
     * @param string $content
     * @return string
     */
    private function inferWCAGLevel($content) {
        // Level A issues (basic accessibility)
        $levelAPatterns = [
            'alt text', 'alternative text', 'image alt', 'missing alt',
            'accessible name', 'name computation', 'link name', 'button name',
            'keyboard navigation', 'keyboard access', 'tab order',
            'form label', 'input label', 'missing label',
            'heading structure', 'heading hierarchy', 'h1', 'h2', 'h3',
            'page title', 'document title'
        ];
        
        // Level AA issues (enhanced accessibility)
        $levelAAPatterns = [
            'color contrast', 'contrast ratio', 'text contrast',
            'focus indicator', 'focus visible', 'focus outline',
            'hover or focus', 'additional content on hover', 'not dismissible',
            'resize text', 'text scaling', 'zoom',
            'link purpose', 'link text', 'descriptive link'
        ];
        
        // Level AAA issues (enhanced accessibility)
        $levelAAAPatterns = [
            'enhanced contrast', 'aaa contrast',
            'context help', 'help text',
            'error prevention', 'error suggestion'
        ];
        
        foreach ($levelAAAPatterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                return 'AAA';
            }
        }

        foreach ($levelAAPatterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                return 'AA';
            }
        }

        foreach ($levelAPatterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                return 'A';
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Categorize violation type
     * 
     * @param array $issue
     * @return string
     */
    private function categorizeViolation($issue) {
        $content = $this->normalizeLowerText(($issue['title'] ?? '') . ' ' . ($issue['description'] ?? ''));
        
        $categories = [
            'Images and Media' => ['alt text', 'alternative text', 'image', 'media', 'video', 'audio'],
            'Keyboard Navigation' => ['keyboard', 'tab', 'focus', 'navigation'],
            'Forms and Labels' => ['form', 'label', 'input', 'button', 'field'],
            'Color and Contrast' => ['color', 'contrast', 'background', 'foreground'],
            'Headings and Structure' => ['heading', 'structure', 'hierarchy', 'h1', 'h2', 'h3'],
            'Links and Navigation' => ['link', 'navigation', 'menu', 'breadcrumb'],
            'Content and Language' => ['content', 'language', 'text', 'reading'],
            'Interactive Elements' => ['interactive', 'clickable', 'hover', 'touch']
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
     * Calculate compliance scores
     * 
     * @param array $levelCounts
     * @param int $totalIssues
     * @return array
     */
    private function calculateComplianceScores($levelCounts, $totalIssues) {
        if ($totalIssues === 0) {
            return [
                'overall' => 100,
                'level_a' => 100,
                'level_aa' => 100,
                'level_aaa' => 100
            ];
        }
        
        // Treat unresolved issues with unknown WCAG mapping as Level A penalties
        // so every active accessibility issue affects the client-facing score.
        $effectiveLevelACount = $levelCounts['A'] + $levelCounts['Unknown'];

        // Compliance is inverse of issues (fewer issues = higher compliance)
        $levelACompliance = max(0, 100 - ($effectiveLevelACount / $totalIssues * 100));
        $levelAACompliance = max(0, 100 - ($levelCounts['AA'] / $totalIssues * 100));
        $levelAAACompliance = max(0, 100 - ($levelCounts['AAA'] / $totalIssues * 100));
        
        // Overall compliance considers all levels with weighted importance
        $overallCompliance = ($levelACompliance * 0.5) + ($levelAACompliance * 0.3) + ($levelAAACompliance * 0.2);
        
        return [
            'overall' => round($overallCompliance, 1),
            'level_a' => round($levelACompliance, 1),
            'level_aa' => round($levelAACompliance, 1),
            'level_aaa' => round($levelAAACompliance, 1)
        ];
    }
    
    /**
     * Process common violations data
     * 
     * @param array $violations
     * @return array
     */
    private function processCommonViolations($violations) {
        // Sort by count and get top 10
        uasort($violations, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        $result = [];
        $rank = 1;
        foreach (array_slice($violations, 0, 10, true) as $type => $data) {
            $result[] = [
                'rank' => $rank++,
                'violation_type' => $type,
                'count' => $data['count'],
                'wcag_level' => $data['wcag_level'],
                'pages_affected' => count(array_unique($data['pages_affected'])),
                'impact_score' => $this->calculateViolationImpactScore($data['count'], count(array_unique($data['pages_affected'])))
            ];
        }
        
        return $result;
    }
    
    /**
     * Calculate page compliance score
     * 
     * @param array $compliance
     * @return float
     */
    private function calculatePageComplianceScore($compliance) {
        $total = $compliance['total_issues'];
        if ($total === 0) return 100;
        
        // Weight different levels differently
        $weightedIssues = ($compliance['by_level']['A'] * 3) + 
                         ($compliance['by_level']['AA'] * 2) + 
                         ($compliance['by_level']['AAA'] * 1);
        
        $maxPossibleWeight = $total * 3; // If all were Level A
        $score = max(0, 100 - ($weightedIssues / $maxPossibleWeight * 100));
        
        return round($score, 1);
    }
    
    /**
     * Generate recommendations based on analysis
     * 
     * @param array $levelCounts
     * @param array $topViolations
     * @return array
     */
    private function generateRecommendations($levelCounts, $topViolations) {
        $recommendations = [];
        
        // Priority recommendations based on level distribution
        if ($levelCounts['A'] > 0) {
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'Level A Compliance',
                'recommendation' => 'Address Level A issues first as they represent fundamental accessibility barriers.',
                'impact' => 'Critical for basic accessibility compliance'
            ];
        }
        
        if ($levelCounts['AA'] > $levelCounts['A']) {
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'Level AA Focus',
                'recommendation' => 'Focus on Level AA issues to meet standard compliance requirements.',
                'impact' => 'Required for most accessibility standards'
            ];
        }
        
        // Recommendations based on top violations
        if (!empty($topViolations)) {
            $topViolation = $topViolations[0];
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'Common Issues',
                'recommendation' => "Address '{$topViolation['violation_type']}' issues which affect {$topViolation['pages_affected']} pages.",
                'impact' => 'High impact due to widespread occurrence'
            ];
        }
        
        return $recommendations;
    }

    private function isResolvedIssue($issue) {
        $status = $this->normalizeLowerText($issue['status_name'] ?? ($issue['status'] ?? ''));
        return in_array($status, ['resolved', 'closed', 'fixed'], true);
    }
    
    /**
     * Calculate impact score for violations
     * 
     * @param int $count
     * @param int $pagesAffected
     * @return float
     */
    private function calculateViolationImpactScore($count, $pagesAffected) {
        // Impact = frequency * spread
        return round(($count * 0.7) + ($pagesAffected * 0.3), 1);
    }
}