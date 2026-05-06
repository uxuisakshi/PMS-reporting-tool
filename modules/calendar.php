<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$baseDir = getBaseDir();

$userId = $_SESSION['user_id'];
$pageTitle = 'My Availability Calendar';
$availabilityStatuses = getAvailabilityStatusOptions(false);
$availabilityStatusMap = [];
foreach ($availabilityStatuses as $statusRow) {
    $statusKey = strtolower(trim((string)($statusRow['status_key'] ?? '')));
    if ($statusKey === '') continue;
    $availabilityStatusMap[$statusKey] = [
        'status_label' => (string)($statusRow['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))),
        'badge_color' => (string)($statusRow['badge_color'] ?? 'secondary')
    ];
}
if (!isset($availabilityStatusMap['not_updated'])) {
    $availabilityStatusMap['not_updated'] = ['status_label' => 'Not Updated', 'badge_color' => 'secondary'];
}
$badgeToHex = [
    'primary' => '#0d6efd',
    'secondary' => '#6c757d',
    'success' => '#198754',
    'danger' => '#dc3545',
    'warning' => '#ffc107',
    'info' => '#0dcaf0',
    'light' => '#f8f9fa',
    'dark' => '#212529'
];

// Get assigned projects for the current user (for quick production hours logging)
$projectsStmt = $db->prepare("
    SELECT p.id, p.title, p.po_number
    FROM projects p
    LEFT JOIN user_assignments ua ON p.id = ua.project_id AND ua.user_id = ?
    WHERE p.status NOT IN ('cancelled', 'archived') AND (ua.id IS NOT NULL OR p.project_lead_id = ? OR p.po_number = 'OFF-PROD-001')
    ORDER BY p.po_number = 'OFF-PROD-001', p.title
");
$projectsStmt->execute([$userId, $userId]);
$assignedProjects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

// Ensure personal notes table exists (safe to run if migration not applied)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_calendar_notes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        note_date DATE NOT NULL,
        content TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_date (user_id, note_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    // ignore - will surface when attempting to use notes if necessary
}

// Handle AJAX request for events
if (isset($_GET['action']) && $_GET['action'] === 'get_events') {
    $start = $_GET['start'] ?? date('Y-m-d');
    $end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));

    $filterUserId = isset($_GET['user_id']) ? $_GET['user_id'] : null;
    $editRequestFilter = isset($_GET['edit_request_filter']) ? $_GET['edit_request_filter'] : null;
    $statusFilters = isset($_GET['status_filter']) ? explode(',', (string)$_GET['status_filter']) : ['all'];
    $statusFilters = array_values(array_filter(array_map(static function ($v) {
        return strtolower(trim((string)$v));
    }, $statusFilters)));
    if (in_array('all', $statusFilters, true) || empty($statusFilters)) {
        $statusFilters = ['all'];
    }
    $statusFilterAllows = static function ($statusKey) use ($statusFilters) {
        $statusKey = strtolower(trim((string)$statusKey));
        if (in_array('all', $statusFilters, true)) return true;
        if (in_array($statusKey, $statusFilters, true)) return true;
        if (($statusKey === 'on_leave' || $statusKey === 'sick_leave') && in_array('leave', $statusFilters, true)) return true;
        return false;
    };
    $isAdminUser = hasAdminPrivileges();

    $events = [];

    // Fetch explicit statuses depending on filter/admin
    if ($isAdminUser && $filterUserId === 'all') {
        $stmt = $db->prepare(
            "SELECT uds.*, u.full_name, u.role, uds.user_id
             FROM user_daily_status uds
             JOIN users u ON uds.user_id = u.id
             WHERE uds.status_date BETWEEN ? AND ?"
        );
        $stmt->execute([$start, $end]);

        $userStmt = $db->prepare("SELECT id, full_name, role FROM users WHERE is_active = 1");
        $userStmt->execute();
        $usersList = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        $usersById = [];
        foreach ($usersList as $u) $usersById[$u['id']] = $u;

    } elseif ($isAdminUser && $filterUserId) {
        $stmt = $db->prepare(
            "SELECT uds.*, u.full_name, u.role, uds.user_id
             FROM user_daily_status uds
             JOIN users u ON uds.user_id = u.id
             WHERE uds.user_id = ?
             AND uds.status_date BETWEEN ? AND ?"
        );
        $stmt->execute([$filterUserId, $start, $end]);

        $userStmt = $db->prepare("SELECT id, full_name, role FROM users WHERE id = ?");
        $userStmt->execute([$filterUserId]);
        $usersList = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        $usersById = [];
        foreach ($usersList as $u) $usersById[$u['id']] = $u;

    } else {
        $stmt = $db->prepare(
            "SELECT uds.*, u.full_name, u.role, uds.user_id
             FROM user_daily_status uds
             JOIN users u ON uds.user_id = u.id
             WHERE uds.user_id = ?
             AND uds.status_date BETWEEN ? AND ?"
        );
        $stmt->execute([$userId, $start, $end]);

        $userStmt = $db->prepare("SELECT id, full_name, role FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $usersList = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        $usersById = [];
        foreach ($usersList as $u) $usersById[$u['id']] = $u;
    }

    // Fetch edit requests for current user in date range
    $editRequests = [];
    if (!$isAdminUser || !$filterUserId) {
        $editStmt = $db->prepare("SELECT req_date, status, reason, created_at, updated_at FROM user_edit_requests WHERE user_id = ? AND req_date BETWEEN ? AND ? AND request_type = 'edit'");
        $editStmt->execute([$userId, $start, $end]);
        while ($editRow = $editStmt->fetch(PDO::FETCH_ASSOC)) {
            $editRequests[$editRow['req_date']] = $editRow;
        }
    }

    $status_map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $uid = $row['user_id'] ?? null;
        $d = $row['status_date'];
        if ($uid === null) continue;
        if (!isset($status_map[$uid])) $status_map[$uid] = [];
        $status_map[$uid][$d] = $row;

        // Skip if edit request filter is active and doesn't match
        if ($editRequestFilter && !$isAdminUser && $uid == $userId) {
            $hasEditRequest = isset($editRequests[$d]);
            $editStatus = $hasEditRequest ? $editRequests[$d]['status'] : null;
            
            if ($editRequestFilter !== $editStatus) {
                continue;
            }
        }
        $statusKey = strtolower(trim((string)($row['status'] ?? 'not_updated')));
        if (!$statusFilterAllows($statusKey)) {
            continue;
        }

        $badgeColor = strtolower((string)($availabilityStatusMap[$statusKey]['badge_color'] ?? 'secondary'));
        $color = $badgeToHex[$badgeColor] ?? '#6c757d';
        $title = (string)($availabilityStatusMap[$statusKey]['status_label'] ?? ucfirst($row['status']));

        // Add edit request indicator to title if exists
        $titleSuffix = '';
        $colorOverride = null;
        
        if (isset($editRequests[$d])) {
            $editStatus = $editRequests[$d]['status'];
            switch ($editStatus) {
                case 'pending':
                    $titleSuffix .= ' - Edit Pending';
                    $colorOverride = '#fd7e14'; // Orange
                    break;
                case 'approved':
                    $titleSuffix .= ' - Edit Approved';
                    $colorOverride = '#20c997'; // Teal
                    break;
                case 'rejected':
                    $titleSuffix .= ' - Edit Rejected';
                    break;
                case 'used':
                    $titleSuffix .= ' - Edit Used';
                    $colorOverride = '#6f42c1'; // Purple
                    break;
            }
        }

        if ($colorOverride) {
            $color = $colorOverride;
        }

        $displayName = $row['full_name'] ?? ($usersById[$uid]['full_name'] ?? ('User ' . intval($uid)));

        $events[] = [
            'title' => $displayName . ' - ' . $title . $titleSuffix,
            'start' => $d,
            'color' => $color,
            'description' => $row['notes'] ?? '',
            'extendedProps' => [
                'notes' => $row['notes'] ?? '',
                'status' => $row['status'],
                'user_full_name' => $displayName,
                'user_role' => $row['role'] ?? ($usersById[$uid]['role'] ?? null),
                'user_id' => $uid,
                'edit_request' => isset($editRequests[$d]) ? $editRequests[$d] : null
            ]
        ];
    }

    // Build date range and add 'Not updated' for missing past/today dates per-user.
    // Future dates stay empty unless the user has explicitly saved a status.
    $todayDate = date('Y-m-d');
    $period = new DatePeriod(
        new DateTime($start),
        new DateInterval('P1D'),
        (new DateTime($end))->modify('+1 day')
    );

    foreach ($period as $dt) {
        $d = $dt->format('Y-m-d');

        if ($d > $todayDate) {
            continue;
        }

        if ($isAdminUser && $filterUserId === 'all') {
            foreach ($usersList as $u) {
                $uid = $u['id'];
                if (empty($status_map[$uid][$d])) {
                    if (!$statusFilterAllows('not_updated')) {
                        continue;
                    }
                    $events[] = [
                        'title' => $u['full_name'] . ' (Not updated)',
                        'start' => $d,
                        'color' => '#6c757d',
                        'description' => '',
                        'extendedProps' => [
                            'status' => 'not_updated',
                            'user_id' => $uid,
                            'user_full_name' => $u['full_name'],
                            'user_role' => $u['role'] ?? null
                        ]
                    ];
                }
            }
            continue;
        }

        if (!empty($usersList)) {
            foreach ($usersList as $u) {
                $uid = $u['id'];
                if (empty($status_map[$uid][$d])) {
                    if (!$statusFilterAllows('not_updated')) {
                        continue;
                    }
                    if ($editRequestFilter && !$isAdminUser && $uid == $userId) {
                        $hasEditRequest = isset($editRequests[$d]);
                        $editStatus = $hasEditRequest ? $editRequests[$d]['status'] : null;
                        
                        if ($editRequestFilter !== $editStatus) {
                            continue;
                        }
                    }

                    $title = $u['full_name'] . ' (Not updated)';
                    $color = '#6c757d';
                    
                    if (isset($editRequests[$d])) {
                        $editStatus = $editRequests[$d]['status'];
                        switch ($editStatus) {
                            case 'pending':
                                $title = $u['full_name'] . ' (Edit Pending)';
                                $color = '#fd7e14';
                                break;
                            case 'approved':
                                $title = $u['full_name'] . ' (Edit Approved)';
                                $color = '#20c997';
                                break;
                            case 'rejected':
                                $title = $u['full_name'] . ' (Edit Rejected)';
                                $color = '#e83e8c';
                                break;
                            case 'used':
                                $title = $u['full_name'] . ' (Edit Used)';
                                $color = '#6f42c1';
                                break;
                        }
                    }

                    $events[] = [
                        'title' => $title,
                        'start' => $d,
                        'color' => $color,
                        'description' => '',
                        'extendedProps' => [
                            'status' => 'not_updated',
                            'user_id' => $uid,
                            'user_full_name' => $u['full_name'],
                            'user_role' => $u['role'] ?? null,
                            'edit_request' => isset($editRequests[$d]) ? $editRequests[$d] : null
                        ]
                    ];
                }
            }
        }
    }

    // Add personal notes for this user (only if content is not empty)
    $noteStmt = $db->prepare("SELECT note_date, content FROM user_calendar_notes WHERE user_id = ? AND note_date BETWEEN ? AND ? AND content IS NOT NULL AND TRIM(content) != ''");
    $noteStmt->execute([$userId, $start, $end]);
    while ($n = $noteStmt->fetch(PDO::FETCH_ASSOC)) {
        $events[] = [
            'title' => 'Personal Note',
            'start' => $n['note_date'],
            'color' => '#6610f2',
            'description' => $n['content'] ?? '',
            'extendedProps' => [ 'personal_note' => $n['content'] ?? '' ]
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($events);
    exit;
}

include __DIR__ . '/../includes/header.php';

// If admin, prepare users for admin selector
$usersForSelect = [];
if (hasAdminPrivileges()) {
    try {
        $uStmt = $db->prepare("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name ASC");
        $uStmt->execute();
        $usersForSelect = $uStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $usersForSelect = [];
    }
}

$canEditFuture = true;
?>

<!-- FullCalendar CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>My Availability Calendar</h2>
        <div class="d-flex align-items-center">
            <?php if (!empty($usersForSelect)): ?>
                <div class="me-2">
                    <select id="admin_user_select" class="form-select">
                        <option value="">-- View user production hours --</option>
                        <option value="all">All users</option>
                        <?php foreach ($usersForSelect as $u): ?>
                            <option value="<?php echo intval($u['id']); ?>"><?php echo htmlspecialchars($u['full_name'] . ' (@' . $u['username'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="me-2">
                <select id="edit_request_filter" class="form-select">
                    <option value="">All Dates</option>
                    <option value="pending">Pending Requests</option>
                    <option value="approved">Approved Requests</option>
                    <option value="rejected">Rejected Requests</option>
                    <option value="used">Used Approvals</option>
                </select>
            </div>
            <div class="me-2">
                <div class="btn-group" role="group" aria-label="Filter Status">
                    <?php foreach ($availabilityStatusMap as $statusKey => $meta): ?>
                        <?php
                        $filterId = 'cal_filter_' . preg_replace('/[^a-z0-9_]+/i', '_', $statusKey);
                        $badgeColor = strtolower((string)($meta['badge_color'] ?? 'secondary'));
                        $outlineClass = in_array($badgeColor, ['primary','secondary','success','danger','warning','info','dark'], true)
                            ? $badgeColor
                            : 'secondary';
                        ?>
                        <input type="checkbox" class="btn-check status-filter-check" id="<?php echo htmlspecialchars($filterId, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8'); ?>" checked autocomplete="off">
                        <label class="btn btn-outline-<?php echo htmlspecialchars($outlineClass, ENT_QUOTES, 'UTF-8'); ?>" for="<?php echo htmlspecialchars($filterId, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars((string)($meta['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))), ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php" class="btn btn-primary">Go to Daily Status</a>
            </div>
        </div>
    </div>

    <!-- Legends -->
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-info-circle"></i> Availability Status Legend</h6>
                </div>
                <div class="card-body py-2">
                    <div class="row">
                        <?php $legendIdx = 0; foreach ($availabilityStatusMap as $statusKey => $meta): ?>
                            <?php
                            $colClass = ($legendIdx % 2 === 0) ? 'col-sm-6' : 'col-sm-6';
                            $badgeColor = strtolower((string)($meta['badge_color'] ?? 'secondary'));
                            $hex = $badgeToHex[$badgeColor] ?? '#6c757d';
                            ?>
                            <div class="<?php echo $colClass; ?>">
                                <small class="d-flex align-items-center mb-1">
                                    <span class="badge me-2" style="background-color: <?php echo htmlspecialchars($hex, ENT_QUOTES, 'UTF-8'); ?>;">&nbsp;&nbsp;&nbsp;</span>
                                    <?php echo htmlspecialchars((string)($meta['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))), ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                            </div>
                        <?php $legendIdx++; endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-edit"></i> Edit Request Status Legend</h6>
                </div>
                <div class="card-body py-2">
                    <div class="row">
                        <div class="col-sm-6">
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #fd7e14;">&nbsp;&nbsp;&nbsp;</span>
                                Pending Request
                            </small>
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #20c997;">&nbsp;&nbsp;&nbsp;</span>
                                Approved Request
                            </small>
                        </div>
                        <div class="col-sm-6">
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #e83e8c;">&nbsp;&nbsp;&nbsp;</span>
                                Rejected Request
                            </small>
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #6f42c1;">&nbsp;&nbsp;&nbsp;</span>
                                Used Approval
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div id="calendar"></div>
        </div>
    </div>
</div>

<!-- Calendar Edit Modal -->
<div class="modal fade" id="calendarEditModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update My Availability</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="calendarEditForm">
                <div class="modal-body">
                    <input type="hidden" id="calDate" name="date">
                    
                    <!-- Edit Request Status -->
                    <div id="editRequestStatus" class="alert alert-info" style="display: none;">
                        <h6><i class="fas fa-info-circle"></i> Edit Request Status: <span id="editRequestStatusBadge" class="badge"></span></h6>
                        <div id="editRequestReasonRow" style="display: none;">
                            <strong>Reason:</strong> <span id="editRequestReason"></span>
                        </div>
                        <div id="editRequestDatesRow" style="display: none;">
                            <strong>Requested:</strong> <span id="editRequestDate"></span>
                        </div>
                        <div id="editRequestUpdatedRow" style="display: none;">
                            <strong>Updated:</strong> <span id="editRequestUpdated"></span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="calStatus" class="form-label">Availability Status</label>
                                <select id="calStatus" name="status" class="form-select">
                                    <?php foreach ($availabilityStatuses as $st): ?>
                                        <?php $stKey = (string)($st['status_key'] ?? ''); ?>
                                        <?php if ($stKey === '') continue; ?>
                                        <option value="<?php echo htmlspecialchars($stKey); ?>">
                                            <?php echo htmlspecialchars((string)($st['status_label'] ?? ucfirst(str_replace('_', ' ', $stKey)))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="calNotes" class="form-label">Work Notes</label>
                                <textarea id="calNotes" name="notes" class="form-control" rows="4" placeholder="What did you work on today?"></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="calPersonalNote" class="form-label">Personal Notes (Private)</label>
                                <textarea id="calPersonalNote" name="personal_note" class="form-control" rows="3" placeholder="Personal reminders, thoughts, etc."></textarea>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0"><i class="fas fa-clock"></i> Production Hours <span id="hoursDate"></span></h6>
                                    <button type="button"
                                            id="openLogHoursModalBtn"
                                            class="btn btn-outline-primary btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#calendarLogHoursModal">
                                        Log Hours
                                    </button>
                                </div>
                                <div class="card-body py-2">
                                    <div class="text-center mb-2">
                                        <h5 id="totalHours" class="mb-2">0.00 hrs</h5>
                                        <div class="progress mb-1">
                                            <div id="utilizedProgress" class="progress-bar bg-success" role="progressbar" style="width: 0%">
                                                Utilized
                                            </div>
                                            <div id="benchProgress" class="progress-bar bg-secondary" role="progressbar" style="width: 100%">
                                                Bench
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            Utilized: <span id="utilizedHours">0.00</span>h | 
                                            Bench: <span id="benchHours">0.00</span>h
                                        </small>
                                    </div>
                                    
                                    <div id="hoursEntries" style="max-height: 140px; overflow-y: auto;">
                                        <p class="text-muted text-center">Loading...</p>
                                    </div>
                                    
                                    <!-- Production hours quick-form is rendered in separate modal -->
                                    <div id="calendarModalLogFormContainer" class="d-none">
                                        <form id="logProductionHoursForm" class="row g-2" novalidate>
                                            <div class="col-md-6">
                                                <label class="form-label">Project</label>
                                                <select name="project_id" id="productionProjectSelect" class="form-select" required>
                                                    <option value="">Select Project</option>
                                                    <?php foreach ($assignedProjects as $p): ?>
                                                        <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Task Type</label>
                                                <select name="task_type" id="taskTypeSelect" class="form-select">
                                                    <option value="">Select Task Type</option>
                                                    <option value="page_testing">Page Testing</option>
                                                    <option value="page_qa">Page QA</option>
                                                    <option value="regression_testing">Regression Testing</option>
                                                    <option value="project_phase">Project Phase</option>
                                                    <option value="generic_task">Generic Task</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6" id="pageTestingContainer" style="display:none;">
                                                <label class="form-label">Page / Screen</label>
                                                <select id="productionPageSelect" class="form-select" multiple size="4">
                                                    <option value="">Select project first</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6" id="productionEnvCol" style="display:none;">
                                                <label class="form-label">Environment</label>
                                                <select id="productionEnvSelect" class="form-select">
                                                    <option value="">Select page first</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Hours</label>
                                                <input type="number" id="logHoursInput" step="0.01" min="0.01" class="form-control" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Description</label>
                                                <input type="text" id="logDescriptionInput" class="form-control">
                                            </div>
                                            <div class="col-12 d-flex justify-content-end">
                                                <button type="button" id="logTimeBtn" class="btn btn-primary">Log Hours</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" id="requestEditFooterBtn" class="btn btn-warning" style="display:none;">Request Edit</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <!-- Dynamic buttons will be added here -->
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Log Hours Modal -->
<div class="modal fade" id="calendarLogHoursModal" tabindex="-1" aria-labelledby="calendarLogHoursModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="calendarLogHoursModalLabel">Log Production Hours</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="calendarLogHoursModalBody">
                <div id="calendarLogStatus" class="alert d-none py-2 px-3 small mb-3" role="alert"></div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Request Modal -->
<div class="modal fade" id="editRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Request Edit Permission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editRequestForm">
                <div class="modal-body">
                    <input type="hidden" id="requestDate" name="date">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        You are requesting permission to edit a past date. Please provide a reason for this request.
                    </div>
                    
                    <div class="mb-3">
                        <label for="editReason" class="form-label">Reason for Edit Request <span class="text-danger">*</span></label>
                        <textarea id="editReason" name="reason" class="form-control" rows="3" placeholder="Please explain why you need to edit this past date..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" id="editRequestSendBtn">Send Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<style>
#calendarEditModal .modal-dialog {
    max-width: min(1140px, 96vw);
    margin: 0.75rem auto;
    height: calc(100vh - 1.5rem);
}
#calendarEditModal .modal-content {
    height: 100%;
    max-height: none;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
#calendarEditModal #calendarEditForm {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
}
#calendarEditModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 1rem;
}
#calendarEditModal .modal-footer {
    position: sticky;
    bottom: 0;
    min-height: 60px;
    background: #fff;
    z-index: 2;
    border-top: 1px solid #dee2e6;
}
</style>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window._calendarConfig = {
    canEditFuture: <?php echo $canEditFuture ? 'true' : 'false'; ?>,
    assignedProjects: <?php echo json_encode($assignedProjects, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>,
    isAdmin: <?php echo hasAdminPrivileges() ? 'true' : 'false'; ?>,
    userId: <?php echo (int)$userId; ?>,
    baseDir: <?php echo json_encode($baseDir); ?>
};
</script>
<script src="<?php echo $baseDir; ?>/assets/js/calendar.js"></script>


<?php include __DIR__ . '/../includes/footer.php'; 