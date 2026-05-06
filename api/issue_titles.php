<?php
// api/issue_titles.php
// Returns JSON array of matching issue titles for suggestions
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$projectType = isset($_GET['project_type']) ? trim($_GET['project_type']) : 'web';
$presetsOnly = isset($_GET['presets_only']) && $_GET['presets_only'] == '1';

$titles = [];

try {
    $db = Database::getInstance();
    
    if ($presetsOnly || $q === '') {
        // Get all presets for the project type (for focus/empty field)
        $presetStmt = $db->prepare('SELECT DISTINCT title FROM issue_presets WHERE project_type = ? ORDER BY title LIMIT 100');
        $presetStmt->execute([$projectType]);
        while ($row = $presetStmt->fetch(PDO::FETCH_ASSOC)) {
            $titles[] = $row['title'];
        }
    } else if ($q !== '' && strlen($q) >= 2) {
        // Get titles from issue_presets matching title OR metadata_json (wcag, gigw, etc.)
        $presetStmt = $db->prepare('
            SELECT DISTINCT title FROM issue_presets 
            WHERE project_type = ? AND (
                title LIKE ? 
                OR metadata_json LIKE ?
            )
            ORDER BY title LIMIT 50
        ');
        $like = '%' . $q . '%';
        $presetStmt->execute([$projectType, $like, $like]);
        while ($row = $presetStmt->fetch(PDO::FETCH_ASSOC)) {
            $titles[] = $row['title'];
        }
        
        // Get titles from existing issues (previously used titles)
        $issueStmt = $db->prepare('SELECT DISTINCT title FROM issues WHERE title LIKE ? ORDER BY updated_at DESC LIMIT 50');
        $issueStmt->execute([$like]);
        while ($row = $issueStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!in_array($row['title'], $titles)) {
                $titles[] = $row['title'];
            }
        }
        
        $titles = array_slice($titles, 0, 100);
    }
    
} catch (Exception $e) {
    error_log('issue_titles.php error: ' . $e->getMessage());
}

echo json_encode(['titles' => $titles]);

