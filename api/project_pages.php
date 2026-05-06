<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// CSRF protection
enforceApiCsrf();

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

function jsonRes($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

    $db->exec("DROP VIEW project_pages");
    $db->exec("RENAME TABLE project_pages_tmp_no_view TO project_pages");
    }
    */
}

// Helper: delete grouped urls by ids (ensures permission and logs activity)
function delete_grouped_ids($db, $userId, $projectId, array $idsArr) {
    if (empty($idsArr)) return 0;
    if (!hasProjectPermission($db, $userId, $projectId, 'delete_grouped_urls')) {
        jsonRes(['error' => 'Permission denied'], 403);
    }
    $in  = str_repeat('?,', count($idsArr) - 1) . '?';
    $sql = "DELETE FROM grouped_urls WHERE project_id = ? AND id IN ($in)";
    $stmt = $db->prepare($sql);
    $params = array_merge([$projectId], $idsArr);
    $stmt->execute($params);
    try { logActivity($db, $userId, 'delete_grouped_urls_bulk', 'project', $projectId, ['deleted_ids'=>$idsArr]); } catch (Exception $e) { error_log('logActivity failed: ' . $e->getMessage()); }
    return $stmt->rowCount();
}

try {
    // ensureProjectPagesTable($db);
    // Read raw input early so JSON POST bodies can provide an action
    $rawBody = @file_get_contents('php://input');
    $jsonBody = null;
    if ($rawBody) {
        $jsonBody = json_decode($rawBody, true);
        if ($jsonBody === null) $jsonBody = null; // keep null if not valid JSON
    }
    $action = $_GET['action'] ?? ($jsonBody['action'] ?? ($_POST['action'] ?? ''));
    // If action is still empty, return helpful debug info to client and log server-side
    if (empty($action)) {
        jsonRes(['error' => 'action required'], 400);
    }

    if ($method === 'GET') {
        if ($action === 'list_pages') {
            $projectId = (int)($_GET['project_id'] ?? 0);
            if (!$projectId) jsonRes(['error' => 'project_id required'], 400);

                    $stmt = $db->prepare('SELECT id, page_name AS name, page_number, url AS canonical_url, created_at FROM project_pages WHERE project_id = ? ORDER BY page_name');
                    $stmt->execute([$projectId]);
                    $rows = $stmt->fetchAll();
                    foreach ($rows as &$row) {
                        // If page_number or page_name indicates global, always show 'Global N'
                        if (
                            (isset($row['page_number']) && preg_match('/^Global\s*\d+$/i', $row['page_number'], $matches)) ||
                            (isset($row['name']) && preg_match('/^Global\s*\d+$/i', $row['name'], $matches))
                        ) {
                            // Extract N from either field
                            if (preg_match('/Global\s*(\d+)/i', $row['page_number'] ?? $row['name'], $numMatch)) {
                                $row['page_number'] = 'Global ' . $numMatch[1];
                            } else {
                                $row['page_number'] = 'Global';
                            }
                        }
                    }
                    jsonRes(['project_pages' => $rows]);
        }

        if ($action === 'list_grouped') {
            $projectId = (int)($_GET['project_id'] ?? 0);
            $uniqueId = isset($_GET['unique_page_id']) ? (int)$_GET['unique_page_id'] : null;
            if (!$projectId) jsonRes(['error' => 'project_id required'], 400);

            if ($uniqueId) {
                $stmt = $db->prepare('SELECT id, url, normalized_url, created_at FROM grouped_urls WHERE project_id = ? AND unique_page_id = ? ORDER BY created_at DESC');
                $stmt->execute([$projectId, $uniqueId]);
            } else {
                $stmt = $db->prepare('SELECT gu.id, gu.url, gu.normalized_url, gu.unique_page_id, up.page_name as unique_page_name FROM grouped_urls gu LEFT JOIN project_pages up ON gu.unique_page_id = up.id WHERE gu.project_id = ? ORDER BY gu.created_at DESC');
                $stmt->execute([$projectId]);
            }
            jsonRes(['grouped_urls' => $stmt->fetchAll()]);
        }

        jsonRes(['error' => 'action not found'], 404);
    }

    if ($method === 'POST') {
        // prefer parsed JSON body when available, otherwise fallback to form POST
        $input = $jsonBody ?: $_POST;
        
        // map unique to page (assign unique => mapped_page_id on grouped urls)
        if ($action === 'map_unique_to_page') {
            // Map a Unique Page to a Project Page by ensuring there's a grouped_urls row
            // matching the project's page URL and pointing to the unique page.
            $uniqueId = (int)($input['unique_page_id'] ?? 0);
            $pageId = isset($input['page_id']) && $input['page_id'] !== '' ? (int)$input['page_id'] : 0;
            if (!$uniqueId) jsonRes(['error'=>'unique_page_id required'], 400);
            $u = $db->prepare('SELECT * FROM project_pages WHERE id = ? LIMIT 1');
            $u->execute([$uniqueId]);
            $up = $u->fetch(PDO::FETCH_ASSOC);
            if (!$up) jsonRes(['error'=>'unique not found'],404);
            $projectId = (int)$up['project_id'];
            // permission: admin/admin/project_lead
            $role = $_SESSION['role'] ?? '';
            $userId = $_SESSION['user_id'] ?? 0;
            if (!in_array($role, ['admin','admin','project_lead'])) jsonRes(['error' => 'Permission denied'], 403);
            if ($role === 'project_lead') {
                $p = $db->prepare('SELECT project_lead_id FROM projects WHERE id = ? LIMIT 1');
                $p->execute([$projectId]);
                $prow = $p->fetch(PDO::FETCH_ASSOC);
                if (!$prow || (int)$prow['project_lead_id'] !== (int)$userId) jsonRes(['error' => 'Permission denied'], 403);
            }

            if ($pageId) {
                // ensure page belongs to project
                $pp = $db->prepare('SELECT id, project_id, url FROM project_pages WHERE id = ? LIMIT 1');
                $pp->execute([$pageId]);
                $prow = $pp->fetch(PDO::FETCH_ASSOC);
                if (!$prow || (int)$prow['project_id'] !== $projectId) jsonRes(['error'=>'page invalid'],400);
                $pageUrl = $prow['url'];
                $norm = normalizeUrlForGrouping($pageUrl);
                // if a grouped_urls row exists for this project and url, update its unique_page_id; otherwise insert
                $find = $db->prepare('SELECT id FROM grouped_urls WHERE project_id = ? AND (url = ? OR normalized_url = ?) LIMIT 1');
                $find->execute([$projectId, $pageUrl, $norm]);
                $frow = $find->fetch(PDO::FETCH_ASSOC);
                if ($frow) {
                    $upd = $db->prepare('UPDATE grouped_urls SET unique_page_id = ? WHERE id = ?');
                    $upd->execute([$uniqueId, $frow['id']]);
                } else {
                    $ins = $db->prepare('INSERT INTO grouped_urls (project_id, unique_page_id, url, normalized_url, created_at) VALUES (?, ?, ?, ?, NOW())');
                    $ins->execute([$projectId, $uniqueId, $pageUrl, $norm]);
                }
            } else {
                // unassign: remove unique_page_id association for grouped_urls that match any project page URL for this project
                // Prefer to only clear rows that match project page URLs to avoid removing user-provided grouped urls
                $pages = $db->prepare('SELECT url FROM project_pages WHERE project_id = ?');
                $pages->execute([$projectId]);
                $urls = $pages->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($urls)) {
                    $in = str_repeat('?,', count($urls)-1) . '?';
                    $params = array_merge([$projectId, $uniqueId], $urls);
                    $sql = 'UPDATE grouped_urls SET unique_page_id = NULL WHERE project_id = ? AND unique_page_id = ? AND url IN (' . $in . ')';
                    $upd = $db->prepare($sql);
                    $upd->execute($params);
                }
            }
            jsonRes(['success'=>true]);
        }
        // Update page name (project_pages only; unique_page_id is treated as project_pages.id)
        if ($action === 'update_page_name') {
            $uniqueId = isset($input['unique_page_id']) ? (int)$input['unique_page_id'] : 0;
            $pageId = isset($input['page_id']) && $input['page_id'] !== '' ? (int)$input['page_id'] : 0;
            $newName = trim($input['page_name'] ?? '');
            $field = trim($input['field'] ?? 'page_name');
            
            // Debug logging for troubleshooting
            error_log("update_page_name: uniqueId=$uniqueId, pageId=$pageId, field=$field, newName='$newName'");
            
            if (!$uniqueId && !$pageId) jsonRes(['error' => 'unique_page_id or page_id required'], 400);
            if (!in_array($field, ['page_name', 'canonical_url', 'notes', 'page_number'], true)) jsonRes(['error' => 'invalid field'], 400);
            
            // For page_number field, allow empty values (it can be cleared)
            // For other fields except notes, require non-empty values
            if (!in_array($field, ['notes', 'page_number']) && $newName === '') {
                jsonRes(['error' => 'value required'], 400);
            }

            // determine project id
            if ($pageId) {
                $pstmt = $db->prepare('SELECT project_id FROM project_pages WHERE id = ? LIMIT 1');
                $pstmt->execute([$pageId]);
                $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
                if (!$prow) jsonRes(['error' => 'page not found'], 404);
                $projectId = (int)$prow['project_id'];
            } else {
                $u = $db->prepare('SELECT project_id, page_name, url FROM project_pages WHERE id = ? LIMIT 1');
                $u->execute([$uniqueId]);
                $ur = $u->fetch(PDO::FETCH_ASSOC);
                if (!$ur) jsonRes(['error' => 'unique page not found'], 404);
                $projectId = (int)$ur['project_id'];
                $uniqueName = $ur['page_name'] ?? '';
                $uniqueCanonical = $ur['url'] ?? null;
            }

            if (!hasProjectAccess($db, $_SESSION['user_id'], $projectId)) jsonRes(['error' => 'Permission denied'], 403);

            // (no-op) removed temporary debug file writes

            // If updating an existing project page
            if ($pageId) {
                if ($field === 'notes') {
                    $upd = $db->prepare('UPDATE project_pages SET notes = ?, updated_at = NOW() WHERE id = ?');
                } elseif ($field === 'canonical_url') {
                    $upd = $db->prepare('UPDATE project_pages SET url = ?, updated_at = NOW() WHERE id = ?');
                } elseif ($field === 'page_number') {
                    $upd = $db->prepare('UPDATE project_pages SET page_number = ?, updated_at = NOW() WHERE id = ?');
                } else {
                    $upd = $db->prepare('UPDATE project_pages SET page_name = ?, updated_at = NOW() WHERE id = ?');
                }
                $upd->execute([$newName, $pageId]);
                try { logActivity($db, $_SESSION['user_id'], 'update_page_name', 'page', $pageId, [$field=>$newName]); } catch (Exception $e) {}
                jsonRes(['success' => true, 'page_id' => $pageId, $field => $newName]);
            }

            // For a unique: if unique uses generated Page N, try to find an existing project_pages by page_number
            if ($field === 'page_name' && preg_match('/^Page\s+\d+/i', $uniqueName)) {
                $pageNumberLabel = $uniqueName;
                $ppFind = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND page_number = ? LIMIT 1');
                $ppFind->execute([$projectId, $pageNumberLabel]);
                $ppf = $ppFind->fetch(PDO::FETCH_ASSOC);
                if ($ppf && !empty($ppf['id'])) {
                    $existingId = (int)$ppf['id'];
                    $updExist = $db->prepare('UPDATE project_pages SET page_name = ?, updated_at = NOW() WHERE id = ?');
                    $updExist->execute([$newName, $existingId]);
                    try { logActivity($db, $_SESSION['user_id'], 'update_page_name', 'page', $existingId, [$field=>$newName]); } catch (Exception $e) {}
                    jsonRes(['success' => true, 'page_id' => $existingId, 'page_number' => $pageNumberLabel, $field => $newName]);
                }
            }

            // Otherwise create a new project_page for this unique
            if ($field === 'page_name') {
                $create = $db->prepare('INSERT INTO project_pages (project_id, page_name, page_number, url, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
                $pageNumberLabel = preg_match('/^Page\s+\d+/i', $uniqueName) ? $uniqueName : null;
                $create->execute([$projectId, $newName, $pageNumberLabel, $uniqueCanonical ?: null, $_SESSION['user_id']]);
                $createdId = (int)$db->lastInsertId();
                // link grouped_urls if possible
                try {
                    $linkUrl = $uniqueCanonical ?: null;
                    if ($linkUrl) {
                        $norm = normalizeUrlForGrouping($linkUrl);
                        $chk = $db->prepare('SELECT id FROM grouped_urls WHERE project_id = ? AND (url = ? OR normalized_url = ?) LIMIT 1');
                        $chk->execute([$projectId, $linkUrl, $norm]);
                        $found = $chk->fetch(PDO::FETCH_ASSOC);
                        if (!$found) {
                            $insG = $db->prepare('INSERT INTO grouped_urls (project_id, unique_page_id, url, normalized_url, created_at) VALUES (?, ?, ?, ?, NOW())');
                            $insG->execute([$projectId, $uniqueId, $linkUrl, $norm]);
                        }
                    }
                } catch (Exception $e) {
                    error_log('project_pages: grouped_urls link failed: ' . $e->getMessage());
                }
                try { logActivity($db, $_SESSION['user_id'], 'create_project_page_from_unique', 'project', $projectId, ['unique_id'=>$uniqueId, 'page_id'=>$createdId, 'page_name'=>$newName]); } catch (Exception $e) {}
                jsonRes(['success' => true, 'created_page_id' => $createdId, 'page_number' => $pageNumberLabel, 'page_name' => $newName]);
            }

            // For non-page_name fields on unique (notes/page_name) in project_pages
            if ($field === 'notes') {
                $upd = $db->prepare('UPDATE project_pages SET notes = ?, updated_at = NOW() WHERE id = ?');
            } elseif ($field === 'canonical_url') {
                $upd = $db->prepare('UPDATE project_pages SET url = ?, updated_at = NOW() WHERE id = ?');
            } else {
                $upd = $db->prepare('UPDATE project_pages SET page_name = ?, updated_at = NOW() WHERE id = ?');
            }
            $upd->execute([$newName, $uniqueId]);
            try { logActivity($db, $_SESSION['user_id'], 'update_unique_name', 'project', $projectId, ['unique_id'=>$uniqueId, $field=>$newName]); } catch (Exception $e) {}
            jsonRes(['success' => true, 'unique_id' => $uniqueId, $field => $newName]);
        }
        if ($action === 'create_unique') {
            $projectId = (int)($input['project_id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $canonical = trim($input['canonical_url'] ?? '');
            if (!$projectId) jsonRes(['error' => 'project_id required'], 400);

                // Always set page_number to 'Global N' if 'GLOBAL' is selected, else normal logic
                $requestedPageNumber = trim((string)($input['page_number'] ?? ''));
                if (strtoupper($requestedPageNumber) === 'GLOBAL') {
                    // Find next Global N for this project
                    $gStmt = $db->prepare("SELECT MAX(CAST(REPLACE(page_number, 'Global ', '') AS UNSIGNED)) as maxg FROM project_pages WHERE project_id = ? AND page_number LIKE 'Global %'");
                    $gStmt->execute([$projectId]);
                    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
                    $nextG = (int)($gRow['maxg'] ?? 0) + 1;
                    $pageLabel = 'Global ' . $nextG;
                } else if ($requestedPageNumber !== '' && strtoupper($requestedPageNumber) !== 'GLOBAL') {
                    // accept any provided label (caller is trusted to provide sensible value)
                    $pageLabel = $requestedPageNumber;
                } else {
                    // Generate the next page number
                    $maxStmt = $db->prepare("SELECT MAX(CAST(REPLACE(page_number, 'Page ', '') AS UNSIGNED)) as maxn FROM project_pages WHERE project_id = ? AND page_number LIKE 'Page %'");
                    $maxStmt->execute([$projectId]);
                    $maxRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
                    $nextN = (int)($maxRow['maxn'] ?? 0) + 1;
                    $pageLabel = 'Page ' . $nextN;
                }

            // If no name provided, use the chosen/generated page number
            if ($name === '') {
                $name = $pageLabel;
            }

            $saveProjectId = $projectId;

            // Check for existing page with same name or URL in the same project
            $checkStmt = $db->prepare("SELECT id FROM project_pages WHERE project_id = ? AND (page_name = ? OR (url IS NOT NULL AND url = ?)) LIMIT 1");
            $checkStmt->execute([$projectId, $name, $canonical ?: null]);
            if ($checkStmt->fetch()) {
                jsonRes(['error' => 'A page with this name or URL already exists in this project.'], 409);
            }

            try {
                $ins = $db->prepare('INSERT INTO project_pages (project_id, page_name, page_number, url, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
                $ins->execute([$saveProjectId, $name, $pageLabel, $canonical ?: null, $_SESSION['user_id'] ?? null]);
                $id = (int)$db->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    jsonRes(['error' => 'A page with this name already exists in the project.'], 409);
                }
                throw $e;
            }

            jsonRes(['success' => true, 'id' => $id, 'page_number_label' => $pageLabel, 'created_page_id' => $id], 201);
        }

        if ($action === 'map_url') {
            $projectId = (int)($input['project_id'] ?? 0);
            $uniqueId = isset($input['unique_page_id']) ? (int)$input['unique_page_id'] : null;
            $url = trim($input['url'] ?? '');
            if (!$projectId || $url === '') jsonRes(['error' => 'project_id and url required'], 400);

            $norm = normalizeUrlForGrouping($url);
            $ins = $db->prepare('INSERT INTO grouped_urls (project_id, unique_page_id, url, normalized_url, created_at) VALUES (?, ?, ?, ?, NOW())');
            $ins->execute([$projectId, $uniqueId ?: null, $url, $norm]);
            $id = (int)$db->lastInsertId();
            jsonRes(['success' => true, 'id' => $id], 201);
        }

        if ($action === 'assign_bulk') {
            // bulk assign or unassign grouped urls to/from a unique page
            $projectId = (int)($input['project_id'] ?? 0);
            // allow explicit null/0 to mean unassign
            $uniqueIdRaw = $input['unique_page_id'] ?? null;
            $uniqueId = is_null($uniqueIdRaw) ? null : (int)$uniqueIdRaw;
            $ids = $input['grouped_ids'] ?? [];
            if (!$projectId || !is_array($ids)) jsonRes(['error' => 'project_id and grouped_ids[] required'], 400);

            if (!hasProjectAccess($db, $_SESSION['user_id'], $projectId)) jsonRes(['error' => 'Permission denied'], 403);

            if ($uniqueId === null) jsonRes(['error' => 'unique_page_id required (use 0 to unassign)'], 400);

            if ($uniqueId > 0) {
                $upd = $db->prepare('UPDATE grouped_urls SET unique_page_id = ? WHERE project_id = ? AND id = ?');
                foreach ($ids as $gid) {
                    $upd->execute([$uniqueId, $projectId, (int)$gid]);
                }
            } else {
                // unassign: set unique_page_id to NULL
                $upd = $db->prepare('UPDATE grouped_urls SET unique_page_id = NULL WHERE project_id = ? AND id = ?');
                foreach ($ids as $gid) {
                    $upd->execute([$projectId, (int)$gid]);
                }
            }
            jsonRes(['success' => true]);
        }

        // Accept bulk grouped delete via POST as well (some environments strip DELETE bodies)
        if ($action === 'remove_grouped_bulk') {
            $projectId = (int)($input['project_id'] ?? 0);
            $ids = $input['ids'] ?? null;
            if (!$projectId || !$ids) jsonRes(['error' => 'project_id and ids required'], 400);
            if (is_string($ids)) {
                $idsArr = array_filter(array_map('intval', explode(',', $ids)), function($v){ return $v>0; });
            } elseif (is_array($ids)) {
                $idsArr = array_values(array_filter(array_map('intval', $ids), function($v){ return $v>0; }));
            } else {
                jsonRes(['error' => 'invalid ids format'], 400);
            }
            if (empty($idsArr)) jsonRes(['error' => 'no valid ids provided', 'received' => ['project_id'=>$projectId, 'ids'=>$ids, 'input'=>$input]], 400);
            $deleted = delete_grouped_ids($db, $_SESSION['user_id'], $projectId, $idsArr);
            jsonRes(['success' => true, 'deleted' => $deleted]);
        }

        if ($action === 'run_unique_test') {
            $uniqueId = (int)($input['unique_page_id'] ?? 0);
            $status = $input['status'] ?? '';
            $envIds = [];
            if (!empty($input['environment_id'])) {
                if (is_array($input['environment_id'])) $envIds = array_map('intval', $input['environment_id']);
                else $envIds = [(int)$input['environment_id']];
            }
            $envIds = array_values(array_filter($envIds, function($v){ return $v>0; }));

            if (!$uniqueId || !$status || empty($envIds)) jsonRes(['error' => 'unique_page_id, status and environment_id required'], 400);

            // fetch unique page and grouped urls
            $uStmt = $db->prepare('SELECT * FROM project_pages WHERE id = ? LIMIT 1');
            $uStmt->execute([$uniqueId]);
            $unique = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$unique) jsonRes(['error' => 'unique page not found'], 404);

            $projectId = (int)$unique['project_id'];
            // permission: must have access to project
            if (!hasProjectAccess($db, $_SESSION['user_id'], $projectId)) jsonRes(['error' => 'Permission denied'], 403);

            $gStmt = $db->prepare('SELECT * FROM grouped_urls WHERE project_id = ? AND unique_page_id = ?');
            $gStmt->execute([$projectId, $uniqueId]);
            $grouped = $gStmt->fetchAll(PDO::FETCH_ASSOC);

            $createdPages = 0; $updatedEnvs = 0; $insertedResults = 0;

            $db->beginTransaction();
            try {
                $findPage = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND url = ? LIMIT 1');
                $createPage = $db->prepare('INSERT INTO project_pages (project_id, page_name, url, created_by, created_at) VALUES (?, ?, ?, ?, NOW())');
                $selectEnv = $db->prepare('SELECT * FROM page_environments WHERE page_id = ? AND environment_id = ?');
                $insertEnv = $db->prepare('INSERT INTO page_environments (page_id, environment_id) VALUES (?, ?)');
                $updateEnvStatus = $db->prepare('UPDATE page_environments SET status = ? WHERE page_id = ? AND environment_id = ?');
                $updateEnvQa = $db->prepare('UPDATE page_environments SET qa_status = ? WHERE page_id = ? AND environment_id = ?');
                $insResult = $db->prepare('INSERT INTO testing_results (page_id, environment_id, tester_id, tester_role, status, issues_found, comments, hours_spent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

                // infer tester type from session role
                $testerType = '';
                $role = $_SESSION['role'] ?? '';
                if ($role === 'at_tester') $testerType = 'at';
                elseif ($role === 'ft_tester') $testerType = 'ft';
                elseif ($role === 'qa') $testerType = 'qa';
                else $testerType = ($input['tester_type'] ?? 'at');

                $testerRole = ($testerType === 'at') ? 'at_tester' : (($testerType === 'ft') ? 'ft_tester' : 'at_tester');

                $issuesFound = isset($input['issues_found']) ? (int)$input['issues_found'] : 0;
                $comments = isset($input['comments']) ? $input['comments'] : '';
                $hours = isset($input['hours_spent']) ? floatval($input['hours_spent']) : 0;

                $idx = 1;
                foreach ($grouped as $g) {
                    // find or create project_page
                    $findPage->execute([$projectId, $g['url']]);
                    $p = $findPage->fetch(PDO::FETCH_ASSOC);
                    if ($p) {
                        $pageId = (int)$p['id'];
                    } else {
                        $pname = trim($unique['page_name']) ?: ('Page ' . ($idx));
                        $createPage->execute([$projectId, $pname, $g['url'], $_SESSION['user_id']]);
                        $pageId = (int)$db->lastInsertId();
                        $createdPages++;
                    }

                    foreach ($envIds as $envId) {
                        // ensure page_environments exists
                        $selectEnv->execute([$pageId, $envId]);
                        $envRow = $selectEnv->fetch(PDO::FETCH_ASSOC);
                        if (!$envRow) {
                            $insertEnv->execute([$pageId, $envId]);
                        }

                        // update environment status column
                        if ($testerType === 'qa') {
                            $updateEnvQa->execute([$status, $pageId, $envId]);
                        } else {
                            $updateEnvStatus->execute([$status, $pageId, $envId]);
                        }
                        $updatedEnvs++;

                        // insert testing_results for at/ft testers
                        if ($testerType !== 'qa') {
                            $insResult->execute([$pageId, $envId, $_SESSION['user_id'], $testerRole, $status, $issuesFound, $comments, $hours]);
                            $insertedResults++;
                        }
                    }

                    // recompute page status
                    $pageStmt = $db->prepare('SELECT * FROM project_pages WHERE id = ?');
                    $pageStmt->execute([$pageId]);
                    $pageData = $pageStmt->fetch(PDO::FETCH_ASSOC);
                    if ($pageData) {
                        $newGlobal = computePageStatus($db, $pageData);
                        $map = function($s){
                            $m = ['testing_failed'=>'in_fixing','qa_failed'=>'in_fixing','in_testing'=>'in_progress','tested'=>'needs_review','qa_review'=>'qa_in_progress','not_tested'=>'not_started','on_hold'=>'on_hold','completed'=>'completed','in_progress'=>'in_progress','in_fixing'=>'in_fixing','needs_review'=>'needs_review','qa_in_progress'=>'qa_in_progress','not_started'=>'not_started','pass'=>'qa_in_progress','fail'=>'in_fixing'];
                            return $m[$s] ?? 'in_progress';
                        };
                        $updatePage = $db->prepare('UPDATE project_pages SET status = ?, updated_at = NOW() WHERE id = ?');
                        $updatePage->execute([$map($newGlobal), $pageId]);
                    }
                    $idx++;
                }

                $db->commit();
                jsonRes(['success'=>true,'created_pages'=>$createdPages,'updated_envs'=>$updatedEnvs,'inserted_results'=>$insertedResults]);
            } catch (Exception $ex) {
                if ($db->inTransaction()) $db->rollBack();
                jsonRes(['error'=>'Failed to run tests'],500);
            }
        }

        jsonRes(['error' => 'action not found'], 404);
    }

    if ($method === 'DELETE') {
        // php://input can only be read once; reuse $rawBody already read above
        parse_str($rawBody ?? '', $delVars);
        if ($action === 'remove_grouped') {
            $id = (int)($delVars['id'] ?? 0);
            if (!$id) jsonRes(['error' => 'id required'], 400);
            $g = $db->prepare('SELECT project_id FROM grouped_urls WHERE id = ? LIMIT 1');
            $g->execute([$id]);
            $grow = $g->fetch(PDO::FETCH_ASSOC);
            if (!$grow) jsonRes(['error' => 'grouped url not found'], 404);
            $projectId = (int)$grow['project_id'];
            $deleted = delete_grouped_ids($db, $_SESSION['user_id'], $projectId, [$id]);
            if ($deleted) jsonRes(['success' => true]);
            jsonRes(['error' => 'delete failed'], 500);
        }
        // bulk delete grouped URLs (project-wise) - accept comma/array via DELETE body
        if ($action === 'remove_grouped_bulk') {
            $projectId = (int)($delVars['project_id'] ?? 0);
            $ids = $delVars['ids'] ?? null;
            if (!$projectId || !$ids) jsonRes(['error' => 'project_id and ids required'], 400);
            // ids could be comma separated or array-like
            if (is_string($ids)) {
                $idsArr = array_filter(array_map('intval', explode(',', $ids)), function($v){ return $v>0; });
            } elseif (is_array($ids)) {
                $idsArr = array_values(array_filter(array_map('intval', $ids), function($v){ return $v>0; }));
            } else {
                jsonRes(['error' => 'invalid ids format'], 400);
            }
            if (empty($idsArr)) jsonRes(['error' => 'no valid ids provided', 'received' => ['project_id'=>$projectId, 'ids'=>$ids, 'delVars'=>$delVars]], 400);
            if (!hasProjectPermission($db, $_SESSION['user_id'], $projectId, 'delete_grouped_urls')) jsonRes(['error' => 'Permission denied'], 403);
            $in  = str_repeat('?,', count($idsArr) - 1) . '?';
            $sql = "DELETE FROM grouped_urls WHERE project_id = ? AND id IN ($in)";
            $stmt = $db->prepare($sql);
            $params = array_merge([$projectId], $idsArr);
            $stmt->execute($params);
            // log
            try { logActivity($db, $_SESSION['user_id'], 'delete_grouped_urls_bulk', 'project', $projectId, ['deleted_ids'=>$idsArr]); } catch (Exception $e) {}
            jsonRes(['success' => true, 'deleted' => $stmt->rowCount()]);
        }
        if ($action === 'delete_unique') {
            $id = (int)($delVars['id'] ?? 0);
            $reassignTo = isset($delVars['reassign_to']) ? (int)$delVars['reassign_to'] : 0;
            $removeGrouped = isset($delVars['remove_grouped']) ? (int)$delVars['remove_grouped'] : 0;
            if (!$id) jsonRes(['error' => 'id required'], 400);
            // fetch unique page to verify project and permissions
            $u = $db->prepare('SELECT * FROM project_pages WHERE id = ? LIMIT 1');
            $u->execute([$id]);
            $up = $u->fetch(PDO::FETCH_ASSOC);
            if (!$up) jsonRes(['error' => 'unique page not found'], 404);
            $projectId = (int)$up['project_id'];
            $userId = $_SESSION['user_id'] ?? 0;
            if (!hasProjectPermission($db, $userId, $projectId, 'pages_assign')) jsonRes(['error' => 'Permission denied'], 403);

            $db->beginTransaction();
            try {
                // Find any project_pages that might be associated with this unique page
                $pageIds = [];
                // 1) via grouped_urls url mapping
                $associatedPages = $db->prepare('SELECT pp.id FROM project_pages pp JOIN grouped_urls gu ON pp.url = gu.url WHERE gu.unique_page_id = ? AND gu.project_id = ?');
                $associatedPages->execute([$id, $projectId]);
                $pageIds = array_merge($pageIds, $associatedPages->fetchAll(PDO::FETCH_COLUMN));
                // 2) via canonical_url match
                if (!empty($up['url'])) {
                    $byUrl = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND url = ?');
                    $byUrl->execute([$projectId, $up['url']]);
                    $pageIds = array_merge($pageIds, $byUrl->fetchAll(PDO::FETCH_COLUMN));
                }
                // 3) via page_number match (when created as "Page N")
                if (!empty($up['page_name']) && preg_match('/^Page\\s+\\d+/i', $up['page_name'])) {
                    $byPageNumber = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND page_number = ?');
                    $byPageNumber->execute([$projectId, $up['page_name']]);
                    $pageIds = array_merge($pageIds, $byPageNumber->fetchAll(PDO::FETCH_COLUMN));
                }
                $pageIds = array_values(array_unique(array_map('intval', $pageIds)));

                // Clean up associated project pages completely
                foreach ($pageIds as $pageId) {
                    // Remove page environments
                    $delEnv = $db->prepare("DELETE FROM page_environments WHERE page_id = ?");
                    $delEnv->execute([$pageId]);

                    // Remove testing results
                    $delTestResults = $db->prepare("DELETE FROM testing_results WHERE page_id = ?");
                    $delTestResults->execute([$pageId]);

                    // Remove QA results
                    $delQaResults = $db->prepare("DELETE FROM qa_results WHERE page_id = ?");
                    $delQaResults->execute([$pageId]);

                    // Remove assignments
                    $delAssignments = $db->prepare("DELETE FROM assignments WHERE page_id = ?");
                    $delAssignments->execute([$pageId]);

                    // Remove the project page
                    $delProjectPage = $db->prepare("DELETE FROM project_pages WHERE id = ?");
                    $delProjectPage->execute([$pageId]);
                }

                // handle grouped urls: remove, reassign, or unassign (null)
                if ($removeGrouped) {
                    $delG = $db->prepare('DELETE FROM grouped_urls WHERE project_id = ? AND unique_page_id = ?');
                    $delG->execute([$projectId, $id]);
                } elseif ($reassignTo && $reassignTo !== $id) {
                    // validate target unique belongs to same project
                    $t = $db->prepare('SELECT id, project_id FROM project_pages WHERE id = ? LIMIT 1');
                    $t->execute([$reassignTo]);
                    $trow = $t->fetch(PDO::FETCH_ASSOC);
                    if (!$trow || (int)$trow['project_id'] !== $projectId) jsonRes(['error' => 'reassign target invalid'], 400);
                    $updG = $db->prepare('UPDATE grouped_urls SET unique_page_id = ? WHERE project_id = ? AND unique_page_id = ?');
                    $updG->execute([$reassignTo, $projectId, $id]);
                } else {
                    // default: unassign grouped urls
                    $updNull = $db->prepare('UPDATE grouped_urls SET unique_page_id = NULL WHERE project_id = ? AND unique_page_id = ?');
                    $updNull->execute([$projectId, $id]);
                }

                // delete the page entry
                $del = $db->prepare('DELETE FROM project_pages WHERE id = ? LIMIT 1');
                $del->execute([$id]);

                $db->commit();
                
                // log activity
                try {
                    logActivity($db, $userId, 'deleted_page', 'page', $id, [
                        'page_name' => $up['page_name'] ?? ($up['name'] ?? ''),
                        'page_number' => $up['page_number'] ?? '',
                        'project_id' => $projectId
                    ]);
                } catch (Exception $e) {}

                jsonRes(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonRes(['error' => 'Failed to delete unique page'], 500);
            }
        }
        // bulk delete unique pages
        if ($action === 'remove_unique_bulk') {
            $idsRaw = $delVars['ids'] ?? null;
            if (!$idsRaw) jsonRes(['error' => 'ids required'], 400);
            if (is_string($idsRaw)) {
                $idsArr = array_filter(array_map('intval', explode(',', $idsRaw)), function($v){ return $v>0; });
            } elseif (is_array($idsRaw)) {
                $idsArr = array_values(array_filter(array_map('intval', $idsRaw), function($v){ return $v>0; }));
            } else jsonRes(['error'=>'invalid ids format'],400);
            if (empty($idsArr)) jsonRes(['error' => 'no valid ids provided'], 400);

            // fetch uniques and ensure same project
            $in = str_repeat('?,', count($idsArr)-1) . '?';
            $stmt = $db->prepare("SELECT id, project_id FROM project_pages WHERE id IN ($in)");
            $stmt->execute($idsArr);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($idsArr)) jsonRes(['error'=>'some ids not found'], 404);
            $projectIds = array_unique(array_column($rows, 'project_id'));
            if (count($projectIds) !== 1) jsonRes(['error'=>'items belong to multiple projects'], 400);
            $projectId = (int)$projectIds[0];

            $userId = $_SESSION['user_id'] ?? 0;
            if (!hasProjectPermission($db, $userId, $projectId, 'pages_assign')) jsonRes(['error' => 'Permission denied'], 403);

            $db->beginTransaction();
            try {
                // Find all associated project pages
                $pageIds = [];
                $associatedPagesStmt = $db->prepare("SELECT DISTINCT pp.id FROM project_pages pp JOIN grouped_urls gu ON pp.url = gu.url WHERE gu.unique_page_id IN ($in) AND gu.project_id = ?");
                $params = array_merge($idsArr, [$projectId]);
                $associatedPagesStmt->execute($params);
                $pageIds = array_merge($pageIds, $associatedPagesStmt->fetchAll(PDO::FETCH_COLUMN));

                // Also collect pages by canonical_url and page_number for each unique
                $uStmt = $db->prepare("SELECT id, page_name, url FROM project_pages WHERE id IN ($in)");
                $uStmt->execute($idsArr);
                $uRows = $uStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($uRows as $urow) {
                    if (!empty($urow['url'])) {
                        $byUrl = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND url = ?');
                        $byUrl->execute([$projectId, $urow['url']]);
                        $pageIds = array_merge($pageIds, $byUrl->fetchAll(PDO::FETCH_COLUMN));
                    }
                    if (!empty($urow['page_name']) && preg_match('/^Page\\s+\\d+/i', $urow['page_name'])) {
                        $byPageNumber = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND page_number = ?');
                        $byPageNumber->execute([$projectId, $urow['page_name']]);
                        $pageIds = array_merge($pageIds, $byPageNumber->fetchAll(PDO::FETCH_COLUMN));
                    }
                }
                $pageIds = array_values(array_unique(array_map('intval', $pageIds)));

                // Clean up all associated project pages completely
                foreach ($pageIds as $pageId) {
                    // Remove page environments
                    $delEnv = $db->prepare("DELETE FROM page_environments WHERE page_id = ?");
                    $delEnv->execute([$pageId]);

                    // Remove testing results
                    $delTestResults = $db->prepare("DELETE FROM testing_results WHERE page_id = ?");
                    $delTestResults->execute([$pageId]);

                    // Remove QA results
                    $delQaResults = $db->prepare("DELETE FROM qa_results WHERE page_id = ?");
                    $delQaResults->execute([$pageId]);

                    // Remove assignments
                    $delAssignments = $db->prepare("DELETE FROM assignments WHERE page_id = ?");
                    $delAssignments->execute([$pageId]);

                    // Remove the project page
                    $delProjectPage = $db->prepare("DELETE FROM project_pages WHERE id = ?");
                    $delProjectPage->execute([$pageId]);
                }

                // unassign grouped urls referencing these uniques
                $upd = $db->prepare('UPDATE grouped_urls SET unique_page_id = NULL WHERE project_id = ? AND unique_page_id IN (' . $in . ')');
                $params = array_merge([$projectId], $idsArr);
                $upd->execute($params);

                // delete pages
                $del = $db->prepare('DELETE FROM project_pages WHERE id IN (' . $in . ')');
                $del->execute($idsArr);

                $db->commit();

                try { logActivity($db, $userId, 'delete_unique_bulk', 'project', $projectId, ['deleted_ids'=>$idsArr]); } catch (Exception $e) {}
                jsonRes(['success'=>true,'deleted'=>count($idsArr)]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonRes(['error' => 'Failed to delete unique pages'], 500);
            }
        }
        jsonRes(['error' => 'action not found'], 404);
    }

    jsonRes(['error' => 'method not allowed'], 405);

} catch (PDOException $e) {
    error_log('project_pages API error: ' . $e->getMessage());
    jsonRes(['error' => 'A database error occurred'], 500);
}

function normalizeUrlForGrouping($u) {
    $u = trim((string)$u);
    $u = preg_replace('#[?].*$#', '', $u); // strip query
    $u = rtrim($u, '/');
    return mb_strtolower($u);
}

