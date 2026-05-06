<?php
set_time_limit(300); // 5 minutes max execution time
ini_set('memory_limit', '512M'); // Increase memory limit for large JSON results
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function jsonRes(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureA11yFindingsTable(PDO $db): void {
    static $ready = false;
    if ($ready) return;

    $db->exec("\n        CREATE TABLE IF NOT EXISTS automated_a11y_findings (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            project_id INT NOT NULL,\n            page_id INT NOT NULL,\n            rule_id VARCHAR(120) NOT NULL,\n            title VARCHAR(255) NOT NULL,\n            severity VARCHAR(20) NOT NULL DEFAULT 'Major',\n            wcag_sc VARCHAR(100) NULL,\n            wcag_name VARCHAR(255) NULL,\n            wcag_level VARCHAR(20) NULL,\n            actual_results LONGTEXT NULL,\n            incorrect_code LONGTEXT NULL,\n            screenshots_json LONGTEXT NULL,\n            recommendation LONGTEXT NULL,\n            correct_code LONGTEXT NULL,\n            help_url VARCHAR(1000) NULL,\n            occurrence_count INT NOT NULL DEFAULT 1,\n            scan_url VARCHAR(1000) NULL,\n            scan_urls_json LONGTEXT NULL,\n            raw_payload LONGTEXT NULL,\n            status VARCHAR(30) NOT NULL DEFAULT 'needs_review',\n            moved_issue_id INT NULL,\n            created_by INT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            KEY idx_a11y_project_page_status (project_id, page_id, status),\n            KEY idx_a11y_created_at (created_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");

    $alterStatements = [
        "ALTER TABLE automated_a11y_findings MODIFY COLUMN description LONGTEXT NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN description LONGTEXT NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN actual_results LONGTEXT NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN incorrect_code LONGTEXT NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN screenshots_json LONGTEXT NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN recommendation LONGTEXT NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN correct_code LONGTEXT NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN help_url VARCHAR(1000) NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN occurrence_count INT NOT NULL DEFAULT 1",
        "ALTER TABLE automated_a11y_findings ADD COLUMN wcag_sc VARCHAR(100) NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN wcag_name VARCHAR(255) NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN wcag_level VARCHAR(20) NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN raw_payload LONGTEXT NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN moved_issue_id INT NULL",
        "ALTER TABLE automated_a11y_findings ADD COLUMN scan_urls_json LONGTEXT NULL",
    ];
    foreach ($alterStatements as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
            // ignore already-exists errors
        }
    }

    $ready = true;
}

function normalizeScanUrl(string $rawUrl): ?string {
    $rawUrl = trim($rawUrl);
    if ($rawUrl === '') return null;

    // Resolve relative URLs to absolute first
    if (!preg_match('/^https?:\/\//i', $rawUrl)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $origin = $scheme . '://' . $host;
        $baseDir = getBaseDir();

        if (strpos($rawUrl, '//') === 0) {
            $rawUrl = $scheme . ':' . $rawUrl;
        } elseif (strpos($rawUrl, '/') === 0) {
            $rawUrl = $origin . $rawUrl;
        } else {
            $rawUrl = rtrim($origin . $baseDir, '/') . '/' . ltrim($rawUrl, '/');
        }
    }

    // SSRF protection: block private/internal IP ranges and localhost
    $parsed = parse_url($rawUrl);
    if (!$parsed || empty($parsed['host'])) return null;

    $host = strtolower($parsed['host']);

    // Block localhost variants
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) return null;
    if (preg_match('/^127\.\d+\.\d+\.\d+$/', $host)) return null;

    // Block private IPv4 ranges (RFC 1918)
    if (preg_match('/^10\.\d+\.\d+\.\d+$/', $host)) return null;
    if (preg_match('/^172\.(1[6-9]|2\d|3[01])\.\d+\.\d+$/', $host)) return null;
    if (preg_match('/^192\.168\.\d+\.\d+$/', $host)) return null;

    // Block link-local and metadata endpoints
    if (preg_match('/^169\.254\.\d+\.\d+$/', $host)) return null;

    // Block non-http(s) schemes (already enforced above, but double-check)
    $scheme = strtolower($parsed['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return null;

    // DNS rebinding protection: resolve hostname and validate the resolved IP
    // This prevents attackers from using a domain that resolves to an internal IP
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        // It's a hostname, not a raw IP — resolve it
        $resolved = @gethostbyname($host);
        if ($resolved && $resolved !== $host) {
            // Check resolved IP against blocked ranges
            if (in_array($resolved, ['127.0.0.1', '::1', '0.0.0.0'], true)) return null;
            if (preg_match('/^127\.\d+\.\d+\.\d+$/', $resolved)) return null;
            if (preg_match('/^10\.\d+\.\d+\.\d+$/', $resolved)) return null;
            if (preg_match('/^172\.(1[6-9]|2\d|3[01])\.\d+\.\d+$/', $resolved)) return null;
            if (preg_match('/^192\.168\.\d+\.\d+$/', $resolved)) return null;
            if (preg_match('/^169\.254\.\d+\.\d+$/', $resolved)) return null;
        }
    }

    return $rawUrl;
}

function getScanProgressPath(string $token): ?string {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $token)) return null;
    $tmpDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
    return $tmpDir . DIRECTORY_SEPARATOR . 'a11y_progress_' . $token . '.json';
}

