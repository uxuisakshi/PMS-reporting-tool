<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/hours_validation.php';
require_once __DIR__ . '/../../includes/client_permissions.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireLogin(); // Allow any logged-in user, we'll check specific permissions after loading the project

// Helper functions for project_pages table management
function isProjectPagesView($db) {
    try {
        $stmt = $db->prepare("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_pages' LIMIT 1");
        $stmt->execute();
        $type = $stmt->fetchColumn();
        return strtoupper((string)$type) === 'VIEW';
    } catch (Exception $e) {
        return false;
    }
}

function ensureProjectPagesTable($db) {
    // [DISABLED] This migration was found to be destructive if the view was scoped.
    return;
    /*
    if (!isProjectPagesView($db)) {

    $db->exec("
        CREATE TABLE IF NOT EXISTS project_pages_tmp_no_view (
            id int(11) NOT NULL AUTO_INCREMENT,
            project_id int(11) DEFAULT NULL,
            page_name varchar(200) NOT NULL,
            page_number varchar(50) DEFAULT NULL,
            url varchar(500) DEFAULT NULL,
            screen_name varchar(200) DEFAULT NULL,
            status enum('not_started','in_progress','on_hold','qa_in_progress','in_fixing','needs_review','completed') DEFAULT 'not_started',
            at_tester_id int(11) DEFAULT NULL,
            ft_tester_id int(11) DEFAULT NULL,
            qa_id int(11) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            created_by int(11) DEFAULT NULL,
            at_tester_ids longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
            ft_tester_ids longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            notes text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_project_pages_project_id (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $db->exec("
        INSERT INTO project_pages_tmp_no_view
            (id, project_id, page_name, page_number, url, screen_name, status, at_tester_id, ft_tester_id, qa_id, created_at, created_by, at_tester_ids, ft_tester_ids, updated_at, notes)
        SELECT
            up.id,
            up.project_id,
            up.page_name,
            up.page_number,
            up.url,
            up.screen_name,
            up.status,
            up.at_tester_id,
            up.ft_tester_id,
            up.qa_id,
            up.created_at,
            up.created_by,
            up.at_tester_ids,
            up.ft_tester_ids,
            up.updated_at,
            up.notes
        FROM project_pages up
        LEFT JOIN project_pages_tmp_no_view t ON t.id = up.id
        WHERE t.id IS NULL
    ");

    */
}

