<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin','admin']);

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: active_sessions.php');
        exit;
    }
    $postAction = trim((string)($_POST['cleanup_action'] ?? ''));
    $redirectTo = strtok($_SERVER['REQUEST_URI'], '?');
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    if ($queryString !== '') {
        $redirectTo .= '?' . $queryString;
    }

    if ($postAction === 'delete_selected') {
        $sessionIdsRaw = $_POST['session_ids'] ?? [];
        $sessionIds = [];
        if (is_array($sessionIdsRaw)) {
            foreach ($sessionIdsRaw as $sid) {
                $sid = trim((string)$sid);
                if ($sid !== '') {
                    $sessionIds[] = $sid;
                }
            }
        }
        $sessionIds = array_values(array_unique($sessionIds));
        if (empty($sessionIds)) {
            $_SESSION['error'] = 'No session rows selected.';
        } else {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $sql = "DELETE FROM user_sessions WHERE session_id IN ($placeholders) AND session_id <> ?";
            $params = array_merge($sessionIds, [session_id()]);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $deleted = (int)$stmt->rowCount();
            $_SESSION['success'] = $deleted . ' session record(s) deleted.';
            try {
                logActivity($db, (int)$_SESSION['user_id'], 'admin_cleanup_sessions', 'user_sessions', 0, [
                    'selected_count' => count($sessionIds),
                    'deleted_rows' => $deleted
                ]);
            } catch (Throwable $_) {
                // non-fatal
            }
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($postAction === 'purge_old') {
        $beforeDate = trim((string)($_POST['before_date'] ?? ''));
        $mode = trim((string)($_POST['purge_mode'] ?? 'inactive_only'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDate)) {
            $_SESSION['error'] = 'Please select a valid date.';
            header('Location: ' . $redirectTo);
            exit;
        }
        $sql = "DELETE FROM user_sessions WHERE last_activity < ? AND session_id <> ?";
        $params = [$beforeDate . ' 00:00:00', session_id()];
        if ($mode === 'inactive_only') {
            $sql .= " AND active = 0";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $deleted = (int)$stmt->rowCount();
        $_SESSION['success'] = $deleted . ' old session record(s) deleted.';
        try {
            logActivity($db, (int)$_SESSION['user_id'], 'admin_purge_sessions', 'user_sessions', 0, [
                'before_date' => $beforeDate,
                'purge_mode' => $mode,
                'deleted_rows' => $deleted
            ]);
        } catch (Throwable $_) {
            // non-fatal
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($postAction === 'delete_by_scope') {
        $scopeType = trim((string)($_POST['scope_type'] ?? ''));
        $targetId = (int)($_POST['target_id'] ?? 0);
        $mode = trim((string)($_POST['scope_mode'] ?? 'all'));

        if ($targetId <= 0) {
            $_SESSION['error'] = 'Please select a valid scope target.';
            header('Location: ' . $redirectTo);
            exit;
        }

        $userIds = [];
        if ($scopeType === 'user') {
            $userIds[] = $targetId;
        } elseif ($scopeType === 'project') {
            $uStmt = $db->prepare("
                SELECT DISTINCT ua.user_id
                FROM user_assignments ua
                WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
            ");
            $uStmt->execute([$targetId]);
            $userIds = array_map('intval', $uStmt->fetchAll(PDO::FETCH_COLUMN));

            $leadStmt = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ? LIMIT 1");
            $leadStmt->execute([$targetId]);
            $leadId = (int)$leadStmt->fetchColumn();
            if ($leadId > 0) {
                $userIds[] = $leadId;
            }
            $userIds = array_values(array_unique(array_filter($userIds)));
        } else {
            $_SESSION['error'] = 'Invalid scope type.';
            header('Location: ' . $redirectTo);
            exit;
        }

        if (empty($userIds)) {
            $_SESSION['error'] = 'No users found for selected scope.';
            header('Location: ' . $redirectTo);
            exit;
        }

        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "DELETE FROM user_sessions WHERE user_id IN ($ph) AND session_id <> ?";
        $params = array_merge($userIds, [session_id()]);
        if ($mode === 'inactive_only') {
            $sql .= " AND active = 0";
        } elseif ($mode === 'active_only') {
            $sql .= " AND active = 1";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $deleted = (int)$stmt->rowCount();
        $_SESSION['success'] = $deleted . ' session record(s) deleted for selected scope.';
        try {
            logActivity($db, (int)$_SESSION['user_id'], 'admin_cleanup_sessions_by_scope', 'user_sessions', 0, [
                'scope_type' => $scopeType,
                'target_id' => $targetId,
                'scope_mode' => $mode,
                'user_count' => count($userIds),
                'deleted_rows' => $deleted
            ]);
        } catch (Throwable $_) {
            // non-fatal
        }
        header('Location: ' . $redirectTo);
        exit;
    }
}

// Build filters and pagination
$params = [];
$where = [];

$search = trim($_GET['q'] ?? '');
$filterActive = $_GET['active'] ?? 'all';
$filterIp = trim($_GET['ip'] ?? '');
$since = trim($_GET['since'] ?? '');
$filterOnline = $_GET['online'] ?? 'all'; // New filter for truly online users

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR us.session_id LIKE ? )";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterActive === 'yes') {
    $where[] = "us.active = 1";
} elseif ($filterActive === 'no') {
    $where[] = "us.active = 0";
}
if ($filterOnline === 'yes') {
    // Consider users online if active AND last activity within last 10 minutes
    // Use TIMESTAMPDIFF to handle timezone differences properly
    $where[] = "us.active = 1 AND TIMESTAMPDIFF(MINUTE, us.last_activity, NOW()) <= 10";
}
if ($filterIp !== '') {
    $where[] = "us.ip_address LIKE ?";
    $params[] = '%' . $filterIp . '%';
}
if ($since !== '') {
    // Expect YYYY-MM-DD
    $where[] = "us.last_activity >= ?";
    $params[] = $since . ' 00:00:00';
}

$whereSql = '';
if (!empty($where)) $whereSql = 'WHERE ' . implode(' AND ', $where);

$perPage = intval($_GET['per_page'] ?? 25);
if ($perPage <= 0 || $perPage > 200) $perPage = 25;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// total count
$countSql = "SELECT COUNT(*) FROM user_sessions us LEFT JOIN users u ON u.id = us.user_id " . $whereSql;
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT us.*, u.full_name, u.email FROM user_sessions us LEFT JOIN users u ON u.id = us.user_id " . $whereSql . " ORDER BY us.last_activity DESC LIMIT ? OFFSET ?";
$paramsWithLimit = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare($sql);
$stmt->execute($paramsWithLimit);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($total / $perPage));
$visiblePages = [];
if ($totalPages <= 9) {
    $visiblePages = range(1, $totalPages);
} else {
    $windowStart = max(2, $page - 1);
    $windowEnd = min($totalPages - 1, $page + 1);

    if ($page <= 3) {
        $windowStart = 2;
        $windowEnd = 4;
    } elseif ($page >= $totalPages - 2) {
        $windowStart = $totalPages - 3;
        $windowEnd = $totalPages - 1;
    }

    $visiblePages[] = 1;
    if ($windowStart > 2) {
        $visiblePages[] = 'ellipsis';
    }
    for ($pageNo = $windowStart; $pageNo <= $windowEnd; $pageNo++) {
        $visiblePages[] = $pageNo;
    }
    if ($windowEnd < $totalPages - 1) {
        $visiblePages[] = 'ellipsis';
    }
    $visiblePages[] = $totalPages;
}
$allUsers = $db->query("SELECT id, full_name, email FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allProjects = $db->query("SELECT id, title FROM projects ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container mt-4">
    <h3>Active Sessions</h3>
    <p class="text-muted">Shows recent sessions (active & inactive). Use Force Logout to terminate a session.</p>

    <div class="card mb-3 border-warning">
        <div class="card-body py-3">
            <div class="fw-semibold mb-2">Session Storage Cleanup</div>
            <form method="post" class="row g-2 align-items-end" data-confirm="Delete old session records?">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="cleanup_action" value="purge_old">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Delete sessions older than</label>
                    <input type="date" name="before_date" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Delete mode</label>
                    <select name="purge_mode" class="form-select form-select-sm">
                        <option value="inactive_only">Only logged-out sessions</option>
                        <option value="all">All sessions (except current)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-danger">Purge Session Records</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card mb-3 border-warning">
        <div class="card-body py-3">
            <div class="fw-semibold mb-2">User/Project Wise Session Cleanup</div>
            <form method="post" class="row g-2 align-items-end" data-confirm="Delete sessions for the selected scope?">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="cleanup_action" value="delete_by_scope">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Scope</label>
                    <select class="form-select form-select-sm" name="scope_type" id="sessionScopeType">
                        <option value="user">User Wise</option>
                        <option value="project">Project Team Wise</option>
                    </select>
                </div>
                <div class="col-md-4" id="sessionUserTargetWrap">
                    <label class="form-label form-label-sm">User</label>
                    <select class="form-select form-select-sm" name="target_id" id="sessionUserTarget">
                        <option value="">Select user</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)$u['full_name'] . ' (' . (string)$u['email'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-none" id="sessionProjectTargetWrap">
                    <label class="form-label form-label-sm">Project</label>
                    <select class="form-select form-select-sm" id="sessionProjectTarget">
                        <option value="">Select project</option>
                        <?php foreach ($allProjects as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Mode</label>
                    <select class="form-select form-select-sm" name="scope_mode">
                        <option value="all">All sessions</option>
                        <option value="inactive_only">Only logged-out</option>
                        <option value="active_only">Only active</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-danger">Delete By Scope</button>
                </div>
            </form>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-auto">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control form-control-sm" placeholder="Search user, email, session id">
        </div>
        <div class="col-auto">
            <input type="text" name="ip" value="<?php echo htmlspecialchars($filterIp); ?>" class="form-control form-control-sm" placeholder="IP contains">
        </div>
        <div class="col-auto">
            <input type="date" name="since" value="<?php echo htmlspecialchars($since); ?>" class="form-control form-control-sm" title="Last activity since">
        </div>
        <div class="col-auto">
            <select name="active" class="form-select form-select-sm">
                <option value="all"<?php if ($filterActive==='all') echo ' selected'; ?>>All Sessions</option>
                <option value="yes"<?php if ($filterActive==='yes') echo ' selected'; ?>>Active Sessions</option>
                <option value="no"<?php if ($filterActive==='no') echo ' selected'; ?>>Logged Out</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="online" class="form-select form-select-sm">
                <option value="all"<?php if ($filterOnline==='all') echo ' selected'; ?>>All</option>
                <option value="yes"<?php if ($filterOnline==='yes') echo ' selected'; ?>>Online Now (10min)</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="per_page" class="form-select form-select-sm">
                <?php foreach ([10,25,50,100] as $pp): ?>
                    <option value="<?php echo $pp; ?>"<?php if ($perPage==$pp) echo ' selected'; ?>><?php echo $pp; ?> per page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-primary">Filter</button>
        </div>
    </form>

    <form method="post" data-confirm="Delete selected sessions?">
    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
    <input type="hidden" name="cleanup_action" value="delete_selected">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="small text-muted">Select session rows to delete DB records directly.</div>
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete Selected</button>
    </div>
    <div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllSessions"></th>
                <th>User</th>
                <th>Email</th>
                <th>Session ID</th>
                <th>IP</th>
                <th>User Agent</th>
                <th>Location</th>
                <th>Created</th>
                <th>Last Activity</th>
                <th>Logout At</th>
                <th>Logout Type</th>
                <th>Active</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr id="sess-<?php echo htmlspecialchars($r['session_id']); ?>">
                <td>
                    <?php if ((string)$r['session_id'] === (string)session_id()): ?>
                        <span class="text-muted small">Current</span>
                    <?php else: ?>
                        <input type="checkbox" name="session_ids[]" value="<?php echo htmlspecialchars($r['session_id']); ?>">
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($r['user_id'])): ?>
                        <a href="<?php echo htmlspecialchars(getBaseDir()); ?>/modules/profile.php?id=<?php echo intval($r['user_id']); ?>"><?php echo htmlspecialchars($r['full_name'] ?? 'User'); ?></a>
                    <?php else: ?>
                        <?php echo htmlspecialchars($r['full_name'] ?? 'User'); ?>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($r['email'] ?? ''); ?></td>
                <td><code><?php echo htmlspecialchars($r['session_id']); ?></code></td>
                <td><?php echo htmlspecialchars($r['ip_address'] ?? ''); ?></td>
                <td>
                    <?php
                        $ua_full  = $r['user_agent'] ?? '';
                        $ua_short = mb_substr($ua_full, 0, 120);
                        $ua_too_long = mb_strlen($ua_full) > 120;
                        $ua_parsed = !empty($ua_full) ? get_browser_info($ua_full) : null;
                    ?>
                    <?php if ($ua_parsed && $ua_parsed['browser'] !== 'Unknown'): ?>
                        <span class="fw-semibold"><?php echo htmlspecialchars($ua_parsed['platform'] . ' / ' . $ua_parsed['browser'] . ' ' . ($ua_parsed['browser_version'] ?? '')); ?></span>
                    <?php endif; ?>
                    <div class="ua-snippet small text-muted" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:480px;" title="<?php echo htmlspecialchars($ua_full); ?>"><?php echo htmlspecialchars($ua_short); ?><?php if ($ua_too_long) echo '...'; ?></div>
                    <?php if ($ua_too_long): ?>
                        <div class="ua-full d-none" style="white-space:normal; word-break:break-word; max-width:480px; margin-top:4px;"><?php echo htmlspecialchars($ua_full); ?></div>
                        <div class="mt-1">
                            <button type="button" class="btn btn-link btn-sm p-0 ua-toggle">Read more</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm ua-copy ms-2" title="Copy user-agent">Copy</button>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                        $loc = [];
                        if (!empty($r['ip_location'])) {
                            $loc = json_decode($r['ip_location'], true) ?: [];
                        }
                        $ipAddr = $r['ip_address'] ?? '';
                        if (!empty($loc)) {
                            $addr = trim(($loc['city'] ?? '') . ', ' . ($loc['region'] ?? '') . ', ' . ($loc['country'] ?? ''));
                            $addr = trim($addr, ', ');
                            if (!empty($loc['latitude']) && !empty($loc['longitude'])) {
                                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($loc['latitude'] . ',' . $loc['longitude']);
                            } else {
                                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr);
                            }
                            echo htmlspecialchars($addr) . ' <a href="' . htmlspecialchars($mapUrl) . '" target="_blank" rel="noopener" class="ms-1"><i class="fas fa-map-marker-alt text-primary"></i></a>';
                        } elseif (!empty($ipAddr)) {
                            echo '<span class="geo-lazy text-muted" data-ip="' . htmlspecialchars($ipAddr, ENT_QUOTES) . '"><i class="fas fa-spinner fa-spin fa-xs"></i></span>';
                        } else {
                            echo '<span class="text-muted">-</span>';
                        }
                    ?>
                </td>
                <td><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['last_activity'] ?? ''); ?></td>
                <td class="session-logout-at"><?php echo htmlspecialchars($r['logout_at'] ?? ''); ?></td>
                <td class="session-logout-type"><?php echo htmlspecialchars($r['logout_type'] ?? ''); ?></td>
                <td class="session-status">
                    <?php 
                    $isActive = (bool)$r['active'];
                    // Use database to calculate time difference to avoid timezone issues
                    $stmt = $db->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) as minutes_ago");
                    $stmt->execute([$r['last_activity']]);
                    $minutesAgo = abs((int)$stmt->fetchColumn());
                    $isOnline = $isActive && $minutesAgo <= 10;
                    
                    if ($isOnline) {
                        echo '<span class="badge bg-success">Online</span>';
                    } elseif ($isActive) {
                        echo '<span class="badge bg-warning text-dark">Idle (' . $minutesAgo . 'm ago)</span>';
                    } else {
                        echo '<span class="badge bg-secondary">Logged Out</span>';
                    }
                    ?>
                </td>
                <td class="session-action">
                    <?php if ($r['active']): ?>
                        <button class="btn btn-sm btn-danger force-logout" data-session="<?php echo htmlspecialchars($r['session_id']); ?>">Force Logout</button>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </form>

    <nav aria-label="Page navigation">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
            <div class="small text-muted">Page <?php echo $page; ?> of <?php echo $totalPages; ?>, total <?php echo $total; ?> session record(s).</div>
            <ul class="pagination pagination-sm flex-wrap mb-0">
            <?php
            // build base query string for pagination links
            $qs = $_GET; unset($qs['page']);
            $baseQs = http_build_query($qs);
            $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
            $previousPage = max(1, $page - 1);
            $nextPage = min($totalPages, $page + 1);
            $previousLink = $baseUrl . '?' . ($baseQs ? ($baseQs . '&') : '') . 'page=' . $previousPage . '&per_page=' . $perPage;
            $nextLink = $baseUrl . '?' . ($baseQs ? ($baseQs . '&') : '') . 'page=' . $nextPage . '&per_page=' . $perPage;
            echo '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($previousLink) . '"' . ($page <= 1 ? ' tabindex="-1" aria-disabled="true"' : '') . '>Previous</a></li>';
            foreach ($visiblePages as $pageToken) {
                if ($pageToken === 'ellipsis') {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    continue;
                }
                $activeClass = ((int)$pageToken === $page) ? ' active' : '';
                $link = $baseUrl . '?' . ($baseQs ? ($baseQs . '&') : '') . 'page=' . (int)$pageToken . '&per_page=' . $perPage;
                echo '<li class="page-item' . $activeClass . '"><a class="page-link" href="' . htmlspecialchars($link) . '">' . (int)$pageToken . '</a></li>';
            }
            echo '<li class="page-item' . ($page >= $totalPages ? ' disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($nextLink) . '"' . ($page >= $totalPages ? ' tabindex="-1" aria-disabled="true"' : '') . '>Next</a></li>';
            ?>
            </ul>
        </div>
    </nav>

    <script>window._activeSessionsConfig = { baseDir: "<?php echo getBaseDir(); ?>", csrfToken: "<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>" };</script>
    <script src="<?php echo getBaseDir(); ?>/assets/js/active-sessions.js?v=<?php echo time(); ?>"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; 