function writeScanProgress(string $token, array $payload): void {
    $path = getScanProgressPath($token);
    if (!$path) return;
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function readScanProgress(string $token): ?array {
    $path = getScanProgressPath($token);
    if (!$path || !is_file($path)) return null;
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : null;
}

function stopScanProcess(string $token): bool {
    $data = readScanProgress($token);
    if (!$data || empty($data['pid'])) {
        return false;
    }
    $pid = (int)$data['pid'];
    if ($pid <= 0) return false;

    // Kill the process (cross-platform support)
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        @exec("taskkill /F /PID $pid > NUL 2>&1");
    } else if (function_exists('posix_kill')) {
        @posix_kill($pid, 9); // SIGKILL
    } else {
        @exec("kill -9 $pid > /dev/null 2>&1");
    }

    // Update progress to cancelled
    writeScanProgress($token, array_merge($data, [
        'status' => 'cancelled',
        'error' => 'Scan was cancelled by user.',
        'updated_at' => date('Y-m-d H:i:s')
    ]));
    return true;
}

function mapSeverity(string $severity): string {
    $s = strtolower(trim($severity));
    if (in_array($s, ['blocker'], true)) return 'Blocker';
    if (in_array($s, ['critical', 'serious'], true)) return 'Critical';
    if (in_array($s, ['major', 'moderate', 'medium'], true)) return 'Major';
    if (in_array($s, ['minor', 'low'], true)) return 'Minor';
    return 'Major';
}

function decodeScreenshotList($raw): array {
    $arr = json_decode((string)$raw, true);
    if (!is_array($arr)) return [];
    return array_values(array_filter(array_map(static function ($v) {
        return trim((string)$v);
    }, $arr), static function ($v) {
        return $v !== '';
    }));
}

function normalizeScreenshotPathToAbs(string $rawPath, string $baseDir, string $projectRoot): ?string {
    $rawPath = trim($rawPath);
    if ($rawPath === '') return null;

    $pathPart = parse_url($rawPath, PHP_URL_PATH);
    if (!is_string($pathPart) || trim($pathPart) === '') {
        $pathPart = $rawPath;
    }
    $pathPart = str_replace('\\', '/', trim($pathPart));
    if ($pathPart === '') return null;

    $baseDirNorm = '/' . trim(str_replace('\\', '/', $baseDir), '/');
    if ($baseDirNorm === '/') $baseDirNorm = '';

    if ($baseDirNorm !== '' && strpos($pathPart, $baseDirNorm . '/') === 0) {
        $pathPart = substr($pathPart, strlen($baseDirNorm) + 1);
    } else {
        $pathPart = ltrim($pathPart, '/');
    }

    if (stripos($pathPart, 'uploads/automated_findings/') !== 0) {
        return null;
    }

    $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pathPart);
    return $abs;
}

function deleteScreenshotFiles(array $screenshotPaths, string $baseDir, string $projectRoot): void {
    foreach ($screenshotPaths as $rawPath) {
        $abs = normalizeScreenshotPathToAbs((string)$rawPath, $baseDir, $projectRoot);
        if (!$abs || !is_file($abs)) continue;
        @unlink($abs);
    }
}

function loadNeedsReviewScreenshotPaths(PDO $db, int $projectId, int $pageId): array {
    $stmt = $db->prepare("SELECT screenshots_json FROM automated_a11y_findings WHERE project_id = ? AND page_id = ? AND status = 'needs_review'");
    $stmt->execute([$projectId, $pageId]);
    $all = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $all = array_merge($all, decodeScreenshotList($row['screenshots_json'] ?? '[]'));
    }
    return $all;
}

function uniqueStrings(array $items): array {
    $seen = [];
    $out = [];
    foreach ($items as $item) {
        $v = trim((string)$item);
        if ($v === '') continue;
        $k = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $v;
    }
    return $out;
}

