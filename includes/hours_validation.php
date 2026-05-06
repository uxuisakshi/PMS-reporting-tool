<?php
/**
 * Hours Validation Helper Functions
 * Provides validation logic for project hours allocation
 */

/**
 * Validate if hours can be allocated to a project
 * 
 * @param PDO $db Database connection
 * @param int $projectId Project ID
 * @param float $hoursToAllocate Hours to allocate
 * @param int|null $excludeAssignmentId Assignment ID to exclude from calculation (for updates)
 * @return array ['valid' => bool, 'message' => string, 'available_hours' => float]
 */
function validateHoursAllocation($db, $projectId, $hoursToAllocate, $excludeAssignmentId = null) {
    try {
        // Get project total hours and current allocations
        $query = "
            SELECT 
                p.total_hours,
                COALESCE(SUM(CASE 
                    WHEN (ua.is_removed IS NULL OR ua.is_removed = 0)
                     AND (ua.id != ? OR ? IS NULL)
                    THEN ua.hours_allocated 
                    ELSE 0 
                END), 0) as allocated_hours
            FROM projects p
            LEFT JOIN user_assignments ua ON p.id = ua.project_id
            WHERE p.id = ?
            GROUP BY p.id, p.total_hours
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$excludeAssignmentId, $excludeAssignmentId, $projectId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return [
                'valid' => false,
                'message' => 'Project not found.',
                'available_hours' => 0
            ];
        }
        
        $totalHours = $result['total_hours'] ?: 0;
        $allocatedHours = $result['allocated_hours'] ?: 0;
        $availableHours = max(0, $totalHours - $allocatedHours);
        
        if ($totalHours <= 0) {
            return [
                'valid' => false,
                'message' => 'Project total hours not set. Please set project total hours first.',
                'available_hours' => 0
            ];
        }
        
        if ($hoursToAllocate > $availableHours) {
            return [
                'valid' => false,
                'message' => "Cannot allocate {$hoursToAllocate} hours. Only {$availableHours} hours available in this project.",
                'available_hours' => $availableHours
            ];
        }
        
        return [
            'valid' => true,
            'message' => 'Hours allocation is valid.',
            'available_hours' => $availableHours
        ];
        
    } catch (Exception $e) {
        return [
            'valid' => false,
            'message' => 'Error validating hours: ' . $e->getMessage(),
            'available_hours' => 0
        ];
    }
}

/**
 * Get project hours summary
 * 
 * @param PDO $db Database connection
 * @param int $projectId Project ID
 * @return array Hours summary data
 */
function getProjectHoursSummary($db, $projectId) {
    try {
        // Use subqueries to avoid Cartesian product when joining user_assignments and project_time_logs
        $hasIsUtilized = false;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'is_utilized'");
            $hasIsUtilized = $colCheck && $colCheck->rowCount() > 0;
        } catch (Exception $e) { $hasIsUtilized = false; }
        $utilizedWhere = $hasIsUtilized ? "AND is_utilized = 1" : "";

        $query = "
            SELECT 
                p.total_hours,
                COALESCE(ua_sum.allocated_hours, 0) as allocated_hours,
                COALESCE(ptl_sum.utilized_hours, 0) as utilized_hours,
                COALESCE(ua_sum.team_members_count, 0) as team_members_count
            FROM projects p
            LEFT JOIN (
                SELECT project_id, SUM(hours_allocated) as allocated_hours, COUNT(DISTINCT user_id) as team_members_count
                FROM user_assignments
                WHERE project_id = ?
                GROUP BY project_id
            ) ua_sum ON p.id = ua_sum.project_id
            LEFT JOIN (
                SELECT project_id, SUM(hours_spent) as utilized_hours
                FROM project_time_logs
                WHERE project_id = ? $utilizedWhere
                GROUP BY project_id
            ) ptl_sum ON p.id = ptl_sum.project_id
            WHERE p.id = ?
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$projectId, $projectId, $projectId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return [
                'total_hours' => 0,
                'allocated_hours' => 0,
                'utilized_hours' => 0,
                'available_hours' => 0,
                'team_members_count' => 0,
                'utilization_percentage' => 0,
                'allocation_percentage' => 0
            ];
        }
        
        $totalHours = $result['total_hours'] ?: 0;
        $allocatedHours = $result['allocated_hours'] ?: 0;
        $utilizedHours = $result['utilized_hours'] ?: 0;
        $availableHours = $totalHours - $allocatedHours;
        
        return [
            'total_hours' => $totalHours,
            'allocated_hours' => $allocatedHours,
            'utilized_hours' => $utilizedHours,
            'available_hours' => $availableHours,
            'team_members_count' => $result['team_members_count'],
            'utilization_percentage' => $totalHours > 0 ? ($utilizedHours / $totalHours) * 100 : 0,
            'allocation_percentage' => $totalHours > 0 ? ($allocatedHours / $totalHours) * 100 : 0
        ];
        
    } catch (Exception $e) {
        return [
            'total_hours' => 0,
            'allocated_hours' => 0,
            'utilized_hours' => 0,
            'available_hours' => 0,
            'team_members_count' => 0,
            'utilization_percentage' => 0,
            'allocation_percentage' => 0,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Check if user can be assigned more hours across all projects
 * 
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @param float $additionalHours Additional hours to check
 * @return array ['valid' => bool, 'message' => string, 'current_total' => float]
 */
function validateUserTotalHours($db, $userId, $additionalHours) {
    try {
        // Get user's current total allocated hours
        $query = "
            SELECT COALESCE(SUM(hours_allocated), 0) as total_allocated_hours
            FROM user_assignments 
            WHERE user_id = ?
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        $currentTotal = $result['total_allocated_hours'] ?: 0;
        $newTotal = $currentTotal + $additionalHours;
        
        // You can set a maximum hours per user if needed
        // For now, we'll just return the information
        return [
            'valid' => true,
            'message' => 'User hours allocation is valid.',
            'current_total' => $currentTotal,
            'new_total' => $newTotal
        ];
        
    } catch (Exception $e) {
        return [
            'valid' => false,
            'message' => 'Error validating user hours: ' . $e->getMessage(),
            'current_total' => 0,
            'new_total' => 0
        ];
    }
}

/**
 * Log hours allocation activity
 * 
 * @param PDO $db Database connection
 * @param int $userId User performing the action
 * @param string $action Action type
 * @param int $entityId Entity ID (assignment ID, project ID, etc.)
 * @param array $details Additional details
 * @return bool Success status
 */
function logHoursActivity($db, $userId, $action, $entityId, $details = []) {
    try {
        $query = "
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at) 
            VALUES (?, ?, 'hours_management', ?, ?, NOW())
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$userId, $action, $entityId, json_encode($details)]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error logging hours activity: " . $e->getMessage());
        return false;
    }
}
