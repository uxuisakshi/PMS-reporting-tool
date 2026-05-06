<?php
/**
 * Helper functions for api/issues.php
 */

function jsonResponse($data, $statusCode = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    // Ensure all data is UTF-8 to prevent json_encode failure
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        error_log("json_encode failed: " . json_last_error_msg());
        echo json_encode(['error' => 'JSON encoding failed', 'msg' => json_last_error_msg()]);
    } else {
        echo $json;
    }
    exit;
}

function jsonError($message, $statusCode = 400) {
    jsonResponse(['error' => $message], $statusCode);
}

function parseArrayInput($value) {
    if ($value === null) return [];
    if (is_array($value)) return array_values(array_filter($value, function($v){ return $v !== '' && $v !== null; }));
    $value = trim((string)$value);
    if ($value === '') return [];
    if ($value[0] === '[') {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter($decoded, function($v){ return $v !== '' && $v !== null; }));
        }
    }
    return array_values(array_filter(array_map('trim', explode(',', $value)), function($v){ return $v !== ''; }));
}

function getStatusId($db, $name) {
    if (!$name) return null;
    static $cache = [];
    $map = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed'
    ];
    $target = $map[strtolower($name)] ?? $name;
    if (isset($cache[$target])) return $cache[$target];
    $stmt = $db->prepare("SELECT id FROM issue_statuses WHERE name = ? LIMIT 1");
    $stmt->execute([$target]);
    $id = $stmt->fetchColumn();
    $cache[$target] = $id ?: null;
    return $cache[$target];
}

function getPriorityId($db, $name) {
    if (!$name) return null;
    static $cache = [];
    $map = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
        'critical' => 'Critical'
    ];
    $target = $map[strtolower($name)] ?? $name;
    if (isset($cache[$target])) return $cache[$target];
    $stmt = $db->prepare("SELECT id FROM issue_priorities WHERE name = ? LIMIT 1");
    $stmt->execute([$target]);
    $id = $stmt->fetchColumn();
    $cache[$target] = $id ?: null;
    return $cache[$target];
}

function replaceMeta($db, $issueId, $key, $values) {
    $db->prepare("DELETE FROM issue_metadata WHERE issue_id = ? AND meta_key = ?")->execute([$issueId, $key]);
    if (empty($values)) return;
    $rows = [];
    $params = [];
    foreach ($values as $v) {
        $val = is_scalar($v) ? (string)$v : json_encode($v);
        if ($val === '') continue;
        $rows[] = '(?, ?, ?)';
        $params[] = $issueId;
        $params[] = $key;
        $params[] = $val;
    }
    if (empty($rows)) return;
    $db->prepare("INSERT INTO issue_metadata (issue_id, meta_key, meta_value) VALUES " . implode(',', $rows))->execute($params);
}

$_metaBatch = [];
function queueMeta($issueId, $key, $values) {
    global $_metaBatch;
    $_metaBatch[] = ['issue_id' => $issueId, 'key' => $key, 'values' => $values];
}

function flushMetaBatch($db) {
    global $_metaBatch;
    if (empty($_metaBatch)) return;
    $deleteMap = [];
    $insertRows = [];
    $insertParams = [];
    foreach ($_metaBatch as $item) {
        $deleteMap[$item['issue_id']][] = $item['key'];
        foreach ((array)$item['values'] as $v) {
            $val = is_scalar($v) ? (string)$v : json_encode($v);
            if ($val === '') continue;
            $insertRows[] = '(?, ?, ?)';
            $insertParams[] = $item['issue_id'];
            $insertParams[] = $item['key'];
            $insertParams[] = $val;
        }
    }
    foreach ($deleteMap as $issueId => $keys) {
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $db->prepare("DELETE FROM issue_metadata WHERE issue_id = ? AND meta_key IN ($ph)")
           ->execute(array_merge([$issueId], $keys));
    }
    if (!empty($insertRows)) {
        $db->prepare("INSERT INTO issue_metadata (issue_id, meta_key, meta_value) VALUES " . implode(',', $insertRows))
           ->execute($insertParams);
    }
    $_metaBatch = [];
}

