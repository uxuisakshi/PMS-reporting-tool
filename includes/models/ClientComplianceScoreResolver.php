<?php

require_once __DIR__ . '/ClientAccessControlManager.php';
require_once __DIR__ . '/WCAGComplianceAnalytics.php';
require_once __DIR__ . '/ComplianceTrendAnalytics.php';

class ClientComplianceScoreResolver {
    private $accessControl;

    public function __construct($accessControl = null) {
        $this->accessControl = $accessControl instanceof ClientAccessControlManager
            ? $accessControl
            : new ClientAccessControlManager();
    }

    public function resolveForClientUser($clientUserId, $projectScope = null): float {
        $normalizedScope = $this->normalizeProjectScope($projectScope);

        if ($normalizedScope === null) {
            $assignedProjects = $this->accessControl->getAssignedProjects((int) $clientUserId);
            $projectIds = array_values(array_unique(array_map('intval', array_column($assignedProjects, 'id'))));

            if (empty($projectIds)) {
                return 0.0;
            }

            $normalizedScope = count($projectIds) === 1 ? $projectIds[0] : $projectIds;
        }

        return $this->resolveForScope($normalizedScope, (int) $clientUserId);
    }

    public function resolveForScope($projectScope, $clientFilterToken = 1): float {
        $normalizedScope = $this->normalizeProjectScope($projectScope);

        if ($normalizedScope === null || $normalizedScope === []) {
            return 0.0;
        }

        try {
            $wcagAnalytics = new WCAGComplianceAnalytics();
            $wcagReport = $wcagAnalytics->generateReport($normalizedScope, $clientFilterToken);
            $wcagData = $wcagReport ? $wcagReport->getData() : [];
            $wcagSummary = $wcagData['summary'] ?? [];
            $wcagScore = round((float) ($wcagSummary['overall_compliance_score'] ?? 0), 1);

            if ($wcagScore > 0 || (int) ($wcagSummary['total_issues'] ?? 0) === 0) {
                return $wcagScore;
            }
        } catch (Exception $wcagException) {
            error_log('ClientComplianceScoreResolver WCAG score error: ' . $wcagException->getMessage());
        }

        try {
            $trendAnalytics = new ComplianceTrendAnalytics();
            $trendReport = $trendAnalytics->generateReport($normalizedScope, $clientFilterToken);
            $trendData = $trendReport ? $trendReport->getData() : [];
            return round((float) (($trendData['summary']['overall_resolution_rate'] ?? 0)), 1);
        } catch (Exception $trendException) {
            error_log('ClientComplianceScoreResolver trend score error: ' . $trendException->getMessage());
        }

        return 0.0;
    }

    public function calculateWcagComplianceFromIssues(array $issues): float {
        $totalIssues = count($issues);
        $issues = array_values(array_filter($issues, function ($issue) {
            return !$this->isResolvedIssue($issue);
        }));

        $levelCounts = [
            'A' => 0,
            'AA' => 0,
            'AAA' => 0,
            'Unknown' => 0
        ];

        foreach ($issues as $issue) {
            $level = $this->extractWCAGLevel($issue);
            $levelCounts[$level] = ($levelCounts[$level] ?? 0) + 1;
        }

        if ($totalIssues === 0) {
            return 100.0;
        }

        $effectiveLevelACount = $levelCounts['A'] + $levelCounts['Unknown'];

        $levelACompliance = max(0, 100 - ($effectiveLevelACount / $totalIssues * 100));
        $levelAACompliance = max(0, 100 - ($levelCounts['AA'] / $totalIssues * 100));
        $levelAAACompliance = max(0, 100 - ($levelCounts['AAA'] / $totalIssues * 100));

        return round(($levelACompliance * 0.5) + ($levelAACompliance * 0.3) + ($levelAAACompliance * 0.2), 1);
    }

    private function normalizeProjectScope($projectScope) {
        if ($projectScope === null) {
            return null;
        }

        if (is_array($projectScope)) {
            $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectScope))));

            if (empty($projectIds)) {
                return [];
            }

            return count($projectIds) === 1 ? $projectIds[0] : $projectIds;
        }

        $projectId = (int) $projectScope;
        return $projectId > 0 ? $projectId : [];
    }

    private function extractWCAGLevel(array $issue): string {
        $title = strtolower((string) ($issue['title'] ?? ''));
        $description = strtolower((string) ($issue['description'] ?? ''));
        $content = $title . ' ' . $description;

        if (preg_match('/wcag\s*(2\.1|2\.0)?\s*level?\s*(aaa|aa|a)\b/i', $content, $matches)) {
            return strtoupper((string) end($matches));
        }

        if (preg_match('/\b(1\.[1-4]\.[0-9]+|2\.[1-5]\.[0-9]+|3\.[1-3]\.[0-9]+|4\.1\.[1-3])\b/', $content, $matches)) {
            return $this->mapSuccessCriteriaToLevel($matches[0]);
        }

        return $this->inferWCAGLevel($content);
    }

    private function mapSuccessCriteriaToLevel(string $criteria): string {
        $levelA = [
            '1.1.1', '1.2.1', '1.2.2', '1.2.3', '1.3.1', '1.3.2', '1.3.3', '1.4.1', '1.4.2',
            '2.1.1', '2.1.2', '2.1.4', '2.2.1', '2.2.2', '2.3.1', '2.4.1', '2.4.2', '2.4.3', '2.4.4',
            '3.1.1', '3.2.1', '3.2.2', '3.3.1', '3.3.2', '4.1.1', '4.1.2'
        ];

        $levelAA = [
            '1.2.4', '1.2.5', '1.3.4', '1.3.5', '1.4.3', '1.4.4', '1.4.5', '1.4.10', '1.4.11',
            '1.4.12', '1.4.13', '2.4.5', '2.4.6', '2.4.7', '2.4.11', '2.4.12', '2.4.13',
            '2.5.3', '2.5.7', '2.5.8', '3.1.2', '3.2.3', '3.2.4', '3.2.6', '3.3.3', '3.3.4'
        ];

        if (in_array($criteria, $levelA, true)) {
            return 'A';
        }

        if (in_array($criteria, $levelAA, true)) {
            return 'AA';
        }

        return 'AAA';
    }

    private function inferWCAGLevel(string $content): string {
        $levelAPatterns = [
            'alt text', 'alternative text', 'image alt', 'missing alt',
            'accessible name', 'name computation', 'link name', 'button name',
            'keyboard navigation', 'keyboard access', 'tab order',
            'form label', 'input label', 'missing label',
            'heading structure', 'heading hierarchy', 'h1', 'h2', 'h3',
            'page title', 'document title'
        ];

        $levelAAPatterns = [
            'color contrast', 'contrast ratio', 'text contrast',
            'focus indicator', 'focus visible', 'focus outline',
            'hover or focus', 'additional content on hover', 'not dismissible',
            'resize text', 'text scaling', 'zoom',
            'link purpose', 'link text', 'descriptive link'
        ];

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

    private function isResolvedIssue(array $issue): bool {
        $status = strtolower(trim((string) ($issue['status_name'] ?? ($issue['status'] ?? ''))));
        return in_array($status, ['resolved', 'closed', 'fixed'], true);
    }
}