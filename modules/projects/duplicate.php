<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$baseDir = getBaseDir();
$projectId = (int)($_POST['project_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $projectId > 0) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ' . $baseDir . '/modules/projects/edit.php?id=' . $projectId);
        exit;
    }
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $newPoNumber = isset($_POST['new_po_number']) ? trim($_POST['new_po_number']) : '';
    $newTitle = isset($_POST['new_title']) ? trim($_POST['new_title']) : '';
    $copyPages = isset($_POST['copy_pages']);
    $copyTeam = isset($_POST['copy_team']);
    
    if ($projectId > 0 && !empty($newPoNumber) && !empty($newTitle)) {
        try {
            $db = Database::getInstance();
            
            // Check if Project Code already exists
            $checkStmt = $db->prepare("SELECT id FROM projects WHERE po_number = ?");
            $checkStmt->execute([$newPoNumber]);
            if ($checkStmt->fetch()) {
                $_SESSION['error'] = "Project Code already exists!";
                header("Location: " . $baseDir . "/modules/projects/edit.php?id=" . $projectId);
                exit;
            }
            
            // Get original project
            $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$projectId]);
            $original = $stmt->fetch();
            
            if (!$original) {
                $_SESSION['error'] = "Original project not found.";
                header("Location: " . $baseDir . "/modules/admin/projects.php");
                exit;
            }
            
            // Start transaction
            $db->beginTransaction();
            
            // Insert new project
            $insertStmt = $db->prepare("
                INSERT INTO projects (po_number, project_code, title, description, project_type, client_id, 
                                     priority, status, total_hours, project_lead_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'not_started', ?, ?, ?)
            ");
            
            $insertStmt->execute([
                $newPoNumber,
                $newPoNumber,
                $newTitle,
                $original['description'],
                ($original['project_type'] ?: 'web'),
                $original['client_id'],
                ($original['priority'] ?: 'medium'),
                $original['total_hours'],
                $copyTeam ? $original['project_lead_id'] : null,
                $_SESSION['user_id']
            ]);
            
            $newProjectId = $db->lastInsertId();
            
            // Copy phases
            $phaseStmt = $db->prepare("SELECT * FROM project_phases WHERE project_id = ?");
            $phaseStmt->execute([$projectId]);
            $phases = $phaseStmt->fetchAll();
            
            foreach ($phases as $phase) {
                $db->prepare("
                    INSERT INTO project_phases (project_id, phase_name, planned_hours)
                    VALUES (?, ?, ?)
                ")->execute([$newProjectId, $phase['phase_name'], $phase['planned_hours']]);
            }
            
            // Copy pages if requested
            if ($copyPages) {
                $pageStmt = $db->prepare("SELECT * FROM project_pages WHERE project_id = ?");
                $pageStmt->execute([$projectId]);
                $pages = $pageStmt->fetchAll();
                
                foreach ($pages as $page) {
                    $db->prepare("
                        INSERT INTO project_pages (project_id, page_name, page_number, url, screen_name)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([
                        $newProjectId,
                        $page['page_name'],
                        $page['page_number'],
                        $page['url'],
                        $page['screen_name']
                    ]);
                }
            }
            
            // Copy team assignments if requested
            if ($copyTeam) {
                $teamStmt = $db->prepare("SELECT * FROM user_assignments WHERE project_id = ?");
                $teamStmt->execute([$projectId]);
                $team = $teamStmt->fetchAll();
                
                foreach ($team as $member) {
                    $db->prepare("
                        INSERT INTO user_assignments (project_id, user_id, role, assigned_by, hours_allocated)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([
                        $newProjectId,
                        $member['user_id'],
                        $member['role'],
                        $_SESSION['user_id'],
                        $member['hours_allocated']
                    ]);
                }
            }
            
            $db->commit();
            
            // Log activity
            logActivity($db, $_SESSION['user_id'], 'duplicated_project', 'project', $newProjectId, [
                'original_project_id' => $projectId,
                'original_title' => $original['title'],
                'new_title' => $newTitle,
                'new_po_number' => $newPoNumber,
                'copied_pages' => $copyPages,
                'copied_team' => $copyTeam,
                'pages_count' => $copyPages ? count($pages ?? []) : 0,
                'team_count' => $copyTeam ? count($team ?? []) : 0
            ]);
            
            $_SESSION['success'] = "Project duplicated successfully!";
            header("Location: " . $baseDir . "/modules/projects/view.php?id=" . $newProjectId);
            exit;
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $_SESSION['error'] = "Failed to duplicate project: " . $e->getMessage();
            header("Location: " . $baseDir . "/modules/projects/edit.php?id=" . $projectId);
            exit;
        }
    }
}

header("Location: " . $baseDir . "/modules/admin/projects.php");
exit;