function ensureIssueReporterQaStatusTable($db) {
    static $isReady = null;
    if ($isReady !== null) return $isReady;
    try {
        $exists = $db->query("SHOW TABLES LIKE 'issue_reporter_qa_status'")->fetchColumn();
        if ($exists) {
            $isReady = true;
            return true;
        }
        $db->exec("
            CREATE TABLE IF NOT EXISTS issue_reporter_qa_status (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                issue_id INT NOT NULL,
                reporter_user_id INT NOT NULL,
                qa_status_key VARCHAR(100) NOT NULL,
                set_by_user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_issue_reporter (issue_id, reporter_user_id),
                KEY idx_issue_id (issue_id),
                KEY idx_reporter_user_id (reporter_user_id),
                KEY idx_qa_status_key (qa_status_key),
                CONSTRAINT fk_irqs_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
                CONSTRAINT fk_irqs_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_irqs_set_by FOREIGN KEY (set_by_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $isReady = true;
    } catch (Exception $e) {
        error_log('ensureIssueReporterQaStatusTable error: ' . $e->getMessage());
        $isReady = false;
    }
    return $isReady;
}

function parseReporterQaStatusMapInput($value) {
    $map = [];
    if ($value === null) return $map;
    if (is_string($value)) {
        $raw = trim($value);
        if ($raw === '') return $map;
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $value = $decoded;
        } else {
            return $map;
        }
    }
    if (!is_array($value)) return $map;
    foreach ($value as $reporterId => $statusValue) {
        $rid = (int)$reporterId;
        if ($rid <= 0) continue;
        $statusKeys = [];
        if (is_array($statusValue)) {
            $statusKeys = $statusValue;
        } elseif (is_string($statusValue)) {
            $raw = trim($statusValue);
            if ($raw !== '' && $raw[0] === '[') {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $statusKeys = $decoded;
                } else {
                    $statusKeys = [$statusValue];
                }
            } elseif (strpos($raw, ',') !== false) {
                $statusKeys = array_map('trim', explode(',', $raw));
            } else {
                $statusKeys = [$statusValue];
            }
        } elseif ($statusValue !== null) {
            $statusKeys = [(string)$statusValue];
        }
        $statusKeys = array_values(array_unique(array_filter(array_map(static function($v){
            return strtolower(trim((string)$v));
        }, $statusKeys), static function($v){
            return $v !== '';
        })));
        if (empty($statusKeys)) continue;
        $map[$rid] = $statusKeys;
    }
    return $map;
}

function parseReporterQaStatusMapFromMetaValues($metaValues) {
    if (!is_array($metaValues) || empty($metaValues)) return [];
    foreach ($metaValues as $value) {
        if (!is_string($value)) continue;
        $raw = trim($value);
        if ($raw === '' || $raw === 'null') continue;
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function normalizeReporterQaStatusMap($map, $reporterIds, $validStatusKeys = []) {
    $allowedReporterIds = [];
    foreach ($reporterIds as $rid) {
        $rid = (int)$rid;
        if ($rid > 0) $allowedReporterIds[$rid] = true;
    }
    $validStatusLookup = [];
    foreach ($validStatusKeys as $key) {
        $k = strtolower(trim((string)$key));
        if ($k !== '') $validStatusLookup[$k] = true;
    }

    $normalized = [];
    foreach ($map as $rid => $statusValues) {
        $rid = (int)$rid;
        if ($rid <= 0) continue;
        if (!isset($allowedReporterIds[$rid])) continue;
        $keys = is_array($statusValues) ? $statusValues : [$statusValues];
        $keys = array_values(array_unique(array_filter(array_map(static function($v){
            return strtolower(trim((string)$v));
        }, $keys), static function($v){
            return $v !== '';
        })));
        if (!empty($validStatusLookup)) {
            $keys = array_values(array_filter($keys, static function($key) use ($validStatusLookup){
                return isset($validStatusLookup[$key]);
            }));
        }
        if (empty($keys)) continue;
        $normalized[$rid] = $keys;
    }
    return $normalized;
}

function loadReporterQaStatusMapByIssueIds($db, $issueIds) {
    $result = [];
    if (empty($issueIds)) return $result;
    if (!ensureIssueReporterQaStatusTable($db)) return $result;

    $issueIds = array_values(array_filter(array_map('intval', $issueIds), function($v){ return $v > 0; }));
    if (empty($issueIds)) return $result;
    $ph = implode(',', array_fill(0, count($issueIds), '?'));
    $stmt = $db->prepare("SELECT issue_id, reporter_user_id, qa_status_key FROM issue_reporter_qa_status WHERE issue_id IN ($ph)");
    $stmt->execute($issueIds);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $iid = (int)$row['issue_id'];
        $rid = (int)$row['reporter_user_id'];
        $raw = trim((string)$row['qa_status_key']);
        if ($iid <= 0 || $rid <= 0 || $raw === '') continue;
        $statusKeys = [];
        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $statusKeys = $decoded;
            }
        }
        if (empty($statusKeys)) {
            $statusKeys = strpos($raw, ',') !== false ? explode(',', $raw) : [$raw];
        }
        $statusKeys = array_values(array_unique(array_filter(array_map(static function($v){
            return strtolower(trim((string)$v));
        }, $statusKeys), static function($v){
            return $v !== '';
        })));
        if (empty($statusKeys)) continue;
        if (!isset($result[$iid])) $result[$iid] = [];
        $result[$iid][$rid] = $statusKeys;
    }
    return $result;
}

