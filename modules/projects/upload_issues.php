<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/project_permissions.php';
require_once __DIR__ . '/../../includes/excel_reader.php';
require_once __DIR__ . '/../../includes/api_issues_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
if (!in_array($userRole, ['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Only internal team can import issues']);
    exit;
}

$db = Database::getInstance();
$projectId = (int)($_POST['project_id'] ?? 0);
$skipDuplicates = (int)($_POST['skip_duplicates'] ?? 1) === 1;
$action = (string)($_POST['action'] ?? 'import');

if ($projectId <= 0) {
    echo json_encode(['error' => 'project_id required']);
    exit;
}

if (!hasProjectAccess($db, $userId, $projectId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied for this project']);
    exit;
}

if (empty($_FILES['file']) || (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

$tmpFile = (string)$_FILES['file']['tmp_name'];
$fileName = (string)($_FILES['file']['name'] ?? '');
if (!is_uploaded_file($tmpFile)) {
    echo json_encode(['error' => 'Invalid upload']);
    exit;
}

$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'], true)) {
    echo json_encode(['error' => 'Unsupported file format. Please upload CSV or XLSX.']);
    exit;
}

$result = readExcelWorkbook($tmpFile, $fileName);
if (isset($result['error'])) {
    echo json_encode(['error' => (string)$result['error']]);
    exit;
}

$sheets = (array)($result['sheets'] ?? []);
if (empty($sheets)) {
    echo json_encode(['error' => 'No worksheet found']);
    exit;
}

$getSheetRows = static function ($name) use ($sheets) {
    $target = strtolower(trim((string)$name));
    foreach ($sheets as $sheet) {
        $sheetName = strtolower(trim((string)($sheet['name'] ?? '')));
        if ($sheetName === $target) {
            return (array)($sheet['rows'] ?? []);
        }
    }
    return null;
};

if ($action === 'preview') {
    $previewSheets = [];
    foreach ($sheets as $sheet) {
        $rows = (array)($sheet['rows'] ?? []);
        $header = [];
        if (!empty($rows)) {
            $header = array_map(static function ($v) {
                return trim((string)$v);
            }, (array)$rows[0]);
        }
        $previewSheets[] = [
            'name' => (string)($sheet['name'] ?? 'Sheet'),
            'headers' => $header,
            'rows' => max(0, count($rows) - 1)
        ];
    }

    echo json_encode([
        'success' => true,
        'sheets' => $previewSheets
    ]);
    exit;
}

$parseJson = static function ($raw) {
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};

$issuesSheetName = 'Final Report';
$pagesSheetName = 'URL details';
$allUrlsSheetName = 'All URLs';

$issuesMap = $parseJson($_POST['issues_map'] ?? '{}');
$pagesMap = $parseJson($_POST['pages_map'] ?? '{}');
$allUrlsMap = $parseJson($_POST['all_urls_map'] ?? '{}');

if (!array_key_exists('title', $issuesMap) || $issuesMap['title'] === '') {
    echo json_encode(['error' => 'Issues sheet and Title mapping are required']);
    exit;
}

$issuesRows = $getSheetRows($issuesSheetName);
if ($issuesRows === null) {
    echo json_encode(['error' => 'Selected Issues sheet not found']);
    exit;
}

$rows = (array)$issuesRows;
if (count($rows) < 2) {
    echo json_encode(['error' => 'Issues sheet must contain header and at least one data row']);
    exit;
}

$header = array_map(static function ($v) {
    return trim((string)$v);
}, (array)$rows[0]);

$resolveColIndex = static function ($mapValue) use ($header) {
    if ($mapValue === null || $mapValue === '') {
        return null;
    }
    $idx = (int)$mapValue;
    return ($idx >= 0 && $idx < count($header)) ? $idx : null;
};

$titleCol = $resolveColIndex($issuesMap['title'] ?? null);
if ($titleCol === null) {
    echo json_encode(['error' => 'Title mapping is invalid']);
    exit;
}

$descCol = $resolveColIndex($issuesMap['description'] ?? null);
$statusCol = $resolveColIndex($issuesMap['status'] ?? null);
$priorityCol = $resolveColIndex($issuesMap['priority'] ?? null);
$severityCol = $resolveColIndex($issuesMap['severity'] ?? null);
$commonTitleCol = $resolveColIndex($issuesMap['common_title'] ?? null);
$pageNamesCol = $resolveColIndex($issuesMap['pages'] ?? null);
$pageNumbersCol = $resolveColIndex($issuesMap['page_numbers'] ?? null);
$qaStatusCol = $resolveColIndex($issuesMap['qa_status'] ?? null);
$groupedUrlsCol = $resolveColIndex($issuesMap['grouped_urls'] ?? null);

$sectionsMap = [];
if (isset($issuesMap['sections_map']) && is_array($issuesMap['sections_map'])) {
    foreach ($issuesMap['sections_map'] as $sectionName => $mapValue) {
        $cleanName = trim((string)$sectionName);
        if ($cleanName === '') {
            continue;
        }
        $sectionsMap[$cleanName] = $resolveColIndex($mapValue);
    }
}

$metadataMap = [];
if (isset($issuesMap['metadata_map']) && is_array($issuesMap['metadata_map'])) {
    foreach ($issuesMap['metadata_map'] as $metaKey => $mapValue) {
        $cleanKey = trim((string)$metaKey);
        if ($cleanKey === '') {
            continue;
        }
        $metadataMap[$cleanKey] = $resolveColIndex($mapValue);
    }
}

$defaultStatusId = getStatusId($db, 'Open');
if (!$defaultStatusId) $defaultStatusId = getAnyStatusId($db);
$defaultPriorityId = getPriorityId($db, 'Medium');
if (!$defaultPriorityId) $defaultPriorityId = getAnyPriorityId($db);
$typeId = getDefaultTypeId($db, $projectId);

if (!$defaultStatusId || !$defaultPriorityId || !$typeId) {
    echo json_encode(['error' => 'Issue status/priority/type is not configured']);
    exit;
}

$hasResolvedAtColumn = columnExists($db, 'issues', 'resolved_at');
$normalizeSeverity = static function ($rawSeverity) {
    $value = strtolower(trim((string)$rawSeverity));
    if ($value === '') {
        return 'major';
    }

    $map = [
        'blocker' => 'blocker',
        'blocking' => 'blocker',
        'critical' => 'critical',
        'crit' => 'critical',
        'sev1' => 'critical',
        's1' => 'critical',
        'high' => 'major',
        'major' => 'major',
        'sev2' => 'major',
        's2' => 'major',
        'medium' => 'major',
        'moderate' => 'major',
        'minor' => 'minor',
        'sev3' => 'minor',
        's3' => 'minor',
        'low' => 'low',
        'info' => 'low',
        'informational' => 'low'
    ];

    return $map[$value] ?? 'major';
};

$duplicateCheckStmt = $db->prepare("SELECT id FROM issues WHERE project_id = ? AND title = ? AND ((page_id IS NULL AND ? IS NULL) OR page_id = ?) LIMIT 1");

$inserted = 0;
$skipped = 0;
$errors = [];

$addedUnique = 0;
$addedGrouped = 0;
$addedProjectPages = 0;

$findUnique = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND (url = ? OR page_name = ?) LIMIT 1');
$insertUnique = $db->prepare('INSERT INTO project_pages (project_id, page_name, url, page_number, screen_name, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
$findGrouped = $db->prepare('SELECT id FROM grouped_urls WHERE project_id = ? AND url = ? LIMIT 1');
$insertGrouped = $db->prepare('INSERT INTO grouped_urls (project_id, unique_page_id, url, normalized_url, created_at) VALUES (?, ?, ?, ?, NOW())');

$maxPageStmt = $db->prepare("SELECT MAX(CAST(REPLACE(page_number, 'Page ', '') AS UNSIGNED)) FROM project_pages WHERE project_id = ? AND page_number LIKE 'Page %'");
$maxPageStmt->execute([$projectId]);
$nextPageNumber = ((int)$maxPageStmt->fetchColumn()) + 1;

$parseDelimited = static function ($raw) {
    $parts = preg_split('/[;,\n\r]+/', (string)$raw);
    return array_values(array_filter(array_map('trim', (array)$parts), static function ($v) {
        return $v !== '';
    }));
};

$addGroupedUrl = static function ($projectId, $uniqueId, $url) use ($findGrouped, $insertGrouped, &$addedGrouped) {
    $trimmed = trim((string)$url);
    if ($trimmed === '') return;
    $findGrouped->execute([$projectId, $trimmed]);
    if ($findGrouped->fetch(PDO::FETCH_ASSOC)) return;
    $norm = preg_replace('#[?].*$#', '', rtrim($trimmed, '/'));
    $norm = mb_strtolower((string)$norm);
    $insertGrouped->execute([$projectId, $uniqueId ?: null, $trimmed, $norm]);
    $addedGrouped++;
};

$pagesImportedBeforeIssues = false;
if ($pagesSheetName !== '') {
    $pagesRows = $getSheetRows($pagesSheetName);
    if ($pagesRows !== null) {
        $pagesHeader = (array)($pagesRows[0] ?? []);
        $pagesResolveCol = static function ($value, $headerRow) {
            if ($value === null || $value === '') return null;
            $idx = (int)$value;
            return ($idx >= 0 && $idx < count((array)$headerRow)) ? $idx : null;
        };

        $pageNameCol = $pagesResolveCol($pagesMap['page_name'] ?? null, $pagesHeader);
        $uniqueUrlCol = $pagesResolveCol($pagesMap['unique_url'] ?? null, $pagesHeader);
        $groupedUrlsColPages = $pagesResolveCol($pagesMap['grouped_urls'] ?? null, $pagesHeader);
        $pageNumberCol = $pagesResolveCol($pagesMap['page_number'] ?? null, $pagesHeader);
        $screenNameCol = $pagesResolveCol($pagesMap['screen_name'] ?? null, $pagesHeader);
        $notesCol = $pagesResolveCol($pagesMap['notes'] ?? null, $pagesHeader);

        if (!($pageNameCol === null || $uniqueUrlCol === null || count($pagesRows) < 2)) {
            for ($i = 1; $i < count($pagesRows); $i++) {
                $r = (array)$pagesRows[$i];
                $pageName = trim((string)($r[$pageNameCol] ?? ''));
                $uniqueUrl = trim((string)($r[$uniqueUrlCol] ?? ''));
                if ($pageName === '' && $uniqueUrl === '') {
                    continue;
                }
                if ($uniqueUrl === '') {
                    continue;
                }

                $findUnique->execute([$projectId, $uniqueUrl, $pageName]);
                $existing = $findUnique->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $uniqueId = (int)$existing['id'];
                } else {
                    $pageNumberVal = $pageNumberCol !== null ? trim((string)($r[$pageNumberCol] ?? '')) : '';
                    $screenNameVal = $screenNameCol !== null ? trim((string)($r[$screenNameCol] ?? '')) : '';
                    $notesVal = $notesCol !== null ? trim((string)($r[$notesCol] ?? '')) : '';
                    if ($pageNumberVal === '') {
                        $pageNumberVal = 'Page ' . $nextPageNumber;
                        $nextPageNumber++;
                    }
                    if ($pageName === '') {
                        $pageName = $pageNumberVal;
                    }

                    $insertUnique->execute([
                        $projectId,
                        $pageName,
                        $uniqueUrl,
                        $pageNumberVal,
                        $screenNameVal !== '' ? $screenNameVal : null,
                        $notesVal !== '' ? $notesVal : null,
                        $userId
                    ]);
                    $uniqueId = (int)$db->lastInsertId();
                    $addedUnique++;
                    $addedProjectPages++;
                }

                $groupedRaw = $groupedUrlsColPages !== null ? (string)($r[$groupedUrlsColPages] ?? '') : '';
                $groupedList = $parseDelimited($groupedRaw);
                if (empty($groupedList)) {
                    $groupedList = [$uniqueUrl];
                }
                foreach ($groupedList as $url) {
                    $addGroupedUrl($projectId, $uniqueId, $url);
                }
            }

            $pagesImportedBeforeIssues = true;
        }
    }
}

$pageLookupByName = [];
$pageLookupByNumber = [];
$pageStmt = $db->prepare("SELECT id, page_name, page_number FROM project_pages WHERE project_id = ?");
$pageStmt->execute([$projectId]);
while ($p = $pageStmt->fetch(PDO::FETCH_ASSOC)) {
    $id = (int)$p['id'];
    $pn = strtolower(trim((string)($p['page_name'] ?? '')));
    $num = strtolower(trim((string)($p['page_number'] ?? '')));
    if ($pn !== '') $pageLookupByName[$pn] = $id;
    if ($num !== '') $pageLookupByNumber[$num] = $id;
}

for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex++) {
    $row = (array)$rows[$rowIndex];
    $excelRowNumber = $rowIndex + 1;

    $getValue = static function ($arr, $idx) {
        if ($idx === null) return '';
        return trim((string)($arr[$idx] ?? ''));
    };

    $title = $getValue($row, $titleCol);
    if ($title === '') {
        continue;
    }

    $description = '';
    $sectionBlocks = [];
    foreach ($sectionsMap as $sectionName => $sectionColIdx) {
        if ($sectionColIdx === null) {
            continue;
        }
        $sectionValue = $getValue($row, $sectionColIdx);
        if ($sectionValue === '') {
            continue;
        }
        $sectionText = nl2br(htmlspecialchars($sectionValue, ENT_QUOTES, 'UTF-8'));
        $sectionBlocks[] = '<p><strong>[' . htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8') . ']</strong></p><p>' . $sectionText . '</p>';
    }
    if (!empty($sectionBlocks)) {
        $description = implode("\n", $sectionBlocks);
    } elseif ($descCol !== null) {
        $description = $getValue($row, $descCol);
    }
    $statusRaw = $statusCol !== null ? $getValue($row, $statusCol) : '';
    $priorityRaw = $priorityCol !== null ? $getValue($row, $priorityCol) : '';
    $severityRaw = $severityCol !== null ? $getValue($row, $severityCol) : '';
    $commonTitle = $commonTitleCol !== null ? $getValue($row, $commonTitleCol) : '';
    $qaStatusRaw = $qaStatusCol !== null ? $getValue($row, $qaStatusCol) : '';
    $groupedUrlsRaw = $groupedUrlsCol !== null ? $getValue($row, $groupedUrlsCol) : '';

    $statusId = $statusRaw !== '' ? getStatusId($db, $statusRaw) : null;
    if (!$statusId) $statusId = $defaultStatusId;

    $priorityId = $priorityRaw !== '' ? getPriorityId($db, $priorityRaw) : null;
    if (!$priorityId) $priorityId = $defaultPriorityId;

    $severity = $normalizeSeverity($severityRaw);

    $resolvePageIds = static function ($raw, $lookup) {
        $raw = trim((string)$raw);
        if ($raw === '') return [];
        $parts = preg_split('/[;,\n\r]+/', $raw);
        $out = [];
        foreach ((array)$parts as $part) {
            $key = strtolower(trim((string)$part));
            if ($key !== '' && isset($lookup[$key])) {
                $out[] = (int)$lookup[$key];
            }
        }
        return array_values(array_unique($out));
    };

    $pageIdsByName = $pageNamesCol !== null ? $resolvePageIds($getValue($row, $pageNamesCol), $pageLookupByName) : [];
    $pageIdsByNumber = $pageNumbersCol !== null ? $resolvePageIds($getValue($row, $pageNumbersCol), $pageLookupByNumber) : [];
    $pageIds = array_values(array_unique(array_merge($pageIdsByNumber, $pageIdsByName)));
    $primaryPageId = !empty($pageIds) ? (int)$pageIds[0] : null;

    if ($skipDuplicates) {
        $duplicateCheckStmt->execute([$projectId, $title, $primaryPageId, $primaryPageId]);
        $exists = $duplicateCheckStmt->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            $skipped++;
            continue;
        }
    }

    try {
        $db->beginTransaction();

        $issueKey = '';
        $createdId = 0;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $issueKey = getIssueKey($db, $projectId);

            if ($hasResolvedAtColumn) {
                $insertSql = "INSERT INTO issues (project_id, issue_key, title, description, type_id, priority_id, status_id, reporter_id, assignee_id, page_id, severity, resolved_at, is_final, common_issue_title, client_ready) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0)";
                $resolvedAt = null;
                $stmt = $db->prepare($insertSql);
                $stmt->execute([
                    $projectId,
                    $issueKey,
                    $title,
                    $description,
                    $typeId,
                    $priorityId,
                    $statusId,
                    $userId,
                    null,
                    $primaryPageId,
                    $severity,
                    $resolvedAt,
                    $commonTitle !== '' ? $commonTitle : null
                ]);
            } else {
                $insertSql = "INSERT INTO issues (project_id, issue_key, title, description, type_id, priority_id, status_id, reporter_id, assignee_id, page_id, severity, is_final, common_issue_title, client_ready) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0)";
                $stmt = $db->prepare($insertSql);
                $stmt->execute([
                    $projectId,
                    $issueKey,
                    $title,
                    $description,
                    $typeId,
                    $priorityId,
                    $statusId,
                    $userId,
                    null,
                    $primaryPageId,
                    $severity,
                    $commonTitle !== '' ? $commonTitle : null
                ]);
            }

            $createdId = (int)$db->lastInsertId();
            if ($createdId > 0) {
                break;
            }
        }

        if ($createdId <= 0) {
            throw new RuntimeException('Could not create issue key');
        }

        if (!empty($pageIds)) {
            queueMeta($createdId, 'page_ids', $pageIds);
        }

        foreach ($metadataMap as $metaKey => $metaColIdx) {
            if ($metaColIdx === null) {
                continue;
            }
            $metaRaw = $getValue($row, $metaColIdx);
            if ($metaRaw === '') {
                continue;
            }
            $metaValues = array_values(array_filter(array_map('trim', preg_split('/[;,\n\r]+/', $metaRaw)), static function ($v) {
                return $v !== '';
            }));
            if (empty($metaValues)) {
                continue;
            }
            queueMeta($createdId, $metaKey, $metaValues);
        }

        if ($qaStatusRaw !== '') {
            $qaStatusParts = array_values(array_filter(array_map('trim', preg_split('/[;,\n\r]+/', $qaStatusRaw)), static function ($v) {
                return $v !== '';
            }));
            if (!empty($qaStatusParts)) {
                queueMeta($createdId, 'qa_status', $qaStatusParts);
            }
        }

        if ($groupedUrlsRaw !== '') {
            $urlParts = array_values(array_filter(array_map('trim', preg_split('/[;,\n\r]+/', $groupedUrlsRaw)), static function ($v) {
                return $v !== '';
            }));
            if (!empty($urlParts)) {
                queueMeta($createdId, 'grouped_urls', $urlParts);
            }
        }

        flushMetaBatch($db);
        $db->commit();
        $inserted++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $errors[] = 'Row ' . $excelRowNumber . ': ' . $e->getMessage();
        if (count($errors) >= 20) {
            break;
        }
    }
}

