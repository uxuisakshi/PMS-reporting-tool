<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
// Some actions needed by public/users (reading presets), others only by admin
// We'll handle auth check inside actions

$baseDir = getBaseDir();
$db = Database::getInstance();
$action = $_REQUEST['action'] ?? '';
$canManageIssueConfig = $auth->checkRole(['admin']) || !empty($_SESSION['can_manage_issue_config']);
// Check permissions (admin, admin, or explicit permission)
if (!$canManageIssueConfig) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// CSRF protection
enforceApiCsrf();
$projectType = $_REQUEST['project_type'] ?? 'web';

// Validate project types
if (!in_array($projectType, ['web', 'app', 'pdf'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid project type']);
    exit;
}

try {
    $assertCanManage = function () use ($canManageIssueConfig) {
        if (!$canManageIssueConfig) {
            throw new Exception('Unauthorized');
        }
    };

    switch ($action) {
        case 'get_presets':
            // Public read allowed (for authenticated users) if we implement public endpoint separately, 
            // but this file seems comprehensive. Let's allow read for 'auth' role
            if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');
            
            $stmt = $db->prepare("SELECT id, title, description_html, metadata_json FROM issue_presets WHERE project_type = ? ORDER BY title ASC");
            $stmt->execute([$projectType]);
            $presets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON
            foreach ($presets as &$p) {
                $p['metadata_json'] = json_decode($p['metadata_json'], true) ?? (object)[];
            }
            echo json_encode(['success' => true, 'data' => $presets]);
            break;

        case 'save_preset':
            $assertCanManage();
            $id = $_POST['id'] ?? '';
            $title = trim($_POST['title']);
            $desc = $_POST['description_html'] ?? '';
            $meta = $_POST['metadata_json'] ?? '{}';
            
            if (!$title) throw new Exception('Title is required');
            
            if ($id) {
                $stmt = $db->prepare("UPDATE issue_presets SET title=?, description_html=?, metadata_json=? WHERE id=?");
                $stmt->execute([$title, $desc, $meta, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO issue_presets (project_type, title, description_html, metadata_json) VALUES (?, ?, ?, ?)");
                $stmt->execute([$projectType, $title, $desc, $meta]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'delete_preset':
            $assertCanManage();
            $id = $_POST['id'];
            $stmt = $db->prepare("DELETE FROM issue_presets WHERE id=?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        case 'bulk_delete_presets':
            $assertCanManage();
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids)) {
                $ids = json_decode($ids, true);
            }
            if (empty($ids)) throw new Exception('No IDs provided');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM issue_presets WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            echo json_encode(['success' => true]);
            break;

        case 'get_metadata':
            if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');
            $stmt = $db->prepare("SELECT * FROM issue_metadata_fields WHERE project_type = ? ORDER BY sort_order ASC, field_label ASC");
            $stmt->execute([$projectType]);
            $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($fields as &$f) {
                $f['options'] = json_decode($f['options_json'], true);
            }
            echo json_encode(['success' => true, 'data' => $fields]);
            break;

        case 'save_metadata':
            $assertCanManage();
            $id = $_POST['id'] ?? '';
            $key = trim($_POST['field_key']);
            $label = trim($_POST['field_label']);
            $active = (int)$_POST['is_active'];
            $optionsRaw = $_POST['options'] ?? '';
            
            // Split by newline instead of comma to allow commas within values
            $options = array_filter(array_map('trim', explode("\n", $optionsRaw)));
            $optionsJson = json_encode(array_values($options));

            if (!$key || !$label) throw new Exception('Key and Label are required');

            if ($id) {
                $stmt = $db->prepare("UPDATE issue_metadata_fields SET field_label=?, options_json=?, is_active=? WHERE id=?");
                $stmt->execute([$label, $optionsJson, $active, $id]);
            } else {
                // Check unique key
                $chk = $db->prepare("SELECT id FROM issue_metadata_fields WHERE project_type=? AND field_key=?");
                $chk->execute([$projectType, $key]);
                if ($chk->fetch()) throw new Exception("Field key '$key' already exists for this project type");

                $stmt = $db->prepare("INSERT INTO issue_metadata_fields (project_type, field_key, field_label, options_json, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$projectType, $key, $label, $optionsJson, $active]);
            }
            echo json_encode(['success' => true]);
            break;
        
        case 'update_metadata_sort':
            $assertCanManage();
            $order = json_decode($_POST['order'], true);
            if (!$order || !is_array($order)) throw new Exception('Invalid order data');
            
            foreach ($order as $item) {
                $id = $item['id'];
                $sortOrder = $item['sort_order'];
                $stmt = $db->prepare("UPDATE issue_metadata_fields SET sort_order = ? WHERE id = ? AND project_type = ?");
                $stmt->execute([$sortOrder, $id, $projectType]);
            }
            echo json_encode(['success' => true]);
            break;
        
        case 'delete_metadata':
            $assertCanManage();
            $id = $_POST['id'];
            $stmt = $db->prepare("DELETE FROM issue_metadata_fields WHERE id=?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        case 'get_defaults':
            if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');
            $stmt = $db->prepare("SELECT sections_json FROM issue_default_templates WHERE project_type = ?");
            $stmt->execute([$projectType]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $sections = $res ? json_decode($res['sections_json'], true) : [];
            echo json_encode(['success' => true, 'data' => ['sections' => $sections]]);
            break;

        case 'save_defaults':
            $assertCanManage();
            $sections = $_POST['sections_json'] ?? '[]';
            
            $stmt = $db->prepare("REPLACE INTO issue_default_templates (project_type, sections_json) VALUES (?, ?)");
            $stmt->execute([$projectType, $sections]);
            echo json_encode(['success' => true]);
            break;

        case 'import_csv':
            $assertCanManage();
            if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== 0) throw new Exception('Upload error');
            
            $mapping = isset($_POST['mapping']) ? json_decode($_POST['mapping'], true) : null;
            
            $file = fopen($_FILES['csv']['tmp_name'], 'r');
            $headers = fgetcsv($file);
            if (!$headers) throw new Exception('Empty CSV');

            // Default mapping if not provided (backward compatibility)
            if (!$mapping) {
                $mapping = []; 
                foreach ($headers as $i => $h) {
                    $h = trim($h);
                    if (strcasecmp($h, 'Title') === 0 || strcasecmp($h, 'Issue') === 0) $mapping['title'] = $i;
                    elseif (strcasecmp($h, 'Description') === 0) $mapping['desc'] = $i;
                }
            }

            if (!isset($mapping['title'])) throw new Exception('Mapping must include a "title" column');

            // Get valid metadata keys
            $metaKeysStmt = $db->prepare("SELECT field_key FROM issue_metadata_fields WHERE project_type = ?");
            $metaKeysStmt->execute([$projectType]);
            $validKeysList = $metaKeysStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get default sections template
            $defaultSectionsStmt = $db->prepare("SELECT sections_json FROM issue_default_templates WHERE project_type = ?");
            $defaultSectionsStmt->execute([$projectType]);
            $defaultSectionsRow = $defaultSectionsStmt->fetch(PDO::FETCH_ASSOC);
            $defaultSections = $defaultSectionsRow ? json_decode($defaultSectionsRow['sections_json'], true) : [];

            $count = 0;
            while (($row = fgetcsv($file)) !== false) {
                $titleIdx = $mapping['title'];
                $title = trim($row[$titleIdx] ?? '');
                if (!$title) continue;

                $desc = '';
                if (isset($mapping['desc'])) {
                    $descIdx = $mapping['desc'];
                    $desc = $row[$descIdx] ?? '';
                }

                $meta = [];
                $sectionsData = []; // Store section content by title
                foreach ($mapping as $key => $idx) {
                    if ($key === 'title' || $key === 'desc' || $key === 'sections') continue;
                    
                    // Handle metadata with prefix
                    if (strpos($key, 'meta:') === 0) {
                        $metaKey = substr($key, 5);
                        if (in_array($metaKey, $validKeysList)) {
                            $val = trim($row[$idx] ?? '');
                            if ($val !== '') $meta[$metaKey] = $val;
                        }
                    }
                }

                if (isset($mapping['sections'])) {
                    $secsMap = $mapping['sections'];
                    // Collect section content from mapped columns
                    foreach ($secsMap as $secTitle => $colIdx) {
                        if ($secTitle === '_generic') {
                            foreach ($colIdx as $gi) {
                                $rawContent = trim($row[$gi] ?? '');
                                $sTitle = $headers[$gi] ?? 'Section';
                                $sectionsData[$sTitle] = $rawContent;
                            }
                        } else {
                            $rawContent = trim($row[$colIdx] ?? '');
                            $sectionsData[$secTitle] = $rawContent;
                        }
                    }
                }
                
                // Build sections HTML preserving all default sections in order
                $sectionsHtml = '';
                if (!empty($defaultSections)) {
                    foreach ($defaultSections as $sectionTitle) {
                        $content = isset($sectionsData[$sectionTitle]) ? $sectionsData[$sectionTitle] : '';
                        $sectionsHtml .= '<p style="margin-bottom:0.2rem;"><strong>[' . htmlspecialchars($sectionTitle) . ']</strong></p>';
                        if ($content !== '') {
                            $sectionsHtml .= '<p>' . nl2br(htmlspecialchars($content)) . '</p>';
                        } else {
                            $sectionsHtml .= '<p><br></p>';
                        }
                    }
                } else {
                    // Fallback: if no default template, use only mapped sections
                    foreach ($sectionsData as $secTitle => $content) {
                        if ($content === '') continue;
                        $sectionsHtml .= '<p style="margin-bottom:0.2rem;"><strong>[' . htmlspecialchars($secTitle) . ']</strong></p><p>' . nl2br(htmlspecialchars($content)) . '</p><p><br></p>';
                    }
                }

                // Append sections to description
                if ($sectionsHtml) {
                    $desc = ($desc ? $desc . '<hr>' : '') . $sectionsHtml;
                }

                $stmt = $db->prepare("INSERT INTO issue_presets (project_type, title, description_html, metadata_json) VALUES (?, ?, ?, ?)");
                $stmt->execute([$projectType, $title, $desc, json_encode($meta)]);
                $count++;
            }
            fclose($file);
            echo json_encode(['success' => true, 'imported' => $count]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }

} catch (Exception $e) {
    error_log('issue_config error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An internal error occurred']);
}
