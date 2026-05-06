<?php
/**
 * ProjectAssignmentManager
 * Manages admin assignment of projects to client users
 * Implements assignProjectsToClient() with admin validation and revokeProjectAccess() with audit logging
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/redis.php';
require_once __DIR__ . '/ClientAccessControlManager.php';

class ProjectAssignmentManager {
    private $db;
    private $redis;
    private $accessControl;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->redis = RedisConfig::getInstance();
        $this->accessControl = new ClientAccessControlManager();
    }
    
    /**
     * Assign projects to a client user
     * @param int $clientUserId Client user ID
     * @param array $projectIds Array of project IDs to assign
     * @param int $adminUserId Admin user ID making the assignment
     * @param array $options Optional assignment options (expires_at, notes)
     * @return array Result with success status and details
     */
    public function assignProjectsToClient($clientUserId, $projectIds, $adminUserId, $options = []) {
        // Validate inputs
        if (!$clientUserId || !is_array($projectIds) || empty($projectIds) || !$adminUserId) {
            return [
                'success' => false,
                'error' => 'Invalid parameters provided',
                'assigned_count' => 0
            ];
        }
        
        try {
            // Validate admin permissions
            if (!$this->hasAdminRole($adminUserId)) {
                $this->logAssignmentAttempt($adminUserId, $clientUserId, $projectIds, false, 'Unauthorized admin access');
                return [
                    'success' => false,
                    'error' => 'Unauthorized: Admin role required',
                    'assigned_count' => 0
                ];
            }
            
            // Validate client user exists and has client role
            $clientUser = $this->getClientUser($clientUserId);
            if (!$clientUser) {
                return [
                    'success' => false,
                    'error' => 'Invalid client user or user does not have client role',
                    'assigned_count' => 0
                ];
            }
            
            // Validate projects exist and admin can assign them
            $validProjects = $this->validateProjectsForAssignment($projectIds, $adminUserId);
            if (empty($validProjects)) {
                return [
                    'success' => false,
                    'error' => 'No valid projects found for assignment',
                    'assigned_count' => 0
                ];
            }
            
            // Begin transaction
            $this->db->beginTransaction();
            
            $assignedCount = 0;
            $skippedCount = 0;
            $errors = [];
            
            foreach ($validProjects as $project) {
                $result = $this->assignSingleProject($clientUserId, $project['id'], $adminUserId, $options);
                
                if ($result['success']) {
                    $assignedCount++;
                } else {
                    if ($result['skipped']) {
                        $skippedCount++;
                    } else {
                        $errors[] = "Project {$project['title']}: {$result['error']}";
                    }
                }
            }
            
            // Commit transaction if any assignments were successful
            if ($assignedCount > 0) {
                $this->db->commit();
                
                // Clear cache
                $this->accessControl->invalidateCache($clientUserId);
                
                // Send notification email
                $this->sendAssignmentNotification($clientUserId, $validProjects, $adminUserId);
                
                // Log successful assignment
                $this->logAssignmentAttempt($adminUserId, $clientUserId, array_column($validProjects, 'id'), true, 
                    "Assigned $assignedCount projects, skipped $skippedCount");
                
                return [
                    'success' => true,
                    'message' => "Successfully assigned $assignedCount project(s)" . 
                                ($skippedCount > 0 ? ", skipped $skippedCount already assigned" : ''),
                    'assigned_count' => $assignedCount,
                    'skipped_count' => $skippedCount,
                    'errors' => $errors
                ];
            } else {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'error' => 'No projects were assigned. ' . implode('; ', $errors),
                    'assigned_count' => 0
                ];
            }
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            
            error_log('ProjectAssignmentManager assignProjectsToClient error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'System error during assignment',
                'assigned_count' => 0
            ];
        }
    }
    
    /**
     * Revoke project access from client user
     * @param int $clientUserId Client user ID
     * @param int $projectId Project ID to revoke
     * @param int $adminUserId Admin user ID making the revocation
     * @param string $reason Reason for revocation
     * @return array Result with success status and details
     */
    public function revokeProjectAccess($clientUserId, $projectId, $adminUserId, $reason = '') {
        // Validate inputs
        if (!$clientUserId || !$projectId || !$adminUserId) {
            return [
                'success' => false,
                'error' => 'Invalid parameters provided'
            ];
        }
        
        try {
            // Validate admin permissions
            if (!$this->hasAdminRole($adminUserId)) {
                return [
                    'success' => false,
                    'error' => 'Unauthorized: Admin role required'
                ];
            }
            
            // Check if assignment exists
            $stmt = $this->db->prepare("
                SELECT cpa.id, p.title as project_title
                FROM client_project_assignments cpa
                INNER JOIN projects p ON cpa.project_id = p.id
                WHERE cpa.client_user_id = ? AND cpa.project_id = ? AND cpa.is_active = 1
            ");
            $stmt->execute([$clientUserId, $projectId]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$assignment) {
                return [
                    'success' => false,
                    'error' => 'No active assignment found for this client and project'
                ];
            }
            
            // Deactivate assignment
            $stmt = $this->db->prepare("
                UPDATE client_project_assignments 
                SET is_active = 0, 
                    notes = CONCAT(COALESCE(notes, ''), '\nRevoked on ', NOW(), ' by admin ID ', ?, 
                                  CASE WHEN ? != '' THEN CONCAT('. Reason: ', ?) ELSE '' END),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$adminUserId, $reason, $reason, $assignment['id']]);
            
            // Clear cache
            $this->accessControl->invalidateCache($clientUserId, $projectId);
            
            // Send notification email
            $this->sendRevocationNotification($clientUserId, $assignment['project_title'], $adminUserId, $reason);
            
            // Log revocation
            $this->logRevocation($adminUserId, $clientUserId, $projectId, $reason);
            
            return [
                'success' => true,
                'message' => "Project access revoked successfully for {$assignment['project_title']}"
            ];
            
        } catch (Exception $e) {
            error_log('ProjectAssignmentManager revokeProjectAccess error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'System error during revocation'
            ];
        }
    }
    
    /**
     * Get client assignments for a specific client
     * @param int $clientUserId Client user ID
     * @return array Array of assignments with details
     */
    public function getClientAssignments($clientUserId) {
        if (!$clientUserId) {
            return [];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    cpa.id,
                    cpa.project_id,
                    cpa.assigned_at,
                    cpa.expires_at,
                    cpa.is_active,
                    cpa.notes,
                    p.title as project_title,
                    p.project_code,
                    p.po_number,
                    p.status as project_status,
                    admin.full_name as assigned_by_name
                FROM client_project_assignments cpa
                INNER JOIN projects p ON cpa.project_id = p.id
                LEFT JOIN users admin ON cpa.assigned_by_admin_id = admin.id
                WHERE cpa.client_user_id = ?
                ORDER BY cpa.is_active DESC, cpa.assigned_at DESC
            ");
            $stmt->execute([$clientUserId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('ProjectAssignmentManager getClientAssignments error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get clients assigned to a specific project
     * @param int $projectId Project ID
     * @return array Array of clients with assignment details
     */
    public function getProjectClients($projectId) {
        if (!$projectId) {
            return [];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    cpa.id,
                    cpa.client_user_id,
                    cpa.assigned_at,
                    cpa.expires_at,
                    cpa.is_active,
                    cpa.notes,
                    u.username,
                    u.email,
                    u.full_name,
                    admin.full_name as assigned_by_name
                FROM client_project_assignments cpa
                INNER JOIN users u ON cpa.client_user_id = u.id
                LEFT JOIN users admin ON cpa.assigned_by_admin_id = admin.id
                WHERE cpa.project_id = ?
                ORDER BY cpa.is_active DESC, cpa.assigned_at DESC
            ");
            $stmt->execute([$projectId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('ProjectAssignmentManager getProjectClients error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Bulk assign projects to multiple clients
     * @param array $assignments Array of [client_user_id => [project_ids], ...]
     * @param int $adminUserId Admin user ID making the assignments
     * @param array $options Optional assignment options
     * @return array Result with success status and details
     */
    public function bulkAssignProjects($assignments, $adminUserId, $options = []) {
        if (!is_array($assignments) || empty($assignments) || !$adminUserId) {
            return [
                'success' => false,
                'error' => 'Invalid parameters provided',
                'results' => []
            ];
        }
        
        $results = [];
        $totalAssigned = 0;
        
        foreach ($assignments as $clientUserId => $projectIds) {
            $result = $this->assignProjectsToClient($clientUserId, $projectIds, $adminUserId, $options);
            $results[$clientUserId] = $result;
            $totalAssigned += $result['assigned_count'];
        }
        
        return [
            'success' => $totalAssigned > 0,
            'message' => "Bulk assignment completed. Total assigned: $totalAssigned",
            'total_assigned' => $totalAssigned,
            'results' => $results
        ];
    }
    
    /**
     * Check if user has admin role
     */
    private function hasAdminRole($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM users 
                WHERE id = ? AND role IN ('admin') AND is_active = 1
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchColumn() !== false;
        } catch (Exception $e) {
            error_log('ProjectAssignmentManager hasAdminRole error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get client user details
     */
    private function getClientUser($clientUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, email, full_name, is_active
                FROM users 
                WHERE id = ? AND role = 'client' AND is_active = 1
            ");
            $stmt->execute([$clientUserId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('ProjectAssignmentManager getClientUser error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate projects for assignment
     */
    private function validateProjectsForAssignment($projectIds, $adminUserId) {
        try {
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $stmt = $this->db->prepare("
                SELECT id, title, project_code, status
                FROM projects 
                WHERE id IN ($placeholders) 
                AND status NOT IN ('cancelled', 'archived')
            ");
            $stmt->execute($projectIds);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('ProjectAssignmentManager validateProjectsForAssignment error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Assign single project to client
     */
    private function assignSingleProject($clientUserId, $projectId, $adminUserId, $options) {
        try {
            // Check if assignment already exists
            $stmt = $this->db->prepare("
                SELECT id, is_active FROM client_project_assignments 
                WHERE client_user_id = ? AND project_id = ?
            ");
            $stmt->execute([$clientUserId, $projectId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                if ($existing['is_active']) {
                    return ['success' => false, 'skipped' => true, 'error' => 'Already assigned'];
                } else {
                    // Reactivate existing assignment
                    $stmt = $this->db->prepare("
                        UPDATE client_project_assignments 
                        SET is_active = 1, 
                            assigned_by_admin_id = ?,
                            assigned_at = NOW(),
                            expires_at = ?,
                            notes = CONCAT(COALESCE(notes, ''), '\nReactivated on ', NOW()),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $adminUserId,
                        $options['expires_at'] ?? null,
                        $existing['id']
                    ]);
                    return ['success' => true, 'skipped' => false];
                }
            } else {
                // Create new assignment
                $stmt = $this->db->prepare("
                    INSERT INTO client_project_assignments 
                    (client_user_id, project_id, assigned_by_admin_id, expires_at, notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $clientUserId,
                    $projectId,
                    $adminUserId,
                    $options['expires_at'] ?? null,
                    $options['notes'] ?? null
                ]);
                return ['success' => true, 'skipped' => false];
            }
            
        } catch (Exception $e) {
            error_log('ProjectAssignmentManager assignSingleProject error: ' . $e->getMessage());
            return ['success' => false, 'skipped' => false, 'error' => 'Database error'];
        }
    }
    
    /**
     * Send assignment notification email
     */
    private function sendAssignmentNotification($clientUserId, $projects, $adminUserId) {
        try {
            require_once __DIR__ . '/NotificationManager.php';
            $notificationManager = new NotificationManager($this->db);
            
            $success = $notificationManager->sendProjectAssignmentNotification(
                $clientUserId, 
                $projects, 
                $adminUserId
            );
            
            if (!$success) {
                error_log("Failed to send assignment notification to client $clientUserId");
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log("Error sending assignment notification: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send revocation notification email
     */
    private function sendRevocationNotification($clientUserId, $projectTitle, $adminUserId, $reason) {
        try {
            require_once __DIR__ . '/NotificationManager.php';
            $notificationManager = new NotificationManager($this->db);
            
            $success = $notificationManager->sendProjectRevocationNotification(
                $clientUserId, 
                $projectTitle, 
                $adminUserId, 
                $reason
            );
            
            if (!$success) {
                error_log("Failed to send revocation notification to client $clientUserId");
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log("Error sending revocation notification: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log assignment attempt
     */
    private function logAssignmentAttempt($adminUserId, $clientUserId, $projectIds, $success, $details) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, action_details, ip_address, user_agent, success, created_at)
                VALUES (?, 'project_assignment', ?, ?, ?, ?, NOW())
            ");
            
            $actionDetails = json_encode([
                'admin_user_id' => $adminUserId,
                'project_ids' => $projectIds,
                'details' => $details,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $clientUserId,
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $success ? 1 : 0
            ]);
            
        } catch (Exception $e) {
            error_log('ProjectAssignmentManager logAssignmentAttempt error: ' . $e->getMessage());
        }
    }
    
    /**
     * Log revocation
     */
    private function logRevocation($adminUserId, $clientUserId, $projectId, $reason) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, action_details, ip_address, user_agent, success, created_at)
                VALUES (?, 'project_revocation', ?, ?, ?, 1, NOW())
            ");
            
            $actionDetails = json_encode([
                'admin_user_id' => $adminUserId,
                'project_id' => $projectId,
                'reason' => $reason,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $clientUserId,
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log('ProjectAssignmentManager logRevocation error: ' . $e->getMessage());
        }
    }
}