if (!$pagesImportedBeforeIssues && $pagesSheetName !== '') {
    $pagesRows = $getSheetRows($pagesSheetName);
    if ($pagesRows !== null) {
        $pagesHeader = (array)($pagesRows[0] ?? []);
        $pagesResolveCol = static function ($value, $headerRow) {
            if ($value === null || $value === '') return null;
            $idx = (int)$value;
            return ($idx >= 0 && $idx < count((array)$headerRow)) ? $idx : null;
        };

        $pageNameCol = $pagesResolveCol($pagesMap['page_name'] ?? null, $pagesHeader);
        $uniqueUrlCol = $pagesResolveCol($pagesMap['unique_url'] ?? null, $pagesHeader);
        $groupedUrlsColPages = $pagesResolveCol($pagesMap['grouped_urls'] ?? null, $pagesHeader);
        $pageNumberCol = $pagesResolveCol($pagesMap['page_number'] ?? null, $pagesHeader);
        $screenNameCol = $pagesResolveCol($pagesMap['screen_name'] ?? null, $pagesHeader);
        $notesCol = $pagesResolveCol($pagesMap['notes'] ?? null, $pagesHeader);

        if ($pageNameCol === null || $uniqueUrlCol === null || count($pagesRows) < 2) {
            $errors[] = 'Project Pages mapping invalid or sheet has no data';
        } else {
            for ($i = 1; $i < count($pagesRows); $i++) {
                $r = (array)$pagesRows[$i];
                $pageName = trim((string)($r[$pageNameCol] ?? ''));
                $uniqueUrl = trim((string)($r[$uniqueUrlCol] ?? ''));
                if ($pageName === '' && $uniqueUrl === '') {
                    continue;
                }
                if ($uniqueUrl === '') {
                    continue;
                }

                $findUnique->execute([$projectId, $uniqueUrl, $pageName]);
                $existing = $findUnique->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $uniqueId = (int)$existing['id'];
                } else {
                    $pageNumberVal = $pageNumberCol !== null ? trim((string)($r[$pageNumberCol] ?? '')) : '';
                    $screenNameVal = $screenNameCol !== null ? trim((string)($r[$screenNameCol] ?? '')) : '';
                    $notesVal = $notesCol !== null ? trim((string)($r[$notesCol] ?? '')) : '';
                    if ($pageNumberVal === '') {
                        $pageNumberVal = 'Page ' . $nextPageNumber;
                        $nextPageNumber++;
                    }
                    if ($pageName === '') {
                        $pageName = $pageNumberVal;
                    }

                    $insertUnique->execute([
                        $projectId,
                        $pageName,
                        $uniqueUrl,
                        $pageNumberVal,
                        $screenNameVal !== '' ? $screenNameVal : null,
                        $notesVal !== '' ? $notesVal : null,
                        $userId
                    ]);
                    $uniqueId = (int)$db->lastInsertId();
                    $addedUnique++;
                    $addedProjectPages++;
                }

                $groupedRaw = $groupedUrlsColPages !== null ? (string)($r[$groupedUrlsColPages] ?? '') : '';
                $groupedList = $parseDelimited($groupedRaw);
                if (empty($groupedList)) {
                    $groupedList = [$uniqueUrl];
                }
                foreach ($groupedList as $url) {
                    $addGroupedUrl($projectId, $uniqueId, $url);
                }
            }
        }
    }
}

if ($allUrlsSheetName !== '') {
    $allRows = $getSheetRows($allUrlsSheetName);
    if ($allRows !== null) {
        $urlCol = null;
        if (array_key_exists('url', $allUrlsMap) && $allUrlsMap['url'] !== '') {
            $candidate = (int)$allUrlsMap['url'];
            if ($candidate >= 0 && $candidate < count((array)($allRows[0] ?? []))) {
                $urlCol = $candidate;
            }
        }
        if ($urlCol === null || count($allRows) < 2) {
            $errors[] = 'All URLs mapping invalid or sheet has no data';
        } else {
            for ($i = 1; $i < count($allRows); $i++) {
                $r = (array)$allRows[$i];
                $raw = trim((string)($r[$urlCol] ?? ''));
                if ($raw === '') continue;
                foreach ($parseDelimited($raw) as $u) {
                    $addGroupedUrl($projectId, null, $u);
                }
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'inserted' => $inserted,
    'skipped' => $skipped,
    'added_unique' => $addedUnique,
    'added_grouped' => $addedGrouped,
    'added_project_pages' => $addedProjectPages,
    'errors' => $errors
]);
exit;
