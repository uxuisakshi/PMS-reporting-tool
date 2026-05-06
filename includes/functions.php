<?php
require_once __DIR__ . '/../config/database.php';

/**
 * ProjectManager class for managing projects
 */
class ProjectManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function getProjectById($id) {
        $stmt = $this->db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getAllProjects() {
        return $this->db->query("SELECT * FROM projects ORDER BY created_at DESC")->fetchAll();
    }
    
    public function getProjectsByStatus($status) {
        $stmt = $this->db->prepare("SELECT * FROM projects WHERE status = ? ORDER BY created_at DESC");
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }

    /**
     * Create a project with optional parent and auto-generated project_code.
     * Returns boolean success.
     */
    public function createProject($data) {
        // Expected keys: title, description, project_type, client_id, priority, created_by, project_lead_id (optional), total_hours (optional), project_code (optional), parent_project_id (optional)
        $db = $this->db;
        $title = $data['title'] ?? '';
        $description = $data['description'] ?? null;
        $project_type = $data['project_type'] ?? 'web';
        $client_id = isset($data['client_id']) ? intval($data['client_id']) : null;
        $priority = $data['priority'] ?? 'medium';
        $created_by = $data['created_by'] ?? null;
        $parent_id = isset($data['parent_project_id']) ? intval($data['parent_project_id']) : null;
        $project_lead_id = isset($data['project_lead_id']) && $data['project_lead_id'] !== '' ? intval($data['project_lead_id']) : null;
        $total_hours = isset($data['total_hours']) && $data['total_hours'] !== '' ? floatval($data['total_hours']) : null;

        // Determine project_code
        $project_code = null;
        // If admin provided project_code and it looks like a code, use it
        if (!empty($data['po_number'])) {
            $project_code = $data['po_number'];
        }

        try {
            // get client prefix
            $prefix = null;
            if ($client_id) {
                $stmt = $db->prepare("SELECT project_code_prefix, name FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $c = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($c) {
                    $prefix = $c['project_code_prefix'] ?: strtoupper(substr(preg_replace('/[^A-Za-z]/','', $c['name']),0,3));
                }
            }

            if ($parent_id) {
                // generate child code like PARENTcode + a/b/c
                $p = $this->getProjectById($parent_id);
                if (!$p) return false;
                $parentCode = $p['project_code'] ?: $p['po_number'];
                if (!$parentCode) return false;
                // find existing siblings and determine next letter
                $stmt = $db->prepare("SELECT project_code FROM projects WHERE parent_project_id = ? ORDER BY project_code");
                $stmt->execute([$parent_id]);
                $sibs = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $nextLetter = 'a';
                if ($sibs) {
                    // collect trailing letters
                    $letters = array_map(function($c) use ($parentCode){ return substr($c, strlen($parentCode)); }, $sibs);
                    // find next unused letter
                    $used = array_map('strtolower', $letters);
                    for ($i=0;$i<26;$i++) {
                        $ch = chr(97+$i);
                        if (!in_array($ch, $used)) { $nextLetter = $ch; break; }
                    }
                }
                $project_code = $parentCode . $nextLetter;
            } else {
                // top-level project: generate numeric sequence using prefix
                if (!$project_code) {
                    $prefix = $prefix ?: 'PRJ';
                    // Find existing project_codes starting with prefix and extract numbers
                    $stmt = $db->prepare("SELECT project_code FROM projects WHERE project_code LIKE ?");
                    $stmt->execute([$prefix . '%']);
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $max = 0;
                    foreach ($rows as $rc) {
                        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)/', $rc, $m)) {
                            $n = intval($m[1]); if ($n>$max) $max=$n;
                        }
                    }
                    $num = $max + 1;
                    $project_code = $prefix . $num;
                }
            }