$db = Database::getInstance();
$projectManager = new ProjectManager();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$projectId = $_GET['project_id'] ?? ($_POST['project_id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'team';
$flashSuccess = isset($_SESSION['success']) ? (string)$_SESSION['success'] : '';
$flashError = isset($_SESSION['error']) ? (string)$_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

// If no project selected, show selector
if (!$projectId) {
    if (hasAdminPrivileges()) {
        $projects = $db->query("SELECT id, title FROM projects WHERE status != 'cancelled' ORDER BY title")->fetchAll();
    } elseif ($userRole === 'project_lead') {
        $projects = $db->prepare("SELECT id, title FROM projects WHERE project_lead_id = ? AND status != 'cancelled' ORDER BY title");
        $projects->execute([$userId]);
        $projects = $projects->fetchAll();
    } elseif ($userRole === 'qa') {
        $projects = $db->prepare("
            SELECT DISTINCT p.id, p.title 
            FROM projects p
            JOIN user_assignments ua ON p.id = ua.project_id
            WHERE ua.user_id = ? AND p.status != 'cancelled'
            AND (ua.is_removed IS NULL OR ua.is_removed = 0)
        ");
        $projects->execute([$userId]);
        $projects = $projects->fetchAll();
    } else {
        // Check if user has client permissions
        $clientIds = getClientsWithPermission($db, $userId, 'edit_project');
        if (!empty($clientIds)) {
            $placeholders = str_repeat('?,', count($clientIds) - 1) . '?';
            $stmt = $db->prepare("SELECT id, title FROM projects WHERE client_id IN ($placeholders) AND status != 'cancelled' ORDER BY title");
            $stmt->execute($clientIds);
            $projects = $stmt->fetchAll();
        } else {
            $projects = [];
        }
    }
} else {
    // Validate project access
    $accessQuery = "
        SELECT p.*, c.name as client_name 
        FROM projects p 
        LEFT JOIN clients c ON p.client_id = c.id 
        WHERE p.id = ?
    ";
    $stmt = $db->prepare($accessQuery);
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        $_SESSION['error'] = "Project not found or access denied.";
        header("Location: manage_assignments.php");
        exit;
    }
    
    // Check if user has permission to manage this project's team
    $canManageTeam = hasAdminPrivileges() || 
                     ($userRole === 'project_lead' && $project['project_lead_id'] == $userId) ||
                     ($userRole === 'qa' && hasProjectAccess($db, $userId, $projectId));
    
    // Also check client permissions for edit access
    if (!$canManageTeam) {
        $canManageTeam = canEditProjectById($db, $userId, $projectId);
    }
    
    if (!$canManageTeam) {
        $_SESSION['error'] = "You don't have permission to manage this project's team.";
        header("Location: " . getBaseDir() . "/modules/projects/view.php?id=$projectId");
        exit;
    }
    
    $projectTotalHoursForTeam = (float)($project['total_hours'] ?? 0);
    $activeAllocatedStmtForTeam = $db->prepare("
        SELECT COALESCE(SUM(hours_allocated), 0)
        FROM user_assignments
        WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0)
    ");
    $activeAllocatedStmtForTeam->execute([$projectId]);
    $activeAllocatedHoursForTeam = (float)$activeAllocatedStmtForTeam->fetchColumn();

    // CSRF check for all POST actions on this page
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ' . getBaseDir() . '/modules/projects/manage_assignments.php?id=' . $projectId);
        exit;
    }

    // Handle Team Assignment (Add/Remove) - Users with manage team permission
    if ($canManageTeam) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_member_hours'])) {
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            $newHoursRaw = $_POST['new_hours'] ?? '';
            $newHours = is_numeric($newHoursRaw) ? (float)$newHoursRaw : -1;

            if ($assignmentId <= 0 || $newHours < 0) {
                $_SESSION['error'] = "Invalid hours update request.";
                header("Location: manage_assignments.php?project_id=$projectId&tab=team");
                exit;
            }

            try {
                $assignmentStmt = $db->prepare("
                    SELECT ua.id, ua.user_id, ua.role, ua.hours_allocated, ua.is_removed, u.full_name
                    FROM user_assignments ua
                    JOIN users u ON u.id = ua.user_id
                    WHERE ua.id = ? AND ua.project_id = ?
                    LIMIT 1
                ");
                $assignmentStmt->execute([$assignmentId, $projectId]);
                $assignmentRow = $assignmentStmt->fetch(PDO::FETCH_ASSOC);

                if (!$assignmentRow || (int)($assignmentRow['is_removed'] ?? 0) === 1) {
                    $_SESSION['error'] = "Assignment not found.";
                    header("Location: manage_assignments.php?project_id=$projectId&tab=team");
                    exit;
                }

                if (($assignmentRow['role'] ?? '') === 'project_lead') {
                    $_SESSION['error'] = "Project lead hours cannot be edited from this section.";
                    header("Location: manage_assignments.php?project_id=$projectId&tab=team");
                    exit;
                }

                $oldHours = (float)($assignmentRow['hours_allocated'] ?? 0);

                $utilizedStmt = $db->prepare("
                    SELECT COALESCE(SUM(hours_spent), 0)
                    FROM project_time_logs
                    WHERE project_id = ? AND user_id = ? AND is_utilized = 1
                ");
                $utilizedStmt->execute([$projectId, (int)$assignmentRow['user_id']]);
                $memberUtilizedHours = (float)$utilizedStmt->fetchColumn();

                if ($newHours < $memberUtilizedHours) {
                    $_SESSION['error'] = "Hours cannot be lower than utilized hours (" . number_format($memberUtilizedHours, 1) . "h).";
                    header("Location: manage_assignments.php?project_id=$projectId&tab=team");
                    exit;
                }

                $activeAllocatedStmt = $db->prepare("
                    SELECT COALESCE(SUM(hours_allocated), 0)
                    FROM user_assignments
                    WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0)
                ");
                $activeAllocatedStmt->execute([$projectId]);
                $activeAllocatedHours = (float)$activeAllocatedStmt->fetchColumn();
                $freeHours = max(0.0, $projectTotalHoursForTeam - $activeAllocatedHours);
                $maxAllowed = $oldHours + $freeHours;

                if ($newHours > $maxAllowed) {
                    $_SESSION['error'] = "Cannot increase above " . number_format($maxAllowed, 1) . "h for this member right now.";
                    header("Location: manage_assignments.php?project_id=$projectId&tab=team");
                    exit;
                }

                if (abs($newHours - $oldHours) < 0.0001) {
                    $_SESSION['success'] = "No changes detected for member hours.";
                    header("Location: manage_assignments.php?project_id=$projectId&tab=team");
                    exit;
                }

                $updateHoursStmt = $db->prepare("UPDATE user_assignments SET hours_allocated = ? WHERE id = ? AND project_id = ?");
                $updateHoursStmt->execute([$newHours, $assignmentId, $projectId]);

                logActivity($db, $userId, 'edit_team_hours', 'project', $projectId, [
                    'assignment_id' => $assignmentId,
                    'target_user_id' => (int)$assignmentRow['user_id'],
                    'target_user_name' => (string)($assignmentRow['full_name'] ?? ''),
                    'old_hours' => $oldHours,
                    'new_hours' => $newHours,
                    'utilized_hours' => $memberUtilizedHours
                ]);

                $_SESSION['success'] = "Hours updated for " . ($assignmentRow['full_name'] ?? 'member') . ".";
            } catch (Throwable $e) {
                error_log("Team hours edit failed (project {$projectId}, assignment {$assignmentId}): " . $e->getMessage());
                $_SESSION['error'] = "Failed to update member hours. Please try again.";
            }

            header("Location: manage_assignments.php?project_id=$projectId&tab=team");
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_team'])) {
            $userIds = $_POST['user_ids'] ?? [];
            $hoursRaw = $_POST['hours_allocated'] ?? 0;
            $hours = is_numeric($hoursRaw) ? (float)$hoursRaw : 0.0;
            if ($hours < 0) $hours = 0.0;
            
            if (!is_array($userIds)) $userIds = [$userIds];

            $addedCount = 0;
            $restoredCount = 0;
            $skippedCount = 0;
            $belowUtilizedCount = 0;
            $errorCount = 0;

            foreach ($userIds as $uId) {
                if (empty($uId)) continue;
                try {
                    $uRoleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                    $uRoleStmt->execute([$uId]);
                    $uRole = $uRoleStmt->fetchColumn();
                    if (!$uRole) {
                        $skippedCount++;
                        continue;
                    }

                    // Enforce minimum assignment hours based on already utilized hours
                    // for this user in this project.
                    $utilizedStmt = $db->prepare("
                        SELECT COALESCE(SUM(hours_spent), 0)
                        FROM project_time_logs
                        WHERE project_id = ? AND user_id = ? AND is_utilized = 1
                    ");
                    $utilizedStmt->execute([$projectId, $uId]);
                    $memberUtilizedHours = (float)$utilizedStmt->fetchColumn();
                    if ($hours < $memberUtilizedHours) {
                        $belowUtilizedCount++;
                        continue;
                    }

                    // Check existing assignment (active or removed)
                    $existingStmt = $db->prepare("SELECT id, is_removed FROM user_assignments WHERE project_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
                    $existingStmt->execute([$projectId, $uId]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing && (int)$existing['is_removed'] === 0) {
                        $skippedCount++;
                        continue;
                    }

                    // Get project details for notification
                    $projectStmt = $db->prepare("SELECT title, po_number FROM projects WHERE id = ?");
                    $projectStmt->execute([$projectId]);
                    $projectInfo = $projectStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing && (int)$existing['is_removed'] === 1) {
                        $validation = validateHoursAllocation($db, $projectId, $hours, (int)$existing['id']);
                        if (!$validation['valid']) {
                            $errorCount++;
                            continue;
                        }
                        // Restore removed assignment
                        $restoreStmt = $db->prepare("UPDATE user_assignments SET is_removed = 0, removed_at = NULL, removed_by = NULL, assigned_by = ?, assigned_at = NOW(), hours_allocated = ? WHERE id = ? AND project_id = ?");
                        $restoreStmt->execute([$userId, $hours, $existing['id'], $projectId]);
                        if ($restoreStmt->rowCount() > 0) {
                            $restoredCount++;
                        } else {
                            $skippedCount++;
                        }

                        $notificationMessage = "You have been restored to project: " . ($projectInfo['title'] ?? 'Unknown Project');
                        if (!empty($projectInfo['po_number'])) {
                            $notificationMessage .= " (" . $projectInfo['po_number'] . ")";
                        }
                        if ($hours > 0) {
                            $notificationMessage .= " with " . $hours . " hours allocated";
                        }

                        createNotification(
                            $db,
                            $uId,
                            'assignment',
                            $notificationMessage,
                            "/modules/projects/view.php?id=" . $projectId
                        );

                        logActivity($db, $userId, 'restore_team', 'project', $projectId, [
                            'assignment_id' => $existing['id'],
                            'restored_user_id' => $uId,
                            'role' => $uRole,
                            'hours' => $hours
                        ]);
                    } else {
                        $validation = validateHoursAllocation($db, $projectId, $hours, null);
                        if (!$validation['valid']) {
                            $errorCount++;
                            continue;
                        }
                        // Insert new assignment
                        $insertStmt = $db->prepare("
                            INSERT INTO user_assignments (project_id, user_id, role, assigned_by, hours_allocated)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $insertStmt->execute([$projectId, $uId, $uRole, $userId, $hours]);
                        if ($insertStmt->rowCount() > 0) {
                            $addedCount++;
                        }

                        $notificationMessage = "You have been assigned to project: " . ($projectInfo['title'] ?? 'Unknown Project');
                        if (!empty($projectInfo['po_number'])) {
                            $notificationMessage .= " (" . $projectInfo['po_number'] . ")";
                        }
                        if ($hours > 0) {
                            $notificationMessage .= " with " . $hours . " hours allocated";
                        }

                        createNotification(
                            $db,
                            $uId,
                            'assignment',
                            $notificationMessage,
                            "/modules/projects/view.php?id=" . $projectId
                        );

                        // Log Activity
                        logActivity($db, $userId, 'assign_team', 'project', $projectId, [
                            'assigned_user_id' => $uId,
                            'role' => $uRole,
                            'hours' => $hours
                        ]);
                    }
                } catch (Exception $e) {
                    $errorCount++;
                    error_log("Team assignment error (project {$projectId}, user {$uId}): " . $e->getMessage());
                }
            }

            if (($addedCount + $restoredCount) > 0) {
                $_SESSION['success'] = "Team updated. Added: {$addedCount}, Restored: {$restoredCount}"
                    . ($skippedCount > 0 ? ", Skipped: {$skippedCount}" : "")
                    . ($belowUtilizedCount > 0 ? ", Below utilized hours: {$belowUtilizedCount}" : "")
                    . ($errorCount > 0 ? ", Errors: {$errorCount}" : "")
                    . ".";
            } elseif ($errorCount > 0) {
                $_SESSION['error'] = "Team assignment failed due to {$errorCount} error(s). Check server error log for details.";
            } elseif ($belowUtilizedCount > 0) {
                $_SESSION['error'] = "Team assignment failed for {$belowUtilizedCount} member(s): assigned hours cannot be less than already utilized hours.";
            } else {
                $_SESSION['error'] = "No members were added. Selected users may already be assigned or invalid.";
            }
            header("Location: manage_assignments.php?project_id=$projectId&tab=team");
            exit;
        }

        if (isset($_GET['remove_member'])) {
            $removeId = $_GET['remove_member'];
            
            // Get user_id before removing
            $userStmt = $db->prepare("SELECT user_id FROM user_assignments WHERE id = ?");
            $userStmt->execute([$removeId]);
            $removedUserId = $userStmt->fetchColumn();
            $removedUserRole = null;
            if ($removedUserId) {
                $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                $roleStmt->execute([$removedUserId]);
                $removedUserRole = $roleStmt->fetchColumn();
            }
            
            // Soft delete - mark as removed instead of hard delete
            $updateStmt = $db->prepare("UPDATE user_assignments SET is_removed = 1, removed_at = NOW(), removed_by = ? WHERE id = ? AND project_id = ?");
            $updateStmt->execute([$userId, $removeId, $projectId]);

            // Remove page-level assignments so removed user no longer appears in active projects list
            if ($removedUserId && $removedUserRole) {
                if ($removedUserRole === 'at_tester') {
                    $updPages = $db->prepare("UPDATE project_pages SET at_tester_id = NULL WHERE project_id = ? AND at_tester_id = ?");
                    $updPages->execute([$projectId, $removedUserId]);
                    $updEnvs = $db->prepare("UPDATE page_environments pe JOIN project_pages pp ON pp.id = pe.page_id SET pe.at_tester_id = NULL WHERE pp.project_id = ? AND pe.at_tester_id = ?");
                    $updEnvs->execute([$projectId, $removedUserId]);
                } elseif ($removedUserRole === 'ft_tester') {
                    $updPages = $db->prepare("UPDATE project_pages SET ft_tester_id = NULL WHERE project_id = ? AND ft_tester_id = ?");
                    $updPages->execute([$projectId, $removedUserId]);
                    $updEnvs = $db->prepare("UPDATE page_environments pe JOIN project_pages pp ON pp.id = pe.page_id SET pe.ft_tester_id = NULL WHERE pp.project_id = ? AND pe.ft_tester_id = ?");
                    $updEnvs->execute([$projectId, $removedUserId]);
                } elseif ($removedUserRole === 'qa') {
                    $updPages = $db->prepare("UPDATE project_pages SET qa_id = NULL WHERE project_id = ? AND qa_id = ?");
                    $updPages->execute([$projectId, $removedUserId]);
                    $updEnvs = $db->prepare("UPDATE page_environments pe JOIN project_pages pp ON pp.id = pe.page_id SET pe.qa_id = NULL WHERE pp.project_id = ? AND pe.qa_id = ?");
                    $updEnvs->execute([$projectId, $removedUserId]);
                }
            }
            
            // Get project details for notification
            if ($removedUserId) {
                $projectStmt = $db->prepare("SELECT title, po_number FROM projects WHERE id = ?");
                $projectStmt->execute([$projectId]);
                $projectInfo = $projectStmt->fetch(PDO::FETCH_ASSOC);
                
                // Create notification for removed user
                $notificationMessage = "You have been removed from project: " . ($projectInfo['title'] ?? 'Unknown Project');
                if (!empty($projectInfo['po_number'])) {
                    $notificationMessage .= " (" . $projectInfo['po_number'] . ")";
                }
                
                createNotification(
                    $db, 
                    $removedUserId, 
                    'system', 
                    $notificationMessage,
                    null // No link since they're removed
                );
            }
            
            // Log Activity
            logActivity($db, $userId, 'remove_team', 'project', $projectId, [
                'assignment_id' => $removeId,
                'removed_user_id' => $removedUserId
            ]);
            
            $_SESSION['success'] = "Team member removed from project.";
            header("Location: manage_assignments.php?project_id=$projectId&tab=team");
            exit;
        }

        // Handle restore member
        if (isset($_GET['restore_member'])) {
            $restoreId = $_GET['restore_member'];
            
            // Get user_id before restoring
            $userStmt = $db->prepare("SELECT user_id FROM user_assignments WHERE id = ?");
            $userStmt->execute([$restoreId]);
            $restoredUserId = $userStmt->fetchColumn();
            
            // Restore member - mark as active again
            $updateStmt = $db->prepare("UPDATE user_assignments SET is_removed = 0, removed_at = NULL, removed_by = NULL WHERE id = ? AND project_id = ?");
            $updateStmt->execute([$restoreId, $projectId]);
            
            // Get project details for notification
            if ($restoredUserId) {
                $projectStmt = $db->prepare("SELECT title, po_number FROM projects WHERE id = ?");
                $projectStmt->execute([$projectId]);
                $projectInfo = $projectStmt->fetch(PDO::FETCH_ASSOC);
                
                // Create notification for restored user
                $notificationMessage = "You have been restored to project: " . ($projectInfo['title'] ?? 'Unknown Project');
                if (!empty($projectInfo['po_number'])) {
                    $notificationMessage .= " (" . $projectInfo['po_number'] . ")";
                }
                
                createNotification(
                    $db, 
                    $restoredUserId, 
                    'assignment', 
                    $notificationMessage,
                    "/modules/projects/view.php?id=" . $projectId
                );
            }
            
            // Log Activity
            logActivity($db, $userId, 'restore_team', 'project', $projectId, [
                'assignment_id' => $restoreId
            ]);
            
            $_SESSION['success'] = "Team member restored to project.";
            header("Location: manage_assignments.php?project_id=$projectId&tab=team");
            exit;
        }

        // Handle Page Deletion (Lead/Admin)
        if (isset($_GET['remove_page'])) {
            $removePageId = (int)$_GET['remove_page'];

            // Start transaction for complete cleanup
            $db->beginTransaction();
            try {
                // Remove all related data first
                
                // Remove issue_pages entries for this page
                $delIssuePagesStmt = $db->prepare("DELETE FROM issue_pages WHERE page_id = ?");
                $delIssuePagesStmt->execute([$removePageId]);
                
                // Delete issues that are ONLY linked to this page (no other pages)
                // First, find issues that were only linked to this deleted page
                $orphanedIssuesStmt = $db->prepare("
                    SELECT i.id 
                    FROM issues i
                    WHERE i.page_id = ? 
                      AND NOT EXISTS (
                          SELECT 1 FROM issue_pages ip 
                          WHERE ip.issue_id = i.id AND ip.page_id != ?
                      )
                ");
                $orphanedIssuesStmt->execute([$removePageId, $removePageId]);
                $orphanedIssueIds = $orphanedIssuesStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete orphaned issues and their related data
                if (!empty($orphanedIssueIds)) {
                    $placeholders = implode(',', array_fill(0, count($orphanedIssueIds), '?'));
                    
                    // Delete issue metadata
                    $delIssueMeta = $db->prepare("DELETE FROM issue_metadata WHERE issue_id IN ($placeholders)");
                    $delIssueMeta->execute($orphanedIssueIds);
                    
                    // Delete issue comments
                    $delIssueComments = $db->prepare("DELETE FROM issue_comments WHERE issue_id IN ($placeholders)");
                    $delIssueComments->execute($orphanedIssueIds);
                    
                    // Delete issue history
                    $delIssueHistory = $db->prepare("DELETE FROM issue_history WHERE issue_id IN ($placeholders)");
                    $delIssueHistory->execute($orphanedIssueIds);
                    
                    // Delete the issues themselves
                    $delIssues = $db->prepare("DELETE FROM issues WHERE id IN ($placeholders)");
                    $delIssues->execute($orphanedIssueIds);
                }
                
                // Remove page environments
                $delEnv = $db->prepare("DELETE FROM page_environments WHERE page_id = ?");
                $delEnv->execute([$removePageId]);

                // Remove testing results
                $delTestResults = $db->prepare("DELETE FROM testing_results WHERE page_id = ?");
                $delTestResults->execute([$removePageId]);

                // Remove QA results
                $delQaResults = $db->prepare("DELETE FROM qa_results WHERE page_id = ?");
                $delQaResults->execute([$removePageId]);

                // Remove assignments related to this page
                $delAssignments = $db->prepare("DELETE FROM assignments WHERE page_id = ?");
                $delAssignments->execute([$removePageId]);

                // Remove any grouped URLs that reference this page
                $delGroupedUrls = $db->prepare("DELETE FROM grouped_urls WHERE project_id = ? AND url = (SELECT url FROM project_pages WHERE id = ?)");
                $delGroupedUrls->execute([$projectId, $removePageId]);

                // Finally remove the page record (ensure it belongs to this project)
                $delPage = $db->prepare("DELETE FROM project_pages WHERE id = ? AND project_id = ?");
                $delPage->execute([$removePageId, $projectId]);

                // Check if this was the last page in the project
                $remainingPagesStmt = $db->prepare("SELECT COUNT(*) as count FROM project_pages WHERE project_id = ?");
                $remainingPagesStmt->execute([$projectId]);
                $remainingCount = $remainingPagesStmt->fetch(PDO::FETCH_ASSOC)['count'];

                $db->commit();

                // Log Activity
                logActivity($db, $userId, 'remove_page', 'project', $projectId, [
                    'page_id' => $removePageId,
                    'remaining_pages' => $remainingCount
                ]);

                if ($remainingCount == 0) {
                    $_SESSION['success'] = "Page and all related data removed successfully. Next page added will start from 'Page 1'.";
                } else {
                    $_SESSION['success'] = "Page and all related data removed successfully.";
                }
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = "Error removing page: " . $e->getMessage();
            }

            header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
            exit;
        }
    }

    // Handle Page Assignment - Leads and QA
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_page'])) {
        $pageId = (int)$_POST['page_id'];
        $returnTo = trim($_POST['return_to'] ?? '');
        // Page-level defaults (may be left empty)
        $atTesterId = isset($_POST['at_tester_id']) && $_POST['at_tester_id'] !== '' ? (int)$_POST['at_tester_id'] : null;
        $ftTesterId = isset($_POST['ft_tester_id']) && $_POST['ft_tester_id'] !== '' ? (int)$_POST['ft_tester_id'] : null;
        $qaId = isset($_POST['qa_id']) && $_POST['qa_id'] !== '' ? (int)$_POST['qa_id'] : null;

        // envs[] contains the environment ids that should be linked to this page
        $selectedEnvs = $_POST['envs'] ?? [];

        // Save page-level columns on project_pages table
        $upd = $db->prepare("UPDATE project_pages SET at_tester_id = ?, ft_tester_id = ?, qa_id = ? WHERE id = ?");
        $upd->execute([$atTesterId, $ftTesterId, $qaId, $pageId]);

        // Handle per-environment assignments: iterate all known environments
        $allEnvStmt = $db->query("SELECT id FROM testing_environments")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allEnvStmt as $envId) {
            $envId = (int)$envId;
            $checked = in_array($envId, $selectedEnvs);
            if ($checked) {
                // read per-env tester selections
                // If per-env value is empty, fall back to page-level defaults selected in modal.
                $atEnv = isset($_POST['at_tester_env_' . $envId]) && $_POST['at_tester_env_' . $envId] !== '' ? (int)$_POST['at_tester_env_' . $envId] : $atTesterId;
                $ftEnv = isset($_POST['ft_tester_env_' . $envId]) && $_POST['ft_tester_env_' . $envId] !== '' ? (int)$_POST['ft_tester_env_' . $envId] : $ftTesterId;
                $qaEnv = isset($_POST['qa_env_' . $envId]) && $_POST['qa_env_' . $envId] !== '' ? (int)$_POST['qa_env_' . $envId] : $qaId;

                $stmt = $db->prepare("INSERT INTO page_environments (page_id, environment_id, at_tester_id, ft_tester_id, qa_id) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE at_tester_id = VALUES(at_tester_id), ft_tester_id = VALUES(ft_tester_id), qa_id = VALUES(qa_id)");
                $stmt->execute([$pageId, $envId, $atEnv, $ftEnv, $qaEnv]);
            } else {
                // remove link if exists
                $del = $db->prepare("DELETE FROM page_environments WHERE page_id = ? AND environment_id = ?");
                $del->execute([$pageId, $envId]);
            }
        }

        // Log Activity
        logActivity($db, $userId, 'assign_page', 'page', $pageId, [
            'at_tester' => $atTesterId,
            'ft_tester' => $ftTesterId,
            'qa' => $qaId,
            'environments' => $selectedEnvs
        ]);

        $_SESSION['success'] = "Page assignment updated.";
        // Validate return_to to prevent open redirect — only allow relative paths on same host
        $safeReturnTo = '';
        if (!empty($returnTo)) {
            $parsed = parse_url($returnTo);
            // Allow only if no host (relative path) and starts with /
            if (!isset($parsed['host']) && !isset($parsed['scheme']) && isset($parsed['path']) && strpos($parsed['path'], '/') === 0) {
                $safeReturnTo = $returnTo;
            }
        }
        if (!empty($safeReturnTo)) {
            $sep = (strpos($safeReturnTo, '?') === false) ? '?' : '&';
            header("Location: {$safeReturnTo}{$sep}tab=pages&subtab=project_pages_sub&focus_assign_btn=$pageId");
        } else {
            header("Location: manage_assignments.php?project_id=$projectId&tab=pages&focus_page_id=$pageId");
        }
        exit;
    }

    // Handle Edit Page Metadata
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_page_metadata'])) {
        $pageId = (int)$_POST['page_id'];
        $pageName = trim($_POST['page_name'] ?? '');
        $pageNumber = trim($_POST['page_number'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $screenName = trim($_POST['screen_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($pageId > 0 && !empty($pageName)) {
            try {
                // Get old values for logging
                $oldStmt = $db->prepare("SELECT page_name, page_number, url, screen_name, notes FROM project_pages WHERE id = ?");
                $oldStmt->execute([$pageId]);
                $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);

                if ($oldData) {
                    $upd = $db->prepare("UPDATE project_pages SET page_name = ?, page_number = ?, url = ?, screen_name = ?, notes = ? WHERE id = ?");
                    $upd->execute([$pageName, $pageNumber, $url, $screenName, $notes, $pageId]);

                    logActivity($db, $userId, 'edit_page_metadata', 'page', $pageId, [
                        'project_id' => $projectId,
                        'old' => $oldData,
                        'new' => [
                            'page_name' => $pageName,
                            'page_number' => $pageNumber,
                            'url' => $url,
                            'screen_name' => $screenName,
                            'notes' => $notes
                        ]
                    ]);

                    $_SESSION['success'] = "Page metadata updated successfully.";
                } else {
                    $_SESSION['error'] = "Page not found.";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Update failed: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Page ID and Name are required.";
        }
        header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
        exit;
    }

    // Handle Unique Page Assignment - create/update project pages for grouped URLs
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_unique'])) {
        $uniqueId = (int)($_POST['unique_id'] ?? 0);
        $atTesterId = isset($_POST['at_tester_id']) && $_POST['at_tester_id'] !== '' ? (int)$_POST['at_tester_id'] : null;
        $ftTesterId = isset($_POST['ft_tester_id']) && $_POST['ft_tester_id'] !== '' ? (int)$_POST['ft_tester_id'] : null;
        $qaId = isset($_POST['qa_id']) && $_POST['qa_id'] !== '' ? (int)$_POST['qa_id'] : null;
        $selectedEnvs = $_POST['envs'] ?? [];

        if ($uniqueId) {
            // fetch grouped urls for this unique
            $gStmt = $db->prepare('SELECT * FROM grouped_urls WHERE project_id = ? AND unique_page_id = ?');
            $gStmt->execute([$projectId, $uniqueId]);
            $grouped = $gStmt->fetchAll(PDO::FETCH_ASSOC);

            $created = 0;
            foreach ($grouped as $g) {
                $url = $g['url'];
                // find or create a project_page for this url
                $find = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND url = ? LIMIT 1');
                $find->execute([$projectId, $url]);
                $p = $find->fetch(PDO::FETCH_ASSOC);
                if ($p) {
                    $pageId = (int)$p['id'];
                } else {
                    // create page named after unique or url
                    $uStmt = $db->prepare('SELECT page_name FROM project_pages WHERE id = ? LIMIT 1');
                    $uStmt->execute([$uniqueId]);
                    $urow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    $pname = $urow['page_name'] ?: substr($url, 0, 80);
                    $ins = $db->prepare('INSERT INTO project_pages (project_id, page_name, url, created_by, created_at) VALUES (?, ?, ?, ?, NOW())');
                    $ins->execute([$projectId, $pname, $url, $userId]);
                    $pageId = (int)$db->lastInsertId();
                    $created++;
                }

                // update page-level testers
                $upd = $db->prepare('UPDATE project_pages SET at_tester_id = ?, ft_tester_id = ?, qa_id = ? WHERE id = ?');
                $upd->execute([$atTesterId, $ftTesterId, $qaId, $pageId]);

                // apply environment assignments
                $allEnvStmt = $db->query("SELECT id FROM testing_environments")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($allEnvStmt as $envId) {
                    $envId = (int)$envId;
                    $checked = in_array($envId, $selectedEnvs);
                    if ($checked) {
                        $atEnv = $atTesterId ?: null;
                        $ftEnv = $ftTesterId ?: null;
                        $qaEnv = $qaId ?: null;
                        $stmt = $db->prepare("INSERT INTO page_environments (page_id, environment_id, at_tester_id, ft_tester_id, qa_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE at_tester_id = VALUES(at_tester_id), ft_tester_id = VALUES(ft_tester_id), qa_id = VALUES(qa_id)");
                        $stmt->execute([$pageId, $envId, $atEnv, $ftEnv, $qaEnv]);
                    }
                }
            }

            logActivity($db, $userId, 'assign_unique', 'project', $projectId, ['unique_id' => $uniqueId, 'created_pages' => $created]);
            $_SESSION['success'] = "Unique assignment applied (created $created pages).";
        } else {
            $_SESSION['error'] = 'Unique page not specified.';
        }
        header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
        exit;
    }

    // Handle Quick Add Page
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_add_page'])) {
        $pageName = trim($_POST['page_name']);
        $url = trim($_POST['url'] ?? '');
        $screenName = trim($_POST['screen_name'] ?? '');
        
        if (!empty($pageName)) {
            // Generate the next page number using the same logic as the API
            $maxStmt = $db->prepare("SELECT MAX(CAST(REPLACE(page_number, 'Page ', '') AS UNSIGNED)) as maxn FROM project_pages WHERE project_id = ? AND page_number LIKE 'Page %'");
            $maxStmt->execute([$projectId]);
            $maxRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
            $nextN = (int)($maxRow['maxn'] ?? 0) + 1;
            $pageNumber = 'Page ' . $nextN;
            // Check for existing page with same name or URL in same project
            $checkStmt = $db->prepare("SELECT id FROM project_pages WHERE project_id = ? AND (page_name = ? OR (url IS NOT NULL AND url = ?)) LIMIT 1");
            $checkStmt->execute([$projectId, $pageName, $url ?: null]);
            if ($checkStmt->fetch()) {
                $_SESSION['error'] = "A page with this name or URL already exists in this project.";
            } else {
                $stmt = $db->prepare("INSERT INTO project_pages (project_id, page_name, page_number, url, screen_name, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$projectId, $pageName, $pageNumber, $url, $screenName, $userId]);
                $newPageId = $db->lastInsertId();
                
                // Log Activity
                logActivity($db, $userId, 'quick_add_page', 'page', $newPageId, [
                    'project_id' => $projectId,
                    'page_name' => $pageName,
                    'page_number' => $pageNumber
                ]);
                
                $_SESSION['success'] = "Page '$pageName' added successfully as $pageNumber.";
            }
        } else {
            $_SESSION['error'] = "Page Name is required.";
        }
        header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
        exit;
    }

    // Handle Bulk Assignment
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_assign'])) {
        $selectedPagesRaw = $_POST['selected_pages'] ?? [];
        $selectedEnvsRaw = $_POST['bulk_envs'] ?? [];
        $selectedPages = array_values(array_unique(array_filter(array_map('intval', is_array($selectedPagesRaw) ? $selectedPagesRaw : []), function ($v) {
            return $v > 0;
        })));
        $selectedEnvs = array_values(array_unique(array_filter(array_map('intval', is_array($selectedEnvsRaw) ? $selectedEnvsRaw : []), function ($v) {
            return $v > 0;
        })));

        $bulkAtTester = isset($_POST['bulk_at_tester']) ? (int)$_POST['bulk_at_tester'] : 0;
        $bulkFtTester = isset($_POST['bulk_ft_tester']) ? (int)$_POST['bulk_ft_tester'] : 0;
        $bulkQa = isset($_POST['bulk_qa']) ? (int)$_POST['bulk_qa'] : 0;

        // QA assignment is allowed only for privileged users. Ignore crafted values otherwise.
        if (!$canManageTeam) {
            $bulkQa = 0;
        }

        if (!empty($selectedPages) && !empty($selectedEnvs)) {
            try {
                $db->beginTransaction();

                // Validate selected pages belong to this project.
                $pagePlaceholders = implode(',', array_fill(0, count($selectedPages), '?'));
                $pagesStmt = $db->prepare("SELECT id FROM project_pages WHERE project_id = ? AND id IN ($pagePlaceholders)");
                $pagesStmt->execute(array_merge([$projectId], $selectedPages));
                $validPages = array_map('intval', $pagesStmt->fetchAll(PDO::FETCH_COLUMN));

                // Validate selected environments exist.
                $envPlaceholders = implode(',', array_fill(0, count($selectedEnvs), '?'));
                $envStmt = $db->prepare("SELECT id FROM testing_environments WHERE id IN ($envPlaceholders)");
                $envStmt->execute($selectedEnvs);
                $validEnvs = array_map('intval', $envStmt->fetchAll(PDO::FETCH_COLUMN));

                if (empty($validPages) || empty($validEnvs)) {
                    throw new RuntimeException('Invalid page/environment selection for bulk assignment.');
                }

                $atVal = $bulkAtTester ?: null;
                $ftVal = $bulkFtTester ?: null;
                $qaVal = $bulkQa ?: null;

                // Reuse prepared statements (faster and safer under load).
                $updatePageStmt = null;
                if ($bulkAtTester || $bulkFtTester || $bulkQa) {
                    $updatePageStmt = $db->prepare("UPDATE project_pages SET at_tester_id = ?, ft_tester_id = ?, qa_id = ? WHERE id = ?");
                }
                $upsertEnvStmt = $db->prepare("INSERT INTO page_environments (page_id, environment_id, at_tester_id, ft_tester_id, qa_id) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE at_tester_id = VALUES(at_tester_id), ft_tester_id = VALUES(ft_tester_id), qa_id = VALUES(qa_id)");

                foreach ($validPages as $pageId) {
                    if ($updatePageStmt) {
                        $updatePageStmt->execute([$atVal, $ftVal, $qaVal, $pageId]);
                    }
                    foreach ($validEnvs as $envId) {
                        $upsertEnvStmt->execute([$pageId, $envId, $atVal, $ftVal, $qaVal]);
                    }
                }

                // Log Activity
                logActivity($db, $userId, 'bulk_assign', 'project', $projectId, [
                    'pages_count' => count($validPages),
                    'environments' => $validEnvs,
                    'at_tester' => $atVal,
                    'ft_tester' => $ftVal,
                    'qa' => $qaVal
                ]);

                $db->commit();
                $_SESSION['success'] = "Bulk assignment completed for " . count($validPages) . " pages.";
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("Bulk assignment failed for project {$projectId}: " . $e->getMessage());
                $_SESSION['error'] = "Bulk assignment failed. Please try again or contact admin.";
            }
        } else {
            $_SESSION['error'] = "Please select at least one page and one environment.";
        }

        header("Location: manage_assignments.php?project_id=$projectId&tab=bulk");
        exit;
    }

    // Handle Bulk Delete Pages
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_pages'])) {
        $pageIdsRaw = trim($_POST['page_ids'] ?? '');
        $pageIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $pageIdsRaw)), function($v) {
            return $v > 0;
        })));
        
        if (!empty($pageIds)) {
            try {
                // Ensure project_pages is a table, not a view
                // ensureProjectPagesTable($db);
                
                // Check if pages exist before deletion
                $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
                $checkStmt = $db->prepare("SELECT id, page_name, project_id FROM project_pages WHERE id IN ($placeholders)");
                $checkStmt->execute($pageIds);
                $existingPages = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Filter to only pages belonging to this project
                $validPageIds = [];
                foreach ($existingPages as $page) {
                    if ((int)$page['project_id'] === (int)$projectId) {
                        $validPageIds[] = (int)$page['id'];
                    }
                }
                
                if (empty($validPageIds)) {
                    $_SESSION['error'] = "No valid pages found to delete for this project.";
                    header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
                    exit;
                }
                
                $db->beginTransaction();
                
                // Create placeholders for valid page IDs
                $validPlaceholders = implode(',', array_fill(0, count($validPageIds), '?'));
                
                // Delete related data first
                
                // Delete issue_pages
                $delIssuePagesStmt = $db->prepare("DELETE FROM issue_pages WHERE page_id IN ($validPlaceholders)");
                $delIssuePagesStmt->execute($validPageIds);
                
                // Delete chat_messages
                $delChatMessages = $db->prepare("DELETE FROM chat_messages WHERE page_id IN ($validPlaceholders)");
                $delChatMessages->execute($validPageIds);
                
                // Preserve production hours by detaching logs from deleted pages.
                $detachTimeLogs = $db->prepare("UPDATE project_time_logs SET page_id = NULL WHERE page_id IN ($validPlaceholders)");
                $detachTimeLogs->execute($validPageIds);
                
                // Delete page_environments
                $deleteEnvStmt = $db->prepare("DELETE FROM page_environments WHERE page_id IN ($validPlaceholders)");
                $deleteEnvStmt->execute($validPageIds);
                
                // Delete testing_results
                $delTestResults = $db->prepare("DELETE FROM testing_results WHERE page_id IN ($validPlaceholders)");
                $delTestResults->execute($validPageIds);
                
                // Delete qa_results
                $delQaResults = $db->prepare("DELETE FROM qa_results WHERE page_id IN ($validPlaceholders)");
                $delQaResults->execute($validPageIds);
                
                // Delete assignments
                $delAssignments = $db->prepare("DELETE FROM assignments WHERE page_id IN ($validPlaceholders)");
                $delAssignments->execute($validPageIds);
                
                // Delete grouped_urls (unique_page_id references)
                $delGroupedUrls = $db->prepare("DELETE FROM grouped_urls WHERE unique_page_id IN ($validPlaceholders)");
                $delGroupedUrls->execute($validPageIds);
                
                // Update issues to set page_id to NULL (since FK is SET NULL)
                $updateIssues = $db->prepare("UPDATE issues SET page_id = NULL WHERE page_id IN ($validPlaceholders)");
                $updateIssues->execute($validPageIds);
                
                // Delete project_pages
                $deletePagesStmt = $db->prepare("DELETE FROM project_pages WHERE id IN ($validPlaceholders) AND project_id = ?");
                $deletePagesStmt->execute(array_merge($validPageIds, [$projectId]));
                $deletedCount = $deletePagesStmt->rowCount();
                
                // CRITICAL CHECK - Verify deletion before commit
                $verifyStmt = $db->prepare("SELECT COUNT(*) FROM project_pages WHERE id IN ($validPlaceholders)");
                $verifyStmt->execute($validPageIds);
                $stillExistBeforeCommit = $verifyStmt->fetchColumn();
                
                $db->commit();
                
                // CRITICAL CHECK - Verify deletion after commit
                $verifyStmt2 = $db->prepare("SELECT COUNT(*) FROM project_pages WHERE id IN ($validPlaceholders)");
                $verifyStmt2->execute($validPageIds);
                $stillExistAfterCommit = $verifyStmt2->fetchColumn();
                
                $_SESSION['success'] = "Successfully deleted {$deletedCount} page(s) and their related data.";
                
                // Log activity
                try {
                    logActivity($db, $userId, 'bulk_delete_pages', 'project', $projectId, [
                        'deleted_page_ids' => $validPageIds,
                        'count' => $deletedCount
                    ]);
                } catch (Exception $e) {
                    error_log('Failed to log bulk delete pages activity: ' . $e->getMessage());
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Bulk delete pages error: ' . $e->getMessage());
                $_SESSION['error'] = "Failed to delete pages: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "No pages selected for deletion.";
        }
        
        header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
        exit;
    }

    // Handle Quick Assign All
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_assign_all'])) {
        $quickAtTester = isset($_POST['quick_at_tester']) ? (int)$_POST['quick_at_tester'] : 0;
        $quickFtTester = isset($_POST['quick_ft_tester']) ? (int)$_POST['quick_ft_tester'] : 0;
        $quickQa = isset($_POST['quick_qa']) ? (int)$_POST['quick_qa'] : 0;

        // QA assignment is allowed only for privileged users. Ignore any crafted value otherwise.
        if (!$canManageTeam) {
            $quickQa = 0;
        }

        $quickEnvsRaw = $_POST['quick_envs'] ?? [];
        $quickEnvs = array_values(array_unique(array_filter(array_map('intval', is_array($quickEnvsRaw) ? $quickEnvsRaw : []), function ($v) {
            return $v > 0;
        })));

        if ($quickAtTester || $quickFtTester || $quickQa || !empty($quickEnvs)) {
            try {
                $db->beginTransaction();

                $affectedPages = 0;

                // Update page-level assignments if testers/QA are selected
                if ($quickAtTester || $quickFtTester || $quickQa) {
                    $updateFields = [];
                    $updateValues = [];

                    if ($quickAtTester) {
                        $updateFields[] = "at_tester_id = ?";
                        $updateValues[] = $quickAtTester;
                    }
                    if ($quickFtTester) {
                        $updateFields[] = "ft_tester_id = ?";
                        $updateValues[] = $quickFtTester;
                    }
                    if ($quickQa) {
                        $updateFields[] = "qa_id = ?";
                        $updateValues[] = $quickQa;
                    }

                    if (!empty($updateFields)) {
                        $updateValues[] = $projectId;
                        $sql = "UPDATE project_pages SET " . implode(', ', $updateFields) . " WHERE project_id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($updateValues);
                        $affectedPages = $stmt->rowCount();
                    }
                }

                // Handle environment assignments if environments are selected
                if (!empty($quickEnvs)) {
                    $pagesStmt = $db->prepare("SELECT id FROM project_pages WHERE project_id = ?");
                    $pagesStmt->execute([$projectId]);
                    $allPages = $pagesStmt->fetchAll(PDO::FETCH_COLUMN);

                    if (!$affectedPages) {
                        $affectedPages = count($allPages);
                    }

                    if (!empty($allPages)) {
                        $envUpsert = $db->prepare("INSERT INTO page_environments (page_id, environment_id, at_tester_id, ft_tester_id, qa_id) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE at_tester_id = VALUES(at_tester_id), ft_tester_id = VALUES(ft_tester_id), qa_id = VALUES(qa_id)");

                        $atVal = $quickAtTester ?: null;
                        $ftVal = $quickFtTester ?: null;
                        $qaVal = $quickQa ?: null;

                        foreach ($allPages as $pageId) {
                            $pageId = (int)$pageId;
                            if ($pageId <= 0) continue;
                            foreach ($quickEnvs as $envId) {
                                $envUpsert->execute([$pageId, $envId, $atVal, $ftVal, $qaVal]);
                            }
                        }
                    }
                }

                // Log Activity
                logActivity($db, $userId, 'quick_assign_all', 'project', $projectId, [
                    'pages_affected' => $affectedPages,
                    'environments_count' => count($quickEnvs),
                    'at_tester' => $quickAtTester ?: null,
                    'ft_tester' => $quickFtTester ?: null,
                    'qa' => $quickQa ?: null
                ]);

                $db->commit();

                $envText = !empty($quickEnvs) ? " and " . count($quickEnvs) . " environments" : "";
                $_SESSION['success'] = "Quick assignment completed for $affectedPages pages$envText.";
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("Quick assign all failed for project {$projectId}: " . $e->getMessage());
                $_SESSION['error'] = "Quick assign failed. Please try again or contact admin.";
            }
        } else {
            $_SESSION['error'] = "Please select at least one tester, QA, or environment to assign.";
        }

        header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
        exit;
    }

    // Data for Tabs - Active team members only
    $team = $db->prepare("
        SELECT ua.*, u.full_name, u.email, u.role as user_role 
        FROM user_assignments ua
        JOIN users u ON ua.user_id = u.id
        WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
        UNION
        SELECT NULL as id, NULL as project_id, p.project_lead_id as user_id, 'project_lead' as role, 
               NULL as assigned_by, NULL as assigned_at, NULL as hours_allocated,
               NULL as is_removed, NULL as removed_at, NULL as removed_by,
               pl.full_name, pl.email, pl.role as user_role
        FROM projects p
        JOIN users pl ON p.project_lead_id = pl.id
        WHERE p.id = ? AND p.project_lead_id IS NOT NULL
        AND p.project_lead_id NOT IN (SELECT user_id FROM user_assignments WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0))
        ORDER BY 
            CASE role 
                WHEN 'project_lead' THEN 1
                WHEN 'qa' THEN 2
                WHEN 'at_tester' THEN 3
                WHEN 'ft_tester' THEN 4
            END, full_name
    ");
    $team->execute([$projectId, $projectId, $projectId]);
    $teamMembers = $team->fetchAll();

    // Get removed team members
    $removedTeam = $db->prepare("
        SELECT ua.*, u.full_name, u.email, u.role as user_role, ru.full_name as removed_by_name
        FROM user_assignments ua
        JOIN users u ON ua.user_id = u.id
        LEFT JOIN users ru ON ua.removed_by = ru.id
        WHERE ua.project_id = ? AND ua.is_removed = 1
        AND ua.user_id NOT IN (
            SELECT user_id 
            FROM user_assignments 
            WHERE project_id = ? 
            AND (is_removed IS NULL OR is_removed = 0)
        )
        ORDER BY ua.removed_at DESC
    ");
    $removedTeam->execute([$projectId, $projectId]);
    $removedMembers = $removedTeam->fetchAll();

    // Backfill from grouped URLs: ensure URLs are represented in project_pages.
    // DISABLED: This was causing deleted pages to be re-inserted on every page load
    // Only enable this if you explicitly want to sync grouped_urls to project_pages
    /*
    $syncUniqueToProjectPages = $db->prepare("
        INSERT INTO project_pages (project_id, page_name, page_number, url, screen_name, created_by, created_at)
        SELECT
            gu.project_id,
            SUBSTRING(gu.url, 1, 120) AS page_name,
            NULL AS page_number,
            gu.url,
            NULL AS screen_name,
            ?,
            NOW()
        FROM grouped_urls gu
        LEFT JOIN project_pages pp
            ON pp.project_id = gu.project_id
           AND pp.url = gu.url
        WHERE gu.project_id = ?
          AND gu.url IS NOT NULL
          AND TRIM(gu.url) <> ''
          AND pp.id IS NULL
    ");
    $syncUniqueToProjectPages->execute([$userId, $projectId]);
    */

    // ensureProjectPagesTable($db);

    // Fetch pages for this project (now using only project_pages table)
    // Order: Global pages first (Global 1, Global 2, ...), then Page pages (Page 1, Page 2, ..., Page 10, ...)
    $pagesStmt = $db->prepare("
        SELECT id, page_name, page_number, url, screen_name, at_tester_id, ft_tester_id, qa_id 
        FROM project_pages 
        WHERE project_id = ? 
        ORDER BY 
            CASE 
                WHEN page_number LIKE 'Global%' THEN 0
                WHEN page_number LIKE 'Page%' THEN 1
                ELSE 2
            END,
            CAST(
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(page_number, ' ', -1),
                    ' ', 1
                ) AS UNSIGNED
            ),
            page_number,
            id ASC
    ");
    $pagesStmt->execute([$projectId]);
    $projectPages = $pagesStmt->fetchAll();

    // Get only users who are assigned to this project team (active members only)
    // This is used for page assignment dropdowns
    $availableUsers = $db->prepare("
        SELECT DISTINCT u.id, u.full_name, u.role 
        FROM users u
        JOIN user_assignments ua ON u.id = ua.user_id
        WHERE ua.project_id = ? 
        AND u.role IN ('qa', 'at_tester', 'ft_tester', 'project_lead') 
        AND u.is_active = 1 
        AND (ua.is_removed IS NULL OR ua.is_removed = 0)
        ORDER BY u.full_name
    ");
    $availableUsers->execute([$projectId]);
    $availableUsers = $availableUsers->fetchAll();
    
    // Get ALL active users for "Add Team Member" dropdown (excluding already assigned team members)
    $allAvailableUsers = $db->prepare("
        SELECT DISTINCT u.id, u.full_name, u.role 
        FROM users u
        WHERE u.role IN ('qa', 'at_tester', 'ft_tester', 'project_lead') 
        AND u.is_active = 1
        AND u.id NOT IN (
            SELECT user_id 
            FROM user_assignments 
            WHERE project_id = ? 
            AND (is_removed IS NULL OR is_removed = 0)
        )
        ORDER BY u.full_name
    ");
    $allAvailableUsers->execute([$projectId]);
    $allAvailableUsers = $allAvailableUsers->fetchAll();
    
    $allEnvironments = $db->query("SELECT * FROM testing_environments ORDER BY name")->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-tasks text-primary"></i> Assignment Center</h2>
        <?php if ($projectId): ?>
            <a href="manage_assignments.php" class="btn btn-outline-secondary">
                <i class="fas fa-exchange-alt"></i> Change Project
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$projectId): ?>
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Select Project to Manage</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET">
                            <div class="mb-3">
                                <label class="form-label">Project</label>
                                <select name="project_id" class="form-select form-select-lg" onchange="this.form.submit()">
                                    <option value="">-- Select Project --</option>
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Project Info Header -->
        <div class="card mb-4 border-start border-4 border-primary">
            <div class="card-body py-2">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h4 class="mb-0"><?php echo htmlspecialchars($project['title']); ?></h4>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($project['client_name']); ?> | Project Code: <?php echo $project['po_number']; ?></p>
                    </div>
                    <div class="col-md-3">
                        <?php
                        // Get project hours summary
                        $hoursSummary = getProjectHoursSummary($db, $projectId);
                        $totalHours = $hoursSummary['total_hours'] ?: 0;
                        $allocatedHours = $hoursSummary['allocated_hours'] ?: 0;
                        $utilizedHours = $hoursSummary['utilized_hours'] ?: 0;
                        $remainingHours = $totalHours - $utilizedHours;
                        $availableForAllocation = max(0, $totalHours - $allocatedHours);
                        ?>
                        <div class="text-center">
                        <div class="text-center">
                            <h6 class="mb-1 text-primary">Project Hours</h6>
                            <div class="d-flex justify-content-center gap-3">
                                <?php 
                                $remainingHours = $totalHours - $utilizedHours;
                                $isOvershoot = $remainingHours < 0;
                                ?>
                                <div class="text-center">
                                    <div class="fw-bold text-primary"><?php echo $totalHours; ?></div>
                                    <small class="text-muted">Total Hours</small>
                                </div>
                                <div class="text-center">
                                    <div class="fw-bold <?php echo $isOvershoot ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $utilizedHours; ?>
                                    </div>
                                    <small class="text-muted">Used Hours</small>
                                </div>
                                <div class="text-center">
                                    <div class="fw-bold <?php echo $isOvershoot ? 'text-danger' : 'text-warning'; ?>">
                                        <?php echo $isOvershoot ? abs($remainingHours) : $remainingHours; ?>
                                    </div>
                                    <small class="text-muted"><?php echo $isOvershoot ? 'Overshoot' : 'Remaining'; ?></small>
                                </div>
                            </div>
                            <?php if ($totalHours > 0): ?>
                            <div class="progress mt-2" style="height: 8px;">
                                <?php if ($isOvershoot): ?>
                                    <!-- Green bar for total hours (100% of container) -->
                                    <div class="progress-bar bg-success" style="width: 100%;" title="Budget: <?php echo $totalHours; ?> hours"></div>
                                    <!-- Red bar for overshoot hours (extends beyond 100%) -->
                                    <div class="progress-bar bg-danger" style="width: <?php echo (abs($remainingHours) / $totalHours) * 100; ?>%;" title="Overshoot: <?php echo abs($remainingHours); ?> hours"></div>
                                <?php else: ?>
                                    <!-- Normal green bar for used hours within budget -->
                                    <div class="progress-bar bg-success" style="width: <?php echo ($utilizedHours / $totalHours) * 100; ?>%;" title="Used: <?php echo $utilizedHours; ?> hours"></div>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">
                                <?php echo round(($utilizedHours / $totalHours) * 100, 1); ?>% used
                                <?php if ($isOvershoot): ?>
                                    <span class="text-danger">(<?php echo abs($remainingHours); ?> hours over budget!)</span>
                                <?php endif; ?>
                            </small>
                            <?php else: ?>
                            <small class="text-muted">No total hours set for this project</small>
                            <?php endif; ?>
                        </div>
                        </div>
                    </div>
                    <div class="col-md-3 text-end">
                        <span class="badge bg-info text-dark">
                            <?php echo ucfirst($project['project_type']); ?>
                        </span>
                        <div class="mt-2">
                            <a href="view.php?id=<?php echo (int)$projectId; ?>" class="btn btn-sm btn-outline-primary" title="Open Project View">Open Project</a>
                        </div>
                        </span>
                        <span class="badge bg-secondary"><?php echo formatProjectStatusLabel($project['status']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'team' ? 'active' : ''; ?>" id="pills-team-tab" data-bs-toggle="pill" data-bs-target="#pills-team" type="button">
                    <i class="fas fa-users"></i> Project Team
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'pages' ? 'active' : ''; ?>" id="pills-pages-tab" data-bs-toggle="pill" data-bs-target="#pills-pages" type="button">
                    <i class="fas fa-file-alt"></i> Page Assignments
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'bulk' ? 'active' : ''; ?>" id="pills-bulk-tab" data-bs-toggle="pill" data-bs-target="#pills-bulk" type="button">
                    <i class="fas fa-magic"></i> Bulk Assignment
                </button>
            </li>
        </ul>

        <div class="tab-content" id="pills-tabContent">
            <!-- TAB 1: TEAM STAFFING -->
            <div class="tab-pane fade <?php echo $activeTab === 'team' ? 'show active' : ''; ?>" id="pills-team">
                <div class="row">
                    <?php if ($canManageTeam): ?>
                    <div class="col-md-4">
                        <div class="card" style="top: 20px;">
                            <div class="card-header">
                                <h5 class="mb-0">Add Team Member</h5>
                                <small class="text-muted">
                                    <?php if ($remainingHours >= 0): ?>
                                        Remaining Budget: <strong class="text-success"><?php echo $remainingHours; ?></strong> hours
                                        of <strong class="text-primary"><?php echo $totalHours; ?></strong> total
                                    <?php else: ?>
                                        Over Budget: <strong class="text-danger"><?php echo abs($remainingHours); ?></strong> hours
                                        (Total: <strong class="text-primary"><?php echo $totalHours; ?></strong>)
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="card-body">
                                <?php if ($totalHours <= 0): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    Project total hours not set. Please set project total hours first.
                                </div>
                                <?php elseif ($availableForAllocation <= 0): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    No hours available for new allocation right now.
                                    <br><small>You can still add members with 0 allocated hours.</small>
                                    <br><small>Allocated: <?php echo $allocatedHours; ?> hours | Total Budget: <?php echo $totalHours; ?> hours</small>
                                </div>
                                <?php endif; ?>
                                
                                <form method="POST" id="teamAssignForm" action="manage_assignments.php?project_id=<?php echo (int)$projectId; ?>&tab=team">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                                    <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                                    <div class="mb-3">
                                        <label class="form-label">Select Users</label>
                                        <select name="user_ids[]" id="teamUserSelect" class="form-select" multiple required>
                                            <?php foreach ($allAvailableUsers as $au): ?>
                                                <option value="<?php echo $au['id']; ?>">
                                                    <?php echo htmlspecialchars($au['full_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $au['role'])); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Allocated Hours</label>
                                        <input type="number" name="hours_allocated" class="form-control" value="0" step="0.01" max="<?php echo $availableForAllocation; ?>" id="hoursInput">
                                        <small class="text-muted">
                                            Max: <?php echo $availableForAllocation; ?> hours available for allocation
                                        </small>
                                        <small class="text-muted d-block">
                                            Minimum allowed per member is their already utilized hours.
                                        </small>
                                        <div id="hoursValidation" class="mt-1"></div>
                                    </div>
                                    <button type="submit" name="assign_team" class="btn btn-primary w-100" <?php echo ($totalHours <= 0) ? 'disabled' : ''; ?>>
                                        Add to Project
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="<?php echo $canManageTeam ? 'col-md-8' : 'col-md-12'; ?>">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Current Project Team</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Role</th>
                                                <th>Allocated Hours</th>
                                                <th>Utilized Hours</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($teamMembers as $m): 
                                                // Get utilized hours for this team member
                                                $utilizedStmt = $db->prepare("
                                                    SELECT COALESCE(SUM(hours_spent), 0) as utilized_hours 
                                                    FROM project_time_logs 
                                                    WHERE project_id = ? AND user_id = ? AND is_utilized = 1
                                                ");
                                                $utilizedStmt->execute([$projectId, $m['user_id']]);
                                                $memberUtilized = $utilizedStmt->fetchColumn() ?: 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo renderUserNameLink(['id' => $m['user_id'], 'full_name' => $m['full_name'], 'role' => $m['user_role']]); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($m['email']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        // Use current user role, not assignment role
                                                        $displayRole = $m['user_role'] ?? $m['role'];
                                                        echo $displayRole === 'project_lead' ? 'warning' : 
                                                             ($displayRole === 'qa' ? 'info' : 'primary');
                                                    ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $displayRole)); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($m['hours_allocated']) && $m['hours_allocated'] > 0): ?>
                                                        <span class="fw-bold text-primary"><?php echo $m['hours_allocated']; ?></span>
                                                        <small class="text-muted">hrs</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($memberUtilized > 0): ?>
                                                        <span class="fw-bold text-success"><?php echo $memberUtilized; ?></span>
                                                        <small class="text-muted">hrs</small>
                                                        <?php if ($m['hours_allocated'] > 0): ?>
                                                            <br><small class="text-muted">
                                                                (<?php echo round(($memberUtilized / $m['hours_allocated']) * 100, 1); ?>% used)
                                                            </small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">0 hrs</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($canManageTeam && $m['role'] !== 'project_lead'): ?>
                                                        <?php
                                                            $memberCurrentHours = (float)($m['hours_allocated'] ?? 0);
                                                            $memberFreeHours = max(0.0, $projectTotalHoursForTeam - $activeAllocatedHoursForTeam);
                                                            $memberMaxAllowed = $memberCurrentHours + $memberFreeHours;
                                                            $isTeamOverAllocated = $activeAllocatedHoursForTeam > $projectTotalHoursForTeam;
                                                        ?>
                                                        <button type="button"
                                                           class="btn btn-sm btn-outline-primary me-1 edit-team-hours-btn"
                                                           title="Edit Hours"
                                                           data-assignment-id="<?php echo (int)$m['id']; ?>"
                                                           data-user-name="<?php echo htmlspecialchars($m['full_name'], ENT_QUOTES); ?>"
                                                           data-current-hours="<?php echo number_format($memberCurrentHours, 1, '.', ''); ?>"
                                                           data-utilized-hours="<?php echo number_format((float)$memberUtilized, 1, '.', ''); ?>"
                                                           data-max-hours="<?php echo number_format($memberMaxAllowed, 1, '.', ''); ?>"
                                                           data-over-allocated="<?php echo $isTeamOverAllocated ? '1' : '0'; ?>">
                                                            <i class="fas fa-pen"></i>
                                                        </button>
                                                        <a href="?project_id=<?php echo $projectId; ?>&remove_member=<?php echo $m['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" title="Remove"
                                                           onclick="confirmModal('Remove <?php echo htmlspecialchars($m['full_name'], ENT_QUOTES); ?> from project? This action can be undone by restoring the member.', function(){ window.location.href='?project_id=<?php echo $projectId; ?>&remove_member=<?php echo $m['id']; ?>'; }); return false;">
                                                            <i class="fas fa-user-minus"></i>
                                                        </a>
                                                    <?php elseif ($m['role'] === 'project_lead'): ?>
                                                        <span class="text-muted small">Project Lead</span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($teamMembers)): ?>
                                            <tr><td colspan="5" class="text-center p-4">No team members assigned.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Removed Resources Section -->
                        <?php if (!empty($removedMembers)): ?>
                        <div class="card mt-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0 text-muted">
                                    <i class="fas fa-user-times"></i> Removed Resources
                                    <span class="badge bg-secondary ms-2"><?php echo count($removedMembers); ?></span>
                                </h5>
                                <small class="text-muted">Team members who have been removed from this project</small>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Role</th>
                                                <th>Allocated Hours</th>
                                                <th>Utilized Hours</th>
                                                <th>Removed</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($removedMembers as $rm): 
                                                // Get utilized hours for this removed member
                                                $utilizedStmt = $db->prepare("
                                                    SELECT COALESCE(SUM(hours_spent), 0) as utilized_hours 
                                                    FROM project_time_logs 
                                                    WHERE project_id = ? AND user_id = ? AND is_utilized = 1
                                                ");
                                                $utilizedStmt->execute([$projectId, $rm['user_id']]);
                                                $memberUtilized = $utilizedStmt->fetchColumn() ?: 0;
                                            ?>
                                            <tr class="text-muted">
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                                            <i class="fas fa-user text-white small"></i>
                                                        </div>
                                                        <div>
                                                            <div class="fw-medium"><?php echo htmlspecialchars($rm['full_name']); ?></div>
                                                            <small class="text-muted"><?php echo htmlspecialchars($rm['email']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        <?php echo ucfirst(str_replace('_', ' ', $rm['user_role'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($rm['hours_allocated']) && $rm['hours_allocated'] > 0): ?>
                                                        <span class="fw-bold text-muted"><?php echo $rm['hours_allocated']; ?></span>
                                                        <small class="text-muted">hrs</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-muted"><?php echo $memberUtilized; ?></span>
                                                    <small class="text-muted">hrs</small>
                                                    <?php if ($rm['hours_allocated'] > 0): ?>
                                                        <br><small class="text-muted">
                                                            (<?php echo round(($memberUtilized / $rm['hours_allocated']) * 100, 1); ?>% used)
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, Y', strtotime($rm['removed_at'])); ?><br>
                                                        by <?php echo htmlspecialchars($rm['removed_by_name'] ?: 'System'); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($canManageTeam): ?>
                                                        <a href="?project_id=<?php echo $projectId; ?>&restore_member=<?php echo $rm['id']; ?>" 
                                                           class="btn btn-sm btn-outline-success" title="Restore to project"
                                                           onclick="confirmModal('Restore <?php echo htmlspecialchars($rm['full_name'], ENT_QUOTES); ?> back to the project?', function(){ window.location.href='?project_id=<?php echo $projectId; ?>&restore_member=<?php echo $rm['id']; ?>'; }); return false;">
                                                            <i class="fas fa-undo"></i> Restore
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- TAB 2: PAGE ASSIGNMENTS -->
            <div class="tab-pane fade <?php echo $activeTab === 'pages' ? 'show active' : ''; ?>" id="pills-pages">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Individual Page Assignments</h5>
                        <div>
                            <button class="btn btn-sm btn-warning me-2" onclick="showQuickAssignModal()" title="Quick assign same tester/QA and environments to all pages">
                                <i class="fas fa-bolt"></i> Quick Assign All
                            </button>
                            <button class="btn btn-sm btn-success me-2" data-bs-toggle="modal" data-bs-target="#addPageModal">
                                <i class="fas fa-plus"></i> Add Page
                            </button>
                            <button class="btn btn-sm btn-outline-info me-2" data-bs-toggle="collapse" data-bs-target="#legendCollapse" title="Show/Hide Legend">
                                <i class="fas fa-info-circle"></i> Legend
                            </button>
                            <small class="text-muted">Page assignments and status overview</small>
                        </div>
                    </div>
                    
                    <!-- Legend -->
                    <div class="collapse" id="legendCollapse">
                        <div class="card-body border-bottom bg-light">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <h6 class="mb-2">Assignment Status</h6>
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <span class="badge bg-success"><i class="fas fa-check-circle"></i> Ready</span>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle"></i> Partial</span>
                                        <span class="badge bg-secondary"><i class="fas fa-circle"></i> Not Started</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="mb-2">Assignment Count</h6>
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <span class="badge bg-success"><i class="fas fa-users"></i> 3/3</span>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-users"></i> 2/3</span>
                                        <span class="badge bg-secondary"><i class="fas fa-users"></i> 0/3</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="mb-2">Environment Count</h6>
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <span class="badge bg-info"><i class="fas fa-server"></i> 5</span>
                                        <span class="badge bg-secondary"><i class="fas fa-server"></i> 0</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="mb-2">Actions</h6>
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" disabled aria-disabled="true" title="Legend sample only">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <span class="small">Show Details</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Project Summary -->
                    <div class="card-body border-bottom bg-light py-2">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <small class="text-muted">AT Assigned:</small>
                                <strong class="ms-1 text-primary">
                                    <?php 
                                    $atAssigned = 0;
                                    foreach ($projectPages as $page) {
                                        if (!empty($page['at_tester_id'])) $atAssigned++;
                                    }
                                    echo $atAssigned;
                                    ?>
                                </strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">FT Assigned:</small>
                                <strong class="ms-1 text-success">
                                    <?php 
                                    $ftAssigned = 0;
                                    foreach ($projectPages as $page) {
                                        if (!empty($page['ft_tester_id'])) $ftAssigned++;
                                    }
                                    echo $ftAssigned;
                                    ?>
                                </strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">QA Assigned:</small>
                                <strong class="ms-1 text-info">
                                    <?php 
                                    $qaAssigned = 0;
                                    foreach ($projectPages as $page) {
                                        if (!empty($page['qa_id'])) $qaAssigned++;
                                    }
                                    echo $qaAssigned;
                                    ?>
                                </strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Total Pages:</small>
                                <strong class="ms-1 text-dark"><?php echo count($projectPages); ?></strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <?php if ($canManageTeam): ?>
                        <div class="p-3 border-bottom bg-light">
                            <button type="button" class="btn btn-sm btn-danger" id="bulkDeletePagesBtn" disabled>
                                <i class="fas fa-trash"></i> Delete Selected (<span id="selectedPagesCount">0</span>)
                            </button>
                        </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 table-sm">
                                <thead class="bg-light">
                                    <tr>
                                        <?php if ($canManageTeam): ?>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="selectAllPages" aria-label="Select all pages">
                                        </th>
                                        <?php endif; ?>
                                        <th class="text-center" style="width: 10%;">Page No.</th>
                                        <th class="text-start" style="width: 30%;">Page/Screen</th>
                                        <th class="text-center" style="width: 30%;">Assignments</th>
                                        <th class="text-center" style="width: 20%;">Status</th>
                                        <th class="text-center" style="width: 10%;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projectPages as $p): 
                                        $pAtIds = getAssignedIdsFromPage($p, 'at_tester');
                                        $pFtIds = getAssignedIdsFromPage($p, 'ft_tester');
                                        
                                        // Get environment assignment summary for this page
                                        $envStmt = $db->prepare("
                                            SELECT 
                                                COUNT(*) AS env_count,
                                                MAX(CASE WHEN pe.at_tester_id IS NOT NULL THEN 1 ELSE 0 END) AS has_env_at,
                                                MAX(CASE WHEN pe.ft_tester_id IS NOT NULL THEN 1 ELSE 0 END) AS has_env_ft,
                                                MAX(CASE WHEN pe.qa_id IS NOT NULL THEN 1 ELSE 0 END) AS has_env_qa
                                            FROM page_environments pe
                                            WHERE pe.page_id = ?
                                        ");
                                        $envStmt->execute([$p['id']]);
                                        $envSummary = $envStmt->fetch(PDO::FETCH_ASSOC) ?: [
                                            'env_count' => 0,
                                            'has_env_at' => 0,
                                            'has_env_ft' => 0,
                                            'has_env_qa' => 0
                                        ];
                                        $envCount = (int)($envSummary['env_count'] ?? 0);
                                        
                                        // Calculate assignment status
                                        $hasAT = !empty($p['at_tester_id']) || !empty($pAtIds) || ((int)($envSummary['has_env_at'] ?? 0) === 1);
                                        $hasFT = !empty($p['ft_tester_id']) || !empty($pFtIds) || ((int)($envSummary['has_env_ft'] ?? 0) === 1);
                                        $hasQA = !empty($p['qa_id']) || ((int)($envSummary['has_env_qa'] ?? 0) === 1);
                                        $assignmentCount = ($hasAT ? 1 : 0) + ($hasFT ? 1 : 0) + ($hasQA ? 1 : 0);
                                        $isReady = ($hasFT && $hasQA && $envCount > 0); // AT is optional
                                    ?>
                                    <tr class="align-middle">
                                        <?php if ($canManageTeam): ?>
                                        <td>
                                            <input type="checkbox" class="page-select" value="<?php echo (int)$p['id']; ?>" aria-label="Select <?php echo htmlspecialchars($p['page_name']); ?>">
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-center">
                                            <?php if (!empty($p['page_number'])): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($p['page_number']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-start">
                                            <div>
                                                <strong class="d-block"><?php echo htmlspecialchars($p['page_name']); ?></strong>
                                                <?php if ($p['url'] || $p['screen_name']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($p['url'] ?: $p['screen_name']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center align-items-center gap-2">
                                                <!-- Assignment Summary -->
                                                <span class="badge <?php echo $isReady ? 'bg-success' : ($assignmentCount > 0 ? 'bg-warning text-dark' : 'bg-secondary'); ?>" 
                                                      title="<?php echo $assignmentCount; ?> roles assigned (AT optional)">
                                                    <i class="fas fa-users"></i> <?php echo $assignmentCount; ?>/3
                                                </span>
                                                
                                                <!-- Environment Summary -->
                                                <span class="badge <?php echo $envCount > 0 ? 'bg-info' : 'bg-secondary'; ?>" 
                                                      title="<?php echo $envCount; ?> environments assigned">
                                                    <i class="fas fa-server"></i> <?php echo $envCount; ?>
                                                </span>
                                                
                                                <!-- Quick View Button -->
                                                <button class="btn btn-sm btn-outline-secondary py-0 px-1" 
                                                        onclick="toggleRowDetails(<?php echo $p['id']; ?>)" 
                                                        title="Show/Hide Details">
                                                    <i class="fas fa-eye" id="eye-<?php echo $p['id']; ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php
                                            $statusClass = 'secondary';
                                            $statusText = 'Not Started';
                                            $statusIcon = 'fas fa-circle';
                                            
                                            if ($isReady) {
                                                $statusClass = 'success';
                                                $statusText = 'Ready';
                                                $statusIcon = 'fas fa-check-circle';
                                            } elseif ($assignmentCount > 0 || $envCount > 0) {
                                                $statusClass = 'warning text-dark';
                                                $statusText = 'Partial';
                                                $statusIcon = 'fas fa-exclamation-circle';
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $statusClass; ?>" title="Assignment Status">
                                                <i class="<?php echo $statusIcon; ?>"></i> <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editMetadataModal" 
                                                    onclick="populateEditMetadataModal(<?php echo htmlspecialchars(json_encode([
                                                        'id' => $p['id'],
                                                        'page_name' => $p['page_name'],
                                                        'page_number' => $p['page_number'],
                                                        'url' => $p['url'],
                                                        'screen_name' => $p['screen_name'],
                                                        'notes' => $p['notes'] ?? ''
                                                    ])); ?>)"
                                                    title="Edit page info (Name, Number, etc.)">
                                                <i class="fas fa-info-circle"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-page-edit-id="<?php echo $p['id']; ?>" data-bs-toggle="modal" data-bs-target="#pageModal<?php echo $p['id']; ?>" title="Edit assignments">
                                                <i class="fas fa-user-edit"></i>
                                            </button>
                                            <?php if ($canManageTeam): ?>
                                                <a href="?project_id=<?php echo $projectId; ?>&remove_page=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-danger ms-1" title="Delete page" onclick="confirmModal('Delete this page and its environment links? This cannot be undone.', function(){ window.location.href='?project_id=<?php echo $projectId; ?>&remove_page=<?php echo $p['id']; ?>'; }); return false;">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <!-- Expandable Details Row -->
                                    <tr class="collapse" id="details-<?php echo $p['id']; ?>">
                                        <td colspan="4" class="bg-light border-top-0">
                                            <div class="p-3">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <h6 class="mb-2 text-primary">
                                                            <i class="fas fa-users"></i> Team Assignments
                                                        </h6>
                                                        <div class="d-flex gap-2 flex-wrap">
                                                            <?php 
                                                            // Get assigned user names
                                                            $assignedUsers = [];
                                                            if (!empty($p['at_tester_id'])) {
                                                                $userStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                                                                $userStmt->execute([$p['at_tester_id']]);
                                                                $userName = $userStmt->fetchColumn();
                                                                if ($userName) {
                                                                    $assignedUsers[] = '<span class="badge bg-primary" title="AT Tester"><i class="fas fa-user-check"></i> AT: ' . htmlspecialchars($userName) . '</span>';
                                                                }
                                                            }
                                                            if (!empty($p['ft_tester_id'])) {
                                                                $userStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                                                                $userStmt->execute([$p['ft_tester_id']]);
                                                                $userName = $userStmt->fetchColumn();
                                                                if ($userName) {
                                                                    $assignedUsers[] = '<span class="badge bg-success" title="FT Tester"><i class="fas fa-user-cog"></i> FT: ' . htmlspecialchars($userName) . '</span>';
                                                                }
                                                            }
                                                            if (!empty($p['qa_id'])) {
                                                                $userStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                                                                $userStmt->execute([$p['qa_id']]);
                                                                $userName = $userStmt->fetchColumn();
                                                                if ($userName) {
                                                                    $assignedUsers[] = '<span class="badge bg-info" title="QA"><i class="fas fa-user-shield"></i> QA: ' . htmlspecialchars($userName) . '</span>';
                                                                }
                                                            }
                                                            
                                                            if (!empty($assignedUsers)) {
                                                                echo implode(' ', $assignedUsers);
                                                            } else {
                                                                echo '<span class="text-muted"><i class="fas fa-exclamation-circle"></i> No team assignments</span>';
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <h6 class="mb-2 text-primary">
                                                            <i class="fas fa-server"></i> Assigned Environments
                                                        </h6>
                                                        <div class="d-flex gap-1 flex-wrap">
                                                            <?php 
                                                            // Get assigned environments for this page
                                                            $envStmt = $db->prepare("
                                                                SELECT e.name, e.id 
                                                                FROM page_environments pe 
                                                                JOIN testing_environments e ON pe.environment_id = e.id 
                                                                WHERE pe.page_id = ? 
                                                                ORDER BY e.name
                                                            ");
                                                            $envStmt->execute([$p['id']]);
                                                            $assignedEnvs = $envStmt->fetchAll();
                                                            
                                                            if (!empty($assignedEnvs)) {
                                                                foreach ($assignedEnvs as $env) {
                                                                    echo '<span class="badge bg-warning text-dark" title="Environment: ' . htmlspecialchars($env['name']) . '">';
                                                                    echo '<i class="fas fa-server"></i> ' . htmlspecialchars($env['name']);
                                                                    echo '</span> ';
                                                                }
                                                            } else {
                                                                echo '<span class="text-muted"><i class="fas fa-exclamation-circle"></i> No environments assigned</span>';
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Quick Actions -->
                                                <div class="row mt-3">
                                                    <div class="col-12">
                                                        <div class="d-flex gap-2 justify-content-end">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    data-page-edit-id="<?php echo $p['id']; ?>" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#pageModal<?php echo $p['id']; ?>" 
                                                                    title="Edit assignments">
                                                                <i class="fas fa-edit"></i> Edit Assignments
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($projectPages)): ?>
                                    <tr><td colspan="4" class="text-center p-4">No pages found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: BULK ASSIGNMENT -->
            <div class="tab-pane fade <?php echo $activeTab === 'bulk' ? 'show active' : ''; ?>" id="pills-bulk">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Bulk Assignment Tool</h5>
                        <small class="text-muted">Assign same tester/QA and environments to multiple pages at once</small>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="mb-3">Select Pages</h6>
                                    <div class="mb-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllPages()">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllPages()">Clear All</button>
                                    </div>
                                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                        <?php foreach ($projectPages as $p): ?>
                                        <div class="form-check mb-1">
                                            <input class="form-check-input page-checkbox" type="checkbox" name="selected_pages[]" value="<?php echo $p['id']; ?>" id="page_<?php echo $p['id']; ?>">
                                            <label class="form-check-label" for="page_<?php echo $p['id']; ?>">
                                                <strong><?php echo htmlspecialchars($p['page_name']); ?></strong>
                                                <?php if ($p['url'] || $p['screen_name']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($p['url'] ?: $p['screen_name']); ?></small>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($projectPages)): ?>
                                        <p class="text-muted text-center py-2">No pages available.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <h6 class="mb-3">Assignment Details</h6>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">AT Tester</label>
                                                <select name="bulk_at_tester" class="form-select">
                                                    <option value="">-- Don't Change --</option>
                                                    <?php foreach ($teamMembers as $tm): 
                                                        $currentRole = $tm['user_role'] ?? $tm['role'];
                                                        if ($currentRole === 'at_tester' || $currentRole === 'project_lead'): ?>
                                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                    <?php endif; endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">FT Tester</label>
                                                <select name="bulk_ft_tester" class="form-select">
                                                    <option value="">-- Don't Change --</option>
                                                    <?php foreach ($teamMembers as $tm): 
                                                        $currentRole = $tm['user_role'] ?? $tm['role'];
                                                        if ($currentRole === 'ft_tester' || $currentRole === 'project_lead'): ?>
                                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                    <?php endif; endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">QA</label>
                                                <select name="bulk_qa" class="form-select" <?php echo !$canManageTeam ? 'disabled' : ''; ?>>
                                                    <option value="">-- Don't Change --</option>
                                                    <?php foreach ($teamMembers as $tm): 
                                                        $currentRole = $tm['user_role'] ?? $tm['role'];
                                                        if ($currentRole === 'qa' || $currentRole === 'project_lead'): ?>
                                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                    <?php endif; endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label class="form-label">Environments</label>
                                                <div class="mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllEnvs()">Select All</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllEnvs()">Clear All</button>
                                                </div>
                                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                                    <?php foreach ($allEnvironments as $env): ?>
                                                    <div class="form-check mb-1">
                                                        <input class="form-check-input env-checkbox" type="checkbox" name="bulk_envs[]" value="<?php echo $env['id']; ?>" id="env_<?php echo $env['id']; ?>">
                                                        <label class="form-check-label" for="env_<?php echo $env['id']; ?>">
                                                            <?php echo htmlspecialchars($env['name']); ?>
                                                        </label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($allEnvironments)): ?>
                                                    <p class="text-muted text-center py-2">No environments configured.</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" name="bulk_assign" class="btn btn-success btn-lg">
                                            <i class="fas fa-magic"></i> Apply Bulk Assignment
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Preview Section -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0">Preview: What will be assigned</h6>
                    </div>
                    <div class="card-body">
                        <div id="bulk-preview" class="text-muted">
                            Select pages, testers/QA, and environments to see preview...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Select2 -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
.badge {
    font-size: 0.7rem;
    padding: 0.25em 0.4em;
}
.badge i {
    font-size: 0.6rem;
    margin-right: 2px;
}
.table-sm td {
    padding: 0.5rem 0.25rem;
    vertical-align: middle;
}
.table-sm th {
    padding: 0.5rem 0.25rem;
    font-size: 0.85rem;
    font-weight: 600;
}
.collapse {
    transition: all 0.3s ease;
}
.collapse.show {
    display: table-row !important;
}
.pms-assign-notice-wrap {
    position: fixed;
    top: 76px;
    right: 16px;
    z-index: 1080;
    width: min(420px, calc(100vw - 32px));
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
}
.pms-assign-notice {
    pointer-events: auto;
    border-radius: 8px;
    border: 1px solid #dbeafe;
    background: #ffffff;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
    padding: 10px 12px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.pms-assign-notice.success {
    border-left: 4px solid #198754;
}
.pms-assign-notice.error {
    border-left: 4px solid #dc3545;
}
.pms-assign-notice .msg {
    font-size: 0.9rem;
    line-height: 1.35;
    color: #1f2937;
}
.pms-assign-notice .close-btn {
    margin-left: auto;
    border: 0;
    background: transparent;
    color: #6b7280;
    font-size: 1rem;
    line-height: 1;
    cursor: pointer;
}
</style>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
// Config for manage-assignments.js
window._manageAssignFlash = {
    success: <?php echo json_encode($flashSuccess); ?>,
    error:   <?php echo json_encode($flashError); ?>
};
window._manageAssignConfig = {
    activeTab: '<?php echo $activeTab; ?>',
    projectId: <?php echo (int)$projectId; ?>,
    availableForAllocation: <?php echo $availableForAllocation; ?>
};
</script>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/manage-assignments.js?v=<?php echo $assetVersion ?? time(); ?>"></script>
<!-- manage-assignments.js handles all JS logic above -->

<script nonce="<?php echo $cspNonce ?? ''; ?>">
jQuery(function ($) {
    $('#teamUserSelect').select2({
        width: '100%',
        placeholder: 'Search and select users...',
        allowClear: true,
        closeOnSelect: false
    }).on('select2:select select2:unselect', function () {
        var $el = $(this);
        setTimeout(function () {
            // Clear search text
            var $search = $el.data('select2').$container.find('.select2-search__field');
            if ($search.length) $search.val('');
            // Re-open with empty search to refresh results
            $el.select2('close');
            $el.select2('open');
        }, 10);
    });
});
</script>



<!-- Quick Add Page Modal -->
<div class="modal fade" id="addPageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Page</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="quick_add_page" value="1">
                        <label class="form-label">Page Name *</label>
                        <input type="text" name="page_name" class="form-control" required placeholder="e.g. Login Page">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">URL / Screen ID</label>
                        <input type="text" name="url" class="form-control" placeholder="e.g. /login or screen_001">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Screen Name (Optional)</label>
                        <input type="text" name="screen_name" class="form-control" placeholder="e.g. Primary Login">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="quick_add_page" class="btn btn-primary">Add Page</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Team Hours Modal -->
<div class="modal fade" id="editTeamHoursModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="manage_assignments.php?project_id=<?php echo (int)$projectId; ?>&tab=team" id="editTeamHoursForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Member Hours</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="assignment_id" id="editMemberAssignmentId" value="">
                    <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">

                    <div class="mb-2">
                        <strong id="editMemberName">Member</strong>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted d-block" id="editMemberCurrentHours">Current: 0.0h</small>
                        <small class="text-muted d-block" id="editMemberUtilizedHours">Utilized: 0.0h</small>
                        <small class="text-muted d-block" id="editMemberMaxHours">Max: 0.0h</small>
                    </div>

                    <div class="mb-3">
                        <label for="editMemberNewHours" class="form-label">New Hours</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="editMemberNewHours" name="new_hours" required>
                        <small class="text-muted d-block mt-1" id="editMemberHoursHint"></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_member_hours" value="1" class="btn btn-primary" id="saveTeamHoursBtn">Save Hours</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Assign All Modal -->
<div class="modal fade" id="quickAssignModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-bolt"></i> Quick Assign All Pages</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> This will assign the selected tester/QA and environments to ALL pages in this project at once.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">AT Tester</label>
                                <select name="quick_at_tester" class="form-select">
                                    <option value="">-- Keep Current --</option>
                                    <?php foreach ($teamMembers as $tm): 
                                        $currentRole = $tm['user_role'] ?? $tm['role'];
                                        if ($currentRole === 'at_tester'): ?>
                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">FT Tester</label>
                                <select name="quick_ft_tester" class="form-select">
                                    <option value="">-- Keep Current --</option>
                                    <?php foreach ($teamMembers as $tm): 
                                        $currentRole = $tm['user_role'] ?? $tm['role'];
                                        if ($currentRole === 'ft_tester'): ?>
                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">QA</label>
                                <select name="quick_qa" class="form-select" <?php echo !$canManageTeam ? 'disabled' : ''; ?>>
                                    <option value="">-- Keep Current --</option>
                                    <?php foreach ($teamMembers as $tm): 
                                        $currentRole = $tm['user_role'] ?? $tm['role'];
                                        if ($currentRole === 'qa' || $currentRole === 'project_lead'): ?>
                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Environments</label>
                                <div class="mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllQuickEnvs()">Select All</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllQuickEnvs()">Clear All</button>
                                </div>
                                <div style="max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                    <?php foreach ($allEnvironments as $env): ?>
                                    <div class="form-check mb-1">
                                        <input class="form-check-input quick-env-checkbox" type="checkbox" name="quick_envs[]" value="<?php echo $env['id']; ?>" id="quick_env_<?php echo $env['id']; ?>" checked>
                                        <label class="form-check-label" for="quick_env_<?php echo $env['id']; ?>">
                                            <?php echo htmlspecialchars($env['name']); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($allEnvironments)): ?>
                                    <p class="text-muted text-center py-2">No environments configured.</p>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">Selected environments will be linked to all pages with the assigned testers/QA.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="quick_assign_all" class="btn btn-warning">
                        <i class="fas fa-bolt"></i> Assign to All Pages
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Page Assignment Modals -->
<?php if (isset($projectPages) && is_array($projectPages)): ?>
<?php foreach ($projectPages as $p): ?>
<div class="modal fade" id="pageModal<?php echo $p['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content text-start">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                <input type="hidden" name="page_id" value="<?php echo $p['id']; ?>">
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_GET['return_to'] ?? ''); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo htmlspecialchars($p['page_name'] ?: ($p['url'] ?: ('Page #'.$p['id']))); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">AT Tester</label>
                        <select name="at_tester_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($teamMembers as $tm): 
                                $currentRole = $tm['user_role'] ?? $tm['role'];
                                if ($currentRole === 'at_tester' || $currentRole === 'project_lead'): ?>
                                <option value="<?php echo $tm['user_id']; ?>" <?php echo $p['at_tester_id'] == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">FT Tester</label>
                        <select name="ft_tester_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($teamMembers as $tm): 
                                $currentRole = $tm['user_role'] ?? $tm['role'];
                                if ($currentRole === 'ft_tester' || $currentRole === 'project_lead'): ?>
                                <option value="<?php echo $tm['user_id']; ?>" <?php echo $p['ft_tester_id'] == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">QA</label>
                        <select name="qa_id" class="form-select" <?php echo !hasProjectLeadPrivileges() ? 'disabled' : ''; ?> >
                            <option value="">-- None --</option>
                            <?php foreach ($teamMembers as $tm): 
                                $currentRole = $tm['user_role'] ?? $tm['role'];
                                if ($currentRole === 'qa' || $currentRole === 'project_lead'): ?>
                                <option value="<?php echo $tm['user_id']; ?>" <?php echo $p['qa_id'] == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                        <?php if (!hasProjectLeadPrivileges()): ?>
                            <input type="hidden" name="qa_id" value="<?php echo $p['qa_id']; ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Environments (per-environment assignments)</label>
                        <?php
                        $stmt = $db->prepare("SELECT pe.*, e.name FROM page_environments pe JOIN testing_environments e ON pe.environment_id = e.id WHERE pe.page_id = ?");
                        $stmt->execute([$p['id']]);
                        $pageEnvMap = [];
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pe) {
                            $pageEnvMap[$pe['environment_id']] = $pe;
                        }
                        foreach ($allEnvironments as $env):
                            $envId = $env['id'];
                            $linked = isset($pageEnvMap[$envId]);
                            $atSelected = $linked ? $pageEnvMap[$envId]['at_tester_id'] : '';
                            $ftSelected = $linked ? $pageEnvMap[$envId]['ft_tester_id'] : '';
                            $qaSelected = $linked ? $pageEnvMap[$envId]['qa_id'] : '';
                        ?>
                        <div class="border rounded p-2 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="envs[]" value="<?php echo $envId; ?>" id="env_chk_<?php echo $p['id']; ?>_<?php echo $envId; ?>" <?php echo $linked ? 'checked' : ''; ?> />
                                <label class="form-check-label fw-bold" for="env_chk_<?php echo $p['id']; ?>_<?php echo $envId; ?>"><?php echo htmlspecialchars($env['name']); ?></label>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-4">
                                    <label class="form-label">AT Tester</label>
                                    <select name="at_tester_env_<?php echo $envId; ?>" class="form-select">
                                        <option value="">-- None --</option>
                                        <?php foreach ($teamMembers as $tm): 
                                            $currentRole = $tm['user_role'] ?? $tm['role'];
                                            if ($currentRole === 'at_tester'): ?>
                                            <option value="<?php echo $tm['user_id']; ?>" <?php echo $atSelected == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                        <?php endif; endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">FT Tester</label>
                                    <select name="ft_tester_env_<?php echo $envId; ?>" class="form-select">
                                        <option value="">-- None --</option>
                                        <?php foreach ($teamMembers as $tm): 
                                            $currentRole = $tm['user_role'] ?? $tm['role'];
                                            if ($currentRole === 'ft_tester'): ?>
                                            <option value="<?php echo $tm['user_id']; ?>" <?php echo $ftSelected == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                        <?php endif; endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">QA</label>
                                    <select name="qa_env_<?php echo $envId; ?>" class="form-select" <?php echo !$canManageTeam ? 'disabled' : ''; ?> >
                                        <option value="">-- None --</option>
                                        <?php foreach ($teamMembers as $tm): 
                                            $currentRole = $tm['user_role'] ?? $tm['role'];
                                            if ($currentRole === 'qa'): ?>
                                            <option value="<?php echo $tm['user_id']; ?>" <?php echo $qaSelected == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                        <?php endif; endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="assign_page" class="btn btn-primary">Save Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Edit Page Metadata Modal -->
<div class="modal fade" id="editMetadataModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editMetadataForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Page Information</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="page_id" id="edit_page_id">
                    <input type="hidden" name="edit_page_metadata" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">Page Name *</label>
                        <input type="text" name="page_name" id="edit_page_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Page Number</label>
                        <input type="text" name="page_number" id="edit_page_number" class="form-control" placeholder="e.g. Page 1">
                        <small class="text-muted">Use 'Page X' format for consistent sorting.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">URL / Screen ID</label>
                        <input type="text" name="url" id="edit_page_url" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Screen Name (Optional)</label>
                        <input type="text" name="screen_name" id="edit_screen_name" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="edit_page_notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
function populateEditMetadataModal(data) {
    document.getElementById('edit_page_id').value = data.id || '';
    document.getElementById('edit_page_name').value = data.page_name || '';
    document.getElementById('edit_page_number').value = data.page_number || '';
    document.getElementById('edit_page_url').value = data.url || '';
    document.getElementById('edit_screen_name').value = data.screen_name || '';
    document.getElementById('edit_page_notes').value = data.notes || '';
}
</script>

<!-- manage-assignments.js handles multiselect delete -->

<?php include __DIR__ . '/../../includes/footer.php'; 