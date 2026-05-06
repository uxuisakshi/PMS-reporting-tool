<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF protection
enforceApiCsrf();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$pageId = isset($_POST['page_id']) ? intval($_POST['page_id']) : 0;
$status = $_POST['status'] ?? '';

// Accept multiple possible POST names for environment id(s): environment_id (array or single), env_id
$envIds = [];
if (isset($_POST['environment_id'])) {
    if (is_array($_POST['environment_id'])) {
        $envIds = array_map('intval', $_POST['environment_id']);
    } else {
        $envIds = [ (int)$_POST['environment_id'] ];
    }
} elseif (isset($_POST['env_id'])) {
    if (is_array($_POST['env_id'])) {
        $envIds = array_map('intval', $_POST['env_id']);
    } else {
        $envIds = [ (int)$_POST['env_id'] ];
    }
}
$envIds = array_values(array_filter($envIds, function($v){ return $v > 0; }));

// Security fix: derive tester_type from server-side session role.
// Do NOT trust POST tester_type for locked roles — prevents QA writing AT status and vice versa.
$validTesterTypes = ['at', 'ft', 'qa'];
$testerType = '';
if ($userRole === 'at_tester') {
    $testerType = 'at'; // locked — cannot write to qa_status column
} elseif ($userRole === 'ft_tester') {
    $testerType = 'ft'; // locked — cannot write to qa_status column
} elseif ($userRole === 'qa') {
    $testerType = 'qa'; // locked — can only write qa_status
} else {
    // admin / project_lead: allow POST tester_type but validate it
    $testerType = $_POST['tester_type'] ?? '';
    if (!in_array($testerType, $validTesterTypes, true)) {
        $testerType = 'at'; // safe fallback
    }
}

function mapComputedToPageStatus(string $status): string {
    $map = [
        'testing_failed' => 'in_fixing',
        'qa_failed' => 'in_fixing',
        'in_testing' => 'in_progress',
        'tested' => 'needs_review',
        'qa_review' => 'qa_in_progress',
        'not_tested' => 'not_started',
        'on_hold' => 'on_hold',
        'completed' => 'completed',
        'in_progress' => 'in_progress',
        'in_fixing' => 'in_fixing',
        'needs_review' => 'needs_review',
        'qa_in_progress' => 'qa_in_progress',
        'not_started' => 'not_started',
        'pass' => 'qa_in_progress',
        'fail' => 'in_fixing'
    ];
    return $map[$status] ?? 'in_progress';
}

if (!$pageId || empty($envIds) || !$status) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate status
$testerStatuses = ['not_started', 'in_progress', 'pass', 'fail', 'on_hold', 'needs_review'];
$qaStatuses = ['pending','pass','fail','na','completed'];
if ($testerType === 'qa') {
    if (!in_array($status, $qaStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }
} else {
    if (!in_array($status, $testerStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }
}


try {
    $updated = [];
    $errors = [];

    $statusColumn = ($testerType === 'qa') ? 'qa_status' : 'status';

    $envSelectStmt = $db->prepare("SELECT pe.*, pp.project_id, p.project_lead_id FROM page_environments pe JOIN project_pages pp ON pe.page_id = pp.id JOIN projects p ON pp.project_id = p.id WHERE pe.page_id = ? AND pe.environment_id = ?");
    $updateStmt = $db->prepare("UPDATE page_environments SET $statusColumn = ? WHERE page_id = ? AND environment_id = ?");

    foreach ($envIds as $eId) {
        $envSelectStmt->execute([$pageId, $eId]);
        $env = $envSelectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$env) {
            $errors[] = ['env_id' => $eId, 'error' => 'Environment assignment not found'];
            continue;
        }

        // Permission checks
        $canUpdate = false;
        if (in_array($userRole, ['admin', 'qa'])) {
            $canUpdate = true;
        } elseif ($userRole === 'project_lead' && isset($env['project_lead_id']) && $env['project_lead_id'] == $userId) {
            $canUpdate = true;
        } else {
            if ($testerType === 'at' && !empty($env['at_tester_id']) && $env['at_tester_id'] == $userId) {
                $canUpdate = true;
            } elseif ($testerType === 'ft' && !empty($env['ft_tester_id']) && $env['ft_tester_id'] == $userId) {
                $canUpdate = true;
            } elseif ($testerType === 'qa' && !empty($env['qa_id']) && $env['qa_id'] == $userId) {
                $canUpdate = true;
            }
        }

        if (!$canUpdate) {
            $errors[] = ['env_id' => $eId, 'error' => 'Permission denied'];
            continue;
        }

        try {
            $updateStmt->execute([$status, $pageId, $eId]);
            $updated[] = $eId;
            logActivity($db, $userId, 'update_env_status', 'project', $env['project_id'], [
                'page_id' => $pageId,
                'environment_id' => $eId,
                'tester_type' => $testerType,
                'status' => $status
            ]);

            // For at/ft testers, also record a testing_results row so dashboards show recent activity
            try {
                if ($testerType !== 'qa') {
                    $testerRole = ($testerType === 'at') ? 'at_tester' : 'ft_tester';
                    $ins = $db->prepare("INSERT INTO testing_results (page_id, environment_id, tester_id, tester_role, status, issues_found, comments, hours_spent) VALUES (?, ?, ?, ?, ?, 0, '', 0)");
                    $ins->execute([$pageId, $eId, $userId, $testerRole, $status]);
                }
            } catch (Exception $_) {
                // non-fatal: don't fail the whole update if testing_results insert fails
            }
        } catch (Exception $ex) {
            $errors[] = ['env_id' => $eId, 'error' => 'DB error'];
        }
    }

    // Recompute page global status once
    $pageStmt = $db->prepare("SELECT * FROM project_pages WHERE id = ?");
    $pageStmt->execute([$pageId]);
    $pageData = $pageStmt->fetch(PDO::FETCH_ASSOC);

    if ($pageData) {
        $newGlobalStatus = computePageStatus($db, $pageData);
        $mappedStatus = mapComputedToPageStatus($newGlobalStatus);

        $updatePageStmt = $db->prepare("UPDATE project_pages SET status = ?, updated_at = NOW() WHERE id = ?");
        $updatePageStmt->execute([$mappedStatus, $pageId]);

        ensureProjectInProgress($db, $pageData['project_id']);

        echo json_encode([
            'success' => true,
            'message' => 'Environment status updated',
            'updated' => $updated,
            'errors' => $errors,
            'global_status' => $mappedStatus,
            'global_status_label' => ucfirst(str_replace('_', ' ', $mappedStatus))
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => true, 'message' => 'Environment status updated', 'updated' => $updated, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    error_log("Environment Status Update API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
