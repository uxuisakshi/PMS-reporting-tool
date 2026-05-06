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
        header('Location: login_activity.php');
        exit;
    }
    $postAction = trim((string)($_POST['cleanup_action'] ?? ''));
    $redirectTo = strtok($_SERVER['REQUEST_URI'], '?');
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    if ($queryString !== '') {
        $redirectTo .= '?' . $queryString;
    }

    if ($postAction === 'unlock_user') {
        $hash = trim($_POST['username_hash'] ?? '');
        if ($hash) {
            $stmt = $db->prepare("DELETE FROM login_attempts WHERE username_hash = ?");
            $stmt->execute([$hash]);
            $_SESSION['success'] = 'Account unlocked successfully.';
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($postAction === 'unlock_all') {
        $db->exec("DELETE FROM login_attempts");
        $_SESSION['success'] = 'All locked accounts have been unlocked.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($postAction === 'delete_selected') {
        $idsRaw = $_POST['log_ids'] ?? [];
        $ids = [];
        if (is_array($idsRaw)) {
            foreach ($idsRaw as $id) {
                $i = (int)$id;
                if ($i > 0) {
                    $ids[] = $i;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            $_SESSION['error'] = 'No activity rows selected.';
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "DELETE FROM activity_log WHERE id IN ($placeholders) AND action IN ('login','logout')";
            $stmt = $db->prepare($sql);
            $stmt->execute($ids);
            $deleted = (int)$stmt->rowCount();
            $_SESSION['success'] = $deleted . ' login activity record(s) deleted.';
            try {
                logActivity($db, (int)$_SESSION['user_id'], 'admin_cleanup_login_activity', 'activity_log', 0, [
                    'deleted_ids_count' => count($ids),
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
        $type = trim((string)($_POST['action_type'] ?? 'all'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDate)) {
            $_SESSION['error'] = 'Please select a valid date.';
            header('Location: ' . $redirectTo);
            exit;
        }

        $params = [$beforeDate . ' 00:00:00'];
        $sql = "DELETE FROM activity_log WHERE created_at < ? AND action IN ('login','logout')";
        if ($type === 'login' || $type === 'logout') {
            $sql .= " AND action = ?";
            $params[] = $type;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $deleted = (int)$stmt->rowCount();
        $_SESSION['success'] = $deleted . ' old login activity record(s) deleted.';
        try {
            logActivity($db, (int)$_SESSION['user_id'], 'admin_purge_login_activity', 'activity_log', 0, [
                'before_date' => $beforeDate,
                'action_type' => $type,
                'deleted_rows' => $deleted
            ]);
        } catch (Throwable $_) {
            // non-fatal
        }

        header('Location: ' . $redirectTo);
        exit;
    }
}

// Filters & pagination
$params = [];
$where = [];

$q = trim($_GET['q'] ?? '');
$actionFilter = $_GET['action'] ?? 'all';
$since = trim($_GET['since'] ?? '');
$perPage = intval($_GET['per_page'] ?? 25);
if ($perPage <= 0 || $perPage > 500) $perPage = 25;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

if ($q !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR al.details LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($actionFilter === 'login' || $actionFilter === 'logout') {
    $where[] = "al.action = ?";
    $params[] = $actionFilter;
}
if ($since !== '') {
    $where[] = "al.created_at >= ?";
    $params[] = $since . ' 00:00:00';
}

$whereSql = '';
if (!empty($where)) $whereSql = 'WHERE ' . implode(' AND ', $where);

// total
$countSql = "SELECT COUNT(*) FROM activity_log al LEFT JOIN users u ON u.id = al.user_id " . $whereSql;
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT al.*, u.id AS user_id, u.full_name, u.email FROM activity_log al LEFT JOIN users u ON u.id = al.user_id " . $whereSql . " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
$paramsWithLimit = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare($sql);
$stmt->execute($paramsWithLimit);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($total / $perPage));

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container mt-4">
    <h3>Login / Logout Activity</h3>
    <p class="text-muted">Showing <?php echo $total; ?> events.</p>

    <!-- Locked Accounts Section -->
    <?php
    $lockedUsers = [];
    try {
        $lockedStmt = $db->query("
            SELECT la.username_hash, COUNT(*) as attempts,
                   MIN(la.attempted_at) as first_attempt,
                   MAX(la.attempted_at) as last_attempt,
                   u.username, u.full_name, u.email,
                   TIMESTAMPDIFF(SECOND, MIN(la.attempted_at), NOW()) as seconds_since_first
            FROM login_attempts la
            LEFT JOIN users u ON MD5(LOWER(u.username)) = la.username_hash
            WHERE la.attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            GROUP BY la.username_hash
            HAVING attempts >= 5
            ORDER BY last_attempt DESC
        ");
        $lockedUsers = $lockedStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    ?>
    <?php if (!empty($lockedUsers)): ?>
    <div class="card mb-3 border-danger">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
            <span><i class="fas fa-lock me-2"></i>Locked Accounts (<?php echo count($lockedUsers); ?>)</span>
            <form method="post" class="mb-0">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="cleanup_action" value="unlock_all">
                <button type="submit" class="btn btn-sm btn-light">
                    <i class="fas fa-unlock me-1"></i> Unlock All
                </button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Attempts</th>
                        <th>First Attempt</th>
                        <th>Last Attempt</th>
                        <th>Auto-unlock in</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lockedUsers as $lu):
                        $remainingSec = max(0, 900 - (int)($lu['seconds_since_first'] ?? 900));
                        $remainingMin = ceil($remainingSec / 60);
                    ?>
                    <tr>
                        <td>
                            <?php if ($lu['full_name']): ?>
                                <strong><?php echo htmlspecialchars($lu['full_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($lu['username'] ?? $lu['email'] ?? ''); ?></small>
                            <?php else: ?>
                                <span class="text-muted">Unknown (hash: <?php echo substr($lu['username_hash'], 0, 8); ?>...)</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-danger"><?php echo (int)$lu['attempts']; ?></span></td>
                        <td><small><?php echo htmlspecialchars($lu['first_attempt']); ?></small></td>
                        <td><small><?php echo htmlspecialchars($lu['last_attempt']); ?></small></td>
                        <td>
                            <?php if ($remainingSec > 0): ?>
                                <span class="text-warning"><?php echo $remainingMin; ?> min</span>
                            <?php else: ?>
                                <span class="text-success">Unlocking...</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" class="mb-0">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="cleanup_action" value="unlock_user">
                                <input type="hidden" name="username_hash" value="<?php echo htmlspecialchars($lu['username_hash']); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-unlock me-1"></i> Unlock
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-success mb-3 py-2">
        <i class="fas fa-unlock me-2"></i> No accounts are currently locked.
    </div>
    <?php endif; ?>

    <div class="card mb-3 border-warning">
        <div class="card-body py-3">
            <div class="fw-semibold mb-2">Storage Cleanup</div>
            <form method="post" class="row g-2 align-items-end" data-confirm="Delete old login/logout records?">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="cleanup_action" value="purge_old">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Delete records older than</label>
                    <input type="date" name="before_date" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Action type</label>
                    <select name="action_type" class="form-select form-select-sm">
                        <option value="all">Login + Logout</option>
                        <option value="login">Only Login</option>
                        <option value="logout">Only Logout</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-danger">Purge Old Records</button>
                </div>
            </form>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-auto">
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control form-control-sm" placeholder="Search user, email, details">
        </div>
        <div class="col-auto">
            <select name="action" class="form-select form-select-sm">
                <option value="all"<?php if ($actionFilter==='all') echo ' selected'; ?>>All</option>
                <option value="login"<?php if ($actionFilter==='login') echo ' selected'; ?>>Login</option>
                <option value="logout"<?php if ($actionFilter==='logout') echo ' selected'; ?>>Logout</option>
            </select>
        </div>
        <div class="col-auto">
            <input type="date" name="since" value="<?php echo htmlspecialchars($since); ?>" class="form-control form-control-sm" title="From date">
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

    <p class="text-muted">Showing <?php echo count($rows); ?> of <?php echo $total; ?> events (Page <?php echo $page; ?> of <?php echo $totalPages; ?>).</p>

    <form method="post" data-confirm="Delete selected login activity records?">
    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
    <input type="hidden" name="cleanup_action" value="delete_selected">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="small text-muted">Select rows and delete selected records if needed.</div>
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete Selected</button>
    </div>
    <div class="table-responsive">
    <table class="table table-striped table-sm">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllLoginLogs"></th>
                <th>When</th>
                <th>User</th>
                <th>Action</th>
                <th>IP</th>
                <th>Device / Browser</th>
                <th>Location</th>
                <th>Session ID</th>
                <th>Recent Sections</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $details = json_decode($r['details'], true) ?: [];
            $ua = $details['user_agent'] ?? '';
            $ua_parsed = $details['ua_parsed'] ?? null;
            $sessionId = $details['session_id'] ?? '';
            $ip = $r['ip_address'] ?? ($details['device_ip'] ?? '');
            $sections = $details['user_sections'] ?? [];
        ?>
            <tr>
                <td><input type="checkbox" name="log_ids[]" value="<?php echo (int)$r['id']; ?>"></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td>
                    <?php if (!empty($r['user_id'])): ?>
                        <a href="<?php echo htmlspecialchars(getBaseDir()); ?>/modules/profile.php?id=<?php echo intval($r['user_id']); ?>"><?php echo htmlspecialchars($r['full_name'] ?: 'User'); ?></a>
                    <?php else: ?>
                        <?php echo htmlspecialchars($r['full_name'] ?: 'System'); ?>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars(ucfirst($r['action'])); ?></td>
                <td><?php echo htmlspecialchars($ip); ?></td>
                <td>
                    <?php
                        $ua_full = $ua ?? '';
                        $ua_short = mb_substr($ua_full, 0, 100);
                        $ua_too_long = mb_strlen($ua_full) > 100;

                        // Parse UA at display time if not already parsed at login time
                        if (!$ua_parsed && !empty($ua_full)) {
                            $ua_parsed = get_browser_info($ua_full);
                        }
                    ?>
                    <?php if ($ua_parsed && $ua_parsed['browser'] !== 'Unknown'): ?>
                        <span class="fw-semibold"><?php echo htmlspecialchars($ua_parsed['platform'] . ' / ' . $ua_parsed['browser'] . ' ' . ($ua_parsed['browser_version'] ?? '')); ?></span>
                        <?php if (!empty($ua_full)): ?>
                        <div class="small text-muted ua-snippet" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:480px;" title="<?php echo htmlspecialchars($ua_full); ?>"><?php echo htmlspecialchars($ua_short); ?><?php if ($ua_too_long) echo '...'; ?></div>
                        <?php endif; ?>
                    <?php elseif (!empty($ua_full)): ?>
                        <div class="ua-snippet" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:480px;" title="<?php echo htmlspecialchars($ua_full); ?>"><?php echo htmlspecialchars($ua_short); ?><?php if ($ua_too_long) echo '...'; ?></div>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                    <?php if ($ua_too_long): ?>
                        <div class="ua-full d-none" style="white-space:normal; word-break:break-word; margin-top:4px; max-width:480px;"><?php echo htmlspecialchars($ua_full); ?></div>
                        <div class="mt-1">
                            <button type="button" class="btn btn-link btn-sm p-0 ua-toggle">Read more</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm ua-copy ms-2" title="Copy user-agent">Copy</button>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                        $geo = $details['geo'] ?? [];
                        if (!empty($geo)) {
                            $addr = trim(($geo['city'] ?? '') . ', ' . ($geo['region'] ?? '') . ', ' . ($geo['country'] ?? ''));
                            $addr = trim($addr, ', ');
                            if (!empty($geo['latitude']) && !empty($geo['longitude'])) {
                                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($geo['latitude'] . ',' . $geo['longitude']);
                            } else {
                                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr);
                            }
                            echo htmlspecialchars($addr) . ' <a href="' . htmlspecialchars($mapUrl) . '" target="_blank" rel="noopener" class="ms-1"><i class="fas fa-map-marker-alt text-primary"></i></a>';
                        } elseif (!empty($ip)) {
                            // Lazy-load geo via JS — avoids slow page load
                            echo '<span class="geo-lazy text-muted" data-ip="' . htmlspecialchars($ip, ENT_QUOTES) . '"><i class="fas fa-spinner fa-spin fa-xs"></i></span>';
                        } else {
                            echo '<span class="text-muted">-</span>';
                        }
                    ?>
                </td>
                <td><code><?php echo htmlspecialchars($sessionId); ?></code></td>
                <td><?php echo htmlspecialchars(implode(' â€º ', array_slice($sections,0,10))); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </form>

    <nav aria-label="Page navigation">
        <ul class="pagination pagination-sm flex-wrap">
            <?php
            $qs = $_GET; unset($qs['page']);
            $baseQs = http_build_query($qs);
            $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
            $buildLink = function(int $p) use ($baseUrl, $baseQs): string {
                return $baseUrl . '?' . ($baseQs ? ($baseQs . '&') : '') . 'page=' . $p;
            };

            // Prev
            if ($page > 1) {
                echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildLink($page - 1)) . '">&laquo;</a></li>';
            } else {
                echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
            }

            // Smart ellipsis pages
            $pagesToShow = [];
            if ($totalPages <= 9) {
                for ($i = 1; $i <= $totalPages; $i++) $pagesToShow[] = $i;
            } else {
                $pagesToShow[] = 1;
                if ($page > 4) $pagesToShow[] = '...';
                for ($i = max(2, $page - 2); $i <= min($totalPages - 1, $page + 2); $i++) $pagesToShow[] = $i;
                if ($page < $totalPages - 3) $pagesToShow[] = '...';
                $pagesToShow[] = $totalPages;
            }
            foreach ($pagesToShow as $p) {
                if ($p === '...') {
                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                } else {
                    $cls = ($p == $page) ? ' active' : '';
                    echo '<li class="page-item' . $cls . '"><a class="page-link" href="' . htmlspecialchars($buildLink((int)$p)) . '">' . $p . '</a></li>';
                }
            }

            // Next
            if ($page < $totalPages) {
                echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildLink($page + 1)) . '">&raquo;</a></li>';
            } else {
                echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
            }
            ?>
        </ul>
    </nav>
    <div class="text-muted small mt-1">
        Page <?php echo $page; ?> of <?php echo $totalPages; ?> &mdash; <?php echo number_format($total); ?> record<?php echo $total !== 1 ? 's' : ''; ?> total
    </div>

</div>
<script src="<?php echo getBaseDir(); ?>/assets/js/login-activity.js?v=<?php echo time(); ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php';

