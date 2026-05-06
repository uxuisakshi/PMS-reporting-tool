<?php
/**
 * Server-side Excel report generator using ZipArchive.
 * Opens template xlsx, injects data into XML, streams result.
 * Preserves ALL formatting, charts, images, and formulas.
 */
// Increase memory limit for large exports (70+ issues with metadata)
ini_set('memory_limit', '256M');
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/client_issue_snapshots.php';
require_once __DIR__ . '/../includes/models/SecurityValidator.php';
// Keep ob_start active for the entire script to trap any potential warnings/notices.


$auth = new Auth();
if (!$auth->isLoggedIn()) { http_response_code(401); exit('Unauthorized'); }

$securityValidator = new SecurityValidator();
$csrfToken = (string) ($_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!$securityValidator->validateCSRFToken($csrfToken, (string) ($_SESSION['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$projectId = (int)($_GET['project_id'] ?? 0);
$format = strtolower(trim((string)($_GET['format'] ?? 'excel')));
$clientReadyOnly = !empty($_GET['client_ready_only']) || (($_SESSION['role'] ?? '') === 'client');
if (!$projectId) { http_response_code(400); exit('project_id required'); }
if (!in_array($format, ['excel', 'pdf'], true)) { http_response_code(400); exit('Invalid format'); }
if ($format === 'excel' && !class_exists('ZipArchive')) { http_response_code(500); exit('ZipArchive not available'); }

$templatePath = __DIR__ . '/../assets/templates/report_template.xlsx';
if ($format === 'excel' && !file_exists($templatePath)) { http_response_code(404); exit('Template not found'); }

$db = Database::getInstance();

// IDOR: verify user has access to this project
require_once __DIR__ . '/../includes/project_permissions.php';
if (!hasProjectAccess($db, $_SESSION['user_id'], $projectId)) {
    http_response_code(403);
    exit('Access denied');
}

// ── helpers ───────────────────────────────────────────────────────────────────

/**
 * Get first value for a meta key. Handles JSON-encoded arrays stored as strings.
 */
function metaFirst(array $meta, string $key): string {
    if (empty($meta[$key])) return '';
    $v = $meta[$key][0]; // always array of raw DB rows
    if (is_string($v) && strlen($v) > 0 && $v[0] === '[') {
        $d = json_decode($v, true);
        if (is_array($d) && !empty($d)) return (string)$d[0];
    }
    return (string)$v;
}

/**
 * Get all values for a meta key as a flat array.
 * Each DB row is one value (may be plain string or JSON array).
 * Does NOT split on comma — each row is treated as one atomic value.
 */
function metaArray(array $meta, string $key): array {
    if (empty($meta[$key])) return [];
    $out = [];
    foreach ($meta[$key] as $v) {
        $v = (string)$v;
        if (strlen($v) > 0 && $v[0] === '[') {
            $d = json_decode($v, true);
            if (is_array($d)) {
                foreach ($d as $item) {
                    $item = trim((string)$item);
                    if ($item !== '') $out[] = $item;
                }
                continue;
            }
        }
        $v = trim($v);
        if ($v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
}

function xstr(string $v): string {
    // Fix invalid UTF-8 sequences first
    $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    // Strip illegal XML 1.0 characters (control chars except tab/LF/CR, and UTF-8 surrogates)
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    // Strip UTF-8 encoded surrogates (U+D800–U+DFFF)
    $v = preg_replace('/\xED[\xA0-\xBF][\x80-\xBF]/s', '', $v);
    // Normalize CR+LF and bare CR to LF
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    // Escape XML special chars FIRST (& must be escaped before we insert &#10;)
    $v = htmlspecialchars($v, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    // Now encode newlines as XML numeric entity (after htmlspecialchars so & isn't double-escaped)
    $v = str_replace("\n", '&#10;', $v);
    return $v;
}

/**
 * Convert 0-indexed column number to Excel column letter (e.g. 0 -> A, 26 -> AA).
 */
function numToCol(int $n): string {
    $result = '';
    while ($n >= 0) {
        $result = chr($n % 26 + 65) . $result;
        $n = intdiv($n, 26) - 1;
    }
    return $result;
}

/** Build an xlsx inlineStr cell — supports newlines and all text content */
function xcell(string $col, int $row, string $val, string $style = ''): string {
    $s = $style ? ' s="' . $style . '"' : '';
    return '<c r="' . $col . $row . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . xstr($val) . '</t></is></c>';
}

/**
 * Replace a cell in worksheet XML with a new value.
 * Handles both self-closing <c .../> and normal <c ...>...</c> forms.
 * Preserves the style (s="N") attribute.
 */
function setCell(string &$xml, string $ref, string $value, bool $isNum = false): void {
    $rq = preg_quote($ref, '/');
    $style = '';

    // Try self-closing form first: <c r="REF" s="N"/>
    // Use strict pattern: [^/]* to avoid matching past the />
    $selfPat = '/<c\s+r="' . $rq . '"([^\/]*)\s*\/>/';
    if (preg_match($selfPat, $xml, $m)) {
        if (preg_match('/\bs="(\d+)"/', $m[1], $sm)) $style = ' s="' . $sm[1] . '"';
        $repl = $isNum
            ? '<c r="' . $ref . '"' . $style . '><v>' . (int)$value . '</v></c>'
            : '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . xstr($value) . '</t></is></c>';
        $xml = preg_replace($selfPat, $repl, $xml, 1);
        return;
    }

    // Normal form: find opening <c r="REF" ...> then scan to matching </c>
    $openPat = '/<c\s+r="' . $rq . '"([^>]*)>/';
    if (!preg_match($openPat, $xml, $m, PREG_OFFSET_CAPTURE)) return;
    if (preg_match('/\bs="(\d+)"/', $m[1][0], $sm)) $style = ' s="' . $sm[1] . '"';
    $start    = $m[0][1];
    $closePos = strpos($xml, '</c>', $start + strlen($m[0][0]));
    if ($closePos === false) return;
    $end = $closePos + 4;

    $repl = $isNum
        ? '<c r="' . $ref . '"' . $style . '><v>' . (int)$value . '</v></c>'
        : '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . xstr($value) . '</t></is></c>';
    $xml = substr($xml, 0, $start) . $repl . substr($xml, $end);
}

/**
 * Inject or replace a cell in a specific row. Creates the row if needed.
 */
function injectCell(string &$xml, int $rowNum, string $ref, string $value, string $styleAttr = '', bool $isNum = false): void {
    $rq = preg_quote($ref, '/');

    // Check if cell already exists (self-closing or normal)
    $cellExists = preg_match('/<c\s+r="' . $rq . '"\s*\/>/', $xml)
               || preg_match('/<c\s+r="' . $rq . '"[^\/][^>]*>/', $xml);
    if ($cellExists) {
        setCell($xml, $ref, $value, $isNum);
        return;
    }

    $newCell = $isNum
        ? '<c r="' . $ref . '"' . $styleAttr . '><v>' . (int)$value . '</v></c>'
        : '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">' . xstr($value) . '</t></is></c>';

    // Find existing row — use exact match with word boundary to avoid matching r="160" when looking for r="16"
    // Pattern: <row r="N" followed by space, >, or />
    $rowPat = '/<row\s+r="' . $rowNum . '"(\s[^>]*)?>.*?<\/row>/s';
    if (preg_match($rowPat, $xml, $rowMatch, PREG_OFFSET_CAPTURE)) {
        $rowStart   = $rowMatch[0][1];
        $rowFull    = $rowMatch[0][0];
        // Update spans to include the new cell's column (e.g. B=2, C=3)
        // Extract column letter(s) from ref (e.g. "B33" -> "B")
        $colLetter = preg_replace('/\d+/', '', $ref);
        $colNum = 0;
        foreach (str_split(strtoupper($colLetter)) as $ch) {
            $colNum = $colNum * 26 + (ord($ch) - ord('A') + 1);
        }
        // Update spans="min:max" to include colNum
        $newRowFull = preg_replace_callback('/\bspans="(\d+):(\d+)"/', function($sm) use ($colNum) {
            $min = min((int)$sm[1], $colNum);
            $max = max((int)$sm[2], $colNum);
            return 'spans="' . $min . ':' . $max . '"';
        }, $rowFull);
        // Find </row> within this match and insert before it
        $closePos   = strrpos($newRowFull, '</row>');
        $newRowFull = substr($newRowFull, 0, $closePos) . $newCell . '</row>';
        $xml = substr($xml, 0, $rowStart) . $newRowFull . substr($xml, $rowStart + strlen($rowFull));
        return;
    }

    // Row doesn't exist — create it before </sheetData>
    if (strpos($xml, '</sheetData>') !== false) {
        $xml = preg_replace('/<\/sheetData>/', '<row r="' . $rowNum . '" spans="1:18">' . $newCell . '</row></sheetData>', $xml, 1);
    } else {
        $xml = preg_replace('/<sheetData\s*\/>/s', '<sheetData><row r="' . $rowNum . '" spans="1:18">' . $newCell . '</row></sheetData>', $xml, 1);
    }
}

/**
 * Inject rows into a sheet's sheetData. Replaces existing sheetData content
 * (after clearDataRows) with the new rows. Handles both </sheetData> and
 * self-closing <sheetData/> forms. Safe to call even if rows string is empty.
 * Also updates the <dimension> element to cover all rows.
 */
function injectRows(string &$xml, string $rows): void {
    if (strpos($xml, '</sheetData>') !== false) {
        // Use str_replace instead of preg_replace to avoid $ backreference issues in $rows
        $xml = str_replace('</sheetData>', $rows . '</sheetData>', $xml);
    } else {
        $xml = preg_replace('/<sheetData\s*\/>/s', '<sheetData>' . $rows . '</sheetData>', $xml, 1);
    }
    // Remove dimension element — Excel will recalculate it, avoids "repair" dialog
    $xml = preg_replace('/<dimension\s+ref="[^"]*"\s*\/>/s', '', $xml);
}

function shouldSkipIssueForExport(array $qaStatusKeys, array $qaStatusLabels): bool {
    if (empty($qaStatusKeys)) {
        return false;
    }

    $deleteOrDuplicateCount = 0;
    $meaningfulStatusCount = 0;

    foreach ($qaStatusKeys as $qk) {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim((string) $qk)));
        $label = strtolower(trim((string) ($qaStatusLabels[strtolower(trim((string) $qk))] ?? $normalized)));

        if ($normalized === '' && $label === '') {
            continue;
        }

        $meaningfulStatusCount++;
        if (strpos($normalized, 'delete') !== false || strpos($normalized, 'duplicate') !== false
            || strpos($label, 'delete') !== false || strpos($label, 'duplicate') !== false) {
            $deleteOrDuplicateCount++;
        }
    }

    return $meaningfulStatusCount > 0 && $deleteOrDuplicateCount === $meaningfulStatusCount;
}

/** Convert a column letter (A, B, ..., Z, AA, ...) to a 1-based integer. */
function colToNum(string $col): int {
    $col = strtoupper($col);
    $n = 0;
    foreach (str_split($col) as $ch) {
        $n = $n * 26 + (ord($ch) - ord('A') + 1);
    }
    return $n;
}

/**
 * Sort cells within a row XML string by column order.
 * Excel REQUIRES cells within a row to be in strictly increasing column order.
 * Handles both self-closing <c .../> and normal <c ...>...</c> forms.
 */
function sortCellsInRow(string $rowXml): string {
    // Extract the opening <row ...> tag
    $openEnd = strpos($rowXml, '>');
    if ($openEnd === false) return $rowXml;
    $openTag = substr($rowXml, 0, $openEnd + 1);
    $inner   = substr($rowXml, $openEnd + 1, -6); // strip </row>

    // Parse cells
    $cells = [];
    $offset = 0;
    $len = strlen($inner);
    while ($offset < $len) {
        $cStart = strpos($inner, '<c ', $offset);
        if ($cStart === false) break;
        // Get cell ref
        $cAttrEnd = strpos($inner, '>', $cStart);
        if ($cAttrEnd === false) break;
        $cOpenTag = substr($inner, $cStart, $cAttrEnd - $cStart + 1);
        if (!preg_match('/\br="([A-Z]+)(\d+)"/', $cOpenTag, $cm)) { $offset = $cAttrEnd + 1; continue; }
        $colLetter = $cm[1];
        $colNum = colToNum($colLetter);

        // Self-closing cell?
        if (substr($cOpenTag, -2) === '/>') {
            $cells[$colNum] = $cOpenTag;
            $offset = $cAttrEnd + 1;
            continue;
        }
        // Normal cell — find </c>
        $cClose = strpos($inner, '</c>', $cAttrEnd);
        if ($cClose === false) break;
        $cells[$colNum] = substr($inner, $cStart, $cClose + 4 - $cStart);
        $offset = $cClose + 4;
    }

    if (empty($cells)) return $rowXml;
    ksort($cells, SORT_NUMERIC);
    return $openTag . implode('', $cells) . '</row>';
}

/** Remove duplicate rows or out-of-order rows.
 *  Excel REQUIRES rows in a worksheet to be in strictly increasing order of the 'r' attribute.
 *  Also sorts cells within each row by column order (Excel requirement).
 */
function sortRows(string &$xml): void {
    $startTag = '<sheetData>';
    $endTag   = '</sheetData>';
    $startPos = strpos($xml, $startTag);
    $endPos   = strpos($xml, $endTag);

    if ($startPos === false || $endPos === false) return;

    $contentStart = $startPos + strlen($startTag);
    $content = substr($xml, $contentStart, $endPos - $contentStart);

    // Extract rows using regex to correctly handle row boundaries
    // Each row is: <row r="N" ...>...</row>
    // We use a regex that matches from <row to the NEXT </row> that closes it
    $rows = [];
    $offset = 0;
    $len = strlen($content);
    while ($offset < $len) {
        // Find next <row
        $rowStart = strpos($content, '<row ', $offset);
        if ($rowStart === false) break;

        // Get opening tag end
        $attrEnd = strpos($content, '>', $rowStart);
        if ($attrEnd === false) break;
        $openTag = substr($content, $rowStart, $attrEnd - $rowStart + 1);

        if (!preg_match('/\br="(\d+)"/', $openTag, $rm)) { $offset = $attrEnd + 1; continue; }
        $rNum = (int)$rm[1];

        // Self-closing row?
        if (substr($openTag, -2) === '/>') {
            $rows[$rNum] = $openTag;
            $offset = $attrEnd + 1;
            continue;
        }

        // Find matching </row> by scanning forward past all <c ...>...</c> cells
        // We track depth: <row opens at depth 1, </row> closes it
        // Since rows don't nest, we just need to find </row> after all cells are closed
        // Cells use <c ...>...</c> or <c .../> - they don't contain </row>
        // So the first </row> after $attrEnd is always the correct closing tag
        // UNLESS cell values contain the literal string </row> - but xstr() escapes all < and >
        // so this is safe.
        $closePos = strpos($content, '</row>', $attrEnd);
        if ($closePos === false) break;
        $rowFull = substr($content, $rowStart, $closePos + 6 - $rowStart);
        $rows[$rNum] = $rowFull;
        $offset = $closePos + 6;
    }

    ksort($rows, SORT_NUMERIC);
    // Sort cells within each row to satisfy Excel's column-order requirement
    $sorted = array_map('sortCellsInRow', $rows);
    $xml = substr($xml, 0, $contentStart) . implode('', $sorted) . substr($xml, $endPos);
}

/** Remove all data rows (row 2 onwards), keep header row 1.
 *  Handles both <row ...>...</row> and self-closing <row .../> forms.
 */
function clearDataRows(string &$xml): void {
    // Self-closing rows: <row r="N" ... />
    // First, clear rows 10-99... then 2-9
    $xml = preg_replace('/<row\s+r="[1-9]\d+"[^>]*\/>/s', '', $xml);
    $xml = preg_replace('/<row\s+r="[2-9]"[^>]*\/>/s', '', $xml);
    // Normal rows: <row r="N" ...>...</row>
    $xml = preg_replace('/<row\s+r="[1-9]\d+"[^>]*>.*?<\/row>/s', '', $xml);
    $xml = preg_replace('/<row\s+r="[2-9]"[^>]*>.*?<\/row>/s', '', $xml);
}

function stripHtml(string $html): string {
    if (!$html) return '';

    // Preserve literal code blocks before stripping other HTML tags.
    $text = preg_replace_callback(
        '/<(?:pre|code)\b[^>]*>(.*?)<\/\s*(?:pre|code)>/is',
        function ($matches) {
            return '###CODEBLOCK###' . htmlspecialchars($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '###ENDCODE###';
        },
        $html
    );

    // Convert block-level formatting tags to newlines or bullets.
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<li\b[^>]*>/i', '• ', $text);
    $text = preg_replace('/<\/li>/i', "\n", $text);
    $text = preg_replace('/<\/p>/i', "\n", $text);
    $text = preg_replace('/<\/h[1-6]>/i', "\n", $text);
    $text = preg_replace('/<\/div>/i', "\n", $text);
    $text = preg_replace('/<\/ul>/i', "\n", $text);
    $text = preg_replace('/<\/ol>/i', "\n", $text);
    // Remove opening tags for headings and block containers now that we have newlines.
    $text = preg_replace('/<\/?(?:div|p|h[1-6]|ul|ol)[^>]*>/i', '', $text);
    // Strip any remaining tags.
    $text = strip_tags($text);
    // Decode HTML entities after stripping tags so encoded markup remains literal.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Restore preserved code content.
    $text = preg_replace_callback('/###CODEBLOCK###(.*?)###ENDCODE###/s', function ($matches) {
        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }, $text);
    // Normalize whitespace/newlines.
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n[ \t]*/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/** Build absolute URL from a possibly-relative src path */
function absoluteUrl(string $src): string {
    if ($src === '') return '';
    if (preg_match('#^https?://#i', $src)) return $src;
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host . '/' . ltrim($src, '/');
}

/**
 * Extract named sections from issue description HTML.
 * Sections are delimited by [Section Name] markers embedded in the rich text.
 * Works on both raw HTML and plain text descriptions.
 * Returns array: ['actual_result' => '...html...', 'incorrect_code' => '...', ...]
 */
function extractSections(string $html): array {
    $keys = ['actual_result', 'incorrect_code', 'screenshot', 'recommendation', 'correct_code'];
    $empty = array_fill_keys($keys, '');

    if (!$html) return $empty;

    // Marker labels (case-insensitive)
    $labels = [
        'actual_result'  => 'Actual Result',
        'incorrect_code' => 'Incorrect Code',
        'screenshot'     => 'Screenshot',
        'recommendation' => 'Recommendation',
        'correct_code'   => 'Correct Code',
    ];

    // Strategy 1: markers exist directly in HTML (most common)
    // Try matching on raw HTML first
    $found = false;
    foreach ($labels as $key => $label) {
        if (stripos($html, '[' . $label . ']') !== false) { $found = true; break; }
    }

    // Strategy 2: markers may be inside HTML tags or encoded — strip tags first
    // then re-match on plain text, but keep original HTML for section content
    if (!$found) {
        // Try after stripping tags (markers might be wrapped in <p>, <strong> etc.)
        $plain = strip_tags($html);
        foreach ($labels as $key => $label) {
            if (stripos($plain, '[' . $label . ']') !== false) { $found = true; break; }
        }
        if ($found) {
            // Work on plain text for section splitting, return plain text sections
            $html = $plain;
        }
    }

    if (!$found) return $empty;

    $patterns = [
        'actual_result'  => '/\[Actual Result\](.*?)(?=\[(?:Incorrect Code|Screenshot|Recommendation|Correct Code)\]|\z)/is',
        'incorrect_code' => '/\[Incorrect Code\](.*?)(?=\[(?:Actual Result|Screenshot|Recommendation|Correct Code)\]|\z)/is',
        'screenshot'     => '/\[Screenshot\](.*?)(?=\[(?:Actual Result|Incorrect Code|Recommendation|Correct Code)\]|\z)/is',
        'recommendation' => '/\[Recommendation\](.*?)(?=\[(?:Actual Result|Incorrect Code|Screenshot|Correct Code)\]|\z)/is',
        'correct_code'   => '/\[Correct Code\](.*?)(?=\[(?:Actual Result|Incorrect Code|Screenshot|Recommendation)\]|\z)/is',
    ];

    $out = $empty;
    foreach ($patterns as $key => $pat) {
        if (preg_match($pat, $html, $m)) {
            $content = trim(preg_replace(
                '/^(?:\s|&nbsp;|<br\s*\/?>|<\/?(?:p|div)[^>]*>)+|(?:\s|&nbsp;|<br\s*\/?>|<\/?(?:p|div)[^>]*>)+$/i',
                '', $m[1] ?? ''
            ));
            $out[$key] = $content;
        }
    }
    return $out;
}

/**
 * Extract all <img src="..."> URLs from an HTML string.
 */
function extractImageUrls(string $html): array {
    if (!$html) return [];
    preg_match_all('/<img\b[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $html, $m);
    return array_values(array_filter($m[1] ?? []));
}

// ── fetch project ─────────────────────────────────────────────────────────────
$projStmt = $db->prepare("SELECT title, project_type FROM projects WHERE id = ?");
$projStmt->execute([$projectId]);
$project = $projStmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { http_response_code(404); exit('Project not found'); }

$typeMap = ['web' => 'Website', 'app' => 'Mobile App', 'pdf' => 'PDF'];
$projectTypeLabel = $typeMap[strtolower($project['project_type'] ?? '')] ?? ($project['project_type'] ?? '');

// ── team members ──────────────────────────────────────────────────────────────
$teamStmt = $db->prepare("
    SELECT DISTINCT u.full_name, ua.role
    FROM user_assignments ua JOIN users u ON u.id = ua.user_id
    WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    ORDER BY ua.role, u.full_name
");
$teamStmt->execute([$projectId]);
$teamMembers = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
$roleLabels = ['admin'=>'Admin','project_lead'=>'Project Lead',
               'qa'=>'QA','at_tester'=>'AT Tester','ft_tester'=>'FT Tester'];

// ── issues + metadata ─────────────────────────────────────────────────────────
$allIssues = [];
$metaMap = [];
$pagesByIssue = [];
$qaStatusByIssue = [];

if ($clientReadyOnly) {
    $records = getClientVisibleIssueRecords($db, [$projectId], ['order_by' => 'i.id ASC']);
    foreach ($records as $record) {
        $issue = $record['issue'] ?? [];
        $iid = (int)($issue['id'] ?? 0);
        if ($iid <= 0) {
            continue;
        }
        $allIssues[] = [
            'id' => $iid,
            'title' => (string)($issue['title'] ?? ''),
            'description' => (string)($issue['description'] ?? ''),
            'severity' => (string)($issue['severity'] ?? ''),
            'issue_key' => (string)($issue['issue_key'] ?? ''),
            'status_name' => (string)($issue['status_name'] ?? ''),
        ];
        $metaMap[$iid] = $record['meta'] ?? [];
        $pagesByIssue[$iid] = $record['pages'] ?? [];

        $keys = [];
        $meta = $metaMap[$iid] ?? [];
        if (!empty($meta['reporter_qa_status_map'])) {
            foreach ($meta['reporter_qa_status_map'] as $v) {
                $d = json_decode($v, true);
                if (is_array($d)) {
                    foreach ($d as $statuses) {
                        foreach ((array)$statuses as $sk) {
                            $sk = strtolower(trim((string)$sk));
                            if ($sk !== '') $keys[] = $sk;
                        }
                    }
                }
            }
        }
        if (empty($keys) && !empty($meta['qa_status'])) {
            foreach ($meta['qa_status'] as $v) {
                $d = json_decode($v, true);
                if (is_array($d)) {
                    foreach ($d as $sk) $keys[] = strtolower(trim((string)$sk));
                } else {
                    $keys[] = strtolower(trim((string)$v));
                }
            }
        }
        $qaStatusByIssue[$iid] = array_values(array_unique(array_filter($keys)));
    }
} else {
    $issueStmt = $db->prepare("
        SELECT i.id, i.title, i.description, i.severity, i.issue_key,
               s.name as status_name
        FROM issues i
        LEFT JOIN issue_statuses s ON s.id = i.status_id
        WHERE i.project_id = ?
        ORDER BY i.id ASC
    ");
    $issueStmt->execute([$projectId]);
    $allIssues = $issueStmt->fetchAll(PDO::FETCH_ASSOC);
    $issueIds  = array_column($allIssues, 'id');

    if (!empty($issueIds)) {
        $ph = implode(',', array_fill(0, count($issueIds), '?'));

        $ms = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($ph) ORDER BY id ASC");
        $ms->execute($issueIds);
        while ($m = $ms->fetch(PDO::FETCH_ASSOC)) {
            $metaMap[(int)$m['issue_id']][$m['meta_key']][] = $m['meta_value'];
        }

        $ps = $db->prepare("SELECT ip.issue_id, pp.id AS page_id, pp.page_number, pp.page_name, pp.url FROM issue_pages ip JOIN project_pages pp ON ip.page_id = pp.id WHERE ip.issue_id IN ($ph)");
        $ps->execute($issueIds);
        while ($p = $ps->fetch(PDO::FETCH_ASSOC)) {
            $pagesByIssue[(int)$p['issue_id']][] = $p;
        }

        foreach ($issueIds as $iid) {
            $meta = $metaMap[$iid] ?? [];
            $keys = [];
            if (!empty($meta['reporter_qa_status_map'])) {
                foreach ($meta['reporter_qa_status_map'] as $v) {
                    $d = json_decode($v, true);
                    if (is_array($d)) {
                        foreach ($d as $statuses) {
                            foreach ((array)$statuses as $sk) {
                                $sk = strtolower(trim((string)$sk));
                                if ($sk !== '') $keys[] = $sk;
                            }
                        }
                    }
                }
            }
            if (empty($keys) && !empty($meta['qa_status'])) {
                foreach ($meta['qa_status'] as $v) {
                    $d = json_decode($v, true);
                    if (is_array($d)) { foreach ($d as $sk) $keys[] = strtolower(trim((string)$sk)); }
                    else { $keys[] = strtolower(trim($v)); }
                }
            }
            $qaStatusByIssue[$iid] = array_values(array_unique(array_filter($keys)));
        }
    }
}

// QA status labels
$qaStatusLabels = [];
try {
    $qsm = $db->query("SELECT status_key, status_label FROM qa_status_master WHERE is_active = 1");
    while ($qs = $qsm->fetch(PDO::FETCH_ASSOC)) {
        $qaStatusLabels[strtolower($qs['status_key'])] = strtolower($qs['status_label']);
    }
} catch (Exception $e) {}

// ── filter deleted/duplicate issues ──────────────────────────────────────────
$filteredIssues = [];
foreach ($allIssues as $iss) {
    $iid  = (int)$iss['id'];
    $skip = shouldSkipIssueForExport($qaStatusByIssue[$iid] ?? [], $qaStatusLabels);
    if (!$skip) $filteredIssues[] = $iss;
}

if (empty($filteredIssues) && !empty($allIssues)) {
    $filteredIssues = $allIssues;
}

if ($format === 'pdf') {
    $safeTitle = trim(preg_replace('/[^a-zA-Z0-9_\- ]/', '', $project['title'] ?? 'Project')) ?: 'Project';
    $filename  = $safeTitle . ' - Accessibility Audit Report.pdf';

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($safeTitle, ENT_QUOTES, 'UTF-8'); ?> - Accessibility Audit Report</title>
        <style>
            body { font-family: Arial, sans-serif; color: #1f2937; margin: 24px; }
            h1, h2 { margin: 0 0 12px; }
            .meta { color: #6b7280; margin-bottom: 20px; }
            .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 20px 0; }
            .card { border: 1px solid #dbe3f0; border-radius: 10px; padding: 14px; background: #f8fbff; }
            .value { font-size: 24px; font-weight: 700; color: #0f4c81; }
            .label { font-size: 12px; text-transform: uppercase; color: #6b7280; }
            table { width: 100%; border-collapse: collapse; margin: 14px 0 24px; }
            th, td { border: 1px solid #d1d5db; padding: 8px 10px; text-align: left; vertical-align: top; }
            th { background: #eef4fb; }
            .section { margin-top: 28px; page-break-inside: avoid; }
            .actions { margin-bottom: 20px; }
            .actions button { padding: 10px 16px; border: 0; border-radius: 6px; cursor: pointer; }
            .print-btn { background: #0f4c81; color: #fff; }
            .close-btn { background: #6b7280; color: #fff; margin-left: 10px; }
            @media print { .actions { display: none; } body { margin: 0; } }
        </style>
    </head>
    <body>
        <div class="actions">
            <button class="print-btn" onclick="window.print()">Print / Save as PDF</button>
            <button class="close-btn" onclick="window.close()">Close</button>
        </div>

        <h1>Accessibility Audit Report</h1>
        <div class="meta">
            <div><strong>Digital Asset:</strong> <?php echo htmlspecialchars($project['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Project Type:</strong> <?php echo htmlspecialchars($projectTypeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Generated:</strong> <?php echo htmlspecialchars(date('F j, Y, g:i a'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Issue Scope:</strong> <?php echo $clientReadyOnly ? 'Client-ready issues only' : 'All issues'; ?></div>
        </div>

        <div class="stats">
            <div class="card"><div class="value"><?php echo count($filteredIssues); ?></div><div class="label">Total Issues</div></div>
            <div class="card"><div class="value"><?php echo count($failingSCsA); ?></div><div class="label">Failing A</div></div>
            <div class="card"><div class="value"><?php echo count($failingSCsAA); ?></div><div class="label">Failing AA</div></div>
            <div class="card"><div class="value"><?php echo count($projectPages); ?></div><div class="label">Pages</div></div>
        </div>

        <div class="section">
            <h2>Issue Severity Distribution</h2>
            <table>
                <thead><tr><th>Severity</th><th>Count</th></tr></thead>
                <tbody>
                <?php foreach ($severityCountsSorted as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['severity'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)$row['count']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Top Issues</h2>
            <table>
                <thead><tr><th>Issue Title</th><th>WCAG SC</th></tr></thead>
                <tbody>
                <?php foreach ($topIssues as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['sc_nums'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Final Report</h2>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Issue Key</th>
                        <th>Page No</th>
                        <th>Page Name</th>
                        <th>Issue Title</th>
                        <th>Severity</th>
                        <th>Developer Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php $rowNumber = 1; ?>
                <?php foreach ($filteredIssues as $iss): ?>
                    <?php
                    $iid = (int)$iss['id'];
                    $meta = $metaMap[$iid] ?? [];
                    $pages = $pagesByIssue[$iid] ?? [];
                    $pageNums = implode(', ', array_column($pages, 'page_number'));
                    $pageNames = implode(', ', array_column($pages, 'page_name'));
                    $severity = ucfirst(strtolower(metaFirst($meta, 'severity') ?: ($iss['severity'] ?? 'minor')));
                    ?>
                    <tr>
                        <td><?php echo $rowNumber++; ?></td>
                        <td><?php echo htmlspecialchars((string)($iss['issue_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($pageNums, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($pageNames, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($iss['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($severity, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($iss['status_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── overview calculations ─────────────────────────────────────────────────────
$wcagLevelMap = [];
try {
    $wc = $db->query("SELECT criterion_number, level FROM wcag_criteria");
    while ($row = $wc->fetch(PDO::FETCH_ASSOC)) {
        $wcagLevelMap[trim($row['criterion_number'])] = strtoupper(trim($row['level']));
    }
} catch (Exception $e) {}

$failingSCsA = []; $failingSCsAA = [];
$userAffectedCounts = [];
$severityCounts = [];
$severityOrder = ['blocker'=>0,'critical'=>1,'major'=>2,'minor'=>3,'low'=>4];
$issuesWithMeta = [];

foreach ($filteredIssues as $iss) {
    $iid  = (int)$iss['id'];
    $meta = $metaMap[$iid] ?? [];

    // WCAG levels
    $scNums   = metaArray($meta, 'wcagsuccesscriteria');
    $scLevels = metaArray($meta, 'wcagsuccesscriterialevel');
    foreach ($scNums as $idx => $sc) {
        $sc = trim($sc); if ($sc === '') continue;
        $level = strtoupper(trim($scLevels[$idx] ?? ''));
        if ($level === '' && isset($wcagLevelMap[$sc])) $level = $wcagLevelMap[$sc];
        if ($level === 'A') $failingSCsA[$sc] = true;
        elseif ($level === 'AA') $failingSCsAA[$sc] = true;
    }

    // Users affected — each DB row = one user (may be comma-separated if multiple selected)
    foreach ($meta['usersaffected'] ?? [] as $rawVal) {
        // Split on comma in case multiple users stored in one row
        foreach (array_filter(array_map('trim', explode(',', $rawVal))) as $u) {
            if ($u !== '') $userAffectedCounts[$u] = ($userAffectedCounts[$u] ?? 0) + 1;
        }
    }

    // Severity — use meta field first, then issue severity column; empty → "Missing Severity Info"
    $rawSev = trim(metaFirst($meta, 'severity'));
    if ($rawSev === '') $rawSev = trim((string)($iss['severity'] ?? ''));
    $sev = $rawSev !== '' ? ucfirst(strtolower($rawSev)) : 'Missing Severity Info';
    $severityCounts[$sev] = ($severityCounts[$sev] ?? 0) + 1;

    $issuesWithMeta[] = [
        'title'    => $iss['title'],
        'sc_nums'  => $scNums,
        'sev_rank' => $severityOrder[strtolower(trim(metaFirst($meta, 'severity') ?: 'minor'))] ?? 99,
    ];
}

// Top 5 issues by severity (unique titles)
usort($issuesWithMeta, fn($a, $b) => $a['sev_rank'] - $b['sev_rank']);
$topIssues = []; $seenTitles = [];
foreach ($issuesWithMeta as $iss) {
    $tk = strtolower(trim($iss['title']));
    if (isset($seenTitles[$tk])) continue;
    $seenTitles[$tk] = true;
    $topIssues[] = ['title' => $iss['title'], 'sc_nums' => implode(', ', $iss['sc_nums'])];
    if (count($topIssues) >= 5) break;
}

// Severity counts sorted
arsort($userAffectedCounts);
$sevOrderKeys = ['Blocker','Critical','Major','Minor','Low'];
$severityCountsSorted = [];
foreach ($sevOrderKeys as $k) {
    if (isset($severityCounts[$k])) $severityCountsSorted[] = ['severity' => $k, 'count' => $severityCounts[$k]];
}
foreach ($severityCounts as $k => $v) {
    if (!in_array($k, $sevOrderKeys)) $severityCountsSorted[] = ['severity' => $k, 'count' => $v];
}

// ── project pages ─────────────────────────────────────────────────────────────
$pagesStmt = $db->prepare("
    SELECT id, page_number, page_name, url FROM project_pages WHERE project_id = ?
    ORDER BY CASE WHEN page_number LIKE 'Global%' THEN 0 WHEN page_number LIKE 'Page%' THEN 1 ELSE 2 END,
             CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(page_number,' ',-1),' ',1) AS UNSIGNED), page_number, id ASC
");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

// All grouped URLs (for All URLs sheet)
$guStmt = $db->prepare("SELECT url FROM grouped_urls WHERE project_id = ? ORDER BY id ASC");
$guStmt->execute([$projectId]);
$allGroupedUrls = $guStmt->fetchAll(PDO::FETCH_COLUMN);

// Grouped URLs per page (for URL Details sheet)
$guPerPageStmt = $db->prepare("SELECT url FROM grouped_urls WHERE project_id = ? AND unique_page_id = ? ORDER BY id ASC");

// ── open template ─────────────────────────────────────────────────────────────
$tmpFile = tempnam(sys_get_temp_dir(), 'pms_rpt_') . '.xlsx';
copy($templatePath, $tmpFile);
$zip = new ZipArchive();
if ($zip->open($tmpFile) !== true) { http_response_code(500); exit('Cannot open template'); }

// Fix sharedStrings count attributes to prevent Excel repair dialog
$ss = $zip->getFromName('xl/sharedStrings.xml');
if ($ss !== false) {
    // Remove count/uniqueCount so Excel recalculates — avoids mismatch after cell replacements
    $ss = preg_replace('/\s+count="\d+"/', '', $ss);
    $ss = preg_replace('/\s+uniqueCount="\d+"/', '', $ss);
    $zip->addFromString('xl/sharedStrings.xml', $ss);
}

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 1: Overview
// ══════════════════════════════════════════════════════════════════════════════
$sh1 = $zip->getFromName('xl/worksheets/sheet1.xml');

// Basic project info
setCell($sh1, 'F2', $project['title'] ?? '');
setCell($sh1, 'F3', $projectTypeLabel);
setCell($sh1, 'F4', 'Sakshi Infotech Solutions LLP');
setCell($sh1, 'F5', 'Sangam Nishad');
// F6: replace TODAY() formula with plain date string
setCell($sh1, 'F6', date('d-M'));

// F12/G12: WCAG failing SC counts
setCell($sh1, 'F12', (string)count($failingSCsA), true);
setCell($sh1, 'G12', (string)count($failingSCsAA), true);

// K12:K16 = top 5 issue titles (K:L merged), M12:M16 = SC numbers
for ($ti = 0; $ti < 5; $ti++) {
    $rn   = 12 + $ti;
    $tiss = $topIssues[$ti] ?? ['title' => '', 'sc_nums' => ''];
    setCell($sh1, 'K' . $rn, $tiss['title']);
    setCell($sh1, 'M' . $rn, $tiss['sc_nums']);
}

// B15: "Users Affected" header, C15: "Issue counts" header
setCell($sh1, 'B15', 'Users Affected');
setCell($sh1, 'C15', 'Issue counts');

// B16 onwards: users affected + counts
$ui = 0;
foreach ($userAffectedCounts as $user => $count) {
    $rn = 16 + $ui;
    injectCell($sh1, $rn, 'B' . $rn, (string)$user, ' s="26"');
    injectCell($sh1, $rn, 'C' . $rn, (string)$count, ' s="27"', true);
    $ui++;
}

// O23: "Severity" header, P23: "Issue Count" header
setCell($sh1, 'O23', 'Severity');
setCell($sh1, 'P23', 'Issue Count');

// O24 onwards: severity name + count
for ($si = 0; $si < count($severityCountsSorted); $si++) {
    $rn = 24 + $si;
    injectCell($sh1, $rn, 'O' . $rn, $severityCountsSorted[$si]['severity'], ' s="108"');
    injectCell($sh1, $rn, 'P' . $rn, (string)$severityCountsSorted[$si]['count'], ' s="109"', true);
}

// B29 onwards: team members (row 28 = header "Resource Name"/"Resource Type" — leave as-is)
// Template has 4 pre-styled data rows (29-32): rows 29-31 use s=62/63, row 32 uses s=65/66 (bottom border).
// For overflow rows (33+) that don't exist in the template, use s=62/63 (standard data row style).
for ($mi = 0; $mi < count($teamMembers); $mi++) {
    $rn   = 29 + $mi;
    $role = $roleLabels[$teamMembers[$mi]['role']] ?? ucfirst(str_replace('_', ' ', $teamMembers[$mi]['role']));
    // s=62/63 are the correct data-row styles; injectCell preserves existing styles for rows 29-32
    injectCell($sh1, $rn, 'B' . $rn, $teamMembers[$mi]['full_name'], ' s="62"');
    injectCell($sh1, $rn, 'C' . $rn, $role, ' s="63"');
}

// Remove dimension element from sheet1 to prevent Excel repair dialog
$sh1 = preg_replace('/<dimension\s+ref="[^"]*"\s*\/>/s', '', $sh1);
sortRows($sh1);

$zip->addFromString('xl/worksheets/sheet1.xml', $sh1);
// Delete calcChain — stale chain prevents recalculation
$zip->deleteName('xl/calcChain.xml');

// Force full recalculation on open — Excel will recalculate ALL formulas
// across all sheets (including Conformance Score) when the file is opened
$wb = $zip->getFromName('xl/workbook.xml');
if ($wb !== false) {
    $wb = preg_replace('/<calcPr\b[^\/]*\/>/i', '<calcPr calcId="191029" calcMode="auto" fullCalcOnLoad="1"/>', $wb);
    if (strpos($wb, 'fullCalcOnLoad') === false) {
        $wb = str_replace('</workbook>', '<calcPr calcId="191029" calcMode="auto" fullCalcOnLoad="1"/></workbook>', $wb);
    }
    $zip->addFromString('xl/workbook.xml', $wb);
}

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 2: URL Details — A=Page Number, B=Page Name, C=Unique URL, D=Grouped URLs
// ══════════════════════════════════════════════════════════════════════════════
$sh2 = $zip->getFromName('xl/worksheets/sheet2.xml');
clearDataRows($sh2);
setCell($sh2, 'A1', 'Page Number');

$rows2 = '';
foreach ($projectPages as $idx => $page) {
    $rn = $idx + 2;
    $guPerPageStmt->execute([$projectId, $page['id']]);
    $grouped = $guPerPageStmt->fetchAll(PDO::FETCH_COLUMN);
    $rows2 .= '<row r="' . $rn . '" spans="1:4">'
        . xcell('A', $rn, $page['page_number'] ?? '')
        . xcell('B', $rn, $page['page_name'] ?? '')
        . xcell('C', $rn, $page['url'] ?? '')
        . xcell('D', $rn, implode(', ', $grouped))
        . '</row>';
}
injectRows($sh2, $rows2);
sortRows($sh2);
$zip->addFromString('xl/worksheets/sheet2.xml', $sh2);

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 3: All URLs — A=URL
// ══════════════════════════════════════════════════════════════════════════════
$sh3 = $zip->getFromName('xl/worksheets/sheet3.xml');
clearDataRows($sh3);

$rows3 = '';
foreach ($allGroupedUrls as $idx => $url) {
    $rn = $idx + 2;
    $rows3 .= '<row r="' . $rn . '" spans="1:1">' . xcell('A', $rn, (string)$url) . '</row>';
}
injectRows($sh3, $rows3);
sortRows($sh3);
$zip->addFromString('xl/worksheets/sheet3.xml', $sh3);

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 4: Final Report
// ══════════════════════════════════════════════════════════════════════════════
// SHEET 4: Final Report — built from scratch (bypass corrupt live template)
// ══════════════════════════════════════════════════════════════════════════════

// We need to rewrite the header row specifically to include Regression columns
$rrStmt = $db->prepare("SELECT id, round_number FROM regression_rounds WHERE project_id = ? ORDER BY round_number ASC");
$rrStmt->execute([$projectId]);
$regressionRounds = $rrStmt->fetchAll(PDO::FETCH_ASSOC);

// Map base headers
$baseHeaders = [
    'Sr.No', 'Issue Key', 'Page No', 'Page Name', 'Page URL', 'Issue Title',
    'Actual Result', 'Incorrect Code', 'Screenshots', 'Recommendation', 'Correct Code',
    'Severity', 'Priority', 'Responsibility', 'User Affected', 'WCAG SC Number', 'WCAG SC Name',
    'WCAG Level', 'GIGW 3.0', 'IS 17802', 'Environments', 'Developer\'s Status', 'Developer\'s Comment'
];

foreach ($regressionRounds as $rr) {
    $rnum = (int)$rr['round_number'];
    $baseHeaders[] = "Regression Round {$rnum} Status";
    $baseHeaders[] = "Regression Round {$rnum} Comment";
}

// Build header row
$headerRow = '<row r="1" spans="1:' . count($baseHeaders) . '">';
foreach ($baseHeaders as $i => $hText) {
    $headerRow .= xcell(numToCol($i), 1, $hText, '2');
}
$headerRow .= '</row>';

// Build completely fresh sheet4.xml - no dependency on template at all
// (live template has corrupt cols/sheetViews content)
$sh4 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/></sheetView></sheetViews>'
    . '<sheetData>'
    . $headerRow
    . '</sheetData>'
    . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0" footer="0"/>'
    . '<pageSetup orientation="landscape"/>'
    . '</worksheet>';

$rows4 = '';
$srNo  = 1;

// Prepare regression issue snapshots statement
$rrivStmt = $db->prepare("SELECT latest_payload FROM regression_round_issue_versions WHERE project_id = ? AND round_id = ? AND issue_id = ?");

// Prepare regression comments statement
// We fetch only normal or regression comments, we will take the latest one added during the round?
// Or we just get all comments with comment_type='regression'
$commentStmt = $db->prepare("SELECT comment_html FROM issue_comments WHERE issue_id = ? AND comment_type = 'regression' AND created_at >= (SELECT started_at FROM regression_rounds WHERE id = ?) AND (created_at <= (SELECT ended_at FROM regression_rounds WHERE id = ?) OR (SELECT ended_at FROM regression_rounds WHERE id = ?) IS NULL) ORDER BY created_at DESC LIMIT 1");

foreach ($filteredIssues as $iss) {
    $iid   = (int)$iss['id'];
    $rn    = $srNo + 1;
    $meta  = $metaMap[$iid] ?? [];
    $pages = $pagesByIssue[$iid] ?? [];

    $pageNums  = implode(', ', array_column($pages, 'page_number'));
    $pageNames = implode(', ', array_column($pages, 'page_name'));

    // Final Report Page URL must prefer grouped URLs; fallback to the unique page URL.
    $pageUrlValues = [];
    foreach ($pages as $page) {
        $pageId = (int)($page['page_id'] ?? $page['id'] ?? 0);
        $groupedForPage = [];
        if ($pageId > 0) {
            $guPerPageStmt->execute([$projectId, $pageId]);
            $groupedForPage = $guPerPageStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (!empty($groupedForPage)) {
            foreach ($groupedForPage as $gurl) {
                $gurl = trim((string)$gurl);
                if ($gurl !== '') {
                    $pageUrlValues[] = $gurl;
                }
            }
            continue;
        }

        $fallbackUrl = trim((string)($page['url'] ?? ''));
        if ($fallbackUrl !== '') {
            $pageUrlValues[] = $fallbackUrl;
        }
    }
    $pageUrls = implode(', ', array_values(array_unique($pageUrlValues)));

    $wcagNums  = implode(', ', metaArray($meta, 'wcagsuccesscriteria'));
    $wcagNames = implode(', ', metaArray($meta, 'wcagsuccesscriterianame'));
    $wcagLevel = implode(', ', metaArray($meta, 'wcagsuccesscriterialevel'));
    $gigw      = implode(', ', metaArray($meta, 'gigw30'));
    $is17802   = implode(', ', metaArray($meta, 'is17802'));
    $severity  = ucfirst(strtolower(metaFirst($meta, 'severity') ?: ($iss['severity'] ?? 'minor')));
    $priority  = ucfirst(strtolower(metaFirst($meta, 'priority') ?: 'medium'));
    $responsibility = implode(', ', metaArray($meta, 'responsibility'));
    $envs      = implode(', ', metaArray($meta, 'environments'));
    $issueKey  = $iss['issue_key'] ?? metaFirst($meta, 'issue_key');

    // Users affected: split on comma since multiple users may be in one row
    $usersArr = [];
    foreach ($meta['usersaffected'] ?? [] as $rawVal) {
        foreach (array_filter(array_map('trim', explode(',', $rawVal))) as $u) {
            $usersArr[] = $u;
        }
    }
    $usersAff = implode(', ', array_unique($usersArr));

    // Extract named sections from description
    $sections       = extractSections($iss['description'] ?? '');
    $actualResult   = stripHtml($sections['actual_result']);
    $incorrectCode  = stripHtml($sections['incorrect_code']);
    $recommendation = stripHtml($sections['recommendation']);
    $correctCode    = stripHtml($sections['correct_code']);
    // Fallback: if no sections found, put full description in Actual Result
    if ($actualResult === '' && $incorrectCode === '' && $recommendation === '' && $correctCode === '') {
        $actualResult = stripHtml($iss['description'] ?? '');
    }
    $screenshotUrls = implode("\n", array_map('absoluteUrl', extractImageUrls($sections['screenshot'])));

    $totalCols = count($baseHeaders);
    $rows4 .= '<row r="' . $rn . '" spans="1:' . $totalCols . '">'
        . '<c r="A' . $rn . '"><v>' . $srNo . '</v></c>'
        . xcell('B', $rn, $issueKey)
        . xcell('C', $rn, $pageNums)
        . xcell('D', $rn, $pageNames)
        . xcell('E', $rn, $pageUrls)
        . xcell('F', $rn, $iss['title'] ?? '')
        . xcell('G', $rn, $actualResult)
        . xcell('H', $rn, $incorrectCode)
        . xcell('I', $rn, $screenshotUrls)
        . xcell('J', $rn, $recommendation)
        . xcell('K', $rn, $correctCode)
        . xcell('L', $rn, $severity)
        . xcell('M', $rn, $priority)
        . xcell('N', $rn, $responsibility)
        . xcell('O', $rn, $usersAff)
        . xcell('P', $rn, $wcagNums)
        . xcell('Q', $rn, $wcagNames)
        . xcell('R', $rn, $wcagLevel)
        . xcell('S', $rn, $gigw)
        . xcell('T', $rn, $is17802)
        . xcell('U', $rn, $envs)
        . xcell('V', $rn, '')
        . xcell('W', $rn, '');
        
    $colIdx = 23; // X is col index 23
    foreach ($regressionRounds as $rr) {
        $roundId = (int)$rr['id'];
        
        // Find issue status in this round
        $rrivStmt->execute([$projectId, $roundId, $iid]);
        $versionPayload = $rrivStmt->fetchColumn();
        $statusStr = '';
        if ($versionPayload) {
            $vp = json_decode($versionPayload, true);
            if ($vp && isset($vp['issue']['status_id'])) {
                $stId = (int)$vp['issue']['status_id'];
                $snameStmt = $db->prepare("SELECT name FROM issue_statuses WHERE id = ?");
                $snameStmt->execute([$stId]);
                $statusStr = (string)$snameStmt->fetchColumn();
            }
        }
        
        // Find comment for this round
        $commentStmt->execute([$iid, $roundId, $roundId, $roundId]);
        $commHtml = $commentStmt->fetchColumn();
        $commStr = $commHtml ? stripHtml($commHtml) : '';
        
        $rows4 .= xcell(numToCol($colIdx++), $rn, $statusStr);
        $rows4 .= xcell(numToCol($colIdx++), $rn, $commStr);
    }
    
    $rows4 .= '</row>';
    $srNo++;
}
injectRows($sh4, $rows4);
// Sheet4 rows are already in sorted order (r=1 header, r=2..N data in sequence)
// sortRows is not needed and can corrupt XML on large datasets due to string parsing
// sortRows($sh4);

$zip->addFromString('xl/worksheets/sheet4.xml', $sh4);

// ── stream ────────────────────────────────────────────────────────────────────
$zip->close();

// Validate all modified sheets before streaming
$validateZip = new ZipArchive();
$validateZip->open($tmpFile);
$sheetNames = ['xl/worksheets/sheet1.xml','xl/worksheets/sheet2.xml','xl/worksheets/sheet3.xml','xl/worksheets/sheet4.xml'];
foreach ($sheetNames as $sn) {
    $content = $validateZip->getFromName($sn);
    if ($content === false) continue;
    libxml_use_internal_errors(true);
    simplexml_load_string($content);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();
    if (!empty($xmlErrors)) {
        // Log first error to PHP error log for debugging
        error_log("export_client_report: XML error in $sn: " . trim($xmlErrors[0]->message) . " at line " . $xmlErrors[0]->line);
    }
}
$validateZip->close();
$safeTitle = trim(preg_replace('/[^a-zA-Z0-9_\- ]/', '', $project['title'] ?? 'Project')) ?: 'Project';
$filename  = $safeTitle . ' - Accessibility Audit Report.xlsx';

// Ensure any accidental output from dependencies or logic is wiped before we send binary data.
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
// Use both filename and filename* for maximum browser compatibility (RFC 6266)
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($tmpFile);
if (file_exists($tmpFile)) {
    unlink($tmpFile);
}
exit;