function persistIssueReporterQaStatuses($db, $issueId, $map, $actorUserId) {
    if (!ensureIssueReporterQaStatusTable($db)) return false;
    $issueId = (int)$issueId;
    $actorUserId = (int)$actorUserId;
    if ($issueId <= 0) return false;

    $db->prepare("DELETE FROM issue_reporter_qa_status WHERE issue_id = ?")->execute([$issueId]);
    if (empty($map)) return true;

    $ins = $db->prepare("
        INSERT INTO issue_reporter_qa_status (issue_id, reporter_user_id, qa_status_key, set_by_user_id)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($map as $rid => $statusValues) {
        $rid = (int)$rid;
        if ($rid <= 0) continue;
        $statusKeys = is_array($statusValues) ? $statusValues : [$statusValues];
        $statusKeys = array_values(array_unique(array_filter(array_map(static function($v){
            return strtolower(trim((string)$v));
        }, $statusKeys), static function($v){
            return $v !== '';
        })));
        if (empty($statusKeys)) continue;
        $statusKeyCsv = implode(',', $statusKeys);
        $ins->execute([$issueId, $rid, $statusKeyCsv, $actorUserId > 0 ? $actorUserId : $rid]);
    }
    return true;
}

function getDefaultTypeId($db, $projectId) {
    $stmt = $db->prepare("SELECT MIN(type_id) FROM issues WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $db->query("SELECT MIN(type_id) FROM issues");
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $db->query("SELECT MIN(id) FROM issue_types");
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : 0;
}

function getIssueKey($db, $projectId) {
    $proj = $db->prepare("SELECT project_code, po_number FROM projects WHERE id = ? LIMIT 1");
    $proj->execute([$projectId]);
    $row = $proj->fetch(PDO::FETCH_ASSOC);
    $prefix = $row['project_code'] ?: ($row['po_number'] ?: 'PRJ');
    $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(issue_key, '-', -1) AS UNSIGNED)) FROM issues WHERE issue_key LIKE ?");
    $stmt->execute([$prefix . '-%']);
    $maxNum = (int)$stmt->fetchColumn();
    return $prefix . '-' . ($maxNum + 1);
}

function getAnyStatusId($db) {
    $stmt = $db->query("SELECT id FROM issue_statuses ORDER BY id ASC LIMIT 1");
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function getAnyPriorityId($db) {
    $stmt = $db->query("SELECT id FROM issue_priorities ORDER BY id ASC LIMIT 1");
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function columnExists($db, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($column));
        $cache[$key] = $stmt && $stmt->rowCount() > 0;
    }
    return $cache[$key];
}

