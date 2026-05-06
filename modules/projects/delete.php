<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$baseDir = getBaseDir();
$userRole = $_SESSION['role'] ?? '';
$projectId = (int)($_POST['project_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $projectId > 0) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid security token.";
        redirect($baseDir . "/modules/admin/projects.php");
        exit;
    }
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    
    if ($projectId > 0) {
        try {
            $db = Database::getInstance();
            
            // Start transaction
            $db->beginTransaction();

            // Required due to FK rule: project_time_logs.project_id => RESTRICT
            $cleanupLogs = $db->prepare("DELETE FROM project_time_logs WHERE project_id = ?");
            $cleanupLogs->execute([$projectId]);

            // Best-effort cleanup for summary snapshot table (if present)
            try {
                $cleanupSummary = $db->prepare("DELETE FROM project_hours_summary WHERE project_id = ?");
                $cleanupSummary->execute([$projectId]);
            } catch (Exception $_) {
                // Ignore if table does not exist or structure differs
            }
            
            // Delete project and related data (cascade should handle most)
            $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$projectId]);
            
            $db->commit();
            
            $_SESSION['success'] = "Project deleted successfully!";
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $_SESSION['error'] = "Failed to delete project: " . $e->getMessage();
        }
    }
}

// Redirect to role-specific projects page
if ($userRole === 'admin') {
    redirect($baseDir . "/modules/admin/projects.php");
} elseif ($userRole === 'project_lead') {
    redirect($baseDir . "/modules/project_lead/my_projects.php");
} elseif ($userRole === 'at_tester') {
    redirect($baseDir . "/modules/at_tester/my_projects.php");
} elseif ($userRole === 'ft_tester') {
    redirect($baseDir . "/modules/ft_tester/my_projects.php");
} elseif ($userRole === 'qa') {
    redirect($baseDir . "/modules/qa/my_projects.php");
} else {
    redirect($baseDir . "/index.php");
}