function splitActualResultsBlock(string $actual): array {
    $text = trim($actual);
    if ($text === '') {
        return ['headline' => '', 'body' => ''];
    }
    if (preg_match('/\bURL:\s*/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        $pos = (int)$m[0][1];
        $headline = trim(substr($text, 0, $pos));
        $body = trim(substr($text, $pos));
        return ['headline' => $headline, 'body' => $body];
    }
    return ['headline' => $text, 'body' => ''];
}

function parseActualResultsUrlBlocks(string $actual): array {
    $parts = splitActualResultsBlock($actual);
    $headline = trim((string)($parts['headline'] ?? ''));
    $body = (string)($parts['body'] ?? '');
    $blocksByUrl = [];
    $order = [];
    if (trim($body) === '') {
        return ['headline' => $headline, 'blocks_by_url' => [], 'order' => []];
    }

    $lines = preg_split('/\r?\n/', $body);
    $currentUrl = '';
    $currentLines = [];
    $flush = static function () use (&$currentUrl, &$currentLines, &$blocksByUrl, &$order): void {
        if ($currentUrl === '' || empty($currentLines)) {
            $currentUrl = '';
            $currentLines = [];
            return;
        }
        $text = trim(implode("\n", $currentLines));
        if ($text !== '') {
            $blocksByUrl[$currentUrl] = $text;
            $order[] = $currentUrl;
        }
        $currentUrl = '';
        $currentLines = [];
    };

    foreach ($lines as $lineRaw) {
        $line = rtrim((string)$lineRaw);
        if (preg_match('/^\s*URL:\s*(.+)\s*$/i', $line, $m)) {
            $flush();
            $url = trim((string)$m[1]);
            if ($url !== '') {
                $currentUrl = $url;
                $currentLines[] = 'URL: ' . $url;
            }
            continue;
        }
        if ($currentUrl !== '') {
            $currentLines[] = $line;
        }
    }
    $flush();

    return ['headline' => $headline, 'blocks_by_url' => $blocksByUrl, 'order' => $order];
}

function loadNeedsReviewFindings(PDO $db, int $projectId, int $pageId): array {
    $stmt = $db->prepare("\n        SELECT id, rule_id, title, severity, wcag_sc, wcag_name, wcag_level,\n               actual_results, incorrect_code, screenshots_json, recommendation, correct_code,\n               help_url, occurrence_count, scan_url, scan_urls_json, raw_payload\n        FROM automated_a11y_findings\n        WHERE project_id = ? AND page_id = ? AND status = 'needs_review'\n    ");
    $stmt->execute([$projectId, $pageId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $scanUrls = json_decode((string)($row['scan_urls_json'] ?? '[]'), true);
        if (!is_array($scanUrls)) $scanUrls = [];
        $scanUrls = uniqueStrings(array_map(static function ($u) {
            return trim((string)$u);
        }, $scanUrls));
        if (empty($scanUrls) && !empty($row['scan_url'])) {
            $scanUrls = [trim((string)$row['scan_url'])];
        }
        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'rule_id' => trim((string)($row['rule_id'] ?? '')),
            'title' => trim((string)($row['title'] ?? 'Automated accessibility issue')),
            'severity' => trim((string)($row['severity'] ?? 'Major')),
            'needs_review_severity' => trim((string)($row['severity'] ?? 'Major')),
            'wcag_sc' => trim((string)($row['wcag_sc'] ?? '')),
            'wcag_name' => trim((string)($row['wcag_name'] ?? '')),
            'wcag_level' => trim((string)($row['wcag_level'] ?? '')),
            'actual_results' => trim((string)($row['actual_results'] ?? '')),
            'incorrect_code' => trim((string)($row['incorrect_code'] ?? '')),
            'screenshots' => decodeScreenshotList($row['screenshots_json'] ?? '[]'),
            'recommendation' => trim((string)($row['recommendation'] ?? '')),
            'correct_code' => trim((string)($row['correct_code'] ?? '')),
            'help_url' => trim((string)($row['help_url'] ?? '')),
            'occurrence_count' => max(1, (int)($row['occurrence_count'] ?? 1)),
            'scan_url' => (string)($scanUrls[0] ?? ''),
            'scan_urls' => $scanUrls,
            'raw_nodes' => [],
            'raw_payload' => (string)($row['raw_payload'] ?? ''),
        ];
    }
    return $out;
}

function trimExistingFindingForRescan(array $finding, array $scannedUrlMap): ?array {
    $existingUrls = uniqueStrings(array_map(static function ($u) {
        return trim((string)$u);
    }, is_array($finding['scan_urls'] ?? null) ? $finding['scan_urls'] : []));
    if (empty($existingUrls) && !empty($finding['scan_url'])) {
        $existingUrls = [trim((string)$finding['scan_url'])];
    }
    if (empty($existingUrls)) {
        return $finding;
    }

    $remainingUrls = [];
    $hasOverlap = false;
    foreach ($existingUrls as $u) {
        $k = function_exists('mb_strtolower') ? mb_strtolower($u, 'UTF-8') : strtolower($u);
        if (isset($scannedUrlMap[$k])) {
            $hasOverlap = true;
            continue;
        }
        $remainingUrls[] = $u;
    }
    if (!$hasOverlap) return $finding;
    if (empty($remainingUrls)) return null;

    $finding['scan_urls'] = $remainingUrls;
    $finding['scan_url'] = (string)($remainingUrls[0] ?? '');

    $parsed = parseActualResultsUrlBlocks((string)($finding['actual_results'] ?? ''));
    $headline = trim((string)($parsed['headline'] ?? ''));
    $blocksByUrl = is_array($parsed['blocks_by_url'] ?? null) ? $parsed['blocks_by_url'] : [];
    if (!empty($blocksByUrl)) {
        $keptBlocks = [];
        foreach ($remainingUrls as $u) {
            if (isset($blocksByUrl[$u])) {
                $keptBlocks[] = trim((string)$blocksByUrl[$u]);
            }
        }
        if (!empty($keptBlocks)) {
            $rebuilt = ($headline !== '' ? ($headline . "\n\n") : '') . implode("\n\n", $keptBlocks);
            $finding['actual_results'] = trim($rebuilt);
        }
    }

    return $finding;
}

function aggregateFindingsByRule(array $findings): array {
    $groups = [];
    foreach ($findings as $f) {
        if (!is_array($f)) continue;
        $ruleId = trim((string)($f['rule_id'] ?? ''));
        $title = trim((string)($f['title'] ?? 'Automated accessibility issue'));
        $recommendation = trim((string)($f['recommendation'] ?? ''));
        // Group by rule + title so the same rule across multiple URLs stays in one row.
        $rawKey = $ruleId . '||' . $title;
        $key = function_exists('mb_strtolower') ? mb_strtolower($rawKey, 'UTF-8') : strtolower($rawKey);

        $parts = splitActualResultsBlock((string)($f['actual_results'] ?? ''));
        $headline = $parts['headline'];
        $body = $parts['body'];

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'rule_id' => $ruleId,
                'title' => $title,
                'severity' => (string)($f['severity'] ?? ''),
                'needs_review_severity' => (string)($f['needs_review_severity'] ?? ''),
                'wcag_sc' => is_array($f['wcag_sc'] ?? null) ? $f['wcag_sc'] : (string)($f['wcag_sc'] ?? ''),
                'wcag_name' => (string)($f['wcag_name'] ?? ''),
                'wcag_level' => (string)($f['wcag_level'] ?? ''),
                'actual_headline' => $headline,
                'actual_blocks' => [],
                'incorrect_code_list' => [],
                'screenshots' => [],
                'recommendation_list' => [],
                'correct_code' => (string)($f['correct_code'] ?? ''),
                'help_url' => (string)($f['help_url'] ?? ''),
                'occurrence_count' => 0,
                'scan_urls' => [],
                'raw_nodes' => [],
            ];
        }

        if ($groups[$key]['actual_headline'] === '' && $headline !== '') {
            $groups[$key]['actual_headline'] = $headline;
        }
        if ($body !== '') {
            $groups[$key]['actual_blocks'][] = $body;
        }
        $inc = trim((string)($f['incorrect_code'] ?? ''));
        if ($inc !== '') {
            $groups[$key]['incorrect_code_list'][] = $inc;
        }
        if ($recommendation !== '') {
            $groups[$key]['recommendation_list'][] = $recommendation;
        }
        if (is_array($f['screenshots'] ?? null)) {
            $groups[$key]['screenshots'] = array_merge($groups[$key]['screenshots'], $f['screenshots']);
        }
        $groups[$key]['occurrence_count'] += max(0, (int)($f['occurrence_count'] ?? 0));
        $scanUrl = trim((string)($f['scan_url'] ?? ''));
        if ($scanUrl !== '') {
            $groups[$key]['scan_urls'][] = $scanUrl;
        }
        if (is_array($f['raw_nodes'] ?? null)) {
            $groups[$key]['raw_nodes'] = array_merge($groups[$key]['raw_nodes'], $f['raw_nodes']);
        }
    }

    $out = [];
    foreach ($groups as $g) {
        $blocks = uniqueStrings($g['actual_blocks']);
        $headline = trim((string)($g['actual_headline'] ?? ''));
        $actualResults = '';
        if ($headline !== '') {
            $actualResults = $headline;
            if (!empty($blocks)) $actualResults .= "\n\n";
        }
        $actualResults .= implode("\n\n", $blocks);

        $scanUrls = uniqueStrings($g['scan_urls']);
        $recommendationList = uniqueStrings($g['recommendation_list'] ?? []);
        $recommendation = '';
        if (!empty($recommendationList)) {
            $headline = '';
            $bulletItems = [];
            foreach ($recommendationList as $recText) {
                $lines = preg_split('/\r?\n/', (string)$recText);
                foreach ($lines as $lineRaw) {
                    $line = trim((string)$lineRaw);
                    if ($line === '') continue;
                    if (preg_match('/^\-?\s*apply the following changes:?$/i', $line)) continue;
                    if (preg_match('/^\-\s+(.+)$/', $line, $m)) {
                        $item = trim((string)$m[1]);
                        if (preg_match('/^apply the following changes:?$/i', $item)) continue;
                        if ($item !== '') $bulletItems[] = $item;
                        continue;
                    }
                    if ($headline === '') {
                        $headline = $line;
                    } else {
                        if (strcasecmp($line, $headline) === 0) continue;
                        $bulletItems[] = $line;
                    }
                }
            }
            $bulletItems = uniqueStrings($bulletItems);

            // Merge duplicate "controls: ..." bullets from same rule into one consolidated line.
            $mergedBuckets = [];
            $mergedOrder = [];
            $remaining = [];
            foreach ($bulletItems as $item) {
                $line = trim((string)$item);
                $matched = false;
                if (preg_match('/^(.*?\bfor these controls:)\s*(.+)$/i', $line, $m)) {
                    $prefix = trim((string)$m[1]);
                    $rest = trim((string)$m[2]);
                    if (!isset($mergedBuckets[$prefix])) {
                        $mergedBuckets[$prefix] = [];
                        $mergedOrder[] = $prefix;
                    }
                    if (preg_match_all('/"([^"]+)"/', $rest, $mm)) {
                        foreach ($mm[1] as $idv) {
                            $idv = trim((string)$idv);
                            if ($idv !== '') $mergedBuckets[$prefix][] = $idv;
                        }
                    }
                    $matched = true;
                }
                if (!$matched) {
                    $remaining[] = $line;
                }
            }

            $mergedLines = [];
            foreach ($mergedOrder as $prefix) {
                $ids = uniqueStrings($mergedBuckets[$prefix] ?? []);
                if (empty($ids)) continue;
                $quoted = implode(', ', array_map(static function ($idv) {
                    return '"' . $idv . '"';
                }, $ids));
                $mergedLines[] = $prefix . ' ' . $quoted . '.';
            }
            $bulletItems = array_values(array_merge($mergedLines, uniqueStrings($remaining)));

            if ($headline !== '' && !empty($bulletItems)) {
                $recommendation = $headline . "\n" . implode("\n", array_map(static function ($item) {
                    return '- ' . $item;
                }, $bulletItems));
            } elseif ($headline !== '') {
                $recommendation = $headline;
            } else {
                $recommendation = implode("\n", array_map(static function ($item) {
                    return '- ' . $item;
                }, $bulletItems));
            }
        }
        $out[] = [
            'rule_id' => $g['rule_id'],
            'title' => $g['title'],
            'severity' => $g['severity'],
            'needs_review_severity' => $g['needs_review_severity'],
            'wcag_sc' => $g['wcag_sc'],
            'wcag_name' => $g['wcag_name'],
            'wcag_level' => $g['wcag_level'],
            'actual_results' => trim($actualResults),
            'incorrect_code' => implode("\n\n", uniqueStrings($g['incorrect_code_list'])),
            'screenshots' => uniqueStrings($g['screenshots']),
            'recommendation' => $recommendation,
            'correct_code' => $g['correct_code'],
            'help_url' => $g['help_url'],
            'occurrence_count' => max(1, (int)$g['occurrence_count']),
            'scan_url' => (string)($scanUrls[0] ?? ''),
            'scan_urls' => $scanUrls,
            'raw_nodes' => $g['raw_nodes'],
        ];
    }
    return $out;
}

