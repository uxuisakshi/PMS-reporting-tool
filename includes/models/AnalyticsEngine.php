<?php

require_once __DIR__ . '/../client_issue_snapshots.php';

abstract class AnalyticsEngine {
    
    // Cache TTL in seconds (1 hour)
    const CACHE_TTL = 3600;
    
    protected $pdo;
    protected $redis;
    
    public function __construct() {
        $this->pdo = $this->getDatabase();
        $this->redis = $this->getRedis();
    }
    
    abstract public function generateReport($projectId = null, $clientId = null);
    
    protected function getDatabase() {
        try {
            require_once __DIR__ . '/../../config/database.php';
            return Database::getInstance();
        } catch (Exception $e) {
            error_log("Database connection failed in AnalyticsEngine: " . $e->getMessage());
            return null;
        }
    }
    
    protected function getRedis() {
        try {
            if (!class_exists('Redis')) {
                return null;
            }
            
            $redisClass = 'Redis';
            $redis = new $redisClass();
            $redis->connect('localhost', 6379);
            
            return $redis;
        } catch (Exception $e) {
            error_log("Redis connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    protected function getFilteredIssues($projectId = null, $clientId = null) {
        try {
            if (!$this->pdo) {
                return $this->getMockIssues();
            }

            if ($clientId !== null) {
                $records = getClientVisibleIssueRecords($this->pdo, $projectId, [
                    'order_by' => 'i.created_at DESC, i.id DESC',
                ]);

                $issues = [];
                foreach ($records as $record) {
                    $issue = $record['issue'] ?? [];
                    if (empty($issue)) {
                        continue;
                    }

                    $meta = $record['meta'] ?? [];
                    $pages = $record['pages'] ?? [];
                    foreach ($meta as $metaKey => $metaValues) {
                        if (!array_key_exists($metaKey, $issue)) {
                            $issue[$metaKey] = $metaValues;
                        }
                    }

                    if (!isset($issue['page_url'])) {
                        $issue['page_url'] = (string) (($pages[0]['url'] ?? '') ?: '');
                    }
                    if (!isset($issue['page_name'])) {
                        $issue['page_name'] = (string) (($pages[0]['page_name'] ?? '') ?: '');
                    }
                    if (!isset($issue['page_number'])) {
                        $issue['page_number'] = (string) (($pages[0]['page_number'] ?? '') ?: '');
                    }
                    if (!isset($issue['status'])) {
                        $issue['status'] = (string) ($issue['status_name'] ?? '');
                    }
                    $issue['client_ready'] = 1;
                    $issue['client_visible_source'] = (string) ($record['source'] ?? 'live');
                    $issue['client_visible_published_at'] = (string) ($record['published_at'] ?? '');
                    $issues[] = $issue;
                }

                return $issues;
            }
            
            $sql = "SELECT * FROM issues WHERE 1=1";
            $params = [];
            
            if ($projectId !== null) {
                if (is_array($projectId)) {
                    if (count($projectId) > 0) {
                        $placeholders = str_repeat('?,', count($projectId) - 1) . '?';
                        $sql .= " AND project_id IN ($placeholders)";
                        $params = array_merge($params, $projectId);
                    } else {
                        // Empty array - no results should be returned
                        $sql .= " AND 1=0";
                    }
                } else {
                    $sql .= " AND project_id = ?";
                    $params[] = $projectId;
                }
            }
            
            $sql .= " ORDER BY created_at DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Error fetching filtered issues: " . $e->getMessage());
            return $this->getMockIssues();
        }
    }
    
    protected function getMockIssues() {
        return [
            [
                'id' => 1,
                'title' => 'Missing alt text for images',
                'description' => 'Images on the homepage are missing alternative text',
                'severity' => 'High',
                'status' => 'Open',
                'users_affected' => 25,
                'page_url' => 'https://example.com/home',
                'created_at' => '2024-01-15 10:00:00',
                'resolved_at' => null,
                'client_ready' => 1
            ],
            [
                'id' => 2,
                'title' => 'Color contrast issue in navigation',
                'description' => 'Navigation text does not meet WCAG AA contrast requirements',
                'severity' => 'Medium',
                'status' => 'Resolved',
                'users_affected' => 50,
                'page_url' => 'https://example.com/navigation',
                'created_at' => '2024-01-10 14:30:00',
                'resolved_at' => '2024-01-20 16:45:00',
                'client_ready' => 1
            ],
            [
                'id' => 3,
                'title' => 'Form labels missing',
                'description' => 'Contact form inputs lack proper labels',
                'severity' => 'Critical',
                'status' => 'In Progress',
                'users_affected' => 100,
                'page_url' => 'https://example.com/contact',
                'created_at' => '2024-01-12 09:15:00',
                'resolved_at' => null,
                'client_ready' => 1
            ]
        ];
    }
    
    protected function generateCacheKey($reportType, $projectId = null, $clientId = null) {
        $keyParts = ['analytics', $reportType];
        
        if ($projectId !== null) {
            if (is_array($projectId)) {
                $keyParts[] = 'projects_' . implode('_', $projectId);
            } else {
                $keyParts[] = 'project_' . $projectId;
            }
        }
        
        if ($clientId !== null) {
            $keyParts[] = 'client_' . $clientId;
        }
        
        return implode(':', $keyParts);
    }
    
    protected function getCachedReport($cacheKey) {
        if (!$this->redis) {
            return null;
        }
        
        try {
            $cached = $this->redis->get($cacheKey);
            if ($cached) {
                $data = json_decode($cached, true);
                if ($data) {
                    return new AnalyticsReport($data);
                }
            }
        } catch (Exception $e) {
            error_log("Cache retrieval error: " . $e->getMessage());
        }
        
        return null;
    }
    
    protected function cacheReport($cacheKey, $report) {
        if (!$this->redis) {
            return;
        }
        
        try {
            $data = $report->toArray();
            $this->redis->setex($cacheKey, self::CACHE_TTL, json_encode($data));
        } catch (Exception $e) {
            error_log("Cache storage error: " . $e->getMessage());
        }
    }
    
    protected function calculatePercentage($numerator, $denominator) {
        if ($denominator === 0) {
            return 0.0;
        }
        return round(($numerator / $denominator) * 100, 1);
    }
    
    protected function calculateImpactScore($frequency, $spread) {
        $impactScore = ($frequency * 0.7) + ($spread * 0.3);
        return round($impactScore, 1);
    }

    protected function normalizeTextValue($value) {
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, function ($item) use (&$parts) {
                if ($item === null) {
                    return;
                }
                $text = trim((string) $item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            });

            return implode(' ', $parts);
        }

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    protected function normalizeLowerText($value) {
        return strtolower($this->normalizeTextValue($value));
    }

    protected function validateProjectAccess($clientId, $projectId) {
        if (!$this->pdo) return true;
        $stmt = $this->pdo->prepare("SELECT 1 FROM client_project_assignments WHERE client_user_id = ? AND project_id = ? AND is_active = 1");
        $stmt->execute([$clientId, $projectId]);
        return $stmt->fetch() !== false;
    }

    protected function getCachedData($cacheKey) {
        if (!$this->redis) return null;
        $data = $this->redis->get($cacheKey);
        return $data ? json_decode($data, true) : null;
    }

    protected function setCachedData($cacheKey, $data, $ttl = self::CACHE_TTL) {
        if (!$this->redis) return false;
        return $this->redis->setex($cacheKey, $ttl, json_encode($data));
    }

    protected function getAssignedProjects($clientId) {
        if (!$this->pdo) return [];
        $stmt = $this->pdo->prepare("SELECT project_id FROM client_project_assignments WHERE client_user_id = ? AND is_active = 1");
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function getClientReadyIssues($projectId, $filters = []) {
        return $this->getFilteredIssues($projectId, 1);
    }

    protected function getClientReadyIssuesMultiple($projectIds, $filters = []) {
        return $this->getFilteredIssues($projectIds, 1);
    }

    protected function getEmptyReport() {
        return [
            'total_issues' => 0,
            'distribution' => [],
            'summary' => [],
            'impact_analysis' => [],
            'trends' => [],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    protected function aggregateByField($data, $field) {
        $aggregated = [];
        foreach ($data as $item) {
            $value = $item[$field] ?? 'unknown';
            if (!isset($aggregated[$value])) {
                $aggregated[$value] = [];
            }
            $aggregated[$value][] = $item;
        }
        return $aggregated;
    }
}