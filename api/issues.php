<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';
require_once __DIR__ . '/../includes/models/AuditLogger.php';
require_once __DIR__ . '/../includes/api_issues_helpers.php';
require_once __DIR__ . '/../includes/client_issue_snapshots.php';
ob_end_clean();

try {
    $db = Database::getInstance();
    
    $db->setAttribute(PDO::ATTR_TIMEOUT, 30);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Auto-patch strict database enum constraints to avoid truncation errors
    try {
        $db->exec("ALTER TABLE issues MODIFY COLUMN severity VARCHAR(50) NOT NULL DEFAULT 'Medium'");
        $db->exec("ALTER TABLE issues MODIFY COLUMN priority VARCHAR(50) NOT NULL DEFAULT 'Medium'");
    } catch (Exception $e) { }
    
} catch (Exception $e) {
    error_log("Issues API: Database connection failed: " . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Database connection failed', 'message' => 'Please try again later'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);

// Handle health check requests
if ($action === 'health_check' && isset($_SERVER['HTTP_X_HEALTH_CHECK'])) {
    http_response_code(200);
    echo json_encode(['status' => 'healthy', 'timestamp' => time()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$projectId) {
    jsonError('project_id is required', 400);
}

$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? '';
$normalizedUserRole = strtolower(trim((string)$userRole));
$isTesterRole = in_array($normalizedUserRole, ['at_tester', 'ft_tester'], true) || (strpos($normalizedUserRole, 'tester') !== false);

function getIssueStatusNameById($db, $statusId) {
    static $cache = [];
    $statusId = (int)$statusId;
    if ($statusId <= 0) return '';
    if (array_key_exists($statusId, $cache)) return $cache[$statusId];

    $stmt = $db->prepare("SELECT name FROM issue_statuses WHERE id = ? LIMIT 1");
    $stmt->execute([$statusId]);
    $cache[$statusId] = (string)($stmt->fetchColumn() ?: '');
    return $cache[$statusId];
}

function resolveIssueStatusDisplayValue($db, $metaStatusValue, $statusName = '', $statusId = 0) {
    $rawValue = trim((string)$metaStatusValue);

    if ($rawValue !== '') {
        if (ctype_digit($rawValue)) {
            $resolvedName = getIssueStatusNameById($db, (int)$rawValue);
            if ($resolvedName !== '') {
                return $resolvedName;
            }
        }
        return $rawValue;
    }

    $statusName = trim((string)$statusName);
    if ($statusName !== '') {
        return $statusName;
    }

    if ((int)$statusId > 0) {
        $resolvedName = getIssueStatusNameById($db, (int)$statusId);
        if ($resolvedName !== '') {
            return $resolvedName;
        }
    }

    return 'open';
}

function isClientEditableIssueStatusValue($value) {
    global $db;
    static $allowed = null;

    if ($allowed === null) {
        $allowed = [];
        foreach (getIssueStatusesForRole($db, 'client') as $statusRow) {
            $allowed[] = normalizeIssueStatusValue($statusRow['name'] ?? '');
        }
    }

    return in_array(normalizeIssueStatusValue($value), $allowed, true);
}

function isResolvedIssueStatusValue($value) {
    return in_array(normalizeIssueStatusValue($value), ['resolved', 'closed', 'fixed'], true);
}

function canUserMarkIssueResolved($userRole) {
    return in_array((string)$userRole, ['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester'], true);
}

function hasActiveRegressionRound($db, $projectId) {
    try {
        $stmt = $db->prepare("SELECT 1 FROM regression_rounds WHERE project_id = ? AND status = 'in_progress' AND is_active = 1 LIMIT 1");
        $stmt->execute([(int)$projectId]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        // If regression_rounds table is unavailable, do not block normal issue updates.
        return false;
    }
}

function getActiveRegressionRoundInfo($db, $projectId) {
    try {
        $stmt = $db->prepare("\n            SELECT id, round_number, started_at, ended_at\n            FROM regression_rounds\n            WHERE project_id = ?\n              AND status = 'in_progress'\n              AND is_active = 1\n            ORDER BY round_number DESC\n            LIMIT 1\n        ");
        $stmt->execute([(int)$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function ensureRegressionRoundIssueVersionsTable(PDO $db): void {
    $stmt = $db->query("SHOW TABLES LIKE 'regression_round_issue_versions'");
    if ($stmt && $stmt->rowCount() > 0) {
        return; // Table exists, avoid running DDL which causes implicit commit in MySQL
    }

    $db->exec("CREATE TABLE IF NOT EXISTS regression_round_issue_versions (
        id INT NOT NULL AUTO_INCREMENT,
        round_id INT NOT NULL,
        project_id INT NOT NULL,
        issue_id INT NOT NULL,
        original_payload LONGTEXT NULL,
        latest_payload LONGTEXT NULL,
        first_modified_by INT DEFAULT NULL,
        last_modified_by INT DEFAULT NULL,
        first_modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_rriv_round_issue (round_id, issue_id),
        KEY idx_rriv_project_round (project_id, round_id),
        KEY idx_rriv_issue (issue_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function decodeIssueSnapshotMetaValue($value) {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $startsWithJson = ($raw[0] === '[' || $raw[0] === '{');
    if ($startsWithJson) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return $raw;
}

function fetchIssueRegressionSnapshotPayload($db, $issueId, $projectId) {
    $issueStmt = $db->prepare("\n        SELECT id, issue_key, title, description, status_id, priority_id, reporter_id, assignee_id,\n               page_id, severity, common_issue_title, client_ready, created_at, updated_at\n        FROM issues\n        WHERE id = ? AND project_id = ?\n        LIMIT 1\n    ");
    $issueStmt->execute([(int)$issueId, (int)$projectId]);
    $issue = $issueStmt->fetch(PDO::FETCH_ASSOC);
    if (!$issue) return null;

    $metaStmt = $db->prepare("SELECT meta_key, meta_value FROM issue_metadata WHERE issue_id = ? ORDER BY id ASC");
    $metaStmt->execute([(int)$issueId]);
    $meta = [];
    while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = (string)$m['meta_key'];
        if (!isset($meta[$key])) $meta[$key] = [];
        $meta[$key][] = decodeIssueSnapshotMetaValue((string)$m['meta_value']);
    }

    $pageStmt = $db->prepare("SELECT page_id FROM issue_pages WHERE issue_id = ? ORDER BY page_id ASC");
    $pageStmt->execute([(int)$issueId]);
    $pageIds = array_map('intval', $pageStmt->fetchAll(PDO::FETCH_COLUMN));
    if (empty($pageIds) && isset($meta['page_ids'])) {
        $fallback = [];
        foreach ((array)$meta['page_ids'] as $entry) {
            if (is_array($entry)) {
                foreach ($entry as $pid) $fallback[] = (int)$pid;
            } else {
                $fallback[] = (int)$entry;
            }
        }
        $pageIds = array_values(array_unique(array_filter($fallback, static function($v) { return $v > 0; })));
    }

    return [
        'issue' => $issue,
        'metadata' => $meta,
        'page_ids' => $pageIds,
    ];
}

function upsertRegressionIssueVersion($db, $roundId, $projectId, $issueId, $userId, $originalPayload, $latestPayload) {
    if ((int)$roundId <= 0 || (int)$issueId <= 0) return;
    $originalJson = $originalPayload !== null
        ? json_encode($originalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;
    $latestJson = $latestPayload !== null
        ? json_encode($latestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    $stmt = $db->prepare("\n        INSERT INTO regression_round_issue_versions\n            (round_id, project_id, issue_id, original_payload, latest_payload, first_modified_by, last_modified_by)\n        VALUES\n            (?, ?, ?, ?, ?, ?, ?)\n        ON DUPLICATE KEY UPDATE\n            latest_payload = VALUES(latest_payload),\n            last_modified_by = VALUES(last_modified_by),\n            last_modified_at = NOW(),\n            original_payload = COALESCE(regression_round_issue_versions.original_payload, VALUES(original_payload))\n    ");
    $stmt->execute([
        (int)$roundId,
        (int)$projectId,
        (int)$issueId,
        $originalJson,
        $latestJson,
        (int)$userId,
        (int)$userId,
    ]);
}

function parseMetaIntValues($values) {
    $out = [];
    foreach ((array)$values as $value) {
        foreach (parseArrayInput($value) as $item) {
            $intValue = (int)$item;
            if ($intValue > 0) {
                $out[] = $intValue;
            }
        }
    }
    return array_values(array_unique($out));
}

function normalizeIssuePageSelection($db, $projectId, $pageId, $pageIds) {
    $requestedPrimaryPageId = (int)$pageId;
    $requestedPageIds = array_values(array_unique(array_filter(array_map('intval', (array)$pageIds), function($value) {
        return $value > 0;
    })));

    $candidatePageIds = $requestedPageIds;
    if ($requestedPrimaryPageId > 0 && !in_array($requestedPrimaryPageId, $candidatePageIds, true)) {
        array_unshift($candidatePageIds, $requestedPrimaryPageId);
    }

    if (empty($candidatePageIds)) {
        return [
            'page_id' => 0,
            'page_ids' => [],
            'had_invalid_primary' => false,
            'had_requested_pages' => false
        ];
    }

    $placeholders = implode(',', array_fill(0, count($candidatePageIds), '?'));
    $stmt = $db->prepare("SELECT id FROM project_pages WHERE project_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([(int)$projectId], $candidatePageIds));
    $validPageLookup = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

    $normalizedPageIds = [];
    foreach ($requestedPageIds as $candidatePageId) {
        if (isset($validPageLookup[$candidatePageId])) {
            $normalizedPageIds[] = $candidatePageId;
        }
    }

    $normalizedPrimaryPageId = isset($validPageLookup[$requestedPrimaryPageId])
        ? $requestedPrimaryPageId
        : (!empty($normalizedPageIds) ? (int)$normalizedPageIds[0] : 0);

    if ($normalizedPrimaryPageId > 0 && !in_array($normalizedPrimaryPageId, $normalizedPageIds, true)) {
        array_unshift($normalizedPageIds, $normalizedPrimaryPageId);
    }

    return [
        'page_id' => $normalizedPrimaryPageId,
        'page_ids' => array_values($normalizedPageIds),
        'had_invalid_primary' => $requestedPrimaryPageId > 0 && !isset($validPageLookup[$requestedPrimaryPageId]),
        'had_requested_pages' => !empty($candidatePageIds)
    ];
}

if (!hasProjectAccess($db, $userId, $projectId)) {
    jsonError('Permission denied', 403);
}

// Fetch project code for issue_key fallbacks
$projectCode = 'ISS';
try {
    $codeStmt = $db->prepare("SELECT project_code FROM projects WHERE id = ? LIMIT 1");
    $codeStmt->execute([(int)$projectId]);
    $projectCode = (string)($codeStmt->fetchColumn() ?: 'ISS');
} catch (Exception $e) { }

$canUpdateQaStatus = hasIssueQaStatusUpdateAccess($db, $userId, $projectId);

// Handle image deletion
if ($method === 'POST' && $action === 'delete_image') {
    $imagePath = $_POST['image_path'] ?? '';
    if (!$imagePath) {
        jsonError('image_path is required', 400);
    }
    
    // Security: Only allow deletion of files in assets/uploads/
    if (strpos($imagePath, '/assets/uploads/') === false) {
        jsonError('Invalid image path', 400);
    }
    
    // Convert to absolute path
    $basePath = dirname(__DIR__);
        // IDOR CHECK: Ensure the image belongs to a project the user has access to.
        // The image path format is typically: /assets/uploads/projects/{project_id}/...
        $pathParts = explode('/', ltrim($imagePath, '/'));
        $projectDirIndex = array_search('projects', $pathParts);
        if ($projectDirIndex !== false && isset($pathParts[$projectDirIndex + 1])) {
            $projectIdFromPath = (int)$pathParts[$projectDirIndex + 1];
            if (!hasProjectAccess($db, $_SESSION['user_id'], $projectIdFromPath)) {
                jsonError('Permission denied', 403);
            }
        } else {
            // If path doesn't contain project ID, it might be a global asset or legacy.
            // For security, only allow admin to delete these if they don't follow the pattern.
            if ($_SESSION['role'] !== 'admin') {
                jsonError('Permission denied: invalid path format', 403);
            }
        }

        $fullPath = __DIR__ . '/..' . $imagePath;
    
    // Check if file exists and delete it
    if (file_exists($fullPath)) {
        if (@unlink($fullPath)) {
            jsonResponse(['success' => true, 'message' => 'Image deleted']);
        } else {
            jsonError('Failed to delete image file', 500);
        }
    } else {
        // File doesn't exist, consider it already deleted
        jsonResponse(['success' => true, 'message' => 'Image already deleted']);
    }
}

try {
    if ($method === 'GET' && $action === 'list') {
        $pageId = (int)($_GET['page_id'] ?? 0);
        $issues = [];
        $metaMap = [];
        $commentCountMap = [];
        $reporterQaStatusByIssue = [];
        if ($userRole === 'client') {
            $records = getClientVisibleIssueRecords($db, [$projectId], ['page_id' => $pageId]);
            foreach ($records as $record) {
                $issue = $record['issue'] ?? [];
                $iid = (int)($issue['id'] ?? 0);
                if ($iid <= 0) {
                    continue;
                }
                $issues[] = $issue;
                $metaMap[$iid] = $record['meta'] ?? [];
            }
        } else {
            $params = [$projectId];
            $sql = "SELECT DISTINCT i.*, 
                           s.name as status_name, 
                           p.name as priority_name,
                           reporter.full_name as reporter_name,
                           assignee.full_name as qa_name,
                           (SELECT COALESCE(MAX(ih.id), 0) FROM issue_history ih WHERE ih.issue_id = i.id) AS latest_history_id
                    FROM issues i
                    LEFT JOIN issue_statuses s ON i.status_id = s.id
                    LEFT JOIN issue_priorities p ON i.priority_id = p.id
                    LEFT JOIN users reporter ON i.reporter_id = reporter.id
                    LEFT JOIN users assignee ON i.assignee_id = assignee.id";
            if ($pageId) {
                $sql .= " WHERE i.project_id = ? AND (
                    EXISTS (SELECT 1 FROM issue_pages ip WHERE ip.issue_id = i.id AND ip.page_id = ?)
                    OR (
                        i.page_id = ?
                        AND NOT EXISTS (SELECT 1 FROM issue_pages ip2 WHERE ip2.issue_id = i.id)
                    )
                )";
                $params[] = $pageId;
                $params[] = $pageId;
            } else {
                $sql .= " WHERE i.project_id = ?";
            }

            $orderByClause = columnExists($db, 'issues', 'issue_key') ? "ORDER BY i.issue_key ASC" : "ORDER BY i.id ASC";
            $sql .= " $orderByClause";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $issueIds = array_map(function($r){ return (int)$r['id']; }, $issues);
            if (!empty($issueIds)) {
                $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
                $metaStmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders)");
                $metaStmt->execute($issueIds);
                while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
                    $iid = (int)$m['issue_id'];
                    if (!isset($metaMap[$iid])) $metaMap[$iid] = [];
                    if (!isset($metaMap[$iid][$m['meta_key']])) $metaMap[$iid][$m['meta_key']] = [];
                    $metaMap[$iid][$m['meta_key']][] = $m['meta_value'];
                }
            }
        }

        $issueIds = array_map(function($r){ return (int)$r['id']; }, $issues);
        if (!empty($issueIds)) {
            $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
            $commentStmt = $db->prepare("SELECT issue_id, COUNT(*) AS c FROM issue_comments WHERE issue_id IN ($placeholders) GROUP BY issue_id");
            $commentStmt->execute($issueIds);
            while ($c = $commentStmt->fetch(PDO::FETCH_ASSOC)) {
                $commentCountMap[(int)$c['issue_id']] = (int)$c['c'];
            }
            if ($userRole !== 'client') {
                $reporterQaStatusByIssue = loadReporterQaStatusMapByIssueIds($db, $issueIds);
            }
        }

        $out = [];
        foreach ($issues as $i) {
            $iid = (int)$i['id'];
            $meta = $metaMap[$iid] ?? [];
            $pages = $meta['page_ids'] ?? [];
            if (empty($pages) && !empty($i['page_id'])) $pages = [(string)$i['page_id']];
            $statusValue = resolveIssueStatusDisplayValue($db, $meta['issue_status'][0] ?? '', $i['status_name'] ?? '', (int)($i['status_id'] ?? 0));
            $qaStatusValues = ($meta['qa_status'] ?? []);
            $reporterQaStatusMap = $reporterQaStatusByIssue[$iid] ?? [];
            if (empty($reporterQaStatusMap) && isset($meta['reporter_qa_status_map'])) {
                $reporterQaStatusMap = parseReporterQaStatusMapFromMetaValues($meta['reporter_qa_status_map']);
            }
            $hasComments = (($commentCountMap[$iid] ?? 0) > 0);
            $isOpen = isIssueOpenStatusValue($statusValue);
            $hasQaStatus = !empty($reporterQaStatusMap) || isQaStatusMetaFilled($qaStatusValues);
            $canTesterDelete = (!$hasComments && !($isOpen && $hasQaStatus));
            
            // Extract severity and priority as simple strings (not arrays or JSON)
            $severity = 'medium';
            if (isset($meta['severity'])) {
                if (is_array($meta['severity'])) {
                    // Get first value from array
                    $severity = $meta['severity'][0] ?? 'medium';
                    // If it's still JSON encoded, decode it
                    if (is_string($severity) && $severity[0] === '[') {
                        $decoded = json_decode($severity, true);
                        if (is_array($decoded)) {
                            $severity = $decoded[0] ?? 'medium';
                        }
                    }
                } else {
                    $severity = $meta['severity'];
                }
            } elseif (!empty($i['severity'])) {
                $severity = $i['severity'];
            }
            // Ensure it's a clean string
            $severity = is_string($severity) ? trim($severity) : 'medium';
            
            $priority = 'medium';
            if (isset($meta['priority'])) {
                if (is_array($meta['priority'])) {
                    // Get first value from array
                    $priority = $meta['priority'][0] ?? 'medium';
                    // If it's still JSON encoded, decode it
                    if (is_string($priority) && $priority[0] === '[') {
                        $decoded = json_decode($priority, true);
                        if (is_array($decoded)) {
                            $priority = $decoded[0] ?? 'medium';
                        }
                    }
                } else {
                    $priority = $meta['priority'];
                }
            } elseif (!empty($i['priority_name'])) {
                $priority = strtolower(str_replace(' ', '_', $i['priority_name']));
            }
            // Ensure it's a clean string
            $priority = is_string($priority) ? trim($priority) : 'medium';
            
            $descriptionHtml = (string)($i['description'] ?? '');
            if ($userRole === 'client' && function_exists('rewrite_html_public_image_urls')) {
                $descriptionHtml = rewrite_html_public_image_urls($descriptionHtml);
            }

            $rowOut = [
                'id' => $iid,
                'issue_key' => $i['issue_key'] ?? ($projectCode . '-' . $iid), // Fallback if column doesn't exist
                'project_id' => (int)$i['project_id'],
                'page_id' => $i['page_id'],
                'title' => $i['title'],
                'description' => $descriptionHtml,
                'status' => $statusValue,
                'status_id' => (int)$i['status_id'],
                'qa_status' => $qaStatusValues, // Return as array for multi-select
                'reporter_qa_status_map' => $reporterQaStatusMap,
                'has_comments' => $hasComments,
                'can_tester_delete' => $canTesterDelete,
                'severity' => $severity,
                'priority' => $priority,
                'pages' => $pages,
                'grouped_urls' => ($meta['grouped_urls'] ?? []),
                'reporters' => ($meta['reporter_ids'] ?? []),
                'reporter_name' => $i['reporter_name'] ?? null,
                'assignee_id' => (int)($i['assignee_id'] ?? 0) ?: null,
                'assignee_ids' => isset($meta['assignee_ids']) ? array_values(array_filter(array_map('intval', $meta['assignee_ids']), function($v){ return $v > 0; })) : ((int)($i['assignee_id'] ?? 0) ? [(int)$i['assignee_id']] : []),
                'qa_name' => $i['qa_name'] ?? null,
                'client_ready' => (int)($i['client_ready'] ?? 0),
                'created_at' => $i['created_at'],
                'updated_at' => $i['updated_at'],
                'latest_history_id' => (int)($i['latest_history_id'] ?? 0)
            ];
            // Add all metadata fields dynamically
            foreach ($meta as $metaKey => $metaVals) {
                if (!array_key_exists($metaKey, $rowOut)) {
                    // Handle common_title as string, others as arrays
                    if ($metaKey === 'common_title') {
                        $rowOut[$metaKey] = is_array($metaVals) ? ($metaVals[0] ?? '') : $metaVals;
                    } else {
                        $rowOut[$metaKey] = $metaVals;
                    }
                }
            }
            $out[] = $rowOut;
        }

        jsonResponse(['success' => true, 'issues' => $out]);
    }
    
    if ($method === 'GET' && $action === 'get_all') {
        // Cache key based on project + role (client sees filtered set)
        $cacheKey = "issues_all_{$projectId}_" . ($userRole === 'client' ? 'client' : 'staff');
        $cacheTtl = 120; // 2 minutes

        // Try APCu first (in-process, zero network overhead)
        $cached = false;
        if (function_exists('apcu_fetch')) {
            $data = apcu_fetch($cacheKey, $cached);
            if ($cached) {
                jsonResponse(['success' => true, 'issues' => $data, 'cached' => true]);
            }
        }

        if ($userRole === 'client') {
            $records = getClientVisibleIssueRecords($db, [$projectId]);

            $qaStatusStmt = $db->query("SELECT status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1");
            $qaStatusMaster = [];
            while ($qs = $qaStatusStmt->fetch(PDO::FETCH_ASSOC)) {
                $qaStatusMaster[$qs['status_key']] = [
                    'label' => $qs['status_label'],
                    'color' => $qs['badge_color']
                ];
            }

            $out = [];
            foreach ($records as $record) {
                $i = $record['issue'] ?? [];
                $iid = (int)($i['id'] ?? 0);
                if ($iid <= 0) {
                    continue;
                }

                $meta = $record['meta'] ?? [];
                $pages = $record['pages'] ?? [];
                $pageNames = array_map(function($p) { return trim((string)($p['page_number'] ?? '')) . ' - ' . trim((string)($p['page_name'] ?? '')); }, $pages);
                $pageNames = array_values(array_filter(array_map('trim', $pageNames)));
                $pageIds = array_values(array_filter(array_map(function($p) { return (int)($p['id'] ?? 0); }, $pages)));

                $reporterQaStatusMap = [];
                if (isset($meta['reporter_qa_status_map'])) {
                    $reporterQaStatusMap = parseReporterQaStatusMapFromMetaValues($meta['reporter_qa_status_map']);
                }

                $qaStatuses = [];
                $qaStatusKeys = [];
                if (!empty($reporterQaStatusMap)) {
                    foreach ($reporterQaStatusMap as $statusValues) {
                        foreach ((array)$statusValues as $statusKey) {
                            $key = strtolower(trim((string)$statusKey));
                            if ($key !== '') {
                                $qaStatusKeys[] = $key;
                            }
                        }
                    }
                    $qaStatusKeys = array_values(array_unique($qaStatusKeys));
                }
                if (empty($qaStatusKeys) && isset($meta['qa_status'])) {
                    $qaStatusKeys = array_values(array_filter(array_map('trim', (array)$meta['qa_status'])));
                }
                foreach ($qaStatusKeys as $key) {
                    if (isset($qaStatusMaster[$key])) {
                        $qaStatuses[] = [
                            'key' => $key,
                            'label' => $qaStatusMaster[$key]['label'],
                            'color' => $qaStatusMaster[$key]['color']
                        ];
                    }
                }

                $reporters = [];
                $reporterIds = [];
                if (!empty($i['reporter_name'])) {
                    $reporters[] = (string)$i['reporter_name'];
                }
                if (!empty($i['reporter_id'])) {
                    $reporterIds[] = (int)$i['reporter_id'];
                }
                if (isset($meta['reporter_ids'])) {
                    $additionalReporterIds = array_values(array_filter(array_map('intval', (array)$meta['reporter_ids'])));
                    if (!empty($additionalReporterIds)) {
                        $placeholders = implode(',', array_fill(0, count($additionalReporterIds), '?'));
                        $reporterStmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders)");
                        $reporterStmt->execute($additionalReporterIds);
                        while ($r = $reporterStmt->fetch(PDO::FETCH_ASSOC)) {
                            if (!in_array($r['full_name'], $reporters, true)) {
                                $reporters[] = $r['full_name'];
                            }
                            $reporterIds[] = (int)$r['id'];
                        }
                    }
                }
                $reporterIds = array_values(array_unique(array_filter($reporterIds)));

                $descriptionHtml = (string)($i['description'] ?? '');
                if ($userRole === 'client' && function_exists('rewrite_html_public_image_urls')) {
                    $descriptionHtml = rewrite_html_public_image_urls($descriptionHtml);
                }

                $out[] = [
                    'id' => $iid,
                    'issue_key' => $i['issue_key'] ?? ($projectCode . '-' . $iid),
                    'title' => $i['title'] ?? '',
                    'description' => $descriptionHtml,
                    'common_title' => isset($meta['common_title']) && is_array($meta['common_title']) ? ($meta['common_title'][0] ?? '') : ($meta['common_title'] ?? ''),
                    'status_id' => (int)($i['status_id'] ?? 0),
                    'status_name' => $i['status_name'] ?? '',
                    'status_color' => $i['status_color'] ?? '#6c757d',
                    'pages' => implode(', ', $pageNames),
                    'page_ids' => $pageIds,
                    'qa_statuses' => $qaStatuses,
                    'qa_status_keys' => $qaStatusKeys,
                    'reporter_qa_status_map' => $reporterQaStatusMap,
                    'reporters' => implode(', ', $reporters),
                    'reporter_ids' => $reporterIds,
                    'assignee_id' => (int)($i['assignee_id'] ?? 0) ?: null,
                    'assignee_ids' => isset($meta['assignee_ids']) ? array_values(array_filter(array_map('intval', $meta['assignee_ids']), function($v){ return $v > 0; })) : ((int)($i['assignee_id'] ?? 0) ? [(int)$i['assignee_id']] : []),
                    'severity' => isset($meta['severity']) ? (is_array($meta['severity']) ? ($meta['severity'][0] ?? 'medium') : $meta['severity']) : ($i['severity'] ?? 'medium'),
                    'priority' => isset($meta['priority']) ? (is_array($meta['priority']) ? ($meta['priority'][0] ?? 'medium') : $meta['priority']) : 'medium',
                    'grouped_urls' => isset($meta['grouped_urls']) && is_array($meta['grouped_urls']) ? $meta['grouped_urls'] : [],
                    'client_ready' => 1,
                    'metadata' => $meta,
                    'created_at' => $i['created_at'] ?? null,
                    'updated_at' => $i['updated_at'] ?? null,
                    'latest_history_id' => (int)($i['latest_history_id'] ?? 0)
                ];
            }

            if (function_exists('apcu_store')) {
                apcu_store($cacheKey, $out, $cacheTtl);
            }

            jsonResponse(['success' => true, 'issues' => $out]);
        }

        // Fetch all issues for the project with complete information
        $sql = "SELECT DISTINCT i.*, 
                       s.name as status_name,
                       s.color as status_color,
                       reporter.full_name as reporter_name,
                       (SELECT COALESCE(MAX(ih.id), 0) FROM issue_history ih WHERE ih.issue_id = i.id) AS latest_history_id
                FROM issues i
                LEFT JOIN issue_statuses s ON i.status_id = s.id
                LEFT JOIN users reporter ON i.reporter_id = reporter.id
                WHERE i.project_id = ?";
        
        // Filter for client role - only show client_ready issues
        if ($userRole === 'client') {
            $sql .= " AND i.client_ready = 1";
        }
        
        $sql .= " ORDER BY i.issue_key ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$projectId]);
        $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all metadata
        $issueIds = array_map(function($r){ return (int)$r['id']; }, $issues);
        $metaMap = [];
        $pageMap = [];
        $qaStatusMap = [];
        $reporterQaStatusByIssue = [];
        
        if (!empty($issueIds)) {
            // Fetch metadata
            $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
            $metaStmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders)");
            $metaStmt->execute($issueIds);
            while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
                $iid = (int)$m['issue_id'];
                if (!isset($metaMap[$iid])) $metaMap[$iid] = [];
                // Store as array to handle multiple values per key
                if (!isset($metaMap[$iid][$m['meta_key']])) {
                    $metaMap[$iid][$m['meta_key']] = [];
                }
                $metaMap[$iid][$m['meta_key']][] = $m['meta_value'];
            }
            
            // Fetch page names
            $pageStmt = $db->prepare("
                SELECT ip.issue_id, pp.id, pp.page_name, pp.page_number
                FROM issue_pages ip
                INNER JOIN project_pages pp ON ip.page_id = pp.id
                WHERE ip.issue_id IN ($placeholders)
            ");
            $pageStmt->execute($issueIds);
            while ($p = $pageStmt->fetch(PDO::FETCH_ASSOC)) {
                $iid = (int)$p['issue_id'];
                if (!isset($pageMap[$iid])) $pageMap[$iid] = [];
                $pageMap[$iid][] = [
                    'id' => (int)$p['id'],
                    'name' => $p['page_name'],
                    'number' => $p['page_number']
                ];
            }
            $reporterQaStatusByIssue = loadReporterQaStatusMapByIssueIds($db, $issueIds);
        }
        
        // Fetch QA status master for labels
        $qaStatusStmt = $db->query("SELECT status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1");
        $qaStatusMaster = [];
        while ($qs = $qaStatusStmt->fetch(PDO::FETCH_ASSOC)) {
            $qaStatusMaster[$qs['status_key']] = [
                'label' => $qs['status_label'],
                'color' => $qs['badge_color']
            ];
        }

        $out = [];
        foreach ($issues as $i) {
            $iid = (int)$i['id'];
            $meta = $metaMap[$iid] ?? [];
            $reporterQaStatusMap = $reporterQaStatusByIssue[$iid] ?? [];
            if (empty($reporterQaStatusMap) && isset($meta['reporter_qa_status_map'])) {
                $reporterQaStatusMap = parseReporterQaStatusMapFromMetaValues($meta['reporter_qa_status_map']);
            }
            
            // Get page info
            $pages = $pageMap[$iid] ?? [];
            $pageNames = array_map(function($p) { return $p['number'] . ' - ' . $p['name']; }, $pages);
            $pageIds = array_map(function($p) { return $p['id']; }, $pages);
            
            // If no pages from issue_pages, try metadata
            if (empty($pages) && isset($meta['page_ids'])) {
                $metaPageIds = $meta['page_ids'];
                // Handle both array and string formats
                if (is_array($metaPageIds)) {
                    // Already an array, just filter
                    $pageIds = array_values(array_filter(array_map('intval', $metaPageIds)));
                } else {
                    // String format, try JSON decode first
                    $decoded = json_decode($metaPageIds, true);
                    if (is_array($decoded)) {
                        $pageIds = array_values(array_filter(array_map('intval', $decoded)));
                    } else {
                        $pageIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $metaPageIds)))));
                    }
                }
                
                if (!empty($pageIds)) {
                    $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
                    // Only fetch pages that actually exist
                    $pageStmt = $db->prepare("SELECT id, page_name, page_number FROM project_pages WHERE id IN ($placeholders)");
                    $pageStmt->execute($pageIds);
                    $pageData = $pageStmt->fetchAll(PDO::FETCH_ASSOC);
                    $pageNames = array_map(function($p) { return $p['page_number'] . ' - ' . $p['page_name']; }, $pageData);
                    // Update pageIds to only include existing pages
                    $pageIds = array_map(function($p) { return (int)$p['id']; }, $pageData);
                }
            }
            
            // Get QA statuses with labels
            $qaStatuses = [];
            $qaStatusKeys = [];
            if (!empty($reporterQaStatusMap)) {
                foreach ($reporterQaStatusMap as $statusValues) {
                    $vals = is_array($statusValues) ? $statusValues : [$statusValues];
                    foreach ($vals as $statusKey) {
                        $key = strtolower(trim((string)$statusKey));
                        if ($key !== '') $qaStatusKeys[] = $key;
                    }
                }
                $qaStatusKeys = array_values(array_unique($qaStatusKeys));
            }
            if (empty($qaStatusKeys) && isset($meta['qa_status'])) {
                $qaStatusData = $meta['qa_status'];
                // Handle both array and string formats
                if (is_array($qaStatusData)) {
                    // If it's an array with one JSON string, decode it
                    if (count($qaStatusData) === 1 && is_string($qaStatusData[0]) && $qaStatusData[0][0] === '[') {
                        $decoded = json_decode($qaStatusData[0], true);
                        $qaStatusKeys = is_array($decoded) ? $decoded : $qaStatusData;
                    } else {
                        $qaStatusKeys = $qaStatusData;
                    }
                } else {
                    // String format
                    $decoded = json_decode($qaStatusData, true);
                    if (is_array($decoded)) {
                        $qaStatusKeys = $decoded;
                    } else {
                        $qaStatusKeys = array_filter(array_map('trim', explode(',', $qaStatusData)));
                    }
                }
                
            }
            foreach ($qaStatusKeys as $key) {
                if (isset($qaStatusMaster[$key])) {
                    $qaStatuses[] = [
                        'key' => $key,
                        'label' => $qaStatusMaster[$key]['label'],
                        'color' => $qaStatusMaster[$key]['color']
                    ];
                }
            }
            
            // Get all reporters
            $reporters = [];
            $reporterIds = [];
            if (!empty($i['reporter_name'])) {
                $reporters[] = $i['reporter_name'];
                $reporterIds[] = (int)$i['reporter_id'];
            }
            
            if (isset($meta['reporter_ids'])) {
                $reporterIdsData = $meta['reporter_ids'];
                // Handle both array and string formats
                if (is_array($reporterIdsData)) {
                    $additionalReporterIds = array_values(array_filter(array_map('intval', $reporterIdsData)));
                } else {
                    $decoded = json_decode($reporterIdsData, true);
                    if (is_array($decoded)) {
                        $additionalReporterIds = array_values(array_filter(array_map('intval', $decoded)));
                    } else {
                        $additionalReporterIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $reporterIdsData)))));
                    }
                }
                
                if (!empty($additionalReporterIds)) {
                    $placeholders = implode(',', array_fill(0, count($additionalReporterIds), '?'));
                    $reporterStmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders)");
                    $reporterStmt->execute($additionalReporterIds);
                    while ($r = $reporterStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!in_array($r['full_name'], $reporters)) {
                            $reporters[] = $r['full_name'];
                            $reporterIds[] = (int)$r['id'];
                        }
                    }
                }
            }
            
            $out[] = [
                'id' => $iid,
                'issue_key' => $i['issue_key'] ?? ($projectCode . '-' . $iid), // Fallback if column doesn't exist
                'title' => $i['title'],
                'description' => $i['description'],
                'common_title' => (string)($i['common_title_val'] ?? (isset($meta['common_title']) && is_array($meta['common_title']) ? $meta['common_title'][0] : ($meta['common_title'] ?? ''))),
                'status_id' => (int)$i['status_id'],
                'status_name' => $i['status_name'] ?? '',
                'status_color' => $i['status_color'] ?? '#6c757d',
                'pages' => implode(', ', $pageNames),
                'page_ids' => $pageIds,
                'qa_statuses' => $qaStatuses,
                'qa_status_keys' => $qaStatusKeys,
                'reporter_qa_status_map' => $reporterQaStatusMap,
                'reporters' => implode(', ', $reporters),
                'reporter_ids' => $reporterIds,
                'assignee_id' => (int)($i['assignee_id'] ?? 0) ?: null,
                'assignee_ids' => isset($meta['assignee_ids']) ? array_values(array_filter(array_map('intval', $meta['assignee_ids']), function($v){ return $v > 0; })) : ((int)($i['assignee_id'] ?? 0) ? [(int)$i['assignee_id']] : []),
                'severity' => isset($meta['severity']) ? (is_array($meta['severity']) ? $meta['severity'][0] : $meta['severity']) : 'medium',
                'priority' => isset($meta['priority']) ? (is_array($meta['priority']) ? $meta['priority'][0] : $meta['priority']) : 'medium',
                'grouped_urls' => isset($meta['grouped_urls']) && is_array($meta['grouped_urls']) ? $meta['grouped_urls'] : [],
                'client_ready' => (int)($i['client_ready'] ?? 0),
                'metadata' => $meta, // Include all metadata for custom fields
                'created_at' => $i['created_at'],
                'updated_at' => $i['updated_at'],
                'latest_history_id' => (int)($i['latest_history_id'] ?? 0)
            ];
        }

        // Store in APCu cache for next requests
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $out, $cacheTtl);
        }

        jsonResponse(['success' => true, 'issues' => $out]);
    }

    if ($method === 'GET' && $action === 'common_get_all') {
        // Cache key based on project + role
        $cacheKey = "issues_common_all_{$projectId}_" . ($userRole === 'client' ? 'client' : 'staff');
        $cacheTtl = 120; // 2 minutes

        // Try APCu first
        $cached = false;
        if (function_exists('apcu_fetch')) {
            $data = apcu_fetch($cacheKey, $cached);
            if ($cached) {
                jsonResponse(['success' => true, 'issues' => $data, 'cached' => true]);
            }
        }

        // Fetch all COMMON issues for the project with complete information
        // We join with common_issues table to only get shared ones.
        $sql = "SELECT DISTINCT i.*, 
                       ci.title AS common_title_val,
                       s.name as status_name,
                       s.color as status_color,
                       reporter.full_name as reporter_name,
                       (SELECT COALESCE(MAX(ih.id), 0) FROM issue_history ih WHERE ih.issue_id = i.id) AS latest_history_id
                FROM issues i
                INNER JOIN common_issues ci ON i.id = ci.issue_id
                LEFT JOIN issue_statuses s ON i.status_id = s.id
                LEFT JOIN users reporter ON i.reporter_id = reporter.id
                WHERE i.project_id = ?";
        
        if ($userRole === 'client') {
            $sql .= " AND i.client_ready = 1";
        }
        
        $sql .= " ORDER BY i.issue_key ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$projectId]);
        $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all metadata, pages, etc. for these specific common issues
        $issueIds = array_map(function($r){ return (int)$r['id']; }, $issues);
        $metaMap = [];
        $pageMap = [];
        $reporterQaStatusByIssue = [];
        
        if (!empty($issueIds)) {
            $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
            
            // Metadata
            $metaStmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders)");
            $metaStmt->execute($issueIds);
            while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
                $iid = (int)$m['issue_id'];
                if (!isset($metaMap[$iid])) $metaMap[$iid] = [];
                if (!isset($metaMap[$iid][$m['meta_key']])) $metaMap[$iid][$m['meta_key']] = [];
                $metaMap[$iid][$m['meta_key']][] = $m['meta_value'];
            }
            
            // Page names
            $pageStmt = $db->prepare("
                SELECT ip.issue_id, pp.id, pp.page_name, pp.page_number
                FROM issue_pages ip
                INNER JOIN project_pages pp ON ip.page_id = pp.id
                WHERE ip.issue_id IN ($placeholders)
            ");
            $pageStmt->execute($issueIds);
            while ($p = $pageStmt->fetch(PDO::FETCH_ASSOC)) {
                $iid = (int)$p['issue_id'];
                if (!isset($pageMap[$iid])) $pageMap[$iid] = [];
                $pageMap[$iid][] = ['id' => (int)$p['id'], 'name' => $p['page_name'], 'number' => $p['page_number']];
            }
            
            $reporterQaStatusByIssue = loadReporterQaStatusMapByIssueIds($db, $issueIds);
        }
        
        $qaStatusStmt = $db->query("SELECT status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1");
        $qaStatusMaster = [];
        while ($qs = $qaStatusStmt->fetch(PDO::FETCH_ASSOC)) {
            $qaStatusMaster[$qs['status_key']] = ['label' => $qs['status_label'], 'color' => $qs['badge_color']];
        }

        $out = [];
        foreach ($issues as $i) {
            $iid = (int)$i['id'];
            $meta = $metaMap[$iid] ?? [];
            $reporterQaStatusMap = $reporterQaStatusByIssue[$iid] ?? [];
            if (empty($reporterQaStatusMap) && isset($meta['reporter_qa_status_map'])) {
                $reporterQaStatusMap = parseReporterQaStatusMapFromMetaValues($meta['reporter_qa_status_map']);
            }
            
            $pages = $pageMap[$iid] ?? [];
            $pageNames = array_map(function($p) { return $p['number'] . ' - ' . $p['name']; }, $pages);
            $pageIds = array_map(function($p) { return $p['id']; }, $pages);
            
            // Sync with page_ids metadata if necessary
            if (empty($pages) && isset($meta['page_ids'])) {
                $pIds = is_array($meta['page_ids']) ? $meta['page_ids'] : explode(',', $meta['page_ids']);
                $pageIds = array_values(array_filter(array_map('intval', $pIds)));
                if (!empty($pageIds)) {
                     $pageStmt = $db->prepare("SELECT id, page_name, page_number FROM project_pages WHERE id IN (".implode(',', array_fill(0, count($pageIds), '?')).")");
                     $pageStmt->execute($pageIds);
                     $pageData = $pageStmt->fetchAll(PDO::FETCH_ASSOC);
                     $pageNames = array_map(function($p) { return $p['page_number'] . ' - ' . $p['page_name']; }, $pageData);
                     $pageIds = array_map(function($p) { return (int)$p['id']; }, $pageData);
                }
            }

            $qaStatuses = []; $qaStatusKeys = [];
            if (!empty($reporterQaStatusMap)) {
                foreach ($reporterQaStatusMap as $statusValues) {
                    foreach ((array)$statusValues as $sk) {
                        $key = strtolower(trim((string)$sk)); if ($key !== '') $qaStatusKeys[] = $key;
                    }
                }
                $qaStatusKeys = array_values(array_unique($qaStatusKeys));
            }
            if (empty($qaStatusKeys) && isset($meta['qa_status'])) {
                 $qaStatusKeys = is_array($meta['qa_status']) ? $meta['qa_status'] : explode(',', $meta['qa_status']);
                 $qaStatusKeys = array_values(array_filter(array_map('trim', $qaStatusKeys)));
            }
            foreach ($qaStatusKeys as $key) {
                if (isset($qaStatusMaster[$key])) {
                    $qaStatuses[] = ['key' => $key, 'label' => $qaStatusMaster[$key]['label'], 'color' => $qaStatusMaster[$key]['color']];
                }
            }
            
            $reporters = []; $reporterIds = [];
            if (!empty($i['reporter_name'])) { $reporters[] = $i['reporter_name']; $reporterIds[] = (int)$i['reporter_id']; }
            if (isset($meta['reporter_ids'])) {
                $rIds = array_values(array_filter(array_map('intval', (array)$meta['reporter_ids'])));
                if (!empty($rIds)) {
                    $rStmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN (".implode(',', array_fill(0, count($rIds), '?')).")");
                    $rStmt->execute($rIds);
                    while ($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!in_array($r['full_name'], $reporters)) { $reporters[] = $r['full_name']; $reporterIds[] = (int)$r['id']; }
                    }
                }
            }

            $out[] = [
                'id' => $iid,
                'issue_key' => $i['issue_key'] ?? ($projectCode . '-' . $iid),
                'title' => $i['title'],
                'description' => $i['description'],
                'common_title' => (string)($i['common_title_val'] ?? (isset($meta['common_title']) && is_array($meta['common_title']) ? $meta['common_title'][0] : ($meta['common_title'] ?? ''))),
                'status_id' => (int)$i['status_id'],
                'status_name' => $i['status_name'] ?? '',
                'status_color' => $i['status_color'] ?? '#6c757d',
                'pages' => implode(', ', $pageNames),
                'page_ids' => $pageIds,
                'qa_statuses' => $qaStatuses,
                'qa_status_keys' => $qaStatusKeys,
                'reporter_qa_status_map' => $reporterQaStatusMap,
                'reporters' => implode(', ', $reporters),
                'reporter_ids' => $reporterIds,
                'assignee_id' => (int)($i['assignee_id'] ?? 0) ?: null,
                'assignee_ids' => isset($meta['assignee_ids']) ? array_values(array_filter(array_map('intval', $meta['assignee_ids']), function($v){ return $v > 0; })) : ((int)($i['assignee_id'] ?? 0) ? [(int)$i['assignee_id']] : []),
                'severity' => isset($meta['severity']) ? (is_array($meta['severity']) ? $meta['severity'][0] : $meta['severity']) : 'medium',
                'priority' => isset($meta['priority']) ? (is_array($meta['priority']) ? $meta['priority'][0] : $meta['priority']) : 'medium',
                'grouped_urls' => isset($meta['grouped_urls']) && is_array($meta['grouped_urls']) ? $meta['grouped_urls'] : [],
                'client_ready' => (int)($i['client_ready'] ?? 0),
                'metadata' => $meta,
                'created_at' => $i['created_at'],
                'updated_at' => $i['updated_at'],
                'latest_history_id' => (int)($i['latest_history_id'] ?? 0)
            ];
        }

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $out, $cacheTtl);
        }

        jsonResponse(['success' => true, 'issues' => $out]);
    }

    if ($method === 'GET' && $action === 'presence_list') {
        $issueId = (int)($_GET['issue_id'] ?? 0);
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (!ensureIssuePresenceTable($db)) {
            jsonResponse(['success' => true, 'users' => []]);
        }
        cleanupIssuePresence($db);
        $users = getIssuePresenceUsers($db, $projectId, $issueId, $userId);
        jsonResponse(['success' => true, 'users' => $users]);
    }

    if ($method === 'GET' && $action === 'presence_session_list') {
        $issueId = (int)($_GET['issue_id'] ?? 0);
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (!ensureIssuePresenceSessionsTable($db)) {
            jsonResponse(['success' => true, 'sessions' => []]);
        }
        cleanupIssuePresenceSessions($db);
        $sessions = getIssuePresenceSessions($db, $projectId, $issueId);
        jsonResponse(['success' => true, 'sessions' => $sessions]);
    }

    if ($method === 'POST' && $action === 'presence_open_session') {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (!ensureIssuePresenceSessionsTable($db)) {
            jsonResponse(['success' => true, 'session_token' => '']);
        }
        cleanupIssuePresenceSessions($db);

        $sessionToken = generatePresenceSessionToken();
        $ins = $db->prepare("
            INSERT INTO issue_presence_sessions
                (project_id, issue_id, user_id, session_token, opened_at, last_seen)
            VALUES
                (?, ?, ?, ?, NOW(), NOW())
        ");
        $ins->execute([(int)$projectId, (int)$issueId, (int)$userId, $sessionToken]);

        jsonResponse(['success' => true, 'session_token' => $sessionToken]);
    }

    if ($method === 'POST' && $action === 'presence_ping') {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        $sessionToken = trim((string)($_POST['session_token'] ?? ''));
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (!ensureIssuePresenceTable($db)) {
            jsonResponse(['success' => true, 'users' => []]);
        }
        cleanupIssuePresence($db);
        $db->prepare("DELETE FROM issue_active_editors WHERE project_id = ? AND issue_id = ? AND last_seen < (NOW() - INTERVAL 6 SECOND)")
            ->execute([(int)$projectId, (int)$issueId]);

        $upsert = $db->prepare("
            INSERT INTO issue_active_editors (project_id, issue_id, user_id, last_seen)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE project_id = VALUES(project_id), last_seen = NOW()
        ");
        $upsert->execute([(int)$projectId, (int)$issueId, (int)$userId]);

        if ($sessionToken !== '') {
            if (ensureIssuePresenceSessionsTable($db)) {
                $touch = $db->prepare("
                    UPDATE issue_presence_sessions
                    SET last_seen = NOW()
                    WHERE session_token = ? AND project_id = ? AND issue_id = ? AND user_id = ? AND closed_at IS NULL
                ");
                $touch->execute([$sessionToken, (int)$projectId, (int)$issueId, (int)$userId]);
            }
        }

        $users = getIssuePresenceUsers($db, $projectId, $issueId, $userId);
        jsonResponse(['success' => true, 'users' => $users]);
    }

    if ($method === 'POST' && $action === 'presence_leave') {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        $sessionToken = trim((string)($_POST['session_token'] ?? ''));
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (ensureIssuePresenceTable($db)) {
            $del = $db->prepare("DELETE FROM issue_active_editors WHERE project_id = ? AND issue_id = ? AND user_id = ?");
            $del->execute([(int)$projectId, (int)$issueId, (int)$userId]);
        }

        if (ensureIssuePresenceSessionsTable($db)) {
            if ($sessionToken !== '') {
                $close = $db->prepare("
                    UPDATE issue_presence_sessions
                    SET closed_at = NOW(),
                        duration_seconds = TIMESTAMPDIFF(SECOND, opened_at, NOW()),
                        last_seen = NOW()
                    WHERE session_token = ? AND project_id = ? AND issue_id = ? AND user_id = ? AND closed_at IS NULL
                ");
                $close->execute([$sessionToken, (int)$projectId, (int)$issueId, (int)$userId]);
            } else {
                $closeAny = $db->prepare("
                    UPDATE issue_presence_sessions
                    SET closed_at = NOW(),
                        duration_seconds = TIMESTAMPDIFF(SECOND, opened_at, NOW()),
                        last_seen = NOW()
                    WHERE project_id = ? AND issue_id = ? AND user_id = ? AND closed_at IS NULL
                ");
                $closeAny->execute([(int)$projectId, (int)$issueId, (int)$userId]);
            }
        }
        jsonResponse(['success' => true]);
    }


if ($method === 'POST' && ($action === 'create' || $action === 'update')) {
        // Debug logging
        error_log("Issue API: Starting $action action. Project ID: $projectId, User ID: $userId");
        
        $id = (int)($_POST['id'] ?? 0);
    $testerDetailsReadonlyDuringRegression = ($action === 'update' && $isTesterRole && hasActiveRegressionRound($db, $projectId));
    if ($userRole === 'client' && $action === 'create') {
        jsonError('Clients cannot create new issues.', 403);
    }
        $expectedUpdatedAt = trim((string)($_POST['expected_updated_at'] ?? ''));
        $expectedHistoryId = isset($_POST['expected_history_id']) ? (int)$_POST['expected_history_id'] : null;
        $title = trim($_POST['title'] ?? '');
        if (!$title) jsonError('title is required', 400);
        $description = $_POST['description'] ?? '';
        
        // Validate description length (TEXT column can hold ~65,535 characters)
        if (strlen($description) > 65000) {
            jsonError('Description is too long. Please reduce the content or remove large images.', 400);
        }
        
        $pageIds = parseArrayInput($_POST['pages'] ?? []);
        $pageId = (int)($_POST['page_id'] ?? 0);
        if (!$pageId && !empty($pageIds)) $pageId = (int)$pageIds[0];
        $pageSelection = normalizeIssuePageSelection($db, $projectId, $pageId, $pageIds);
        $pageId = (int)$pageSelection['page_id'];
        $pageIds = $pageSelection['page_ids'];
        if ($pageSelection['had_invalid_primary'] && empty($pageIds)) {
            jsonError('Selected page is invalid or no longer available for this project.', 400);
        }

        $statusId = null;
        $statusInput = $_POST['issue_status'] ?? '';
        if (is_numeric($statusInput)) {
            // Direct ID provided
            $statusId = (int)$statusInput;
        } else {
            // Name provided, convert to ID
            $statusId = getStatusId($db, $statusInput);
        }
        if (!$statusId) $statusId = getStatusId($db, 'Open');
        if (!$statusId) $statusId = getAnyStatusId($db);
        if (!$statusId) jsonError('Issue statuses are not configured.', 500);
        $reporters = parseArrayInput($_POST['reporters'] ?? []);
        $reporters = array_values(array_filter(array_map('intval', $reporters), function($v){ return $v > 0; }));
        $reporterId = !empty($reporters) ? (int)$reporters[0] : (int)$userId;
        $qaStatusMasterRows = [];
        try {
            $qaStatusMasterRows = $db->query("SELECT status_key FROM qa_status_master WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $qaStatusMasterRows = [];
        }
        $validQaStatusKeys = array_values(array_filter(array_map(static function($v) {
            return strtolower(trim((string)$v));
        }, (array)$qaStatusMasterRows)));
        $priorityId = getPriorityId($db, $_POST['priority'] ?? 'medium');
        if (!$priorityId) $priorityId = getPriorityId($db, 'Medium');
        if (!$priorityId) $priorityId = getAnyPriorityId($db);
        if (!$priorityId) jsonError('Issue priorities are not configured.', 500);
        $typeId = getDefaultTypeId($db, $projectId);
        if (!$typeId) jsonError('Issue types are not configured.', 500);
        $issueKey = '';
        $commonTitle = trim($_POST['common_title'] ?? '');
        $clientReady = (int)($_POST['client_ready'] ?? 0);
        $severity = trim($_POST['severity'] ?? 'medium');
        // assignee_ids: multi-select QA names — stored in metadata; first ID also goes to assignee_id column
        $assigneeIdsRaw = parseArrayInput($_POST['assignee_ids'] ?? ($_POST['assignee_id'] ?? []));
        $assigneeIds = array_values(array_filter(array_map('intval', $assigneeIdsRaw), function($v){ return $v > 0; }));
        $assigneeId = !empty($assigneeIds) ? $assigneeIds[0] : null;

        if (count($pageIds) > 1 && $commonTitle === '') {
            jsonError('Common Issue Title is required when multiple pages are selected.', 400);
        }

        $hasResolvedAtColumn = columnExists($db, 'issues', 'resolved_at');
        $requestedStatusValue = normalizeIssueStatusValue(is_numeric($statusInput)
            ? getIssueStatusNameById($db, $statusId)
            : $statusInput);

        if ($action === 'create' && isResolvedIssueStatusValue($requestedStatusValue) && !canUserMarkIssueResolved($userRole)) {
            jsonError('Only the testing team can mark issues as resolved.', 403);
        }

        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
            }

            $activeRegressionRound = getActiveRegressionRoundInfo($db, $projectId);
            if ($activeRegressionRound) {
                ensureRegressionRoundIssueVersionsTable($db);
            }
            $regressionBeforePayload = null;

            if ($action === 'create') {
                if ($hasResolvedAtColumn) {
                    $resolvedAtValue = isResolvedIssueStatusValue($requestedStatusValue) ? date('Y-m-d H:i:s') : null;
                    $stmt = $db->prepare("INSERT INTO issues (project_id, issue_key, title, description, type_id, priority_id, status_id, reporter_id, assignee_id, page_id, severity, resolved_at, is_final, common_issue_title, client_ready) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
                } else {
                    $resolvedAtValue = null;
                    $stmt = $db->prepare("INSERT INTO issues (project_id, issue_key, title, description, type_id, priority_id, status_id, reporter_id, assignee_id, page_id, severity, is_final, common_issue_title, client_ready) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
                }
                $created = false;
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $issueKey = getIssueKey($db, $projectId);
                    try {
                        $params = [$projectId, $issueKey, $title, $description, $typeId, $priorityId, $statusId, $reporterId, $assigneeId, $pageId ?: null, $severity];
                        if ($hasResolvedAtColumn) {
                            $params[] = $resolvedAtValue;
                        }
                        $params[] = $commonTitle ?: null;
                        $params[] = $clientReady;
                        $stmt->execute($params);
                        $id = (int)$db->lastInsertId();
                        $created = true;
                        break;
                    } catch (PDOException $pe) {
                        // Retry only on unique issue_key race/collision.
                        $isDuplicate = ((int)($pe->errorInfo[1] ?? 0) === 1062);
                        $isIssueKeyDup = stripos((string)$pe->getMessage(), 'issue_key') !== false;
                        if (!($isDuplicate && $isIssueKeyDup)) {
                            throw $pe;
                        }
                    }
                }
                if (!$created) {
                    throw new RuntimeException('Unable to generate a unique issue key. Please retry.');
                }
            } else {
                if (!$id) {
                    if ($db->inTransaction()) $db->rollBack();
                    jsonError('id is required', 400);
                }
                
                // --- HISTORY LOGGING ---
                // Fetch current state
                // IDOR Fix: Ensure issue belongs to the provided projectId
                $oldStmt = $db->prepare("SELECT * FROM issues WHERE id = ? AND project_id = ? FOR UPDATE");
                $oldStmt->execute([$id, $projectId]);
                $oldIssue = $oldStmt->fetch(PDO::FETCH_ASSOC);
                if (!$oldIssue) {
                    if ($db->inTransaction()) $db->rollBack();
                    jsonError('Issue not found or unauthorized access to this project.', 404);
                }

                // Populate $issueKey for activity log and response
                $issueKey = $oldIssue['issue_key'] ?? '';

                if ($userRole === 'client' && (int)($oldIssue['client_ready'] ?? 0) !== 1) {
                    if (!isIssueVisibleToClientThroughSnapshot($db, $id, $projectId)) {
                        if ($db->inTransaction()) $db->rollBack();
                        jsonError('Permission denied', 403);
                    }
                }

                if ($userRole !== 'client') {
                    if ($expectedUpdatedAt !== '' && !empty($oldIssue['updated_at']) && $expectedUpdatedAt !== (string)$oldIssue['updated_at']) {
                        if ($db->inTransaction()) $db->rollBack();
                        jsonResponse([
                            'error' => 'This issue was modified by another user. Please reload latest data and try again.',
                            'conflict' => true,
                            'current_updated_at' => (string)$oldIssue['updated_at']
                        ], 409);
                    }

                    if ($expectedHistoryId !== null) {
                        $histStmt = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM issue_history WHERE issue_id = ?");
                        $histStmt->execute([$id]);
                        $currentHistoryId = (int)$histStmt->fetchColumn();
                        if ($currentHistoryId !== $expectedHistoryId) {
                            if ($db->inTransaction()) $db->rollBack();
                            jsonResponse([
                                'error' => 'This issue was modified by another user. Please reload latest data and try again.',
                                'conflict' => true,
                                'current_history_id' => $currentHistoryId
                            ], 409);
                        }
                    }
                }

                $oldMetaStmt = $db->prepare("SELECT meta_key, meta_value FROM issue_metadata WHERE issue_id = ? ORDER BY id ASC");
                $oldMetaStmt->execute([$id]);
                $oldMeta = [];
                while ($m = $oldMetaStmt->fetch(PDO::FETCH_ASSOC)) {
                    $k = (string)$m['meta_key'];
                    if (!isset($oldMeta[$k])) $oldMeta[$k] = [];
                    $oldMeta[$k][] = (string)$m['meta_value'];
                }

                if ($activeRegressionRound) {
                    $regressionBeforePayload = fetchIssueRegressionSnapshotPayload($db, $id, $projectId);
                }

                $currentStatusValue = normalizeIssueStatusValue(getIssueStatusNameById($db, (int)($oldIssue['status_id'] ?? 0)));
                if ($requestedStatusValue === '') {
                    $requestedStatusValue = $currentStatusValue;
                }

                if (isResolvedIssueStatusValue($requestedStatusValue)
                    && $requestedStatusValue !== $currentStatusValue
                    && !canUserMarkIssueResolved($userRole)) {
                    if ($db->inTransaction()) $db->rollBack();
                    jsonError('Only the testing team can mark issues as resolved.', 403);
                }

                $resolvedAtValue = $oldIssue['resolved_at'] ?? null;
                if ($hasResolvedAtColumn) {
                    if (isResolvedIssueStatusValue($requestedStatusValue)) {
                        if (empty($resolvedAtValue)) {
                            $resolvedAtValue = date('Y-m-d H:i:s');
                        }
                    } else {
                        $resolvedAtValue = null;
                    }
                }

                if ($userRole === 'client') {
                    if ($requestedStatusValue === '') {
                        $requestedStatusValue = $currentStatusValue;
                    }
                    if (!isClientEditableIssueStatusValue($requestedStatusValue) && $requestedStatusValue !== $currentStatusValue) {
                        if ($db->inTransaction()) $db->rollBack();
                        jsonError('Clients can only update allowed issue statuses.', 403);
                    }

                    $title = (string)$oldIssue['title'];
                    $description = (string)$oldIssue['description'];
                    $priorityId = (int)$oldIssue['priority_id'];
                    $reporterId = (int)$oldIssue['reporter_id'];
                    $severity = (string)$oldIssue['severity'];
                    $commonTitle = $oldIssue['common_issue_title'];
                    $clientReady = (int)($oldIssue['client_ready'] ?? 0);
                    $assigneeId = !empty($oldIssue['assignee_id']) ? (int)$oldIssue['assignee_id'] : null;
                    $pageId = !empty($oldIssue['page_id']) ? (int)$oldIssue['page_id'] : 0;
                    $reporters = parseMetaIntValues($oldMeta['reporter_ids'] ?? []);
                    if (empty($reporters) && $reporterId > 0) {
                        $reporters = [$reporterId];
                    }
                    $assigneeIds = parseMetaIntValues($oldMeta['assignee_ids'] ?? []);
                    if (empty($assigneeIds) && $assigneeId) {
                        $assigneeIds = [$assigneeId];
                    }
                    $pageIds = parseMetaIntValues($oldMeta['page_ids'] ?? []);
                    if (empty($pageIds) && $pageId > 0) {
                        $pageIds = [$pageId];
                    }
                }

                if ($testerDetailsReadonlyDuringRegression) {
                    // During active regression rounds testers can edit metadata/status but not issue details body.
                    $description = (string)$oldIssue['description'];
                }

                $pageSelection = normalizeIssuePageSelection($db, $projectId, $pageId, $pageIds);
                $pageId = (int)$pageSelection['page_id'];
                $pageIds = $pageSelection['page_ids'];
                if ($pageSelection['had_invalid_primary'] && empty($pageIds)) {
                    if ($db->inTransaction()) $db->rollBack();
                    jsonError('Selected page is invalid or no longer available for this project.', 400);
                }

                // DETECT CHANGES
                $hasChanged = false;
                if ($oldIssue['title'] !== $title) $hasChanged = true;
                if ($oldIssue['description'] !== $description) $hasChanged = true;
                if ((int)$oldIssue['priority_id'] !== (int)$priorityId) $hasChanged = true;
                if ((int)$oldIssue['status_id'] !== (int)$statusId) $hasChanged = true;
                if ((int)$oldIssue['reporter_id'] !== (int)$reporterId) $hasChanged = true;
                if ($oldIssue['page_id'] != ($pageId ?: null)) $hasChanged = true;
                if ($oldIssue['severity'] !== $severity) $hasChanged = true;
                if ($oldIssue['common_issue_title'] !== ($commonTitle ?: null)) $hasChanged = true;
                if ((int)($oldIssue['client_ready'] ?? 0) !== $clientReady) $hasChanged = true;
                if ((int)($oldIssue['assignee_id'] ?? 0) !== (int)($assigneeId ?? 0)) $hasChanged = true;
                if ($hasResolvedAtColumn && (string)($oldIssue['resolved_at'] ?? '') !== (string)($resolvedAtValue ?? '')) $hasChanged = true;

                logHistory($db, $id, $userId, 'title', $oldIssue['title'], $title);
                logHistory($db, $id, $userId, 'description', $oldIssue['description'], $description);
                logHistory($db, $id, $userId, 'severity', $oldIssue['severity'], $severity);
                logHistory($db, $id, $userId, 'common_issue_title', $oldIssue['common_issue_title'], $commonTitle ?: null);
                logHistory($db, $id, $userId, 'client_ready', $oldIssue['client_ready'] ?? 0, $clientReady);
                logHistory($db, $id, $userId, 'assignee_id', $oldIssue['assignee_id'] ?? null, $assigneeId);
                if ($hasResolvedAtColumn) logHistory($db, $id, $userId, 'resolved_at', $oldIssue['resolved_at'] ?? null, $resolvedAtValue);

                if ($hasResolvedAtColumn && $hasChanged) {
                    $stmt = $db->prepare("UPDATE issues SET issue_key = ?, title = ?, description = ?, priority_id = ?, status_id = ?, reporter_id = ?, assignee_id = ?, page_id = ?, severity = ?, common_issue_title = ?, client_ready = ?, resolved_at = ?, updated_at = NOW() WHERE id = ? AND project_id = ?");
                } elseif ($hasResolvedAtColumn) {
                    $stmt = $db->prepare("UPDATE issues SET issue_key = ?, title = ?, description = ?, priority_id = ?, status_id = ?, reporter_id = ?, assignee_id = ?, page_id = ?, severity = ?, common_issue_title = ?, client_ready = ?, resolved_at = ? WHERE id = ? AND project_id = ?");
                } elseif ($hasChanged) {
                    $stmt = $db->prepare("UPDATE issues SET issue_key = ?, title = ?, description = ?, priority_id = ?, status_id = ?, reporter_id = ?, assignee_id = ?, page_id = ?, severity = ?, common_issue_title = ?, client_ready = ?, updated_at = NOW() WHERE id = ? AND project_id = ?");
                } else {
                    $stmt = $db->prepare("UPDATE issues SET issue_key = ?, title = ?, description = ?, priority_id = ?, status_id = ?, reporter_id = ?, assignee_id = ?, page_id = ?, severity = ?, common_issue_title = ?, client_ready = ? WHERE id = ? AND project_id = ?");
                }
                $params = [$issueKey, $title, $description, $priorityId, $statusId, $reporterId, $assigneeId, $pageId ?: null, $severity, $commonTitle ?: null, $clientReady];
                if ($hasResolvedAtColumn) {
                    $params[] = $resolvedAtValue;
                }
                $params[] = $id;
                $params[] = $projectId;
                $stmt->execute($params);
            }

        // For update operations, check if QA status is actually being changed
        $isActuallyUpdatingQaStatus = false;
        
        // Handle QA status as array (multi-select)
        $qaStatusInput = $_POST['qa_status'] ?? [];
        if (is_string($qaStatusInput) && !empty($qaStatusInput)) {
            // If it's a JSON string, decode it
            if ($qaStatusInput[0] === '[') {
                $parsed = json_decode($qaStatusInput, true);
                if (is_array($parsed)) {
                    $qaStatusInput = $parsed;
                } else {
                    $qaStatusInput = [$qaStatusInput];
                }
            } else {
                $qaStatusInput = [$qaStatusInput];
            }
        } elseif (!is_array($qaStatusInput)) {
            $qaStatusInput = [];
        }
        $qaStatusInput = array_values(array_filter(array_map(static function($v) {
            return strtolower(trim((string)$v));
        }, $qaStatusInput), static function($v) {
            return $v !== '';
        }));

        $reporterQaStatusMapInput = parseReporterQaStatusMapInput($_POST['reporter_qa_status_map'] ?? null);
        $reporterQaStatusMap = normalizeReporterQaStatusMap($reporterQaStatusMapInput, $reporters, $validQaStatusKeys);

        // If user doesn't have QA permission, don't check for QA status changes at all
        if (!$canUpdateQaStatus) {
            $isActuallyUpdatingQaStatus = false;
        } else if ($action === 'update' && $id > 0) {
            // Get existing QA status values for comparison
            $existingQaStatusStmt = $db->prepare("SELECT meta_value FROM issue_metadata WHERE issue_id = ? AND meta_key = 'qa_status'");
            $existingQaStatusStmt->execute([$id]);
            $existingQaStatusValues = $existingQaStatusStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $existingQaStatusNormalized = array_values(array_filter(array_map(static function($v) {
                return strtolower(trim((string)$v));
            }, $existingQaStatusValues), static function($v) {
                return $v !== '';
            }));
            
            // Get existing reporter QA status map
            $existingReporterQaMapStmt = $db->prepare("SELECT meta_value FROM issue_metadata WHERE issue_id = ? AND meta_key = 'reporter_qa_status_map'");
            $existingReporterQaMapStmt->execute([$id]);
            $existingReporterQaMapValues = $existingReporterQaMapStmt->fetchAll(PDO::FETCH_COLUMN);
            $existingReporterQaMap = [];
            if (!empty($existingReporterQaMapValues)) {
                $existingReporterQaMap = parseReporterQaStatusMapFromMetaValues($existingReporterQaMapValues);
            }
            
            // Compare current values with new values to see if they're actually changing
            sort($qaStatusInput);
            sort($existingQaStatusNormalized);
            $qaStatusChanged = (json_encode($qaStatusInput) !== json_encode($existingQaStatusNormalized));
            $reporterQaMapChanged = (json_encode($reporterQaStatusMap) !== json_encode($existingReporterQaMap));
            
            $isActuallyUpdatingQaStatus = $qaStatusChanged || $reporterQaMapChanged;
        } else {
            // For create operations, check if QA status data is being provided
            $isActuallyUpdatingQaStatus = !empty($qaStatusInput) || !empty($reporterQaStatusMap);
        }

        if ($action === 'update') {
            handleMetaHistory($db, $id, $userId, 'issue_status', $_POST['issue_status'] ?? '', $oldMeta);
            // Only track QA status history if user has permission and is actually updating QA status
            if ($canUpdateQaStatus && $isActuallyUpdatingQaStatus) {
                handleMetaHistory($db, $id, $userId, 'qa_status', $qaStatusInput, $oldMeta);
                handleMetaHistory($db, $id, $userId, 'reporter_qa_status_map', $reporterQaStatusMap, $oldMeta);
            }
            if ($userRole !== 'client') {
                handleMetaHistory($db, $id, $userId, 'page_ids', $pageIds, $oldMeta);
                handleMetaHistory($db, $id, $userId, 'reporter_ids', $reporters, $oldMeta);
            }
            // Add other meta fields as needed
        }

        // Only check QA permissions if user is actually trying to change QA status
        if (!$canUpdateQaStatus && $isActuallyUpdatingQaStatus) {
            jsonError('You do not have permission to update QA status for this project.', 403);
        }
        // Only process QA status updates if user has permission and is actually updating QA status
        if ($canUpdateQaStatus && $isActuallyUpdatingQaStatus) {
            replaceMeta($db, $id, 'qa_status', $qaStatusInput);
            // Store as a single JSON-encoded object (plain object, not array-of-strings)
            // This is the canonical format; parseReporterQaStatusMapFromMetaValues handles legacy array-of-strings too.
            replaceMeta($db, $id, 'reporter_qa_status_map', [json_encode((object)$reporterQaStatusMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            persistIssueReporterQaStatuses($db, $id, $reporterQaStatusMap, $userId);
        }
        
        replaceMeta($db, $id, 'issue_status', [$_POST['issue_status'] ?? '']);

        if ($userRole !== 'client') {
            replaceMeta($db, $id, 'page_ids', $pageIds);
            replaceMeta($db, $id, 'grouped_urls', parseArrayInput($_POST['grouped_urls'] ?? []));
            replaceMeta($db, $id, 'reporter_ids', $reporters);
            replaceMeta($db, $id, 'assignee_ids', $assigneeIds);
            replaceMeta($db, $id, 'common_title', [trim($_POST['common_title'] ?? '')]);

            // Handle dynamic metadata (admin-created custom fields) — all go through same replaceMeta batch
            if (isset($_POST['metadata'])) {
                $metadata = json_decode($_POST['metadata'], true);
                if (is_array($metadata)) {
                    foreach ($metadata as $key => $value) {
                        if ($action === 'update') {
                            handleMetaHistory($db, $id, $userId, $key, $value, $oldMeta);
                        }
                        $valueArray = is_array($value) ? $value : [$value];
                        replaceMeta($db, $id, $key, $valueArray);
                    }
                }
            }

            // Batch insert issue_pages (single query instead of N individual inserts)
            $db->prepare("DELETE FROM issue_pages WHERE issue_id = ?")->execute([$id]);
            if (!empty($pageIds)) {
                $pageRows = implode(',', array_fill(0, count($pageIds), '(?, ?)'));
                $pageParams = [];
                foreach ($pageIds as $pid) { $pageParams[] = $id; $pageParams[] = (int)$pid; }
                $db->prepare("INSERT INTO issue_pages (issue_id, page_id) VALUES $pageRows")->execute($pageParams);
            }

            if ($commonTitle && count($pageIds) > 1) {
                $stmt = $db->prepare("SELECT id FROM common_issues WHERE issue_id = ? LIMIT 1");
                $stmt->execute([$id]);
                $cid = $stmt->fetchColumn();
                if ($cid) {
                    $up = $db->prepare("UPDATE common_issues SET title = ?, updated_at = NOW() WHERE id = ?");
                    $up->execute([$commonTitle, $cid]);
                } else {
                    if (columnExists($db, 'common_issues', 'created_by')) {
                        $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title, created_by) VALUES (?, ?, ?, ?)");
                        $ins->execute([$projectId, $id, $commonTitle, $userId]);
                    } else {
                        $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title) VALUES (?, ?, ?)");
                        $ins->execute([$projectId, $id, $commonTitle]);
                    }
                }
            } else {
                $db->prepare("DELETE FROM common_issues WHERE issue_id = ?")->execute([$id]);
            }
        }

        if ($activeRegressionRound && (int)$id > 0) {
            $regressionAfterPayload = fetchIssueRegressionSnapshotPayload($db, $id, $projectId);
            upsertRegressionIssueVersion(
                $db,
                (int)($activeRegressionRound['id'] ?? 0),
                (int)$projectId,
                (int)$id,
                (int)$userId,
                $regressionBeforePayload,
                $regressionAfterPayload
            );
        }

        $db->commit();

        // Activity Log for UI timeline
        try {
            $logAction = ($action === 'create') ? 'added_issue' : 'updated_issue';
            logActivity($db, $userId, $logAction, 'issue', $id, [
                'issue_key' => $issueKey,
                'title' => $title,
                'project_id' => $projectId
            ]);
        } catch (Exception $e) {}

        if ((int) $id > 0) {
            if ((int) $clientReady === 1 || $userRole === 'client' || isIssueVisibleToClientThroughSnapshot($db, $id, $projectId)) {
                publishIssueClientSnapshot($db, (int) $id);
            }
        }
        
        // Debug logging
        error_log("Issue API: Transaction committed successfully for $action action. Issue ID: $id");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        
        // Debug logging
        error_log("Issue API: Exception in $action action: " . $e->getMessage());
        
        jsonError($e->getMessage(), 500);
    }

        // Fetch the updated issue data to return to client
        $sql = "SELECT DISTINCT i.*, 
                       s.name as status_name,
                       s.color as status_color,
                       reporter.full_name as reporter_name,
                       assignee.full_name as qa_name
                FROM issues i
                LEFT JOIN issue_statuses s ON i.status_id = s.id
                LEFT JOIN users reporter ON i.reporter_id = reporter.id
                LEFT JOIN users assignee ON i.assignee_id = assignee.id
                WHERE i.id = ? AND i.project_id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$id, $projectId]);
        $issueData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$issueData) {
            jsonResponse(['success' => true, 'id' => $id, 'issue_key' => $issueKey]);
        }
        
        // Fetch metadata for this issue
        $metaStmt = $db->prepare("SELECT meta_key, meta_value FROM issue_metadata WHERE issue_id = ?");
        $metaStmt->execute([$id]);
        $meta = [];
        while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($meta[$m['meta_key']])) {
                $meta[$m['meta_key']] = [];
            }
            $meta[$m['meta_key']][] = $m['meta_value'];
        }
        
        // Fetch page info
        $pageStmt = $db->prepare("
            SELECT pp.id, pp.page_name, pp.page_number
            FROM issue_pages ip
            INNER JOIN project_pages pp ON ip.page_id = pp.id
            WHERE ip.issue_id = ?
        ");
        $pageStmt->execute([$id]);
        $pages = [];
        $pageIds = [];
        while ($p = $pageStmt->fetch(PDO::FETCH_ASSOC)) {
            $pages[] = [
                'id' => (int)$p['id'],
                'name' => $p['page_name'],
                'number' => $p['page_number']
            ];
            $pageIds[] = (int)$p['id'];
        }
        
        // If no pages from issue_pages, try metadata
        if (empty($pages) && isset($meta['page_ids'])) {
            $metaPageIds = $meta['page_ids'];
            if (is_array($metaPageIds)) {
                $pageIds = array_values(array_filter(array_map('intval', $metaPageIds)));
            } else {
                $decoded = json_decode($metaPageIds, true);
                if (is_array($decoded)) {
                    $pageIds = array_values(array_filter(array_map('intval', $decoded)));
                } else {
                    $pageIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $metaPageIds)))));
                }
            }
        }
        
        // Get reporter QA status map
        $reporterQaStatusByIssue = loadReporterQaStatusMapByIssueIds($db, [$id]);
        $reporterQaStatusMap = $reporterQaStatusByIssue[$id] ?? [];
        if (empty($reporterQaStatusMap) && isset($meta['reporter_qa_status_map'])) {
            $reporterQaStatusMap = parseReporterQaStatusMapFromMetaValues($meta['reporter_qa_status_map']);
        }
        
        // Get all reporters
        $reporterIds = [];
        if (!empty($issueData['reporter_id'])) {
            $reporterIds[] = (int)$issueData['reporter_id'];
        }
        
        if (isset($meta['reporter_ids'])) {
            $reporterIdsData = $meta['reporter_ids'];
            if (is_array($reporterIdsData)) {
                $additionalReporterIds = array_values(array_filter(array_map('intval', $reporterIdsData)));
            } else {
                $decoded = json_decode($reporterIdsData, true);
                if (is_array($decoded)) {
                    $additionalReporterIds = array_values(array_filter(array_map('intval', $decoded)));
                } else {
                    $additionalReporterIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $reporterIdsData)))));
                }
            }
            $reporterIds = array_unique(array_merge($reporterIds, $additionalReporterIds));
        }
        
        // Get QA status keys
        $qaStatusKeys = [];
        if (!empty($reporterQaStatusMap)) {
            foreach ($reporterQaStatusMap as $statusValues) {
                $vals = is_array($statusValues) ? $statusValues : [$statusValues];
                foreach ($vals as $statusKey) {
                    $key = strtolower(trim((string)$statusKey));
                    if ($key !== '') $qaStatusKeys[] = $key;
                }
            }
            $qaStatusKeys = array_values(array_unique($qaStatusKeys));
        }
        if (empty($qaStatusKeys) && isset($meta['qa_status'])) {
            $qaStatusData = $meta['qa_status'];
            if (is_array($qaStatusData)) {
                if (count($qaStatusData) === 1 && is_string($qaStatusData[0]) && $qaStatusData[0][0] === '[') {
                    $decoded = json_decode($qaStatusData[0], true);
                    $qaStatusKeys = is_array($decoded) ? $decoded : $qaStatusData;
                } else {
                    $qaStatusKeys = $qaStatusData;
                }
            } else {
                $decoded = json_decode($qaStatusData, true);
                if (is_array($decoded)) {
                    $qaStatusKeys = $decoded;
                } else {
                    $qaStatusKeys = array_filter(array_map('trim', explode(',', $qaStatusData)));
                }
            }
        }
        
        // Check if issue has comments
        $commentStmt = $db->prepare("SELECT COUNT(*) FROM issue_comments WHERE issue_id = ?");
        $commentStmt->execute([$id]);
        $hasComments = $commentStmt->fetchColumn() > 0;
        
        // Check if tester can delete (for UI purposes)
        $canTesterDelete = true;
        if ($isTesterRole) {
            $blockedIds = getTesterBlockedIssueIdsForDelete($db, $projectId, [$id]);
            $canTesterDelete = empty($blockedIds);
        }
        
        // Get latest history ID for conflict detection
        $historyStmt = $db->prepare("SELECT MAX(id) FROM issue_history WHERE issue_id = ?");
        $historyStmt->execute([$id]);
        $latestHistoryId = (int)$historyStmt->fetchColumn();
        
        $descriptionHtml = (string)($issueData['description'] ?? '');
        if ($userRole === 'client' && function_exists('rewrite_html_public_image_urls')) {
            $descriptionHtml = rewrite_html_public_image_urls($descriptionHtml);
        }

        $updatedIssue = [
            'id' => (int)$issueData['id'],
            'issue_key' => $issueData['issue_key'] ?? ($projectCode . '-' . $issueData['id']), // Fallback if column doesn't exist
            'title' => $issueData['title'],
            'description' => $descriptionHtml,
            'common_title' => isset($meta['common_title']) && is_array($meta['common_title']) ? $meta['common_title'][0] : '',
            'status' => $issueData['status_name'] ?? 'open',
            'status_id' => (int)$issueData['status_id'],
            'qa_status' => $qaStatusKeys,
            'severity' => isset($meta['severity']) ? (is_array($meta['severity']) ? $meta['severity'][0] : $meta['severity']) : 'medium',
            'priority' => isset($meta['priority']) ? (is_array($meta['priority']) ? $meta['priority'][0] : $meta['priority']) : 'medium',
            'pages' => $pageIds,
            'grouped_urls' => isset($meta['grouped_urls']) && is_array($meta['grouped_urls']) ? $meta['grouped_urls'] : [],
            'reporter_name' => $issueData['reporter_name'],
            'qa_name' => $issueData['qa_name'] ?? null,
            'assignee_id' => (int)($issueData['assignee_id'] ?? 0) ?: null,
            'assignee_ids' => isset($meta['assignee_ids']) ? array_values(array_filter(array_map('intval', $meta['assignee_ids']), function($v){ return $v > 0; })) : ((int)($issueData['assignee_id'] ?? 0) ? [(int)$issueData['assignee_id']] : []),
            'page_id' => !empty($pageIds) ? $pageIds[0] : null,
            'client_ready' => (int)($issueData['client_ready'] ?? 0),
            'environments' => isset($meta['environments']) && is_array($meta['environments']) ? $meta['environments'] : [],
            'usersaffected' => isset($meta['usersaffected']) && is_array($meta['usersaffected']) ? $meta['usersaffected'] : [],
            'wcagsuccesscriteria' => isset($meta['wcagsuccesscriteria']) && is_array($meta['wcagsuccesscriteria']) ? $meta['wcagsuccesscriteria'] : [],
            'wcagsuccesscriterianame' => isset($meta['wcagsuccesscriterianame']) && is_array($meta['wcagsuccesscriterianame']) ? $meta['wcagsuccesscriterianame'] : [],
            'wcagsuccesscriterialevel' => isset($meta['wcagsuccesscriterialevel']) && is_array($meta['wcagsuccesscriterialevel']) ? $meta['wcagsuccesscriterialevel'] : [],
            'gigw30' => isset($meta['gigw30']) && is_array($meta['gigw30']) ? $meta['gigw30'] : [],
            'is17802' => isset($meta['is17802']) && is_array($meta['is17802']) ? $meta['is17802'] : [],
            'reporters' => $reporterIds,
            'reporter_qa_status_map' => $reporterQaStatusMap,
            'has_comments' => $hasComments,
            'can_tester_delete' => $canTesterDelete,
            'created_at' => $issueData['created_at'],
            'updated_at' => $issueData['updated_at'],
            'latest_history_id' => $latestHistoryId,
            'metadata' => $meta // Unified structure for metadata rendering
        ];
        
        // Add custom metadata fields
        if (isset($meta)) {
            foreach ($meta as $key => $values) {
                if (!isset($updatedIssue[$key])) {
                    $updatedIssue[$key] = is_array($values) && count($values) === 1 ? $values[0] : $values;
                }
            }
        }

        // Invalidate get_all and common_get_all cache for this project
        if (function_exists('apcu_delete')) {
            apcu_delete("issues_all_{$projectId}_staff");
            apcu_delete("issues_all_{$projectId}_client");
            apcu_delete("issues_common_all_{$projectId}_staff");
            apcu_delete("issues_common_all_{$projectId}_client");
        }

        jsonResponse(['success' => true, 'id' => $id, 'issue_key' => $issueKey, 'issue' => $updatedIssue]);
        
        // Debug logging
        error_log("Issue API: Successfully processed issue creation/update. ID: $id, Key: $issueKey, Action: $action");
    }

    if ($method === 'POST' && $action === 'bulk_client_ready') {
        $issueIds = $_POST['issue_ids'] ?? '';
        $clientReady = (int)($_POST['client_ready'] ?? 0);
        
        $ids = is_array($issueIds) ? $issueIds : array_filter(array_map('intval', explode(',', $issueIds)));
        if (empty($ids)) jsonError('issue_ids required', 400);
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$clientReady], $ids, [$projectId]);
        
        try {
            $stmt = $db->prepare("UPDATE issues SET client_ready = ?, updated_at = NOW() WHERE id IN ($placeholders) AND project_id = ?");
            $stmt->execute($params);

            if ($clientReady === 1) {
                foreach ($ids as $snapshotIssueId) {
                    publishIssueClientSnapshot($db, (int) $snapshotIssueId);
                }
            }
            
            // Invalidate get_all cache
            if (function_exists('apcu_delete')) {
                apcu_delete("issues_all_{$projectId}_staff");
                apcu_delete("issues_all_{$projectId}_client");
            }

            jsonResponse(['success' => true, 'updated' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            error_log("Bulk client ready update error: " . $e->getMessage());
            jsonError('Failed to update issues', 500);
        }
    }

    if ($method === 'POST' && $action === 'delete') {
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : array_filter(array_map('intval', explode(',', $idsRaw)));
        if (empty($ids)) jsonError('ids required', 400);

        // IDOR Fix: Verify all IDs belong to the current projectId
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $checkStmt = $db->prepare("SELECT id FROM issues WHERE id IN ($placeholders) AND project_id = ?");
        $checkStmt->execute(array_merge($ids, [$projectId]));
        $verifiedIds = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($verifiedIds) !== count($ids)) {
            // Some IDs are invalid or belong to other projects.
            // We only process the verified ones or block entirely.
            // Blocking entirely is safer for security.
            jsonError('One or more Issue IDs are invalid or belong to another project.', 403);
        }

        if ($isTesterRole) {
            $blockedIds = getTesterBlockedIssueIdsForDelete($db, $projectId, $ids);
            if (!empty($blockedIds)) {
                jsonResponse([
                    'error' => 'Testers can delete only when QA status is empty and no comments exist on the issue.',
                    'blocked_issue_ids' => $blockedIds
                ], 403);
            }
        }

        $htmlBlocksForCleanup = collectIssueDeleteHtmlBlocks($db, $projectId, $ids);

        $params = array_merge($ids, [$projectId]);

        $db->beginTransaction();
        try {
            // Remove dependent rows first to avoid FK failures.
            $db->prepare("DELETE FROM issue_metadata WHERE issue_id IN ($placeholders)")->execute($ids);
            $db->prepare("DELETE FROM issue_pages WHERE issue_id IN ($placeholders)")->execute($ids);
            $db->prepare("DELETE FROM issue_comments WHERE issue_id IN ($placeholders)")->execute($ids);
            $db->prepare("DELETE FROM issue_history WHERE issue_id IN ($placeholders)")->execute($ids);
            $db->prepare("DELETE FROM issue_reporter_qa_status WHERE issue_id IN ($placeholders)")->execute($ids);

            $delCommon = $db->prepare("DELETE FROM common_issues WHERE issue_id IN ($placeholders) AND project_id = ?");
            $delCommon->execute($params);

            $stmt = $db->prepare("DELETE FROM issues WHERE id IN ($placeholders) AND project_id = ?");
            $stmt->execute($params);

            $db->commit();
            
            // Log to UI Activity Log
            try {
                logActivity($db, $userId, 'bulk_delete_issues', 'project', $projectId, [
                    'count' => count($ids),
                    'ids' => $ids
                ]);
            } catch (Exception $e) {}

            // Security Logging: Log issue deletion
            try {
                $auditLogger = new AuditLogger();
                $auditLogger->logAdminActivity(
                    $userId,
                    'issue_bulk_delete',
                    "User deleted issues: " . implode(', ', $ids) . " in project " . $projectId,
                    null,
                    true
                );
            } catch (Exception $al_e) {
                error_log("Failed to log issue deletion to audit log: " . $al_e->getMessage());
            }

            cleanupIssueUploadsFromHtmlBlocks($htmlBlocksForCleanup);
            // Invalidate get_all cache
            if (function_exists('apcu_delete')) {
                apcu_delete("issues_all_{$projectId}_staff");
                apcu_delete("issues_all_{$projectId}_client");
            }
            jsonResponse(['success' => true, 'deleted' => $stmt->rowCount()]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    if ($method === 'GET' && $action === 'common_list') {
        $orderByClause = columnExists($db, 'issues', 'issue_key') ? "ORDER BY i.issue_key ASC" : "ORDER BY i.id ASC";
        $clientReadyClause = $userRole === 'client' ? ' AND i.client_ready = 1' : '';

        $stmt = $db->prepare("
            SELECT ci.id as common_id, ci.title as common_title, i.*, s.name AS status_name
            FROM common_issues ci
            JOIN issues i ON ci.issue_id = i.id
            LEFT JOIN issue_statuses s ON s.id = i.status_id
            WHERE ci.project_id = ?
            {$clientReadyClause}
            $orderByClause
        ");
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $issueIds = array_map(function($r){ return (int)$r['id']; }, $rows);
        $metaMap = [];
        $commentCountMap = [];
        if (!empty($issueIds)) {
            $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
            $metaStmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders)");
            $metaStmt->execute($issueIds);
            while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
                $iid = (int)$m['issue_id'];
                if (!isset($metaMap[$iid])) $metaMap[$iid] = [];
                if (!isset($metaMap[$iid][$m['meta_key']])) $metaMap[$iid][$m['meta_key']] = [];
                $metaMap[$iid][$m['meta_key']][] = $m['meta_value'];
            }
            $commentStmt = $db->prepare("SELECT issue_id, COUNT(*) AS c FROM issue_comments WHERE issue_id IN ($placeholders) GROUP BY issue_id");
            $commentStmt->execute($issueIds);
            while ($c = $commentStmt->fetch(PDO::FETCH_ASSOC)) {
                $commentCountMap[(int)$c['issue_id']] = (int)$c['c'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $iid = (int)$r['id'];
            $meta = $metaMap[$iid] ?? [];
            $pages = $meta['page_ids'] ?? [];
            $statusValue = resolveIssueStatusDisplayValue($db, $meta['issue_status'][0] ?? '', $r['status_name'] ?? '', (int)($r['status_id'] ?? 0));
            $qaStatusValues = ($meta['qa_status'] ?? []);
            $hasComments = (($commentCountMap[$iid] ?? 0) > 0);
            $isOpen = isIssueOpenStatusValue($statusValue);
            $hasQaStatus = isQaStatusMetaFilled($qaStatusValues);
            $canTesterDelete = (!$hasComments && !($isOpen && $hasQaStatus));
            $descriptionHtml = (string)($r['description'] ?? '');
            if ($userRole === 'client' && function_exists('rewrite_html_public_image_urls')) {
                $descriptionHtml = rewrite_html_public_image_urls($descriptionHtml);
            }

            $out[] = [
                'id' => (int)$r['common_id'],
                'issue_id' => $iid,
                'issue_key' => $r['issue_key'] ?? ('ISS-' . $iid),
                'title' => $r['common_title'] ?: $r['title'],
                'description' => $descriptionHtml,
                'pages' => $pages,
                'status' => $statusValue,
                'qa_status' => $qaStatusValues,
                'has_comments' => $hasComments,
                'can_tester_delete' => $canTesterDelete
            ];
        }
        jsonResponse(['success' => true, 'common' => $out]);
    }

    if ($method === 'POST' && ($action === 'common_create' || $action === 'common_update')) {
        $commonId = (int)($_POST['id'] ?? 0);
        if ($action === 'common_update' && $isTesterRole && hasActiveRegressionRound($db, $projectId)) {
            jsonError('Issue details are locked for testers while a regression round is in progress.', 403);
        }
        $title = trim($_POST['title'] ?? '');
        if (!$title) jsonError('title is required', 400);
        $description = $_POST['description'] ?? '';
        
        // Validate description length (TEXT column can hold ~65,535 characters)
        if (strlen($description) > 65000) {
            jsonError('Description is too long. Please reduce the content or remove large images.', 400);
        }
        
        $pageIds = parseArrayInput($_POST['pages'] ?? []);
        $pageSelection = normalizeIssuePageSelection($db, $projectId, 0, $pageIds);
        $pageIds = $pageSelection['page_ids'];
        $statusId = getStatusId($db, 'Open');
        $priorityId = getPriorityId($db, 'Medium');
        $typeId = getDefaultTypeId($db, $projectId);
        $issueKey = getIssueKey($db, $projectId);
        $severity = 'major';

        if ($action === 'common_create') {
            $stmt = $db->prepare("INSERT INTO issues (project_id, issue_key, title, description, type_id, priority_id, status_id, reporter_id, severity, is_final, common_issue_title) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
            $stmt->execute([$projectId, $issueKey, $title, $description, $typeId, $priorityId, $statusId, $userId, $severity, $title]);
            $issueId = (int)$db->lastInsertId();
            
            if (columnExists($db, 'common_issues', 'created_by')) {
                $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title, created_by) VALUES (?, ?, ?, ?)");
                $ins->execute([$projectId, $issueId, $title, $userId]);
            } else {
                $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title) VALUES (?, ?, ?)");
                $ins->execute([$projectId, $issueId, $title]);
            }
            $commonId = (int)$db->lastInsertId();
        } else {
            if (!$commonId) jsonError('id is required', 400);
            $stmt = $db->prepare("SELECT issue_id FROM common_issues WHERE id = ? AND project_id = ?");
            $stmt->execute([$commonId, $projectId]);
            $issueId = (int)$stmt->fetchColumn();
            if (!$issueId) jsonError('Common issue not found', 404);
            $upIssue = $db->prepare("UPDATE issues SET title = ?, description = ?, common_issue_title = ?, updated_at = NOW() WHERE id = ? AND project_id = ?");
            $upIssue->execute([$title, $description, $title, $issueId, $projectId]);
            $up = $db->prepare("UPDATE common_issues SET title = ?, updated_at = NOW() WHERE id = ?");
            $up->execute([$title, $commonId]);
        }

        replaceMeta($db, $issueId, 'page_ids', $pageIds);
        replaceMeta($db, $issueId, 'common_title', [$title]);

        jsonResponse(['success' => true, 'id' => $commonId, 'issue_id' => $issueId]);
    }

    if ($method === 'POST' && $action === 'common_delete') {
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : array_filter(array_map('intval', explode(',', $idsRaw)));
        if (empty($ids)) jsonError('ids required', 400);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT issue_id FROM common_issues WHERE id IN ($placeholders) AND project_id = ?");
        $stmt->execute(array_merge($ids, [$projectId]));
        $issueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $htmlBlocksForCleanup = !empty($issueIds) ? collectIssueDeleteHtmlBlocks($db, $projectId, array_map('intval', $issueIds)) : [];

        if ($isTesterRole && !empty($issueIds)) {
            $issueIds = array_map('intval', $issueIds);
            $blockedIds = getTesterBlockedIssueIdsForDelete($db, $projectId, $issueIds);
            if (!empty($blockedIds)) {
                jsonResponse([
                    'error' => 'Testers can delete only when QA status is empty and no comments exist on the issue.',
                    'blocked_issue_ids' => $blockedIds
                ], 403);
            }
        }

        $del = $db->prepare("DELETE FROM common_issues WHERE id IN ($placeholders) AND project_id = ?");
        $del->execute(array_merge($ids, [$projectId]));
        if (!empty($issueIds)) {
            $issueIds = array_map('intval', $issueIds);
            $ph = implode(',', array_fill(0, count($issueIds), '?'));
            
            // Cleanup metadata, pages, history, comments, and the issues themselves
            $db->prepare("DELETE FROM issue_metadata WHERE issue_id IN ($ph)")->execute($issueIds);
            $db->prepare("DELETE FROM issue_pages WHERE issue_id IN ($ph)")->execute($issueIds);
            $db->prepare("DELETE FROM issue_comments WHERE issue_id IN ($ph)")->execute($issueIds);
            $db->prepare("DELETE FROM issue_history WHERE issue_id IN ($ph)")->execute($issueIds);
            $db->prepare("DELETE FROM issue_reporter_qa_status WHERE issue_id IN ($ph)")->execute($issueIds);
            
            $db->prepare("DELETE FROM issues WHERE id IN ($ph) AND project_id = ?")->execute(array_merge($issueIds, [$projectId]));
        }

        // Security Logging: Log common issue deletion
        try {
            $auditLogger = new AuditLogger();
            $auditLogger->logAdminActivity(
                $userId,
                'common_issue_bulk_delete',
                "User deleted common issues: " . implode(', ', $ids) . " (Issue IDs: " . implode(', ', $issueIds) . ") in project " . $projectId,
                null,
                true
            );
        } catch (Exception $al_e) {
            error_log("Failed to log common issue deletion to audit log: " . $al_e->getMessage());
        }

        cleanupIssueUploadsFromHtmlBlocks($htmlBlocksForCleanup);
        
        // Log to UI Activity Log for Common Issue deletion
        try {
            logActivity($db, $userId, 'bulk_delete_common_issues', 'project', $projectId, [
                'count' => count($ids),
                'issue_count' => count($issueIds)
            ]);
        } catch (Exception $e) {}

        jsonResponse(['success' => true]);
    }

    jsonError('Invalid action', 400);
} catch (Exception $e) {
    error_log('issues api error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonError('Server error', 500);
}
