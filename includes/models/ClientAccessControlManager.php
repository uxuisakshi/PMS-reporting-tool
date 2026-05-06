<?php
/**
 * ClientAccessControlManager
 * Manages client access control, project assignments, and permission validation
 * Implements hasProjectAccess() and getAssignedProjects() with active assignment filtering
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/redis.php';
require_once __DIR__ . '/ClientComplianceScoreResolver.php';
require_once __DIR__ . '/../client_issue_snapshots.php';
require_once __DIR__ . '/../helpers.php';

class ClientAccessControlManager {
    private $db;
    private $redis;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->redis = RedisConfig::getInstance();
    }
    
    /**
     * Check if client user has access to a specific project
     * @param int $clientUserId Client user ID
     * @param int $projectId Project ID to check access for
     * @return bool True if client has access, false otherwise
     */
    public function hasProjectAccess($clientUserId, $projectId) {
        // Validate inputs
        if (!$clientUserId || !$projectId) {
            return false;
        }
        
        // Check cache first
        $cacheKey = "client_access_{$clientUserId}_{$projectId}";
        if ($this->redis->isAvailable()) {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                return (bool)$cached;
            }
        }
        
        try {
            // Check if user is active
            $stmt = $this->db->prepare("
                SELECT role FROM users 
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$clientUserId]);
            $dbRole = $stmt->fetchColumn();
            
            if (!$dbRole) {
                return false;
            }
            
            // Check primary assignments table first
            $hasAccess = false;
            try {
                $stmt = $this->db->prepare("
                    SELECT 1 
                    FROM client_project_assignments cpa
                    INNER JOIN projects p ON cpa.project_id = p.id
                    WHERE cpa.client_user_id = ?
                    AND cpa.project_id = ?
                    AND cpa.is_active = 1
                    AND (cpa.expires_at IS NULL OR cpa.expires_at > NOW())
                    AND p.status NOT IN ('cancelled', 'archived')
                    LIMIT 1
                ");
                $stmt->execute([$clientUserId, $projectId]);
                if ($stmt->fetchColumn() !== false) {
                    $hasAccess = true;
                }
            } catch (Exception $e) {
                error_log('ClientAccessControlManager hasProjectAccess (cpa) error: ' . $e->getMessage());
            }

            // Also check legacy client_permissions table if not found yet
            if (!$hasAccess) {
                try {
                    $stmt = $this->db->prepare("
                        SELECT 1 
                        FROM client_permissions cp
                        INNER JOIN projects p ON cp.project_id = p.id
                        WHERE cp.user_id = ?
                        AND cp.project_id = ?
                        AND cp.is_active = 1
                        AND (cp.expires_at IS NULL OR cp.expires_at > NOW())
                        AND p.status NOT IN ('cancelled', 'archived')
                        LIMIT 1
                    ");
                    $stmt->execute([$clientUserId, $projectId]);
                    if ($stmt->fetchColumn() !== false) {
                        $hasAccess = true;
                    }
                } catch (Exception $e) {
                    error_log('ClientAccessControlManager hasProjectAccess (cp) error: ' . $e->getMessage());
                }
            }

            // Creator of the project should always retain access.
            if (!$hasAccess) {
                try {
                    $creatorStmt = $this->db->prepare("
                        SELECT 1
                        FROM projects p
                        WHERE p.id = ?
                          AND p.created_by = ?
                          AND p.status NOT IN ('cancelled', 'archived')
                        LIMIT 1
                    ");
                    $creatorStmt->execute([$projectId, $clientUserId]);
                    if ($creatorStmt->fetchColumn() !== false) {
                        $hasAccess = true;
                    }
                } catch (Exception $e) {
                    error_log('ClientAccessControlManager hasProjectAccess (creator) error: ' . $e->getMessage());
                }
            }
            
            // Cache result for 5 minutes
            if ($this->redis->isAvailable()) {
                $this->redis->set($cacheKey, $hasAccess, 300);
            }
            
            return $hasAccess;
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager hasProjectAccess error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all projects assigned to a client user
     * @param int $clientUserId Client user ID
     * @return array Array of assigned projects with details
     */
    public function getAssignedProjects($clientUserId) {
        // Validate input
        if (!$clientUserId) {
            return [];
        }
        
        // Check cache first
        $cacheKey = "client_projects_{$clientUserId}";
        if ($this->redis->isAvailable()) {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        try {
            // Check if user is active
            $stmt = $this->db->prepare("
                SELECT role FROM users 
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$clientUserId]);
            $dbRole = $stmt->fetchColumn();
            
            if (!$dbRole) {
                return [];
            }
            
            // First try to get projects from client_project_assignments
            $projects = [];
            try {
                $stmt = $this->db->prepare("
                    SELECT 
                        p.id,
                        p.po_number,
                        p.project_code,
                        p.title,
                        p.description,
                        p.project_type,
                        p.priority,
                        p.status,
                        p.created_at,
                        p.completed_at,
                        p.client_id,
                        c.name as client_name,
                        cpa.assigned_at,
                        cpa.expires_at,
                        cpa.notes as assignment_notes,
                        admin.full_name as assigned_by_name,
                        (SELECT COUNT(*) FROM issues i 
                         WHERE i.project_id = p.id AND i.client_ready = 1) as client_ready_issues_count,
                        (SELECT COUNT(*) FROM issues i 
                         WHERE i.project_id = p.id) as total_issues_count
                    FROM client_project_assignments cpa
                    INNER JOIN projects p ON cpa.project_id = p.id
                    LEFT JOIN clients c ON p.client_id = c.id
                    LEFT JOIN users admin ON cpa.assigned_by_admin_id = admin.id
                    WHERE cpa.client_user_id = ?
                    AND cpa.is_active = 1
                    AND (cpa.expires_at IS NULL OR cpa.expires_at > NOW())
                    AND p.status NOT IN ('cancelled', 'archived')
                    ORDER BY cpa.assigned_at DESC, p.title ASC
                ");
                $stmt->execute([$clientUserId]);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Table may not exist on all installations - fall back to legacy table only
                error_log('ClientAccessControlManager getAssignedProjects (cpa) error: ' . $e->getMessage());
                $projects = [];
            }
            
            // Also get projects from legacy client_permissions table
            try {
                $legacyStmt = $this->db->prepare("
                    SELECT 
                        p.id,
                        p.po_number,
                        p.project_code,
                        p.title,
                        p.description,
                        p.project_type,
                        p.priority,
                        p.status,
                        p.created_at,
                        p.completed_at,
                        p.client_id,
                        c.name as client_name,
                        cp.created_at as assigned_at,
                        cp.expires_at,
                        '' as assignment_notes,
                        NULL as assigned_by_name,
                        (SELECT COUNT(*) FROM issues i 
                         WHERE i.project_id = p.id AND i.client_ready = 1) as client_ready_issues_count,
                        (SELECT COUNT(*) FROM issues i 
                         WHERE i.project_id = p.id) as total_issues_count
                    FROM client_permissions cp
                    INNER JOIN projects p ON cp.project_id = p.id
                    LEFT JOIN clients c ON p.client_id = c.id
                    WHERE cp.user_id = ?
                    AND cp.project_id IS NOT NULL
                    AND cp.is_active = 1
                    AND (cp.expires_at IS NULL OR cp.expires_at > NOW())
                    AND p.status NOT IN ('cancelled', 'archived')
                    ORDER BY cp.created_at DESC, p.title ASC
                ");
                $legacyStmt->execute([$clientUserId]);
                $legacyProjects = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Merge: add legacy projects not already in the list
                $existingIds = array_column($projects, 'id');
                foreach ($legacyProjects as $lp) {
                    if (!in_array($lp['id'], $existingIds)) {
                        $projects[] = $lp;
                        $existingIds[] = $lp['id'];
                    }
                }
            } catch (Exception $legacyEx) {
                // client_permissions table may not have expected columns - ignore
                error_log('ClientAccessControlManager legacy permissions lookup error: ' . $legacyEx->getMessage());
            }

            // Include projects created by this user even if assignment row is missing.
            try {
                $creatorStmt = $this->db->prepare("
                    SELECT
                        p.id,
                        p.po_number,
                        p.project_code,
                        p.title,
                        p.description,
                        p.project_type,
                        p.priority,
                        p.status,
                        p.created_at,
                        p.completed_at,
                        p.client_id,
                        c.name as client_name,
                        p.created_at as assigned_at,
                        NULL as expires_at,
                        'Auto-visible for creator' as assignment_notes,
                        creator.full_name as assigned_by_name,
                        (SELECT COUNT(*) FROM issues i
                         WHERE i.project_id = p.id AND i.client_ready = 1) as client_ready_issues_count,
                        (SELECT COUNT(*) FROM issues i
                         WHERE i.project_id = p.id) as total_issues_count
                    FROM projects p
                    LEFT JOIN clients c ON p.client_id = c.id
                    LEFT JOIN users creator ON p.created_by = creator.id
                    WHERE p.created_by = ?
                      AND p.status NOT IN ('cancelled', 'archived')
                    ORDER BY p.created_at DESC, p.title ASC
                ");
                $creatorStmt->execute([$clientUserId]);
                $creatorProjects = $creatorStmt->fetchAll(PDO::FETCH_ASSOC);

                $existingIds = array_column($projects, 'id');
                foreach ($creatorProjects as $creatorProject) {
                    if (!in_array($creatorProject['id'], $existingIds)) {
                        $projects[] = $creatorProject;
                        $existingIds[] = $creatorProject['id'];
                    }
                }
            } catch (Exception $creatorEx) {
                error_log('ClientAccessControlManager creator projects lookup error: ' . $creatorEx->getMessage());
            }

            $visibleCountByProject = [];
            $projectIdsForCounts = array_values(array_unique(array_filter(array_map('intval', array_column($projects, 'id')))));
            if (!empty($projectIdsForCounts)) {
                $visibleRecords = getClientVisibleIssueRecords($this->db, $projectIdsForCounts, [
                    'order_by' => 'i.created_at DESC, i.id DESC',
                ]);
                foreach ($visibleRecords as $record) {
                    $visibleProjectId = (int)($record['project_id'] ?? (($record['issue']['project_id'] ?? 0)));
                    if ($visibleProjectId > 0) {
                        $visibleCountByProject[$visibleProjectId] = ($visibleCountByProject[$visibleProjectId] ?? 0) + 1;
                    }
                }
            }
            
            // Process and enrich project data
            $enrichedProjects = [];
            foreach ($projects as $project) {
                $enrichedProjects[] = [
                    'id' => (int)$project['id'],
                    'po_number' => $project['po_number'],
                    'project_code' => $project['project_code'],
                    'title' => $project['title'],
                    'description' => $project['description'],
                    'project_type' => $project['project_type'],
                    'priority' => $project['priority'],
                    'status' => $project['status'],
                    'client_id' => (int)$project['client_id'],
                    'client_name' => $project['client_name'],
                    'assigned_at' => $project['assigned_at'],
                    'expires_at' => $project['expires_at'],
                    'assignment_notes' => $project['assignment_notes'],
                    'assigned_by_name' => $project['assigned_by_name'],
                    'client_ready_issues_count' => (int)($visibleCountByProject[(int)$project['id']] ?? $project['client_ready_issues_count']),
                    'total_issues_count' => (int)$project['total_issues_count'],
                    'created_at' => $project['created_at'],
                    'completed_at' => $project['completed_at']
                ];
            }
            
            // Cache result for 10 minutes
            if ($this->redis->isAvailable()) {
                $this->redis->set($cacheKey, $enrichedProjects, 600);
            }
            
            return $enrichedProjects;
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager getAssignedProjects error: ' . $e->getMessage());
            return [];
        }
    }

    public function getAssignedProject($clientUserId, $projectId) {
        foreach ($this->getAssignedProjects($clientUserId) as $project) {
            if ((int) ($project['id'] ?? 0) === (int) $projectId) {
                return $project;
            }
        }

        return null;
    }

    public function getCanonicalProjectIdentifier($clientUserId, $projectId) {
        $project = $this->getAssignedProject($clientUserId, $projectId);

        if (!$project) {
            return null;
        }

        return getClientProjectRouteKey(
            (int) $project['id'],
            (string) ($project['title'] ?? ''),
            (string) ($project['project_code'] ?? '')
        );
    }

    public function resolveProjectIdentifier($clientUserId, $identifier) {
        $identifier = trim((string) $identifier);

        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier)) {
            $projectId = (int) $identifier;
            return $this->hasProjectAccess($clientUserId, $projectId) ? $projectId : null;
        }

        $slugMatches = [];

        foreach ($this->getAssignedProjects($clientUserId) as $project) {
            $projectId = (int) ($project['id'] ?? 0);
            if ($projectId <= 0) {
                continue;
            }

            $canonicalIdentifier = getClientProjectRouteKey(
                $projectId,
                (string) ($project['title'] ?? ''),
                (string) ($project['project_code'] ?? '')
            );

            if (hash_equals($canonicalIdentifier, $identifier) || hash_equals(getClientProjectRouteToken($projectId), $identifier)) {
                return $projectId;
            }

            $projectSlug = slugifyPathSegment((string) (($project['project_code'] ?? '') !== '' ? $project['project_code'] : ($project['title'] ?? '')));
            if ($projectSlug === $identifier) {
                $slugMatches[] = $projectId;
            }
        }

        $slugMatches = array_values(array_unique($slugMatches));
        if (count($slugMatches) === 1) {
            return $slugMatches[0];
        }

        return null;
    }
    
    /**
     * Filter issues to show only client-ready ones
     * @param array $issues Array of issues to filter
     * @return array Filtered array containing only client-ready issues
     */
    public function filterClientReadyIssues($issues) {
        if (!is_array($issues)) {
            return [];
        }
        
        return array_filter($issues, function($issue) {
            // Handle both array and object formats
            if (is_array($issue)) {
                return isset($issue['client_ready']) && $issue['client_ready'] == 1;
            } elseif (is_object($issue)) {
                return isset($issue->client_ready) && $issue->client_ready == 1;
            }
            return false;
        });
    }
    
    /**
     * Get client-ready issues for specific projects
     * @param int $clientUserId Client user ID
     * @param array $projectIds Array of project IDs (optional, gets all if empty)
     * @return array Array of client-ready issues
     */
    public function getClientReadyIssues($clientUserId, $projectIds = []) {
        // Validate input
        if (!$clientUserId) {
            return [];
        }
        
        try {
            // Get assigned projects if no specific projects provided
            if (empty($projectIds)) {
                $assignedProjects = $this->getAssignedProjects($clientUserId);
                $projectIds = array_column($assignedProjects, 'id');
            } else {
                // Validate client has access to all requested projects
                foreach ($projectIds as $projectId) {
                    if (!$this->hasProjectAccess($clientUserId, $projectId)) {
                        error_log("Client $clientUserId attempted to access unauthorized project $projectId");
                        return [];
                    }
                }
            }
            
            if (empty($projectIds)) {
                return [];
            }

            $records = getClientVisibleIssueRecords($this->db, $projectIds, [
                'order_by' => 'i.created_at DESC, i.id DESC',
            ]);
            $issues = [];
            foreach ($records as $record) {
                $issue = $record['issue'] ?? [];
                if (empty($issue)) {
                    continue;
                }

                $projectStmt = $this->db->prepare("SELECT title, project_code FROM projects WHERE id = ? LIMIT 1");
                $projectStmt->execute([(int) ($issue['project_id'] ?? 0)]);
                $projectData = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $commentCount = 0;
                if (($record['source'] ?? 'live') === 'snapshot' && !empty($record['published_at'])) {
                    $commentStmt = $this->db->prepare("SELECT COUNT(*) FROM issue_comments WHERE issue_id = ? AND comment_type = 'regression' AND created_at <= ?");
                    $commentStmt->execute([(int) ($issue['id'] ?? 0), (string) $record['published_at']]);
                    $commentCount = (int) $commentStmt->fetchColumn();
                } else {
                    $commentStmt = $this->db->prepare("SELECT COUNT(*) FROM issue_comments WHERE issue_id = ? AND comment_type = 'regression'");
                    $commentStmt->execute([(int) ($issue['id'] ?? 0)]);
                    $commentCount = (int) $commentStmt->fetchColumn();
                }

                $issues[] = [
                    'id' => (int) ($issue['id'] ?? 0),
                    'project_id' => (int) ($issue['project_id'] ?? 0),
                    'issue_key' => (string) ($issue['issue_key'] ?? ''),
                    'title' => (string) ($issue['title'] ?? ''),
                    'description' => (string) ($issue['description'] ?? ''),
                    'severity' => (string) ($issue['severity'] ?? ''),
                    'created_at' => (string) ($issue['created_at'] ?? ''),
                    'updated_at' => (string) ($issue['updated_at'] ?? ''),
                    'resolved_at' => $issue['resolved_at'] ?? null,
                    'project_title' => (string) ($projectData['title'] ?? ''),
                    'project_code' => (string) ($projectData['project_code'] ?? ''),
                    'status_name' => (string) ($issue['status_name'] ?? ''),
                    'status_color' => (string) ($issue['status_color'] ?? ''),
                    'priority_name' => (string) ($issue['priority_name'] ?? ''),
                    'reporter_name' => (string) ($issue['reporter_name'] ?? ''),
                    'metadata' => $record['meta'] ?? [],
                    'comment_count' => $commentCount,
                    'client_visible_source' => (string) ($record['source'] ?? 'live'),
                    'client_visible_published_at' => (string) ($record['published_at'] ?? ''),
                ];
            }

            return $issues;
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager getClientReadyIssues error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if client can access specific issue
     * @param int $clientUserId Client user ID
     * @param int $issueId Issue ID to check
     * @return bool True if client can access the issue
     */
    public function canAccessIssue($clientUserId, $issueId) {
        if (!$clientUserId || !$issueId) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("SELECT i.project_id, i.client_ready FROM issues i WHERE i.id = ?");
            $stmt->execute([$issueId]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$issue) {
                return false;
            }

            if ((int) ($issue['client_ready'] ?? 0) !== 1 && !isIssueVisibleToClientThroughSnapshot($this->db, (int) $issueId, (int) ($issue['project_id'] ?? 0))) {
                return false;
            }
            
            return $this->hasProjectAccess($clientUserId, $issue['project_id']);
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager canAccessIssue error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalidate cache for client access
     * @param int $clientUserId Client user ID
     * @param int $projectId Optional specific project ID
     */
    public function invalidateCache($clientUserId, $projectId = null) {
        if (!$this->redis->isAvailable()) {
            return;
        }
        
        try {
            // Clear project list cache
            $this->redis->delete("client_projects_{$clientUserId}");
            
            if ($projectId) {
                // Clear specific project access cache
                $this->redis->delete("client_access_{$clientUserId}_{$projectId}");
            } else {
                // Clear all project access cache for this client
                $keys = $this->redis->keys("client_access_{$clientUserId}_*");
                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        $this->redis->delete($key);
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager invalidateCache error: ' . $e->getMessage());
        }
    }
    
    /**
     * Log client access attempt
     * @param int $clientUserId Client user ID
     * @param string $resourceType Type of resource accessed
     * @param int $resourceId ID of resource accessed
     * @param bool $success Whether access was granted
     * @param string $details Additional details
     */
    public function logAccess($clientUserId, $resourceType, $resourceId, $success = true, $details = '') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, resource_type, resource_id, action_details, 
                 ip_address, user_agent, success, created_at)
                VALUES (?, 'access_check', ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $actionDetails = json_encode([
                'details' => $details,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $clientUserId,
                $resourceType,
                $resourceId,
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $success ? 1 : 0
            ]);
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager logAccess error: ' . $e->getMessage());
        }
    }
    
    /**
     * Parse metadata string into associative array
     * @param string $metadataString Pipe-separated metadata string
     * @return array Parsed metadata
     */
    private function parseMetadata($metadataString) {
        $metadata = [];
        
        if (empty($metadataString)) {
            return $metadata;
        }
        
        $pairs = explode('|', $metadataString);
        foreach ($pairs as $pair) {
            if (strpos($pair, ':') !== false) {
                list($key, $value) = explode(':', $pair, 2);
                $metadata[trim($key)] = trim($value);
            }
        }
        
        return $metadata;
    }
    
    /**
     * Get project statistics for client dashboard
     * @param int $clientUserId Client user ID
     * @return array Project statistics
     */
    public function getProjectStatistics($clientUserId, $projectId = null) {
        $assignedProjects = $this->getAssignedProjects($clientUserId);
        
        // If specific project requested, filter the list
        if ($projectId !== null) {
            $assignedProjects = array_filter($assignedProjects, function($p) use ($projectId) {
                return $p['id'] == $projectId;
            });
        }
        
        if (empty($assignedProjects)) {
            return [
                'total_projects' => 0,
                'total_issues' => 0,
                'client_ready_issues' => 0,
                'open_issues' => 0,
                'resolved_issues' => 0,
                'compliance_score' => 0,
                'projects_by_status' => [],
                'issues_by_severity' => []
            ];
        }
        
        $projectIds = array_column($assignedProjects, 'id');
        
        try {
            // Get project status distribution
            $projectsByStatus = [];
            foreach ($assignedProjects as $project) {
                $status = $project['status'] ?? 'Unknown';
                if (is_array($status)) {
                    $status = 'Multiple';
                }
                $projectsByStatus[(string)$status] = ($projectsByStatus[(string)$status] ?? 0) + 1;
            }
            
            $visibleRecords = getClientVisibleIssueRecords($this->db, $projectIds, [
                'order_by' => 'i.created_at DESC, i.id DESC',
            ]);
            $issuesBySeverity = [];
            $clientReadyIssues = 0;
            $openIssues = 0;
            $resolvedIssues = 0;
            foreach ($visibleRecords as $record) {
                $issue = $record['issue'] ?? [];
                if (empty($issue)) {
                    continue;
                }
                $clientReadyIssues++;
                $severity = (string) ($issue['severity'] ?? '');
                if ($severity !== '') {
                    $issuesBySeverity[$severity] = ($issuesBySeverity[$severity] ?? 0) + 1;
                }

                $statusName = strtolower(trim((string) ($issue['status_name'] ?? '')));
                if (in_array($statusName, ['open', 'in progress', 'reopened', 'in_progress'], true)) {
                    $openIssues++;
                }
                if (in_array($statusName, ['resolved', 'closed', 'fixed'], true)) {
                    $resolvedIssues++;
                }
            }

            $complianceResolver = new ClientComplianceScoreResolver($this);
            $complianceScore = $complianceResolver->resolveForClientUser((int) $clientUserId, $projectIds);
            
            return [
                'total_projects' => count($assignedProjects),
                'total_issues' => array_sum(array_column($assignedProjects, 'total_issues_count')),
                'client_ready_issues' => $clientReadyIssues,
                'open_issues' => $openIssues,
                'resolved_issues' => $resolvedIssues,
                'compliance_score' => $complianceScore,
                'projects_by_status' => $projectsByStatus,
                'issues_by_severity' => $issuesBySeverity
            ];
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager getProjectStatistics error: ' . $e->getMessage());
            return [
                'total_projects' => count($assignedProjects),
                'total_issues' => 0,
                'client_ready_issues' => 0,
                'open_issues' => 0,
                'resolved_issues' => 0,
                'compliance_score' => 0,
                'projects_by_status' => [],
                'issues_by_severity' => []
            ];
        }
    }
}