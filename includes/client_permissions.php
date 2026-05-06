<?php
/**
 * Client Permissions Helper Functions
 * Provides functions to check if a user has specific permissions for a client
 */

/**
 * Check if user has permission to create projects (general permission, not project-specific)
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @return bool True if user has permission, false otherwise
 */
function canCreateProject($db, $userId) {
    // Admin and admin always have permission
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin'])) {
        return true;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM client_permissions 
            WHERE user_id = ? 
              AND permission_type = 'create_project' 
              AND is_active = 1
              AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error checking create project permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if user has permission to edit a specific project
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @param int $projectId Project ID
 * @return bool True if user has permission, false otherwise
 */
function canEditProjectById($db, $userId, $projectId) {
    // Admin and admin always have permission
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin'])) {
        return true;
    }
    
    try {
        // Check if user is project lead or creator
        $stmt = $db->prepare("SELECT project_lead_id, created_by FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        
        if ($project && ($project['project_lead_id'] == $userId || $project['created_by'] == $userId)) {
            return true;
        }
        
        // Check project-level permission
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM client_permissions 
            WHERE user_id = ? 
              AND project_id = ? 
              AND permission_type = 'edit_project' 
              AND is_active = 1
              AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$userId, $projectId]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error checking edit project permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all projects for which user has specific permission
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @param string $permissionType Permission type (e.g., 'create_project', 'edit_project', 'view_project')
 * @return array Array of project IDs
 */
function getProjectsWithPermission($db, $userId, $permissionType) {
    // Admin and admin have access to all projects
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin'])) {
        try {
            $stmt = $db->query("SELECT id FROM projects ORDER BY title");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Error getting all projects: " . $e->getMessage());
            return [];
        }
    }
    
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT project_id 
            FROM client_permissions 
            WHERE user_id = ? 
              AND permission_type = ? 
              AND is_active = 1
              AND project_id IS NOT NULL
              AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$userId, $permissionType]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Error getting projects with permission: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if user can edit a specific project based on project permissions
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @param int $projectId Project ID
 * @return bool True if user has permission, false otherwise
 */
function canEditProject($db, $userId, $projectId) {
    // Admin and admin always have permission
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin'])) {
        return true;
    }
    
    try {
        // Get project's lead/creator
        $stmt = $db->prepare("SELECT project_lead_id, created_by FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        
        if (!$project) {
            return false;
        }
        
        // Project lead/creator can always edit their own project
        if ($project['project_lead_id'] == $userId || $project['created_by'] == $userId) {
            return true;
        }
        
        // Check project-level permission
        return canEditProjectById($db, $userId, $projectId);
    } catch (PDOException $e) {
        error_log("Error checking project edit permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if user has any project permissions at all
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @return bool True if user has any project permissions, false otherwise
 */
function hasAnyProjectPermissions($db, $userId) {
    // Admin and admin always have access
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin'])) {
        return true;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM client_permissions 
            WHERE user_id = ? 
              AND is_active = 1
              AND project_id IS NOT NULL
              AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error checking any project permissions: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all clients that have projects user can access
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @return array Array of client IDs
 */
function getClientsWithAccessibleProjects($db, $userId) {
    // Admin and admin have access to all clients
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin'])) {
        try {
            $stmt = $db->query("SELECT id FROM clients ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Error getting all clients: " . $e->getMessage());
            return [];
        }
    }
    
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT p.client_id 
            FROM client_permissions cp
            JOIN projects p ON cp.project_id = p.id
            WHERE cp.user_id = ? 
              AND cp.is_active = 1
              AND cp.project_id IS NOT NULL
              AND (cp.expires_at IS NULL OR cp.expires_at > NOW())
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Error getting clients with accessible projects: " . $e->getMessage());
        return [];
    }
}
/**
 * Check if user has any client permissions at all (utility alias)
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @return bool True if user has any client-level permissions
 */
function hasAnyClientPermissions($db, $userId) {
    return canCreateProject($db, $userId);
}

/**
 * Check if user has permission to create projects for a specific client
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @param int $clientId Client ID
 * @return bool True if user has permission
 */
function canCreateProjectForClient($db, $userId, $clientId) {
    // Admin and admin always have permission
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin'])) {
        return true;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM client_permissions 
            WHERE user_id = ? 
              AND client_id = ?
              AND permission_type = 'create_project' 
              AND is_active = 1
              AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$userId, $clientId]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error checking client create project permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all client IDs for which user has specific permission
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @param string $permissionType Permission type
 * @return array Array of client IDs
 */
function getClientsWithPermission($db, $userId, $permissionType) {
    // Admin and admin have access to all clients
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin'])) {
        try {
            $stmt = $db->query("SELECT id FROM clients ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Error getting all clients: " . $e->getMessage());
            return [];
        }
    }
    
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT client_id 
            FROM client_permissions 
            WHERE user_id = ? 
              AND permission_type = ? 
              AND is_active = 1
              AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$userId, $permissionType]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Error getting clients with permission: " . $e->getMessage());
        return [];
    }
}
