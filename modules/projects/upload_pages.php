<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/project_permissions.php';
require_once __DIR__ . '/../../includes/excel_reader.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$db = Database::getInstance();

$projectId = (int)($_POST['project_id'] ?? 0);
if (!$projectId) {
    echo json_encode(['error' => 'project_id required']);
    exit;
}

if (!hasProjectAccess($db, $userId, $projectId)) {
    echo json_encode(['error' => 'Permission denied for this project']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

$tmp = $_FILES['file']['tmp_name'];
if (!is_uploaded_file($tmp)) {
    echo json_encode(['error' => 'Invalid upload']);
    exit;
}

// Detect file type and read data
$fileName = $_FILES['file']['name'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

$allRows = [];
if (in_array($ext, ['xlsx', 'xls', 'csv'])) {
    $result = readExcelFile($tmp, $fileName);
    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]);
        exit;
    }
    $allRows = $result['rows'];
} else {
    echo json_encode(['error' => 'Unsupported file format. Please upload CSV, XLSX, or XLS file.']);
    exit;
}

if (empty($allRows)) {
    echo json_encode(['error' => 'Empty file']);
    exit;
}

$uniqueCols = [];
$allCols = [];
$pageNumberCol = null;
$pageNameCol = null;
$screenNameCol = null;
$notesCol = null;
$groupedUrlsCol = null;

// New simplified column mapping
if (isset($_POST['unique_url_col']) && $_POST['unique_url_col'] !== '') {
    $uniqueCols = [(int)$_POST['unique_url_col']];
}
if (isset($_POST['page_number_col']) && $_POST['page_number_col'] !== '') {
    $pageNumberCol = (int)$_POST['page_number_col'];
}
if (isset($_POST['page_name_col']) && $_POST['page_name_col'] !== '') {
    $pageNameCol = (int)$_POST['page_name_col'];
}
if (isset($_POST['screen_name_col']) && $_POST['screen_name_col'] !== '') {
    $screenNameCol = (int)$_POST['screen_name_col'];
}
if (isset($_POST['notes_col']) && $_POST['notes_col'] !== '') {
    $notesCol = (int)$_POST['notes_col'];
}
if (isset($_POST['grouped_urls_col']) && $_POST['grouped_urls_col'] !== '') {
    $groupedUrlsCol = (int)$_POST['grouped_urls_col'];
}

// Backward compatibility: support old format
if (empty($uniqueCols)) {
    if (!empty($_POST['unique_cols']) && is_array($_POST['unique_cols'])) {
        $uniqueCols = array_map('intval', $_POST['unique_cols']);
    } elseif (isset($_POST['unique_col']) && $_POST['unique_col'] !== '') {
        $uniqueCols = [(int)$_POST['unique_col']];
    }
}
if (!empty($_POST['all_cols']) && is_array($_POST['all_cols'])) {
    $allCols = array_map('intval', $_POST['all_cols']);
} elseif (isset($_POST['all_col']) && $_POST['all_col'] !== '') {
    $allCols = [(int)$_POST['all_col']];
}

// Require at least one unique URL column
if (empty($uniqueCols) && empty($allCols)) {
    echo json_encode(['error' => 'At least one column mapping required (unique_url_col or unique_cols)']);
    exit;
}

$addedUnique = 0; $addedGrouped = 0;
$addedProjectPages = 0;

// Extract header and data rows
$header = array_shift($allRows);
if ($header === null) {
    echo json_encode(['error' => 'No header row found']);
    exit;
}