function ensureIssuePresenceTable($db) {
    static $isReady = null;
    if ($isReady !== null) return $isReady;
    try {
        $exists = $db->query("SHOW TABLES LIKE 'issue_active_editors'")->fetchColumn();
        if ($exists) {
            $isReady = true;
        } else {
            $db->exec("
                CREATE TABLE IF NOT EXISTS issue_active_editors (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    project_id INT NOT NULL,
                    issue_id INT NOT NULL,
                    user_id INT NOT NULL,
                    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY ux_issue_active_editor (project_id, issue_id, user_id),
                    KEY idx_issue_active_last_seen (last_seen),
                    KEY idx_issue_active_issue (project_id, issue_id, last_seen)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $isReady = (bool)$db->query("SHOW TABLES LIKE 'issue_active_editors'")->fetchColumn();
        }
        if ($isReady) {
            $cols = [];
            $colStmt = $db->query("SHOW COLUMNS FROM issue_active_editors");
            while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[strtolower((string)$c['Field'])] = true;
            }
            if (!isset($cols['project_id'])) $db->exec("ALTER TABLE issue_active_editors ADD COLUMN project_id INT NOT NULL DEFAULT 0");
            if (!isset($cols['issue_id'])) $db->exec("ALTER TABLE issue_active_editors ADD COLUMN issue_id INT NOT NULL DEFAULT 0");
            if (!isset($cols['user_id'])) $db->exec("ALTER TABLE issue_active_editors ADD COLUMN user_id INT NOT NULL DEFAULT 0");
            if (!isset($cols['last_seen'])) $db->exec("ALTER TABLE issue_active_editors ADD COLUMN last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
            try { $db->exec("ALTER TABLE issue_active_editors ADD UNIQUE KEY ux_issue_active_editor (project_id, issue_id, user_id)"); } catch (Exception $e) { }
        }
    } catch (Exception $e) {
        error_log('ensureIssuePresenceTable error: ' . $e->getMessage());
        try {
            $isReady = (bool)$db->query("SHOW TABLES LIKE " . $db->quote('issue_active_editors'))->fetchColumn();
        } catch (Exception $e2) {
            $isReady = false;
        }
    }
    return $isReady;
}

function normalizeIssueStatusValue($value) {
    $raw = trim((string)$value);
    if ($raw !== '' && $raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $raw = trim((string)($decoded[0] ?? ''));
        }
    }
    $v = strtolower($raw);
    if ($v === '') return '';
    $v = str_replace('-', '_', $v);
    $v = preg_replace('/\s+/', '_', $v);
    return $v;
}

function isIssueOpenStatusValue($value) {
    return normalizeIssueStatusValue($value) === 'open';
}

function isQaStatusMetaFilled($qaValues) {
    if ($qaValues === null) return false;
    $values = is_array($qaValues) ? $qaValues : [$qaValues];
    foreach ($values as $v) {
        $s = trim((string)$v);
        if ($s === '' || $s === '[]') continue;
        if ($s[0] === '[') {
            $decoded = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (trim((string)$item) !== '') return true;
                }
                continue;
            }
        }
        return true;
    }
    return false;
}

function getTesterBlockedIssueIdsForDelete($db, $projectId, $issueIds) {
    if (empty($issueIds)) return [];
    $issueIds = array_values(array_unique(array_map('intval', $issueIds)));
    $issueIds = array_values(array_filter($issueIds, function($id){ return $id > 0; }));
    if (empty($issueIds)) return [];

    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $sql = "
        SELECT
            i.id,
            COALESCE(
                (
                    SELECT im_status.meta_value
                    FROM issue_metadata im_status
                    WHERE im_status.issue_id = i.id AND im_status.meta_key = 'issue_status'
                    ORDER BY im_status.id DESC
                    LIMIT 1
                ),
                s.name,
                ''
            ) AS issue_status_value,
            EXISTS (
                SELECT 1
                FROM issue_metadata im_qa
                WHERE im_qa.issue_id = i.id
                  AND im_qa.meta_key = 'qa_status'
                  AND TRIM(COALESCE(im_qa.meta_value, '')) <> ''
                  AND TRIM(COALESCE(im_qa.meta_value, '')) <> '[]'
            ) AS has_qa_status,
            EXISTS (
                SELECT 1
                FROM issue_comments ic
                WHERE ic.issue_id = i.id
            ) AS has_comments
        FROM issues i
        LEFT JOIN issue_statuses s ON s.id = i.status_id
        WHERE i.project_id = ? AND i.id IN ($placeholders)
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$projectId], $issueIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $blocked = [];
    foreach ($rows as $row) {
        $issueId = (int)$row['id'];
        $status = normalizeIssueStatusValue($row['issue_status_value'] ?? '');
        $isOpen = ($status === 'open');
        $hasQaStatus = !empty($row['has_qa_status']);
        $hasComments = !empty($row['has_comments']);

        if ($hasComments || ($isOpen && $hasQaStatus)) {
            $blocked[] = $issueId;
        }
    }
    return $blocked;
}

