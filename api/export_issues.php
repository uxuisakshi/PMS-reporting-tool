<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'admin']);

// CSRF protection for state-changing POST export
enforceApiCsrf();

$projectId = (int)($_POST['project_id'] ?? 0);
$format = $_POST['format'] ?? 'excel';
$selectedColumns = $_POST['columns'] ?? [];
$selectedPages = $_POST['pages'] ?? ['all'];
$selectedStatuses = $_POST['status'] ?? ['all'];
$imageHandling = $_POST['image_handling'] ?? 'links'; // links, embed, none
$includeRegressionComments = isset($_POST['include_regression_comments']) && $_POST['include_regression_comments'] == '1';

if (!$projectId || empty($selectedColumns)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$db = Database::getInstance();

// IDOR fix: verify user has access to this project
if (!hasProjectAccess($db, $_SESSION['user_id'], $projectId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Get project details
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Project not found']);
    exit;
}

// Build query to fetch issues
$query = "
    SELECT 
        i.id,
        i.issue_key,
        i.title,
        i.description,
        i.status_id,
        ist.name as status,
        i.created_at,
        i.updated_at,
        i.reporter_id,
        reporter.full_name as reporter_name,
        GROUP_CONCAT(DISTINCT pp.page_name ORDER BY pp.page_name SEPARATOR ', ') as pages,
        GROUP_CONCAT(DISTINCT pp.page_number ORDER BY pp.page_number SEPARATOR ', ') as page_numbers
    FROM issues i
    LEFT JOIN issue_statuses ist ON i.status_id = ist.id
    LEFT JOIN issue_pages ip ON i.id = ip.issue_id
    LEFT JOIN project_pages pp ON ip.page_id = pp.id
    LEFT JOIN users reporter ON i.reporter_id = reporter.id
    WHERE i.project_id = ?
";

$params = [$projectId];

// Add page filter
if (!in_array('all', $selectedPages)) {
    $placeholders = str_repeat('?,', count($selectedPages) - 1) . '?';
    $query .= " AND ip.page_id IN ($placeholders)";
    $params = array_merge($params, $selectedPages);
}

// Add status filter
if (!in_array('all', $selectedStatuses)) {
    $statusMap = [
        'open' => 1,
        'in_progress' => 2,
        'resolved' => 3,
        'closed' => 4
    ];
    $statusIds = array_map(function($s) use ($statusMap) {
        return $statusMap[$s] ?? 1;
    }, $selectedStatuses);
    $placeholders = str_repeat('?,', count($statusIds) - 1) . '?';
    $query .= " AND i.status_id IN ($placeholders)";
    $params = array_merge($params, $statusIds);
}

$query .= " GROUP BY i.id ORDER BY i.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch metadata for each issue
foreach ($issues as &$issue) {
    $metaStmt = $db->prepare("SELECT meta_key, meta_value FROM issue_metadata WHERE issue_id = ?");
    $metaStmt->execute([$issue['id']]);
    $metadata = $metaStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $issue['metadata'] = $metadata;
    
    // If pages are empty from query, try to get from metadata
    if (empty($issue['pages']) && isset($metadata['page_ids'])) {
        $pageIds = json_decode($metadata['page_ids'], true);
        if (!is_array($pageIds)) {
            $pageIds = array_filter(array_map('trim', explode(',', $metadata['page_ids'])));
        }
        $pageIds = array_values(array_filter(array_map('intval', $pageIds)));
        
        if (!empty($pageIds)) {
            try {
                $placeholders = str_repeat('?,', count($pageIds) - 1) . '?';
                $pageStmt = $db->prepare("SELECT page_name, page_number FROM project_pages WHERE id IN ($placeholders)");
                $pageStmt->execute($pageIds);
                $pageData = $pageStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $pageNames = array_column($pageData, 'page_name');
                $pageNumbers = array_column($pageData, 'page_number');
                
                $issue['pages'] = implode(', ', $pageNames);
                $issue['page_numbers'] = implode(', ', $pageNumbers);
            } catch (Exception $e) {
                // Silently handle error
            }
        }
    }
    
    // Fetch grouped URLs from metadata
    $groupedUrlsList = [];
    if (isset($metadata['grouped_urls'])) {
        $urlData = $metadata['grouped_urls'];
        
        // Check if it's already a URL string (not IDs)
        if (filter_var($urlData, FILTER_VALIDATE_URL) || strpos($urlData, 'http') === 0) {
            // It's a direct URL or comma-separated URLs
            $groupedUrlsList = array_filter(array_map('trim', explode(',', $urlData)));
        } else {
            // Try to decode as JSON (IDs)
            $urlIds = json_decode($urlData, true);
            
            // If not valid JSON, try comma-separated
            if (!is_array($urlIds)) {
                $urlIds = array_filter(array_map('trim', explode(',', $urlData)));
            }
            
            // Convert string IDs to integers and filter out empty values
            $urlIds = array_values(array_filter(array_map('intval', $urlIds)));
            
            if (!empty($urlIds)) {
                try {
                    $placeholders = str_repeat('?,', count($urlIds) - 1) . '?';
                    $urlStmt = $db->prepare("SELECT url FROM grouped_urls WHERE id IN ($placeholders)");
                    $urlStmt->execute($urlIds);
                    $groupedUrlsList = $urlStmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {
                    // Silently handle error
                }
            }
        }
    }
    $issue['grouped_urls'] = !empty($groupedUrlsList) ? implode(', ', $groupedUrlsList) : '';
    
    // Get common issue title from metadata
    $issue['common_title'] = $metadata['common_title'] ?? '';
    
    // Fetch all reporters (primary + additional from metadata)
    $allReporters = [];
    
    // Add primary reporter if exists
    if (!empty($issue['reporter_name'])) {
        $allReporters[] = $issue['reporter_name'];
    }
    
    // Add additional reporters from metadata if exists
    if (isset($metadata['reporter_ids'])) {
        $reporterIds = json_decode($metadata['reporter_ids'], true);
        if (!is_array($reporterIds)) {
            // Try comma-separated format
            $reporterIds = array_filter(array_map('trim', explode(',', $metadata['reporter_ids'])));
        }
        
        if (is_array($reporterIds) && !empty($reporterIds)) {
            $placeholders = str_repeat('?,', count($reporterIds) - 1) . '?';
            $reporterStmt = $db->prepare("SELECT full_name FROM users WHERE id IN ($placeholders)");
            $reporterStmt->execute($reporterIds);
            $additionalReporters = $reporterStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($additionalReporters as $reporter) {
                if (!in_array($reporter, $allReporters)) {
                    $allReporters[] = $reporter;
                }
            }
        }
    }
    
    // Combine all reporters
    $issue['all_reporters'] = implode(', ', array_filter($allReporters));
    
    // Fetch QA status from metadata
    $qaStatusLabels = [];
    if (isset($metadata['qa_status'])) {
        $qaStatusKeys = json_decode($metadata['qa_status'], true);
        if (!is_array($qaStatusKeys)) {
            // Try comma-separated format
            $qaStatusKeys = array_filter(array_map('trim', explode(',', $metadata['qa_status'])));
        }
        
        if (is_array($qaStatusKeys) && !empty($qaStatusKeys)) {
            $placeholders = str_repeat('?,', count($qaStatusKeys) - 1) . '?';
            $qaStmt = $db->prepare("SELECT status_label FROM qa_status_master WHERE status_key IN ($placeholders)");
            $qaStmt->execute($qaStatusKeys);
            $qaStatusLabels = $qaStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    $issue['qa_status'] = implode(', ', $qaStatusLabels);
    
    // Extract template sections from description field
    $issue['sections'] = extractTemplateSections($issue['description'] ?? '');
    
    // Fetch regression comments if column is selected
    $issue['regression_comments'] = '';
    if (in_array('regression_comments', $selectedColumns)) {
        $commentsStmt = $db->prepare("
            SELECT 
                ic.comment_html,
                ic.created_at,
                u.full_name as commenter_name
            FROM issue_comments ic
            LEFT JOIN users u ON ic.user_id = u.id
            WHERE ic.issue_id = ? AND ic.comment_type = 'regression'
            ORDER BY ic.created_at ASC
        ");
        $commentsStmt->execute([$issue['id']]);
        $regressionComments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($regressionComments)) {
            $commentsList = [];
            foreach ($regressionComments as $comment) {
                $commenterName = $comment['commenter_name'] ?? 'Unknown';
                $commentDate = date('Y-m-d H:i', strtotime($comment['created_at']));
                $commentText = strip_tags($comment['comment_html']);
                $commentsList[] = "[$commentDate] $commenterName: $commentText";
            }
            $issue['regression_comments'] = implode("\n\n", $commentsList);
        }
    }
    
    // Extract image alt texts if column is selected
    $issue['image_alt_texts'] = '';
    if (in_array('image_alt_texts', $selectedColumns)) {
        $altTexts = extractImageAltTexts($issue['description'] ?? '');
        if (!empty($altTexts)) {
            $issue['image_alt_texts'] = implode("\n", $altTexts);
        }
    }
}

if ($format === 'excel') {
    exportToExcel($issues, $selectedColumns, $project, $imageHandling, $includeRegressionComments);
} else {
    exportToPDF($issues, $selectedColumns, $project, $imageHandling, $includeRegressionComments);
}

function extractImageAltTexts($html) {
    $altTexts = [];
    
    if (empty($html)) {
        return $altTexts;
    }
    
    // Use DOMDocument to parse HTML and extract alt attributes
    $dom = new DOMDocument();
    // Suppress warnings for malformed HTML
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    
    $images = $dom->getElementsByTagName('img');
    
    foreach ($images as $img) {
        $alt = $img->getAttribute('alt');
        if (!empty($alt)) {
            $altTexts[] = $alt;
        }
    }
    
    return $altTexts;
}

function extractTemplateSections($html) {
    $sections = [];
    
    // Common section patterns to look for
    $sectionPatterns = [
        'actual_result' => '/\[Actual Result\](.*?)(?=\[|$)/is',
        'incorrect_code' => '/\[Incorrect Code\](.*?)(?=\[|$)/is',
        'screenshot' => '/\[Screenshot\](.*?)(?=\[|$)/is',
        'recommendation' => '/\[Recommendation\](.*?)(?=\[|$)/is',
        'correct_code' => '/\[Correct Code\](.*?)(?=\[|$)/is',
        'expected_result' => '/\[Expected Result\](.*?)(?=\[|$)/is',
        'steps_to_reproduce' => '/\[Steps to Reproduce\](.*?)(?=\[|$)/is',
        'impact' => '/\[Impact\](.*?)(?=\[|$)/is',
        'notes' => '/\[Notes\](.*?)(?=\[|$)/is',
    ];
    
    foreach ($sectionPatterns as $key => $pattern) {
        if (preg_match($pattern, $html, $matches)) {
            // Extract content - keep HTML for now (will be processed later)
            $content = cleanExtractedSectionContent($matches[1] ?? '');
            $sections[$key] = $content;
        } else {
            $sections[$key] = '';
        }
    }
    
    return $sections;
}

function cleanExtractedSectionContent($content) {
    $content = (string)$content;
    if ($content === '') {
        return '';
    }

    $content = trim($content);
    if ($content === '') {
        return '';
    }

    // Remove leading/trailing empty block wrappers and break tags that appear
    // after section markers like [Actual Result] in rich-text HTML.
    $edgePattern = '/^(?:\s|&nbsp;|<br\s*\/?>|<\/?(?:p|div|li|ul|ol|h[1-6]|pre|blockquote)[^>]*>)+|(?:\s|&nbsp;|<br\s*\/?>|<\/?(?:p|div|li|ul|ol|h[1-6]|pre|blockquote)[^>]*>)+$/i';
    $cleaned = preg_replace($edgePattern, '', $content);
    if ($cleaned !== null) {
        $content = $cleaned;
    }

    return trim($content);
}

function exportToExcel($issues, $columns, $project, $imageHandling, $includeRegressionComments) {
    // Use HTML-based XLS export to preserve rich text formatting.
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="issues_' . sanitizeFilename($project['title']) . '_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        th, td { border: 1px solid #d0d7de; padding: 8px; vertical-align: top; word-wrap: break-word; }
        th { background: #f2f5f9; font-weight: bold; }
        td.same-cell { white-space: pre-wrap; mso-data-placement: same-cell; }
        br { mso-data-placement: same-cell; }
        th.description-col, td.description-col { width: 420px; min-width: 420px; }
        .rich { margin: 0; padding: 0; }
        .rich > *:first-child { margin-top: 0 !important; padding-top: 0 !important; }
        .rich p { margin: 0 0 8px 0; }
        .rich ul, .rich ol { margin: 0 0 8px 20px; padding: 0; }
        .rich pre { white-space: pre-wrap; word-break: break-word; background: #f6f8fa; border: 1px solid #d8dee4; padding: 8px; border-radius: 4px; }
        .rich code { font-family: Consolas, monospace; background: #f6f8fa; padding: 1px 3px; border-radius: 3px; }
        .rich img { max-width: 420px; height: auto; border: 1px solid #ddd; }
        .rich * { max-width: 100%; }
    </style>
</head>
<body>';

    echo '<h2>Issues Export - ' . htmlspecialchars($project['title']) . '</h2>';
    echo '<p><strong>Export Date:</strong> ' . date('Y-m-d H:i:s') . ' | <strong>Total Issues:</strong> ' . count($issues) . '</p>';

    echo '<table><thead><tr>';
    foreach ($columns as $col) {
        $thClass = $col === 'description' ? ' class="description-col"' : '';
        if (strpos($col, 'section_') === 0) {
            $sectionName = str_replace('section_', '', $col);
            $sectionName = str_replace('_', ' ', $sectionName);
            echo '<th' . $thClass . '>' . htmlspecialchars('[' . ucwords($sectionName) . ']') . '</th>';
        } else {
            echo '<th' . $thClass . '>' . htmlspecialchars(ucwords(str_replace('_', ' ', $col))) . '</th>';
        }
    }
    echo '</tr></thead><tbody>';

    foreach ($issues as $issue) {
        echo '<tr>';
        foreach ($columns as $col) {
            $tdClass = $col === 'description' ? 'same-cell description-col' : 'same-cell';
            echo '<td class="' . $tdClass . '">';

            if ($col === 'description') {
                $descriptionHtml = renderRichContentForExport($issue[$col] ?? '', $imageHandling, 'excel');
                $descriptionHtml = addSectionGapToDescriptionExcel($descriptionHtml);
                echo '<div class="rich">' . $descriptionHtml . '</div>';
            } elseif ($col === 'common_title') {
                echo formatExcelTextCell($issue['common_title'] ?? '');
            } elseif ($col === 'reporter_name') {
                echo formatExcelTextCell($issue['all_reporters'] ?? '');
            } elseif (strpos($col, 'section_') === 0) {
                $sectionKey = str_replace('section_', '', $col);
                $sectionContent = $issue['sections'][$sectionKey] ?? '';
                $renderedSection = renderRichContentForExport($sectionContent, $imageHandling, 'excel');
                $renderedSection = removeFirstLineFromExcelHtml($renderedSection);
                echo '<div class="rich">' . $renderedSection . '</div>';
            } elseif ($col === 'regression_comments') {
                echo formatExcelTextCell($issue['regression_comments'] ?? '');
            } elseif ($col === 'image_alt_texts') {
                echo formatExcelTextCell($issue['image_alt_texts'] ?? '');
            } elseif ($col === 'grouped_urls') {
                $urlsString = $issue['grouped_urls'] ?? $issue[$col] ?? '';
                $urls = array_filter(array_map('trim', explode(',', $urlsString)));
                if (!empty($urls)) {
                    $lines = array_map(function($url) {
                        return '&#8226; ' . htmlspecialchars($url);
                    }, $urls);
                    echo '<div class="rich">' . implode('<br>', $lines) . '</div>';
                }
            } elseif (isset($issue['metadata'][$col])) {
                $value = $issue['metadata'][$col];
                echo formatExcelTextCell(is_array($value) ? implode(', ', $value) : (string)$value);
            } else {
                echo formatExcelTextCell($issue[$col] ?? '');
            }

            echo '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

function exportToPDF($issues, $columns, $project, $imageHandling, $includeRegressionComments) {
    header('Content-Type: text/html; charset=utf-8');
    $pdfTemplate = loadPdfTemplateConfig();
    $customEnabled = !empty($pdfTemplate['enabled']);
    $customCss = $customEnabled ? sanitizeTemplateCss((string)($pdfTemplate['custom_css'] ?? '')) : '';
    $customHeaderHtml = $customEnabled ? sanitizeTemplateHtml((string)($pdfTemplate['header_html'] ?? '')) : '';
    $customFooterHtml = $customEnabled ? sanitizeTemplateHtml((string)($pdfTemplate['footer_html'] ?? '')) : '';
    $logoUrl = $customEnabled ? resolveTemplateLogoUrl((string)($pdfTemplate['logo_url'] ?? '')) : '';
    $logoAlt = $customEnabled ? trim((string)($pdfTemplate['logo_alt'] ?? '')) : '';
    $showDefaultHeader = $customEnabled ? !empty($pdfTemplate['show_default_header']) : true;
    $showExportDate = $customEnabled ? !empty($pdfTemplate['show_export_date']) : true;
    $showTotalIssues = $customEnabled ? !empty($pdfTemplate['show_total_issues']) : true;
    $headerTitleRaw = $customEnabled ? trim((string)($pdfTemplate['header_title'] ?? '')) : '';
    $headerTitle = htmlspecialchars($headerTitleRaw !== '' ? $headerTitleRaw : ('Issues Export - ' . ($project['title'] ?? '')), ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Issues Export - ' . htmlspecialchars($project['title']) . '</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: 13px;
            margin: 15px;
            line-height: 1.45;
            max-width: 100%;
        }
        .header {
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 15px;
            margin-bottom: 30px;
            max-width: 100%;
        }
        .header h1 {
            color: #333;
            margin: 0;
            font-size: 26px;
            word-wrap: break-word;
        }
        .meta-info {
            color: #666;
            font-size: 12px;
            margin-top: 10px;
        }
        .issue-container {
            margin-bottom: 18px;
            page-break-inside: avoid;
            border: 1px solid #ddd;
            padding: 12px;
            border-radius: 5px;
            background: #f8f9fa;
            max-width: 100%;
            overflow: hidden;
        }
        .issue-title {
            font-size: 20px;
            font-weight: bold;
            color: #0d6efd;
            margin-bottom: 10px;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 6px;
            word-wrap: break-word;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin: 6px 0 10px;
        }
        .meta-item {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #0d6efd;
            border-radius: 4px;
            padding: 8px;
        }
        .meta-label {
            display: block;
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 2px;
            font-weight: 600;
        }
        .meta-value {
            display: block;
            font-size: 12px;
            line-height: 1.35;
            word-break: break-word;
        }
        .section-container {
            margin-top: 8px;
            padding: 10px;
            background: white;
            border-left: 4px solid #0d6efd;
            max-width: 100%;
            overflow: hidden;
        }
        .section-title {
            font-weight: bold;
            color: #0d6efd;
            font-size: 14px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .section-content {
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
            line-height: 1.45;
        }
        .section-content p { margin: 0 0 8px 0; }
        .section-content ul, .section-content ol { margin: 0 0 8px 20px; }
        .section-content pre {
            white-space: pre-wrap;
            word-break: break-word;
            background: #f6f8fa;
            border: 1px solid #d8dee4;
            border-radius: 4px;
            padding: 8px;
            margin: 8px 0;
            font-family: Consolas, monospace;
        }
        .section-content code {
            background: #f6f8fa;
            border-radius: 3px;
            padding: 1px 3px;
            font-family: Consolas, monospace;
        }
        .section-content * { max-width: 100%; }
        .url-list {
            list-style: none;
            padding-left: 0;
            margin: 5px 0;
        }
        .url-list li {
            padding: 2px 0;
            color: #0d6efd;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .custom-template-header {
            border: 1px solid #d9e2ef;
            background: #ffffff;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }
        .custom-template-header .logo-wrap img {
            max-height: 56px;
            width: auto;
            display: inline-block;
            margin-bottom: 8px;
        }
        .custom-template-footer {
            margin-top: 12px;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            font-size: 11px;
            color: #6b7280;
        }
        ' . $customCss . '
        @media print {
            body { margin: 10mm; }
            button { display: none; }
            .issue-container { page-break-inside: avoid; }
        }
        @page {
            margin: 15mm;
            size: A4;
        }
    </style>
</head>
<body>
    <button onclick="window.print()" style="padding: 10px 20px; background: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer; margin-bottom: 20px;">
        Print / Save as PDF
    </button>
    ' . ($customEnabled ? '<div class="custom-template-header">' .
            ($logoUrl !== '' ? '<div class="logo-wrap"><img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($logoAlt !== '' ? $logoAlt : 'Template Logo', ENT_QUOTES, 'UTF-8') . '"></div>' : '') .
            $customHeaderHtml .
        '</div>' : '') . '

    <main role="main" aria-label="Issues export document">
    ' . ($showDefaultHeader ? '<header class="header" role="banner">
        <h1>' . $headerTitle . '</h1>
        ' . (($showExportDate || $showTotalIssues) ? '<div class="meta-info">' : '') . '
            ' . ($showExportDate ? '<strong>Export Date:</strong> ' . date('Y-m-d H:i:s') : '') . '
            ' . ($showExportDate && $showTotalIssues ? ' | ' : '') . '
            ' . ($showTotalIssues ? '<strong>Total Issues:</strong> ' . count($issues) : '') . '
        ' . (($showExportDate || $showTotalIssues) ? '</div>' : '') . '
    </header>' : '');

    $issueNumber = 1;
    foreach ($issues as $issue) {
        echo '<div class="issue-container">';
        echo '<h2 class="issue-title">Issue #' . $issueNumber . '</h2>';
        $metaBuffer = [];

        $flushMetaBuffer = function () use (&$metaBuffer) {
            if (empty($metaBuffer)) {
                return;
            }
            echo '<div class="meta-grid">';
            foreach ($metaBuffer as $item) {
                echo '<div class="meta-item">';
                echo '<span class="meta-label">' . htmlspecialchars($item['label']) . '</span>';
                echo '<span class="meta-value">' . nl2br(htmlspecialchars($item['value'])) . '</span>';
                echo '</div>';
            }
            echo '</div>';
            $metaBuffer = [];
        };

        foreach ($columns as $col) {
            $label = (strpos($col, 'section_') === 0)
                ? '[' . ucwords(str_replace('_', ' ', str_replace('section_', '', $col))) . ']'
                : ucwords(str_replace('_', ' ', $col));

            if ($col === 'description') {
                $html = renderRichContentForExport($issue['description'] ?? '', $imageHandling, 'pdf');
                if (trim((string)$html) !== '') {
                    $flushMetaBuffer();
                    echo '<div class="section-container"><div class="section-title">' . htmlspecialchars($label) . '</div><div class="section-content">' . $html . '</div></div>';
                }
                continue;
            }

            if (strpos($col, 'section_') === 0) {
                $sectionKey = str_replace('section_', '', $col);
                $html = renderRichContentForExport($issue['sections'][$sectionKey] ?? '', $imageHandling, 'pdf');
                if (trim((string)$html) !== '') {
                    $flushMetaBuffer();
                    echo '<div class="section-container"><div class="section-title">' . htmlspecialchars($label) . '</div><div class="section-content">' . $html . '</div></div>';
                }
                continue;
            }

            if ($col === 'grouped_urls') {
                $urlsString = $issue['grouped_urls'] ?? $issue[$col] ?? '';
                $urls = array_filter(array_map('trim', explode(',', (string)$urlsString)));
                if (!empty($urls)) {
                    $flushMetaBuffer();
                    echo '<div class="section-container"><div class="section-title">' . htmlspecialchars($label) . '</div><div class="section-content"><ul class="url-list">';
                    foreach ($urls as $url) {
                        echo '<li>' . htmlspecialchars($url) . '</li>';
                    }
                    echo '</ul></div></div>';
                }
                continue;
            }

            if ($col === 'regression_comments') {
                $value = $issue['regression_comments'] ?? '';
                if ($value !== '') {
                    $flushMetaBuffer();
                    echo '<div class="section-container"><div class="section-title">' . htmlspecialchars($label) . '</div><div class="section-content">' . nl2br(htmlspecialchars($value)) . '</div></div>';
                }
                continue;
            }

            if ($col === 'image_alt_texts') {
                $value = $issue['image_alt_texts'] ?? '';
                if ($value !== '') {
                    $flushMetaBuffer();
                    echo '<div class="section-container"><div class="section-title">' . htmlspecialchars($label) . '</div><div class="section-content">' . nl2br(htmlspecialchars($value)) . '</div></div>';
                }
                continue;
            }

            if ($col === 'common_title') {
                $value = $issue['common_title'] ?? '';
            } elseif ($col === 'reporter_name') {
                $value = $issue['all_reporters'] ?? '';
            } elseif ($col === 'title') {
                $value = $issue['title'] ?? '';
            } elseif (isset($issue['metadata'][$col])) {
                $metaValue = $issue['metadata'][$col];
                $value = is_array($metaValue) ? implode(', ', $metaValue) : $metaValue;
            } else {
                $value = $issue[$col] ?? '';
            }

            if ((string)$value !== '') {
                $metaBuffer[] = [
                    'label' => $label,
                    'value' => (string)$value
                ];
            }
        }
        $flushMetaBuffer();

        echo '</div>';
        $issueNumber++;
    }

    if ($customEnabled && $customFooterHtml !== '') {
        echo '<footer class="custom-template-footer" role="contentinfo">' . $customFooterHtml . '</footer>';
    }

    echo '</main></body></html>';
    exit;
}

function sanitizeFilename($filename) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
}

function formatExcelTextCell($value) {
    $text = (string)($value ?? '');
    $text = ltrim($text);
    $text = preg_replace("/^(?:\r\n|\r|\n)+/", '', $text);
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return preg_replace("/\r\n|\r|\n/", '<br>', $text);
}

function renderRichContentForExport($html, $imageHandling, $context = 'pdf') {
    if (trim((string)$html) === '') {
        return '';
    }

    $cleanHtml = sanitizeExportHtml($html);
    $cleanHtml = trimLeadingVisualGapHtml($cleanHtml);
    $cleanHtml = applyImageHandlingToHtml($cleanHtml, $imageHandling, $context);
    if ($context === 'excel') {
        // Prevent Excel from treating large blocks as one hyperlink when rich HTML contains anchors.
        $cleanHtml = convertExcelAnchorsToText($cleanHtml);
        $cleanHtml = normalizeExcelCellHtml($cleanHtml);
    }

    return $cleanHtml;
}

function trimLeadingVisualGapHtml($html) {
    $html = (string)$html;
    if ($html === '') return '';

    $html = preg_replace('/^[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{2060}\x{00A0}\s]+/u', '', $html);

    $patterns = [
        '/^(?:\s|&nbsp;|&#160;|<br\s*\/?>)+/i',
        '/^<o:p>\s*(?:&nbsp;|&#160;|\s|<br\s*\/?>)*<\/o:p>/i',
        '/^<(p|div|span|font|blockquote|pre)\b[^>]*>\s*(?:&nbsp;|&#160;|\s|<br\s*\/?>)*<\/\1>/i',
        '/^<\/(?:p|div|li|ul|ol|h[1-6]|pre|blockquote|span|font)>/i'
    ];

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($patterns as $pattern) {
            $newHtml = preg_replace($pattern, '', $html);
            if ($newHtml !== null && $newHtml !== $html) {
                $html = $newHtml;
                $changed = true;
            }
        }
    }

    return ltrim((string)$html);
}

function convertExcelAnchorsToText($html) {
    $html = (string)$html;
    if ($html === '') return '';

    return preg_replace_callback('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function ($m) {
        $href = trim((string)($m[1] ?? ''));
        $text = trim(strip_tags((string)($m[2] ?? '')));
        if ($text === '') {
            $text = $href;
        }
        if ($href !== '' && strcasecmp($text, $href) !== 0) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . ')';
        }
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }, $html);
}

function normalizeExcelCellHtml($html) {
    $html = (string)$html;
    if ($html === '') return '';

    // Remove leading invisible characters that can render as a blank first line in Excel.
    $html = preg_replace('/^[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{2060}\x{00A0}\s]+/u', '', $html);

    // Keep formatting tags but flatten block tags so content stays in one cell row.
    $html = preg_replace('/<\\s*br\\s*\\/?>/i', '<br>', $html);
    
    // Handle list items before stripping tags: insert bullet point and ensure it reflects as a new line
    // Use the HTML entity or a direct bullet character that Excel handles well in HTML imports.
    $html = preg_replace('/<li\\b[^>]*>/i', '&#8226; ', $html);
    $html = preg_replace('/<\\/li>/i', '<br>', $html);

    // Remove empty wrapper blocks that produce blank first line in Excel.
    $html = preg_replace('/<(p|div)\\b[^>]*>\\s*(?:&nbsp;|\\s|<br>)*<\\/\\1>/i', '', $html);
    // Remove leading empty inline wrappers.
    $html = preg_replace('/^(?:\\s|&nbsp;|<span\\b[^>]*>\\s*<\\/span>|<font\\b[^>]*>\\s*<\\/font>|<strong>\\s*<\\/strong>|<em>\\s*<\\/em>|<u>\\s*<\\/u>|<b>\\s*<\\/b>|<i>\\s*<\\/i>|<br>)+/i', '', $html);
    // Remove orphan leading closing tags before block normalization.
    $html = preg_replace('/^(?:\\s*<\\/(?:p|div|li|ul|ol|h[1-6]|pre|blockquote)>)+/i', '', $html);
    $html = preg_replace('/<\\/(p|div|ul|ol|h[1-6]|pre|blockquote)>/i', '<br>', $html);
    $html = preg_replace('/<(p|div|ul|ol|h[1-6]|pre|blockquote)\\b[^>]*>/i', '', $html);

    // Remove duplicate breaks and trim leading/trailing breaks.
    $html = preg_replace('/(?:<br>\\s*){3,}/i', '<br><br>', $html);
    $html = preg_replace('/^(?:\\s|&nbsp;|<br>)+/i', '', $html);
    $html = preg_replace('/(?:<br>\\s*)+$/i', '', $html);
    // Final safety trim after all conversions.
    $html = preg_replace('/^(?:\\s|&nbsp;|<br>)+/i', '', $html);
    return $html;
}

function removeFirstLineFromExcelHtml($html) {
    $html = (string)$html;
    if ($html === '') return '';

    $html = preg_replace('/^(?:\s|&nbsp;|<br>)+/i', '', $html);
    if ($html === null || $html === '') return '';

    if (preg_match('/<br>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        $after = substr($html, $pos + strlen($m[0][0]));
        if ($after === false) {
            return '';
        }
        $after = preg_replace('/^(?:\s|&nbsp;|<br>)+/i', '', $after);
        return (string)$after;
    }

    // Single-line section content: removing first line leaves empty value.
    return '';
}

function addSectionGapToDescriptionExcel($html) {
    $html = (string)$html;
    if ($html === '') return '';

    // Normalize line-break tags first.
    $html = preg_replace('/<\s*br\s*\/?>/i', '<br>', $html);

    // Force every section heading after the first to start on a new block
    // with controlled spacing, independent of existing mixed spacing/tags.
    $sectionIndex = 0;
    $html = preg_replace_callback('/\[[^\]]+\]/', function ($m) use (&$sectionIndex) {
        $sectionIndex++;
        if ($sectionIndex === 1) {
            return $m[0];
        }
        return '__PMS_SECTION_BREAK__' . $m[0];
    }, $html);

    // Keep exactly one break before subsequent section markers to avoid
    // double/triple blank lines in Excel.
    $html = preg_replace('/(?:\s|&nbsp;|<br>)*__PMS_SECTION_BREAK__/i', '<br>', $html);

    return (string)$html;
}

function sanitizeExportHtml($html) {
    // Drop dangerous blocks first.
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', (string)$html);
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', (string)$html);

    // Keep rich-text formatting tags used in issue descriptions.
    $allowed = '<p><br><strong><b><em><i><u><s><strike><sub><sup><mark><ul><ol><li><pre><code><blockquote><h1><h2><h3><h4><h5><h6><a><img><span><div><font>';
    $html = strip_tags($html, $allowed);

    // Remove inline event handlers and javascript: URLs.
    $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $html);
    $html = preg_replace("/\\son\\w+\\s*=\\s*'[^']*'/i", '', $html);
    $html = preg_replace("/\\s(href|src)\\s*=\\s*([\"'])\\s*javascript:[^\"']*\\2/i", '', $html);

    return $html;
}

function applyImageHandlingToHtml($html, $imageHandling, $context = 'pdf') {
    return preg_replace_callback("/<img\\b[^>]*src=[\"']([^\"']+)[\"'][^>]*>/i", function ($matches) use ($imageHandling, $context) {
        $src = toExportImageUrl($matches[1]);

        if ($imageHandling === 'none') {
            return '';
        }

        if ($imageHandling === 'links') {
            $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
            return '<p><a href="' . $safeSrc . '" target="_blank" rel="noopener noreferrer">' . $safeSrc . '</a></p>';
        }

        $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
        $imgStyle = $context === 'excel'
            ? 'max-width: 420px; height: auto; border: 1px solid #ddd;'
            : 'max-width: 100%; height: auto; border: 1px solid #ddd; padding: 4px; background: #fff;';

        return '<img src="' . $safeSrc . '" alt="Issue image" style="' . $imgStyle . '">';
    }, $html);
}

function toExportImageUrl($src) {
    $src = trim((string)$src);
    if ($src === '') return '';

    if (function_exists('build_public_image_url_from_src')) {
        $public = build_public_image_url_from_src($src);
        if (trim((string)$public) !== '') {
            $src = $public;
        }
    }
    return toAbsoluteUrl($src);
}

function toAbsoluteUrl($src) {
    $src = trim((string)$src);
    if ($src === '') {
        return '';
    }

    if (preg_match('/^(https?:)?\/\//i', $src) || strpos($src, 'data:') === 0) {
        return $src;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $protocol . '://' . $host . '/' . ltrim($src, '/');
}

function resolveTemplateLogoUrl($src) {
    $src = trim((string)$src);
    if ($src === '') {
        return '';
    }

    if (preg_match('/^(https?:)?\/\//i', $src) || strpos($src, 'data:') === 0) {
        return $src;
    }

    return rtrim(getBaseDir(), '/') . '/' . ltrim($src, '/');
}


function getPdfTemplateConfigPath() {
    return __DIR__ . '/../storage/pdf_export_template.json';
}

function loadPdfTemplateConfig() {
    $defaults = [
        'enabled' => false,
        'header_html' => '',
        'footer_html' => '',
        'custom_css' => '',
        'logo_url' => '',
        'logo_alt' => '',
        'show_default_header' => true,
        'show_export_date' => true,
        'show_total_issues' => true,
        'header_title' => ''
    ];

    $path = getPdfTemplateConfigPath();
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, $decoded);
}

function sanitizeTemplateHtml($html) {
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', (string)$html);
    $allowed = '<p><br><strong><b><em><i><u><s><span><div><small><h1><h2><h3><h4><h5><h6><ul><ol><li><a>';
    return strip_tags($html, $allowed);
}

function sanitizeTemplateCss($css) {
    $css = (string)$css;
    $css = str_replace(['</style>', '<style>'], '', $css);
    $css = preg_replace('/@import\s+url\s*\([^)]*\)\s*;?/i', '', $css);
    $css = preg_replace('/expression\s*\([^)]*\)/i', '', $css);
    return $css;
}