// Prepare statements
$findUnique = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND (url = ? OR page_name = ?) LIMIT 1');
$insertUnique = $db->prepare('INSERT INTO project_pages (project_id, page_name, url, page_number, screen_name, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
$getUniqueById = $db->prepare('SELECT id, page_name AS name, url AS canonical_url, page_number, screen_name FROM project_pages WHERE id = ? LIMIT 1');
$findProjectPageByUrl = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND url = ? LIMIT 1');
$insertProjectPage = $db->prepare('INSERT INTO project_pages (project_id, page_name, page_number, url, screen_name, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
$findGrouped = $db->prepare('SELECT id FROM grouped_urls WHERE project_id = ? AND url = ? LIMIT 1');
$insertGrouped = $db->prepare('INSERT INTO grouped_urls (project_id, unique_page_id, url, normalized_url, created_at) VALUES (?, ?, ?, ?, NOW())');

// Get current count for unique naming and page numbering
$cntStmt = $db->prepare('SELECT COUNT(*) FROM project_pages WHERE project_id = ?');
$cntStmt->execute([$projectId]);
$nextIndex = (int)$cntStmt->fetchColumn() + 1;

// Get the next page number using the same logic as the API
$maxStmt = $db->prepare("SELECT MAX(CAST(REPLACE(page_number, 'Page ', '') AS UNSIGNED)) as maxn FROM project_pages WHERE project_id = ? AND page_number LIKE 'Page %'");
$maxStmt->execute([$projectId]);
$maxRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
$nextPageNumber = (int)($maxRow['maxn'] ?? 0) + 1;

foreach ($allRows as $row) {
        // Extract values from CSV columns
        $uniqueParts = [];
        foreach ($uniqueCols as $uc) { if (isset($row[$uc])) $uniqueParts[] = trim($row[$uc]); }
        $uniqueVal = trim(implode(' ', array_filter($uniqueParts, function($v){ return $v !== ''; })));
        
        $pageNumberVal = ($pageNumberCol !== null && isset($row[$pageNumberCol])) ? trim($row[$pageNumberCol]) : '';
        $pageNameVal = ($pageNameCol !== null && isset($row[$pageNameCol])) ? trim($row[$pageNameCol]) : '';
        $screenNameVal = ($screenNameCol !== null && isset($row[$screenNameCol])) ? trim($row[$screenNameCol]) : '';
        $notesVal = ($notesCol !== null && isset($row[$notesCol])) ? trim($row[$notesCol]) : '';
        $groupedUrlsVal = ($groupedUrlsCol !== null && isset($row[$groupedUrlsCol])) ? trim($row[$groupedUrlsCol]) : '';
        
        // Build all URLs values by collecting selected columns (backward compatibility)
        $allParts = [];
        foreach ($allCols as $ac) { if (isset($row[$ac])) $allParts[] = trim($row[$ac]); }
        $allVal = trim(implode(' ', array_filter($allParts, function($v){ return $v !== ''; })));
        
        // If grouped URLs column is provided, use it; otherwise fall back to allVal or uniqueVal
        if ($groupedUrlsVal !== '') {
            $allVal = $groupedUrlsVal;
        } elseif ($allVal === '' && !empty($uniqueCols)) {
            // take the first unique col as the URL source
            $uc = (int)$uniqueCols[0];
            $allVal = isset($row[$uc]) ? trim($row[$uc]) : '';
        }

        $uniqueId = null;
        if ($uniqueVal !== '') {
            $findUnique->execute([$projectId, $uniqueVal, $uniqueVal]);
            $u = $findUnique->fetch();
            if ($u) {
                $uniqueId = (int)$u['id'];
            } else {
                // Generate page number if not provided
                $pageNumberToUse = $pageNumberVal !== '' ? $pageNumberVal : ('Page ' . $nextPageNumber++);
                // Generate name if not provided
                $nameToUse = $pageNameVal !== '' ? $pageNameVal : $pageNumberToUse;
                
                $insertUnique->execute([
                    $projectId, 
                    $nameToUse, 
                    $uniqueVal, 
                    $pageNumberToUse,
                    $screenNameVal ?: null,
                    $notesVal ?: null,
                    $userId
                ]);
                $uniqueId = (int)$db->lastInsertId();
                $addedUnique++;
            }

            // Ensure imported unique pages are also available in project page assignments.
            if ($uniqueId > 0) {
                $getUniqueById->execute([$uniqueId]);
                $uniqueRow = $getUniqueById->fetch(PDO::FETCH_ASSOC);
                if ($uniqueRow) {
                    $canonicalUrl = trim((string)($uniqueRow['canonical_url'] ?? ''));
                    $pageNameToUse = trim((string)($uniqueRow['name'] ?? ''));
                    $pageNumberToUse = trim((string)($uniqueRow['page_number'] ?? ''));
                    $screenNameToUse = trim((string)($uniqueRow['screen_name'] ?? ''));

                    if ($canonicalUrl !== '') {
                        $findProjectPageByUrl->execute([$projectId, $canonicalUrl]);
                        $existingProjectPage = $findProjectPageByUrl->fetch(PDO::FETCH_ASSOC);
                        if (!$existingProjectPage) {
                            if ($pageNameToUse === '') {
                                $pageNameToUse = $pageNumberToUse !== '' ? $pageNumberToUse : substr($canonicalUrl, 0, 120);
                            }
                            if ($pageNumberToUse === '') {
                                $pageNumberToUse = $pageNameToUse;
                            }
                            $insertProjectPage->execute([
                                $projectId,
                                $pageNameToUse,
                                $pageNumberToUse,
                                $canonicalUrl,
                                $screenNameToUse !== '' ? $screenNameToUse : null,
                                $userId
                            ]);
                            $addedProjectPages++;
                        }
                    }
                }
            }
        }

        if ($allVal !== '') {
            // split multiple urls in cell by semicolon, pipe or newline
            $parts = preg_split('/[;|\n\r]+/', $allVal);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $findGrouped->execute([$projectId, $p]);
                if ($findGrouped->fetch()) continue;
                $norm = preg_replace('#[?].*$#', '', rtrim($p, '/'));
                $norm = mb_strtolower($norm);
                $insertGrouped->execute([$projectId, $uniqueId ?: null, $p, $norm]);
                $addedGrouped++;
            }
        }
}

echo json_encode([
    'success' => true,
    'added_unique' => $addedUnique,
    'added_grouped' => $addedGrouped,
    'added_project_pages' => $addedProjectPages
]);
exit;