function collectIssueDeleteHtmlBlocks($db, $projectId, $issueIds) {
    $issueIds = array_values(array_unique(array_map('intval', $issueIds)));
    $issueIds = array_values(array_filter($issueIds, function ($id) { return $id > 0; }));
    if (empty($issueIds)) return [];

    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $params = array_merge($issueIds, [$projectId]);
    $blocks = [];

    $issueStmt = $db->prepare("SELECT description FROM issues WHERE id IN ($placeholders) AND project_id = ?");
    $issueStmt->execute($params);
    while ($row = $issueStmt->fetch(PDO::FETCH_ASSOC)) {
        $html = (string)($row['description'] ?? '');
        if (trim($html) !== '') $blocks[] = $html;
    }

    $commentStmt = $db->prepare("
        SELECT ic.comment_html
        FROM issue_comments ic
        INNER JOIN issues i ON i.id = ic.issue_id
        WHERE ic.issue_id IN ($placeholders) AND i.project_id = ?
    ");
    $commentStmt->execute($params);
    while ($row = $commentStmt->fetch(PDO::FETCH_ASSOC)) {
        $html = (string)($row['comment_html'] ?? '');
        if (trim($html) !== '') $blocks[] = $html;
    }

    return $blocks;
}

function cleanupIssueUploadsFromHtmlBlocks($htmlBlocks) {
    if (!function_exists('delete_local_upload_files_from_html')) return;
    foreach ($htmlBlocks as $html) {
        delete_local_upload_files_from_html((string)$html, ['uploads/issues/', 'uploads/chat/']);
    }
}

function ensureIssuePresenceSessionsTable($db) {
    static $isReady = null;
    if ($isReady !== null) return $isReady;
    try {
        $exists = $db->query("SHOW TABLES LIKE 'issue_presence_sessions'")->fetchColumn();
        if ($exists) {
            $isReady = true;
        } else {
            $db->exec("
                CREATE TABLE IF NOT EXISTS issue_presence_sessions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    project_id INT NOT NULL,
                    issue_id INT NOT NULL,
                    user_id INT NOT NULL,
                    session_token VARCHAR(64) NOT NULL,
                    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    closed_at DATETIME NULL,
                    duration_seconds INT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY ux_issue_presence_session_token (session_token),
                    KEY idx_issue_presence_issue (project_id, issue_id, opened_at),
                    KEY idx_issue_presence_user (user_id, opened_at),
                    KEY idx_issue_presence_open (closed_at, last_seen)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $isReady = (bool)$db->query("SHOW TABLES LIKE 'issue_presence_sessions'")->fetchColumn();
        }
        if ($isReady) {
            $cols = [];
            $colStmt = $db->query("SHOW COLUMNS FROM issue_presence_sessions");
            while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) { $cols[strtolower((string)$c['Field'])] = true; }
            if (!isset($cols['session_token'])) try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN session_token VARCHAR(64) NOT NULL DEFAULT ''"); } catch (Exception $e) { }
            if (!isset($cols['opened_at'])) try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Exception $e) { }
            if (!isset($cols['last_seen'])) try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Exception $e) { }
            if (!isset($cols['closed_at'])) try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN closed_at DATETIME NULL"); } catch (Exception $e) { }
            if (!isset($cols['duration_seconds'])) try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN duration_seconds INT NULL"); } catch (Exception $e) { }
        }
    } catch (Exception $e) {
        error_log('ensureIssuePresenceSessionsTable error: ' . $e->getMessage());
        try {
            $isReady = (bool)$db->query("SHOW TABLES LIKE " . $db->quote('issue_presence_sessions'))->fetchColumn();
        } catch (Exception $e2) {
            $isReady = false;
        }
    }
    return $isReady;
}

function generatePresenceSessionToken() {
    if (function_exists('random_bytes')) {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $e) {}
    }
    return md5(uniqid('iss_', true) . mt_rand());
}

