<?php
/**
 * ClientProjectAssignment Model
 * Represents the relationship between client users and assigned projects
 * Handles validation rules, relationships, and active assignment queries
 */

require_once __DIR__ . '/../../config/database.php';

class ClientProjectAssignment {
    private $db;
    
    // Properties
    public $id;
    public $client_user_id;
    public $project_id;
    public $assigned_by_admin_id;
    public $assigned_at;
    public $expires_at;
    public $is_active;
    public $notes;
    public $created_at;
    public $updated_at;
    
    // Related objects
    public $client_user;
    public $project;
    public $assigned_by_admin;
    
    public function __construct($data = null) {
        $this->db = Database::getInstance();
        
        if ($data) {
            $this->populate($data);
        }
    }
    
    /**
     * Populate model with data
     * @param array $data Data array
     */
    public function populate($data) {
        $this->id = $data['id'] ?? null;
        $this->client_user_id = $data['client_user_id'] ?? null;
        $this->project_id = $data['project_id'] ?? null;
        $this->assigned_by_admin_id = $data['assigned_by_admin_id'] ?? null;
        $this->assigned_at = $data['assigned_at'] ?? null;
        $this->expires_at = $data['expires_at'] ?? null;
        $this->is_active = $data['is_active'] ?? 1;
        $this->notes = $data['notes'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
        $this->updated_at = $data['updated_at'] ?? null;
    }
    
    /**
     * Validate assignment data
     * @return array Validation result with success status and errors
     */
    public function validate() {
        $errors = [];
        
        // Required fields
        if (!$this->client_user_id) {
            $errors[] = 'Client user ID is required';
        }
        
        if (!$this->project_id) {
            $errors[] = 'Project ID is required';
        }
        
        if (!$this->assigned_by_admin_id) {
            $errors[] = 'Admin user ID is required';
        }
        
        // Validate client user exists and has client role
        if ($this->client_user_id && !$this->validateClientUser($this->client_user_id)) {
            $errors[] = 'Invalid client user or user does not have client role';
        }
        
        // Validate project exists and is not archived
        if ($this->project_id && !$this->validateProject($this->project_id)) {
            $errors[] = 'Invalid project or project is archived';
        }
        
        // Validate admin user exists and has admin role
        if ($this->assigned_by_admin_id && !$this->validateAdminUser($this->assigned_by_admin_id)) {
            $errors[] = 'Invalid admin user or user does not have admin role';
        }
        
        // Validate expiration date if provided
        if ($this->expires_at && strtotime($this->expires_at) <= time()) {
            $errors[] = 'Expiration date must be in the future';
        }
        
        // Check for duplicate active assignment
        if ($this->client_user_id && $this->project_id && !$this->id) {
            if ($this->hasActiveAssignment($this->client_user_id, $this->project_id)) {
                $errors[] = 'Client already has an active assignment for this project';
            }
        }
        
        return [
            'success' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Save assignment to database
     * @return array Result with success status and details
     */
    public function save() {
        $validation = $this->validate();
        if (!$validation['success']) {
            return [
                'success' => false,
                'errors' => $validation['errors']
            ];
        }
        
        try {
            if ($this->id) {
                // Update existing assignment
                $stmt = $this->db->prepare("
                    UPDATE client_project_assignments 
                    SET client_user_id = ?, 
                        project_id = ?, 
                        assigned_by_admin_id = ?,
                        expires_at = ?,
                        is_active = ?,
                        notes = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $result = $stmt->execute([
                    $this->client_user_id,
                    $this->project_id,
                    $this->assigned_by_admin_id,
                    $this->expires_at,
                    $this->is_active,
                    $this->notes,
                    $this->id
                ]);
            } else {
                // Create new assignment
                $stmt = $this->db->prepare("
                    INSERT INTO client_project_assignments 
                    (client_user_id, project_id, assigned_by_admin_id, assigned_at, expires_at, is_active, notes, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), ?, ?, ?, NOW(), NOW())
                ");
                
                $result = $stmt->execute([
                    $this->client_user_id,
                    $this->project_id,
                    $this->assigned_by_admin_id,
                    $this->expires_at,
                    $this->is_active,
                    $this->notes
                ]);
                
                if ($result) {
                    $this->id = $this->db->lastInsertId();
                    $this->assigned_at = date('Y-m-d H:i:s');
                    $this->created_at = date('Y-m-d H:i:s');
                    $this->updated_at = date('Y-m-d H:i:s');
                }
            }
            
            return [
                'success' => $result,
                'id' => $this->id,
                'message' => $this->id ? 'Assignment updated successfully' : 'Assignment created successfully'
            ];
            
        } catch (Exception $e) {
            error_log('ClientProjectAssignment save error: ' . $e->getMessage());
            return [
                'success' => false,
                'errors' => ['Database error occurred while saving assignment']
            ];
        }
    }
    
    /**
     * Find assignment by ID
     * @param int $id Assignment ID
     * @return ClientProjectAssignment|null
     */
    public static function find($id) {
        if (!$id) return null;
        
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT cpa.*, 
                       cu.username as client_username, cu.email as client_email, cu.full_name as client_name,
                       p.title as project_title, p.project_code, p.status as project_status,
                       admin.full_name as admin_name
                FROM client_project_assignments cpa
                LEFT JOIN users cu ON cpa.client_user_id = cu.id
                LEFT JOIN projects p ON cpa.project_id = p.id
                LEFT JOIN users admin ON cpa.assigned_by_admin_id = admin.id
                WHERE cpa.id = ?
            ");
            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($data) {
                $assignment = new self($data);
                $assignment->loadRelationships($data);
                return $assignment;
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('ClientProjectAssignment find error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get active assignments for a client
     * @param int $clientUserId Client user ID
     * @return array Array of ClientProjectAssignment objects
     */
    public static function getActiveAssignments($clientUserId) {
        if (!$clientUserId) return [];
        
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT cpa.*, 
                       p.title as project_title, p.project_code, p.status as project_status,
                       admin.full_name as admin_name
                FROM client_project_assignments cpa
                INNER JOIN projects p ON cpa.project_id = p.id
                LEFT JOIN users admin ON cpa.assigned_by_admin_id = admin.id
                WHERE cpa.client_user_id = ? 
                AND cpa.is_active = 1 
                AND (cpa.expires_at IS NULL OR cpa.expires_at > NOW())
                AND p.status NOT IN ('cancelled', 'archived')
                ORDER BY cpa.assigned_at DESC
            ");
            $stmt->execute([$clientUserId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $assignments = [];
            foreach ($results as $data) {
                $assignment = new self($data);
                $assignment->loadRelationships($data);
                $assignments[] = $assignment;
            }
            
            return $assignments;
            
        } catch (Exception $e) {
            error_log('ClientProjectAssignment getActiveAssignments error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get assignments for a project
     * @param int $projectId Project ID
     * @param bool $activeOnly Whether to return only active assignments
     * @return array Array of ClientProjectAssignment objects
     */
    public static function getProjectAssignments($projectId, $activeOnly = true) {
        if (!$projectId) return [];
        
        try {
            $db = Database::getInstance();
            $whereClause = $activeOnly ? 
                "WHERE cpa.project_id = ? AND cpa.is_active = 1 AND (cpa.expires_at IS NULL OR cpa.expires_at > NOW())" :
                "WHERE cpa.project_id = ?";
            
            $stmt = $db->prepare("
                SELECT cpa.*, 
                       cu.username as client_username, cu.email as client_email, cu.full_name as client_name,
                       admin.full_name as admin_name
                FROM client_project_assignments cpa
                INNER JOIN users cu ON cpa.client_user_id = cu.id
                LEFT JOIN users admin ON cpa.assigned_by_admin_id = admin.id
                $whereClause
                ORDER BY cpa.is_active DESC, cpa.assigned_at DESC
            ");
            $stmt->execute([$projectId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $assignments = [];
            foreach ($results as $data) {
                $assignment = new self($data);
                $assignment->loadRelationships($data);
                $assignments[] = $assignment;
            }
            
            return $assignments;
            
        } catch (Exception $e) {
            error_log('ClientProjectAssignment getProjectAssignments error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if assignment is expired
     * @return bool True if expired
     */
    public function isExpired() {
        return $this->expires_at && strtotime($this->expires_at) <= time();
    }
    
    /**
     * Check if assignment is currently active and valid
     * @return bool True if active and valid
     */
    public function isActiveAndValid() {
        return $this->is_active && !$this->isExpired();
    }
    
    /**
     * Deactivate assignment
     * @param string $reason Reason for deactivation
     * @return array Result with success status
     */
    public function deactivate($reason = '') {
        try {
            $this->is_active = 0;
            if ($reason) {
                $this->notes = ($this->notes ? $this->notes . "\n" : '') . 
                              "Deactivated on " . date('Y-m-d H:i:s') . ". Reason: " . $reason;
            }
            
            return $this->save();
            
        } catch (Exception $e) {
            error_log('ClientProjectAssignment deactivate error: ' . $e->getMessage());
            return [
                'success' => false,
                'errors' => ['Error deactivating assignment']
            ];
        }
    }
    
    /**
     * Handle expired assignments (cleanup method)
     * @return int Number of assignments processed
     */
    public static function handleExpiredAssignments() {
        try {
            $db = Database::getInstance();
            
            // Deactivate expired assignments
            $stmt = $db->prepare("
                UPDATE client_project_assignments 
                SET is_active = 0,
                    notes = CONCAT(COALESCE(notes, ''), '\nAuto-deactivated on ', NOW(), ' due to expiration'),
                    updated_at = NOW()
                WHERE is_active = 1 
                AND expires_at IS NOT NULL 
                AND expires_at <= NOW()
            ");
            $stmt->execute();
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            error_log('ClientProjectAssignment handleExpiredAssignments error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Validate client user
     */
    private function validateClientUser($clientUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM users 
                WHERE id = ? AND role = 'client' AND is_active = 1
            ");
            $stmt->execute([$clientUserId]);
            return $stmt->fetchColumn() !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Validate project
     */
    private function validateProject($projectId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM projects 
                WHERE id = ? AND status NOT IN ('cancelled', 'archived')
            ");
            $stmt->execute([$projectId]);
            return $stmt->fetchColumn() !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Validate admin user
     */
    private function validateAdminUser($adminUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM users 
                WHERE id = ? AND role IN ('admin') AND is_active = 1
            ");
            $stmt->execute([$adminUserId]);
            return $stmt->fetchColumn() !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check for existing active assignment
     */
    private function hasActiveAssignment($clientUserId, $projectId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM client_project_assignments 
                WHERE client_user_id = ? AND project_id = ? AND is_active = 1
            ");
            $stmt->execute([$clientUserId, $projectId]);
            return $stmt->fetchColumn() !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Load relationship data
     */
    private function loadRelationships($data) {
        // Load client user data
        if (isset($data['client_username'])) {
            $this->client_user = [
                'id' => $this->client_user_id,
                'username' => $data['client_username'],
                'email' => $data['client_email'],
                'full_name' => $data['client_name']
            ];
        }
        
        // Load project data
        if (isset($data['project_title'])) {
            $this->project = [
                'id' => $this->project_id,
                'title' => $data['project_title'],
                'project_code' => $data['project_code'],
                'status' => $data['project_status']
            ];
        }
        
        // Load admin data
        if (isset($data['admin_name'])) {
            $this->assigned_by_admin = [
                'id' => $this->assigned_by_admin_id,
                'full_name' => $data['admin_name']
            ];
        }
    }
    
    /**
     * Convert to array for JSON serialization
     * @return array
     */
    public function toArray() {
        return [
            'id' => $this->id,
            'client_user_id' => $this->client_user_id,
            'project_id' => $this->project_id,
            'assigned_by_admin_id' => $this->assigned_by_admin_id,
            'assigned_at' => $this->assigned_at,
            'expires_at' => $this->expires_at,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'client_user' => $this->client_user,
            'project' => $this->project,
            'assigned_by_admin' => $this->assigned_by_admin,
            'is_expired' => $this->isExpired(),
            'is_active_and_valid' => $this->isActiveAndValid()
        ];
    }
}