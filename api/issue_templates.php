<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
ob_end_clean();


header('Content-Type: application/json');

$auth = new Auth();
// Basic auth check - user must be logged in
if (!$auth->isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$projectType = isset($_GET['project_type']) ? $_GET['project_type'] : 'web';
// Validate project type
if (!in_array($projectType, ['web', 'app', 'pdf'])) {
    $projectType = 'web';
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

try {
    if ($action === 'list') {
        // Fetch Presets
        $stmt = $db->prepare("SELECT id, title as name, description_html, metadata_json FROM issue_presets WHERE project_type = ? ORDER BY title ASC");
        $stmt->execute([$projectType]);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Default Sections
        $stmt = $db->prepare("SELECT sections_json FROM issue_default_templates WHERE project_type = ?");
        $stmt->execute([$projectType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $defaultSections = $row ? json_decode($row['sections_json'], true) : [];

        echo json_encode([
            'templates' => $templates,
            'default_sections' => $defaultSections
        ]);

    } elseif ($action === 'metadata_options') {
        // Fetch Metadata Fields
        $stmt = $db->prepare("SELECT field_key, field_label, options_json FROM issue_metadata_fields WHERE project_type = ? AND is_active = 1 ORDER BY sort_order ASC");
        $stmt->execute([$projectType]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format for frontend
        $formattedFields = [];
        foreach ($fields as $f) {
            $options = json_decode($f['options_json'], true);
            $formattedOptions = [];
            if (is_array($options)) {
                foreach ($options as $opt) {
                    $formattedOptions[] = [
                        'option_value' => $opt,
                        'option_label' => ucfirst($opt)
                    ];
                }
            }
            $formattedFields[] = [
                'field_key' => $f['field_key'],
                'field_label' => $f['field_label'],
                'options' => $formattedOptions
            ];
        }

        echo json_encode(['fields' => $formattedFields]);
    } else {
        echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    echo json_encode(['error' => 'An internal error occurred']);
}