function issueBelongsToProject($db, $issueId, $projectId) {
    $stmt = $db->prepare("SELECT id FROM issues WHERE id = ? AND project_id = ? LIMIT 1");
    $stmt->execute([(int)$issueId, (int)$projectId]);
    return (bool)$stmt->fetchColumn();
}

function cleanupIssuePresence($db) {
    if (!ensureIssuePresenceTable($db)) return;
    $db->exec("DELETE FROM issue_active_editors WHERE last_seen < (NOW() - INTERVAL 6 SECOND)");
}

function cleanupIssuePresenceSessions($db) {
    if (!ensureIssuePresenceSessionsTable($db)) return;
    $db->exec("
        UPDATE issue_presence_sessions
        SET closed_at = IFNULL(closed_at, NOW()),
            duration_seconds = TIMESTAMPDIFF(SECOND, opened_at, IFNULL(closed_at, NOW()))
        WHERE closed_at IS NULL
          AND last_seen < (NOW() - INTERVAL 2 MINUTE)
    ");
}

function getIssuePresenceUsers($db, $projectId, $issueId, $excludeUserId = 0) {
    if (!ensureIssuePresenceTable($db)) return [];
    $sql = "
        SELECT p.user_id, u.full_name, p.last_seen
        FROM issue_active_editors p
        JOIN users u ON u.id = p.user_id
        WHERE p.project_id = ? AND p.issue_id = ? AND p.last_seen >= (NOW() - INTERVAL 6 SECOND)
    ";
    $params = [(int)$projectId, (int)$issueId];
    if ((int)$excludeUserId > 0) {
        $sql .= " AND p.user_id <> ? ";
        $params[] = (int)$excludeUserId;
    }
    $sql .= " ORDER BY p.last_seen DESC, u.full_name ASC ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getIssuePresenceSessions($db, $projectId, $issueId) {
    if (!ensureIssuePresenceSessionsTable($db)) return [];
    $stmt = $db->prepare("
        SELECT s.user_id, u.full_name, s.opened_at, s.closed_at, s.duration_seconds
        FROM issue_presence_sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.project_id = ? AND s.issue_id = ?
        ORDER BY s.opened_at DESC
        LIMIT 200
    ");
    $stmt->execute([(int)$projectId, (int)$issueId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function logHistory($db, $issueId, $userId, $field, $oldVal, $newVal) {
    if ($oldVal === $newVal) return;
    $stmt = $db->prepare("INSERT INTO issue_history (issue_id, user_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$issueId, $userId, $field, $oldVal, $newVal]);
}

function normalizeHistoryMetaValues($values, $allowCsv = false) {
    $out = [];
    $push = function($v) use (&$out) {
        if ($v === null) return;
        $s = trim((string)$v);
        if ($s === '') return;
        $out[] = $s;
    };
    $walk = function($input) use (&$walk, $push, $allowCsv) {
        if ($input === null) return;
        if (is_array($input)) {
            foreach ($input as $v) $walk($v);
            return;
        }
        $raw = trim((string)$input);
        if ($raw === '') return;
        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $v) $walk($v);
                return;
            }
        }
        if ($allowCsv && strpos($raw, ',') !== false) {
            foreach (explode(',', $raw) as $part) $push($part);
            return;
        }
        $push($raw);
    };
    $walk($values);
    $out = array_values(array_unique(array_filter($out, function($v){ return $v !== ''; })));
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function handleMetaHistory($db, $issueId, $userId, $key, $newValues, $oldMeta) {
    $multiKeys = ['qa_status', 'page_ids', 'reporter_ids', 'grouped_urls', 'reporter_qa_status_map'];
    $allowCsv = in_array($key, $multiKeys, true);
    $oldVals = normalizeHistoryMetaValues($oldMeta[$key] ?? [], $allowCsv);
    $newVals = normalizeHistoryMetaValues($newValues, $allowCsv);
    if (json_encode($oldVals) === json_encode($newVals)) return;
    $oldVal = implode(', ', $oldVals);
    $newVal = implode(', ', $newVals);
    $stmt = $db->prepare("INSERT INTO issue_history (issue_id, user_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$issueId, $userId, "meta:$key", $oldVal, $newVal]);
}
