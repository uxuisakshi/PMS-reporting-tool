<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$db = Database::getInstance();
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$baseDir = function_exists('getBaseDir') ? getBaseDir() : '';
if (!$projectId) {
    echo '<div class="alert alert-info"><i class="fas fa-info-circle"></i> No assets uploaded for this project.</div>';
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$projectLeadIdStmt = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ? LIMIT 1");
$projectLeadIdStmt->execute([$projectId]);
$projectLeadId = (int)($projectLeadIdStmt->fetchColumn() ?? 0);
$assignedStmt = $db->prepare("SELECT id FROM user_assignments WHERE project_id = ? AND user_id = ? AND (is_removed IS NULL OR is_removed = 0) LIMIT 1");
$assignedStmt->execute([$projectId, $userId]);
$isAssigned = (bool)$assignedStmt->fetch();
$canManageAssets = in_array($userRole, ['admin'], true)
    || ($userRole === 'project_lead' && $projectLeadId === (int)$userId)
    || $isAssigned
    || hasAnyProjectPermission($db, $userId, $projectId, ['assets_edit', 'assets_delete']);

$stmt = $db->prepare("SELECT pa.*, u.full_name as creator_name FROM project_assets pa LEFT JOIN users u ON pa.created_by = u.id WHERE pa.project_id = ? ORDER BY pa.created_at DESC");
$stmt->execute([$projectId]);

if ($stmt->rowCount() > 0) {
    echo '<div class="row">';
    while ($asset = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo '<div class="col-md-4 mb-3">';
        echo '<div class="card h-100 shadow-sm">';
        echo '<div class="card-body">';
        echo '<div class="d-flex justify-content-between align-items-start mb-2">';
        echo '<h5 class="card-title mb-0">' . htmlspecialchars($asset['asset_name']) . '</h5>';
        if ($asset['asset_type'] === 'file') {
            echo '<span class="badge bg-secondary"><i class="fas fa-file"></i> File</span>';
        } elseif ($asset['asset_type'] === 'text') {
            echo '<span class="badge bg-success"><i class="fas fa-edit"></i> Text/Blog</span>';
        } else {
            echo '<span class="badge bg-info text-dark"><i class="fas fa-link"></i> Link</span>';
        }
        echo '</div>';

        if ($asset['asset_type'] === 'link') {
            if ($asset['link_type']) {
                echo '<p class="mb-1 text-muted small"><strong>Type:</strong> ' . htmlspecialchars($asset['link_type']) . '</p>';
            }
            echo '<p class="card-text"><a href="' . htmlspecialchars($asset['main_url']) . '" target="_blank" class="text-break"><i class="fas fa-external-link-alt small"></i> ' . htmlspecialchars($asset['main_url']) . '</a></p>';
        } elseif ($asset['asset_type'] === 'text') {
            if ($asset['link_type']) {
                echo '<p class="mb-1 text-muted small"><strong>Category:</strong> ' . htmlspecialchars($asset['link_type']) . '</p>';
            }
            $content = $asset['text_content'] ?: $asset['description'] ?: '';
            $preview = strlen($content) > 200 ? substr(strip_tags($content), 0, 200) . '...' : strip_tags($content);
            echo '<div class="card-text">';
            echo '<div class="text-content-preview" style="max-height:150px; overflow:hidden;">' . htmlspecialchars($preview) . '</div>';
            echo '<button type="button" class="btn btn-sm btn-outline-primary mt-2" data-bs-toggle="modal" data-bs-target="#viewTextModal" data-title="' . htmlspecialchars($asset['asset_name']) . '" data-content="' . htmlspecialchars($content) . '"><i class="fas fa-eye"></i> View Full Content</button>';
            echo '</div>';
        } else {
            echo '<div class="d-grid"><a href="' . $baseDir . '/api/secure_file.php?path=' . rawurlencode($asset['file_path']) . '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-download"></i> Download File</a></div>';
        }

        echo '</div>'; // card-body
        echo '<div class="card-footer bg-transparent border-top-0 pt-0 d-flex justify-content-between align-items-end">';
        $createdAt = !empty($asset['created_at']) ? date('M d, Y', strtotime($asset['created_at'])) : '';
        echo '<small class="text-muted">By: ' . htmlspecialchars($asset['creator_name'] ?: 'System') . ($createdAt ? '<br>' . $createdAt : '') . '</small>';

        if ($canManageAssets) {
            $formId = "deleteAssetForm_" . $asset['id'];
            echo '<form id="' . $formId . '" method="POST" action="' . $baseDir . '/modules/projects/handle_asset.php" onsubmit="confirmModal(\'Are you sure you want to delete this asset?\', function(){ document.getElementById(\'' . $formId . '\').submit(); }); return false;" class="d-inline">';
            echo '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
            echo '<input type="hidden" name="project_id" value="' . $projectId . '">';
            echo '<input type="hidden" name="asset_id" value="' . $asset['id'] . '">';
            echo '<input type="hidden" name="delete_asset" value="1">';
            echo '<button type="submit" class="btn btn-sm btn-link text-danger p-0 border-0"><i class="fas fa-trash"></i></button>';
            echo '</form>';
        }

        echo '</div>'; // footer
        echo '</div>'; // card
        echo '</div>'; // col
    }
    echo '</div>'; // row
} else {
    echo '<div class="alert alert-info"><i class="fas fa-info-circle"></i> No assets uploaded for this project.</div>';
}