            // Insert project; keep po_number set to project_code for compatibility
            $stmt = $db->prepare(
                "INSERT INTO projects (po_number, project_code, title, description, project_type, client_id, priority, project_lead_id, total_hours, created_by, parent_project_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $res = $stmt->execute([$project_code, $project_code, $title, $description, $project_type, $client_id, $priority, $project_lead_id, $total_hours, $created_by, $parent_id]);
            return $res;
        } catch (PDOException $e) {
            error_log('createProject error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get active status options for a given entity type
 * 
 * @param string $entityType Entity type (project, page, phase, test_result, qa_result)
 * @return array Array of status options
 */
function getStatusOptions($entityType) {
    $db = Database::getInstance();
    
    // For project status, use the new project_statuses table
    if ($entityType === 'project') {
        $stmt = $db->query("
            SELECT status_key, status_label, badge_color as color 
            FROM project_statuses 
            ORDER BY display_order, status_label
        ");
        return $stmt->fetchAll();
    }
    
    // For other entity types, check if status_options table exists
    try {
        $stmt = $db->prepare("
            SELECT status_key, status_label, color 
            FROM status_options 
            WHERE entity_type = ? AND is_active = TRUE 
            ORDER BY display_order, status_label
        ");
        $stmt->execute([$entityType]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table doesn't exist, return empty array
        return [];
    }
}

function ensureIssueStatusVisibilityColumns($db) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $columns = [];
        $stmt = $db->query("SHOW COLUMNS FROM issue_statuses");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = strtolower((string)($row['Field'] ?? ''));
        }

        if (!in_array('visible_to_client', $columns, true)) {
            $db->exec("ALTER TABLE issue_statuses ADD COLUMN visible_to_client TINYINT(1) NOT NULL DEFAULT 1 AFTER is_qa");
        }
        if (!in_array('visible_to_internal', $columns, true)) {
            $db->exec("ALTER TABLE issue_statuses ADD COLUMN visible_to_internal TINYINT(1) NOT NULL DEFAULT 1 AFTER visible_to_client");
        }
    } catch (Exception $e) {
        error_log('Failed to ensure issue status visibility columns: ' . $e->getMessage());
    }
}

function getIssueStatusesForRole($db, $role = '', array $columns = []) {
    ensureIssueStatusVisibilityColumns($db);

    $baseColumns = ['id', 'name', 'color', 'category', 'points', 'is_qa', 'visible_to_client', 'visible_to_internal'];
    $selectColumns = array_values(array_unique(array_merge($baseColumns, $columns)));
    $sql = 'SELECT ' . implode(', ', $selectColumns) . ' FROM issue_statuses';
    $params = [];
    $normalizedRole = strtolower(trim((string)$role));

    if ($normalizedRole === 'client') {
        $sql .= ' WHERE visible_to_client = 1';
    } elseif ($normalizedRole !== '') {
        $sql .= ' WHERE visible_to_internal = 1';
    }

    $sql .= ' ORDER BY name ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Sanitize chat HTML allowing only a small whitelist of tags and safe attributes.
 * Allows <a href>, <img src> (http/https only), <b>, <strong>, <i>, <em>, <u>, <br>, <p>, <ul>, <ol>, <li>
 * Uses DOM-based parsing when available for robust XSS prevention.
 */
function sanitize_chat_html($html) {
    if (trim($html) === '') return '';

    // --- Pass 1: Regex pre-filter (fast removal of obvious dangerous content) ---

    // Strip dangerous tags completely including their content
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|select|textarea|base|link|meta|applet|frame|frameset|layer|ilayer|bgsound|xml|svg|math)\b[^>]*>.*?</\1>#is', '', $html);
    // Strip self-closing dangerous tags (including svg, math)
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|select|textarea|base|link|meta|applet|frame|frameset|layer|ilayer|bgsound|xml|svg|math)\b[^>]*/?\>#is', '', $html);

    // Remove ALL event handler attributes (on*) - covers onerror, onload, onclick, etc.
    $html = preg_replace('/(\s+on[a-z][a-z0-9]*\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>\/]+)/i', '', $html);

    // Remove style attributes entirely (prevents CSS expression(), url(javascript:...) etc.)
    $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>\/]+)/i', '', $html);

    // Remove data: URIs from src/href (can carry JS payloads)
    $html = preg_replace_callback('/(src|href|action|formaction|xlink:href)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>\/]+)/i', function ($m) {
        $attr = $m[1];
        $val = trim($m[2], "'\"");
        // Block javascript:, vbscript:, data: URIs
        if (preg_match('#^\s*(javascript|vbscript|data)\s*:#i', $val)) {
            return '';
        }
        return $attr . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
    }, $html);

