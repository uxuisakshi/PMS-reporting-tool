<?php

function pmsTableExists($db, $tableName, $refresh = false) {
    static $cache = [];

    $tableName = trim((string) $tableName);
    if ($tableName === '') {
        return false;
    }

    if (!$refresh && array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$tableName]);
        $cache[$tableName] = (bool) ((int) ($stmt ? $stmt->fetchColumn() : 0));
    } catch (Exception $e) {
        error_log($tableName . ' existence check failed: ' . $e->getMessage());
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function issueClientSnapshotTableExists($db, $refresh = false) {
    return pmsTableExists($db, 'issue_client_snapshots', $refresh);
}

function ensureIssueClientSnapshotTable($db) {
    static $ensured = false;

    if (issueClientSnapshotTableExists($db)) {
        return true;
    }

    if ($ensured) {
        return false;
    }

    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS issue_client_snapshots (
                issue_id INT(11) NOT NULL,
                project_id INT(11) NOT NULL,
                snapshot_json LONGTEXT NOT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                pages_json LONGTEXT DEFAULT NULL,
                published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (issue_id),
                KEY idx_project_published (project_id, published_at),
                CONSTRAINT fk_issue_client_snapshots_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_client_snapshots_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Exception $e) {
        error_log('issue_client_snapshots table check failed: ' . $e->getMessage());
    }

    $ensured = true;
    return issueClientSnapshotTableExists($db, true);
}

function normalizeIssueSnapshotProjectIds($projectScope) {
    if ($projectScope === null) {
        return [];
    }

    if (!is_array($projectScope)) {
        $projectScope = [$projectScope];
    }

    return array_values(array_unique(array_filter(array_map('intval', $projectScope), static function ($value) {
        return $value > 0;
    })));
}

function decodeIssueSnapshotJson($json, $fallback = []) {
    if (!is_string($json) || trim($json) === '') {
        return $fallback;
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function loadIssueSnapshotMetadata($db, $issueIds) {
    $issueIds = array_values(array_unique(array_filter(array_map('intval', $issueIds))));
    if (empty($issueIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $stmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders) ORDER BY id ASC");
    $stmt->execute($issueIds);

    $metaMap = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $issueId = (int) ($row['issue_id'] ?? 0);
        $metaKey = (string) ($row['meta_key'] ?? '');
        if ($issueId <= 0 || $metaKey === '') {
            continue;
        }
        if (!isset($metaMap[$issueId])) {
            $metaMap[$issueId] = [];
        }
        if (!isset($metaMap[$issueId][$metaKey])) {
            $metaMap[$issueId][$metaKey] = [];
        }
        $metaMap[$issueId][$metaKey][] = (string) ($row['meta_value'] ?? '');
    }

    return $metaMap;
}

function loadIssueSnapshotPages($db, $issueIds) {
    $issueIds = array_values(array_unique(array_filter(array_map('intval', $issueIds))));
    if (empty($issueIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $stmt = $db->prepare(
        "SELECT ip.issue_id, pp.id, pp.page_name, pp.page_number, pp.url
         FROM issue_pages ip
         INNER JOIN project_pages pp ON pp.id = ip.page_id
         WHERE ip.issue_id IN ($placeholders)
         ORDER BY pp.page_number ASC, pp.page_name ASC"
    );
    $stmt->execute($issueIds);

    $pageMap = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $issueId = (int) ($row['issue_id'] ?? 0);
        if ($issueId <= 0) {
            continue;
        }
        if (!isset($pageMap[$issueId])) {
            $pageMap[$issueId] = [];
        }
        $pageMap[$issueId][] = [
            'id' => (int) ($row['id'] ?? 0),
            'page_name' => (string) ($row['page_name'] ?? ''),
            'page_number' => (string) ($row['page_number'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
        ];
    }

    return $pageMap;
}

function loadIssueSnapshotBaseRows($db, $issueIds) {
    $issueIds = array_values(array_unique(array_filter(array_map('intval', $issueIds))));
    if (empty($issueIds)) {
        return [];
    }

    $historyField = pmsTableExists($db, 'issue_history')
        ? '(SELECT COALESCE(MAX(ih.id), 0) FROM issue_history ih WHERE ih.issue_id = i.id)'
        : '0';

    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $stmt = $db->prepare(
        "SELECT i.*, 
                s.name AS status_name,
                s.color AS status_color,
                p.name AS priority_name,
                reporter.full_name AS reporter_name,
                assignee.full_name AS qa_name,
                $historyField AS latest_history_id
         FROM issues i
         LEFT JOIN issue_statuses s ON s.id = i.status_id
         LEFT JOIN issue_priorities p ON p.id = i.priority_id
         LEFT JOIN users reporter ON reporter.id = i.reporter_id
         LEFT JOIN users assignee ON assignee.id = i.assignee_id
         WHERE i.id IN ($placeholders)"
    );
    $stmt->execute($issueIds);

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[(int) ($row['id'] ?? 0)] = $row;
    }

    return $rows;
}

function buildIssueClientSnapshotPayload($db, $issueId) {
    $issueRows = loadIssueSnapshotBaseRows($db, [$issueId]);
    $issue = $issueRows[$issueId] ?? null;
    if (!$issue) {
        return null;
    }

    return [
        'issue' => $issue,
        'meta' => loadIssueSnapshotMetadata($db, [$issueId])[$issueId] ?? [],
        'pages' => loadIssueSnapshotPages($db, [$issueId])[$issueId] ?? [],
    ];
}

function publishIssueClientSnapshot($db, $issueId) {
    if ($issueId <= 0) {
        return false;
    }

    if (!ensureIssueClientSnapshotTable($db)) {
        return false;
    }
    $payload = buildIssueClientSnapshotPayload($db, $issueId);
    if (!$payload || empty($payload['issue'])) {
        return false;
    }

    $issue = $payload['issue'];
    $stmt = $db->prepare(
        "INSERT INTO issue_client_snapshots (issue_id, project_id, snapshot_json, metadata_json, pages_json, published_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            project_id = VALUES(project_id),
            snapshot_json = VALUES(snapshot_json),
            metadata_json = VALUES(metadata_json),
            pages_json = VALUES(pages_json),
            published_at = NOW()"
    );

    return $stmt->execute([
        $issueId,
        (int) ($issue['project_id'] ?? 0),
        json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($payload['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($payload['pages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function getIssueClientSnapshot($db, $issueId) {
    if ($issueId <= 0) {
        return null;
    }

    if (!ensureIssueClientSnapshotTable($db)) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT issue_id, project_id, snapshot_json, metadata_json, pages_json, published_at
         FROM issue_client_snapshots
         WHERE issue_id = ?
         LIMIT 1"
    );
    $stmt->execute([$issueId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'issue' => decodeIssueSnapshotJson($row['snapshot_json'] ?? '', []),
        'meta' => decodeIssueSnapshotJson($row['metadata_json'] ?? '', []),
        'pages' => decodeIssueSnapshotJson($row['pages_json'] ?? '', []),
        'published_at' => (string) ($row['published_at'] ?? ''),
        'issue_id' => (int) ($row['issue_id'] ?? 0),
        'project_id' => (int) ($row['project_id'] ?? 0),
        'source' => 'snapshot',
    ];
}

function isIssueVisibleToClientThroughSnapshot($db, $issueId, $projectId = 0) {
    $snapshot = getIssueClientSnapshot($db, $issueId);
    if (!$snapshot) {
        return false;
    }
    if ($projectId > 0 && (int) ($snapshot['project_id'] ?? 0) !== $projectId) {
        return false;
    }
    return !empty($snapshot['issue']);
}

function issueRecordMatchesPageFilter($record, $pageId) {
    if ($pageId <= 0) {
        return true;
    }

    $issue = $record['issue'] ?? [];
    if ((int) ($issue['page_id'] ?? 0) === $pageId) {
        return true;
    }

    foreach (($record['pages'] ?? []) as $page) {
        if ((int) ($page['id'] ?? 0) === $pageId) {
            return true;
        }
    }

    $metaPageIds = $record['meta']['page_ids'] ?? [];
    foreach ((array) $metaPageIds as $metaPageId) {
        if ((int) $metaPageId === $pageId) {
            return true;
        }
    }

    return false;
}

function getClientVisibleIssueRecords($db, $projectScope, $options = []) {
    $snapshotTableAvailable = ensureIssueClientSnapshotTable($db);

    $projectIds = normalizeIssueSnapshotProjectIds($projectScope);
    if (empty($projectIds)) {
        return [];
    }

    $pageId = (int) ($options['page_id'] ?? 0);
    $orderBy = (string) ($options['order_by'] ?? 'i.issue_key ASC, i.id ASC');
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

    $historyField = pmsTableExists($db, 'issue_history')
        ? '(SELECT COALESCE(MAX(ih.id), 0) FROM issue_history ih WHERE ih.issue_id = i.id)'
        : '0';

    $liveSql =
        "SELECT i.*, 
                s.name AS status_name,
                s.color AS status_color,
                p.name AS priority_name,
                reporter.full_name AS reporter_name,
                assignee.full_name AS qa_name,
                $historyField AS latest_history_id
         FROM issues i
         LEFT JOIN issue_statuses s ON s.id = i.status_id
         LEFT JOIN issue_priorities p ON p.id = i.priority_id
         LEFT JOIN users reporter ON reporter.id = i.reporter_id
         LEFT JOIN users assignee ON assignee.id = i.assignee_id
         WHERE i.project_id IN ($placeholders)
           AND i.client_ready = 1";

    $params = $projectIds;
    if ($pageId > 0) {
        $liveSql .= " AND (
            EXISTS (SELECT 1 FROM issue_pages ip WHERE ip.issue_id = i.id AND ip.page_id = ?)
            OR (
                i.page_id = ?
                AND NOT EXISTS (SELECT 1 FROM issue_pages ip2 WHERE ip2.issue_id = i.id)
            )
        )";
        $params[] = $pageId;
        $params[] = $pageId;
    }
    $liveSql .= " ORDER BY $orderBy";

    $liveStmt = $db->prepare($liveSql);
    $liveStmt->execute($params);
    $liveIssues = $liveStmt->fetchAll(PDO::FETCH_ASSOC);
    $liveIssueIds = array_values(array_unique(array_map('intval', array_column($liveIssues, 'id'))));
    $liveMeta = loadIssueSnapshotMetadata($db, $liveIssueIds);
    $livePages = loadIssueSnapshotPages($db, $liveIssueIds);

    $records = [];
    $liveById = [];
    foreach ($liveIssues as $issue) {
        $issueId = (int) ($issue['id'] ?? 0);
        if ($issueId <= 0) {
            continue;
        }
        $record = [
            'issue' => $issue,
            'meta' => $liveMeta[$issueId] ?? [],
            'pages' => $livePages[$issueId] ?? [],
            'published_at' => (string) ($issue['updated_at'] ?? $issue['created_at'] ?? ''),
            'issue_id' => $issueId,
            'project_id' => (int) ($issue['project_id'] ?? 0),
            'source' => 'live',
        ];
        $records[] = $record;
        $liveById[$issueId] = true;
    }

    if ($snapshotTableAvailable) {
        $snapshotStmt = $db->prepare(
            "SELECT s.issue_id, s.project_id, s.snapshot_json, s.metadata_json, s.pages_json, s.published_at,
                    COALESCE(i.client_ready, 0) AS current_client_ready
             FROM issue_client_snapshots s
             LEFT JOIN issues i ON i.id = s.issue_id
             WHERE s.project_id IN ($placeholders)
             ORDER BY s.published_at DESC, s.issue_id ASC"
        );
        $snapshotStmt->execute($projectIds);
        while ($row = $snapshotStmt->fetch(PDO::FETCH_ASSOC)) {
            $issueId = (int) ($row['issue_id'] ?? 0);
            if ($issueId <= 0 || isset($liveById[$issueId]) || (int) ($row['current_client_ready'] ?? 0) === 1) {
                continue;
            }

            $record = [
                'issue' => decodeIssueSnapshotJson($row['snapshot_json'] ?? '', []),
                'meta' => decodeIssueSnapshotJson($row['metadata_json'] ?? '', []),
                'pages' => decodeIssueSnapshotJson($row['pages_json'] ?? '', []),
                'published_at' => (string) ($row['published_at'] ?? ''),
                'issue_id' => $issueId,
                'project_id' => (int) ($row['project_id'] ?? 0),
                'source' => 'snapshot',
            ];

            if (empty($record['issue']) || !issueRecordMatchesPageFilter($record, $pageId)) {
                continue;
            }

            $records[] = $record;
        }
    }

    usort($records, static function ($left, $right) {
        $leftIssue = $left['issue'] ?? [];
        $rightIssue = $right['issue'] ?? [];
        $leftKey = (string) ($leftIssue['issue_key'] ?? '');
        $rightKey = (string) ($rightIssue['issue_key'] ?? '');
        $keyCompare = strnatcasecmp($leftKey, $rightKey);
        if ($keyCompare !== 0) {
            return $keyCompare;
        }
        return ((int) ($leftIssue['id'] ?? 0)) <=> ((int) ($rightIssue['id'] ?? 0));
    });

    return $records;
}