function runDeepScan(string $url, string $outputJsonPath, string $screenshotDir, ?string $progressToken = null, string $mode = 'default'): array {
    $script = realpath(__DIR__ . '/../scripts/deep_a11y_scan.js');
    if (!$script) {
        throw new RuntimeException('Deep scan script not found');
    }

    $cmd = 'node '
        . escapeshellarg($script)
        . ' --url ' . escapeshellarg($url)
        . ' --out ' . escapeshellarg($outputJsonPath)
        . ' --screenshot-dir ' . escapeshellarg($screenshotDir)
        . ' --max-nodes 0'
        . ' --mode ' . escapeshellarg($mode)
        . ($progressToken ? ' --token ' . escapeshellarg($progressToken) : '');

    $descriptorspec = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
    ];

    $process = proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start deep scan process');
    }

    $status = proc_get_status($process);
    $pid = $status['pid'];

    // Store PID in progress file if token exists
    if ($progressToken) {
        $pData = readScanProgress($progressToken) ?: [];
        $pData['pid'] = $pid;
        writeScanProgress($progressToken, $pData);
    }

    // Capture output and wait
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if (!file_exists($outputJsonPath)) {
        $err = trim($stderr . "\n" . $stdout);
        throw new RuntimeException('Deep scan output missing. ' . ($err !== '' ? $err : ''));
    }

    $raw = file_get_contents($outputJsonPath);
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid deep scan output JSON');
    }
    if (empty($json['success'])) {
        $msg = trim((string)($json['error'] ?? 'Deep scan failed'));
        throw new RuntimeException($msg);
    }
    return $json;
}