    // --- Pass 2: DOM-based sanitization (when ext/dom available) ---
    if (class_exists('DOMDocument')) {
        $allowedTags = [
            'a', 'img', 'b', 'strong', 'i', 'em', 'u', 'br', 'p',
            'ul', 'ol', 'li', 'span', 'code', 'pre', 'blockquote',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
        ];
        // Allowed attributes per tag (whitelist approach)
        $allowedAttrs = [
            'a'   => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'title', 'width', 'height'],
            'td'  => ['colspan', 'rowspan'],
            'th'  => ['colspan', 'rowspan'],
            '*'   => ['class'], // class allowed on all tags (no JS in class)
        ];

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // Wrap in utf-8 meta so DOMDocument handles encoding correctly
        $doc->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $toRemove = [];
        $xpath = new DOMXPath($doc);

        // Collect all elements
        foreach ($xpath->query('//*') as $node) {
            $tagName = strtolower($node->nodeName);
            if (!in_array($tagName, $allowedTags, true)) {
                // Replace disallowed tag with its text content (don't just remove — preserve text)
                $frag = $doc->createDocumentFragment();
                while ($node->firstChild) {
                    $frag->appendChild($node->firstChild);
                }
                $node->parentNode->replaceChild($frag, $node);
                continue;
            }

            // Remove disallowed attributes
            $attrsToRemove = [];
            foreach ($node->attributes as $attr) {
                $attrName = strtolower($attr->name);
                $allowed = $allowedAttrs[$tagName] ?? [];
                $allowedAll = $allowedAttrs['*'] ?? [];
                if (!in_array($attrName, $allowed, true) && !in_array($attrName, $allowedAll, true)) {
                    $attrsToRemove[] = $attr->name;
                }
                // Extra: block javascript/vbscript/data in any remaining attr value
                if (preg_match('#^\s*(javascript|vbscript|data)\s*:#i', $attr->value)) {
                    $attrsToRemove[] = $attr->name;
                }
            }
            foreach ($attrsToRemove as $a) {
                $node->removeAttribute($a);
            }

            // Force target="_blank" links to have rel="noopener noreferrer"
            if ($tagName === 'a') {
                $target = $node->getAttribute('target');
                if ($target === '_blank') {
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
                // Ensure external links always have rel set
                if (!$node->hasAttribute('rel')) {
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }

        // Extract body innerHTML
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            $result = '';
            foreach ($body->childNodes as $child) {
                $result .= $doc->saveHTML($child);
            }
            return $result;
        }
    }

    // --- Fallback: regex-only path (when DOM not available) ---
    // Sanitize href/src on <a> and <img> tags
    $html = preg_replace_callback('/<(a|img)\b([^>]*)>/i', function ($m) {
        $tag = strtolower($m[1]);
        $attrs = $m[2];
        // Keep only safe attributes
        $safeAttrs = '';
        if ($tag === 'a') {
            if (preg_match('/href\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $attrs, $hm)) {
                $val = trim($hm[1], "'\"");
                if (!preg_match('#^\s*(javascript|vbscript|data)\s*:#i', $val)) {
                    $safeAttrs .= ' href="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
                }
            }
            $safeAttrs .= ' rel="noopener noreferrer"';
        } elseif ($tag === 'img') {
            if (preg_match('/src\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $attrs, $sm)) {
                $val = trim($sm[1], "'\"");
                if (!preg_match('#^\s*(javascript|vbscript|data)\s*:#i', $val)) {
                    $safeAttrs .= ' src="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
                }
            }
            if (preg_match('/alt\s*=\s*("[^"]*"|\'[^\']*\')/i', $attrs, $am)) {
                $safeAttrs .= ' alt=' . $am[1];
            }
        }
        return '<' . $tag . $safeAttrs . '>';
    }, $html);

    // Strip any tags not in the allowed whitelist
    $allowed = '<a><img><b><strong><i><em><u><br><p><ul><ol><li><span><code><pre><blockquote><h1><h2><h3><h4><h5><h6><hr><table><thead><tbody><tr><th><td>';
    return strip_tags($html, $allowed);
}

if (!function_exists('ensureAvailabilityStatusMaster')) {
    function ensureAvailabilityStatusMaster($db) {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS availability_status_master (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    status_key VARCHAR(50) NOT NULL UNIQUE,
                    status_label VARCHAR(100) NOT NULL,
                    badge_color VARCHAR(30) NOT NULL DEFAULT 'secondary',
                    description TEXT NULL,
                    display_order INT NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_active_order (is_active, display_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $existingCount = (int)$db->query("SELECT COUNT(*) FROM availability_status_master")->fetchColumn();

            if ($existingCount === 0) {
                $seedRows = [
                    ['not_updated', 'Not Updated', 'secondary', 'No status update submitted yet', 0],
                    ['available', 'Available', 'success', 'Available for work', 10],
                    ['working', 'Working', 'primary', 'Actively working', 20],
                    ['busy', 'Busy / In Meeting', 'warning', 'Busy or in a meeting', 30],
                    ['on_leave', 'On Leave', 'danger', 'On planned leave', 40],
                    ['sick_leave', 'Sick Leave', 'danger', 'Out due to sickness', 50]
                ];

                $seedStmt = $db->prepare("
                    INSERT INTO availability_status_master
                        (status_key, status_label, badge_color, description, display_order, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                        status_key = status_key
                ");
                foreach ($seedRows as $row) {
                    $seedStmt->execute($row);
                }
            } else {
                // Keep core fallback status present without re-seeding all defaults every load.
                $notUpdatedStmt = $db->prepare("
                    INSERT INTO availability_status_master
                        (status_key, status_label, badge_color, description, display_order, is_active)
                    VALUES ('not_updated', 'Not Updated', 'secondary', 'No status update submitted yet', 0, 1)
                    ON DUPLICATE KEY UPDATE
                        status_key = status_key
                ");
                $notUpdatedStmt->execute();
            }
        } catch (Exception $e) {
            // Keep call-sites resilient; they will use fallback options.
        }
    }
}

if (!function_exists('getAvailabilityStatusOptions')) {
    function getAvailabilityStatusOptions($includeInactive = false) {
        $fallback = [
            ['status_key' => 'not_updated', 'status_label' => 'Not Updated', 'badge_color' => 'secondary', 'display_order' => 0, 'is_active' => 1],
            ['status_key' => 'available', 'status_label' => 'Available', 'badge_color' => 'success', 'display_order' => 10, 'is_active' => 1],
            ['status_key' => 'working', 'status_label' => 'Working', 'badge_color' => 'primary', 'display_order' => 20, 'is_active' => 1],
            ['status_key' => 'busy', 'status_label' => 'Busy / In Meeting', 'badge_color' => 'warning', 'display_order' => 30, 'is_active' => 1],
            ['status_key' => 'on_leave', 'status_label' => 'On Leave', 'badge_color' => 'danger', 'display_order' => 40, 'is_active' => 1],
            ['status_key' => 'sick_leave', 'status_label' => 'Sick Leave', 'badge_color' => 'danger', 'display_order' => 50, 'is_active' => 1]
        ];

        try {
            $db = Database::getInstance();
            ensureAvailabilityStatusMaster($db);
            $sql = "
                SELECT status_key, status_label, badge_color, display_order, is_active
                FROM availability_status_master
            ";
            if (!$includeInactive) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY display_order ASC, status_label ASC";
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            return !empty($rows) ? $rows : $fallback;
        } catch (Exception $e) {
            return $fallback;
        }
    }
}

if (!function_exists('normalizeAvailabilityStatusKey')) {
    function normalizeAvailabilityStatusKey($status, array $allowedStatuses, $default = 'not_updated') {
        $status = strtolower(trim((string)$status));
        return in_array($status, $allowedStatuses, true) ? $status : $default;
    }
}

/**
 * Rewrite local upload URLs in HTML to secure file API URLs.
 * This avoids direct /uploads access issues on restrictive hosts.
 */
function rewrite_upload_urls_to_secure($html) {
    if (trim((string)$html) === '') return '';

    $baseDir = '';
    if (function_exists('getBaseDir')) {
        try {
            $baseDir = (string)getBaseDir();
        } catch (Exception $e) {
            $baseDir = '';
        }
    }
    $baseDir = rtrim($baseDir, '/');
    $secureBase = $baseDir . '/api/secure_file.php?path=';

    $mapUrl = function ($url) use ($secureBase) {
        $url = html_entity_decode(trim((string)$url), ENT_QUOTES, 'UTF-8');
        if ($url === '' || preg_match('#^(data:|javascript:|mailto:|tel:)#i', $url)) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $url;
        }

        $rel = null;
        $posUploads = strpos($path, 'uploads/');
        $posAssets = strpos($path, 'assets/uploads/');

        if ($posUploads !== false) {
            $rel = ltrim(substr($path, $posUploads), '/');
        } elseif ($posAssets !== false) {
            $rel = ltrim(substr($path, $posAssets), '/');
        } else {
            $pathTrim = ltrim(str_replace('\\', '/', $path), '/');
            if (strpos($pathTrim, 'uploads/') === 0 || strpos($pathTrim, 'assets/uploads/') === 0) {
                $rel = $pathTrim;
            }
        }

        if ($rel === null || $rel === '') {
            return $url;
        }

        return $secureBase . rawurlencode($rel);
    };

    $html = preg_replace_callback('/\b(src|href)\s*=\s*("([^"]*)"|\'([^\']*)\')/i', function ($m) use ($mapUrl) {
        $attr = $m[1];
        $quoteWrapped = $m[2];
        $val = isset($m[3]) && $m[3] !== '' ? $m[3] : (isset($m[4]) ? $m[4] : '');
        $newVal = $mapUrl($val);
        return $attr . '="' . htmlspecialchars($newVal, ENT_QUOTES, 'UTF-8') . '"';
    }, $html);

    return $html;
}

/**
 * Extract local upload-relative paths from HTML src/href attributes.
 * Supports direct /uploads URLs and secure_file.php?path=... URLs.
 */
function extract_local_upload_paths_from_html($html, $allowedPrefixes = ['uploads/', 'assets/uploads/']) {
    $html = (string)$html;
    if (trim($html) === '') return [];

    $paths = [];
    $matches = [];
    preg_match_all('/\b(?:src|href)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $matches, PREG_SET_ORDER);

    $normalize = function ($rawUrl) use ($allowedPrefixes) {
        $url = html_entity_decode(trim((string)$rawUrl), ENT_QUOTES, 'UTF-8');
        if ($url === '' || preg_match('#^(data:|javascript:|mailto:|tel:)#i', $url)) return '';

        $path = '';
        $urlPath = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');

        if ($urlPath !== '' && stripos($urlPath, '/api/secure_file.php') !== false) {
            $qp = [];
            parse_str($query, $qp);
            $path = (string)($qp['path'] ?? '');
            $path = rawurldecode($path);
        } elseif ($urlPath !== '' && stripos($urlPath, '/api/public_image.php') !== false) {
            $qp = [];
            parse_str($query, $qp);
            $token = trim((string)($qp['t'] ?? ''));
            if ($token !== '' && strpos($token, '.') !== false) {
                $parts = explode('.', $token, 2);
                $payloadB64 = (string)($parts[0] ?? '');
                $sig = (string)($parts[1] ?? '');
                $expected = hash_hmac('sha256', $payloadB64, get_public_image_token_secret());
                if (hash_equals($expected, $sig)) {
                    $decoded = base64url_decode($payloadB64);
                    $payload = is_string($decoded) ? json_decode($decoded, true) : null;
                    $path = (string)($payload['p'] ?? '');
                }
            }
        } else {
            $candidate = $urlPath !== '' ? $urlPath : $url;
            $candidate = str_replace('\\', '/', $candidate);
            $candidate = ltrim($candidate, '/');

            $posUploads = strpos($candidate, 'uploads/');
            $posAssets = strpos($candidate, 'assets/uploads/');
            if ($posUploads !== false) {
                $path = substr($candidate, $posUploads);
            } elseif ($posAssets !== false) {
                $path = substr($candidate, $posAssets);
            }
        }

        $path = ltrim(str_replace('\\', '/', (string)$path), '/');
        if ($path === '' || strpos($path, "\0") !== false || strpos($path, '..') !== false) return '';

        $allowed = false;
        foreach ((array)$allowedPrefixes as $prefix) {
            $prefix = ltrim(str_replace('\\', '/', (string)$prefix), '/');
            if ($prefix !== '' && strpos($path, $prefix) === 0) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) return '';

        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'];
        if (!in_array($ext, $imageExts, true)) return '';

        return $path;
    };

    foreach ($matches as $m) {
        $url = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
        $rel = $normalize($url);
        if ($rel !== '') $paths[$rel] = true;
    }

    return array_keys($paths);
}

/**
 * Delete local upload files referenced in HTML. Missing files are ignored.
 */
function delete_local_upload_files_from_html($html, $allowedPrefixes = ['uploads/', 'assets/uploads/']) {
    $relPaths = extract_local_upload_paths_from_html($html, $allowedPrefixes);
    if (empty($relPaths)) return ['deleted' => 0, 'paths' => []];

    $baseDir = realpath(__DIR__ . '/..');
    if ($baseDir === false) return ['deleted' => 0, 'paths' => []];
    $baseNorm = rtrim(str_replace('\\', '/', $baseDir), '/');
    $deleted = 0;
    $deletedPaths = [];

    foreach ($relPaths as $rel) {
        $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
        if ($rel === '' || strpos($rel, '..') !== false) continue;

        $candidate = $baseNorm . '/' . $rel;
        $full = realpath($candidate);
        if ($full === false) {
            if (!is_file($candidate)) continue;
            $full = $candidate;
        }

        $fullNorm = str_replace('\\', '/', $full);
        if (strpos($fullNorm, $baseNorm . '/uploads/') !== 0 && strpos($fullNorm, $baseNorm . '/assets/uploads/') !== 0) {
            continue;
        }
        if (is_file($fullNorm) && @unlink($fullNorm)) {
            $deleted++;
            $deletedPaths[] = $rel;
        }
    }

    return ['deleted' => $deleted, 'paths' => $deletedPaths];
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode((string)$data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $s = strtr((string)$data, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($s, true);
}

function get_public_image_token_secret() {
    static $secret = null;
    if ($secret !== null) return $secret;

    $fromEnv = trim((string)getenv('PMS_PUBLIC_IMAGE_SECRET'));
    if ($fromEnv !== '') {
        $secret = $fromEnv;
        return $secret;
    }

    // Fallback: derive from APP_KEY if set (stronger than DB credentials)
    $appKey = trim((string)getenv('APP_KEY'));
    if ($appKey !== '') {
        $secret = hash_pbkdf2('sha256', $appKey, 'pms_public_image_secret_v1', 100000, 32);
        return $secret;
    }

    // Last resort: derive from DB credentials + server path.
    // Log a warning so admins know to set PMS_PUBLIC_IMAGE_SECRET.
    error_log('SECURITY WARNING: PMS_PUBLIC_IMAGE_SECRET env var not set. Set it to a random 32+ char secret for stronger public image token security.');
    $parts = [
        (string)DB_HOST,
        (string)DB_NAME,
        (string)DB_USER,
        (string)DB_PASS,
        strtolower(str_replace('\\', '/', realpath(__DIR__)))
    ];
    $secret = hash('sha256', implode('|', $parts));
    return $secret;
}

function normalize_local_upload_path_from_src($src, $allowedPrefixes = ['uploads/', 'assets/uploads/']) {
    $src = html_entity_decode(trim((string)$src), ENT_QUOTES, 'UTF-8');
    if ($src === '') return null;

    $urlPath = (string)(parse_url($src, PHP_URL_PATH) ?? '');
    $query = (string)(parse_url($src, PHP_URL_QUERY) ?? '');

    $path = '';
    if ($urlPath !== '' && stripos($urlPath, '/api/secure_file.php') !== false) {
        $qp = [];
        parse_str($query, $qp);
        $path = rawurldecode((string)($qp['path'] ?? ''));
    } elseif ($urlPath !== '' && stripos($urlPath, '/api/public_image.php') !== false) {
        $qp = [];
        parse_str($query, $qp);
        $token = trim((string)($qp['t'] ?? ''));
        if ($token !== '' && strpos($token, '.') !== false) {
            $parts = explode('.', $token, 2);
            $payloadB64 = (string)($parts[0] ?? '');
            $sig = (string)($parts[1] ?? '');
            $expected = hash_hmac('sha256', $payloadB64, get_public_image_token_secret());
            if (hash_equals($expected, $sig)) {
                $decoded = base64url_decode($payloadB64);
                $payload = is_string($decoded) ? json_decode($decoded, true) : null;
                $path = (string)($payload['p'] ?? '');
            }
        }
    } else {
        $candidate = $urlPath !== '' ? $urlPath : $src;
        $candidate = str_replace('\\', '/', $candidate);
        $candidate = ltrim($candidate, '/');
        $posAssetsUploads = strpos($candidate, 'assets/uploads/');
        $posUploads = strpos($candidate, 'uploads/');
        if ($posAssetsUploads !== false) {
            $path = substr($candidate, $posAssetsUploads);
        } elseif ($posUploads !== false) {
            $path = substr($candidate, $posUploads);
        }
    }

    $path = ltrim(str_replace('\\', '/', (string)$path), '/');
    if ($path === '' || strpos($path, "\0") !== false || strpos($path, '..') !== false) {
        return null;
    }

    $allowed = false;
    foreach ((array)$allowedPrefixes as $prefix) {
        $prefix = ltrim(str_replace('\\', '/', (string)$prefix), '/');
        if ($prefix !== '' && strpos($path, $prefix) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) return null;

    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];
    if (!in_array($ext, $imageExts, true)) return null;

    return $path;
}

function build_public_image_url_from_src($src) {
    $relPath = normalize_local_upload_path_from_src($src, [
        'uploads/issues/', 
        'uploads/chat/', 
        'assets/uploads/issues/', 
        'assets/uploads/chat/', 
        'assets/uploads/issue_screenshots/',
        'assets/uploads/'
    ]);
    if ($relPath === null) {
        return (string)$src;
    }

    $payload = json_encode(['p' => $relPath], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return (string)$src;
    }
    $payloadB64 = base64url_encode($payload);
    $sig = hash_hmac('sha256', $payloadB64, get_public_image_token_secret());
    $token = $payloadB64 . '.' . $sig;

    $baseDir = '';
    if (function_exists('getBaseDir')) {
        try { $baseDir = (string)getBaseDir(); } catch (Exception $e) { $baseDir = ''; }
    }
    return rtrim($baseDir, '/') . '/api/public_image.php?t=' . rawurlencode($token);
}

function rewrite_html_public_image_urls($html) {
    $html = (string)$html;
    if ($html === '') {
        return $html;
    }

    $rewritten = preg_replace_callback(
        '/\b(src|href)\s*=\s*(["\'])(.*?)\2/i',
        static function ($matches) {
            $attrName = (string)($matches[1] ?? '');
            $quote = (string)($matches[2] ?? '"');
            $value = html_entity_decode((string)($matches[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $publicUrl = build_public_image_url_from_src($value);
            if ($publicUrl === $value) {
                return (string)$matches[0];
            }

            return $attrName . '=' . $quote
                . htmlspecialchars($publicUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . $quote;
        },
        $html
    );

    return is_string($rewritten) ? $rewritten : $html;
}

/**
 * Render a user's full name as a link to their profile unless the user is an admin/admin.
 * Accepts either a user id or an array with keys ['id','full_name','role'].
 */
function renderUserNameLink($user) {
    $db = Database::getInstance();
    $id = null; $name = null; $role = null;
    if (is_array($user)) {
        $id = isset($user['id']) ? (int)$user['id'] : null;
        $name = $user['full_name'] ?? null;
        $role = $user['role'] ?? null;
    } else {
        $id = (int)$user;
    }
    if (!$id) return htmlspecialchars($name ?: 'Unknown', ENT_QUOTES, 'UTF-8');

    if (!$name || !$role) {
        $stmt = $db->prepare("SELECT full_name, role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (!$name) $name = $row['full_name'];
            if (!$role) $role = $row['role'];
        }
    }

    $name = $name ?: 'User';
    if (in_array($role, ['admin'])) {
        return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    }

    $baseDir = isset($GLOBALS['baseDir']) ? $GLOBALS['baseDir'] : '';
    $href = ($baseDir ? $baseDir : '') . "/modules/profile.php?id=" . $id;
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
}

