<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'admin']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_phases'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        $pid = intval($_POST['project_id'] ?? 0);
        redirect("/modules/projects/view.php?id=$pid");
    }
    $projectId = $_POST['project_id'];
    
    foreach ($_POST['phase'] as $phaseId => $phaseData) {
        $stmt = $db->prepare("
            UPDATE project_phases 
            SET start_date = ?, end_date = ?, planned_hours = ?, 
                actual_hours = ?, completion_percentage = ?
            WHERE id = ? AND project_id = ?
        ");
        
        $stmt->execute([
            $phaseData['start_date'] ?: null,
            $phaseData['end_date'] ?: null,
            $phaseData['planned_hours'] ?: null,
            $phaseData['actual_hours'] ?: null,
            $phaseData['completion_percentage'] ?: 0,
            $phaseId,
            $projectId
        ]);
    }
    
    $_SESSION['success'] = "Project phases updated successfully!";
    redirect("/modules/projects/view.php?id=$projectId");
}

// Handle Add Phase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_phase'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        $pid = intval($_POST['project_id'] ?? 0);
        redirect("/modules/projects/view.php?id=$pid");
    }
    $projectId = $_POST['project_id'];
    $phaseName = sanitizeInput($_POST['phase_name']);
    $startDate = $_POST['start_date'] ?: null;
    $endDate = $_POST['end_date'] ?: null;
    $plannedHours = $_POST['planned_hours'] ?: null;
    $status = $_POST['status'] ?: 'not_started';
    
    if (!empty($phaseName)) {
        // Check if phase already exists for this project
        $checkStmt = $db->prepare("SELECT id FROM project_phases WHERE project_id = ? AND phase_name = ?");
        $checkStmt->execute([$projectId, $phaseName]);
        
        if ($checkStmt->rowCount() > 0) {
            $_SESSION['error'] = "This phase already exists for this project!";
        } else {
            $stmt = $db->prepare("
                INSERT INTO project_phases (project_id, phase_name, start_date, end_date, planned_hours, status) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if ($stmt->execute([$projectId, $phaseName, $startDate, $endDate, $plannedHours, $status])) {
                $_SESSION['success'] = "New phase added successfully!";
                logActivity($db, $userId, 'add_phase', 'project', $projectId, ['phase' => $phaseName]);
            } else {
                $_SESSION['error'] = "Failed to add phase!";
            }
        }
    } else {
        $_SESSION['error'] = "Phase name is required!";
    }
    redirect("/modules/projects/view.php?id=$projectId");
}

// Handle Update Phase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_phase'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        $pid = intval($_POST['project_id'] ?? 0);
        redirect("/modules/projects/view.php?id=$pid");
    }
    $projectId = $_POST['project_id'];
    $phaseId = $_POST['phase_id'];
    $startDate = $_POST['start_date'] ?: null;
    $endDate = $_POST['end_date'] ?: null;
    $plannedHours = $_POST['planned_hours'] ?: null;
    $status = $_POST['status'] ?: 'not_started';
    
    if (!empty($phaseId)) {
        $stmt = $db->prepare("
            UPDATE project_phases 
            SET start_date = ?, end_date = ?, planned_hours = ?, status = ?
            WHERE id = ? AND project_id = ?
        ");
        
        if ($stmt->execute([$startDate, $endDate, $plannedHours, $status, $phaseId, $projectId])) {
            $_SESSION['success'] = "Phase updated successfully!";
            logActivity($db, $userId, 'update_phase', 'project', $projectId, [
                'phase_id' => $phaseId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'planned_hours' => $plannedHours,
                'status' => $status
            ]);
        } else {
            $_SESSION['error'] = "Failed to update phase!";
        }
    } else {
        $_SESSION['error'] = "Phase ID is required!";
    }
    redirect("/modules/projects/view.php?id=$projectId");
}

// Handle Delete Phase
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_phase'])) || 
    ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete']))) {
    
    if (isset($_POST['delete_phase'])) {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid request. Please try again.';
            $pid = intval($_POST['project_id'] ?? 0);
            redirect("/modules/projects/view.php?id=$pid");
        }
        $projectId = $_POST['project_id'];
        $phaseId = $_POST['phase_id'];
    } else {
        $phaseId = $_GET['delete'];
        $projectId = $_GET['project_id'];
    }
    
    $stmt = $db->prepare("DELETE FROM project_phases WHERE id = ? AND project_id = ?");
    if ($stmt->execute([$phaseId, $projectId])) {
        $_SESSION['success'] = "Phase removed successfully!";
        logActivity($db, $userId, 'remove_phase', 'project', $projectId, ['phase_id' => $phaseId]);
    } else {
        $_SESSION['error'] = "Failed to remove phase!";
    }
    redirect("/modules/projects/view.php?id=$projectId");
}

// Redirect if accessed directly
header('Location: ' . getBaseDir() . '/modules/admin/projects.php');
exit;