function saveFindings(PDO $db, int $projectId, int $pageId, int $userId, string $scanUrl, array $scanJson, string $scanRelDir, string $baseDir, array $scannedUrls = []): int {
    ensureA11yFindingsTable($db);
    $newFindings = is_array($scanJson['findings'] ?? null) ? $scanJson['findings'] : [];
    $projectRoot = realpath(__DIR__ . '/..');
    if (!$projectRoot) {
        throw new RuntimeException('Project root not found');
    }
    $scannedUrls = uniqueStrings(array_map(static function ($u) {
        return trim((string)$u);
    }, $scannedUrls));
    if (empty($scannedUrls) && trim($scanUrl) !== '') {
        $scannedUrls = [trim($scanUrl)];
    }
    $scannedUrlMap = [];
    foreach ($scannedUrls as $u) {
        $k = function_exists('mb_strtolower') ? mb_strtolower($u, 'UTF-8') : strtolower($u);
        $scannedUrlMap[$k] = true;
    }

    $db->beginTransaction();
    try {
        $existingFindings = loadNeedsReviewFindings($db, $projectId, $pageId);
        $oldShots = loadNeedsReviewScreenshotPaths($db, $projectId, $pageId);

        $preservedFindings = [];
        foreach ($existingFindings as $oldFinding) {
            $trimmed = trimExistingFindingForRescan($oldFinding, $scannedUrlMap);
            if ($trimmed === null) continue;
            $preservedFindings[] = $trimmed;
        }

        $allFindings = array_merge($preservedFindings, $newFindings);
        $findings = aggregateFindingsByRule($allFindings);

        $del = $db->prepare("DELETE FROM automated_a11y_findings WHERE project_id = ? AND page_id = ? AND status = 'needs_review'");
        $del->execute([$projectId, $pageId]);

        if (empty($findings)) {
            $db->commit();
            if (!empty($oldShots)) {
                deleteScreenshotFiles($oldShots, $baseDir, $projectRoot);
            }
            return 0;
        }

        $ins = $db->prepare("\n            INSERT INTO automated_a11y_findings\n                (project_id, page_id, rule_id, title, severity, wcag_sc, wcag_name, wcag_level, actual_results, incorrect_code, screenshots_json, recommendation, correct_code, help_url, occurrence_count, scan_url, scan_urls_json, raw_payload, status, created_by)\n            VALUES\n                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'needs_review', ?)\n        ");

        $saved = 0;
        foreach ($findings as $f) {
            $shots = [];
            if (is_array($f['screenshots'] ?? null)) {
                foreach ($f['screenshots'] as $fileName) {
                    $name = trim((string)$fileName);
                    if ($name === '') continue;
                    if (preg_match('#^https?://#i', $name) || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
                        $shots[] = $name;
                    } else {
                        $prefix = rtrim($baseDir, '/');
                        $shots[] = $prefix . '/' . trim($scanRelDir, '/\\') . '/' . ltrim($name, '/\\');
                    }
                }
            }

            $rawPayload = json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $scanUrls = [];
            if (is_array($f['scan_urls'] ?? null)) {
                $scanUrls = $f['scan_urls'];
            } elseif (!empty($f['scan_url'])) {
                $scanUrls = [(string)$f['scan_url']];
            }
            $scanUrls = uniqueStrings(array_map(static function ($u) {
                return trim((string)$u);
            }, $scanUrls));
            $scanUrlPrimary = trim((string)($f['scan_url'] ?? ($scanUrls[0] ?? $scanUrl)));
            $ins->execute([
                $projectId,
                $pageId,
                trim((string)($f['rule_id'] ?? '')),
                trim((string)($f['title'] ?? 'Automated accessibility issue')),
                mapSeverity((string)($f['needs_review_severity'] ?? $f['severity'] ?? 'Major')),
                trim((string)(is_array($f['wcag_sc'] ?? null) ? implode(', ', $f['wcag_sc']) : ($f['wcag_sc'] ?? ''))),
                trim((string)($f['wcag_name'] ?? '')),
                trim((string)($f['wcag_level'] ?? '')),
                trim((string)($f['actual_results'] ?? '')),
                trim((string)($f['incorrect_code'] ?? '')),
                json_encode($shots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                trim((string)($f['recommendation'] ?? '')),
                trim((string)($f['correct_code'] ?? '')),
                trim((string)($f['help_url'] ?? '')),
                max(1, (int)($f['occurrence_count'] ?? 1)),
                $scanUrlPrimary,
                json_encode($scanUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $rawPayload,
                $userId,
            ]);
            $saved++;
        }

        $db->commit();
        if (!empty($oldShots)) {
            $keepShots = [];
            foreach ($findings as $f) {
                if (is_array($f['screenshots'] ?? null)) {
                    foreach ($f['screenshots'] as $s) {
                        $sv = trim((string)$s);
                        if ($sv !== '') $keepShots[] = $sv;
                    }
                }
            }
            $keepMap = [];
            foreach (uniqueStrings($keepShots) as $kp) {
                $abs = normalizeScreenshotPathToAbs($kp, $baseDir, $projectRoot);
                if ($abs) $keepMap[$abs] = true;
            }

            $toDelete = [];
            foreach ($oldShots as $oldShot) {
                $absOld = normalizeScreenshotPathToAbs((string)$oldShot, $baseDir, $projectRoot);
                if (!$absOld) continue;
                if (!isset($keepMap[$absOld])) $toDelete[] = $oldShot;
            }
            if (!empty($toDelete)) {
                deleteScreenshotFiles($toDelete, $baseDir, $projectRoot);
            }
        }
        return $saved;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    jsonRes(['success' => false, 'message' => 'Unauthorized'], 401);
}

$db = Database::getInstance();
$userId = (int)($_SESSION['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'scan'));
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);

// For 'cancel' and 'progress', we only need a valid token, project_id is optional/supplemental
if ($action === 'cancel') {
    $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') jsonRes(['success' => false, 'message' => 'Token required'], 400);
    $ok = stopScanProcess($token);
    jsonRes(['success' => $ok, 'message' => $ok ? 'Scan cancelled' : 'Failed to cancel or already stopped']);
}

if ($action !== 'progress' && $projectId <= 0) {
    jsonRes(['success' => false, 'message' => 'Project is required'], 400);
}
if ($projectId > 0 && !hasProjectAccess($db, $userId, $projectId)) {
    jsonRes(['success' => false, 'message' => 'Forbidden'], 403);
}

// Release session lock so progress polling requests can run while long scan is in progress.
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}

try {
    ensureA11yFindingsTable($db);

    if ($method === 'GET' && $action === 'list') {
        $pageId = (int)($_GET['page_id'] ?? 0);
        if ($pageId <= 0) jsonRes(['success' => false, 'message' => 'page_id is required'], 400);

        $stmt = $db->prepare("\n            SELECT id, project_id, page_id, rule_id, title, severity, wcag_sc, wcag_name, wcag_level,\n                   actual_results, incorrect_code, screenshots_json, recommendation, correct_code,\n                   help_url, occurrence_count, scan_url, scan_urls_json, status, moved_issue_id, created_at, updated_at\n            FROM automated_a11y_findings\n            WHERE project_id = ? AND page_id = ? AND status = 'needs_review'\n            ORDER BY FIELD(severity,'Blocker','Critical','Major','Minor'), id DESC\n        ");
        $stmt->execute([$projectId, $pageId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $shots = json_decode((string)($row['screenshots_json'] ?? '[]'), true);
            $row['screenshots'] = is_array($shots) ? $shots : [];
            $scanUrls = json_decode((string)($row['scan_urls_json'] ?? '[]'), true);
            if (!is_array($scanUrls)) $scanUrls = [];
            $scanUrls = uniqueStrings(array_map(static function ($u) {
                return trim((string)$u);
            }, $scanUrls));
            if (empty($scanUrls) && !empty($row['scan_url'])) {
                $scanUrls = [trim((string)$row['scan_url'])];
            }
            $row['scan_urls'] = $scanUrls;
        }
        unset($row);

        jsonRes(['success' => true, 'findings' => $rows]);
    }

    if ($method === 'GET' && $action === 'progress') {
        $token = trim((string)($_GET['token'] ?? ''));
        $progress = readScanProgress($token);
        if (!$progress) {
            jsonRes([
                'success' => true,
                'status' => 'not_found',
                'completed' => 0,
                'total' => 0,
                'percent' => 0
            ]);
        }
        jsonRes(array_merge(['success' => true], $progress));
    }

    if ($method === 'POST' && $action === 'mark_moved') {
        enforceApiCsrf();
        $findingId = (int)($_POST['finding_id'] ?? 0);
        $issueId = (int)($_POST['issue_id'] ?? 0);
        if ($findingId <= 0 || $issueId <= 0) {
            jsonRes(['success' => false, 'message' => 'finding_id and issue_id are required'], 400);
        }

        $up = $db->prepare("\n            UPDATE automated_a11y_findings\n            SET status = 'moved_to_final', moved_issue_id = ?, updated_at = NOW()\n            WHERE id = ? AND project_id = ?\n        ");
        $up->execute([$issueId, $findingId, $projectId]);
        if ($up->rowCount() < 1) {
            jsonRes(['success' => false, 'message' => 'Finding not found'], 404);
        }
        jsonRes(['success' => true]);
    }

    if ($method === 'POST' && $action === 'delete') {
        enforceApiCsrf();
        $pageId = (int)($_POST['page_id'] ?? 0);
        $idsRaw = $_POST['ids'] ?? ($_POST['finding_id'] ?? '');
        $ids = [];
        if (is_array($idsRaw)) {
            $ids = array_map('intval', $idsRaw);
        } else {
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', (string)$idsRaw))));
        }
        $ids = array_values(array_filter($ids, static function ($v) { return $v > 0; }));
        if (empty($ids)) {
            jsonRes(['success' => false, 'message' => 'No finding IDs provided'], 400);
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $baseDirForDelete = getBaseDir();
        $projectRootForDelete = realpath(__DIR__ . '/..');
        if (!$projectRootForDelete) {
            throw new RuntimeException('Project root not found');
        }

        if ($pageId > 0) {
            $selectSql = "SELECT screenshots_json FROM automated_a11y_findings WHERE project_id = ? AND page_id = ? AND id IN ($ph)";
            $selectParams = array_merge([$projectId, $pageId], $ids);
            $sql = "DELETE FROM automated_a11y_findings WHERE project_id = ? AND page_id = ? AND id IN ($ph)";
            $params = array_merge([$projectId, $pageId], $ids);
        } else {
            $selectSql = "SELECT screenshots_json FROM automated_a11y_findings WHERE project_id = ? AND id IN ($ph)";
            $selectParams = array_merge([$projectId], $ids);
            $sql = "DELETE FROM automated_a11y_findings WHERE project_id = ? AND id IN ($ph)";
            $params = array_merge([$projectId], $ids);
        }

        $shotsStmt = $db->prepare($selectSql);
        $shotsStmt->execute($selectParams);
        $deleteShots = [];
        while ($row = $shotsStmt->fetch(PDO::FETCH_ASSOC)) {
            $deleteShots = array_merge($deleteShots, decodeScreenshotList($row['screenshots_json'] ?? '[]'));
        }

        $del = $db->prepare($sql);
        $del->execute($params);
        if (!empty($deleteShots)) {
            deleteScreenshotFiles($deleteShots, $baseDirForDelete, $projectRootForDelete);
        }
        jsonRes(['success' => true, 'deleted' => (int)$del->rowCount()]);
    }

    if ($method !== 'POST') {
        jsonRes(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    // CSRF protection for scan POST
    enforceApiCsrf();

    $pageId = (int)($_POST['page_id'] ?? $_POST['unique_id'] ?? 0);
    if ($pageId <= 0) {
        jsonRes(['success' => false, 'message' => 'Page identifier is required'], 400);
    }

    $stmt = $db->prepare("SELECT id, page_name, url FROM project_pages WHERE project_id = ? AND id = ? LIMIT 1");
    $stmt->execute([$projectId, $pageId]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page) {
        jsonRes(['success' => false, 'message' => 'Page not found in this project'], 404);
    }

    $scanUrl = normalizeScanUrl((string)($page['url'] ?? ''));
    if ($scanUrl === null) {
        jsonRes(['success' => false, 'message' => 'Page URL is empty or invalid.'], 400);
    }

    $scanToken = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $scanRelDir = 'uploads/automated_findings/project_' . $projectId . '/page_' . (int)$page['id'] . '/' . $scanToken;
    $scanAbsDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $scanRelDir);
    if (!is_dir($scanAbsDir)) {
        @mkdir($scanAbsDir, 0777, true);
    }

    $outJson = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'a11y_scan_' . $scanToken . '.json';
    @mkdir(dirname($outJson), 0777, true);

    $scanUrls = [];
    $scanUrlsRaw = $_POST['scan_urls'] ?? '';
    $progressToken = trim((string)($_POST['progress_token'] ?? ''));
    if (is_array($scanUrlsRaw)) {
        $scanUrls = $scanUrlsRaw;
    } elseif (is_string($scanUrlsRaw) && trim($scanUrlsRaw) !== '') {
        $decoded = json_decode($scanUrlsRaw, true);
        if (is_array($decoded)) {
            $scanUrls = $decoded;
        } else {
            $scanUrls = array_map('trim', explode(',', $scanUrlsRaw));
        }
    }
    $scanUrls = array_values(array_unique(array_filter(array_map(static function ($u) {
        return trim((string)$u);
    }, $scanUrls), static function ($u) {
        return $u !== '';
    })));
    if (empty($scanUrls)) {
        $scanUrls = [$scanUrl];
    }
    $totalUrls = count($scanUrls);
    $completedUrls = 0;
    if ($progressToken !== '') {
        writeScanProgress($progressToken, [
            'status' => 'running',
            'completed' => 0,
            'total' => $totalUrls,
            'percent' => 0,
            'page_id' => $pageId,
            'project_id' => $projectId,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    $backgroundMode = false;
    // Return immediate response to the user and continue in background
    if (function_exists('fastcgi_finish_request')) {
        $backgroundMode = true;
        echo json_encode(['success' => true, 'status' => 'started', 'token' => $progressToken]);
        session_write_close();
        fastcgi_finish_request();
    } else {
        // Fallback for non-FPM environments
        ignore_user_abort(true);
        set_time_limit(1800);
        $backgroundMode = true;
        $res = json_encode(['success' => true, 'status' => 'started', 'token' => $progressToken]);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($res));
        header('Connection: close');
        echo $res;
        @ob_end_flush();
        @flush();
        session_write_close();
    }

    // --- EVERYTHING BELOW RUNS IN BACKGROUND ---
    ignore_user_abort(true);
    set_time_limit(0);

    $combinedFindings = [];
    $summary = ['issues' => 0, 'critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0];
    $scanMode = 'default';
    foreach ($scanUrls as $targetUrlRaw) {
        $targetUrl = normalizeScanUrl($targetUrlRaw);
        if ($targetUrl === null) continue;
        $urlToken = substr(sha1($targetUrl), 0, 10);
        $urlOutJson = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'a11y_scan_' . $scanToken . '_' . $urlToken . '.json';
        $scanResult = runDeepScan($targetUrl, $urlOutJson, $scanAbsDir, $progressToken, $scanMode);
        $urlFindings = is_array($scanResult['findings'] ?? null) ? $scanResult['findings'] : [];
        foreach ($urlFindings as &$finding) {
            if (!is_array($finding)) continue;
            $finding['scan_url'] = $targetUrl;
        }
        unset($finding);
        $combinedFindings = array_merge($combinedFindings, $urlFindings);

        $s = is_array($scanResult['summary'] ?? null) ? $scanResult['summary'] : [];
        $summary['issues'] += (int)($s['issues'] ?? 0);
        $summary['critical'] += (int)($s['critical'] ?? 0);
        $summary['serious'] += (int)($s['serious'] ?? 0);
        $summary['moderate'] += (int)($s['moderate'] ?? 0);
        $summary['minor'] += (int)($s['minor'] ?? 0);
        $completedUrls++;
        if ($progressToken !== '') {
            $percent = $totalUrls > 0 ? (int)floor(($completedUrls / $totalUrls) * 100) : 100;
            writeScanProgress($progressToken, [
                'status' => 'running',
                'completed' => $completedUrls,
                'total' => $totalUrls,
                'percent' => $percent,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    $aggregatedFindings = aggregateFindingsByRule($combinedFindings);
    $scanJson = [
        'success' => true,
        'summary' => $summary,
        'findings' => $aggregatedFindings
    ];
    $saved = saveFindings($db, $projectId, (int)$page['id'], $userId, $scanUrl, $scanJson, $scanRelDir, getBaseDir(), $scanUrls);
    if ($progressToken !== '') {
        writeScanProgress($progressToken, [
            'status' => 'completed',
            'completed' => $totalUrls,
            'total' => $totalUrls,
            'percent' => 100,
            'saved_findings' => $saved,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    if ($backgroundMode) {
        exit;
    }

    jsonRes([
        'success' => true,
        'project_id' => $projectId,
        'page_id' => (int)$page['id'],
        'page_name' => (string)$page['page_name'],
        'scan_url' => $scanUrl,
        'scan_urls' => $scanUrls,
        'generated_at' => date('Y-m-d H:i:s'),
        'summary' => $summary,
        'saved_findings' => $saved
    ]);
} catch (Throwable $e) {
    error_log('accessibility_scan error: ' . $e->getMessage());
    $progressToken = trim((string)($_POST['progress_token'] ?? $_GET['progress_token'] ?? ''));
    if ($progressToken !== '') {
        writeScanProgress($progressToken, [
            'status' => 'failed',
            'completed' => 0,
            'total' => 0,
            'percent' => 0,
            'error' => $e->getMessage(),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }
    file_put_contents(__DIR__ . '/../tmp/pms_error.txt', "Error at line " . $e->getLine() . ": " . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonRes(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
